<?php

if (! defined('ABSPATH')) {
	exit;
}

class MJF_GSheet_Sync
{
	private $option_name = 'mjf_gsheet_importer_settings';
	private $table_name;
	private $api;
	private $main;

	public function __construct($api, $main)
	{
		global $wpdb;
		$this->table_name = $wpdb->prefix . 'mjf_gsheet_sync_queue';
		$this->api = $api;
		$this->main = $main;
	}

	/**
	 * Upsert Simple or Variable Product
	 */
	public function upsert_product($item, $type)
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

		$this->main->log(sprintf(__('Upserted %s product: %s', 'mjf-gsheet-importer'), $type, $sku));
		return $product_id;
	}

	/**
	 * Upsert Variation
	 */
	public function upsert_variation($item)
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
			$this->main->log(sprintf(__('Skipped variation %s: Parent SKU %s not found.', 'mjf-gsheet-importer'), $sku, $parent_sku));
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
		$this->main->log(sprintf(__('Forced variation %s status to publish (Enabled)', 'mjf-gsheet-importer'), $sku));

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
							$this->main->log(sprintf(__('Added term "%s" (slug: %s) to parent taxonomy "%s"', 'mjf-gsheet-importer'), $value, $term_slug, $attr_key));
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
						$this->main->log(sprintf(__('Initialized parent taxonomy "%s" with term "%s"', 'mjf-gsheet-importer'), $attr_key, $value));
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
							$this->main->log(sprintf(__('Added option "%s" to parent attribute "%s"', 'mjf-gsheet-importer'), $value, $display_name));
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
			$this->main->log(sprintf(__('Skipped variation %s: Cannot create a variation without attributes. Parent product attributes may be missing.', 'mjf-gsheet-importer'), $sku));
			return;
		}

		$variation_id = $variation->save();
		$this->update_variation_title($variation_id);

		// Force update meta after save to ensure variations are linked and identified
		if (! empty($attr_data)) {
			foreach ($attr_data as $key => $val) {
				$meta_key = 'attribute_' . $key;
				update_post_meta($variation_id, $meta_key, $val);
				$this->main->log(sprintf(__('Variation %s: Set %s to "%s"', 'mjf-gsheet-importer'), $sku, $meta_key, $val));
			}
		}

		if ($parent_updated) {
			wc_delete_product_transients($parent_id);
		}

		$this->main->log(sprintf(__('Upserted variation: %s (Parent: %s)', 'mjf-gsheet-importer'), $sku, $parent_sku));
	}

	/**
	 * Helper: Get Category IDs with Hierarchy
	 */
	private function get_category_ids($primary_name, $secondary_name)
	{
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
					$this->main->log(sprintf(__('Set global attribute "%s" (ID: %d)', 'mjf-gsheet-importer'), $display_name, $attr_id));
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
							$this->main->log(sprintf(__('Created and set new global attribute "%s" (ID: %d)', 'mjf-gsheet-importer'), $display_name, $attr_id));
						}
					}

					if ($attr_id > 0) {
						$attribute->set_id($attr_id);
						$attribute->set_name($display_name);
					} else {
						$attribute->set_id(0);
						$attribute->set_name($display_name);
						$this->main->log(sprintf(__('Set custom attribute "%s"', 'mjf-gsheet-importer'), $display_name));
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
	public function upsert_translation($original_id, $item, $lang = 'en')
	{
		if (! has_action('wpml_set_element_language_details')) {
			$this->main->log(__('WPML is not active. Translation skipped.', 'mjf-gsheet-importer'));
			return;
		}

		$original_product = wc_get_product($original_id);
		if (! $original_product) return;

		$sku = $original_product->get_sku();

		// Find existing translation ID
		$translated_id = apply_filters('wpml_object_id', $original_id, 'product', false, $lang);

		if (! $translated_id) {
			$this->main->log(sprintf(__('Creating new %s translation for product %s', 'mjf-gsheet-importer'), strtoupper($lang), $sku));

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

			global $sitepress;
			$orig_lang_for_sync = null;
			if (isset($sitepress)) {
				$orig_lang_for_sync = $sitepress->get_current_language();
				$sitepress->switch_lang($lang);
			}

			$wp_shipping_class_id = $original_product->get_shipping_class_id();
			if ($wp_shipping_class_id) {
				$translated_class_id = $this->translate_or_create_term($wp_shipping_class_id, 'product_shipping_class', $lang);
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

			if (isset($sitepress) && $orig_lang_for_sync) {
				$sitepress->switch_lang($orig_lang_for_sync);
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
			$this->main->log(sprintf(__('Updating existing %s translation for product %s (ID: %d)', 'mjf-gsheet-importer'), strtoupper($lang), $sku, $translated_id));
			$translated_product = wc_get_product($translated_id);
			if (isset($item['title_en'])) $translated_product->set_name($item['title_en']);
			if (isset($item['description_en'])) $translated_product->set_description($item['description_en']);
			if (isset($item['content_en'])) $translated_product->set_short_description($item['content_en']);

			// Sync price and stock even on update
			$translated_product->set_regular_price($original_product->get_regular_price());
			$translated_product->set_manage_stock($original_product->get_manage_stock());
			$translated_product->set_stock_quantity($original_product->get_stock_quantity());

			global $sitepress;
			$orig_lang_for_sync = null;
			if (isset($sitepress)) {
				$orig_lang_for_sync = $sitepress->get_current_language();
				$sitepress->switch_lang($lang);
			}

			$wp_shipping_class_id = $original_product->get_shipping_class_id();
			if ($wp_shipping_class_id) {
				$translated_class_id = $this->translate_or_create_term($wp_shipping_class_id, 'product_shipping_class', $lang);
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

			if (isset($sitepress) && $orig_lang_for_sync) {
				$sitepress->switch_lang($orig_lang_for_sync);
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

		// 2. If it does not exist, get original term details
		$original_term = get_term($term_id, $taxonomy);
		if (!$original_term || is_wp_error($original_term)) {
			return (int) $term_id; // Return original if term not found
		}

		$term_name = $original_term->name;
		$original_slug = $original_term->slug;
		$translated_slug = $original_slug . '-' . $lang;

		// 3. Create the translated term
		$new_term = wp_insert_term($term_name, $taxonomy, [
			'slug' => $translated_slug
		]);

		if (is_wp_error($new_term)) {
			if (isset($new_term->error_data['term_exists'])) {
				$translated_term_id = $new_term->error_data['term_exists'];
			} else {
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

		return (int) $translated_term_id;
	}


	public function sync_field_to_wp($product_id, $field, $value, $raw_item)
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
				$mapping = $this->main->get_hardcoded_mapping();
				$image_slug_col = $mapping['image_slug'] ?? 'Slug Image';
				$slug = trim($raw_item[$image_slug_col] ?? '');
				if ($slug) {
					$id = $this->find_attachment_by_base_name($slug . '-main');
					if ($id) $product->set_image_id($id);
				}
				break;
			case 'image_gallery':
				$mapping = $this->main->get_hardcoded_mapping();
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
				$mapping = $this->main->get_hardcoded_mapping();
				$sec_col = $mapping['cat_secondary'] ?? 'Catégorie secondaire';
				$sec_val = isset($raw_item[$sec_col]) ? trim($raw_item[$sec_col]) : '';
				$cat_ids = $this->get_category_ids($value, $sec_val);
				$product->set_category_ids($cat_ids);
				break;
			case 'cat_secondary':
				$mapping = $this->main->get_hardcoded_mapping();
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
						$orig_lang_for_sync2 = null;
						if (isset($sitepress)) {
							$orig_lang_for_sync2 = $sitepress->get_current_language();
							$sitepress->switch_lang('en');
						}
						$wp_shipping_class_id = $product->get_shipping_class_id();
						if ($wp_shipping_class_id) {
							$translated_class_id = $this->translate_or_create_term($wp_shipping_class_id, 'product_shipping_class', 'en');
							$translated_product->set_shipping_class_id($translated_class_id);
							$translated_product->save();
							wp_set_object_terms($translated_product->get_id(), array((int)$translated_class_id), 'product_shipping_class');
						} else {
							$translated_product->set_shipping_class_id(0);
							$translated_product->save();
							wp_set_object_terms($translated_product->get_id(), array(), 'product_shipping_class');
						}
						if (isset($sitepress) && $orig_lang_for_sync2) {
							$sitepress->switch_lang($orig_lang_for_sync2);
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
							$_orig_lang3 = null;
							if (isset($sitepress)) {
								$_orig_lang3 = $sitepress->get_current_language();
								$sitepress->switch_lang('en');
							}
							$wp_shipping_class_id = $product->get_shipping_class_id();
							if ($wp_shipping_class_id) {
								$translated_class_id = $this->translate_or_create_term($wp_shipping_class_id, 'product_shipping_class', 'en');
								$translated_product->set_shipping_class_id($translated_class_id);
								$translated_product->save();
								wp_set_object_terms($translated_product->get_id(), array((int)$translated_class_id), 'product_shipping_class');
							} else {
								$translated_product->set_shipping_class_id(0);
								$translated_product->save();
								wp_set_object_terms($translated_product->get_id(), array(), 'product_shipping_class');
							}
							if (isset($sitepress) && $_orig_lang3) {
								$sitepress->switch_lang($_orig_lang3);
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

	public function format_raw_for_upsert($raw)
	{
		$mapping = $this->main->get_hardcoded_mapping();
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

		$this->main->log(sprintf('Attempting to set shipping class "%s" for product %s', $shipping_class_name, $sku));
		
		$term_id = 0;
		$classes = WC()->shipping()->get_shipping_classes();
		foreach ($classes as $sc) {
			if (strcasecmp(trim($sc->name), trim($shipping_class_name)) === 0 || strcasecmp(trim($sc->slug), sanitize_title($shipping_class_name)) === 0) {
				$term_id = absint($sc->term_id);
				break;
			}
		}

		if ($term_id) {
			$this->main->log(sprintf('Found existing shipping class term id %d', $term_id));
		} else {
			$this->main->log(sprintf('Creating new shipping class term %s', $shipping_class_name));
			$term_data = wp_insert_term($shipping_class_name, 'product_shipping_class');
			if (! is_wp_error($term_data)) {
				$term_id = absint($term_data['term_id']);
				$this->main->log(sprintf('Created term id %d', $term_id));
			} else {
				$this->main->log('Failed to create term: ' . $term_data->get_error_message());
				// If it failed because it exists (e.g. cached), try one more time by exact slug
				if ($term_data->get_error_code() === 'term_exists') {
					$existing_term_id = $term_data->get_error_data();
					$term_id = absint($existing_term_id);
					$this->main->log(sprintf('Recovered existing term id %d', $term_id));
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


}
