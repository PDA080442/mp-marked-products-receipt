<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Builds RoboFiscal / Robokassa receipt items for **marked** line items only.
 *
 * Base shape matches `MP_Robokassa_Receipt2_ReceiptBuilder` (name, quantity, cost, sum, payment_method, payment_object, tax).
 * Marking: per project notes, marking / КИЗ is passed as item `nomenclature_code` (see `Robokassa/kkm-fiscal-receipts-by-product-type.md`).
 * If your RoboFiscal schema uses another key, adjust via `mp_mpr_rb_items`.
 */
final class MP_Marked_Products_Receipt_ReceiptBuilder_RB {
	/**
	 * @param WC_Order $order
	 * @param float $settlement_amount
	 * @param array<int,mixed> $only_items Item IDs (int) and/or WC_Order_Item_Product. Empty = all marked line items.
	 * @return array{
	 *   items:array<int,array<string,mixed>>,
	 *   settlements:array<int,array<string,mixed>>,
	 *   total_items_amount:float,
	 *   warnings:array<int,string>
	 * }
	 */
	public static function build(WC_Order $order, float $settlement_amount, array $only_items = []): array {
		$items = [];
		$warnings = [];
		$total_items_amount = 0.0;

		$default_mode = MP_Marked_Products_Receipt_Settings::get_rb_default_payment_mode();
		$default_subject = MP_Marked_Products_Receipt_Settings::get_rb_default_payment_subject();
		$rules = MP_Marked_Products_Receipt_Settings::get_rb_rules();

		$candidates = self::resolve_line_items($order, $only_items);

		foreach ($candidates as $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				continue;
			}

			if (!MP_Marked_Products_Receipt_ProductMarker::line_item_is_marked($item)) {
				continue;
			}

			$product = $item->get_product();
			if (!$product) {
				$warnings[] = sprintf(
					/* translators: %d: WooCommerce order line item ID */
					__('Позиция заказа #%d: товар не найден.', 'mp-marked-products-receipt'),
					(int) $item->get_id()
				);
				continue;
			}

			if (self::is_gift_card_product($product)) {
				continue;
			}

			$qty = (float) $item->get_quantity();
			if ($qty <= 0) {
				continue;
			}

			$line_total = (float) $item->get_total();
			if ($line_total <= 0) {
				continue;
			}

			$unit_cost = (float) wc_format_decimal($line_total / $qty, wc_get_price_decimals());
			$qty = (float) wc_format_decimal($qty, 3);
			$sum = (float) wc_format_decimal($unit_cost * $qty, wc_get_price_decimals());
			$total_items_amount += $sum;

			$rule = self::resolve_rule_for_product($product, $rules);
			$payment_mode = $rule['payment_mode'] ?? $default_mode;
			$payment_subject = $rule['payment_subject'] ?? $default_subject;

			$row = [
				'name' => self::sanitize_name($item->get_name()),
				'quantity' => $qty,
				'cost' => self::money($unit_cost),
				'sum' => self::money($sum),
				'payment_method' => $payment_mode,
				'payment_object' => $payment_subject,
				'tax' => self::resolve_tax_code($item),
			];

			$payload = MP_Marked_Products_Receipt_ProductMarker::get_marking_payload_for_line_item($item);
			$cis = '';
			if (is_array($payload) && isset($payload['cis'])) {
				$cis = trim((string) $payload['cis']);
			}

			if ($cis === '') {
				$warnings[] = sprintf(
					/* translators: %d: order line item ID */
					__('Нет КИЗ/nomenclature_code для позиции заказа #%d.', 'mp-marked-products-receipt'),
					(int) $item->get_id()
				);
				if (MP_Marked_Products_Receipt_Settings::is_rb_skip_without_cis()) {
					$total_items_amount -= $sum;
					continue;
				}
			} else {
				$row['nomenclature_code'] = $cis;
			}

			$items[] = $row;
		}

		if (empty($items)) {
			$warnings[] = __('В чек Robokassa не попала ни одна маркированная позиция.', 'mp-marked-products-receipt');
		}

		$total_items_amount = (float) wc_format_decimal($total_items_amount, wc_get_price_decimals());
		$settlement_amount = (float) wc_format_decimal(max(0.0, $settlement_amount), wc_get_price_decimals());

		if ($total_items_amount > 0 && $settlement_amount > $total_items_amount) {
			$warnings[] = __('Сумма зачёта больше суммы позиций; ограничено суммой позиций.', 'mp-marked-products-receipt');
			$settlement_amount = $total_items_amount;
		}

		$settlements = [
			[
				'type' => 'prepayment',
				'amount' => self::money($settlement_amount),
			],
		];

		$items = apply_filters('mp_mpr_rb_items', $items, $order, $settlement_amount);
		$settlements = apply_filters('mp_mpr_rb_settlements', $settlements, $order, $settlement_amount);

		return [
			'items' => $items,
			'settlements' => $settlements,
			'total_items_amount' => $total_items_amount,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param WC_Order $order
	 * @param array<int,mixed> $only_items
	 * @return array<int,WC_Order_Item_Product>
	 */
	private static function resolve_line_items(WC_Order $order, array $only_items): array {
		if ($only_items === []) {
			$split = MP_Marked_Products_Receipt_ProductMarker::split_order_line_items($order);

			return array_values($split['marked']);
		}

		$out = [];
		foreach ($only_items as $entry) {
			if ($entry instanceof WC_Order_Item_Product) {
				if ((int) $entry->get_order_id() === (int) $order->get_id()) {
					$out[] = $entry;
				}
				continue;
			}

			$id = (int) $entry;
			if ($id <= 0) {
				continue;
			}

			$candidate = $order->get_item($id);
			if ($candidate instanceof WC_Order_Item_Product) {
				$out[] = $candidate;
			}
		}

		return $out;
	}

	/**
	 * @param WC_Order_Item_Product $item
	 * @return string
	 */
	private static function resolve_tax_code(WC_Order_Item_Product $item): string {
		$default_tax = get_option('robokassa_payment_tax') ?: 'none';
		$tax_class = '';
		$product = $item->get_product();
		if ($product) {
			$tax_class = (string) $product->get_tax_class();
		}

		$map = [
			'' => (string) $default_tax,
			'standard' => (string) $default_tax,
			'reduced-rate' => 'vat10',
			'zero-rate' => 'vat0',
		];

		$tax = isset($map[$tax_class]) ? $map[$tax_class] : (string) $default_tax;
		$tax = (string) apply_filters('mp_mpr_rb_vat_code', $tax, $item, $tax_class);

		return $tax;
	}

	/**
	 * @param string $name
	 * @return string
	 */
	private static function sanitize_name(string $name): string {
		$name = trim(wp_strip_all_tags($name));
		if ($name === '') {
			return 'Product';
		}
		$length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
		if ($length > 128) {
			return function_exists('mb_substr') ? mb_substr($name, 0, 128) : substr($name, 0, 128);
		}

		return $name;
	}

	/**
	 * @param WC_Product $product
	 * @return bool
	 */
	private static function is_gift_card_product(WC_Product $product): bool {
		$product_to_check = $product->get_parent_id() ? wc_get_product($product->get_parent_id()) : $product;
		if (!$product_to_check) {
			return false;
		}

		if (is_a($product_to_check, 'WC_Product_PW_Gift_Card')) {
			return true;
		}

		$type = method_exists($product_to_check, 'get_type') ? (string) $product_to_check->get_type() : '';
		if (strpos($type, 'gift') !== false) {
			return true;
		}

		$sku = method_exists($product_to_check, 'get_sku') ? (string) $product_to_check->get_sku() : '';
		if ($sku !== '' && stripos($sku, 'gift') !== false) {
			return true;
		}

		return false;
	}

	/**
	 * @param float $amount
	 * @return string
	 */
	private static function money(float $amount): string {
		return number_format((float) wc_format_decimal($amount, wc_get_price_decimals()), 2, '.', '');
	}

	/**
	 * @param WC_Product $product
	 * @param array<int,array<string,mixed>> $rules
	 * @return array<string,mixed>
	 */
	private static function resolve_rule_for_product(WC_Product $product, array $rules): array {
		if (empty($rules)) {
			return [];
		}

		$product_cat_ids = self::get_product_category_ids($product);
		if (empty($product_cat_ids)) {
			return [];
		}

		foreach ($rules as $rule) {
			if (empty($rule['enabled'])) {
				continue;
			}

			$rule_cats = isset($rule['category_ids']) && is_array($rule['category_ids']) ? array_map('intval', $rule['category_ids']) : [];
			if (empty($rule_cats)) {
				continue;
			}

			$intersect = array_intersect($product_cat_ids, $rule_cats);
			if (!empty($intersect)) {
				return $rule;
			}
		}

		return [];
	}

	/**
	 * @param WC_Product $product
	 * @return array<int,int>
	 */
	private static function get_product_category_ids(WC_Product $product): array {
		$ids = [];

		if (method_exists($product, 'get_category_ids')) {
			$ids = array_map('intval', (array) $product->get_category_ids());
		}

		if (empty($ids) && $product->get_parent_id() > 0) {
			$parent = wc_get_product($product->get_parent_id());
			if ($parent && method_exists($parent, 'get_category_ids')) {
				$ids = array_map('intval', (array) $parent->get_category_ids());
			}
		}

		return array_values(array_unique(array_filter($ids, static function ($id) {
			return (int) $id > 0;
		})));
	}
}
