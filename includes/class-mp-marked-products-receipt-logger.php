<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * File logger: `wp-content/uploads/mp-marked-products-receipt/logs/mpr-YYYY-MM.log`.
 */
final class MP_Marked_Products_Receipt_Logger {
	private const DIR_REL = 'mp-marked-products-receipt' . DIRECTORY_SEPARATOR . 'logs';
	private const FILE_PREFIX = 'mpr-';

	private const LEVELS = ['INFO', 'DEBUG', 'ERROR'];

	/**
	 * @param string $level INFO|DEBUG|ERROR
	 * @param int|string $order_id
	 * @param string $action
	 * @param array<string,mixed> $context
	 * @return void
	 */
	public static function log(string $level, $order_id, string $action, array $context = []): void {
		$level = strtoupper(trim($level));
		if (!in_array($level, self::LEVELS, true)) {
			$level = 'INFO';
		}

		if ($level === 'DEBUG' && !self::is_debug_enabled()) {
			return;
		}

		self::ensure_dir_exists();

		$line = self::format_line($level, (string) $order_id, $action, self::sanitize_context($context));
		@file_put_contents(self::current_log_path(), $line, FILE_APPEND);
	}

	/**
	 * @return bool
	 */
	public static function is_debug_enabled(): bool {
		if (defined('MP_MPR_FORCE_LOG_DEBUG') && MP_MPR_FORCE_LOG_DEBUG) {
			return true;
		}

		return MP_Marked_Products_Receipt_Settings::is_debug();
	}

	/**
	 * Absolute path to logs directory (for admin UI).
	 *
	 * @return string
	 */
	public static function get_log_dir(): string {
		return self::log_dir();
	}

	/**
	 * Last N lines of the current month log file (for admin UI).
	 *
	 * @param int $lines
	 * @return string
	 */
	public static function read_log_tail(int $lines = 40): string {
		$path = self::current_log_path();
		if (!is_file($path) || !is_readable($path)) {
			return __('Лог-файл не найден или недоступен.', 'mp-marked-products-receipt');
		}
		$content = (string) @file_get_contents($path);
		if ($content === '') {
			return __('Лог пуст.', 'mp-marked-products-receipt');
		}
		$all_lines = preg_split("/\r\n|\n|\r/", trim($content));
		if (!is_array($all_lines) || empty($all_lines)) {
			return __('Лог пуст.', 'mp-marked-products-receipt');
		}

		return implode("\n", array_slice($all_lines, -1 * max(1, $lines)));
	}

	/**
	 * @param string $level
	 * @param string $order_id
	 * @param string $action
	 * @param array<string,mixed> $context
	 * @return string
	 */
	private static function format_line(string $level, string $order_id, string $action, array $context): string {
		$ts = date('Y-m-d H:i:s');
		$ctx_json = '';
		if (!empty($context)) {
			$ctx_json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			if (!is_string($ctx_json)) {
				$ctx_json = '';
			}
		}

		return sprintf(
			"[%s] %s order_id=%s action=%s%s\n",
			$ts,
			$level,
			$order_id,
			$action,
			$ctx_json !== '' ? ' context=' . $ctx_json : ''
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private static function sanitize_context(array $context): array {
		$sanitized = [];
		foreach ($context as $key => $value) {
			$k = strtolower((string) $key);

			if (self::is_sensitive_key($k)) {
				$sanitized[$key] = self::mask_secret_scalar($value);
				continue;
			}

			if (self::is_marking_related_key($k) && is_string($value)) {
				$sanitized[$key] = self::shorten_marking_string($value);
				continue;
			}

			if (is_array($value)) {
				$sanitized[$key] = self::sanitize_context($value);
			} elseif (is_string($value) && strlen($value) > 96 && (strpos($k, 'body') !== false || strpos($k, 'response') !== false)) {
				$sanitized[$key] = self::shorten_marking_string($value);
			} else {
				$sanitized[$key] = $value;
			}
		}

		return $sanitized;
	}

	private static function is_sensitive_key(string $key): bool {
		$needles = ['password', 'pass1', 'pass2', 'secret', 'token', 'signature', 'authorization'];
		foreach ($needles as $needle) {
			if (strpos($key, $needle) !== false) {
				return true;
			}
		}

		return false;
	}

	private static function is_marking_related_key(string $key): bool {
		$needles = ['cis', 'mark_code', 'marking', 'nomenclature', 'kiz', 'datamatrix'];
		foreach ($needles as $needle) {
			if (strpos($key, $needle) !== false) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function mask_secret_scalar($value): string {
		$str = is_scalar($value) ? (string) $value : (string) wp_json_encode($value);
		if ($str === '') {
			return '***';
		}
		$len = strlen($str);
		if ($len <= 4) {
			return str_repeat('*', min(8, $len));
		}

		return substr($str, 0, 2) . str_repeat('*', max(1, $len - 4)) . substr($str, -2);
	}

	/**
	 * @param string $s
	 * @return string
	 */
	private static function shorten_marking_string(string $s): string {
		$s = trim($s);
		$len = strlen($s);
		if ($len <= 32) {
			return $s;
		}

		return 'len=' . $len . ' tail=' . substr($s, -12);
	}

	private static function ensure_dir_exists(): void {
		$dir = self::log_dir();
		if (is_dir($dir)) {
			return;
		}
		if (function_exists('wp_mkdir_p')) {
			wp_mkdir_p($dir);
		} else {
			@mkdir($dir, 0755, true);
		}
	}

	private static function log_dir(): string {
		$uploads = wp_upload_dir();
		$base = is_array($uploads) && !empty($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';

		return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . self::DIR_REL;
	}

	private static function current_log_path(): string {
		return self::log_dir() . DIRECTORY_SEPARATOR . self::FILE_PREFIX . date('Y-m') . '.log';
	}
}
