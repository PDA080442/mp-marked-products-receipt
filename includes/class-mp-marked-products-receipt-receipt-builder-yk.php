<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Builds YooKassa receipt payload (payment receipt) for **marked** line items only.
 *
 * Marking (FFD 1.2) per YooKassa:
 * @see https://yookassa.ru/developers/payment-acceptance/receipts/54fz/other-services/marking
 * — `items.mark_code_info` (tag 1163), `items.measure`, optionally `items.mark_quantity`, `items.mark_mode`, etc.
 */
final class MP_Marked_Products_Receipt_ReceiptBuilder_YK {
	/**
	 * @param WC_Order $order
	 * @param float $settlement_amount
	 * @param array<int,mixed> $only_items Item IDs (int) and/or WC_Order_Item_Product. Empty = all line items that are marked.
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

		$default_mode = MP_Marked_Products_Receipt_Settings::get_yk_default_payment_mode();
		$default_subject = MP_Marked_Products_Receipt_Settings::get_yk_default_payment_subject();
		$rules = MP_Marked_Products_Receipt_Settings::get_yk_rules();

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

			$quantity = (float) $item->get_quantity();
			if ($quantity <= 0) {
				continue;
			}

			$line_total = (float) $item->get_total();
			if ($line_total <= 0) {
				continue;
			}

			$unit_amount = $line_total / $quantity;
			$unit_amount = (float) wc_format_decimal($unit_amount, wc_get_price_decimals());
			$quantity = (float) wc_format_decimal($quantity, 3);
			$line_amount = (float) wc_format_decimal($unit_amount * $quantity, wc_get_price_decimals());
			$total_items_amount += $line_amount;

			$rule = self::resolve_rule_for_product($product, $rules);
			$payment_mode = $rule['payment_mode'] ?? $default_mode;
			$payment_subject = $rule['payment_subject'] ?? $default_subject;

			$row = [
				'description' => self::sanitize_description($item->get_name()),
				'quantity' => $quantity,
				'amount' => [
					'value' => self::money($line_amount),
					'currency' => $order->get_currency() ?: 'RUB',
				],
				'vat_code' => self::resolve_vat_code($item),
				'payment_mode' => $payment_mode,
				'payment_subject' => $payment_subject,
			];

			$payload = MP_Marked_Products_Receipt_ProductMarker::get_marking_payload_for_line_item($item);
			$cis = '';
			if (is_array($payload) && isset($payload['cis'])) {
				$cis = trim((string) $payload['cis']);
			}

			if ($cis === '') {
				$warnings[] = sprintf(
					/* translators: %d: order line item ID */
					__('Нет КИЗ/mark_code_info для позиции заказа #%d.', 'mp-marked-products-receipt'),
					(int) $item->get_id()
				);
				if (MP_Marked_Products_Receipt_Settings::is_yk_skip_without_cis()) {
					$total_items_amount -= $line_amount;
					continue;
				}
			} else {
				// Tag 1163 — mandatory for marked goods (FFD 1.2).
				$row['mark_code_info'] = $cis;
				// Tag 2108 — unit of measure; required for FFD 1.2 receipts.
				$row['measure'] = 'piece';
				// Tag 1291 — fraction / quantity for piece goods.
				$row['mark_quantity'] = $quantity;
			}

			$items[] = $row;
		}

		if (empty($items)) {
			$warnings[] = __('В чек ЮKassa не попала ни одна маркированная позиция.', 'mp-marked-products-receipt');
		}

		$total_items_amount = (float) wc_format_decimal($total_items_amount, wc_get_price_decimals());
		$settlement_amount = (float) wc_format_decimal(max(0.0, $settlement_amount), wc_get_price_decimals());

		if ($settlement_amount > $total_items_amount && $total_items_amount > 0) {
			$warnings[] = __('Сумма зачёта больше суммы позиций; ограничено суммой позиций.', 'mp-marked-products-receipt');
			$settlement_amount = $total_items_amount;
		}

		$settlements = [
			[
				'type' => 'prepayment',
				'amount' => [
					'value' => self::money($settlement_amount),
					'currency' => $order->get_currency() ?: 'RUB',
				],
			],
		];

		$items = apply_filters('mp_mpr_yk_items', $items, $order, $settlement_amount);
		$settlements = apply_filters('mp_mpr_yk_settlements', $settlements, $order, $settlement_amount);

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
	 * @param string $name
	 * @return string
	 */
	private static function sanitize_description(string $name): string {
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
	 * @param WC_Order_Item_Product $item
	 * @return int
	 */
	private static function resolve_vat_code(WC_Order_Item_Product $item): int {
		$default = 1;
		$tax_class = '';
		$product = $item->get_product();
		if ($product) {
			$tax_class = (string) $product->get_tax_class();
		}

		$map = [
			'' => 1,
			'standard' => 1,
			'reduced-rate' => 2,
			'zero-rate' => 3,
		];
		$vat_code = isset($map[$tax_class]) ? (int) $map[$tax_class] : $default;

		return (int) apply_filters('mp_mpr_yk_vat_code', $vat_code, $item, $tax_class);
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

		$class_name = get_class($product_to_check);
		if ($class_name === 'WC_Product_PW_Gift_Card' || is_a($product_to_check, 'WC_Product_PW_Gift_Card')) {
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

			$rule_cats = isset($rule['category_ids']) && is_array($rule['category_ids']) ? $rule['category_ids'] : [];
			if (empty($rule_cats)) {
				continue;
			}

			$intersect = array_intersect($product_cat_ids, array_map('intval', $rule_cats));
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

		$ids = array_values(array_unique(array_filter($ids, static function ($id) {
			return (int) $id > 0;
		})));

		return $ids;
	}
}
