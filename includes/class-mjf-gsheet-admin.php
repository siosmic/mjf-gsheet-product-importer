<?php

if (! defined('ABSPATH')) {
	exit;
}

class MJF_GSheet_Admin
{
	private $option_name = 'mjf_gsheet_importer_settings';

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

		$current_tab = isset($_GET['tab']) ? $_GET['tab'] : 'sync';

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

			/* Classic Log UI */
			.mjf-log-table {
				border-collapse: collapse;
				width: 100%;
				background: #fff;
				border: 1px solid #dcdcde;
			}

			.mjf-log-table th {
				background: #f6f7f7;
				padding: 9px 12px;
				font-size: 12px;
				font-weight: 600;
				border-bottom: 1px solid #dcdcde;
				text-align: left;
			}

			.mjf-log-table td {
				padding: 8px 12px;
				vertical-align: middle;
				border-bottom: 1px solid #f0f0f1;
			}

			.mjf-log-row:hover td {
				background-color: #f6f7f7 !important;
			}

			/* Warning: Orange color-code */
			.mjf-log-row.mjf-log-level-warning td {
				background-color: #fffbf0;
			}
			.mjf-log-row.mjf-log-level-warning td:first-child {
				border-left: 4px solid #f59e0b;
			}

			/* Error: Red color-code */
			.mjf-log-row.mjf-log-level-error td {
				background-color: #fef2f2;
			}
			.mjf-log-row.mjf-log-level-error td:first-child {
				border-left: 4px solid #ef4444;
			}

			.mjf-log-badge {
				display: inline-block;
				padding: 2px 7px;
				border-radius: 3px;
				font-size: 10px;
				font-weight: 700;
				font-family: monospace;
				letter-spacing: 0.5px;
				line-height: 1.3;
				white-space: nowrap;
			}

			/* Orange = Warning */
			.mjf-log-warning {
				background: #fef3c7;
				color: #b45309;
				border: 1px solid #fcd34d;
			}

			/* Rouge = Error */
			.mjf-log-error {
				background: #fee2e2;
				color: #b91c1c;
				border: 1px solid #fca5a5;
			}

			.mjf-log-success {
				background: #dcfce7;
				color: #15803d;
				border: 1px solid #86efac;
			}

			.mjf-log-info {
				background: #f1f5f9;
				color: #475569;
				border: 1px solid #cbd5e1;
			}

			.mjf-log-empty-state {
				padding: 40px 20px;
				text-align: center;
				background: #fafafa;
				border: 1px dashed #c3c4c7;
				border-radius: 4px;
			}

			.mjf-log-pagination-wrapper {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-top: 15px;
				padding-top: 12px;
				border-top: 1px solid #dcdcde;
				flex-wrap: wrap;
				gap: 10px;
			}

			/* Perfect Dashicon Alignment in all Buttons */
			.mjf-step-card .button,
			.mjf-step-card a.button,
			.mjf-log-toolbar .button,
			#tab-logs .button,
			#tab-logs a.button,
			.mjf-btn-with-icon {
				display: inline-flex !important;
				align-items: center !important;
				justify-content: center !important;
				vertical-align: middle !important;
				gap: 6px !important;
				line-height: normal !important;
			}

			.mjf-step-card .button .dashicons,
			.mjf-step-card a.button .dashicons,
			#tab-logs .button .dashicons,
			#tab-logs a.button .dashicons,
			.mjf-btn-with-icon .dashicons {
				font-size: 16px !important;
				width: 16px !important;
				height: 16px !important;
				line-height: 16px !important;
				vertical-align: middle !important;
				margin: 0 !important;
				padding: 0 !important;
				display: inline-block !important;
				flex-shrink: 0 !important;
			}
		</style>

		<div class="wrap">
			<h1><?php _e('MJF Product Importer', 'mjf-gsheet-importer'); ?></h1>

			<h2 class="nav-tab-wrapper mjf-tabs">
				<a href="#sync" class="nav-tab <?php echo $current_tab == 'sync' ? 'nav-tab-active' : ''; ?>" data-tab="sync">1. <?php _e('Sync Dashboard', 'mjf-gsheet-importer'); ?></a>
				<a href="#settings" class="nav-tab <?php echo $current_tab == 'settings' ? 'nav-tab-active' : ''; ?>" data-tab="settings">2. <?php _e('Settings', 'mjf-gsheet-importer'); ?></a>
				<a href="#logs" class="nav-tab <?php echo $current_tab == 'logs' ? 'nav-tab-active' : ''; ?>" data-tab="logs">3. <?php _e('Logs', 'mjf-gsheet-importer'); ?></a>
			</h2>

			<div class="mjf-step-card">
				<!-- TAB: SYNC DASHBOARD -->
				<div id="tab-sync" class="mjf-tab-content <?php echo $current_tab == 'sync' ? 'active' : ''; ?>">
					<h2><?php _e('Synchronization Dashboard', 'mjf-gsheet-importer'); ?></h2>
					<p><?php _e('Click the button below to fetch the latest data from Google Sheets and compare it with the WooCommerce database.', 'mjf-gsheet-importer'); ?></p>

					<button id="mjf-generate-comparison" class="button button-primary button-large"><?php _e('Refresh Sync Base', 'mjf-gsheet-importer'); ?></button>
					<span id="mjf-sync-msg" style="margin-left: 10px; color: #46b450; font-weight: 500; display: none;"></span>
					<span class="spinner" id="mjf-sync-spinner" style="float:none; margin-top:0;"></span>

					<div id="mjf-progress-container" style="display:none; margin-top:20px; width:100%; background:#f0f0f1; border-radius:3px; height:20px; overflow:hidden;">
						<div id="mjf-progress-bar" style="width:0%; height:100%; background:#2271b1; transition:width 0.3s;"></div>
					</div>
					<p id="mjf-progress-text" style="text-align:center; display:none;"></p>

					<hr style="margin:30px 0;">

					<div id="mjf-comparison-results">
						<!-- React/AJAX list will be rendered here -->
						<p style="color: #666; font-style: italic;"><?php _e('No comparison generated yet.', 'mjf-gsheet-importer'); ?></p>
					</div>
				</div>

				<!-- TAB: SETTINGS -->
				<div id="tab-settings" class="mjf-tab-content <?php echo $current_tab == 'settings' ? 'active' : ''; ?>">
					<form method="post" action="options.php">
						<?php settings_fields('mjf_gsheet_importer_group'); ?>
						<table class="form-table">
							<tr>
								<th scope="row"><label for="sheet_id"><?php _e('Google Sheet ID', 'mjf-gsheet-importer'); ?></label></th>
								<td>
									<input name="mjf_gsheet_importer_settings[sheet_id]" type="text" id="sheet_id" value="<?php echo esc_attr($sheet_id); ?>" class="regular-text">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="sheet_name"><?php _e('Sheet Tab Name', 'mjf-gsheet-importer'); ?></label></th>
								<td>
									<input name="mjf_gsheet_importer_settings[sheet_name]" type="text" id="sheet_name" value="<?php echo esc_attr($sheet_name); ?>" class="regular-text">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="header_row"><?php _e('Header Row Number', 'mjf-gsheet-importer'); ?></label></th>
								<td>
									<input name="mjf_gsheet_importer_settings[header_row]" type="number" id="header_row" value="<?php echo esc_attr($settings['header_row'] ?? 1); ?>" class="small-text">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="google_service_account"><?php _e('Google Service Account JSON', 'mjf-gsheet-importer'); ?></label></th>
								<td>
									<textarea name="mjf_gsheet_importer_settings[google_service_account]" id="google_service_account" rows="10" class="large-text code" placeholder='{"type": "service_account", ...}'><?php echo esc_textarea($settings['google_service_account'] ?? ''); ?></textarea>
									<p class="description"><?php _e('Required for WP -> GS synchronization. Paste the full JSON content of your Google Service Account key here.', 'mjf-gsheet-importer'); ?></p>
								</td>
							</tr>
						</table>
						<?php submit_button(__('Save Settings', 'mjf-gsheet-importer')); ?>
					</form>
				</div>

				<!-- TAB: LOGS -->
				<div id="tab-logs" class="mjf-tab-content <?php echo $current_tab == 'logs' ? 'active' : ''; ?>">
					<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; margin-bottom: 15px; gap: 15px;">
						<div>
							<h2 style="margin: 0;"><?php _e('Activity Logs', 'mjf-gsheet-importer'); ?></h2>
							<p style="margin: 4px 0 0; color: #646970; font-size: 13px;"><?php _e('History of synchronizations, product creations, and errors.', 'mjf-gsheet-importer'); ?></p>
						</div>
						<div style="display:flex; gap:10px; align-items:center;">
							<button id="mjf-refresh-logs-btn" class="button button-secondary mjf-btn-with-icon" type="button" title="<?php esc_attr_e('Refresh logs', 'mjf-gsheet-importer'); ?>">
								<span class="dashicons dashicons-update"></span>
								<span><?php _e('Refresh', 'mjf-gsheet-importer'); ?></span>
							</button>
							<button id="mjf-clear-logs-btn" class="button mjf-btn-with-icon" type="button" style="color:#b32d2e; border-color:#d63638; background:#fff;">
								<span class="dashicons dashicons-trash"></span>
								<span><?php _e('Clear Logs', 'mjf-gsheet-importer'); ?></span>
							</button>
							<span class="spinner" id="mjf-logs-spinner" style="float:none; margin:0;"></span>
						</div>
					</div>

					<!-- Logs Container (AJAX Table) -->
					<div id="mjf-logs-container">
						<p style="color: #646970; font-style: italic;"><?php _e('Loading logs...', 'mjf-gsheet-importer'); ?></p>
					</div>
				</div>
			</div>

			<script>
				jQuery(document).ready(function($) {
					// Tab switching
					$('.mjf-tabs .nav-tab').on('click', function(e) {
						if ($(this).hasClass('nav-tab-disabled')) return false;
						e.preventDefault();

						var target = $(this).data('tab');
						$('.mjf-tabs .nav-tab').removeClass('nav-tab-active');
						$(this).addClass('nav-tab-active');

						$('.mjf-tab-content').removeClass('active');
						$('#tab-' + target).addClass('active');

						if (target === 'sync') {
							loadComparisonPage(1);
						} else if (target === 'logs') {
							loadLogsPage(1);
						}

						history.pushState(null, null, '#' + target);
					});

					// Load Comparison Page
					function loadComparisonPage(page) {
						$('#mjf-comparison-results').html('<p>Loading...</p>');
						$.post(ajaxurl, {
							action: 'mjf_gsheet_get_comparison_page',
							page: page
						}, function(response) {
							if (response.success) {
								$('#mjf-comparison-results').html(response.data.html);
							} else {
								$('#mjf-comparison-results').html('<p style="color:red;">Error loading page.</p>');
							}
						});
					}

					// Initial load if on sync or logs tab
					if ($('#tab-sync').hasClass('active')) {
						loadComparisonPage(1);
					} else if ($('#tab-logs').hasClass('active')) {
						loadLogsPage(1);
					}

					// Generate Comparison
					$('#mjf-generate-comparison').on('click', function(e) {
						e.preventDefault();
						var $btn = $(this);
						var $spinner = $('#mjf-sync-spinner');

						$btn.prop('disabled', true);
						$spinner.addClass('is-active');
						$('#mjf-comparison-results').html('<p>Analyzing Google Sheets data against WordPress database. This might take a while...</p>');

						$.post(ajaxurl, {
							action: 'mjf_gsheet_generate_comparison'
						}, function(response) {
							$btn.prop('disabled', false);
							$spinner.removeClass('is-active');

							if (response.success) {
								loadComparisonPage(1);
								$('#mjf-sync-msg').text(response.data.message).fadeIn();
								setTimeout(function() {
									$('#mjf-sync-msg').fadeOut();
								}, 2500);
							} else {
								$('#mjf-comparison-results').html('<p style="color:red;">Error: ' + response.data + '</p>');
							}
						}).fail(function() {
							$btn.prop('disabled', false);
							$spinner.removeClass('is-active');
							$('#mjf-comparison-results').html('<p style="color:red;">A fatal error occurred. Please check your PHP error logs.</p>');
						});
					});

					// Handle Pagination Clicks
					$(document).on('click', '.mjf-page-btn', function(e) {
						e.preventDefault();
						loadComparisonPage($(this).data('page'));
					});


					// Accordion Toggle
					$(document).on('click', '.mjf-row-clickable', function(e) {
						if ($(e.target).closest('button, a').length || $(e.target).is('button, a')) return;
						e.preventDefault();
						var sku = $(this).data('sku');
						$('.mjf-variation-row[data-parent-sku="' + sku + '"]').toggle();
						var icon = $(this).find('.mjf-chevron-icon');
						if (icon.hasClass('dashicons-arrow-down-alt2')) {
							icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
						} else {
							icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
						}
					});


					// Inline Sync Buttons
					$(document).on('click', '.mjf-inline-sync-btn', function(e) {
						e.preventDefault();
						var $btn = $(this);
						var id = $btn.data('id');
						var field = $btn.data('field');
						var dir = $btn.data('dir');

						$btn.prop('disabled', true);

						$.post(ajaxurl, {
							action: 'mjf_gsheet_sync_single_field',
							id: id,
							field: field,
							direction: dir
						}, function(response) {
							if (response.success) {
								$btn.closest('tr').fadeOut(function() {
									$(this).remove();
								});
							} else {
								alert('Error: ' + response.data);
								$btn.prop('disabled', false);
							}
						}).fail(function() {
							alert('Fatal Error');
							$btn.prop('disabled', false);
						});
					});



					// Create Product Button
					$(document).on('click', '.mjf-create-product-btn', function(e) {
						e.preventDefault();
						var $btn = $(this);
						var id = $btn.data('id');
						if (!confirm('Créer ce produit dans WooCommerce avec les données de la Google Sheet ?')) return;
						$btn.prop('disabled', true).text('Création en cours...');
						$.post(ajaxurl, {
							action: 'mjf_gsheet_create_product',
							id: id
						}, function(response) {
							if (response.success) {
								$btn.closest('.mjf-creation-panel').html('<div style="padding:10px; color:#46b450;"><span class="dashicons dashicons-yes-alt"></span> ' + response.data.message + '</div>');
								setTimeout(function() {
									loadComparisonPage(1);
								}, 1500);
							} else {
								alert('Erreur : ' + response.data);
								$btn.prop('disabled', false).text('Créer dans WooCommerce');
							}
						}).fail(function() {
							alert('Erreur fatale lors de la création.');
							$btn.prop('disabled', false).text('Créer dans WooCommerce');
						});
					});

					// Create Translation Button
					$(document).on('click', '.mjf-create-translation-btn', function(e) {
						e.preventDefault();
						var $btn = $(this);
						var id = $btn.data('id');
						if (!confirm('Créer la traduction anglaise pour ce produit ?')) return;

						$btn.prop('disabled', true).text('Création de la traduction...');
						$.post(ajaxurl, {
							action: 'mjf_gsheet_create_translation',
							id: id
						}, function(response) {
							if (response.success) {
								$btn.closest('.mjf-translation-panel').html('<div style="padding:10px; color:#46b450;"><span class="dashicons dashicons-yes-alt"></span> ' + response.data + '</div>');
								setTimeout(function() {
									loadComparisonPage(1);
								}, 1500);
							} else {
								var msg = (response && response.data) ? response.data : (typeof response === 'string' ? response : 'Erreur inconnue');
								alert('Erreur : ' + msg);
								$btn.prop('disabled', false).text('Créer la traduction Anglaise');
							}
						}).fail(function() {
							alert('Erreur fatale lors de la création de la traduction.');
							$btn.prop('disabled', false).text('Créer la traduction Anglaise');
						});
					});

					// Handle Direction Selection
					$(document).on('change', '.mjf-sync-direction', function() {
						var $select = $(this);
						var id = $select.data('id');
						var field = $select.data('field');
						var direction = $select.val();

						$select.css('opacity', '0.5');
						$.post(ajaxurl, {
							action: 'mjf_gsheet_update_sync_direction',
							id: id,
							field: field,
							direction: direction
						}, function(response) {
							$select.css('opacity', '1');
						});
					});

					// Execute Sync
					$(document).on('click', '#mjf-execute-sync-btn', function(e) {
						e.preventDefault();
						var $btn = $(this);
						$btn.prop('disabled', true).text('Syncing...');

						function processBatch() {
							$.post(ajaxurl, {
								action: 'mjf_gsheet_execute_sync'
							}, function(response) {
								if (response.success) {
									if (response.data.continue) {
										processBatch(); // recursive batching
									} else {
										alert(response.data.message);
										$btn.prop('disabled', false).text('Run Sync');
										loadComparisonPage(1);
									}
								} else {
									alert('Error: ' + response.data);
									$btn.prop('disabled', false).text('Run Sync');
								}
							}).fail(function() {
								alert('A fatal error occurred during sync.');
								$btn.prop('disabled', false).text('Run Sync');
							});
						}

						processBatch();
					});

					// --- LOGS TAB LOGIC ---
					// Logs Handling
					var currentLogsPage = 1;

					function loadLogsPage(page) {
						page = page || 1;
						currentLogsPage = page;
						var $container = $('#mjf-logs-container');
						var $spinner = $('#mjf-logs-spinner');

						$spinner.addClass('is-active');

						$.post(ajaxurl, {
							action: 'mjf_gsheet_get_logs',
							page: page
						}, function(response) {
							$spinner.removeClass('is-active');
							if (response.success) {
								$container.html(response.data.html);
							} else {
								$container.html('<p style="color:red; padding: 20px;">' + (response.data || 'Error loading logs.') + '</p>');
							}
						}).fail(function() {
							$spinner.removeClass('is-active');
							$container.html('<p style="color:red; padding: 20px;">A network error occurred while loading logs.</p>');
						});
					}

					// Refresh logs
					$('#mjf-refresh-logs-btn').on('click', function(e) {
						e.preventDefault();
						loadLogsPage(currentLogsPage);
					});

					// Clear all logs
					$('#mjf-clear-logs-btn').on('click', function(e) {
						e.preventDefault();
						if (!confirm('Are you sure you want to clear all logs?')) {
							return;
						}

						var $btn = $(this);
						$btn.prop('disabled', true);

						$.post(ajaxurl, {
							action: 'mjf_gsheet_clear_logs'
						}, function(response) {
							$btn.prop('disabled', false);
							if (response.success) {
								loadLogsPage(1);
							} else {
								alert('Error: ' + (response.data || 'Failed to clear logs.'));
							}
						}).fail(function() {
							$btn.prop('disabled', false);
							alert('Communication error while clearing logs.');
						});
					});

					// Log pagination buttons
					$(document).on('click', '.mjf-log-page-btn', function(e) {
						e.preventDefault();
						loadLogsPage($(this).data('page'));
					});

					// Ensure UI opens correct tab on load
					var hash = window.location.hash.substring(1);
					if (hash && $('#tab-' + hash).length) {
						$('.mjf-tabs .nav-tab[data-tab="' + hash + '"]').click();
					}
				});
			</script>
		</div>
<?php
	}
}
