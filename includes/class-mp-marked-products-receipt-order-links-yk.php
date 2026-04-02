<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Resolves YooKassa `payment_id` for attaching a receipt and settlement amount for marked goods.
 *
 * Replaces gift-card settlement detection from `MP_Yookassa_Receipt2_OrderLinks` with
 * “has marked line items + sum of their totals”.
 */
final class MP_Marked_Products_Receipt_OrderLinks_YK {
	public const META_SOURCE_PAYMENT_ID = 'mp_mpr_yk_source_payment_id';
	public const META_SETTLEMENT_AMOUNT = 'mp_mpr_yk_settlement_amount';

	/**
	 * @param WC_Order $order
	 * @return array{
	 *   has_marked_receipt:bool,
	 *   settlement_amount:float,
	 *   source_payment_id:string,
	 *   reason:string,
	 *   ambiguous_source:bool
	 * }
	 */
	public static function resolve_for_order(WC_Order $order): array {
		$result = [
			'has_marked_receipt' => false,
			'settlement_amount' => 0.0,
			'source_payment_id' => '',
			'reason' => '',
			'ambiguous_source' => false,
		];

		/**
		 * Full override. Expected keys: has_marked_receipt, settlement_amount, source_payment_id, reason (optional).
		 *
		 * @param array<string,mixed>|null $override
		 * @param WC_Order $order
		 */
		$override = apply_filters('mp_mpr_yk_order_links', null, $order);
		if (is_array($override)) {
			$result['has_marked_receipt'] = !empty($override['has_marked_receipt']);
			if (isset($override['has_marked_items_ready_for_second_receipt'])) {
				$result['has_marked_receipt'] = !empty($override['has_marked_items_ready_for_second_receipt']);
			}
			$result['settlement_amount'] = isset($override['settlement_amount']) ? max(0.0, (float) $override['settlement_amount']) : 0.0;
			$result['source_payment_id'] = isset($override['source_payment_id']) ? trim((string) $override['source_payment_id']) : '';
			$result['reason'] = isset($override['reason']) ? trim((string) $override['reason']) : 'resolved_by_filter';
			$result['ambiguous_source'] = !empty($override['ambiguous_source']);

			return $result;
		}

		$split = MP_Marked_Products_Receipt_ProductMarker::split_order_line_items($order);
		$marked_items = $split['marked'];
		if (empty($marked_items)) {
			$result['reason'] = 'no_marked_line_items';

			return $result;
		}

		$computed = self::sum_marked_line_totals_excluding_gift_cards($marked_items);
		$meta_amount = self::read_settlement_amount_meta($order);
		if ($meta_amount > 0.0) {
			$result['settlement_amount'] = $meta_amount;
			$result['reason'] = 'settlement_from_order_meta';
		} else {
			$result['settlement_amount'] = $computed;
			$result['reason'] = $computed > 0 ? 'settlement_from_marked_line_totals' : 'zero_settlement';
		}

		$result['has_marked_receipt'] = $result['settlement_amount'] > 0.0;

		if (!$result['has_marked_receipt']) {
			$result['reason'] = 'zero_settlement';

			return $result;
		}

		$settlement_reason = $result['reason'];

		$resolved = self::resolve_source_payment_id($order);
		$result['source_payment_id'] = $resolved['payment_id'];
		$result['ambiguous_source'] = $resolved['ambiguous'];

		if ($result['ambiguous_source']) {
			$result['source_payment_id'] = '';
			$result['has_marked_receipt'] = false;
			$result['reason'] = 'ambiguous_multiple_source_payment_ids';

			return $result;
		}

		if ($resolved['reason'] !== '') {
			$result['reason'] = $resolved['reason'];
		} elseif ($result['source_payment_id'] === '') {
			$result['reason'] = 'source_payment_id_not_found';
		} else {
			$result['reason'] = $settlement_reason;
		}

		return $result;
	}

	/**
	 * @param WC_Order $order
	 * @return float
	 */
	private static function read_settlement_amount_meta(WC_Order $order): float {
		$raw = $order->get_meta(self::META_SETTLEMENT_AMOUNT, true);
		if ($raw === '' || $raw === null) {
			$raw = get_post_meta((int) $order->get_id(), self::META_SETTLEMENT_AMOUNT, true);
		}
		$amount = (float) $raw;
		if ($amount <= 0) {
			return 0.0;
		}

		return (float) wc_format_decimal($amount, wc_get_price_decimals());
	}

	/**
	 * @param array<int,WC_Order_Item_Product> $marked_items
	 * @return float
	 */
	private static function sum_marked_line_totals_excluding_gift_cards(array $marked_items): float {
		$total = 0.0;

		foreach ($marked_items as $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				continue;
			}

			$product = $item->get_product();
			if ($product && self::is_gift_card_product($product)) {
				continue;
			}

			$line_total = (float) $item->get_total();
			if ($line_total > 0) {
				$total += $line_total;
			}
		}

		return (float) wc_format_decimal($total, wc_get_price_decimals());
	}

	/**
	 * @param WC_Order $order
	 * @return array{payment_id:string,ambiguous:bool,reason:string}
	 */
	private static function resolve_source_payment_id(WC_Order $order): array {
		$explicit = trim((string) $order->get_meta(self::META_SOURCE_PAYMENT_ID, true));
		if ($explicit === '') {
			$explicit = trim((string) get_post_meta((int) $order->get_id(), self::META_SOURCE_PAYMENT_ID, true));
		}
		if ($explicit !== '') {
			return ['payment_id' => $explicit, 'ambiguous' => false, 'reason' => 'source_from_plugin_meta'];
		}

		$candidates = [];
		$tx = trim((string) $order->get_transaction_id());
		if ($tx !== '') {
			$candidates[] = $tx;
		}

		foreach (self::get_source_payment_meta_keys() as $meta_key) {
			if ($meta_key === self::META_SOURCE_PAYMENT_ID) {
				continue;
			}
			$val = $order->get_meta($meta_key, true);
			if ($val === '' || $val === null) {
				$val = get_post_meta((int) $order->get_id(), $meta_key, true);
			}
			if (is_scalar($val)) {
				$s = trim((string) $val);
				if ($s !== '') {
					$candidates[] = $s;
				}
			}
		}

		$candidates = array_values(array_unique($candidates));

		if (count($candidates) > 1) {
			return ['payment_id' => '', 'ambiguous' => true, 'reason' => 'ambiguous_multiple_source_payment_ids'];
		}

		if (count($candidates) === 1) {
			return ['payment_id' => $candidates[0], 'ambiguous' => false, 'reason' => ''];
		}

		$filtered = apply_filters('mp_mpr_yk_source_payment_id', '', $order, [
			'candidates' => $candidates,
		]);
		$filtered = trim((string) $filtered);

		return ['payment_id' => $filtered, 'ambiguous' => false, 'reason' => $filtered !== '' ? 'source_resolved_by_filter' : ''];
	}

	/**
	 * Meta keys to scan for YooKassa payment id (after transaction_id).
	 *
	 * @return array<int,string>
	 */
	private static function get_source_payment_meta_keys(): array {
		$keys = [
			'_transaction_id',
			'transaction_id',
			'_yookassa_payment_id',
			'yookassa_payment_id',
		];

		/**
		 * @param array<int,string> $keys
		 */
		return apply_filters('mp_mpr_yk_source_payment_meta_keys', $keys);
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
}
