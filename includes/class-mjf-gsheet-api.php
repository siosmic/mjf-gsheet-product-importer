<?php

if (! defined('ABSPATH')) {
	exit;
}

class MJF_GSheet_API
{
	private $option_name = 'mjf_gsheet_importer_settings';

	public function log($message, $level = 'info', $action = 'api', $context = [])
	{
		if (class_exists('MJF_GSheet_Logger')) {
			MJF_GSheet_Logger::log($level, $message, $action, $context);
		}

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

	public function new_google_model($class, $data = [])
	{
		return new $class($data);
	}

	public function is_local_environment()
	{
		if (function_exists('wp_get_environment_type') && in_array(wp_get_environment_type(), ['local', 'development'], true)) {
			return true;
		}

		$host = parse_url(site_url(), PHP_URL_HOST);
		if ($host) {
			$host = strtolower($host);
			if ($host === 'localhost' || $host === '127.0.0.1' || preg_match('/\.(local|dev|test)$/i', $host)) {
				return true;
			}
		}

		return false;
	}

	public function fetch_sheet_data()
	{
		$settings = get_option($this->option_name, []);
		$sheet_id = $settings['sheet_id'] ?? '';
		$sheet_name = $settings['sheet_name'] ?? 'Sheet1';
		$service_account_json = $settings['google_service_account'] ?? '';

		if (empty($sheet_id)) {
			$this->log(__('Please specify a Google Sheet ID in the settings.', 'mjf-gsheet-importer'), 'error', 'api');
			return new WP_Error('missing_id', 'Please provide a Google Sheet ID.');
		}

		$this->log(sprintf(__('Fetching Google Sheet data (ID: %s, Sheet: %s)...', 'mjf-gsheet-importer'), $sheet_id, $sheet_name), 'info', 'api');

		// Try API if credentials exist
		if (!empty($service_account_json) && class_exists('Google\Client')) {
			try {
				$client = new \Google\Client();
				$client->setAuthConfig(json_decode($service_account_json, true));
				$client->addScope(\Google\Service\Sheets::SPREADSHEETS_READONLY);
				$service = new \Google\Service\Sheets($client);

				// We fetch the entire sheet to avoid missing columns beyond Z
				$spreadsheet = $service->spreadsheets->get($sheet_id, [
					'ranges'          => $sheet_name,
					'includeGridData' => true,
					'fields'          => 'sheets(data(rowData(values(formattedValue,textFormatRuns))))'
				]);

				$header_row_index = ((int) ($settings['header_row'] ?? 1)) - 1;
				$allowed_html_columns = array_map('strtolower', array_map('trim', ['Description FR', 'Description EN', 'Contenu additionel FR', 'Contenu additionel EN']));
				$html_col_indices = [];

				$values = [];
				$row_count = 0;
				foreach ($spreadsheet->getSheets() as $sheet) {
					$props = $sheet->getProperties();
					if ($props && $props->getTitle() !== $sheet_name) continue;

					foreach ($sheet->getData() as $gridData) {
						foreach ($gridData->getRowData() as $rowData) {
							$row = [];
							$cell_values = $rowData->getValues();
							if (empty($cell_values)) {
								$values[] = [];
								$row_count++;
								continue;
							}

							// Identify column indices for HTML columns based on the header row
							if ($row_count === $header_row_index) {
								foreach ($cell_values as $col_idx => $cellData) {
									$header_val = strtolower(trim($cellData ? $cellData->getFormattedValue() : ''));
									if (in_array($header_val, $allowed_html_columns)) {
										$html_col_indices[] = $col_idx;
									}
								}
							}

							foreach ($cell_values as $col_idx => $cellData) {
								// Apply rich text only to allowed columns after the header row
								if ($row_count > $header_row_index && in_array($col_idx, $html_col_indices)) {
									$row[] = $this->convert_cell_to_html($cellData);
								} else {
									$row[] = $cellData ? $cellData->getFormattedValue() : '';
								}
							}
							$values[] = $row;
							$row_count++;
						}
					}
				}

				if (!empty($values)) {
					$this->log(sprintf(__('Google Sheet data fetched successfully via Google API (%d rows).', 'mjf-gsheet-importer'), count($values)), 'success', 'api');
					return $values;
				}
			} catch (\Exception $e) {
				$this->log('Google API Error (Fetch Rich Text): ' . $e->getMessage(), 'error', 'api', ['exception' => $e->getMessage()]);
			}
		}

		// Fallback CSV method
		$this->log(__('Attempting fetch via public Google Sheets CSV export...', 'mjf-gsheet-importer'), 'info', 'api');
		$csv_url = sprintf(
			'https://docs.google.com/spreadsheets/d/%s/export?format=csv&sheet=%s',
			urlencode($sheet_id),
			urlencode($sheet_name)
		);

		$response = wp_remote_get($csv_url, ['timeout' => 30]);
		if (is_wp_error($response)) {
			$this->log('HTTP CSV Request Error: ' . $response->get_error_message(), 'error', 'api');
			return $response;
		}

		$body = trim(wp_remote_retrieve_body($response));
		if (strpos($body, '<!DOCTYPE html') !== false || strpos($body, '<html') !== false) {
			$err_msg = 'Error: Google Sheets returned an HTML page. Ensure it is publicly viewable or provide API credentials.';
			$this->log(__('Error: Google Sheets returned an HTML page instead of CSV. Ensure the sheet is public or configure the service account.', 'mjf-gsheet-importer'), 'error', 'api');
			return new WP_Error('html_returned', $err_msg);
		}

		$temp_file = fopen('php://temp', 'r+');
		fwrite($temp_file, $body);
		rewind($temp_file);
		$data = [];
		while (($row = fgetcsv($temp_file)) !== false) {
			$data[] = $row;
		}
		fclose($temp_file);
		$this->log(sprintf(__('Google Sheet data fetched successfully via CSV export (%d rows).', 'mjf-gsheet-importer'), count($data)), 'success', 'api');
		return $data;
	}

	public function convert_cell_to_html($cellData)
	{
		if (!$cellData) return '';

		$text = $cellData->getFormattedValue();
		if (empty($text)) return '';

		$runs = $cellData->getTextFormatRuns();
		// If no special formatting runs, return the plain text
		if (empty($runs)) return $text;

		$html = '';
		$utf8_text = $text;

		// Google uses UTF-16 code units for indices. 
		// We convert the text to UTF-16BE to easily slice it by bytes (2 bytes per unit).
		$utf16_text = mb_convert_encoding($utf8_text, 'UTF-16BE', 'UTF-8');
		$utf16_len = strlen($utf16_text) / 2;

		for ($i = 0; $i < count($runs); $i++) {
			$run = $runs[$i];
			$startIndex = $run->getStartIndex();
			$nextStartIndex = isset($runs[$i + 1]) ? $runs[$i + 1]->getStartIndex() : $utf16_len;

			// Slice the UTF-16 string
			$segment_utf16 = substr($utf16_text, $startIndex * 2, ($nextStartIndex - $startIndex) * 2);
			$segment = mb_convert_encoding($segment_utf16, 'UTF-8', 'UTF-16BE');

			$format = $run->getFormat();
			$tags = [];
			if ($format && $format->getBold()) $tags[] = 'strong';
			if ($format && $format->getItalic()) $tags[] = 'em';
			if ($format && $format->getUnderline()) $tags[] = 'u';
			if ($format && $format->getStrikethrough()) $tags[] = 'strike';

			$link = $format ? $format->getLink() : null;
			$link_url = $link ? $link->getUri() : null;

			$opening = '';
			$closing = '';
			foreach ($tags as $tag) {
				$opening .= "<$tag>";
				$closing = "</$tag>" . $closing;
			}
			if ($link_url) {
				$opening = '<a href="' . esc_url($link_url) . '">' . $opening;
				$closing = $closing . '</a>';
			}

			// Concatenate formatted segment
			$html .= $opening . $segment . $closing;
		}

		return $html;
	}

	public function write_to_google_sheet($row_index, $column_name, $value)
	{
		if ($this->is_local_environment()) {
			$this->log(
				sprintf(__('Write skipped in local environment: row %d, column "%s", value "%s"', 'mjf-gsheet-importer'), $row_index, $column_name, (string)$value),
				'warning',
				'export_wp_gs',
				['row' => $row_index, 'column' => $column_name, 'value' => $value]
			);
			return true;
		}

		$settings = get_option($this->option_name, []);
		$sheet_id = $settings['sheet_id'] ?? '';
		$sheet_name = $settings['sheet_name'] ?? 'Sheet1';
		$service_account_json = $settings['google_service_account'] ?? '';

		if (empty($service_account_json) || !class_exists('Google\Client')) {
			$this->log(__('Cannot write to Google Sheet: Google API Client or service account JSON missing.', 'mjf-gsheet-importer'), 'error', 'export_wp_gs');
			return new WP_Error('missing_api', 'Google API Client or credentials missing.');
		}

		try {
			$client = new \Google\Client();
			$client->setAuthConfig(json_decode($service_account_json, true));
			$client->addScope(\Google\Service\Sheets::SPREADSHEETS);
			$service = new \Google\Service\Sheets($client);

			$header_row = (int) ($settings['header_row'] ?? 1);
			$header_range = $sheet_name . '!' . $header_row . ':' . $header_row;
			$headers_response = $service->spreadsheets_values->get($sheet_id, $header_range);
			$headers = $headers_response->getValues()[0] ?? [];

			// Case-insensitive, whitespace-tolerant column search
			$col_index = false;
			$column_name_norm = strtolower(trim($column_name));
			foreach ($headers as $idx => $h) {
				if (strtolower(trim($h)) === $column_name_norm) {
					$col_index = $idx;
					break;
				}
			}
			if ($col_index === false) {
				$err_msg = "Column '$column_name' not found. Available: " . implode(', ', $headers);
				$this->log(sprintf(__('Cannot write to Google Sheet: column "%s" not found.', 'mjf-gsheet-importer'), $column_name), 'error', 'export_wp_gs');
				return new WP_Error('col_not_found', $err_msg);
			}

			// Simple index to letter converter (handles A to ZZ)
			$col_letter = '';
			$n = $col_index;
			while ($n >= 0) {
				$col_letter = chr($n % 26 + 65) . $col_letter;
				$n = floor($n / 26) - 1;
			}

			$spreadsheet = $service->spreadsheets->get($sheet_id);
			$target_sheet_id = null;
			foreach ($spreadsheet->getSheets() as $sheet) {
				if ($sheet->getProperties()->getTitle() === $sheet_name) {
					$target_sheet_id = $sheet->getProperties()->getSheetId();
					break;
				}
			}

			if ($target_sheet_id === null) {
				$this->log(sprintf(__('Cannot write to Google Sheet: sheet tab "%s" not found.', 'mjf-gsheet-importer'), $sheet_name), 'error', 'export_wp_gs');
				return new WP_Error('sheet_not_found', "Sheet '$sheet_name' not found.");
			}

			// Check if we should push as Rich Text
			$html_cols = ['Description FR', 'Description EN', 'Contenu additionel FR', 'Contenu additionel EN'];
			$is_html_col = false;
			foreach ($html_cols as $hc) {
				if (strtolower(trim($hc)) === $column_name_norm) {
					$is_html_col = true;
					break;
				}
			}

			if ($is_html_col && strpos($value, '<') !== false) {
				// Rich Text Push via batchUpdate
				$rich_data = $this->html_to_rich_text_data($value);

				$cell_data = $this->new_google_model(\Google\Service\Sheets\CellData::class, [
					'userEnteredValue' => ['stringValue' => $rich_data['text']],
					'textFormatRuns' => $rich_data['runs']
				]);

				$request = $this->new_google_model(\Google\Service\Sheets\Request::class, [
					'updateCells' => [
						'rows' => [
							['values' => [$cell_data]]
						],
						'fields' => 'userEnteredValue,textFormatRuns',
						'start' => [
							'sheetId' => $target_sheet_id,
							'rowIndex' => $row_index - 1,
							'columnIndex' => $col_index
						]
					]
				]);

				$batch_request = $this->new_google_model(\Google\Service\Sheets\BatchUpdateSpreadsheetRequest::class, [
					'requests' => [$request]
				]);

				$service->spreadsheets->batchUpdate($sheet_id, $batch_request);
			} else {
				// Standard Simple Push
				$cell_range = $sheet_name . '!' . $col_letter . $row_index;
				$body = $this->new_google_model(\Google\Service\Sheets\ValueRange::class, ['values' => [[$value]]]);
				$params = ['valueInputOption' => 'USER_ENTERED'];
				$service->spreadsheets_values->update($sheet_id, $cell_range, $body, $params);
			}

			$this->log(
				sprintf(__('Google Sheets export successful: row %d, column "%s", value: "%s"', 'mjf-gsheet-importer'), $row_index, $column_name, mb_strimwidth((string)$value, 0, 80, '...')),
				'success',
				'export_wp_gs',
				['row' => $row_index, 'column' => $column_name, 'value' => $value]
			);

			return true;
		} catch (\Exception $e) {
			$this->log('Google API Error (Write): ' . $e->getMessage(), 'error', 'export_wp_gs', ['row' => $row_index, 'column' => $column_name, 'error' => $e->getMessage()]);
			return new WP_Error('api_error', $e->getMessage());
		}
	}

	public function html_to_rich_text_data($html)
	{
		if (empty($html)) {
			return ['text' => '', 'runs' => []];
		}

		// Normalize line breaks before parsing
		$html = str_replace(['<br>', '<br/>', '<br />'], "\n", $html);

		$doc = new \DOMDocument();
		// Use UTF-8 hack for DOMDocument
		@$doc->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>');
		$divs = $doc->getElementsByTagName('div');
		if ($divs->length === 0) return ['text' => $html, 'runs' => []];
		$root = $divs->item(0);

		$result_text = '';
		$runs_data = [];

		$current_format = [
			'bold' => false,
			'italic' => false,
			'underline' => false,
			'strikethrough' => false,
			'link' => null
		];

		// Always ensure a run at the very beginning
		$runs_data[] = [
			'startIndex' => 0,
			'format' => $current_format
		];

		$this->traverse_dom_to_rich_text($root, $result_text, $runs_data, $current_format);

		// Post-process runs: Only keep runs where format changes and within string bounds
		$final_text = rtrim($result_text);
		$final_text_len = strlen(mb_convert_encoding($final_text, 'UTF-16BE', 'UTF-8')) / 2;

		$final_runs = [];
		$last_format_json = null;

		foreach ($runs_data as $run) {
			if ($run['startIndex'] >= $final_text_len) continue;

			$fmt = $run['format'];
			$fmt_json = json_encode($fmt);
			if ($fmt_json !== $last_format_json) {
				$final_runs[] = $this->new_google_model(\Google\Service\Sheets\TextFormatRun::class, [
					'startIndex' => (int)$run['startIndex'],
					'format' => $this->new_google_model(\Google\Service\Sheets\TextFormat::class, [
						'bold' => $fmt['bold'],
						'italic' => $fmt['italic'],
						'underline' => $fmt['underline'],
						'strikethrough' => $fmt['strikethrough'],
						'link' => $fmt['link'] ? ['uri' => $fmt['link']] : null
					])
				]);
				$last_format_json = $fmt_json;
			}
		}

		return [
			'text' => $final_text,
			'runs' => $final_runs
		];
	}

	private function traverse_dom_to_rich_text($node, &$result_text, &$runs_data, $format)
	{
		if ($node->nodeType === XML_TEXT_NODE) {
			$text = $node->nodeValue;
			if ($text === '') return;

			// Record format starting at this point
			$utf16_index = strlen(mb_convert_encoding($result_text, 'UTF-16BE', 'UTF-8')) / 2;
			$runs_data[] = [
				'startIndex' => (int)$utf16_index,
				'format' => $format
			];

			$result_text .= $text;
			return;
		}

		if ($node->nodeType === XML_ELEMENT_NODE) {
			$tag = strtolower($node->tagName);
			$new_format = $format;

			switch ($tag) {
				case 'b':
				case 'strong':
					$new_format['bold'] = true;
					break;
				case 'i':
				case 'em':
					$new_format['italic'] = true;
					break;
				case 'u':
					$new_format['underline'] = true;
					break;
				case 'strike':
				case 's':
				case 'del':
					$new_format['strikethrough'] = true;
					break;
				case 'a':
					$new_format['link'] = $node->getAttribute('href');
					break;
				case 'ul':
					$result_text .= "<ul>";
					break;
				case 'li':
					$result_text .= "<li>";
					break;
				case 'h1':
				case 'h2':
				case 'h3':
				case 'h4':
				case 'h5':
				case 'h6':
					$result_text .= "<" . $tag . ">";
					break;
			}

			foreach ($node->childNodes as $child) {
				$this->traverse_dom_to_rich_text($child, $result_text, $runs_data, $new_format);
			}

			if ($tag === 'ul') $result_text .= "</ul>";
			if ($tag === 'li') $result_text .= "</li>";
			if (in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {
				$result_text .= "</" . $tag . ">";
			}

			if ($tag === 'p' || $tag === 'div' || in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'])) {
				$result_text .= "\n";
			}

			// After closing a tag, we should ideally record the return to the parent format
			$utf16_index = strlen(mb_convert_encoding($result_text, 'UTF-16BE', 'UTF-8')) / 2;
			$runs_data[] = [
				'startIndex' => (int)$utf16_index,
				'format' => $format
			];
		}
	}
}
