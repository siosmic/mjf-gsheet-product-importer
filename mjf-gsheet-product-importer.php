<?php

/**
 * Plugin Name: MJF Google Sheets Product Importer
 * Description: Import and sync WooCommerce products from a Google Sheet.
 * Version: 2.6.2
 * Author: Siosm
 * Author URI: https://siosm.ch/
 * Text Domain: mjf-gsheet-importer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 */

if (! defined('ABSPATH')) {
	exit;
}

// Load Composer Autoloader
if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
	require_once dirname(__FILE__) . '/vendor/autoload.php';
}

require_once dirname(__FILE__) . '/includes/class-mjf-gsheet-api.php';
require_once dirname(__FILE__) . '/includes/class-mjf-gsheet-admin.php';

/**
 * Main Plugin Class
 */
class MJF_GSheet_Product_Importer
{
	private $option_name = 'mjf_gsheet_importer_settings';
	private $table_name;
	public $api;
	public $admin;

	public function __construct()
	{
		// Force increased memory limit for sync operations (especially with WPML)
		@ini_set('memory_limit', '1024M');

		global $wpdb;
		$this->table_name = $wpdb->prefix . 'mjf_gsheet_sync_queue';
		$this->api = new MJF_GSheet_API();
		$this->admin = new MJF_GSheet_Admin();

		if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") !== $this->table_name) {
			self::activate();
		}

		add_action('admin_menu', [$this->admin, 'add_admin_menu']);
		add_action('admin_init', [$this->admin, 'register_settings']);

		// AJAX Endpoints
		add_action('wp_ajax_mjf_gsheet_generate_comparison', [$this, 'ajax_generate_comparison']);
		add_action('wp_ajax_mjf_gsheet_get_comparison_page', [$this, 'ajax_get_comparison_page']);
		add_action('wp_ajax_mjf_gsheet_execute_sync', [$this, 'ajax_execute_sync']);
		add_action('wp_ajax_mjf_gsheet_update_sync_direction', [$this, 'ajax_update_sync_direction']);
		add_action('wp_ajax_mjf_gsheet_sync_single_field', [$this, 'ajax_sync_single_field']);
		add_action('wp_ajax_mjf_gsheet_create_product', [$this, 'ajax_create_product']);
		add_action('wp_ajax_mjf_gsheet_create_translation', [$this, 'ajax_create_translation']);

		// Order Completed hook for Stock Sync WP -> GS
		add_action('woocommerce_order_status_completed', [$this, 'sync_stock_to_gsheet_on_order_completed'], 10, 1);

		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this->admin, 'add_action_links']);
	}

	/**
	 * Log message
	 */
	private function log($message)
	{
		$logs = get_option('mjf_gsheet_import_logs', []);
		$logs[] = [
			'time'    => current_time('Y-m-d H:i:s'),
			'message' => $message,
		];
		// Keep last 100 logs
		if (count($logs) > 100) {
			array_shift($logs);
		}
		update_option('mjf_gsheet_import_logs', $logs);
	}

	/**
	 * Upsert Simple or Variable Product
	 */
	private function upsert_product($item, $type)
	{
		$sku = $item['sku'];
		$wp_id = isset($item['wp_id']) ? intval($item['wp_id']) : 0;
		if (empty($sku) && empty($wp_id)) return;

		$product_id = 0;
		if ($wp_id > 0) {
			$product = wc_get_product($wp_id);
			if ($product) {
				$product_id = $wp_id;
			}
		}
		if (!$product_id && !empty($sku)) {
			$product_id = wc_get_product_id_by_sku($sku);
		}

		if ($type === 'variable') {
			$product = $product_id ? wc_get_product($product_id) : new WC_Product_Variable();
		} else {
			$product = $product_id ? wc_get_product($product_id) : new WC_Product_Simple();
		}

		// Description and Short Description
		if (isset($item['description']) && $item['description'] !== '') $product->set_description($item['description']);
		if (isset($item['short_description']) && $item['short_description'] !== '') $product->set_short_description($item['short_description']);

		$product->set_sku($sku);

		if (! empty($item['title'])) {
			$product->set_name($item['title']);
		}

		// Status
		if (! empty($item['status'])) {
			$status = strtolower(trim($item['status']));
			// Try to handle french terms too
			if ($status === 'brouillon' || $status === 'draft') {
				$product->set_status('draft');
			} else {
				$product->set_status('publish');
			}
		}

		// Weight
		if (isset($item['weight']) && $item['weight'] !== '') {
			$product->set_weight($item['weight']);
		}

		// Shipping class
		if (isset($item['shipping_class'])) {
			$this->set_product_shipping_class($product, $item['shipping_class']);
		}

		if (isset($item['price']) && $item['price'] !== '' && $type !== 'variable') {
			$product->set_regular_price((float)$item['price']);
		}

		if (isset($item['stock']) && $item['stock'] !== '' && $type !== 'variable') {
			$product->set_manage_stock(true);
			$product->set_stock_quantity((int)$item['stock']);
		} else {
			$product->set_manage_stock(false);
		}

		// Categories
		if ($type === 'simple' || $type === 'variable') {
			$cat_ids = $this->get_category_ids($item['cat_primary'] ?? '', $item['cat_secondary'] ?? '');
			if (! empty($cat_ids)) {
				$product->set_category_ids($cat_ids);
			}
		}

		// FedEx Meta
		$fedex_map = [
			'fedex_hs_tariff'       => '_wf_hs_code',
			'fedex_description'     => '_ph_commodity_description',
			'fedex_freight_class'   => '_wf_freight_class',
			'fedex_signature'       => '_ph_fedex_signature_option',
			'fedex_country'         => '_wf_manufacture_country',
			'fedex_custom_value'    => '_wf_fedex_custom_declared_value',
		];
		foreach ($fedex_map as $item_key => $meta_key) {
			if (isset($item[$item_key]) && $item[$item_key] !== '') {
				$normalized = $this->normalize_fedex_select_value($meta_key, $item[$item_key]);
				$product->update_meta_data($meta_key, $normalized);
				if ($item_key === 'fedex_country') {
					$product->update_meta_data('_ph_manufacture_country', $normalized);
				}
			}
		}

		// Attributes for Variable Products
		if ($type === 'variable' && ! empty($item['attributes'])) {
			$settings = $item['attr_settings'] ?? [];
			$this->set_variable_attributes($product, $item['attributes'], $settings);
		}

		// Custom Meta
		if (! empty($item['custom_meta'])) {
			foreach ($item['custom_meta'] as $meta_key => $meta_value) {
				$product->update_meta_data($meta_key, $meta_value);
			}
		}

		// WCPOS UUID
		if (! $product->get_meta('_woocommerce_pos_uuid')) {
			$product->update_meta_data('_woocommerce_pos_uuid', wp_generate_uuid4());
		}

		$product_id = $product->save();
		wc_delete_product_transients($product_id);

		// Sync taxonomy terms for global attributes (essential for linking variations)
		if ($type === 'variable' && ! empty($item['attributes'])) {
			$product_attributes = $product->get_attributes();
			foreach ($product_attributes as $attr) {
				if ($attr && $attr->is_taxonomy()) {
					wp_set_object_terms($product_id, $attr->get_options(), $attr->get_name());
				}
			}
		}

		$this->log(sprintf(__('Upserted %s product: %s', 'mjf-gsheet-importer'), $type, $sku));
		return $product_id;
	}

	/**
	 * Upsert Variation
	 */
	private function upsert_variation($item)
	{
		$sku = trim($item['sku'] ?? '');
		$parent_sku = trim($item['parent_sku'] ?? '');
		$wp_id = isset($item['wp_id']) ? intval($item['wp_id']) : 0;
		if ((empty($sku) && empty($wp_id)) || empty($parent_sku)) return;

		global $wpdb;
		$parent_id = 0;
		$parent_row = $wpdb->get_row($wpdb->prepare("SELECT product_id FROM {$this->table_name} WHERE sku = %s", $parent_sku));
		if ($parent_row && $parent_row->product_id) {
			$parent_id = intval($parent_row->product_id);
		}
		if (!$parent_id) {
			$parent_id = wc_get_product_id_by_sku($parent_sku);
		}
		if (! $parent_id) {
			$this->log(sprintf(__('Skipped variation %s: Parent SKU %s not found.', 'mjf-gsheet-importer'), $sku, $parent_sku));
			return;
		}

		$parent_product = wc_get_product($parent_id);

		$variation_id = 0;
		if ($wp_id > 0) {
			$variation_product = wc_get_product($wp_id);
			if ($variation_product) {
				$variation_id = $wp_id;
			}
		}
		if (!$variation_id && !empty($sku)) {
			$variation_id = wc_get_product_id_by_sku($sku);
		}

		$variation = $variation_id ? new WC_Product_Variation($variation_id) : new WC_Product_Variation();

		// Description and Short Description (NOT INHERITED OR SAVED ON VARIATION)
		// User specifically requested not to save descriptions on variations.

		$variation->set_parent_id($parent_id);
		$variation->set_sku($sku);

		// Status: For variations, "Enabled" = "publish".
		// We force 'publish' to ensure they are linked to the parent product in the admin UI.
		$variation->set_status('publish');
		$this->log(sprintf(__('Forced variation %s status to publish (Enabled)', 'mjf-gsheet-importer'), $sku));

		// Weight
		if (isset($item['weight']) && $item['weight'] !== '') {
			$variation->set_weight($item['weight']);
		}

		// Shipping class
		if (isset($item['shipping_class'])) {
			$this->set_product_shipping_class($variation, $item['shipping_class']);
		}

		if (isset($item['price']) && $item['price'] !== '') {
			$variation->set_regular_price((float)$item['price']);
		}

		if (isset($item['stock']) && $item['stock'] !== '') {
			$variation->set_manage_stock(true);
			$variation->set_stock_quantity((int)$item['stock']);
		} elseif (!$variation_id) {
			$variation->set_manage_stock(true);
			$variation->set_stock_quantity(0);
		}

		// FedEx Meta
		$fedex_map = [
			'fedex_hs_tariff'       => '_wf_hs_code',
			'fedex_description'     => '_ph_commodity_description',
			'fedex_freight_class'   => '_wf_freight_class',
			'fedex_signature'       => '_ph_fedex_signature_option',
			'fedex_country'         => '_wf_manufacture_country',
			'fedex_custom_value'    => '_wf_fedex_custom_declared_value',
		];
		foreach ($fedex_map as $item_key => $meta_key) {
			if (isset($item[$item_key]) && $item[$item_key] !== '') {
				$normalized = $this->normalize_fedex_select_value($meta_key, $item[$item_key]);
				$variation->update_meta_data($meta_key, $normalized);
				if ($item_key === 'fedex_country') {
					$variation->update_meta_data('_ph_manufacture_country', $normalized);
				}
			}
		}

		// Set Variation Attributes
		if (! empty($item['attributes'])) {
			$attr_data = [];
			$parent_attributes = $parent_product->get_attributes();
			$parent_updated = false;

			foreach ($item['attributes'] as $name => $value) {
				$display_name = $name;
				$is_taxonomy = false;

				// Apply Override
				if (! empty($item['attr_settings'][$name]['target'])) {
					$target = $item['attr_settings'][$name]['target'];
					if (strpos($target, '|') !== false) {
						list($unused_id, $display_name) = explode('|', $target);
						$is_taxonomy = true;
					} elseif (strpos($target, 'custom:') === 0) {
						$display_name = str_replace('custom:', '', $target);
						$is_taxonomy = false;
					} else {
						$display_name = $target;
						$is_taxonomy = (strpos($display_name, 'pa_') === 0);
					}
				} else {
					$tax_name = wc_attribute_taxonomy_name($display_name);
					if (taxonomy_exists($tax_name)) {
						$is_taxonomy = true;
						$display_name = $tax_name;
					}
				}

				if ($is_taxonomy) {
					$attr_key = $display_name;

					// Ensure term exists
					$term = get_term_by('name', $value, $attr_key);
					if (!$term) {
						$term_data = wp_insert_term($value, $attr_key);
						if (!is_wp_error($term_data)) {
							$new_term = get_term($term_data['term_id'], $attr_key);
							$term_slug = ($new_term instanceof WP_Term) ? $new_term->slug : sanitize_title($value);
							$term_id = $term_data['term_id'];
						} elseif (is_wp_error($term_data) && isset($term_data->error_data['term_exists'])) {
							$existing_term_id = $term_data->error_data['term_exists'];
							$existing_term = get_term($existing_term_id, $attr_key);
							$term_slug = ($existing_term instanceof WP_Term) ? $existing_term->slug : sanitize_title($value);
							$term_id = $existing_term_id;
						} else {
							$term_slug = sanitize_title($value);
							$term_id = 0;
						}
					} else {
						$term_slug = $term->slug;
						$term_id = $term->term_id;
					}

					$attr_data[$attr_key] = $term_slug;

					if (isset($parent_attributes[$attr_key])) {
						$options = $parent_attributes[$attr_key]->get_options();
						if (! in_array($term_id, $options)) {
							$options[] = $term_id;
							$parent_attributes[$attr_key]->set_options($options);
							$parent_updated = true;
							$this->log(sprintf(__('Added term "%s" (slug: %s) to parent taxonomy "%s"', 'mjf-gsheet-importer'), $value, $term_slug, $attr_key));
						}
					} else {
						$new_attr = new WC_Product_Attribute();
						$new_attr->set_id(wc_attribute_taxonomy_id_by_name($attr_key));
						$new_attr->set_name($attr_key);
						$new_attr->set_options([$term_id]);
						$new_attr->set_visible(true);
						$new_attr->set_variation($attr_key === 'pa_tailles' || $attr_key === 'pa_taille');
						$parent_attributes[$attr_key] = $new_attr;
						$parent_updated = true;
						$this->log(sprintf(__('Initialized parent taxonomy "%s" with term "%s"', 'mjf-gsheet-importer'), $attr_key, $value));
					}
				} else {
					$attr_key = sanitize_title($display_name);
					$attr_data[$attr_key] = $value;

					if (isset($parent_attributes[$attr_key])) {
						$options = $parent_attributes[$attr_key]->get_options();
						if (! in_array($value, $options)) {
							$options[] = $value;
							$parent_attributes[$attr_key]->set_options($options);
							$parent_updated = true;
							$this->log(sprintf(__('Added option "%s" to parent attribute "%s"', 'mjf-gsheet-importer'), $value, $display_name));
						}
					}
				}
			}
			$variation->set_attributes($attr_data);

			if ($parent_updated) {
				$parent_product->set_attributes($parent_attributes);
				$parent_product->save();
			}
		}

		if (! empty($item['custom_meta'])) {
			foreach ($item['custom_meta'] as $meta_key => $meta_value) {
				$variation->update_meta_data($meta_key, $meta_value);
			}
		}

		// WCPOS UUID
		if (! $variation->get_meta('_woocommerce_pos_uuid')) {
			$variation->update_meta_data('_woocommerce_pos_uuid', wp_generate_uuid4());
		}

		// Ensure the variation has attributes before creating it
		if (!$variation_id && empty($variation->get_attributes())) {
			$this->log(sprintf(__('Skipped variation %s: Cannot create a variation without attributes. Parent product attributes may be missing.', 'mjf-gsheet-importer'), $sku));
			return;
		}

		$variation_id = $variation->save();
		$this->update_variation_title($variation_id);

		// Force update meta after save to ensure variations are linked and identified
		if (! empty($attr_data)) {
			foreach ($attr_data as $key => $val) {
				$meta_key = 'attribute_' . $key;
				update_post_meta($variation_id, $meta_key, $val);
				$this->log(sprintf(__('Variation %s: Set %s to "%s"', 'mjf-gsheet-importer'), $sku, $meta_key, $val));
			}
		}

		if ($parent_updated) {
			wc_delete_product_transients($parent_id);
		}

		$this->log(sprintf(__('Upserted variation: %s (Parent: %s)', 'mjf-gsheet-importer'), $sku, $parent_sku));
	}

	/**
	 * Helper: Get Category IDs with Hierarchy
	 */
	private function get_category_ids($primary_name, $secondary_name)
	{
		global $sitepress;
		$original_lang = null;
		if (isset($sitepress)) {
			$original_lang = $sitepress->get_current_language();
			$sitepress->switch_lang('fr');
		}

		$cat_ids = [];
		$primary_ids = [];

		// 1. Process Primary
		if (! empty($primary_name)) {
			$parts = explode(',', $primary_name);
			foreach ($parts as $part) {
				$name = trim($part);
				if (empty($name)) continue;

				$term = get_term_by('name', $name, 'product_cat');
				$term_id = 0;
				if (! $term) {
					$term = wp_insert_term($name, 'product_cat');
					if (! is_wp_error($term)) {
						$term_id = $term['term_id'];
					} elseif (isset($term->error_data['term_exists'])) {
						$term_id = $term->error_data['term_exists'];
					}
				} else {
					$term_id = $term->term_id;
				}
				if ($term_id) {
					$primary_ids[] = $term_id;
					$cat_ids[] = $term_id;
				}
			}
		}

		// Use the first primary category as the parent for secondary categories
		$parent_id = !empty($primary_ids) ? $primary_ids[0] : 0;

		// 2. Process Secondary
		if (! empty($secondary_name)) {
			$parts = explode(',', $secondary_name);
			foreach ($parts as $part) {
				$name = trim($part);
				if (empty($name)) continue;

				$found_term_id = 0;

				// 1. Search globally first to see if a category with this name already exists anywhere
				$global_terms = get_terms([
					'taxonomy'   => 'product_cat',
					'name'       => $name,
					'hide_empty' => false,
				]);

				if (! empty($global_terms) && ! is_wp_error($global_terms)) {
					$found_term_id = $global_terms[0]->term_id;
				}

				// 2. If it doesn't exist, create it (under the first primary category if available, otherwise at root)
				if (! $found_term_id) {
					$args = [];
					if ($parent_id) {
						$args['parent'] = $parent_id;
					}
					$term = wp_insert_term($name, 'product_cat', $args);
					if (! is_wp_error($term)) {
						$found_term_id = $term['term_id'];
					} elseif (isset($term->error_data['term_exists'])) {
						$found_term_id = $term->error_data['term_exists'];
					}
				}

				if ($found_term_id) {
					$cat_ids[] = $found_term_id;
				}
			}
		}

		if (isset($sitepress) && $original_lang) {
			$sitepress->switch_lang($original_lang);
		}

		return array_values(array_unique(array_map('intval', $cat_ids)));
	}

	/**
	 * Helper: Set Variable Attributes
	 */
	private function set_variable_attributes(&$product, $attributes_array, $settings = [])
	{
		$attributes = [];
		$position = 0;

		foreach ($attributes_array as $name => $values) {
			$display_name = $name;
			$target = $settings[$name]['target'] ?? '';
			$is_taxonomy = false;
			$visible = true;
			$variation = true;

			if (isset($settings[$name]) && is_array($settings[$name])) {
				if (! empty($target)) {
					if (strpos($target, '|') !== false) {
						list($attr_id_from_ui, $display_name) = explode('|', $target);
						$is_taxonomy = true;
						$attr_id = (int)$attr_id_from_ui;
					} elseif (strpos($target, 'custom:') === 0) {
						$display_name = str_replace('custom:', '', $target);
						$is_taxonomy = false;
						$attr_id = 0;
					} else {
						$display_name = $target;
						$is_taxonomy = (strpos($display_name, 'pa_') === 0);
						$attr_id = 0;
					}
				}
				$visible = isset($settings[$name]['visible']);
				$variation = isset($settings[$name]['variation']);
			} else {
				$tax_name = wc_attribute_taxonomy_name($display_name);
				if (taxonomy_exists($tax_name)) {
					$is_taxonomy = true;
					$display_name = $tax_name;
				}
			}

			$attribute = new WC_Product_Attribute();
			if ($is_taxonomy) {
				// Global attribute
				if ($attr_id <= 0) {
					$attr_obj = wc_get_attribute($display_name);
					if ($attr_obj && !is_wp_error($attr_obj)) {
						$attr_id = (int)$attr_obj->id;
					}
				}

				if ($attr_id > 0) {
					$attribute->set_id($attr_id);
					$attribute->set_name($display_name);
					$this->log(sprintf(__('Set global attribute "%s" (ID: %d)', 'mjf-gsheet-importer'), $display_name, $attr_id));
				} else {
					// Fallback to searching by slug if wc_get_attribute fails
					$clean_name = str_replace('pa_', '', $display_name);
					$wc_taxonomies = wc_get_attribute_taxonomies();
					foreach ($wc_taxonomies as $tax) {
						if ($tax->attribute_name === $clean_name) {
							$attr_id = (int)$tax->attribute_id;
							break;
						}
					}

					if ($attr_id <= 0) {
						$original_name = isset($settings[$display_name]['original_name']) ? $settings[$display_name]['original_name'] : '';
						if (empty($original_name) && isset($settings[$name]['original_name'])) {
							$original_name = $settings[$name]['original_name'];
						}

						$attr_args = [
							'name'         => !empty($original_name) ? ucfirst($original_name) : wc_attribute_label($display_name),
							'slug'         => $clean_name,
							'type'         => 'select',
							'order_by'     => 'menu_order',
							'has_archives' => false,
						];
						$new_attr_id = wc_create_attribute($attr_args);
						if (!is_wp_error($new_attr_id)) {
							$attr_id = (int)$new_attr_id;
							register_taxonomy($display_name, apply_filters('woocommerce_taxonomy_objects_' . $display_name, ['product']));
							$this->log(sprintf(__('Created and set new global attribute "%s" (ID: %d)', 'mjf-gsheet-importer'), $display_name, $attr_id));
						}
					}

					if ($attr_id > 0) {
						$attribute->set_id($attr_id);
						$attribute->set_name($display_name);
					} else {
						$attribute->set_id(0);
						$attribute->set_name($display_name);
						$this->log(sprintf(__('Set custom attribute "%s"', 'mjf-gsheet-importer'), $display_name));
					}
				}

				// Populate terms
				$term_ids = [];
				$options = is_array($values) ? $values : [$values];
				foreach ($options as $val) {
					$term = get_term_by('name', $val, $display_name);
					if (!$term) {
						$term_data = wp_insert_term($val, $display_name);
						if (!is_wp_error($term_data)) {
							$term_ids[] = (int)$term_data['term_id'];
						} elseif (isset($term_data->error_data['term_exists'])) {
							$term_ids[] = (int)$term_data->error_data['term_exists'];
						}
					} else {
						$term_ids[] = (int)$term->term_id;
					}
				}
				$attribute->set_options($term_ids);
			} else {
				$attribute->set_id(0);
				$attribute->set_name($display_name);
				$attribute->set_options(is_array($values) ? $values : [$values]);
			}

			$attribute->set_position($position++);
			$attribute->set_visible($visible);
			$attribute->set_variation($variation);
			$attributes[] = $attribute;
		}

		$product->set_attributes($attributes);
	}
	/**
	 * Helper: Update Variation Title
	 */
	private function update_variation_title($variation_id)
	{
		$variation = wc_get_product($variation_id);
		if (!$variation) return;

		$parent_id = $variation->get_parent_id();
		$parent = wc_get_product($parent_id);
		if (!$parent) return;

		$attributes = $variation->get_attributes();
		$title_parts = [];
		foreach ($attributes as $attr_name => $attr_value) {
			$taxonomy = strpos($attr_name, 'pa_') === 0 ? $attr_name : '';
			if ($taxonomy) {
				$term = get_term_by('slug', $attr_value, $taxonomy);
				$title_parts[] = $term ? $term->name : $attr_value;
			} else {
				$title_parts[] = $attr_value;
			}
		}

		if (!empty($title_parts)) {
			$new_title = $parent->get_name() . ' - ' . implode(', ', $title_parts);
			wp_update_post([
				'ID' => $variation_id,
				'post_title' => $new_title,
				'post_name'  => sanitize_title($new_title)
			]);
		}
	}

	/**
	 * Upsert Translation (WPML EN)
	 */
	private function upsert_translation($original_id, $item, $lang = 'en')
	{
		if (! has_action('wpml_set_element_language_details')) {
			$this->log(__('WPML is not active. Translation skipped.', 'mjf-gsheet-importer'));
			return;
		}

		$original_product = wc_get_product($original_id);
		if (! $original_product) return;

		$sku = $original_product->get_sku();

		// Find existing translation ID
		$translated_id = apply_filters('wpml_object_id', $original_id, 'product', false, $lang);

		if (! $translated_id) {
			$this->log(sprintf(__('Creating new %s translation for product %s', 'mjf-gsheet-importer'), strtoupper($lang), $sku));

			// We create a new product of the same type
			if ($original_product->is_type('variable')) {
				$translated_product = new WC_Product_Variable();
			} else {
				$translated_product = new WC_Product_Simple();
			}

			// Copy base fields that should be synced
			// We append the language code to the SKU to avoid duplicates in WooCommerce
			$translated_product->set_sku($sku . '-' . strtoupper($lang));
			$translated_product->set_name($item['title_en'] ?? $original_product->get_name());
			$translated_product->set_description($item['description_en'] ?? $original_product->get_description());
			$translated_product->set_short_description($item['content_en'] ?? $original_product->get_short_description());
			$translated_product->set_status($original_product->get_status());

			// Copy stock and price (unless user wants different prices per language)
			$translated_product->set_regular_price($original_product->get_regular_price());
			$translated_product->set_manage_stock($original_product->get_manage_stock());
			$translated_product->set_stock_quantity($original_product->get_stock_quantity());
			$translated_product->set_category_ids($original_product->get_category_ids());

			$wp_shipping_class_id = $original_product->get_shipping_class_id();
			if ($wp_shipping_class_id) {
				$translated_class_id = $this->translate_or_create_term($wp_shipping_class_id, 'product_shipping_class', $lang);
				$translated_product->set_shipping_class_id($translated_class_id);
			} else {
				$translated_product->set_shipping_class_id(0);
			}

			// WCPOS UUID
			if (! $translated_product->get_meta('_woocommerce_pos_uuid')) {
				$translated_product->update_meta_data('_woocommerce_pos_uuid', wp_generate_uuid4());
			}

			// Force attributes for variable translation
			if ($original_product->is_type('variable')) {
				$attrs = $original_product->get_attributes();
				$translated_product->set_attributes($attrs);
				$translated_id = $translated_product->save();
			} else {
				$translated_id = $translated_product->save();
			}

			// Link to original via WPML
			$trid = apply_filters('wpml_element_trid', null, $original_id, 'post_product');
			$default_lang = apply_filters('wpml_default_language', null);

			do_action('wpml_set_element_language_details', [
				'element_id'           => $translated_id,
				'element_type'         => 'post_product',
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => $default_lang
			]);

			// Force attributes for variable and simple translation with translated term IDs
			$this->sync_attributes_to_translation($original_product, $translated_product, $lang);
		} else {
			$this->log(sprintf(__('Updating existing %s translation for product %s (ID: %d)', 'mjf-gsheet-importer'), strtoupper($lang), $sku, $translated_id));
			$translated_product = wc_get_product($translated_id);
			if (isset($item['title_en'])) $translated_product->set_name($item['title_en']);
			if (isset($item['description_en'])) $translated_product->set_description($item['description_en']);
			if (isset($item['content_en'])) $translated_product->set_short_description($item['content_en']);

			// Sync price and stock even on update
			$translated_product->set_regular_price($original_product->get_regular_price());
			$translated_product->set_manage_stock($original_product->get_manage_stock());
			$translated_product->set_stock_quantity($original_product->get_stock_quantity());

			$wp_shipping_class_id = $original_product->get_shipping_class_id();
			if ($wp_shipping_class_id) {
				$translated_class_id = $this->translate_or_create_term($wp_shipping_class_id, 'product_shipping_class', $lang);
				$translated_product->set_shipping_class_id($translated_class_id);
			} else {
				$translated_product->set_shipping_class_id(0);
			}

			// Sync attributes even on update with translated term IDs
			$this->sync_attributes_to_translation($original_product, $translated_product, $lang);

			// WCPOS UUID
			if (! $translated_product->get_meta('_woocommerce_pos_uuid')) {
				$translated_product->update_meta_data('_woocommerce_pos_uuid', wp_generate_uuid4());
			}

			$translated_product->save();
		}
	}

	/**
	 * Sync attributes from original product to translated product, translating term IDs
	 */
	private function sync_attributes_to_translation($original_product, $translated_product, $lang = 'en')
	{
		$attrs = $original_product->get_attributes();
		$translated_attrs = [];

		foreach ($attrs as $key => $attr) {
			// Handle cases where attribute is returned as a raw array instead of an object
			if (is_array($attr)) {
				$attr_obj = new WC_Product_Attribute();
				$name = isset($attr['name']) ? $attr['name'] : '';
				$is_taxonomy = isset($attr['is_taxonomy']) ? (bool) $attr['is_taxonomy'] : taxonomy_exists($name);

				$attr_obj->set_id(isset($attr['id']) ? $attr['id'] : 0);
				$attr_obj->set_name($name);

				if ($is_taxonomy && taxonomy_exists($name)) {
					$term_ids = wp_get_post_terms($original_product->get_id(), $name, ['fields' => 'ids']);
					$attr_obj->set_options($term_ids);
				} else {
					$value = isset($attr['value']) ? $attr['value'] : '';
					$attr_obj->set_options(array_filter(array_map('trim', explode('|', $value))));
				}

				$attr_obj->set_position(isset($attr['position']) ? $attr['position'] : 0);
				$attr_obj->set_visible(isset($attr['is_visible']) ? (bool) $attr['is_visible'] : true);
				$attr_obj->set_variation(isset($attr['is_variation']) ? (bool) $attr['is_variation'] : true);

				$attr = $attr_obj;
			}

			if (!is_object($attr) || !is_a($attr, 'WC_Product_Attribute')) {
				continue;
			}

			$translated_attr = clone $attr;
			if ($attr->is_taxonomy()) {
				$taxonomy = $attr->get_name();
				$options = $attr->get_options(); // Array of term IDs
				$translated_options = [];
				foreach ($options as $term_id) {
					$translated_term_id = $this->translate_or_create_term($term_id, $taxonomy, $lang);
					$translated_options[] = $translated_term_id;
				}
				$translated_attr->set_options($translated_options);
			}
			$translated_attrs[$key] = $translated_attr;
		}

		$translated_product->set_attributes($translated_attrs);
		$translated_product->save();

		// Set object terms on database level to ensure synchronization
		$translated_id = $translated_product->get_id();
		foreach ($translated_attrs as $attr) {
			if ($attr->is_taxonomy()) {
				wp_set_object_terms($translated_id, $attr->get_options(), $attr->get_name());
			}
		}
	}

	/**
	 * Retrieve translation of a taxonomy term, or create it in the target language
	 * if it does not exist, appending '-{$lang}' to the slug and linking it in WPML.
	 */
	private function translate_or_create_term($term_id, $taxonomy, $lang = 'en')
	{
		// 1. Check if translation already exists
		$translated_term_id = apply_filters('wpml_object_id', $term_id, $taxonomy, false, $lang);
		if ($translated_term_id) {
			return (int) $translated_term_id;
		}

		global $sitepress;
		$original_lang = null;
		if (isset($sitepress)) {
			$original_lang = $sitepress->get_current_language();
			// Switch to FR to load the original term
			$sitepress->switch_lang('fr');
		}

		// 2. If it does not exist, get original term details
		$original_term = get_term($term_id, $taxonomy);
		if (!$original_term || is_wp_error($original_term)) {
			if (isset($sitepress) && $original_lang) {
				$sitepress->switch_lang($original_lang);
			}
			return (int) $term_id; // Return original if term not found
		}

		$term_name = $original_term->name;
		$original_slug = $original_term->slug;
		$translated_slug = $original_slug . '-' . $lang;

		if (isset($sitepress)) {
			// Switch to the target language (e.g. 'en') to insert the translation term
			$sitepress->switch_lang($lang);
		}

		// 3. Create the translated term
		$new_term = wp_insert_term($term_name, $taxonomy, [
			'slug' => $translated_slug
		]);

		if (is_wp_error($new_term)) {
			if (isset($new_term->error_data['term_exists'])) {
				$translated_term_id = $new_term->error_data['term_exists'];
			} else {
				if (isset($sitepress) && $original_lang) {
					$sitepress->switch_lang($original_lang);
				}
				return (int) $term_id; // Fallback on failure
			}
		} else {
			$translated_term_id = $new_term['term_id'];
		}

		// 4. Link the new term to the original term in WPML
		$trid = apply_filters('wpml_element_trid', null, $term_id, 'tax_' . $taxonomy);
		if ($trid) {
			do_action('wpml_set_element_language_details', [
				'element_id'           => $translated_term_id,
				'element_type'         => 'tax_' . $taxonomy,
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => 'fr' // Source language is FR
			]);
		}

		if (isset($sitepress) && $original_lang) {
			$sitepress->switch_lang($original_lang);
		}

		return (int) $translated_term_id;
	}

	// --------------------------------------------------------
	// NEW AJAX ENDPOINTS
	// --------------------------------------------------------

	public function get_hardcoded_mapping()
	{
		return [
			'wp_id' => 'WP ID',
			'type' => 'Type',
			'sku' => 'UGS',
			'parent_sku' => 'UGS Parent',
			'title' => 'Nom du produit',
			'description' => 'Contenu additionel FR',
			'short_description' => 'Description FR',
			'price' => 'Prix',
			'stock' => 'Stock',
			'weight' => 'Poids (kg)',
			'status' => 'Status',
			'_wf_hs_code' => 'Numéro de tarif HS',
			'_ph_commodity_description' => 'Description Fedex',
			'_wf_freight_class' => 'Classe de fret',
			'_ph_fedex_signature_option' => 'Signature de livraison',
			'_ph_manufacture_country' => 'Pays de fabrication',
			'_wf_manufacture_country' => 'Pays de fabrication',
			'_wf_fedex_custom_declared_value' => 'Custom Declared Value',
			'title_en' => 'Nom du produit EN',
			'description_en' => 'Contenu additionel EN',
			'content_en' => 'Description EN',
			'image_slug' => 'Slug Image',
			'cat_primary' => 'Catégorie principale',
			'cat_secondary' => 'Catégorie secondaire',
			'shipping_class' => "Classe d'expédition"
		];
	}

	private function compare_item($product_id, $raw_item, $mapping, $type = 'simple', $precalc_attributes = [])
	{
		$diff = [];
		if (!$product_id) {
			$diff['creation'] = ['gs' => 'New Product', 'wp' => null];
			return $diff;
		}

		$product = wc_get_product($product_id);
		if (!$product) {
			$diff['creation'] = ['gs' => 'Missing in WP (Deleted)', 'wp' => null];
			return $diff;
		}

		global $sitepress;
		$original_lang = null;
		if (isset($sitepress)) {
			$original_lang = $sitepress->get_current_language();
			$sitepress->switch_lang('fr');
		}

		$fields_to_compare = [
			'sku'               => ['get' => 'get_sku', 'type' => 'string'],
			'title'             => ['get' => 'get_name', 'type' => 'string'],
			'price'             => ['get' => 'get_regular_price', 'type' => 'float'],
			// 'stock'             => ['get' => 'get_stock_quantity', 'type' => 'int'], // Excluded to prevent stock warnings and imports on existing products
			'weight'            => ['get' => 'get_weight', 'type' => 'float'],
			'description'       => ['get' => 'get_description', 'type' => 'html'],
			'short_description' => ['get' => 'get_short_description', 'type' => 'html'],
		];

		if ($type === 'variable') {
			unset($fields_to_compare['price']);
			unset($fields_to_compare['stock']);
		}

		if ($type === 'variation') {
			// Variations in WC inherit descriptions and don't have unique titles
			unset($fields_to_compare['title']);
			unset($fields_to_compare['description']);
			unset($fields_to_compare['short_description']);
		}

		foreach ($fields_to_compare as $key => $meta) {
			$gs_col = $mapping[$key] ?? '';
			if (empty($gs_col) || !isset($raw_item[$gs_col])) continue;

			$gs_val = $raw_item[$gs_col];
			$wp_val = $product->{$meta['get']}();

			if ($meta['type'] === 'float') {
				$gs_val = (float) str_replace(',', '.', $gs_val);
				$wp_val = (float) $wp_val;
			} elseif ($meta['type'] === 'int') {
				$gs_val = (int) $gs_val;
				$wp_val = (int) $wp_val;
			} elseif ($meta['type'] === 'html') {
				if (empty(trim($gs_val)) && empty(trim($wp_val))) continue;
				// Normalize both sides to plain text before comparing to avoid
				// false positives from <br> vs \n, entity encoding, whitespace, etc.
				$gs_plain = $this->normalize_html_for_comparison($gs_val);
				$wp_plain = $this->normalize_html_for_comparison($wp_val);
				if ($gs_plain === $wp_plain) continue;
				$diff[$key] = ['gs' => trim($gs_val), 'wp' => trim($wp_val)];
				continue;
			} elseif ($meta['type'] === 'string') {
				// Normalize spaces and decode entities for robust comparison
				$gs_val = preg_replace('/\s+/u', ' ', trim(html_entity_decode((string)$gs_val, ENT_QUOTES, 'UTF-8')));
				$wp_val = preg_replace('/\s+/u', ' ', trim(html_entity_decode((string)$wp_val, ENT_QUOTES, 'UTF-8')));
			}

			if ($gs_val != $wp_val) {
				$diff[$key] = ['gs' => $gs_val, 'wp' => $wp_val];
			}
		}

		// FedEx & Custom Meta
		$country_meta_key = ($type === 'variation') ? '_ph_manufacture_country' : '_wf_manufacture_country';
		$fedex_map = [
			'_wf_hs_code'                     => $mapping['_wf_hs_code'] ?? 'HS Tariff Number',
			'_ph_commodity_description'       => $mapping['_ph_commodity_description'] ?? 'Commodity Description',
			'_wf_freight_class'               => $mapping['_wf_freight_class'] ?? 'Freight Class',
			'_ph_fedex_signature_option'      => $mapping['_ph_fedex_signature_option'] ?? 'Delivery Signature',
			$country_meta_key                 => $mapping[$country_meta_key] ?? 'Country of Manufacture',
			'_wf_fedex_custom_declared_value' => $mapping['_wf_fedex_custom_declared_value'] ?? 'Custom Declared Value',
		];
		foreach ($fedex_map as $meta_key => $gs_col) {
			if (!isset($raw_item[$gs_col])) continue;
			$gs_val = trim($raw_item[$gs_col]);
			$wp_val = trim($product->get_meta($meta_key));
			// Normalize BOTH sides before comparing:
			// - GS may have "CLASS 125" or "Matinée" (human labels)
			// - WP may have been stored pre-fix with the same human labels
			// Both sides are reduced to the canonical constant before diffing.
			$gs_val_normalized = $this->normalize_fedex_select_value($meta_key, $gs_val);
			$wp_val_normalized = $this->normalize_fedex_select_value($meta_key, $wp_val);
			if ($gs_val_normalized !== $wp_val_normalized) {
				$diff[$meta_key] = ['gs' => $gs_val, 'wp' => $wp_val];
			}
		}

		// Shipping class
		$gs_shipping_class_col = $mapping['shipping_class'] ?? "Classe d'expédition";
		if (isset($raw_item[$gs_shipping_class_col])) {
			$gs_shipping_class = trim($raw_item[$gs_shipping_class_col]);

			// Force FR context so get_term returns the canonical FR term name
			global $sitepress;
			$_orig_lang_ci = null;
			if (isset($sitepress)) {
				$_orig_lang_ci = $sitepress->get_current_language();
				$sitepress->switch_lang('fr');
			}

			$wp_shipping_class_id = $product->get_shipping_class_id();
			$wp_shipping_class_name = '';
			if ($wp_shipping_class_id) {
				// Try direct lookup first
				$term = get_term($wp_shipping_class_id, 'product_shipping_class');
				if ($term && !is_wp_error($term)) {
					$wp_shipping_class_name = $term->name;
				} else {
					// Fallback: search all WC shipping classes by term_id (handles WPML translated IDs)
					$classes = WC()->shipping()->get_shipping_classes();
					foreach ($classes as $sc) {
						if ((int)$sc->term_id === (int)$wp_shipping_class_id) {
							$wp_shipping_class_name = $sc->name;
							break;
						}
					}
				}
			}

			if (isset($sitepress) && $_orig_lang_ci) {
				$sitepress->switch_lang($_orig_lang_ci);
			}

			// Compare by name (case-insensitive, trimmed) — avoids ID mismatch under WPML
			$match = false;
			if (empty($gs_shipping_class) && empty($wp_shipping_class_name)) {
				$match = true;
			} elseif (!empty($gs_shipping_class) && !empty($wp_shipping_class_name)) {
				$match = (strcasecmp(trim($gs_shipping_class), trim($wp_shipping_class_name)) === 0);
			}

			if (!$match) {
				$diff['shipping_class'] = [
					'gs' => $gs_shipping_class ?: 'Aucune',
					'wp' => $wp_shipping_class_name ?: 'Aucune'
				];
			}
		}

		// Categories
		if ($type !== 'variation') {
			$primary_names = [];
			$secondary_names = [];

			$term_ids = $product->get_category_ids();
			if (!empty($term_ids)) {
				foreach ($term_ids as $term_id) {
					$term = get_term($term_id, 'product_cat');
					if ($term && !is_wp_error($term)) {
						if ($term->parent == 0) {
							$primary_names[] = $term->name;
						} else {
							$secondary_names[] = $term->name;
						}
					}
				}
			}

			sort($primary_names);
			sort($secondary_names);

			$wp_primary = implode(', ', $primary_names);
			$wp_secondary = implode(', ', $secondary_names);

			$gs_cat_primary_col = $mapping['cat_primary'] ?? 'Catégorie principale';
			$gs_cat_secondary_col = $mapping['cat_secondary'] ?? 'Catégorie secondaire';

			$gs_primary_raw = isset($raw_item[$gs_cat_primary_col]) ? trim($raw_item[$gs_cat_primary_col]) : '';
			$gs_secondary_raw = isset($raw_item[$gs_cat_secondary_col]) ? trim($raw_item[$gs_cat_secondary_col]) : '';

			// Helper to normalize and sort comma-separated category lists
			$normalize_and_sort_cats = function ($cat_str) {
				if (empty($cat_str)) return '';
				$parts = explode(',', $cat_str);
				$cleaned = [];
				foreach ($parts as $part) {
					$clean_part = mb_strtolower(preg_replace('/\s+/u', ' ', trim(html_entity_decode($part, ENT_QUOTES, 'UTF-8'))), 'UTF-8');
					if ($clean_part !== '') {
						$cleaned[] = $clean_part;
					}
				}
				sort($cleaned);
				return implode(', ', $cleaned);
			};

			$gs_primary_norm = $normalize_and_sort_cats($gs_primary_raw);
			$wp_primary_norm = $normalize_and_sort_cats($wp_primary);
			if ($gs_primary_norm !== $wp_primary_norm) {
				$diff['cat_primary'] = ['gs' => $gs_primary_raw, 'wp' => $wp_primary];
			}

			$gs_secondary_norm = $normalize_and_sort_cats($gs_secondary_raw);
			$wp_secondary_norm = $normalize_and_sort_cats($wp_secondary);
			if ($gs_secondary_norm !== $wp_secondary_norm) {
				$diff['cat_secondary'] = ['gs' => $gs_secondary_raw, 'wp' => $wp_secondary];
			}
		}

		// Attributes for variable products
		if ($type === 'variable') {
			$sku_col = $mapping['sku'] ?? 'UGS';
			$parent_sku = trim($raw_item[$sku_col] ?? '');

			// Build structured normalized arrays
			$gs_norm_attrs = [];
			if (!empty($parent_sku)) {
				if (isset($precalc_attributes[$parent_sku])) {
					$aggregated = [
						'attributes' => $precalc_attributes[$parent_sku],
						'attr_settings' => []
					];
				} else {
					$aggregated = $this->aggregate_parent_attributes($parent_sku);
				}
				foreach ($aggregated['attributes'] as $tax_name => $vals) {
					$label = wc_attribute_label($tax_name);
					$norm_label = $this->normalize_attribute_label($label);
					if (!empty($vals)) {
						$norm_vals = array_map([$this, 'normalize_attribute_value'], $vals);
						sort($norm_vals);
						$gs_norm_attrs[$norm_label] = $norm_vals;
					}
				}
			}

			$wp_norm_attrs = [];
			$wp_attributes = $product->get_attributes();
			foreach ($wp_attributes as $attr) {
				if (!$attr) continue;
				if ($attr->is_taxonomy()) {
					$terms = wc_get_product_terms($product->get_id(), $attr->get_name(), ['fields' => 'names']);
					if (!is_wp_error($terms) && !empty($terms)) {
						$label = wc_attribute_label($attr->get_name());
						$norm_label = $this->normalize_attribute_label($label);
						$norm_vals = array_map([$this, 'normalize_attribute_value'], $terms);
						sort($norm_vals);
						$wp_norm_attrs[$norm_label] = $norm_vals;
					}
				} else {
					$options = $attr->get_options();
					if (!empty($options)) {
						$label = $attr->get_name();
						$norm_label = $this->normalize_attribute_label($label);
						$norm_vals = array_map([$this, 'normalize_attribute_value'], $options);
						sort($norm_vals);
						$wp_norm_attrs[$norm_label] = $norm_vals;
					}
				}
			}

			// Sort by keys
			ksort($gs_norm_attrs);
			ksort($wp_norm_attrs);

			// Compare the normalized arrays
			if ($gs_norm_attrs !== $wp_norm_attrs) {
				// If they are not equal, compute the display strings to show on the UI (in human format)
				$gs_attr_texts = [];
				foreach ($gs_norm_attrs as $norm_label => $vals) {
					$display_label = $this->get_attribute_display_label($norm_label);
					$gs_attr_texts[] = $display_label . ': ' . implode(', ', $vals);
				}
				$wp_attr_texts = [];
				foreach ($wp_norm_attrs as $norm_label => $vals) {
					$display_label = $this->get_attribute_display_label($norm_label);
					$wp_attr_texts[] = $display_label . ': ' . implode(', ', $vals);
				}
				sort($gs_attr_texts);
				sort($wp_attr_texts);

				$gs_attr_str = implode(' | ', $gs_attr_texts);
				$wp_attr_str = implode(' | ', $wp_attr_texts);

				$diff['attributes'] = ['gs' => $gs_attr_str ?: 'Aucun', 'wp' => $wp_attr_str ?: 'Aucun'];
			}
		}

		// Images
		$image_slug_col = $mapping['image_slug'] ?? 'Slug Image';
		if (!empty($raw_item[$image_slug_col])) {
			$slug = trim($raw_item[$image_slug_col]);

			$expected_main_id = $this->find_attachment_by_base_name($slug . '-main');
			$wp_main_id = $product->get_image_id();

			if ((int)$expected_main_id !== (int)$wp_main_id && $expected_main_id) {
				$diff['image_main'] = ['gs' => "Lier: {$slug}-main", 'wp' => $wp_main_id ? "ID: {$wp_main_id}" : "Aucune"];
			}

			if ($type !== 'variation') {
				$expected_gallery_ids = [];
				for ($i = 1; $i <= 25; $i++) {
					$id = $this->find_attachment_by_base_name($slug . '-' . $i);
					if ($id) $expected_gallery_ids[] = (int)$id;
				}
				$wp_gallery_ids = array_map('intval', $product->get_gallery_image_ids());
				$expected_sorted = $expected_gallery_ids;
				sort($expected_sorted);
				$wp_sorted = $wp_gallery_ids;
				sort($wp_sorted);

				if ($expected_sorted !== $wp_sorted && !empty($expected_gallery_ids)) {
					$diff['image_gallery'] = [
						'gs' => "Lier " . count($expected_gallery_ids) . " images ({$slug}-1...)",
						'wp' => count($wp_gallery_ids) . " images"
					];
				}
			}
		}

		if (isset($sitepress) && $original_lang) {
			$sitepress->switch_lang($original_lang);
		}

		// WPML EN
		$post_type_wpml = ($type === 'variation') ? 'product_variation' : 'product';
		$translated_id = apply_filters('wpml_object_id', $product_id, $post_type_wpml, false, 'en');
		$wpml_map = [
			'title_en'       => $mapping['title_en'] ?? 'Nom du produit EN',
			'description_en' => $mapping['description_en'] ?? 'Contenu additionel EN',
			'content_en'     => $mapping['content_en'] ?? 'Description EN'
		];

		if ($type === 'variation') {
			unset($wpml_map['title_en']);
			unset($wpml_map['description_en']);
			unset($wpml_map['content_en']);
		}

		if (!$translated_id) {
			// Check if we have at least a Title EN in GS to consider it "missing"
			$title_en_gs = isset($wpml_map['title_en']) ? trim($raw_item[$wpml_map['title_en']] ?? '') : '';
			if (!empty($title_en_gs) || $type === 'variation') {
				$diff['wpml_missing'] = ['gs' => 'Missing EN Translation', 'wp' => null];
			}
		} else {
			$trans_product = wc_get_product($translated_id);
			if ($trans_product) {
				foreach ($wpml_map as $field => $col) {
					if (!isset($raw_item[$col])) continue;
					$gs_val = trim($raw_item[$col]);

					if ($field === 'title_en') {
						$wp_val = trim($trans_product->get_name());
					} elseif ($field === 'description_en') {
						$wp_val = trim($trans_product->get_description());
					} else {
						$wp_val = trim($trans_product->get_short_description());
					}

					// Normalize for comparison
					if ($field === 'title_en') {
						$gs_norm = preg_replace('/\s+/u', ' ', trim(html_entity_decode((string)$gs_val, ENT_QUOTES, 'UTF-8')));
						$wp_norm = preg_replace('/\s+/u', ' ', trim(html_entity_decode((string)$wp_val, ENT_QUOTES, 'UTF-8')));
					} else {
						if (empty(trim($gs_val)) && empty(trim($wp_val))) continue;
						$gs_norm = $this->normalize_html_for_comparison($gs_val);
						$wp_norm = $this->normalize_html_for_comparison($wp_val);
					}

					if ($gs_norm !== $wp_norm) {
						$diff[$field] = ['gs' => $gs_val, 'wp' => $wp_val];
					}
				}
			}
		}

		return $diff;
	}

	public function ajax_generate_comparison()
	{
		if (! current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

		@ini_set('memory_limit', '512M');

		$data = $this->api->fetch_sheet_data();
		if (is_wp_error($data)) wp_send_json_error($data->get_error_message());

		global $wpdb;
		$table_name = $this->table_name;
		$wpdb->query("TRUNCATE TABLE {$table_name}");

		$settings = get_option($this->option_name, []);
		$header_row_index = ((int) ($settings['header_row'] ?? 1)) - 1;
		$headers = array_map('trim', $data[$header_row_index] ?? []);
		$rows = array_slice($data, $header_row_index + 1);

		$mapping = $this->get_hardcoded_mapping();
		$inserted = 0;
		$price_col = $mapping['price'] ?? 'Prix';
		$sku_col = $mapping['sku'] ?? 'SKU';
		$type_col = $mapping['type'] ?? 'Type';
		$parent_sku_col = $mapping['parent_sku'] ?? 'Parent SKU';

		// Pre-pass to collect variable product prices and attributes
		$parent_prices = [];
		$precalc_attributes = [];
		$attr_columns = ['Taille', 'Couleur', 'Modèle', 'Année'];

		foreach ($rows as $row) {
			if (count($row) !== count($headers)) {
				$row = count($row) > count($headers) ? array_slice($row, 0, count($headers)) : array_pad($row, count($headers), '');
			}
			$raw_item = array_combine($headers, $row);
			$t = strtolower(trim($raw_item[$type_col] ?? 'simple'));
			$s = trim($raw_item[$sku_col] ?? '');
			$parent_sku = trim($raw_item[$parent_sku_col] ?? '');
			if ($s === '00000000000000') continue;

			if ($t === 'variable' && $s) {
				$parent_prices[$s] = trim($raw_item[$price_col] ?? '');
				if (!isset($precalc_attributes[$s])) {
					$precalc_attributes[$s] = [];
				}
			} elseif ($t === 'variation' && $parent_sku) {
				if (!isset($precalc_attributes[$parent_sku])) {
					$precalc_attributes[$parent_sku] = [];
				}
				foreach ($attr_columns as $col) {
					$matched_col = null;
					$val = $this->extract_attribute_from_raw($raw_item, $col, $matched_col);
					if (!empty($val)) {
						$tax_name = wc_attribute_taxonomy_name($matched_col);
						if (!isset($precalc_attributes[$parent_sku][$tax_name])) {
							$precalc_attributes[$parent_sku][$tax_name] = [];
						}
						if (!in_array($val, $precalc_attributes[$parent_sku][$tax_name])) {
							$precalc_attributes[$parent_sku][$tax_name][] = $val;
						}
					}
				}
			}
		}

		foreach ($rows as $index => $row) {
			if (count($row) !== count($headers)) {
				if (count($row) > count($headers)) {
					$row = array_slice($row, 0, count($headers));
				} else {
					$row = array_pad($row, count($headers), '');
				}
			}
			$raw_item = array_combine($headers, $row);

			$sku = trim($raw_item[$sku_col] ?? '');
			if (empty($sku) || $sku === '00000000000000') continue;

			$type = strtolower(trim($raw_item[$type_col] ?? 'simple'));
			$parent_sku = trim($raw_item[$parent_sku_col] ?? '');

			// Inherit parent price if variation price is empty
			if ($type === 'variation' && empty(trim($raw_item[$price_col] ?? '')) && !empty($parent_sku) && isset($parent_prices[$parent_sku])) {
				$raw_item[$price_col] = $parent_prices[$parent_sku];
			}

			$wp_id_col = $mapping['wp_id'] ?? 'WP ID';
			$wp_id = isset($raw_item[$wp_id_col]) ? intval(trim($raw_item[$wp_id_col])) : 0;

			$product_id = 0;
			if ($wp_id > 0) {
				$product = wc_get_product($wp_id);
				if ($product) {
					$product_id = $wp_id;
				}
			}

			$gs_row_index = $header_row_index + 1 + $index + 1;

			if (!$product_id && !empty($sku)) {
				$product_id = wc_get_product_id_by_sku($sku);
				if ($product_id) {
					if (!empty($wp_id_col)) {
						$this->api->write_to_google_sheet($gs_row_index, $wp_id_col, $product_id);
					}
					$raw_item[$wp_id_col] = $product_id;
				}
			}

			// Automatically update Status in Google Sheets to match WP status (one-way sync WP -> GS)
			if ($product_id) {
				$product = wc_get_product($product_id);
				if ($product) {
					$status_col = $mapping['status'] ?? 'Status';
					$gs_status_raw = trim($raw_item[$status_col] ?? '');
					$gs_status_lower = strtolower($gs_status_raw);

					$gs_status_canonical = 'draft';
					if (in_array($gs_status_lower, ['publish', 'publié', 'publie', 'active'], true)) {
						$gs_status_canonical = 'publish';
					} elseif (in_array($gs_status_lower, ['private', 'privé', 'prive'], true)) {
						$gs_status_canonical = 'private';
					} elseif (in_array($gs_status_lower, ['draft', 'brouillon'], true)) {
						$gs_status_canonical = 'draft';
					}

					$wp_status_canonical = $product->get_status();

					if ($wp_status_canonical !== $gs_status_canonical) {
						$wp_status_label = 'Brouillon';
						if ($wp_status_canonical === 'publish') {
							$wp_status_label = 'Publié';
						} elseif ($wp_status_canonical === 'private') {
							$wp_status_label = 'Privé';
						}

						$this->api->write_to_google_sheet($gs_row_index, $status_col, $wp_status_label);
						$raw_item[$status_col] = $wp_status_label;
					}
				}
			}

			$diff = $this->compare_item($product_id, $raw_item, $mapping, $type, $precalc_attributes);

			$status = 'synced';
			if (isset($diff['creation'])) {
				$status = 'missing_in_wp';
			} elseif (isset($diff['wpml_missing'])) {
				$status = 'translation_missing';
			} elseif (!empty($diff)) {
				$status = 'pending';
			}

			$wpdb->insert($table_name, [
				'sku' => $sku,
				'product_id' => $product_id ?: null,
				'gs_row_index' => $gs_row_index,
				'status' => $status,
				'diff_data' => wp_json_encode([
					'type' => $type,
					'parent_sku' => $parent_sku,
					'raw' => $raw_item,
					'diff' => $diff
				]),
				'direction' => 'gs_to_wp'
			]);
			$inserted++;
		}

		error_log("Finished processing. Inserted: $inserted");

		wp_send_json_success(['message' => "Comparison generated successfully. Processed {$inserted} items from the sheet."]);
	}
	public function ajax_get_comparison_page()
	{
		if (! current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

		global $wpdb;
		$table_name = $this->table_name;
		$mapping = $this->get_hardcoded_mapping();
		$title_col = $mapping['title'] ?? 'Title';

		// Fetch all items to group them properly
		$items = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY id ASC");

		$grouped = [];
		foreach ($items as $item) {
			$diff_data = json_decode($item->diff_data, true);
			$type = $diff_data['type'] ?? 'simple';
			$parent_sku = $diff_data['parent_sku'] ?? '';
			$item->diff_data_decoded = $diff_data;

			if ($type === 'variation' && !empty($parent_sku)) {
				if (!isset($grouped[$parent_sku])) $grouped[$parent_sku] = ['parent' => null, 'variations' => []];
				$grouped[$parent_sku]['variations'][] = $item;
			} else {
				if (!isset($grouped[$item->sku])) $grouped[$item->sku] = ['parent' => null, 'variations' => []];
				$grouped[$item->sku]['parent'] = $item;
			}
		}

		// Default Sorting Logic: Red/Yellow first, Green last.
		uasort($grouped, function ($a, $b) {
			$get_weight = function ($item) {
				if (!$item) return 99;
				$status = $item->status;
				$diff_data = $item->diff_data_decoded ?? [];
				$diffs = $diff_data['diff'] ?? [];
				if ($status === 'synced' || $status === 'synced_to_wp' || $status === 'synced_to_gs') return 2; // Green
				if ($status === 'missing_in_wp') return 0; // Red
				return 1; // Yellow (Differences or Translation Missing)
			};

			$get_group_weight = function ($group) use ($get_weight) {
				$min_weight = $get_weight($group['parent']);
				if (!empty($group['variations'])) {
					foreach ($group['variations'] as $var) {
						$min_weight = min($min_weight, $get_weight($var));
					}
				}
				return $min_weight;
			};

			$weightA = $get_group_weight($a);
			$weightB = $get_group_weight($b);

			if ($weightA == $weightB) {
				$skuA = $a['parent'] ? $a['parent']->sku : '';
				$skuB = $b['parent'] ? $b['parent']->sku : '';
				return strcmp($skuA, $skuB);
			}
			return $weightA < $weightB ? -1 : 1;
		});

		$per_page = 100;
		$total_parents = count($grouped);
		$total_pages = ceil($total_parents / $per_page);
		$page = isset($_POST['page']) ? intval($_POST['page']) : 1;
		$offset = ($page - 1) * $per_page;

		$paged_groups = array_slice($grouped, $offset, $per_page);

		$render_meta_table = function ($item) use ($mapping) {
			$diff_data = $item->diff_data_decoded;
			$diffs = $diff_data['diff'] ?? [];
			$raw = $diff_data['raw'] ?? [];
			$type = strtolower(trim($diff_data['type'] ?? 'simple'));
			$is_missing = ($item->status === 'missing_in_wp' || isset($diffs['creation']));

			// Build parent prices lookup for variation price inheritance
			global $wpdb;
			$parent_prices_lookup = [];
			if ($type === 'variation' && !empty($raw[$mapping['parent_sku'] ?? 'UGS Parent'])) {
				$parent_sku = trim($raw[$mapping['parent_sku'] ?? 'UGS Parent']);
				$parent_entry = $wpdb->get_row($wpdb->prepare("SELECT diff_data FROM {$this->table_name} WHERE sku = %s", $parent_sku));
				if ($parent_entry) {
					$parent_dd = json_decode($parent_entry->diff_data, true);
					$parent_prices_lookup = $parent_dd['raw'] ?? [];
				}
			}

			if ($is_missing) {
				// Validate fields for product creation
				$errors = [];
				// Flexible search helper for validation
				$get_val = function ($key_or_name) use ($raw, $mapping) {
					// 1. Try mapping key
					$col_name = $mapping[$key_or_name] ?? $key_or_name;
					if (isset($raw[$col_name]) && !empty(trim($raw[$col_name]))) return trim($raw[$col_name]);

					// 2. Try case-insensitive search in raw keys
					$search = strtolower(trim($col_name));
					foreach ($raw as $k => $v) {
						if (strtolower(trim($k)) === $search) return trim($v);
					}
					return '';
				};

				$sku = $get_val('sku');
				$title = $get_val('title');
				$gs_type = $get_val('type');
				$status = $get_val('status');
				$title_en = $get_val('title_en');
				$parent_sku = $get_val('parent_sku');

				$price = $get_val('price');
				if (empty($price) && $type === 'variation' && !empty($parent_prices_lookup)) {
					$price = trim($parent_prices_lookup[$mapping['price'] ?? 'Prix'] ?? '');
				}
				$stock = $get_val('stock');

				// Rule: SKU
				if (empty($sku) || $sku === '00000000000000') {
					$errors[] = 'UGS invalide ou manquant.';
				}
				// Rule: Title
				if ($type !== 'variation' && empty($title)) {
					$errors[] = '"Nom du produit" est vide.';
				}
				// Rule: Type
				if (empty($gs_type)) {
					$errors[] = '"Type" est vide.';
				}
				// Rule: Status
				if ($type !== 'variation' && empty($status)) {
					$errors[] = '"Status" est vide.';
				}
				// Rule: Nom EN
				if ($type !== 'variation' && empty($title_en)) {
					$errors[] = '"Nom du produit EN" est vide.';
				}
				// Rule: parent SKU for variations
				if ($type === 'variation' && empty($parent_sku)) {
					$errors[] = '"UGS Parent" est obligatoire pour une variation.';
				}
				// Rule: parent must exist in WP for variations
				if ($type === 'variation' && !empty($parent_sku)) {
					$parent_id = 0;
					$parent_row = $wpdb->get_row($wpdb->prepare("SELECT product_id FROM {$this->table_name} WHERE sku = %s", $parent_sku));
					if ($parent_row && $parent_row->product_id) {
						$parent_id = intval($parent_row->product_id);
					}
					if (!$parent_id) {
						$parent_id = wc_get_product_id_by_sku($parent_sku);
					}
					if (!$parent_id || !wc_get_product($parent_id)) {
						$errors[] = 'Le produit parent (UGS: ' . esc_html($parent_sku) . ') n\'existe pas encore dans WooCommerce.';
					}
				}
				// Rule: price for simple/variation
				if (in_array($type, ['simple', 'variation']) && empty($price)) {
					$errors[] = '"Prix" est obligatoire et manquant (y compris chez le parent).';
				}
				// Rule: stock for simple
				if ($type === 'simple' && $stock === '') {
					$errors[] = '"Stock" est obligatoire pour ce type de produit.';
				}

				// Flexible check for UGS builder columns
				$check_group = function ($names) use ($raw) {
					foreach ($names as $name) {
						$search = strtolower(trim($name));
						foreach ($raw as $k => $v) {
							$key_norm = strtolower(trim($k));
							if (strpos($key_norm, $search) !== false && !empty(trim($v))) return true;
						}
					}
					return false;
				};

				// Rule: group 1 - category columns (UGS builder step 1)
				$cat_group = ['Affiche', 'Textile', 'Accessoire', 'Livre', 'LP', 'CD', 'DVD', 'Multimédia'];
				if (!$check_group($cat_group)) {
					$errors[] = 'Constructeur UGS étape 1 : aucune valeur dans Affiche / Textile / Accessoires / Livres / LP-CD-DVD / Multimédia.';
				}

				// Rule: group 2 - attribute columns (UGS builder step 2)
				$attr_group = ['Taille', 'Couleur', 'Modèle', 'Année'];
				if (!$check_group($attr_group)) {
					$errors[] = 'Constructeur UGS étape 2 : aucune valeur dans Tailles / Couleur / Modèle / Année.';
				}

				// Render creation panel
				echo '<div class="mjf-creation-panel" style="padding:15px; border:1px solid #ccd0d4; background:#f9f9f9; margin:0;">';
				echo '<h4 style="margin:0 0 10px; color:#2271b1;"><span class="dashicons dashicons-warning" style="color:#dc3232;"></span> Produit manquant dans WooCommerce</h4>';

				if (!empty($errors)) {
					echo '<p style="margin:0 0 8px; font-weight:600; color:#dc3232;">Erreurs de validation — Création impossible :</p>';
					echo '<ul style="margin:0 0 12px; color:#dc3232; list-style:disc; padding-left:20px;">';
					foreach ($errors as $err) {
						echo '<li>' . esc_html($err) . '</li>';
					}
					echo '</ul>';
					echo '<button class="button" disabled>Créer dans WooCommerce</button>';
				} else {
					echo '<p style="margin:0 0 12px; color:#46b450;"><span class="dashicons dashicons-yes-alt"></span> Toutes les données requises sont présentes.</p>';
					echo '<button class="button button-primary mjf-create-product-btn" data-id="' . esc_attr($item->id) . '">';
					echo '<span class="dashicons dashicons-plus-alt" style="font-size:16px; width:16px; height:16px; line-height:16px; vertical-align:middle; margin-top:-2px;"></span> Créer dans WooCommerce</button>';
				}
				echo '</div>';
				return;
			}

			if (isset($diffs['wpml_missing'])) {
				echo '<div class="mjf-translation-panel" style="padding:15px; border:1px solid #ccd0d4; background:#f0f6fb; margin-bottom:15px;">';
				echo '<h4 style="margin:0 0 10px; color:#2271b1;"><span class="dashicons dashicons-translation" style="color:#2271b1;"></span> Traduction EN manquante</h4>';

				$title_en = trim($raw[$mapping['title_en'] ?? 'Nom du produit EN'] ?? '');
				if ($type !== 'variation' && empty($title_en)) {
					echo '<p style="margin:0 0 8px; color:#dc3232; font-weight:600;">Erreur : "Nom du produit EN" est vide dans la Google Sheet. La traduction ne peut pas être créée.</p>';
				} else {
					if ($type === 'variation') {
						echo '<p style="margin:0 0 12px; color:#666;">La variation existe en Français mais sa version Anglaise n\'est pas encore générée par WPML.</p>';
					} else {
						echo '<p style="margin:0 0 12px; color:#666;">Le produit existe en Français mais n\'a pas encore de traduction Anglaise dans WooCommerce.</p>';
					}
					echo '<button class="button button-primary mjf-create-translation-btn" data-id="' . esc_attr($item->id) . '">';
					echo '<span class="dashicons dashicons-plus-alt" style="font-size:16px; width:16px; height:16px; line-height:16px; vertical-align:middle; margin-top:-2px;"></span> Créer la traduction Anglaise</button>';
				}
				echo '</div>';
				unset($diffs['wpml_missing']);
			}

			if (empty($diffs)) {
				echo '<div style="padding:10px; color:#666; font-style:italic;">Everything matches perfectly.</div>';
			} else {
				echo '<table class="wp-list-table widefat" style="margin:0;">';
				echo '<thead><tr><th>Meta</th><th>Google Sheet</th><th>WordPress</th><th style="width:200px; text-align:center;">Actions</th></tr></thead><tbody>';
				foreach ($diffs as $field => $values) {
					echo '<tr>';
					$label = isset($mapping[$field]) ? $mapping[$field] : $field;
					if ($field === 'image_main') {
						$label = 'Image principale';
					} elseif ($field === 'image_gallery') {
						$label = 'Galerie d\'images';
					}
					echo '<td style="width:15%; padding:8px; border-bottom:1px solid #ccd0d4;"><strong>' . esc_html($label) . '</strong></td>';

					$gs_render = is_array($values['gs']) ? print_r($values['gs'], true) : $values['gs'];
					$wp_render = is_array($values['wp']) ? print_r($values['wp'], true) : $values['wp'];

					echo '<td style="width:35%; background:#e6ffed; padding:8px; word-break:break-word; font-size:12px; border-bottom:1px solid #ccd0d4;">' . wp_kses_post($gs_render) . '</td>';
					echo '<td style="width:35%; background:#ffeef0; padding:8px; word-break:break-word; font-size:12px; border-bottom:1px solid #ccd0d4;">' . wp_kses_post($wp_render) . '</td>';
					echo '<td style="width:15%; padding:8px; text-align:center; border-bottom:1px solid #ccd0d4;">';
					echo '<div style="display:flex; flex-direction: column; gap:5px; justify-content:center;">';
					echo '<button class="button button-small mjf-inline-sync-btn" data-id="' . $item->id . '" data-field="' . esc_attr($field) . '" data-dir="gs_to_wp"><span class="dashicons dashicons-arrow-down-alt" style="font-size:16px; width:16px; height:16px; line-height:16px; vertical-align:middle; margin-top:-2px;"></span> Import from Google Sheet</button>';
					echo '<button class="button button-small mjf-inline-sync-btn" data-id="' . $item->id . '" data-field="' . esc_attr($field) . '" data-dir="wp_to_gs"><span class="dashicons dashicons-arrow-up-alt" style="font-size:16px; width:16px; height:16px; line-height:16px; vertical-align:middle; margin-top:-2px;"></span> Push to Google Sheet</button>';
					echo '</div></td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
		};

		$render_row = function ($item, $is_variation = false, $bg_color = '#fff', $var_status = '') use ($render_meta_table, $title_col) {
			if (!$item) return;
			$diff_data = $item->diff_data_decoded;
			$diffs = $diff_data['diff'] ?? [];

			$status_icon = '';
			if (($item->status === 'synced' || $item->status === 'synced_to_wp' || $item->status === 'synced_to_gs') && empty($var_status)) {
				$status_icon = '<span class="dashicons dashicons-yes-alt" style="color:#46b450; font-size: 24px;" title="Synced"></span>';
			} elseif ($item->status === 'missing_in_wp' || $var_status === 'missing_in_wp') {
				$status_icon = '<span class="dashicons dashicons-warning" style="color:#dc3232; font-size: 24px;" title="Missing in WP"></span>';
			} elseif ($item->status === 'translation_missing') {
				$status_icon = '<span class="dashicons dashicons-warning" style="color:#ffb900; font-size: 24px;" title="EN Translation Missing"></span>';
				$status_icon .= '<span class="dashicons dashicons-translation" style="color:#ffb900; font-size: 22px;" title="EN Translation Missing"></span>';
			} elseif (!empty($var_status)) {
				$status_icon = '<span class="dashicons dashicons-warning" style="color:#ffb900; font-size: 24px;" title="Une ou plusieurs variations ont un problème"></span>';
			} else {
				// Pending differences
				$status_icon = '<span class="dashicons dashicons-update" style="color:#ffb900; font-size: 24px;" title="Differences found"></span>';
			}

			$title = $diff_data['raw'][$title_col] ?? '';

			$edit_link = '';
			$lang_badges = '<div style="display:flex; gap:4px; font-size:10px; font-weight:bold;">';
			if ($item->product_id) {
				$edit_url = get_edit_post_link($item->product_id);
				if ($edit_url) {
					$edit_link = ' <a href="' . esc_url($edit_url) . '" target="_blank" title="Edit in WooCommerce" style="margin-left:8px; color:#2271b1;"><span class="dashicons dashicons-edit"></span></a>';
				}

				$post_type = $is_variation ? 'product_variation' : 'product';
				$has_fr = apply_filters('wpml_object_id', $item->product_id, $post_type, false, 'fr');
				$has_en = apply_filters('wpml_object_id', $item->product_id, $post_type, false, 'en');

				$fr_style = $has_fr ? 'background:#46b450; color:#fff;' : 'background:#eee; color:#aaa;';
				$en_style = $has_en ? 'background:#46b450; color:#fff;' : 'background:#eee; color:#aaa;';

				$lang_badges .= '<span style="padding:2px 6px; border-radius:3px; ' . $fr_style . '">FR</span>';
				$lang_badges .= '<span style="padding:2px 6px; border-radius:3px; ' . $en_style . '">EN</span>';
			} else {
				$lang_badges .= '<span style="padding:2px 6px; border-radius:3px; background:#eee; color:#aaa;">FR</span>';
				$lang_badges .= '<span style="padding:2px 6px; border-radius:3px; background:#eee; color:#aaa;">EN</span>';
			}
			$lang_badges .= '</div>';

			if (!$is_variation) {
				$var_warning_icon = '';
				if (!empty($var_status)) {
					$color = ($var_status === 'missing_in_wp') ? '#dc3232' : '#ffb900';
					$var_warning_icon = ' <span class="dashicons dashicons-warning" style="color:' . $color . '; vertical-align:middle; margin-left:8px; font-size:18px; width:18px; height:18px;" title="Une ou plusieurs variations ont un problème"></span>';
				}

				// Parent Row
				$wp_id_display = '—';
				if ($item->product_id) {
					$edit_url = get_edit_post_link($item->product_id);
					$wp_id_display = $edit_url ? '<a href="' . esc_url($edit_url) . '" target="_blank" style="font-family:monospace; font-weight:bold; color:#2271b1; text-decoration:none;">' . $item->product_id . ' <span class="dashicons dashicons-external" style="font-size:16px; width:16px; height:16px; line-height:16px; vertical-align:middle; margin-top:-2px;"></span></a>' : $item->product_id;
				}
				echo '<tr class="mjf-row-clickable" data-sku="' . esc_attr($item->sku) . '" style="background:' . $bg_color . '; box-shadow: 0 1px 1px rgba(0,0,0,.04); cursor:pointer;">';
				echo '<td style="vertical-align:middle; width:100px;">' . $wp_id_display . '</td>';
				echo '<td style="vertical-align:middle; width:150px;"><strong style="font-family:monospace; font-size:13px; letter-spacing:-0.5px;">' . esc_html($item->sku) . '</strong></td>';
				echo '<td style="vertical-align:middle;">' . esc_html($title) . $var_warning_icon . '</td>';
				echo '<td style="vertical-align:middle; width:100px;">' . $lang_badges . '</td>';
				echo '<td style="text-align:center; vertical-align:middle; width:100px;"><div style="display:flex; align-items:center; justify-content:center; gap: 8px;">' . $status_icon . '</div></td>';
				echo '<td style="vertical-align:middle; width:40px;"><span class="dashicons dashicons-arrow-down-alt2 mjf-chevron-icon" style="color:#888;"></span></td>';
				echo '</tr>';

				// Details drawer (opened by toggle)
				echo '<tr class="mjf-variation-row" data-parent-sku="' . esc_attr($item->sku) . '" style="display:none; background-color:' . $bg_color . ';">';
				echo '<td colspan="6" style="padding: 10px 20px; border-left: 4px solid #2271b1;">';

				echo '<h4 style="margin-top:0;">Parent Metadata</h4>';
				$render_meta_table($item);
			} else {
				// Variation Title logic
				$attr_title = '';
				if ($item->product_id) {
					$v_product = wc_get_product($item->product_id);
					if ($v_product && $v_product->is_type('variation')) {
						$attrs = $v_product->get_variation_attributes();
						$parts = [];
						foreach ($attrs as $k => $v) {
							if ($v) {
								$attr_name = str_replace('attribute_pa_', '', $k);
								$attr_name = str_replace('attribute_', '', $attr_name);
								$attr_name = ucfirst(str_replace('-', ' ', $attr_name));
								$val = ucfirst(urldecode(str_replace('-', ' ', $v)));
								$parts[] = $val . ' (' . $attr_name . ')';
							}
						}
						$attr_title = implode(', ', $parts);
					}
				}
				if (empty($attr_title)) {
					$raw = $item->diff_data_decoded['raw'] ?? [];
					$potential_attrs = ['Tailles', 'Couleur', 'Modèle'];
					$found = [];
					foreach ($potential_attrs as $pa) {
						if (!empty($raw[$pa])) $found[] = $raw[$pa] . ' (' . $pa . ')';
					}
					$attr_title = !empty($found) ? implode(', ', $found) : 'Variation';
				}

				// Variation Row inside variation table
				$wp_id_display = '—';
				if ($item->product_id) {
					$edit_url = get_edit_post_link($item->product_id);
					$wp_id_display = $edit_url ? '<a href="' . esc_url($edit_url) . '" target="_blank" style="font-family:monospace; font-weight:bold; color:#2271b1; text-decoration:none;">' . $item->product_id . ' <span class="dashicons dashicons-external" style="font-size:11px; width:11px; height:11px; line-height:11px; vertical-align:middle; margin-top:-2px;"></span></a>' : $item->product_id;
				}
				echo '<tr class="mjf-row-clickable" data-sku="' . esc_attr($item->sku) . '" style="background:#fff; cursor:pointer;">';
				echo '<td style="vertical-align:middle; width:100px;"><span class="dashicons dashicons-subdirectory-right" style="color:#ccc; margin-right:5px;"></span>' . $wp_id_display . '</td>';
				echo '<td><code style="font-family:monospace; background:none; padding:0; color:inherit; font-size:12px;">' . esc_html($item->sku) . '</code></td>';
				echo '<td><strong>' . esc_html($attr_title) . '</strong></td>';
				echo '<td style="vertical-align:middle;">' . $lang_badges . '</td>';
				echo '<td style="text-align:center;"><div style="display:flex; align-items:center; justify-content:center; gap: 8px;">' . $status_icon . '</div></td>';
				echo '<td><span class="dashicons dashicons-arrow-down-alt2 mjf-chevron-icon" style="color:#888;"></span></td>';
				echo '</tr>';

				// Variation Details drawer
				echo '<tr class="mjf-variation-row" data-parent-sku="' . esc_attr($item->sku) . '" style="display:none;">';
				echo '<td colspan="6" style="padding: 10px 20px; border-left: 4px solid #ffb900; background:#fafafa;">';
				$render_meta_table($item);
				echo '</td></tr>';
			}
		};

		ob_start();
		if (empty($paged_groups)) {
			echo '<p>No data found.</p>';
		} else {
			echo '<table class="wp-list-table widefat striped" style="margin-top:15px; border:1px solid #ccd0d4; border-spacing:0;">';
			echo '<thead><tr>';
			echo '<th style="width:100px;">WP ID</th>';
			echo '<th style="width:150px;">SKU</th>';
			echo '<th>Titre</th>';
			echo '<th style="width:100px;">Langues</th>';
			echo '<th style="text-align:center; width:150px;">Status</th>';
			echo '<th style="width:40px;"></th>';
			echo '</tr></thead><tbody>';

			$row_count = 0;
			foreach ($paged_groups as $group) {
				if ($group['parent']) {
					$row_count++;
					$bg = ($row_count % 2 === 0) ? '#f9f9f9' : '#fff';

					$var_status = '';
					if (!empty($group['variations'])) {
						foreach ($group['variations'] as $var) {
							if ($var->status !== 'synced' && $var->status !== 'synced_to_wp' && $var->status !== 'synced_to_gs') {
								if ($var->status === 'missing_in_wp') {
									$var_status = 'missing_in_wp';
									break;
								} else {
									$var_status = 'pending';
								}
							}
						}
					}

					$render_row($group['parent'], false, $bg, $var_status);

					// Variations section
					if (!empty($group['variations'])) {
						$has_missing_translations = false;
						$missing_var_id = 0;
						foreach ($group['variations'] as $var) {
							if ($var->status === 'translation_missing') {
								$has_missing_translations = true;
								$missing_var_id = $var->id;
								break;
							}
						}

						echo '<h4 style="margin-top:20px; margin-bottom:10px;">Variations</h4>';
						if ($has_missing_translations) {
							echo '<button class="button button-small mjf-create-translation-btn" data-id="' . esc_attr($missing_var_id) . '" style="margin-bottom:10px;"><span class="dashicons dashicons-translation" style="font-size:14px; width:14px; height:14px; line-height:14px; vertical-align:middle; margin-top:-2px; color:#2271b1;"></span> Générer les traductions EN manquantes</button>';
						}
						echo '<table class="wp-list-table widefat" style="margin-bottom:20px; border:1px solid #eee;">';
						echo '<thead><tr><th style="width:100px;">WP ID</th><th style="width:150px;">SKU</th><th>Variation (Attributs)</th><th style="width:100px;">Langues</th><th style="text-align:center; width:100px;">Status</th><th style="width:40px;"></th></tr></thead>';
						echo '<tbody>';
						foreach ($group['variations'] as $variation) {
							$render_row($variation, true);
						}
						echo '</tbody></table>';
					}

					// Close parent td/tr
					echo '</td></tr>';
				}
			}
			echo '</tbody></table>';

			// Pagination
			if ($total_pages > 1) {
				echo '<div class="tablenav-pages" style="margin-top:15px;">';
				for ($i = 1; $i <= $total_pages; $i++) {
					echo '<button class="button mjf-page-btn' . ($i === $page ? 'button-primary' : '') . '" data-page="' . $i . '">' . $i . '</button> ';
				}
				echo '</div>';
			}
		}

		wp_send_json_success(['html' => ob_get_clean()]);
	}

	public function ajax_sync_single_field()
	{
		if (! current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

		$id        = isset($_POST['id']) ? intval($_POST['id']) : 0;
		$field     = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
		$direction = isset($_POST['direction']) ? sanitize_text_field($_POST['direction']) : '';

		if (!$id || !$field || !$direction) wp_send_json_error('Missing parameters');

		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
		if (!$row) wp_send_json_error('Row not found');

		$diff_data = json_decode($row->diff_data, true);
		$raw_item = $diff_data['raw'] ?? [];
		$diffs = $diff_data['diff'] ?? [];

		if (!isset($diffs[$field])) wp_send_json_success(['status' => 'synced']);

		$vals = $diffs[$field];
		$mapping = $this->get_hardcoded_mapping();

		if ($direction === 'gs_to_wp') {
			if ($field === 'stock' && $row->product_id) {
				wp_send_json_error('L\'importation du stock depuis Google Sheet est bloquée pour les produits existants afin de préserver les données de vente.');
			}
			$this->sync_field_to_wp($row->product_id, $field, $vals['gs'], $raw_item);
		} else if ($direction === 'wp_to_gs') {
			$gs_col = $mapping[$field] ?? $field;
			// Write to sheet
			$result = $this->api->write_to_google_sheet($row->gs_row_index, $gs_col, $vals['wp']);
			if (is_wp_error($result)) {
				wp_send_json_error($result->get_error_message());
			}
		}

		// Remove field from diffs
		unset($diffs[$field]);
		$diff_data['diff'] = $diffs;

		$new_status = empty($diffs) ? 'synced' : $row->status;

		$wpdb->update($this->table_name, [
			'status' => $new_status,
			'diff_data' => wp_json_encode($diff_data)
		], ['id' => $id]);

		wp_send_json_success([
			'new_status' => $new_status,
			'message' => 'Field synced successfully.'
		]);
	}

	/**
	 * Create a missing product in WooCommerce from Google Sheet data
	 */
	public function ajax_create_product()
	{
		if (! current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

		$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
		if (!$id) wp_send_json_error('Missing parameters');

		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
		if (!$row) wp_send_json_error('Entrée introuvable en base.');

		global $sitepress;
		$original_lang = null;
		if (isset($sitepress)) {
			$original_lang = $sitepress->get_current_language();
			$sitepress->switch_lang('fr');
		}

		$diff_data = json_decode($row->diff_data, true);
		$raw = $diff_data['raw'] ?? [];
		$mapping = $this->get_hardcoded_mapping();

		$type_raw = strtolower(trim($raw[$mapping['type'] ?? 'Type'] ?? ''));
		$sku      = trim($raw[$mapping['sku'] ?? 'UGS'] ?? '');
		$title    = trim($raw[$mapping['title'] ?? 'Nom du produit'] ?? '');
		$status   = trim($raw[$mapping['status'] ?? 'Status'] ?? '');
		$parent_sku = trim($raw[$mapping['parent_sku'] ?? 'UGS Parent'] ?? '');
		$price    = trim($raw[$mapping['price'] ?? 'Prix'] ?? '');
		$stock    = trim($raw[$mapping['stock'] ?? 'Stock'] ?? '');
		$weight   = trim($raw[$mapping['weight'] ?? 'Poids (kg)'] ?? '');
		$description   = $raw[$mapping['description'] ?? 'Description FR'] ?? '';
		$short_desc    = $raw[$mapping['short_description'] ?? 'Contenu additionel FR'] ?? '';
		$title_en      = trim($raw[$mapping['title_en'] ?? 'Nom du produit EN'] ?? '');
		$description_en = $raw[$mapping['description_en'] ?? 'Description EN'] ?? '';
		$content_en    = $raw[$mapping['content_en'] ?? 'Contenu additionel EN'] ?? '';
		$cat_primary   = trim($raw[$mapping['cat_primary'] ?? 'Catégorie principale'] ?? '');
		$cat_secondary = trim($raw[$mapping['cat_secondary'] ?? 'Catégorie secondaire'] ?? '');
		$shipping_class = trim($raw[$mapping['shipping_class'] ?? "Classe d'expédition"] ?? '');

		// Inherit price from parent if variation and price is empty
		if ($type_raw === 'variation' && empty($price) && !empty($parent_sku)) {
			$parent_entry = $wpdb->get_row($wpdb->prepare("SELECT diff_data FROM {$this->table_name} WHERE sku = %s", $parent_sku));
			if ($parent_entry) {
				$parent_dd = json_decode($parent_entry->diff_data, true);
				$price = trim($parent_dd['raw'][$mapping['price'] ?? 'Prix'] ?? '');
			}
		}

		// Map WP post status
		$status_map = [
			'publish' => 'publish',
			'publié' => 'publish',
			'publie' => 'publish',
			'active' => 'publish',
			'draft'   => 'draft',
			'brouillon' => 'draft',
			'private' => 'private',
			'privé' => 'private',
		];
		$wp_status = $status_map[strtolower($status)] ?? 'draft';

		try {
			if ($type_raw === 'variation') {
				// Create as variation of an existing parent
				$parent_id = 0;
				$parent_row = $wpdb->get_row($wpdb->prepare("SELECT product_id FROM {$this->table_name} WHERE sku = %s", $parent_sku));
				if ($parent_row && $parent_row->product_id) {
					$parent_id = intval($parent_row->product_id);
				}
				if (!$parent_id) {
					$parent_id = wc_get_product_id_by_sku($parent_sku);
				}

				global $sitepress;
				if (isset($sitepress) && $parent_id) {
					$fr_parent_id = apply_filters('wpml_object_id', $parent_id, 'product', true, 'fr');
					if ($fr_parent_id) {
						$parent_id = $fr_parent_id;
					}
				}

				if (!$parent_id) wp_send_json_error('Produit parent introuvable dans WooCommerce.');

				$variation = new WC_Product_Variation();
				$variation->set_parent_id($parent_id);
				$variation->set_sku($sku);
				$variation->set_status('publish');
				if (!empty($price)) {
					$price_clean = (float) str_replace(',', '.', $price);
					$variation->set_regular_price($price_clean);
					$variation->set_price($price_clean);
				}
				if ($stock !== '') {
					$variation->set_manage_stock(true);
					$variation->set_stock_quantity((int) $stock);
				} else {
					$variation->set_manage_stock(true);
					$variation->set_stock_quantity(0);
				}
				if (!empty($weight)) $variation->set_weight((float) str_replace(',', '.', $weight));

				// Set Attributes
				$parent_product = wc_get_product($parent_id);
				if ($parent_product) {
					$parent_attributes = $parent_product->get_attributes();
					$attr_data = [];
					$attr_columns = ['Taille', 'Couleur', 'Modèle', 'Année'];
					foreach ($attr_columns as $col) {
						$matched_col = null;
						$val = $this->extract_attribute_from_raw($raw, $col, $matched_col);
						if (!empty($val)) {
							$tax_name = wc_attribute_taxonomy_name($matched_col);
							$matched_key = false;

							if (isset($parent_attributes[$tax_name])) {
								$matched_key = $tax_name;
							} else {
								foreach ($parent_attributes as $key => $attr_obj) {
									$label = wc_attribute_label($key);
									if ($attr_obj->get_name() === $col || $label === $col || $label === rtrim($col, 's')) {
										$matched_key = $key;
										break;
									}
								}
							}

							if (!$matched_key) {
								$matched_key = $tax_name;
								$new_attr = new WC_Product_Attribute();
								$new_attr->set_id(wc_attribute_taxonomy_id_by_name($matched_key));
								$new_attr->set_name($matched_key);
								$new_attr->set_options([]);
								$new_attr->set_visible(true);
								$new_attr->set_variation($matched_key === 'pa_tailles' || $matched_key === 'pa_taille');
								$parent_attributes[$matched_key] = $new_attr;
								$parent_product->set_attributes($parent_attributes);
								$parent_product->save();
							}

							if ($parent_attributes[$matched_key]->is_taxonomy()) {
								$term = get_term_by('name', $val, $matched_key);
								if (!$term) {
									// Auto-create missing term
									$inserted = wp_insert_term($val, $matched_key);
									if (!is_wp_error($inserted)) {
										$term = get_term($inserted['term_id'], $matched_key);
										$term_id_to_add = $term->term_id;
									} elseif (is_wp_error($inserted) && isset($inserted->error_data['term_exists'])) {
										$term_id_to_add = $inserted->error_data['term_exists'];
										$term = get_term($term_id_to_add, $matched_key);
									} else {
										$term = false;
									}
								} else {
									$term_id_to_add = $term->term_id;
								}

								if ($term) {
									// Add to parent product attribute options
									$options = $parent_attributes[$matched_key]->get_options();
									if (!in_array($term_id_to_add, $options)) {
										$options[] = $term_id_to_add;
										$parent_attributes[$matched_key]->set_options($options);
										$parent_product->set_attributes($parent_attributes);
										$parent_product->save();
										wp_set_object_terms($parent_id, $options, $matched_key);
									}
								}
								$attr_data[$matched_key] = $term ? $term->slug : sanitize_title($val);
							} else {
								$attr_data[$matched_key] = $val;
							}
						}
					}
					if (!empty($attr_data)) {
						$variation->set_attributes($attr_data);
					} else {
						wp_send_json_error('Impossible de générer la variation : aucun attribut valide trouvé. Vérifiez que le produit parent a bien ses attributs configurés et enregistrés dans WooCommerce.');
					}
				}

				if (!empty($shipping_class)) {
					$this->set_product_shipping_class($variation, $shipping_class);
				}

				$new_id = $variation->save();

				// Trigger WPML variation sync
				global $woocommerce_wpml;
				if (isset($woocommerce_wpml) && isset($woocommerce_wpml->sync_variations_data)) {
					$woocommerce_wpml->sync_variations_data->sync_product_variations_action($parent_id);
				}
				// Force parent to rebuild children cache
				$parent_product = wc_get_product($parent_id);
				if ($parent_product) $parent_product->save();
			} elseif ($type_raw === 'variable') {
				$product = new WC_Product_Variable();
				$product->set_name($title);
				$product->set_sku($sku);
				$product->set_status($wp_status);
				if (!empty($description)) $product->set_description($description);
				if (!empty($short_desc)) $product->set_short_description($short_desc);

				// Set Categories
				if (!empty($cat_primary) || !empty($cat_secondary)) {
					$cat_ids = $this->get_category_ids($cat_primary, $cat_secondary);
					if (!empty($cat_ids)) {
						$product->set_category_ids($cat_ids);
					}
				}

				// Set Attributes
				$formatted_item = $this->format_raw_for_upsert($raw);
				if (!empty($formatted_item['attributes'])) {
					$this->set_variable_attributes($product, $formatted_item['attributes'], $formatted_item['attr_settings']);
				}

				if (!empty($shipping_class)) {
					$this->set_product_shipping_class($product, $shipping_class);
				}

				$new_id = $product->save();
			} else {
				// Simple product
				$product = new WC_Product_Simple();
				$product->set_name($title);
				$product->set_sku($sku);
				$product->set_status($wp_status);
				if (!empty($description)) $product->set_description($description);
				if (!empty($short_desc)) $product->set_short_description($short_desc);
				if (!empty($price)) {
					$price_clean = (float) str_replace(',', '.', $price);
					$product->set_regular_price($price_clean);
					$product->set_price($price_clean);
				}
				if ($stock !== '') {
					$product->set_manage_stock(true);
					$product->set_stock_quantity((int) $stock);
				}
				if (!empty($weight)) $product->set_weight((float) str_replace(',', '.', $weight));

				// Set Categories
				if (!empty($cat_primary) || !empty($cat_secondary)) {
					$cat_ids = $this->get_category_ids($cat_primary, $cat_secondary);
					if (!empty($cat_ids)) {
						$product->set_category_ids($cat_ids);
					}
				}

				if (!empty($shipping_class)) {
					$this->set_product_shipping_class($product, $shipping_class);
				}

				$new_id = $product->save();
			}

			if (!$new_id || is_wp_error($new_id)) {
				wp_send_json_error('Échec lors de la sauvegarde du produit dans WooCommerce.');
			}

			// Force WPML language to FR for the newly created product
			global $sitepress;
			if (isset($sitepress)) {
				$wpml_element_type = apply_filters('wpml_element_type', 'post_product');
				if ($type_raw === 'variation') {
					$wpml_element_type = apply_filters('wpml_element_type', 'post_product_variation');
				}
				$language_details = apply_filters('wpml_element_language_details', null, ['element_id' => $new_id, 'element_type' => $wpml_element_type]);
				if (empty($language_details) || $language_details->language_code !== 'fr') {
					$sitepress->set_element_language_details($new_id, $wpml_element_type, false, 'fr');
				}
			}

			// Store EN data as post meta for reference
			update_post_meta($new_id, '_mjf_title_en', sanitize_text_field($title_en));
			update_post_meta($new_id, '_mjf_description_en', wp_kses_post($description_en));
			update_post_meta($new_id, '_mjf_content_en', wp_kses_post($content_en));

			// Store FedEx custom meta
			$fedex_map = [
				'_wf_hs_code'                     => 'Numéro de tarif HS',
				'_ph_commodity_description'       => 'Description Fedex',
				'_wf_freight_class'               => 'Classe de fret',
				'_ph_fedex_signature_option'      => 'Signature de livraison',
				'_ph_manufacture_country'         => 'Pays de fabrication',
				'_wf_manufacture_country'         => 'Pays de fabrication',
				'_wf_fedex_custom_declared_value' => 'Custom Declared Value',
			];
			foreach ($fedex_map as $meta_key => $gs_col) {
				$val = trim($raw[$gs_col] ?? '');
				if ($val !== '') update_post_meta($new_id, $meta_key, $this->normalize_fedex_select_value($meta_key, $val));
			}

			// Write the new WP ID back to the Google Sheet
			$wp_id_col = $mapping['wp_id'] ?? 'WP ID';
			if ($wp_id_col) {
				$this->api->write_to_google_sheet($row->gs_row_index, $wp_id_col, $new_id);
			}

			// Refresh this item status
			$new_diff = $this->compare_item($new_id, $raw, $mapping, $type_raw);
			$status_new = empty($new_diff) ? 'synced' : 'pending';

			// Update sync table
			$wpdb->update($this->table_name, [
				'product_id' => $new_id,
				'status'     => $status_new,
				'diff_data'  => wp_json_encode([
					'type'       => $type_raw,
					'parent_sku' => $parent_sku,
					'raw'        => $raw,
					'diff'       => $new_diff
				])
			], ['id' => $id]);

			if (isset($sitepress) && $original_lang) {
				$sitepress->switch_lang($original_lang);
			}

			wp_send_json_success(['message' => 'Produit créé avec succès (ID: ' . $new_id . ').']);
		} catch (\Exception $e) {
			if (isset($sitepress) && $original_lang) {
				$sitepress->switch_lang($original_lang);
			}
			wp_send_json_error('Exception : ' . $e->getMessage());
		}
	}

	public function ajax_create_translation()
	{
		if (! current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

		$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
		if (!$id) wp_send_json_error('Missing ID');

		global $wpdb;
		$item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
		if (!$item) wp_send_json_error('Item not found in queue.');

		$diff_data = json_decode($item->diff_data, true);
		$raw = $diff_data['raw'] ?? [];
		$mapping = $this->get_hardcoded_mapping();
		$sku = $item->sku;

		$product_id = $item->product_id;
		if (!$product_id) {
			$product_id = wc_get_product_id_by_sku($sku);
		}
		if (!$product_id) wp_send_json_error('Original product (FR) not found.');

		$title_en = trim($raw[$mapping['title_en'] ?? 'Nom du produit EN'] ?? '');
		$type = $diff_data['type'] ?? 'simple';

		if ($type === 'variation') {
			$parent_sku = $diff_data['parent_sku'] ?? '';
			if (!$parent_sku) wp_send_json_error('Parent SKU not found for this variation.');

			$parent_id = 0;
			$parent_row = $wpdb->get_row($wpdb->prepare("SELECT product_id FROM {$this->table_name} WHERE sku = %s", $parent_sku));
			if ($parent_row && $parent_row->product_id) {
				$parent_id = intval($parent_row->product_id);
			}
			if (!$parent_id) {
				$parent_id = wc_get_product_id_by_sku($parent_sku);
			}
			if (!$parent_id) wp_send_json_error('Parent product not found.');

			global $woocommerce_wpml;
			if (isset($woocommerce_wpml) && isset($woocommerce_wpml->sync_variations_data)) {
				$woocommerce_wpml->sync_variations_data->sync_product_variations_action($parent_id);
			}
		} else {
			if (empty($title_en)) wp_send_json_error('English title is required.');
			$formatted_item = $this->format_raw_for_upsert($raw);
			$this->upsert_translation($product_id, $formatted_item, 'en');
		}

		// Refresh this item status
		$new_diff = $this->compare_item($product_id, $raw, $mapping, $type);
		$status = empty($new_diff) ? 'synced' : 'pending';

		$wpdb->update($this->table_name, [
			'status' => $status,
			'diff_data' => wp_json_encode([
				'type' => $diff_data['type'] ?? 'simple',
				'parent_sku' => $diff_data['parent_sku'] ?? '',
				'raw' => $raw,
				'diff' => $new_diff
			])
		], ['id' => $id]);

		wp_send_json_success('English translation created successfully.');
	}

	public function ajax_update_sync_direction()
	{
		if (! current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

		$id        = isset($_POST['id']) ? intval($_POST['id']) : 0;
		$field     = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
		$direction = isset($_POST['direction']) ? sanitize_text_field($_POST['direction']) : '';

		if (!$id || !$field || !$direction) {
			wp_send_json_error('Missing parameters');
		}

		global $wpdb;
		$row = $wpdb->get_row($wpdb->prepare("SELECT diff_data FROM {$this->table_name} WHERE id = %d", $id));
		if ($row) {
			$diff_data = json_decode($row->diff_data, true);
			$diff_data['directions'][$field] = $direction;
			$wpdb->update($this->table_name, ['diff_data' => wp_json_encode($diff_data)], ['id' => $id]);
			wp_send_json_success();
		}
		wp_send_json_error('Row not found');
	}

	public function ajax_execute_sync()
	{
		if (! current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied');

		@ini_set('memory_limit', '512M');

		global $wpdb;
		$table_name = $this->table_name;

		$items = $wpdb->get_results("SELECT * FROM {$table_name} WHERE status = 'pending' LIMIT 10");

		if (empty($items)) {
			wp_send_json_success(['message' => 'Sync complete!', 'continue' => false]);
		}

		$processed = 0;
		$mapping = $this->get_hardcoded_mapping();

		foreach ($items as $item) {
			$diff_data = json_decode($item->diff_data, true);
			$raw_item = $diff_data['raw'] ?? [];
			$diffs = $diff_data['diff'] ?? [];
			$directions = $diff_data['directions'] ?? [];

			foreach ($diffs as $field => $vals) {
				if ($field === 'stock' && $item->product_id) continue;

				$dir = $directions[$field] ?? 'gs_to_wp';
				if ($dir === 'ignore') continue;

				if ($dir === 'gs_to_wp') {
					$this->sync_field_to_wp($item->product_id, $field, $vals['gs'], $raw_item);
				} else if ($dir === 'wp_to_gs') {
					$gs_col = $mapping[$field] ?? '';
					if ($gs_col) {
						$this->api->write_to_google_sheet($item->gs_row_index, $gs_col, $vals['wp']);
					}
				}
			}
			$wpdb->update($table_name, ['status' => 'processed'], ['id' => $item->id]);
			$processed++;
		}

		wp_send_json_success([
			'message' => "Processed $processed items.",
			'continue' => true
		]);
	}

	private function sync_field_to_wp($product_id, $field, $value, $raw_item)
	{
		if (!$product_id) return;

		// Block GS to WP stock import for existing products to preserve real store stock
		if ($field === 'stock') {
			return;
		}

		$product = wc_get_product($product_id);
		if (!$product) return;

		// Handle WPML Translation fields
		if (in_array($field, ['title_en', 'description_en', 'content_en', 'wpml_missing'])) {
			$formatted_item = $this->format_raw_for_upsert($raw_item);
			$this->upsert_translation($product_id, $formatted_item, 'en');
			return;
		}

		global $sitepress;
		$original_lang = null;
		if (isset($sitepress)) {
			$original_lang = $sitepress->get_current_language();
			$sitepress->switch_lang('fr');
		}

		switch ($field) {
			case 'sku':
				$product->set_sku($value);
				break;
			case 'title':
				$product->set_name($value);
				break;
			case 'price':
				$product->set_regular_price($value);
				break;
			case 'stock':
				$product->set_stock_quantity($value);
				$product->set_manage_stock(true);
				break;
			case 'weight':
				$product->set_weight($value);
				break;
			case 'description':
				$product->set_description($value);
				break;
			case 'short_description':
				$product->set_short_description($value);
				break;
			case 'image_main':
				$mapping = $this->get_hardcoded_mapping();
				$image_slug_col = $mapping['image_slug'] ?? 'Slug Image';
				$slug = trim($raw_item[$image_slug_col] ?? '');
				if ($slug) {
					$id = $this->find_attachment_by_base_name($slug . '-main');
					if ($id) $product->set_image_id($id);
				}
				break;
			case 'image_gallery':
				$mapping = $this->get_hardcoded_mapping();
				$image_slug_col = $mapping['image_slug'] ?? 'Slug Image';
				$slug = trim($raw_item[$image_slug_col] ?? '');
				if ($slug) {
					$ids = [];
					for ($i = 1; $i <= 25; $i++) {
						$id = $this->find_attachment_by_base_name($slug . '-' . $i);
						if ($id) $ids[] = (int)$id;
					}
					if (!empty($ids)) $product->set_gallery_image_ids($ids);
				}
				break;
			case 'cat_primary':
				$mapping = $this->get_hardcoded_mapping();
				$sec_col = $mapping['cat_secondary'] ?? 'Catégorie secondaire';
				$sec_val = isset($raw_item[$sec_col]) ? trim($raw_item[$sec_col]) : '';
				$cat_ids = $this->get_category_ids($value, $sec_val);
				$product->set_category_ids($cat_ids);
				break;
			case 'cat_secondary':
				$mapping = $this->get_hardcoded_mapping();
				$prim_col = $mapping['cat_primary'] ?? 'Catégorie principale';
				$prim_val = isset($raw_item[$prim_col]) ? trim($raw_item[$prim_col]) : '';
				$cat_ids = $this->get_category_ids($prim_val, $value);
				$product->set_category_ids($cat_ids);
				break;
			case 'attributes':
				$formatted_item = $this->format_raw_for_upsert($raw_item);
				$this->set_variable_attributes($product, $formatted_item['attributes'], $formatted_item['attr_settings']);
				// Force WC to sync term relationships
				$product_attributes = $product->get_attributes();
				foreach ($product_attributes as $attr) {
					if ($attr && $attr->is_taxonomy()) {
						wp_set_object_terms($product->get_id(), $attr->get_options(), $attr->get_name());
					}
				}
				break;
			case 'shipping_class':
				$this->set_product_shipping_class($product, $value);
				break;
			default:
				// Meta keys
				if (strpos($field, '_') === 0) {
					$normalized_value = $this->normalize_fedex_select_value($field, $value);
					$product->update_meta_data($field, $normalized_value);
					// Keep both _ph_manufacture_country and _wf_manufacture_country in sync
					if ($field === '_ph_manufacture_country') {
						$product->update_meta_data('_wf_manufacture_country', $normalized_value);
					} elseif ($field === '_wf_manufacture_country') {
						$product->update_meta_data('_ph_manufacture_country', $normalized_value);
					}
				}
		}
		$product->save();

		// Sync changes to English translation if active
		$product_type = $product->get_type();
		if ($product_type === 'variation') {
			$parent_id = $product->get_parent_id();
			global $woocommerce_wpml;
			if (isset($woocommerce_wpml) && isset($woocommerce_wpml->sync_variations_data)) {
				$woocommerce_wpml->sync_variations_data->sync_product_variations_action($parent_id);
			}
			if ($field === 'shipping_class') {
				$translated_id = apply_filters('wpml_object_id', $product_id, 'product_variation', false, 'en');
				if ($translated_id && $translated_id !== $product_id) {
					$translated_product = wc_get_product($translated_id);
					if ($translated_product) {
						global $sitepress;
						$orig_lang_for_sync = null;
						if (isset($sitepress)) {
							$orig_lang_for_sync = $sitepress->get_current_language();
							$sitepress->switch_lang('en');
						}

						$wp_shipping_class_id = $product->get_shipping_class_id();
						if ($wp_shipping_class_id) {
							$translated_class_id = $this->translate_or_create_term($wp_shipping_class_id, 'product_shipping_class', 'en');
							$translated_product->set_shipping_class_id($translated_class_id);
							if ($translated_product->get_id()) {
								wp_set_object_terms($translated_product->get_id(), array((int)$translated_class_id), 'product_shipping_class');
							}
						} else {
							$translated_product->set_shipping_class_id(0);
							if ($translated_product->get_id()) {
								wp_set_object_terms($translated_product->get_id(), array(), 'product_shipping_class');
							}
						}
						$translated_product->save();

						if (isset($sitepress) && $orig_lang_for_sync) {
							$sitepress->switch_lang($orig_lang_for_sync);
						}
					}
				}
			}
		} else {
			$translated_id = apply_filters('wpml_object_id', $product_id, 'product', false, 'en');
			if ($translated_id && $translated_id !== $product_id) {
				$translated_product = wc_get_product($translated_id);
				if ($translated_product) {
					switch ($field) {
						case 'price':
							$translated_product->set_regular_price($value);
							$translated_product->save();
							break;
						case 'stock':
							$translated_product->set_stock_quantity($value);
							$translated_product->set_manage_stock(true);
							$translated_product->save();
							break;
						case 'weight':
							$translated_product->set_weight($value);
							$translated_product->save();
							break;
						case 'shipping_class':
							global $sitepress;
							$orig_lang_for_sync = null;
							if (isset($sitepress)) {
								$orig_lang_for_sync = $sitepress->get_current_language();
								$sitepress->switch_lang('en');
							}

							$wp_shipping_class_id = $product->get_shipping_class_id();
							if ($wp_shipping_class_id) {
								$translated_class_id = $this->translate_or_create_term($wp_shipping_class_id, 'product_shipping_class', 'en');
								$translated_product->set_shipping_class_id($translated_class_id);
								if ($translated_product->get_id()) {
									wp_set_object_terms($translated_product->get_id(), array((int)$translated_class_id), 'product_shipping_class');
								}
							} else {
								$translated_product->set_shipping_class_id(0);
								if ($translated_product->get_id()) {
									wp_set_object_terms($translated_product->get_id(), array(), 'product_shipping_class');
								}
							}
							$translated_product->save();

							if (isset($sitepress) && $orig_lang_for_sync) {
								$sitepress->switch_lang($orig_lang_for_sync);
							}
							break;
						case 'attributes':
							$this->sync_attributes_to_translation($product, $translated_product, 'en');
							if ($product_type === 'variable') {
								global $woocommerce_wpml;
								if (isset($woocommerce_wpml) && isset($woocommerce_wpml->sync_variations_data)) {
									$woocommerce_wpml->sync_variations_data->sync_product_variations_action($product_id);
								}
							}
							break;
					}
				}
			}
		}

		if (isset($sitepress) && $original_lang) {
			$sitepress->switch_lang($original_lang);
		}
	}

	/**
	 * Normalize FedEx select field values coming from Google Sheets.
	 *
	 * For _wf_freight_class: accepts "CLASS 125" or "125" → stores "125"
	 * For _ph_fedex_signature_option: accepts French labels or raw values → stores the correct constant
	 */
	private function normalize_fedex_select_value($meta_key, $value)
	{
		$value = trim((string) $value);

		if ($meta_key === '_wf_freight_class') {
			// Accept "CLASS 125", "class 125", or plain "125" / "77.5"
			$normalized = preg_replace('/^class\s*/i', '', $value);
			$normalized = trim($normalized);
			// Valid values for this select field
			$valid = ['50', '55', '60', '65', '70', '77.5', '85', '92.5', '100', '110', '125', '150', '175', '200', '250', '300', '400', '500'];
			if (in_array($normalized, $valid, true)) {
				return $normalized;
			}
			// Return empty if not recognized so we don't pollute the field
			return '';
		}

		if ($meta_key === '_ph_fedex_signature_option') {
			// Map French GS labels → FedEx constant values
			$label_map = [
				'matinée'              => 'ADULT',
				'matinee'              => 'ADULT',
				'direct'               => 'DIRECT',
				'indirect'             => 'INDIRECT',
				'signature obligatoire' => 'NO_SIGNATURE_REQUIRED',
				'service par défaut'   => 'SERVICE_DEFAULT',
				'service par defaut'   => 'SERVICE_DEFAULT',
			];
			// Also accept raw constant values as-is
			$valid_constants = ['ADULT', 'DIRECT', 'INDIRECT', 'NO_SIGNATURE_REQUIRED', 'SERVICE_DEFAULT'];
			if (in_array($value, $valid_constants, true)) {
				return $value;
			}
			$key = mb_strtolower($value, 'UTF-8');
			if (isset($label_map[$key])) {
				return $label_map[$key];
			}
			// Unrecognized value: return empty (= "Sélectionnez n'importe qui")
			return '';
		}

		if ($meta_key === '_wf_fedex_custom_declared_value') {
			$normalized = str_replace(',', '.', $value);
			if (is_numeric($normalized)) {
				return (string)(float)$normalized;
			}
			return '';
		}

		// For all other meta keys, return value unchanged
		return $value;
	}

	/**
	 * Reduce an HTML string (or plain text with newlines) to normalized plain text
	 * for comparison purposes only. Converts block-level tags and <br> to newlines,
	 * strips remaining tags, decodes HTML entities, collapses whitespace.
	 */
	private function normalize_html_for_comparison($value)
	{
		$value = (string) $value;

		// Replace <br>, <br/>, <br /> with newlines
		$value = preg_replace('/<br\s*\/?>/i', "\n", $value);

		// Replace block-level closing tags with a newline so paragraphs/headings separate properly
		$value = preg_replace('/<\/(p|div|h[1-6]|li|ul|ol|blockquote|tr)>/i', "\n", $value);

		// Strip all remaining HTML tags
		$value = wp_strip_all_tags($value);

		// Decode HTML entities (&amp; &nbsp; etc.)
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		// Replace non-breaking spaces with regular spaces
		$value = str_replace("\xc2\xa0", ' ', $value);

		// Collapse runs of spaces/tabs on each line
		$value = preg_replace('/[ \t]+/u', ' ', $value);

		// Trim each line individually
		$lines = explode("\n", $value);
		$lines = array_map('trim', $lines);

		// Remove leading/trailing blank lines and collapse multiple blank lines into one
		$result = [];
		$blank_streak = 0;
		foreach ($lines as $line) {
			if ($line === '') {
				$blank_streak++;
				if ($blank_streak === 1 && !empty($result)) {
					$result[] = '';
				}
			} else {
				$blank_streak = 0;
				$result[] = $line;
			}
		}

		return trim(implode("\n", $result));
	}

	/**
	 * Helper to safely extract attribute values from a raw sheet row, 
	 * ignoring accents, case, and singular/plural ('s').
	 */
	private function extract_attribute_from_raw($raw_item, $col_name, &$matched_col = null)
	{
		if (isset($raw_item[$col_name]) && trim($raw_item[$col_name]) !== '') {
			$matched_col = $col_name;
			return trim($raw_item[$col_name]);
		}

		$norm = function ($s) {
			$s = mb_strtolower(trim($s), 'UTF-8');
			$s = str_replace(['é', 'è', 'ê', 'ë'], 'e', $s);
			$s = str_replace(['à', 'â', 'ä'], 'a', $s);
			$s = rtrim($s, 's'); // remove trailing 's' for plural matching
			return $s;
		};

		$target = $norm($col_name);

		foreach ($raw_item as $k => $v) {
			if ($norm($k) === $target) {
				$matched_col = $k;
				return trim($v);
			}
		}
		return '';
	}

	private function aggregate_parent_attributes($sku)
	{
		global $wpdb;
		$attr_columns = ['Taille', 'Couleur', 'Modèle', 'Année'];
		$attributes = [];
		$attr_settings = [];

		$children = $wpdb->get_results($wpdb->prepare("SELECT diff_data FROM {$this->table_name} WHERE diff_data LIKE %s AND diff_data LIKE %s", '%"type":"variation"%', '%"parent_sku":"' . esc_sql($sku) . '"%'));

		foreach ($children as $child) {
			$child_diff = json_decode($child->diff_data, true);
			$child_raw = $child_diff['raw'] ?? [];

			foreach ($attr_columns as $col) {
				$matched_col = null;
				$val = $this->extract_attribute_from_raw($child_raw, $col, $matched_col);
				if (!empty($val)) {
					$tax_name = wc_attribute_taxonomy_name($matched_col);

					if (!isset($attributes[$tax_name])) {
						$attributes[$tax_name] = [];
					}
					if (!in_array($val, $attributes[$tax_name])) {
						$attributes[$tax_name][] = $val;
					}

					if (!isset($attr_settings[$tax_name])) {
						$attr_settings[$tax_name] = [
							'target' => $tax_name,
							'visible' => true
						];
						if ($col === 'Taille' || $col === 'Tailles') {
							$attr_settings[$tax_name]['variation'] = true;
						}
					}
				}
			}
		}

		return ['attributes' => $attributes, 'attr_settings' => $attr_settings];
	}

	private function format_raw_for_upsert($raw)
	{
		$mapping = $this->get_hardcoded_mapping();
		$item = [
			'wp_id'             => $raw[$mapping['wp_id']] ?? '',
			'type'              => $raw[$mapping['type']] ?? '',
			'sku'               => $raw[$mapping['sku']] ?? '',
			'parent_sku'        => $raw[$mapping['parent_sku']] ?? '',
			'title'             => $raw[$mapping['title']] ?? '',
			'description'       => $raw[$mapping['description']] ?? '',
			'short_description' => $raw[$mapping['short_description']] ?? '',
			'price'             => $raw[$mapping['price']] ?? '',
			'stock'             => $raw[$mapping['stock']] ?? '',
			'weight'            => $raw[$mapping['weight']] ?? '',
			'status'            => $raw[$mapping['status']] ?? '',
			'title_en'          => $raw[$mapping['title_en']] ?? '',
			'description_en'    => $raw[$mapping['description_en']] ?? '',
			'content_en'        => $raw[$mapping['content_en']] ?? '',
			'cat_primary'       => $raw[$mapping['cat_primary'] ?? 'Catégorie principale'] ?? '',
			'cat_secondary'     => $raw[$mapping['cat_secondary'] ?? 'Catégorie secondaire'] ?? '',
			'shipping_class'    => $raw[$mapping['shipping_class'] ?? "Classe d'expédition"] ?? '',
			'attributes'        => [],
			'attr_settings'     => [],
			'custom_meta'       => [],
		];

		$type = strtolower(trim($item['type']));
		if ($type === 'variable') {
			$aggregated = $this->aggregate_parent_attributes($item['sku']);
			$item['attributes'] = $aggregated['attributes'];
			$item['attr_settings'] = $aggregated['attr_settings'];
		} else {
			$attr_columns = ['Taille', 'Couleur', 'Modèle', 'Année'];
			foreach ($attr_columns as $col) {
				$matched_col = null;
				$val = $this->extract_attribute_from_raw($raw, $col, $matched_col);
				if (!empty($val)) {
					$tax_name = wc_attribute_taxonomy_name($matched_col);

					if ($type === 'variation') {
						$item['attributes'][$tax_name] = $val;
					} else {
						$item['attributes'][$tax_name] = array_filter(array_map('trim', explode(',', $val)));
					}

					$item['attr_settings'][$tax_name] = [
						'target' => $tax_name,
						'visible' => true
					];
					if ($col === 'Taille') {
						$item['attr_settings'][$tax_name]['variation'] = true;
					}
				}
			}
		}

		return $item;
	}

	/**
	 * Create Custom DB Table on Activation
	 */
	public static function activate()
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'mjf_gsheet_sync_queue';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			sku varchar(255) NOT NULL,
			product_id bigint(20) DEFAULT NULL,
			gs_row_index int(11) DEFAULT NULL,
			status varchar(50) DEFAULT 'pending',
			diff_data longtext DEFAULT NULL,
			direction varchar(10) DEFAULT NULL,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY sku (sku)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	private function find_attachment_by_base_name($base_name)
	{
		global $wpdb;
		// WP saves relative path in _wp_attached_file, e.g. "2026/05/slug.jpg"
		// We look for anything ending with "slug.extension"
		$query = $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s ORDER BY post_id DESC LIMIT 1",
			'%' . $wpdb->esc_like($base_name) . '.%'
		);
		return $wpdb->get_var($query);
	}

	private function set_product_shipping_class(&$product, $shipping_class_name)
	{
		$sku = $product->get_sku();
		if (empty($shipping_class_name) || strtolower(trim($shipping_class_name)) === 'aucune') {
			$product->set_shipping_class_id(0);
			if ($product->get_id()) {
				wp_set_object_terms($product->get_id(), array(), 'product_shipping_class');
			}
			return;
		}

		global $sitepress;
		$original_lang = null;
		if (isset($sitepress)) {
			$original_lang = $sitepress->get_current_language();
			$sitepress->switch_lang('fr');
		}

		$this->log(sprintf('Attempting to set shipping class "%s" for product %s', $shipping_class_name, $sku));
		
		$term_id = 0;
		$classes = WC()->shipping()->get_shipping_classes();
		foreach ($classes as $sc) {
			if (strcasecmp(trim($sc->name), trim($shipping_class_name)) === 0 || strcasecmp(trim($sc->slug), sanitize_title($shipping_class_name)) === 0) {
				$term_id = absint($sc->term_id);
				break;
			}
		}

		if ($term_id) {
			$this->log(sprintf('Found existing shipping class term id %d', $term_id));
		} else {
			$this->log(sprintf('Creating new shipping class term %s', $shipping_class_name));
			$term_data = wp_insert_term($shipping_class_name, 'product_shipping_class');
			if (! is_wp_error($term_data)) {
				$term_id = absint($term_data['term_id']);
				$this->log(sprintf('Created term id %d', $term_id));
			} else {
				$this->log('Failed to create term: ' . $term_data->get_error_message());
				// If it failed because it exists (e.g. cached), try one more time by exact slug
				if ($term_data->get_error_code() === 'term_exists') {
					$existing_term_id = $term_data->get_error_data();
					$term_id = absint($existing_term_id);
					$this->log(sprintf('Recovered existing term id %d', $term_id));
				}
			}
		}

		if ($term_id > 0) {
			$product->set_shipping_class_id($term_id);
			if ($product->get_id()) {
				wp_set_object_terms($product->get_id(), $term_id, 'product_shipping_class');
			}
		}

		if (isset($sitepress) && $original_lang) {
			$sitepress->switch_lang($original_lang);
		}
	}

	private function normalize_attribute_label($label)
	{
		$label = mb_strtolower(trim($label), 'UTF-8');
		$label = str_replace(['é', 'è', 'ê', 'ë'], 'e', $label);
		$label = str_replace(['à', 'â', 'ä'], 'a', $label);
		$label = rtrim($label, 's'); // Singular/plural matching (e.g. Tailles -> taille)

		$synonyms = [
			'annee d\'edition' => 'annee',
			'annee dedition'   => 'annee',
			'annee d’edition'  => 'annee',
			'tailles'          => 'taille',
			'modele'           => 'modele',
			'couleur'          => 'couleur',
		];

		if (isset($synonyms[$label])) {
			return $synonyms[$label];
		}

		return $label;
	}

	private function normalize_attribute_value($val)
	{
		$val = html_entity_decode((string)$val, ENT_QUOTES, 'UTF-8');
		$val = preg_replace('/\s+/u', ' ', trim($val));
		return mb_strtolower($val, 'UTF-8');
	}

	private function get_attribute_display_label($norm_label)
	{
		$display_labels = [
			'annee'   => 'Année',
			'taille'  => 'Tailles',
			'modele'  => 'Modèle',
			'couleur' => 'Couleur',
		];

		if (isset($display_labels[$norm_label])) {
			return $display_labels[$norm_label];
		}

		return ucfirst($norm_label);
	}

	/**
	 * Sync stock quantity to Google Sheets when an order is completed.
	 */
	public function sync_stock_to_gsheet_on_order_completed($order_id)
	{
		$order = wc_get_order($order_id);
		if (!$order) return;

		global $wpdb;
		$mapping = $this->get_hardcoded_mapping();
		$stock_col = $mapping['stock'] ?? 'Stock';

		foreach ($order->get_items() as $item) {
			$product = $item->get_product();
			if (!$product) continue;

			$product_id = $product->get_id();
			$post_type = $product->is_type('variation') ? 'product_variation' : 'product';

			// Resolve translation to original French product if WPML is active
			$original_id = apply_filters('wpml_object_id', $product_id, $post_type, true, 'fr');
			$original_product = wc_get_product($original_id);
			if (!$original_product) {
				$original_product = $product;
			}

			// Check if stock management is enabled for this product/variation
			if (!$original_product->managing_stock()) {
				continue;
			}

			$sku = $original_product->get_sku();
			if (empty($sku)) continue;

			// Get current WooCommerce stock quantity
			$stock_qty = $original_product->get_stock_quantity();

			// Look up in sync queue table to find Google Sheet row index
			$row = $wpdb->get_row($wpdb->prepare(
				"SELECT gs_row_index FROM {$this->table_name} WHERE sku = %s",
				$sku
			));

			if ($row && !empty($row->gs_row_index)) {
				if ($stock_col) {
					$this->api->write_to_google_sheet($row->gs_row_index, $stock_col, $stock_qty);
					$this->log(sprintf('Stock updated in GSheet for order %d: Product %d -> %d', $order_id, $original_id, $stock_qty));
				}
			}
		}
	}
}

// Initialize the plugin
register_activation_hook(__FILE__, ['MJF_GSheet_Product_Importer', 'activate']);
new MJF_GSheet_Product_Importer();
