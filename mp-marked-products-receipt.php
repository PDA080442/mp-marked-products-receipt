<?php
/**
 * Plugin Name: MP Marked Products Receipt (YooKassa + Robokassa)
 * Description: Separate fiscal receipts for marked goods via YooKassa and/or Robokassa.
 * Version: 0.1.0
 * Author: Popravkin Danil
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: mp-marked-products-receipt
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Translations (§19): load before other plugin code uses __().
 */
function mp_marked_products_receipt_load_textdomain(): void {
	load_plugin_textdomain(
		'mp-marked-products-receipt',
		false,
		dirname(plugin_basename(__FILE__)) . '/languages'
	);
}

add_action('plugins_loaded', 'mp_marked_products_receipt_load_textdomain', 5);

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
		if (!class_exists('WooCommerce')) {
			return;
		}

		add_action(
			'woocommerce_order_status_completed',
			[MP_Marked_Products_Receipt_Orchestrator::class, 'on_order_completed'],
			20,
			1
		);

		add_filter('woocommerce_order_actions', [self::class, 'register_order_actions'], 20, 1);
		add_action('woocommerce_order_action_mp_mpr_resend_yk', [self::class, 'on_order_action_resend_yk'], 10, 1);
		add_action('woocommerce_order_action_mp_mpr_resend_rb', [self::class, 'on_order_action_resend_rb'], 10, 1);
	}

	/**
	 * @param array<string,string> $actions
	 * @return array<string,string>
	 */
	public static function register_order_actions($actions): array {
		if (!is_array($actions)) {
			$actions = [];
		}

		$actions['mp_mpr_resend_yk'] = __('Отправить отдельный чек маркированных (ЮKassa)', 'mp-marked-products-receipt');
		$actions['mp_mpr_resend_rb'] = __('Отправить отдельный чек маркированных (Robokassa)', 'mp-marked-products-receipt');

		return $actions;
	}

	/**
	 * @param WC_Order $order
	 * @return void
	 */
	public static function on_order_action_resend_yk($order): void {
		if (!$order instanceof WC_Order) {
			return;
		}

		MP_Marked_Products_Receipt_Orchestrator::handle_order($order, 'manual_yk');
	}

	/**
	 * @param WC_Order $order
	 * @return void
	 */
	public static function on_order_action_resend_rb($order): void {
		if (!$order instanceof WC_Order) {
			return;
		}

		MP_Marked_Products_Receipt_Orchestrator::handle_order($order, 'manual_rb');
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

add_action('plugins_loaded', [MP_Marked_Products_Receipt_Plugin::class, 'init'], 10);
