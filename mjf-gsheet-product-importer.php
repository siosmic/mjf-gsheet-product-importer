<?php

/**
 * Plugin Name: MJF Google Sheets Product Importer
 * Description: Import and sync WooCommerce products from a Google Sheet.
 * Version: 1.0.0
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

/**
 * Main Plugin Class
 */
class MJF_GSheet_Product_Importer
{

	private $option_name = 'mjf_gsheet_importer_settings';

	public function __construct()
	{
		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_init', [$this, 'register_settings']);
		add_action('admin_post_mjf_gsheet_sync', [$this, 'handle_sync_request']);
		add_action('admin_post_mjf_gsheet_fetch_cols', [$this, 'handle_fetch_cols']);
		add_action('admin_post_mjf_gsheet_save_mapping', [$this, 'handle_save_mapping']);
		add_action('admin_post_mjf_gsheet_preview', [$this, 'handle_preview_request']);
		add_action('admin_post_mjf_gsheet_save_review', [$this, 'handle_save_review_request']);
		add_action('add_meta_boxes', [$this, 'add_debug_meta_box']);
		add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_action_links']);
	}

	/**
	 * Debug Meta Box
	 */
	public function add_debug_meta_box() {
		add_meta_box('mjf_gsheet_debug', 'MJF Importer Debug', [$this, 'render_debug_meta_box'], 'product', 'normal', 'high');
	}

	public function render_debug_meta_box($post) {
		$product = wc_get_product($post->ID);
		if (! $product) return;
		
		echo '<div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; max-height: 500px; overflow: auto; font-family: monospace; font-size: 11px; line-height: 1.4;">';
		echo '<h4>Raw Object (print_r):</h4>';
		echo '<pre>' . print_r($product, true) . '</pre>';
		echo '<hr>';
		echo '<strong style="font-size: 14px; color: #2271b1;">Summary: ' . $product->get_type() . '</strong><hr>';
		
		$attrs = $product->get_attributes();
		echo '<h4>Parent Attributes (_product_attributes):</h4>';
		if (empty($attrs)) {
			echo '<span style="color: red;">No attributes found on parent.</span>';
		} else {
			foreach ($attrs as $key => $attr) {
				echo "<div style='margin-bottom: 10px; border-left: 3px solid #ccc; padding-left: 10px;'>";
				echo "<strong>Key: $key</strong><br>";
				echo "Type: " . ($attr->is_taxonomy() ? 'Taxonomy ('.$attr->get_name().')' : 'Custom') . "<br>";
				echo "Variation: " . ($attr->get_variation() ? '<span style="color: green; font-weight: bold;">YES</span>' : '<span style="color: red;">NO</span>') . "<br>";
				echo "Visible: " . ($attr->get_visible() ? 'YES' : 'NO') . "<br>";
				echo "Options/Terms: " . json_encode($attr->get_options()) . "";
				echo "</div>";
			}
		}

		if ($product->is_type('variable')) {
			$children = $product->get_children();
			echo '<h4>Children (Variations):</h4>';
			if (empty($children)) {
				echo '<div style="padding: 10px; background: #fee; border: 1px solid #fcc; color: #a00; font-weight: bold;">NO CHILDREN FOUND IN PARENT OBJECT</div>';
			} else {
				echo '<ul style="margin: 0; padding-left: 20px;">';
				foreach ($children as $child_id) {
					$v = wc_get_product($child_id);
					if ($v) {
						$v_attrs = $v->get_attributes();
						echo '<li style="margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 5px;">';
						echo '<strong>ID: ' . $child_id . '</strong> (SKU: ' . $v->get_sku() . ') - Status: ' . $v->get_status() . '<br>';
						echo 'Price: ' . $v->get_regular_price() . ' | Visible: ' . ($v->variation_is_visible() ? 'YES' : 'NO') . ' | Active: ' . ($v->variation_is_active() ? 'YES' : 'NO') . '<br>';
						echo '<span style="color: #666;">Meta Attrs (attribute_...):</span><br>';
						foreach ($v_attrs as $v_key => $v_val) {
							echo " &nbsp;&nbsp; - <strong>$v_key</strong>: <span style='color: #2271b1;'>$v_val</span><br>";
						}
						echo '</li>';
					} else {
						echo "<li>ID: $child_id - <span style='color: red;'>COULD NOT LOAD VARIATION OBJECT</span></li>";
					}
				}
				echo '</ul>';
			}
		}
		echo '</div>';
	}

	/**
	 * Add Admin Menu
	 */
	public function add_admin_menu()
	{
		add_submenu_page(
			'woocommerce',
			__('GSheet Importer', 'mjf-gsheet-importer'),
			__('GSheet Importer', 'mjf-gsheet-importer'),
			'manage_woocommerce',
			'mjf-gsheet-importer',
			[$this, 'render_admin_page']
		);
	}

	/**
	 * Add Plugin Action Links
	 */
	public function add_action_links($links)
	{
		$settings_link = '<a href="' . admin_url('admin.php?page=mjf-gsheet-importer') . '">' . __('Settings', 'mjf-gsheet-importer') . '</a>';
		array_unshift($links, $settings_link);
		return $links;
	}

	/**
	 * Register Settings
	 */
	public function register_settings()
	{
		register_setting('mjf_gsheet_importer_group', $this->option_name);
	}

	/**
	 * Render Admin Page
	 */
	public function render_admin_page()
	{
		$settings = get_option($this->option_name, []);
		$sheet_id = $settings['sheet_id'] ?? '';
		$sheet_name = $settings['sheet_name'] ?? 'Sheet1';
		$mapping = $settings['mapping'] ?? [];
		$available_columns = $settings['available_columns'] ?? [];
		$preview_data = get_option('mjf_gsheet_preview_data', []);

		$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'connection';

		// Step Locking Logic
		$step_mapping_ok = ! empty($sheet_id) && ! empty($available_columns);
		$step_preview_ok = $step_mapping_ok && ! empty($mapping['sku']);
		$step_import_ok = $step_preview_ok && ! empty($preview_data);

?>
		<style>
			.mjf-tabs {
				margin-top: 20px;
			}

			.nav-tab-disabled {
				background: #eee;
				color: #aaa;
				cursor: not-allowed !important;
				opacity: 0.6;
				pointer-events: none;
			}

			.mjf-step-card {
				background: #fff;
				border: 1px solid #ccd0d4;
				padding: 25px;
				margin-top: -1px;
				min-height: 400px;
			}

			.mjf-tab-content {
				display: none;
			}

			.mjf-tab-content.active {
				display: block;
			}

			.mjf-badge {
				padding: 4px 8px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: bold;
			}

			.mjf-badge-blue {
				background: #e7f3ff;
				color: #2271b1;
			}

			.mjf-badge-green {
				background: #edfaef;
				color: #1a7f37;
			}

			.variation-list-table {
				width: 100%;
				border-collapse: collapse;
				margin-top: 10px;
				font-size: 11px;
			}

			.variation-list-table td,
			.variation-list-table th {
				border: 1px solid #eee;
				padding: 6px;
				text-align: left;
			}

			.variation-list-table th {
				background: #f9f9f9;
			}
		</style>

		<div class="wrap">
			<h1><?php _e('MJF Product Importer', 'mjf-gsheet-importer'); ?></h1>

			<h2 class="nav-tab-wrapper mjf-tabs">
				<a href="#connection" class="nav-tab <?php echo $current_tab == 'connection' ? 'nav-tab-active' : ''; ?>" data-tab="connection">1. <?php _e('Connection', 'mjf-gsheet-importer'); ?></a>

				<a href="#mapping" class="nav-tab <?php echo $current_tab == 'mapping' ? 'nav-tab-active' : ''; ?> <?php echo !$step_mapping_ok ? 'nav-tab-disabled' : ''; ?>" data-tab="mapping">2. <?php _e('Mapping', 'mjf-gsheet-importer'); ?></a>

				<a href="#preview" class="nav-tab <?php echo $current_tab == 'preview' ? 'nav-tab-active' : ''; ?> <?php echo !$step_preview_ok ? 'nav-tab-disabled' : ''; ?>" data-tab="preview">3. <?php _e('Preview & Review', 'mjf-gsheet-importer'); ?></a>

				<a href="#import" class="nav-tab <?php echo $current_tab == 'import' ? 'nav-tab-active' : ''; ?> <?php echo !$step_import_ok ? 'nav-tab-disabled' : ''; ?>" data-tab="import">4. <?php _e('Final Import', 'mjf-gsheet-importer'); ?></a>
			</h2>

			<div class="mjf-step-card">
				<!-- TAB 1: CONNECTION -->
				<div id="tab-connection" class="mjf-tab-content <?php echo $current_tab == 'connection' ? 'active' : ''; ?>">
					<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
						<input type="hidden" name="action" value="mjf_gsheet_fetch_cols">
						<?php wp_nonce_field('mjf_gsheet_fetch_cols_action', 'mjf_gsheet_fetch_cols_nonce'); ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="sheet_id"><?php _e('Google Sheet ID', 'mjf-gsheet-importer'); ?></label></th>
								<td>
									<input name="mjf_gsheet_importer[sheet_id]" type="text" id="sheet_id" value="<?php echo esc_attr($sheet_id); ?>" class="regular-text" placeholder="ex: 11gPM2f-YivsTg8Q6Flkvn8lfleK_5bXrsJi1ZjsHNKI">
									<p class="description"><?php _e('Found in the URL of your Google Sheet.', 'mjf-gsheet-importer'); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sheet_name"><?php _e('Sheet Tab Name', 'mjf-gsheet-importer'); ?></label></th>
								<td>
									<input name="mjf_gsheet_importer[sheet_name]" type="text" id="sheet_name" value="<?php echo esc_attr($sheet_name); ?>" class="regular-text">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="header_row"><?php _e('Header Row Number', 'mjf-gsheet-importer'); ?></label></th>
								<td>
									<input name="mjf_gsheet_importer[header_row]" type="number" id="header_row" value="<?php echo esc_attr($settings['header_row'] ?? 1); ?>" class="small-text">
								</td>
							</tr>
						</table>
						<?php submit_button(__('Save & Fetch Columns', 'mjf-gsheet-importer'), 'primary large'); ?>
					</form>
				</div>

				<!-- TAB 2: MAPPING -->
				<div id="tab-mapping" class="mjf-tab-content <?php echo $current_tab == 'mapping' ? 'active' : ''; ?>">
					<?php if ($step_mapping_ok) : ?>
						<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
							<input type="hidden" name="action" value="mjf_gsheet_save_mapping">
							<?php wp_nonce_field('mjf_gsheet_save_mapping_action', 'mjf_gsheet_save_mapping_nonce'); ?>

							<table class="fixed widefat striped" style="margin-top: 20px;">
								<thead>
									<tr>
										<th><?php _e('WooCommerce Field', 'mjf-gsheet-importer'); ?></th>
										<th><?php _e('Google Sheet Column', 'mjf-gsheet-importer'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$fields = [
										'type'              => __('Product Type', 'mjf-gsheet-importer'),
										'sku'               => __('SKU', 'mjf-gsheet-importer') . ' *',
										'parent_sku'        => __('Parent SKU', 'mjf-gsheet-importer'),
										'title'             => __('Title', 'mjf-gsheet-importer'),
										'description'       => __('Description', 'mjf-gsheet-importer'),
										'short_description' => __('Short Description', 'mjf-gsheet-importer'),
										'price'             => __('Price', 'mjf-gsheet-importer'),
										'stock'             => __('Stock', 'mjf-gsheet-importer'),
										'weight'            => __('Weight (kg)', 'mjf-gsheet-importer'),
										'shipping_class'    => __('Shipping Class', 'mjf-gsheet-importer'),
										'status'            => __('Status (publish/draft)', 'mjf-gsheet-importer'),
										'cat_primary'       => __('Primary Category', 'mjf-gsheet-importer'),
										'cat_secondary'     => __('Secondary Category', 'mjf-gsheet-importer'),
									];
									foreach ($fields as $key => $label) : ?>
										<tr>
											<td><strong><?php echo $label; ?></strong></td>
											<td>
												<select name="mapping[<?php echo esc_attr($key); ?>]">
													<option value="">-- Select --</option>
													<?php foreach ($available_columns as $col) : ?>
														<option value="<?php echo esc_attr($col); ?>" <?php selected($mapping[$key] ?? '', $col); ?>><?php echo esc_html($col); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<h3><?php _e('FedEx Specific Mapping', 'mjf-gsheet-importer'); ?></h3>
							<table class="fixed widefat striped" style="margin-top: 10px;">
								<thead>
									<tr>
										<th><?php _e('FedEx Field', 'mjf-gsheet-importer'); ?></th>
										<th><?php _e('Google Sheet Column', 'mjf-gsheet-importer'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$fedex_fields = [
										'fedex_hs_tariff'       => __('HS Tariff Number', 'mjf-gsheet-importer'),
										'fedex_description'     => __('Commodity Description', 'mjf-gsheet-importer'),
										'fedex_freight_class'   => __('Freight Class', 'mjf-gsheet-importer'),
										'fedex_signature'       => __('Delivery Signature', 'mjf-gsheet-importer'),
										'fedex_country'         => __('Country of Manufacture', 'mjf-gsheet-importer'),
										'fedex_custom_value'    => __('Custom Declared Value', 'mjf-gsheet-importer'),
									];
									foreach ($fedex_fields as $key => $label) : ?>
										<tr>
											<td><strong><?php echo $label; ?></strong></td>
											<td>
												<select name="mapping[<?php echo esc_attr($key); ?>]">
													<option value="">-- Select --</option>
													<?php foreach ($available_columns as $col) : ?>
														<option value="<?php echo esc_attr($col); ?>" <?php selected($mapping[$key] ?? '', $col); ?>><?php echo esc_html($col); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<h3><?php _e('WPML (EN) Translation Mapping', 'mjf-gsheet-importer'); ?></h3>
							<p class="description"><?php _e('If mapped, the importer will create/update an English translation for each product.', 'mjf-gsheet-importer'); ?></p>
							<table class="fixed widefat striped" style="margin-top: 10px;">
								<thead>
									<tr>
										<th><?php _e('WPML Field', 'mjf-gsheet-importer'); ?></th>
										<th><?php _e('Google Sheet Column', 'mjf-gsheet-importer'); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$wpml_fields = [
										'title_en'       => __('Product Name (EN)', 'mjf-gsheet-importer'),
										'description_en' => __('Description (EN)', 'mjf-gsheet-importer'),
										'content_en'     => __('Additional Content (EN)', 'mjf-gsheet-importer'),
									];
									foreach ($wpml_fields as $key => $label) : ?>
										<tr>
											<td><strong><?php echo $label; ?></strong></td>
											<td>
												<select name="mapping[<?php echo esc_attr($key); ?>]">
													<option value="">-- Select --</option>
													<?php foreach ($available_columns as $col) : ?>
														<option value="<?php echo esc_attr($col); ?>" <?php selected($mapping[$key] ?? '', $col); ?>><?php echo esc_html($col); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<h3><?php _e('Product Attributes Mapping', 'mjf-gsheet-importer'); ?></h3>
							<table class="fixed widefat striped" id="attr-mapping-table" style="margin-top: 10px;">
								<thead>
									<tr>
										<th><?php _e('WC Attribute Name', 'mjf-gsheet-importer'); ?></th>
										<th><?php _e('Google Sheet Column', 'mjf-gsheet-importer'); ?></th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$attr_mapping = $settings['attr_mapping'] ?? [];
									if (empty($attr_mapping)) $attr_mapping = ['' => ''];
									$wc_attributes = wc_get_attribute_taxonomies();
									foreach ($attr_mapping as $wc_attr => $sheet_col) : ?>
										<tr class="attr-mapping-row">
											<td>
												<select name="attr_mapping_keys_select[]" onchange="if(this.value==='custom') { this.nextElementSibling.style.display='inline-block'; } else { this.nextElementSibling.style.display='none'; this.nextElementSibling.value=''; }" style="width: 100%;">
													<option value="">-- Select WC Attribute --</option>
													<?php
													$is_custom = true;
													foreach ($wc_attributes as $tax) {
														$tax_key = 'pa_' . $tax->attribute_name;
														if ($wc_attr === $tax_key) $is_custom = false;
														echo '<option value="' . esc_attr($tax_key) . '" ' . selected($wc_attr, $tax_key, false) . '>' . esc_html($tax->attribute_label) . ' (' . $tax_key . ')</option>';
													}
													if ($wc_attr === '') $is_custom = false;
													?>
													<option value="custom" <?php selected($is_custom, true); ?>>Custom Attribute...</option>
												</select>
												<input type="text" name="attr_mapping_keys_custom[]" value="<?php echo $is_custom ? esc_attr($wc_attr) : ''; ?>" placeholder="Custom Attribute Name" style="display: <?php echo $is_custom ? 'inline-block' : 'none'; ?>; margin-top:5px; width:100%;">
											</td>
											<td>
												<select name="attr_mapping_cols[]">
													<option value="">-- Select --</option>
													<?php foreach ($available_columns as $col) : ?>
														<option value="<?php echo esc_attr($col); ?>" <?php selected($sheet_col, $col); ?>><?php echo esc_html($col); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
											<td><button type="button" class="button remove-row">Remove</button></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p><button type="button" class="button" id="add-attr-row"><?php _e('Add Attribute', 'mjf-gsheet-importer'); ?></button></p>

							<h3><?php _e('Custom Meta / Fields Mapping', 'mjf-gsheet-importer'); ?></h3>
							<table class="fixed widefat striped" id="custom-meta-table" style="margin-top: 10px;">
								<thead>
									<tr>
										<th><?php _e('Meta Key', 'mjf-gsheet-importer'); ?></th>
										<th><?php _e('Google Sheet Column', 'mjf-gsheet-importer'); ?></th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									<?php
									$custom_mapping = $settings['custom_mapping'] ?? [];
									if (empty($custom_mapping)) $custom_mapping = ['' => ''];
									foreach ($custom_mapping as $meta_key => $sheet_col) : ?>
										<tr class="custom-meta-row">
											<td><input type="text" name="custom_mapping_keys[]" value="<?php echo esc_attr($meta_key); ?>" placeholder="_weight"></td>
											<td>
												<select name="custom_mapping_cols[]">
													<option value="">-- Select --</option>
													<?php foreach ($available_columns as $col) : ?>
														<option value="<?php echo esc_attr($col); ?>" <?php selected($sheet_col, $col); ?>><?php echo esc_html($col); ?></option>
													<?php endforeach; ?>
												</select>
											</td>
											<td><button type="button" class="button remove-row">Remove</button></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
							<p><button type="button" class="button" id="add-meta-row"><?php _e('Add Custom Meta', 'mjf-gsheet-importer'); ?></button></p>
							<?php submit_button(__('Save Mapping & Go to Preview', 'mjf-gsheet-importer')); ?>
						</form>
					<?php else : ?>
						<p><?php _e('Please complete step 1 first.', 'mjf-gsheet-importer'); ?></p>
					<?php endif; ?>
				</div>

				<!-- TAB 3: PREVIEW -->
				<div id="tab-preview" class="mjf-tab-content <?php echo $current_tab == 'preview' ? 'active' : ''; ?>">
					<?php if ($step_preview_ok) : ?>
						<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
							<h2><?php _e('Data Analysis & Review', 'mjf-gsheet-importer'); ?></h2>
							<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
								<input type="hidden" name="action" value="mjf_gsheet_preview">
								<?php wp_nonce_field('mjf_gsheet_preview_action', 'mjf_gsheet_preview_nonce'); ?>
								<?php submit_button(__('Refresh Analysis', 'mjf-gsheet-importer'), 'secondary'); ?>
							</form>
						</div>

						<?php if (empty($preview_data)) : ?>
							<div class="inline notice notice-warning">
								<p><?php _e('Please click "Refresh Analysis" to load data.', 'mjf-gsheet-importer'); ?></p>
							</div>
						<?php else :
							$simple_products = $preview_data['simple'] ?? [];
							$variable_products = $preview_data['variable'] ?? [];
						?>
							<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
								<input type="hidden" name="action" value="mjf_gsheet_save_review">
								<?php wp_nonce_field('mjf_gsheet_save_review_action', 'mjf_gsheet_save_review_nonce'); ?>

								<div style="display: flex; gap: 30px; align-items: flex-start;">
									<div style="flex: 1; border: 1px solid #ddd; padding: 15px; border-radius: 4px; background: #fafafa; max-height: 700px; overflow-y: auto;">
										<h4 style="position: sticky; top: -15px; background: #fafafa; padding: 10px 0; margin-top: 0; z-index: 10; border-bottom: 1px solid #eee;"><?php _e('Simple Products', 'mjf-gsheet-importer'); ?> <span class="mjf-badge mjf-badge-blue"><?php echo count($simple_products); ?></span></h4>
										<table class="fixed wp-list-table widefat striped" style="font-size: 11px;">
											<thead>
												<tr>
													<th>SKU</th>
													<th>Name</th>
												</tr>
											</thead>
											<tbody>
												<?php foreach ($simple_products as $sku => $prod) : ?>
													<tr>
														<td><code><?php echo esc_html($sku); ?></code></td>
														<td><?php echo esc_html($prod['title']); ?></td>
													</tr>
												<?php endforeach; ?>
											</tbody>
										</table>
									</div>
									<div style="flex: 2; border: 1px solid #ddd; padding: 15px; border-radius: 4px; background: #fafafa; max-height: 700px; overflow-y: auto;">
										<h4 style="position: sticky; top: -15px; background: #fafafa; padding: 10px 0; margin-top: 0; z-index: 10; border-bottom: 1px solid #eee;"><?php _e('Variable Products', 'mjf-gsheet-importer'); ?> <span class="mjf-badge mjf-badge-green"><?php echo count($variable_products); ?></span></h4>
										<?php
										$wc_attributes = wc_get_attribute_taxonomies();
										$wc_attr_list = [];
										foreach ($wc_attributes as $tax) {
											$full_name = 'pa_' . $tax->attribute_name;
											$wc_attr_list[$tax->attribute_id . '|' . $full_name] = $tax->attribute_label . ' (' . $full_name . ')';
										}
										foreach ($variable_products as $v_sku => $v_prod) : ?>
											<div style="border: 1px solid #ccc; margin-bottom: 25px; background: #fff; padding: 15px; border-radius: 4px;">
												<div style="display: flex; justify-content: space-between; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
													<div><strong><?php echo esc_html($v_prod['title']); ?></strong><br><code>SKU: <?php echo esc_html($v_sku); ?></code></div>
													<div style="text-align: right;"><span class="mjf-badge mjf-badge-blue"><?php echo count($v_prod['variations']); ?> Variations</span></div>
												</div>
												<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
													<?php foreach ($v_prod['attributes'] as $attr_name => $attr_vals) :
														// Auto-detect if it varies
														$is_variation = count($attr_vals) > 1;
														$sample_val = implode(', ', $attr_vals);
														if (strlen($sample_val) > 40) $sample_val = substr($sample_val, 0, 40) . '...';
													?>
														<?php 
														$overrides = get_option('mjf_gsheet_overrides', []);
														$current_target = $overrides[$v_sku][$attr_name]['target'] ?? '';
														
														// 1. Detect if values vary across variations
														$actually_varies = count($attr_vals) > 1;

														// 2. Smart auto-detection for target matching
														$has_match = false;
														$matched_val = '';
														$display_label = $attr_name;
														foreach ($wc_attr_list as $val => $label) {
															list($tid, $tname) = explode('|', $val);
															if ($tname === $attr_name || $tname === wc_attribute_taxonomy_name($attr_name)) {
																$has_match = true;
																$matched_val = $val;
																// Extract label from "Label (pa_name)"
																$display_label = preg_replace('/ \(.+\)$/', '', $label);
																break;
															}
														}

														if (empty($current_target) && $has_match) {
															$current_target = $matched_val;
														}

														// 3. Default state for checkboxes
														$is_visible_ov = isset($overrides[$v_sku][$attr_name]['visible']) 
															? $overrides[$v_sku][$attr_name]['visible'] 
															: $actually_varies;

														$is_variation_ov = isset($overrides[$v_sku][$attr_name]['variation']) 
															? $overrides[$v_sku][$attr_name]['variation'] 
															: $actually_varies;
														?>
														<div style="border: 1px solid #eee; padding: 10px; border-radius: 3px; <?php echo !$is_variation_ov ? 'opacity: 0.8; background: #fdfdfd;' : ''; ?>">
															<div style="display: flex; justify-content: space-between;">
																<strong><?php echo esc_html($display_label); ?></strong>
																<span style="font-size: 10px; color: #999;"><?php echo count($attr_vals); ?> values</span>
															</div>
															<small style="color: #666;">Source: <?php echo esc_html($sample_val); ?></small>
															<div style="margin-top: 8px;">
																<select name="overrides[<?php echo esc_attr($v_sku); ?>][<?php echo esc_attr($attr_name); ?>][target]" style="width: 100%; font-size: 11px;">
																	<option value=""><?php echo sprintf(__('Default (%s)', 'mjf-gsheet-importer'), $attr_name); ?></option>
																	<?php foreach ($wc_attr_list as $val => $label) : 
																		$is_selected = ($current_target === $val);
																	?>
																		<option value="<?php echo esc_attr($val); ?>" <?php selected($is_selected, true); ?>><?php echo esc_html($label); ?></option>
																	<?php endforeach; ?>
																	<option value="custom:<?php echo esc_attr($attr_name); ?>" <?php selected($current_target, 'custom:' . $attr_name); ?>>Custom Attribute</option>
																</select>
															</div>
															<div style="margin-top: 8px; font-size: 11px;">
																<label><input type="checkbox" name="overrides[<?php echo esc_attr($v_sku); ?>][<?php echo esc_attr($attr_name); ?>][visible]" <?php checked($is_visible_ov); ?>> Visible</label>
																<label style="margin-left: 10px;"><input type="checkbox" name="overrides[<?php echo esc_attr($v_sku); ?>][<?php echo esc_attr($attr_name); ?>][variation]" <?php checked($is_variation_ov); ?>> Variations</label>
															</div>
														</div>
													<?php endforeach; ?>
												</div>
												<details style="margin-top: 15px;">
													<summary style="cursor: pointer; color: #2271b1;">View Detail</summary>
													<table class="variation-list-table">
														<thead>
															<tr>
																<th>SKU</th><?php foreach ($v_prod['attributes'] as $name => $vals) echo "<th>$name</th>"; ?>
															</tr>
														</thead>
														<tbody>
															<?php foreach ($v_prod['variations'] as $v) : ?>
																<tr>
																	<td><code><?php echo esc_html($v['sku']); ?></code></td><?php foreach ($v_prod['attributes'] as $name => $val) echo "<td>" . ($v['attributes'][$name] ?? '-') . "</td>"; ?>
																</tr>
															<?php endforeach; ?>
														</tbody>
													</table>
												</details>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
								<div style="position: sticky; bottom: 0; background: #f0f0f1; padding: 20px; border-top: 1px solid #ccc; z-index: 100; margin-top: 20px; box-shadow: 0 -5px 15px rgba(0,0,0,0.05); border-radius: 0 0 4px 4px; display: flex; justify-content: center; align-items: center; gap: 20px;">
									<div style="color: #666; font-size: 13px;">
										<span class="dashicons dashicons-info" style="font-size: 18px; width: 18px; height: 18px; vertical-align: middle;"></span>
										<?php _e('Review the mapping above and save to proceed to the final import step.', 'mjf-gsheet-importer'); ?>
									</div>
									<?php submit_button(__('Save Review & Go to Import', 'mjf-gsheet-importer'), 'primary large', 'submit', false); ?>
								</div>
							</form>
						<?php endif; ?>
					<?php else : ?>
						<p><?php _e('Please complete mapping first.', 'mjf-gsheet-importer'); ?></p>
					<?php endif; ?>
				</div>

				<!-- TAB 4: IMPORT -->
				<div id="tab-import" class="mjf-tab-content <?php echo $current_tab == 'import' ? 'active' : ''; ?>">
					<?php if ($step_import_ok) : ?>
						
						<?php if (isset($_GET['sync_done'])) :
							$results = get_option('mjf_gsheet_last_sync_results', []);
						?>
							<div class="notice notice-success is-dismissible" style="margin-top: 20px; padding: 15px;">
								<h3>🎉 <?php _e('Import Successful!', 'mjf-gsheet-importer'); ?></h3>
								<p><?php echo sprintf(__('Processed %d entries.', 'mjf-gsheet-importer'), intval($_GET['sync_done'])); ?></p>
								
								<?php if (!empty($results['imported'])) : ?>
									<h4><?php _e('Imported Products:', 'mjf-gsheet-importer'); ?></h4>
									<ul style="max-height: 200px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">
										<?php foreach ($results['imported'] as $res) : ?>
											<li>
												<strong><?php echo esc_html($res['sku']); ?></strong>: <?php echo esc_html($res['name']); ?> 
												— <a href="<?php echo esc_url($res['url']); ?>" target="_blank"><?php _e('Edit Product', 'mjf-gsheet-importer'); ?></a>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</div>
						<?php endif; ?>

						<div style="padding: 20px; border: 2px dashed #ddd; background: #fdfdfd; text-align: left;">
							<h2 style="text-align: center;"><?php _e('Select Products to Import', 'mjf-gsheet-importer'); ?></h2>
							<form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('<?php _e('Are you sure you want to run the final import now?', 'mjf-gsheet-importer'); ?>');">
								<input type="hidden" name="action" value="mjf_gsheet_sync">
								<?php wp_nonce_field('mjf_gsheet_sync_action', 'mjf_gsheet_nonce'); ?>
								
								<p style="text-align: center; color: #666; margin-bottom: 20px;"><?php _e('By default, all products are selected. Uncheck the ones you want to skip. If you only want to test one product, uncheck everything and select only that one.', 'mjf-gsheet-importer'); ?></p>
								
								<div style="background: #fff; border: 1px solid #ccd0d4; padding: 15px; margin-bottom: 20px; max-height: 300px; overflow-y: auto;">
									<label style="font-weight:bold; display:block; margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
										<input type="checkbox" id="select_all_imports" checked> <?php _e('Select / Deselect All', 'mjf-gsheet-importer'); ?>
									</label>
									<?php
									if (!empty($preview_data['simple'])) {
										echo '<strong style="display:inline-block; margin-top:10px;">' . __('Simple Products', 'mjf-gsheet-importer') . '</strong><br>';
										foreach ($preview_data['simple'] as $sku => $item) {
											echo '<label style="display:block; margin-bottom:5px; margin-left: 10px;"><input type="checkbox" class="import_sku_checkbox" name="import_skus[]" value="' . esc_attr($sku) . '" checked> ' . esc_html($item['title'] ?: $sku) . ' <code style="margin-left: 5px;">SKU: ' . esc_html($sku) . '</code></label>';
										}
									}
									if (!empty($preview_data['variable'])) {
										echo '<strong style="display:inline-block; margin-top: 15px;">' . __('Variable Products', 'mjf-gsheet-importer') . '</strong><br>';
										foreach ($preview_data['variable'] as $sku => $item) {
											$var_count = count($item['variations'] ?? []);
											echo '<label style="display:block; margin-bottom:5px; margin-left: 10px;"><input type="checkbox" class="import_sku_checkbox" name="import_skus[]" value="' . esc_attr($sku) . '" checked> ' . esc_html($item['title'] ?: $sku) . ' <code style="margin-left: 5px;">SKU: ' . esc_html($sku) . '</code> <small style="color:#666; margin-left: 5px;">('. $var_count .' variations)</small></label>';
										}
									}
									?>
								</div>
								<script>
									document.getElementById('select_all_imports').addEventListener('change', function(e) {
										var checkboxes = document.querySelectorAll('.import_sku_checkbox');
										for (var i = 0; i < checkboxes.length; i++) {
											checkboxes[i].checked = e.target.checked;
										}
									});
								</script>

								<div style="text-align: center;">
									<?php submit_button(__('Launch Final Synchronization', 'mjf-gsheet-importer'), 'primary large', 'submit', false); ?>
								</div>
							</form>
						</div>
						<div style="margin-top: 30px;">
							<h3><?php _e('Logs', 'mjf-gsheet-importer'); ?></h3>
							<div style="background: #f9f9f9; border: 1px solid #ccc; padding: 10px; max-height: 400px; overflow-y: auto; font-family: monospace;">
								<?php $logs = get_option('mjf_gsheet_import_logs', []);
								foreach (array_reverse($logs) as $log) : ?>
									<div>[<?php echo esc_html($log['time']); ?>] <?php echo esc_html($log['message']); ?></div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php else : ?>
						<p><?php _e('Please complete preview first.', 'mjf-gsheet-importer'); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<script>
				jQuery(document).ready(function($) {
					$('.mjf-tabs .nav-tab').on('click', function(e) {
						if ($(this).hasClass('nav-tab-disabled')) return false;
						e.preventDefault();

						var target = $(this).data('tab');

						// Update Tab UI
						$('.mjf-tabs .nav-tab').removeClass('nav-tab-active');
						$(this).addClass('nav-tab-active');

						// Update Content UI
						$('.mjf-tab-content').removeClass('active');
						$('#tab-' + target).addClass('active');

						// Update URL hash for better UX
						window.location.hash = target;
					});

					// Dynamic Row Management (Attributes & Metas)
					$('#add-attr-row').on('click', function() {
						var table = $('#attr-mapping-table tbody');
						var newRow = table.find('tr:first').clone();
						newRow.find('input').val('');
						newRow.find('select').val('');
						table.append(newRow);
					});

					$('#add-meta-row').on('click', function() {
						var table = $('#custom-meta-table tbody');
						var newRow = table.find('tr:first').clone();
						newRow.find('input').val('');
						newRow.find('select').val('');
						newRow.find('input[type="text"]').hide();
						table.append(newRow);
					});

					$(document).on('click', '.remove-row', function() {
						if ($(this).closest('tbody').find('tr').length > 1) {
							$(this).closest('tr').remove();
						}
					});
				});
			</script>
		</div>
<?php
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
	 * Handle Fetch Columns
	 */
	public function handle_fetch_cols()
	{
		check_admin_referer('mjf_gsheet_fetch_cols_action', 'mjf_gsheet_fetch_cols_nonce');

		$settings = get_option($this->option_name, []);

		// Save form values first if submitted
		if (isset($_POST['mjf_gsheet_importer']) && is_array($_POST['mjf_gsheet_importer'])) {
			$posted = wp_unslash($_POST['mjf_gsheet_importer']);
			if (isset($posted['sheet_id'])) $settings['sheet_id'] = sanitize_text_field($posted['sheet_id']);
			if (isset($posted['sheet_name'])) $settings['sheet_name'] = sanitize_text_field($posted['sheet_name']);
			if (isset($posted['header_row'])) $settings['header_row'] = absint($posted['header_row']);
			update_option($this->option_name, $settings);
		}

		$sheet_id = trim($settings['sheet_id'] ?? '');

		// Auto-clean Sheet ID if full URL was pasted
		if (strpos($sheet_id, 'docs.google.com') !== false) {
			preg_match('/\/d\/(.*?)(\/|$)/', $sheet_id, $matches);
			if (! empty($matches[1])) {
				$sheet_id = $matches[1];
				$settings['sheet_id'] = $sheet_id;
				update_option($this->option_name, $settings);
			}
		}

		$sheet_name = $settings['sheet_name'] ?? 'Sheet1';

		$sheet_name = $settings['sheet_name'] ?? 'Sheet1';

		// Standard CSV export URL is often more reliable for raw data
		$csv_url = sprintf(
			'https://docs.google.com/spreadsheets/d/%s/export?format=csv&sheet=%s',
			urlencode($sheet_id),
			urlencode($sheet_name)
		);

		$response = wp_remote_get($csv_url);
		if (is_wp_error($response)) {
			wp_die($response->get_error_message());
		}

		$body = trim(wp_remote_retrieve_body($response));

		// Detect HTML error page from Google
		if (strpos($body, '<!DOCTYPE html') !== false || strpos($body, '<html') !== false) {
			wp_die(__('Error: Google Sheets returned an HTML page instead of data. This usually means the Sheet ID is wrong or the sheet is NOT shared with "Anyone with the link can view".', 'mjf-gsheet-importer'));
		}

		// Robust CSV Parsing
		$temp_file = fopen('php://temp', 'r+');
		fwrite($temp_file, $body);
		rewind($temp_file);

		$data = [];
		while (($row = fgetcsv($temp_file)) !== false) {
			$data[] = $row;
		}
		fclose($temp_file);

		if (empty($data)) {
			wp_die(__('Could not read any data from the sheet.', 'mjf-gsheet-importer'));
		}

		// DEBUG: Log first 5 rows
		update_option('mjf_gsheet_import_logs', []);
		$this->log('--- DEBUG: First 5 rows detected ---');
		foreach (array_slice($data, 0, 5) as $index => $row) {
			$row_preview = array_map(function ($c) {
				return substr(trim($c), 0, 15);
			}, $row);
			$this->log(sprintf('Row %d: %s', $index + 1, implode(' | ', $row_preview)));
		}
		$this->log('------------------------------------');

		$header_row_index = ((int) ($settings['header_row'] ?? 1)) - 1;
		if (! isset($data[$header_row_index])) {
			wp_die(sprintf(__('Header row %d not found in the sheet.', 'mjf-gsheet-importer'), $settings['header_row']));
		}

		$headers = array_map('trim', $data[$header_row_index]);

		// Fallback for empty headers (happens with merged cells)
		foreach ($headers as $i => $h) {
			if (empty($h)) {
				// Check the row above if it exists (merge detection)
				if ($header_row_index > 0 && ! empty($data[$header_row_index - 1][$i])) {
					$headers[$i] = trim($data[$header_row_index - 1][$i]);
				} else {
					$headers[$i] = sprintf(__('Column %d (Empty)', 'mjf-gsheet-importer'), $i + 1);
				}
			}
		}

		$settings['available_columns'] = $headers;
		update_option($this->option_name, $settings);

		wp_redirect(admin_url('admin.php?page=mjf-gsheet-importer&tab=mapping&cols_detected=1#mapping'));
		exit;
	}

	/**
	 * Handle Save Mapping
	 */
	public function handle_save_mapping()
	{
		check_admin_referer('mjf_gsheet_save_mapping_action', 'mjf_gsheet_save_mapping_nonce');

		$mapping = [];
		if (isset($_POST['mapping']) && is_array($_POST['mapping'])) {
			$raw_mapping = wp_unslash($_POST['mapping']);
			$this->log('Raw POST mapping: ' . print_r($raw_mapping, true));
			foreach ($raw_mapping as $k => $v) {
				$mapping[sanitize_text_field($k)] = sanitize_text_field($v);
			}
			$this->log('Sanitized mapping: ' . print_r($mapping, true));
		}

		$custom_keys = isset($_POST['custom_mapping_keys']) ? wp_unslash($_POST['custom_mapping_keys']) : [];
		$custom_cols = isset($_POST['custom_mapping_cols']) ? wp_unslash($_POST['custom_mapping_cols']) : [];

		$custom_mapping = [];
		foreach ($custom_keys as $index => $key) {
			$key = trim($key);
			$col = $custom_cols[$index] ?? '';
			if (! empty($key) && ! empty($col)) {
				$custom_mapping[$key] = $col;
			}
		}

		$keys_select = isset($_POST['attr_mapping_keys_select']) ? wp_unslash($_POST['attr_mapping_keys_select']) : [];
		$keys_custom = isset($_POST['attr_mapping_keys_custom']) ? wp_unslash($_POST['attr_mapping_keys_custom']) : [];
		$attr_cols = isset($_POST['attr_mapping_cols']) ? wp_unslash($_POST['attr_mapping_cols']) : [];

		$attr_mapping = [];
		foreach ($attr_cols as $index => $col) {
			$key = trim($keys_select[$index] ?? '');
			if ($key === 'custom') {
				$key = trim($keys_custom[$index] ?? '');
			}
			if (! empty($key) && ! empty($col)) {
				$attr_mapping[$key] = $col;
			}
		}

		$settings = get_option($this->option_name, []);
		$settings['mapping'] = $mapping;
		$settings['custom_mapping'] = $custom_mapping;
		$settings['attr_mapping'] = $attr_mapping;

		update_option($this->option_name, $settings);

		wp_redirect(admin_url('admin.php?page=mjf-gsheet-importer&tab=preview&mapping_saved=1#preview'));
		exit;
	}

	/**
	 * Handle Preview Request
	 */
	public function handle_preview_request()
	{
		check_admin_referer('mjf_gsheet_preview_action', 'mjf_gsheet_preview_nonce');

		$settings = get_option($this->option_name, []);
		$sheet_id = $settings['sheet_id'] ?? '';
		$sheet_name = $settings['sheet_name'] ?? 'Sheet1';

		if (empty($sheet_id)) {
			wp_die(__('Please provide a Google Sheet ID.', 'mjf-gsheet-importer'));
		}

		$csv_url = sprintf(
			'https://docs.google.com/spreadsheets/d/%s/export?format=csv&sheet=%s',
			urlencode($sheet_id),
			urlencode($sheet_name)
		);

		$response = wp_remote_get($csv_url);
		if (is_wp_error($response)) {
			wp_die($response->get_error_message());
		}

		$body = trim(wp_remote_retrieve_body($response));
		if (strpos($body, '<!DOCTYPE html') !== false || strpos($body, '<html') !== false) {
			wp_die(__('Error: Google Sheets returned an HTML page. Check your Sheet ID and sharing settings.', 'mjf-gsheet-importer'));
		}

		$temp_file = fopen('php://temp', 'r+');
		fwrite($temp_file, $body);
		rewind($temp_file);

		$data = [];
		while (($row = fgetcsv($temp_file)) !== false) {
			$data[] = $row;
		}
		fclose($temp_file);

		$header_row_index = ((int) ($settings['header_row'] ?? 1)) - 1;
		$headers = $data[$header_row_index] ?? [];
		$rows = array_slice($data, $header_row_index + 1);

		$preview_data = [
			'simple'   => [],
			'variable' => [],
		];

		foreach ($rows as $row) {
			if (count($row) !== count($headers)) continue;

			$raw_item = array_combine($headers, $row);
			$item = $this->map_item_fields($raw_item);
			$sku = $item['sku'];
			$type = strtolower($item['type']); // Case-insensitive type
			$parent_sku = $item['parent_sku'];

			if (empty($sku) || empty($item['title'])) continue;

			if ($type === 'simple') {
				$preview_data['simple'][$sku] = $item;
			} elseif ($type === 'variable') {
				// Initialize parent attributes as arrays for unique value aggregation
				if (!empty($item['attributes'])) {
					foreach ($item['attributes'] as $k => $v) {
						$item['attributes'][$k] = [$v];
					}
				}
				$item['variations'] = [];
				$preview_data['variable'][$sku] = $item;
			} elseif ($type === 'variation' && ! empty($parent_sku)) {
				if (isset($preview_data['variable'][$parent_sku])) {
					$preview_data['variable'][$parent_sku]['variations'][] = $item;

					// Aggregate attributes from variation to parent for preview review
					if (! empty($item['attributes'])) {
						foreach ($item['attributes'] as $attr_name => $attr_val) {
							if (! isset($preview_data['variable'][$parent_sku]['attributes'][$attr_name])) {
								$preview_data['variable'][$parent_sku]['attributes'][$attr_name] = [];
							} elseif (! is_array($preview_data['variable'][$parent_sku]['attributes'][$attr_name])) {
								// Fallback just in case
								$preview_data['variable'][$parent_sku]['attributes'][$attr_name] = [$preview_data['variable'][$parent_sku]['attributes'][$attr_name]];
							}

							if (! in_array($attr_val, $preview_data['variable'][$parent_sku]['attributes'][$attr_name])) {
								$preview_data['variable'][$parent_sku]['attributes'][$attr_name][] = $attr_val;
							}
						}
					}
				} else {
					// Parent not found yet or not in list
					$preview_data['simple'][$sku] = $item; // Fallback
				}
			}
		}

		update_option('mjf_gsheet_preview_data', $preview_data);
		delete_option('mjf_gsheet_overrides'); // Reset overrides on new analysis
		
		wp_redirect(admin_url('admin.php?page=mjf-gsheet-importer&tab=preview&preview_done=1#preview'));
		exit;
	}

	/**
	 * Handle Save Review Request
	 */
	public function handle_save_review_request()
	{
		check_admin_referer('mjf_gsheet_save_review_action', 'mjf_gsheet_save_review_nonce');

		$overrides = $_POST['overrides'] ?? [];
		update_option('mjf_gsheet_overrides', $overrides);

		wp_redirect(admin_url('admin.php?page=mjf-gsheet-importer&tab=import&review_saved=1#import'));
		exit;
	}

	/**
	 * Handle Sync Request
	 */
	public function handle_sync_request()
	{
		if (! current_user_can('manage_woocommerce')) {
			wp_die(__('You do not have sufficient permissions to access this page.', 'mjf-gsheet-importer'));
		}

		check_admin_referer('mjf_gsheet_sync_action', 'mjf_gsheet_nonce');

		$preview_data = get_option('mjf_gsheet_preview_data', []);
		$overrides = get_option('mjf_gsheet_overrides', []);
		$results = [
			'imported' => [],
			'errors'   => []
		];

		if (empty($preview_data)) {
			wp_die(__('No preview data found. Please run Step 2 first.', 'mjf-gsheet-importer'));
		}

		update_option('mjf_gsheet_import_logs', []);
		$this->log(__('Starting final import with manual overrides...', 'mjf-gsheet-importer'));

		// Retrieve selected SKUs
		$selected_skus = isset($_POST['import_skus']) && is_array($_POST['import_skus']) ? array_map('sanitize_text_field', wp_unslash($_POST['import_skus'])) : [];

		if (empty($selected_skus)) {
			wp_redirect(admin_url('admin.php?page=mjf-gsheet-importer&tab=import&sync_done=0#import'));
			exit;
		}

		$processed_count = 0;

		// Apply Overrides to preview data before processing
		foreach ($overrides as $sku => $sku_overrides) {
			if (isset($preview_data['variable'][$sku])) {
				$preview_data['variable'][$sku]['attr_settings'] = $sku_overrides;
			}
		}

		// 1. Process Variable Products Parents
		foreach ($preview_data['variable'] as $sku => $item) {
			if (!in_array($sku, $selected_skus)) continue;
			$product_id = $this->upsert_product($item, 'variable');
			if ($product_id) {
				$results['imported'][] = [
					'sku'  => $sku,
					'id'   => $product_id,
					'name' => $item['title'],
					'url'  => get_edit_post_link($product_id)
				];
				$processed_count++;

				// Handle WPML Translation (EN) if fields are mapped
				if (! empty($item['title_en']) || ! empty($item['description_en']) || ! empty($item['content_en'])) {
					$this->upsert_translation($product_id, $item, 'en');
				}
			}
		}

		// 2. Process Simple Products
		foreach ($preview_data['simple'] as $sku => $item) {
			if (!in_array($sku, $selected_skus)) continue;
			$product_id = $this->upsert_product($item, 'simple');
			if ($product_id) {
				$results['imported'][] = [
					'sku'  => $sku,
					'id'   => $product_id,
					'name' => $item['title'],
					'url'  => get_edit_post_link($product_id)
				];
				$processed_count++;

				// Handle WPML Translation (EN) if fields are mapped
				if (! empty($item['title_en']) || ! empty($item['description_en']) || ! empty($item['content_en'])) {
					$this->upsert_translation($product_id, $item, 'en');
				}
			}
		}

		// 3. Process Variations
		foreach ($preview_data['variable'] as $p_sku => $p_item) {
			if (!in_array($p_sku, $selected_skus)) continue;
			
			foreach ($p_item['variations'] as $v_item) {
				// Inherit all empty fields from parent
				$exclude_keys = ['variations', 'sku', 'type', 'parent_sku', 'attributes', 'custom_meta', 'attr_settings', 'description', 'short_description'];
				foreach ($p_item as $key => $val) {
					if (in_array($key, $exclude_keys)) continue;
					if (empty($v_item[$key]) && !empty($val)) {
						$v_item[$key] = $val;
					}
				}

				// Inherit custom meta specifically
				if (!empty($p_item['custom_meta'])) {
					if (!isset($v_item['custom_meta'])) $v_item['custom_meta'] = [];
					foreach ($p_item['custom_meta'] as $mk => $mv) {
						if (empty($v_item['custom_meta'][$mk]) && !empty($mv)) {
							$v_item['custom_meta'][$mk] = $mv;
						}
					}
				}

				// Pass parent overrides to variation
				if (! empty($p_item['attr_settings'])) {
					$v_item['attr_settings'] = $p_item['attr_settings'];
				}
				
				$this->upsert_variation($v_item);
				$processed_count++;
			}

			// Force sync of children list for the parent
			$p_id = wc_get_product_id_by_sku($p_sku);
			if ($p_id) {
				$parent_product = wc_get_product($p_id);
				if ($parent_product && $parent_product->is_type('variable')) {
					// 1. Force the data store to refresh children
					$data_store = $parent_product->get_data_store();
					$data_store->read_children($parent_product, true);
					
					// 2. Save to persist the _children meta and clear transients
					$parent_product->save();
					
					// 3. Clear cache aggressively
					clean_post_cache($p_id);
					wc_delete_product_transients($p_id);
					
					$this->log(sprintf(__('Aggressive children synchronization for parent SKU: %s', 'mjf-gsheet-importer'), $p_sku));
				}
			}
		}

		update_option('mjf_gsheet_last_sync_results', $results);
		$this->log(sprintf(__('Final import finished. %d total entries processed.', 'mjf-gsheet-importer'), $processed_count));

		wp_redirect(admin_url('admin.php?page=mjf-gsheet-importer&tab=import&sync_done=' . $processed_count . '#import'));
		exit;
	}

	/**
	 * Map raw CSV item to internal fields using user mapping
	 */
	private function map_item_fields($raw)
	{
		$settings = get_option($this->option_name, []);
		$mapping = $settings['mapping'] ?? [];
		$custom_mapping = $settings['custom_mapping'] ?? [];
		$attr_mapping = $settings['attr_mapping'] ?? [];

		$mapped = [];
		foreach ($mapping as $internal => $sheet_col) {
			$mapped[$internal] = '';
			if (! empty($sheet_col) && isset($raw[$sheet_col])) {
				$mapped[$internal] = trim($raw[$sheet_col]);
			}
		}

		// Handle Attributes
		$mapped['attributes'] = [];
		foreach ($attr_mapping as $attr_name => $sheet_col) {
			if (! empty($sheet_col) && isset($raw[$sheet_col]) && trim($raw[$sheet_col]) !== '') {
				$mapped['attributes'][$attr_name] = trim($raw[$sheet_col]);
			}
		}

		// Handle Custom Meta
		$mapped['custom_meta'] = [];
		foreach ($custom_mapping as $meta_key => $sheet_col) {
			if (! empty($sheet_col) && isset($raw[$sheet_col])) {
				$mapped['custom_meta'][$meta_key] = trim($raw[$sheet_col]);
			}
		}

		// Default type to simple if empty
		if (empty($mapped['type'])) {
			$mapped['type'] = 'simple';
		}
		$mapped['type'] = strtolower($mapped['type']);

		return $mapped;
	}

	/**
	 * Upsert Simple or Variable Product
	 */
	private function upsert_product($item, $type)
	{
		$sku = $item['sku'];
		if (empty($sku)) return;

		$product_id = wc_get_product_id_by_sku($sku);

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
		if (! empty($item['shipping_class'])) {
			$this->log(sprintf('Attempting to set shipping class "%s" for product %s', $item['shipping_class'], $sku));
			$term = get_term_by('name', $item['shipping_class'], 'product_shipping_class');
			if (!$term) {
				$term = get_term_by('slug', sanitize_title($item['shipping_class']), 'product_shipping_class');
			}
			if ($term) {
				$this->log(sprintf('Found existing shipping class term id %d', $term->term_id));
				$product->set_shipping_class_id(absint($term->term_id));
			} else {
				$this->log(sprintf('Creating new shipping class term %s', $item['shipping_class']));
				$term_data = wp_insert_term($item['shipping_class'], 'product_shipping_class');
				if (! is_wp_error($term_data)) {
					$product->set_shipping_class_id(absint($term_data['term_id']));
					$this->log(sprintf('Created term id %d', $term_data['term_id']));
				} else {
					$this->log('Failed to create term: ' . $term_data->get_error_message());
					// If it failed because it exists (e.g. cached), try one more time by exact slug
					if ($term_data->get_error_code() === 'term_exists') {
						$existing_term_id = $term_data->get_error_data();
						$product->set_shipping_class_id(absint($existing_term_id));
						$this->log(sprintf('Recovered existing term id %d', $existing_term_id));
					}
				}
			}
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
				$product->update_meta_data($meta_key, $item[$item_key]);
				if ($item_key === 'fedex_country') {
					$product->update_meta_data('_ph_manufacture_country', $item[$item_key]);
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

		$product_id = $product->save();
		wc_delete_product_transients($product_id);
		
		// Sync taxonomy terms for global attributes (essential for linking variations)
		if ($type === 'variable' && ! empty($item['attributes'])) {
			$product_attributes = $product->get_attributes();
			foreach ($product_attributes as $attr) {
				if ($attr->is_taxonomy()) {
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
		if (empty($sku) || empty($parent_sku)) return;

		$parent_id = wc_get_product_id_by_sku($parent_sku);
		if (! $parent_id) {
			$this->log(sprintf(__('Skipped variation %s: Parent SKU %s not found.', 'mjf-gsheet-importer'), $sku, $parent_sku));
			return;
		}

		$parent_product = wc_get_product($parent_id);

		$variation_id = wc_get_product_id_by_sku($sku);
		
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
		if (! empty($item['shipping_class'])) {
			$this->log(sprintf('Attempting to set shipping class "%s" for variation %s', $item['shipping_class'], $sku));
			$term = get_term_by('name', $item['shipping_class'], 'product_shipping_class');
			if (!$term) {
				$term = get_term_by('slug', sanitize_title($item['shipping_class']), 'product_shipping_class');
			}
			if ($term) {
				$this->log(sprintf('Found existing shipping class term id %d', $term->term_id));
				$variation->set_shipping_class_id(absint($term->term_id));
			} else {
				$this->log(sprintf('Creating new shipping class term %s', $item['shipping_class']));
				$term_data = wp_insert_term($item['shipping_class'], 'product_shipping_class');
				if (! is_wp_error($term_data)) {
					$variation->set_shipping_class_id(absint($term_data['term_id']));
					$this->log(sprintf('Created term id %d', $term_data['term_id']));
				} else {
					$this->log('Failed to create term: ' . $term_data->get_error_message());
					if ($term_data->get_error_code() === 'term_exists') {
						$existing_term_id = $term_data->get_error_data();
						$variation->set_shipping_class_id(absint($existing_term_id));
						$this->log(sprintf('Recovered existing term id %d', $existing_term_id));
					}
				}
			}
		}

		if (isset($item['price']) && $item['price'] !== '') {
			$variation->set_regular_price((float)$item['price']);
		}

		if (isset($item['stock']) && $item['stock'] !== '') {
			$variation->set_manage_stock(true);
			$variation->set_stock_quantity((int)$item['stock']);
		} else {
			$variation->set_manage_stock(false);
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
				$variation->update_meta_data($meta_key, $item[$item_key]);
				if ($item_key === 'fedex_country') {
					$variation->update_meta_data('_ph_manufacture_country', $item[$item_key]);
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

		// Custom Meta
		if (! empty($item['custom_meta'])) {
			foreach ($item['custom_meta'] as $meta_key => $meta_value) {
				$variation->update_meta_data($meta_key, $meta_value);
			}
		}

		$variation->save();

		// Update title based on attributes (e.g. "Product - XL, Red")
		$variation_id = $variation->get_id();
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
		$cat_ids = [];
		$parent_id = 0;

		// 1. Process Primary
		if (! empty($primary_name)) {
			$primary_name = trim($primary_name);
			$term = get_term_by('name', $primary_name, 'product_cat');
			if (! $term) {
				$term = wp_insert_term($primary_name, 'product_cat');
				if (! is_wp_error($term)) {
					$parent_id = $term['term_id'];
				}
			} else {
				$parent_id = $term->term_id;
			}
			if ($parent_id) $cat_ids[] = $parent_id;
		}

		// 2. Process Secondary
		if (! empty($secondary_name) && $parent_id) {
			$secondary_name = trim($secondary_name);
			// Check if child exists under this specific parent
			$found_term_id = 0;

			// Search for a term with same name and correct parent
			$terms = get_terms([
				'taxonomy'   => 'product_cat',
				'name'       => $secondary_name,
				'parent'     => $parent_id,
				'hide_empty' => false,
			]);

			if (! empty($terms) && ! is_wp_error($terms)) {
				$found_term_id = $terms[0]->term_id;
			}

			if (! $found_term_id) {
				$term = wp_insert_term($secondary_name, 'product_cat', ['parent' => $parent_id]);
				if (! is_wp_error($term)) {
					$found_term_id = $term['term_id'];
				}
			}

			if ($found_term_id) $cat_ids[] = $found_term_id;
		}

		return $cat_ids;
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
					
					if ($attr_id > 0) {
						$attribute->set_id($attr_id);
						$attribute->set_name($display_name);
						$this->log(sprintf(__('Set global attribute "%s" (ID: %d) via fallback', 'mjf-gsheet-importer'), $display_name, $attr_id));
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
	private function update_variation_title($variation_id) {
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
			
			// Save to get an ID
			$translated_id = $translated_product->save();

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

			// Force attributes for variable translation
			if ($original_product->is_type('variable')) {
				$attrs = $original_product->get_attributes();
				$translated_product->set_attributes($attrs);
				$translated_product->save();
			}

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
			
			$translated_product->save();
		}
	}
}

// Initialize the plugin
new MJF_GSheet_Product_Importer();
