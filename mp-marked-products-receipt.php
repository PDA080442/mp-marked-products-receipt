<?php
/**
 * Plugin Name: MP Marked Products Receipt (YooKassa + Robokassa)
 * Description: Separate fiscal receipts for marked goods via YooKassa and/or Robokassa.
 * Version: 0.1.0
 * Author: Popravkin Danil
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: mp-marked-products-receipt
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Bootstrap: structure + fixed-order dependency loading (no Composer autoload).
 */
final class MP_Marked_Products_Receipt_Plugin {
	public const VERSION = '0.1.0';

	public static function init(): void {
		self::load_dependencies();
		self::register_hooks();

		if (is_admin() && class_exists('MP_Marked_Products_Receipt_Admin')) {
			MP_Marked_Products_Receipt_Admin::init();
		}
	}

	private static function register_hooks(): void {
		if (class_exists('WooCommerce')) {
			add_action(
				'woocommerce_order_status_completed',
				[MP_Marked_Products_Receipt_Orchestrator::class, 'on_order_completed'],
				20,
				1
			);
		}
	}

	/**
	 * Load includes in dependency order (see development plan §1).
	 */
	private static function load_dependencies(): void {
		$base = __DIR__;
		$inc = $base . '/includes/';

		require_once $inc . 'class-mp-marked-products-receipt-settings.php';
		require_once $inc . 'class-mp-marked-products-receipt-logger.php';
		require_once $inc . 'class-mp-marked-products-receipt-product-marker.php';
		require_once $inc . 'class-mp-marked-products-receipt-order-links-yk.php';
		require_once $inc . 'class-mp-marked-products-receipt-order-links-rb.php';
		require_once $inc . 'class-mp-marked-products-receipt-receipt-builder-yk.php';
		require_once $inc . 'class-mp-marked-products-receipt-receipt-builder-rb.php';
		require_once $inc . 'class-mp-marked-products-receipt-api-client-yk.php';
		require_once $inc . 'class-mp-marked-products-receipt-api-client-rb.php';
		require_once $inc . 'class-mp-marked-products-receipt-orchestrator.php';

		if (is_admin()) {
			require_once $base . '/admin/class-mp-marked-products-receipt-admin.php';
		}
	}
}

add_action('plugins_loaded', [MP_Marked_Products_Receipt_Plugin::class, 'init']);
