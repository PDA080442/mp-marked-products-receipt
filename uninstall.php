<?php
/**
 * Fired when the plugin is deleted (not on deactivate).
 *
 * §22: удаляются только опции `mp_mpr_*`. Мета заказов не трогаем.
 * Логи в uploads удаляются только если была включена опция `mp_mpr_delete_logs_on_uninstall`.
 *
 * @package MP_Marked_Products_Receipt
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Имена опций плагина (синхронно с MP_Marked_Products_Receipt_Settings).
 *
 * @return list<string>
 */
function mp_mpr_uninstall_option_names(): array {
	return [
		'mp_mpr_common_enabled',
		'mp_mpr_debug',
		'mp_mpr_delete_logs_on_uninstall',
		'mp_mpr_yk_enabled',
		'mp_mpr_yk_sandbox',
		'mp_mpr_yk_shop_id',
		'mp_mpr_yk_secret_key',
		'mp_mpr_yk_default_payment_mode',
		'mp_mpr_yk_default_payment_subject',
		'mp_mpr_yk_rules',
		'mp_mpr_yk_skip_without_cis',
		'mp_mpr_rb_enabled',
		'mp_mpr_rb_sandbox',
		'mp_mpr_rb_login',
		'mp_mpr_rb_password1',
		'mp_mpr_rb_default_payment_mode',
		'mp_mpr_rb_default_payment_subject',
		'mp_mpr_rb_rules',
		'mp_mpr_rb_skip_without_cis',
		'mp_mpr_marking_source',
		'mp_mpr_marking_meta_key',
		'mp_mpr_marking_taxonomy',
		'mp_mpr_marking_term_ids',
		'mp_mpr_marking_category_ids',
		'mp_mpr_require_cis_in_order_item_meta',
		'mp_mpr_order_item_cis_meta_key',
	];
}

/**
 * Удаляет `wp-content/uploads/mp-marked-products-receipt/logs/` (файлы и пустые каталоги).
 *
 * @return void
 */
function mp_mpr_uninstall_delete_log_files(): void {
	if (!function_exists('wp_upload_dir')) {
		return;
	}

	$upload = wp_upload_dir();
	if (!empty($upload['error'])) {
		return;
	}

	$logs = trailingslashit($upload['basedir']) . 'mp-marked-products-receipt/logs';
	if (!is_dir($logs)) {
		return;
	}

	$files = glob($logs . '/*');
	if (is_array($files)) {
		foreach ($files as $file) {
			if (!is_string($file) || !is_file($file)) {
				continue;
			}
			if (function_exists('wp_delete_file')) {
				wp_delete_file($file);
			} else {
				@unlink($file);
			}
		}
	}

	if (is_dir($logs)) {
		@rmdir($logs);
	}

	$parent = dirname($logs);
	if (is_dir($parent)) {
		$left = glob($parent . '/*');
		if (is_array($left) && $left === []) {
			@rmdir($parent);
		}
	}
}

/**
 * @return void
 */
function mp_mpr_uninstall_single_site(): void {
	$raw = get_option('mp_mpr_delete_logs_on_uninstall', false);
	$delete_logs = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'yes');

	foreach (mp_mpr_uninstall_option_names() as $name) {
		delete_option($name);
	}

	if ($delete_logs) {
		mp_mpr_uninstall_delete_log_files();
	}
}

global $wpdb;

if (is_multisite()) {
	$blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
	if (is_array($blog_ids)) {
		foreach ($blog_ids as $blog_id) {
			switch_to_blog((int) $blog_id);
			mp_mpr_uninstall_single_site();
			restore_current_blog();
		}
	}
} else {
	mp_mpr_uninstall_single_site();
}
