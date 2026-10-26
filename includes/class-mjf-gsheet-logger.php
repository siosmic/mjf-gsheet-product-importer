<?php

if (! defined('ABSPATH')) {
	exit;
}

class MJF_GSheet_Logger
{
	const LEVEL_INFO    = 'info';
	const LEVEL_SUCCESS = 'success';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	/**
	 * Get table name with prefix
	 */
	public static function get_table_name()
	{
		global $wpdb;
		return $wpdb->prefix . 'mjf_gsheet_logs';
	}

	/**
	 * Create DB Table for Logs
	 */
	public static function create_table()
	{
		global $wpdb;
		$table_name = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			level varchar(20) NOT NULL DEFAULT 'info',
			action varchar(50) NOT NULL DEFAULT 'general',
			message text NOT NULL,
			context longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY level (level),
			KEY action (action)
		) $charset_collate;";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}

	/**
	 * Ensure table exists
	 */
	public static function ensure_table_exists()
	{
		global $wpdb;
		$table_name = self::get_table_name();
		if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
			self::create_table();
		}
	}

	/**
	 * Add a log entry
	 *
	 * @param string $level Log level (info, success, warning, error)
	 * @param string $message Log message
	 * @param string $action Action category (e.g. import_gs_wp, export_wp_gs, create_product, etc.)
	 * @param array|mixed $context Additional contextual data
	 * @return int|false The log ID or false on failure
	 */
	public static function log($level, $message, $action = 'general', $context = [])
	{
		global $wpdb;

		// Ensure table exists
		self::ensure_table_exists();

		$table_name = self::get_table_name();

		$valid_levels = [self::LEVEL_INFO, self::LEVEL_SUCCESS, self::LEVEL_WARNING, self::LEVEL_ERROR];
		$level = strtolower(trim((string)$level));
		if (!in_array($level, $valid_levels, true)) {
			$level = self::LEVEL_INFO;
		}

		$action = sanitize_key((string)$action);
		if (empty($action)) {
			$action = 'general';
		}

		$context_str = null;
		if (!empty($context)) {
			if (is_string($context)) {
				$context_str = $context;
			} else {
				$context_str = wp_json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			}
		}

		$data = [
			'created_at' => current_time('mysql'),
			'level'      => $level,
			'action'     => $action,
			'message'    => (string)$message,
			'context'    => $context_str,
		];

		$inserted = $wpdb->insert(
			$table_name,
			$data,
			['%s', '%s', '%s', '%s', '%s']
		);

		// Mirror to WooCommerce logger if available
		if (function_exists('wc_get_logger')) {
			$wc_logger = wc_get_logger();
			$wc_context = ['source' => 'mjf-gsheet-importer'];
			$formatted_wc_msg = sprintf('[%s] %s', strtoupper($action), $message);
			if (!empty($context_str)) {
				$formatted_wc_msg .= ' | Context: ' . $context_str;
			}

			switch ($level) {
				case self::LEVEL_ERROR:
					$wc_logger->error($formatted_wc_msg, $wc_context);
					break;
				case self::LEVEL_WARNING:
					$wc_logger->warning($formatted_wc_msg, $wc_context);
					break;
				default:
					$wc_logger->info($formatted_wc_msg, $wc_context);
					break;
			}
		}

		// Keep table size under control (prune occasionally)
		if (wp_rand(1, 100) === 1) {
			self::prune_old_logs(5000);
		}

		return $inserted ? $wpdb->insert_id : false;
	}

	/**
	 * Log info message
	 */
	public static function info($message, $action = 'general', $context = [])
	{
		return self::log(self::LEVEL_INFO, $message, $action, $context);
	}

	/**
	 * Log success message
	 */
	public static function success($message, $action = 'general', $context = [])
	{
		return self::log(self::LEVEL_SUCCESS, $message, $action, $context);
	}

	/**
	 * Log warning message
	 */
	public static function warning($message, $action = 'general', $context = [])
	{
		return self::log(self::LEVEL_WARNING, $message, $action, $context);
	}

	/**
	 * Log error message
	 */
	public static function error($message, $action = 'general', $context = [])
	{
		return self::log(self::LEVEL_ERROR, $message, $action, $context);
	}

	/**
	 * Retrieve filtered and paginated logs
	 *
	 * @param array $args Filter arguments
	 * @return array
	 */
	public static function get_logs($args = [])
	{
		global $wpdb;
		self::ensure_table_exists();
		$table_name = self::get_table_name();

		$page     = isset($args['page']) ? max(1, intval($args['page'])) : 1;
		$per_page = isset($args['per_page']) ? max(10, min(200, intval($args['per_page']))) : 50;
		$level    = isset($args['level']) ? sanitize_text_field($args['level']) : '';
		$action   = isset($args['action']) ? sanitize_text_field($args['action']) : '';
		$search   = isset($args['search']) ? sanitize_text_field($args['search']) : '';

		$where_clauses = ['1=1'];
		$where_values  = [];

		if (!empty($level) && $level !== 'all') {
			$where_clauses[] = 'level = %s';
			$where_values[]  = $level;
		}

		if (!empty($action) && $action !== 'all') {
			$where_clauses[] = 'action = %s';
			$where_values[]  = $action;
		}

		if (!empty($search)) {
			$where_clauses[] = '(message LIKE %s OR context LIKE %s)';
			$like = '%' . $wpdb->esc_like($search) . '%';
			$where_values[] = $like;
			$where_values[] = $like;
		}

		$where_sql = implode(' AND ', $where_clauses);
		if (!empty($where_values)) {
			$where_sql = $wpdb->prepare($where_sql, $where_values);
		}

		// Total count with current filters
		$total_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE {$where_sql}");

		// Fetch stats by level (overall)
		$stats = [
			'total'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}"),
			'error'   => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE level = %s", self::LEVEL_ERROR)),
			'warning' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE level = %s", self::LEVEL_WARNING)),
			'success' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE level = %s", self::LEVEL_SUCCESS)),
			'info'    => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE level = %s", self::LEVEL_INFO)),
		];

		// Pagination
		$offset = ($page - 1) * $per_page;
		$total_pages = ceil($total_count / $per_page);

		$items = $wpdb->get_results(
			"SELECT * FROM {$table_name} WHERE {$where_sql} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}",
			ARRAY_A
		);

		return [
			'items'        => $items ?: [],
			'total'        => $total_count,
			'total_pages'  => max(1, $total_pages),
			'current_page' => $page,
			'per_page'     => $per_page,
			'stats'        => $stats,
		];
	}

	/**
	 * Clear all logs
	 */
	public static function clear_logs()
	{
		global $wpdb;
		self::ensure_table_exists();
		$table_name = self::get_table_name();
		$wpdb->query("TRUNCATE TABLE {$table_name}");

		$current_user = wp_get_current_user();
		$user_login = $current_user && $current_user->ID ? $current_user->user_login : 'system';

		self::info(
			sprintf(__('Logs cleared by %s.', 'mjf-gsheet-importer'), $user_login),
			'system'
		);

		return true;
	}

	/**
	 * Prune old logs keeping only the most recent N entries
	 */
	public static function prune_old_logs($keep = 5000)
	{
		global $wpdb;
		$table_name = self::get_table_name();
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
		if ($total > $keep) {
			$min_id = $wpdb->get_var("SELECT id FROM {$table_name} ORDER BY id DESC LIMIT 1 OFFSET {$keep}");
			if ($min_id) {
				$wpdb->query($wpdb->prepare("DELETE FROM {$table_name} WHERE id <= %d", $min_id));
			}
		}
	}

	/**
	 * Get human readable action label
	 */
	public static function get_action_label($action)
	{
		$labels = [
			'import_gs_wp'       => __('Import GS → WP', 'mjf-gsheet-importer'),
			'export_wp_gs'       => __('Export WP → GS', 'mjf-gsheet-importer'),
			'create_product'     => __('Create Product', 'mjf-gsheet-importer'),
			'create_translation' => __('WPML Translation', 'mjf-gsheet-importer'),
			'sync_field'         => __('Sync Field', 'mjf-gsheet-importer'),
			'sync_batch'         => __('Batch Sync', 'mjf-gsheet-importer'),
			'order_stock'        => __('Order Stock', 'mjf-gsheet-importer'),
			'api'                => __('Google Sheets API', 'mjf-gsheet-importer'),
			'settings'           => __('Settings', 'mjf-gsheet-importer'),
			'system'             => __('System', 'mjf-gsheet-importer'),
			'general'            => __('General', 'mjf-gsheet-importer'),
		];

		return $labels[$action] ?? ucfirst(str_replace('_', ' ', $action));
	}

	/**
	 * Get CSS style and badge classes for a level
	 */
	public static function get_level_badge($level)
	{
		switch ($level) {
			case self::LEVEL_ERROR:
				return '<span class="mjf-log-badge mjf-log-error">' . esc_html__('ERROR', 'mjf-gsheet-importer') . '</span>';
			case self::LEVEL_WARNING:
				return '<span class="mjf-log-badge mjf-log-warning">' . esc_html__('WARNING', 'mjf-gsheet-importer') . '</span>';
			case self::LEVEL_SUCCESS:
				return '<span class="mjf-log-badge mjf-log-success">' . esc_html__('SUCCESS', 'mjf-gsheet-importer') . '</span>';
			default:
				return '<span class="mjf-log-badge mjf-log-info">' . esc_html__('INFO', 'mjf-gsheet-importer') . '</span>';
		}
	}
}
