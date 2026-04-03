<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Coordinates marked-only receipt sending for YooKassa and Robokassa.
 */
final class MP_Marked_Products_Receipt_Orchestrator {
	private const META_YK_SENT = 'mp_mpr_yk_sent';
	private const META_YK_RECEIPT_ID = 'mp_mpr_yk_receipt_id';
	private const META_YK_ERROR = 'mp_mpr_yk_error';
	private const META_YK_IDEMPOTENCE = 'mp_mpr_yk_idempotence_key';

	private const META_RB_SENT = 'mp_mpr_rb_sent';
	private const META_RB_RECEIPT_ID = 'mp_mpr_rb_receipt_id';
	private const META_RB_RECEIPT_URL = 'mp_mpr_rb_receipt_url';
	private const META_RB_ERROR = 'mp_mpr_rb_error';
	private const META_RB_REQUEST_ID = 'mp_mpr_rb_request_id';

	/**
	 * @param int $order_id
	 * @return void
	 */
	public static function on_order_completed($order_id): void {
		$order_id = (int) $order_id;
		if ($order_id <= 0 || !function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);
		if (!$order instanceof WC_Order) {
			return;
		}

		self::handle_order($order, 'auto');
	}

	/**
	 * @param WC_Order $order
	 * @param string $context auto|manual_yk|manual_rb
	 * @return void
	 */
	public static function handle_order(WC_Order $order, string $context = 'auto'): void {
		if (!MP_Marked_Products_Receipt_Settings::is_common_enabled()) {
			return;
		}

		/**
		 * @param bool $run
		 * @param WC_Order $order
		 */
		if (!apply_filters('mp_mpr_should_process_order', true, $order)) {
			return;
		}

		if ($context === 'manual_yk') {
			self::process_yk($order, true);

			return;
		}

		if ($context === 'manual_rb') {
			self::process_rb($order, true);

			return;
		}

		if (MP_Marked_Products_Receipt_Settings::is_yk_enabled()) {
			self::process_yk($order, false);
		}
		if (MP_Marked_Products_Receipt_Settings::is_rb_enabled()) {
			self::process_rb($order, false);
		}
	}

	/**
	 * @param WC_Order $order
	 * @param bool $manual
	 * @return void
	 */
	public static function process_yk(WC_Order $order, bool $manual): void {
		$order_id = (int) $order->get_id();

		if (!MP_Marked_Products_Receipt_Settings::is_common_enabled() || !MP_Marked_Products_Receipt_Settings::is_yk_enabled()) {
			return;
		}

		if ($manual) {
			$order->delete_meta_data(self::META_YK_SENT);
			$order->save();
		} else {
			$sent = $order->get_meta(self::META_YK_SENT, true);
			if ($sent === 'yes') {
				MP_Marked_Products_Receipt_Logger::log('INFO', $order_id, 'yk_skip_already_sent', [
					'manual' => false,
				]);

				return;
			}
		}

		$settings_errors = MP_Marked_Products_Receipt_Settings::validate_yk_for_api();
		if (!empty($settings_errors)) {
			$order->update_meta_data(self::META_YK_ERROR, implode('; ', $settings_errors));
			$order->save();
			MP_Marked_Products_Receipt_Logger::log('ERROR', $order_id, 'yk_settings_invalid', [
				'errors' => $settings_errors,
				'manual' => $manual,
			]);

			return;
		}

		if (self::bail_if_order_currency_not_rub($order, 'yk', $manual)) {
			return;
		}

		$split = MP_Marked_Products_Receipt_ProductMarker::split_order_line_items($order);
		if (empty($split['marked'])) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'yk_skip_no_marked_items', [
				'manual' => $manual,
			]);

			return;
		}

		$resolved = MP_Marked_Products_Receipt_OrderLinks_YK::resolve_for_order($order);
		if (empty($resolved['has_marked_receipt'])) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'yk_skip_no_receipt_context', [
				'reason' => $resolved['reason'] ?? '',
				'manual' => $manual,
			]);

			return;
		}

		if (trim((string) ($resolved['source_payment_id'] ?? '')) === '') {
			$order->update_meta_data(self::META_YK_ERROR, __('Не найден source_payment_id для ЮKassa.', 'mp-marked-products-receipt'));
			$order->save();
			MP_Marked_Products_Receipt_Logger::log('ERROR', $order_id, 'yk_missing_source_payment_id', [
				'reason' => $resolved['reason'] ?? '',
				'manual' => $manual,
			]);

			return;
		}

		$settlement = (float) ($resolved['settlement_amount'] ?? 0.0);
		$receipt_data = MP_Marked_Products_Receipt_ReceiptBuilder_YK::build($order, $settlement, []);

		if (self::receipt_has_no_payable_amount($receipt_data)) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'yk_skip_zero_marked_after_filter', [
				'total_items_amount' => isset($receipt_data['total_items_amount']) ? (float) $receipt_data['total_items_amount'] : 0.0,
				'warnings' => $receipt_data['warnings'] ?? [],
				'manual' => $manual,
			]);

			return;
		}

		$yk_payload = [
			'items' => $receipt_data['items'],
			'settlements' => $receipt_data['settlements'],
		];

		/**
		 * §21: полный фрагмент чека ЮKassa (items + settlements) перед POST `/v3/receipts`.
		 *
		 * @param array $payload Keys: `items`, `settlements`.
		 * @param WC_Order $order
		 * @param array $resolved Результат `OrderLinks_YK::resolve_for_order`.
		 * @param bool $manual Ручная отправка из заказа.
		 */
		$yk_payload = apply_filters('mp_mpr_yk_receipt_payload', $yk_payload, $order, $resolved, $manual);
		if (!is_array($yk_payload)) {
			$yk_payload = [
				'items' => $receipt_data['items'],
				'settlements' => $receipt_data['settlements'],
			];
		}

		$yk_items = isset($yk_payload['items']) && is_array($yk_payload['items']) ? $yk_payload['items'] : [];
		$yk_settlements = isset($yk_payload['settlements']) && is_array($yk_payload['settlements'])
			? $yk_payload['settlements']
			: (isset($receipt_data['settlements']) && is_array($receipt_data['settlements']) ? $receipt_data['settlements'] : []);

		$yk_check = [
			'items' => $yk_items,
			'total_items_amount' => self::sum_yk_items_amounts($yk_items),
		];
		if (self::receipt_has_no_payable_amount($yk_check)) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'yk_skip_zero_marked_after_payload_filter', [
				'manual' => $manual,
			]);

			return;
		}

		$items_count = count($yk_items);

		$api = MP_Marked_Products_Receipt_ApiClient_YK::send_receipt(
			(string) $resolved['source_payment_id'],
			[
				'items' => $yk_items,
				'settlements' => $yk_settlements,
			]
		);

		// §32: ключ последней попытки храним и при ошибке (разбор инцидентов / идемпотентность на стороне YooKassa).
		$order->update_meta_data(self::META_YK_IDEMPOTENCE, (string) ($api['idempotence_key'] ?? ''));

		if (!empty($api['ok'])) {
			$order->update_meta_data(self::META_YK_SENT, 'yes');
			$order->update_meta_data(self::META_YK_RECEIPT_ID, (string) ($api['receipt_id'] ?? ''));
			$order->delete_meta_data(self::META_YK_ERROR);
			$order->save();

			MP_Marked_Products_Receipt_Logger::log('INFO', $order_id, 'yk_send_success', [
				'receipt_id' => $api['receipt_id'] ?? '',
				'status_code' => $api['status_code'] ?? 0,
				'items_count' => $items_count,
				'settlement_amount' => $settlement,
				'manual' => $manual,
			]);

			if ($manual) {
				$order->add_order_note(__('Отдельный чек маркированных (ЮKassa) отправлен вручную успешно.', 'mp-marked-products-receipt'));
			}

			return;
		}

		$err = isset($api['error']) && is_string($api['error']) && $api['error'] !== ''
			? $api['error']
			: __('Неизвестная ошибка API ЮKassa.', 'mp-marked-products-receipt');
		$order->update_meta_data(self::META_YK_ERROR, $err);
		$order->save();

		MP_Marked_Products_Receipt_Logger::log('ERROR', $order_id, 'yk_send_failed', [
			'error' => $err,
			'status_code' => $api['status_code'] ?? 0,
			'response_body' => $api['response_body'] ?? null,
			'manual' => $manual,
		]);

		// §32: при 4xx автоповторов нет (хук completed не дергается повторно сам по себе; клиент тоже не ретраит 4xx).
		$sc = (int) ($api['status_code'] ?? 0);
		if (!$manual && $sc >= 400 && $sc <= 499) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'yk_4xx_expect_manual_fix', [
				'status_code' => $sc,
				'idempotence_key' => (string) ($api['idempotence_key'] ?? ''),
			]);
		}

		if ($manual) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__('Ошибка ручной отправки чека маркированных (ЮKassa): %s', 'mp-marked-products-receipt'),
					$err
				)
			);
		}
	}

	/**
	 * @param WC_Order $order
	 * @param bool $manual
	 * @return void
	 */
	public static function process_rb(WC_Order $order, bool $manual): void {
		$order_id = (int) $order->get_id();

		if (!MP_Marked_Products_Receipt_Settings::is_common_enabled() || !MP_Marked_Products_Receipt_Settings::is_rb_enabled()) {
			return;
		}

		if ($manual) {
			$order->delete_meta_data(self::META_RB_SENT);
			$order->save();
		} else {
			$sent = $order->get_meta(self::META_RB_SENT, true);
			if ($sent === 'yes') {
				MP_Marked_Products_Receipt_Logger::log('INFO', $order_id, 'rb_skip_already_sent', [
					'manual' => false,
				]);

				return;
			}
		}

		$settings_errors = MP_Marked_Products_Receipt_Settings::validate_rb_for_api();
		if (!empty($settings_errors)) {
			$order->update_meta_data(self::META_RB_ERROR, implode('; ', $settings_errors));
			$order->save();
			MP_Marked_Products_Receipt_Logger::log('ERROR', $order_id, 'rb_settings_invalid', [
				'errors' => $settings_errors,
				'manual' => $manual,
			]);

			return;
		}

		if (self::bail_if_order_currency_not_rub($order, 'rb', $manual)) {
			return;
		}

		$split = MP_Marked_Products_Receipt_ProductMarker::split_order_line_items($order);
		if (empty($split['marked'])) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'rb_skip_no_marked_items', [
				'manual' => $manual,
			]);

			return;
		}

		$resolved = MP_Marked_Products_Receipt_OrderLinks_RB::resolve_for_order($order);
		if (empty($resolved['has_marked_receipt'])) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'rb_skip_no_receipt_context', [
				'reason' => $resolved['reason'] ?? '',
				'manual' => $manual,
			]);

			return;
		}

		if (trim((string) ($resolved['source_id'] ?? '')) === '') {
			$order->update_meta_data(self::META_RB_ERROR, __('Не найден source_id для Robokassa.', 'mp-marked-products-receipt'));
			$order->save();
			MP_Marked_Products_Receipt_Logger::log('ERROR', $order_id, 'rb_missing_source_id', [
				'reason' => $resolved['reason'] ?? '',
				'manual' => $manual,
			]);

			return;
		}

		$settlement = (float) ($resolved['settlement_amount'] ?? 0.0);
		$receipt_data = MP_Marked_Products_Receipt_ReceiptBuilder_RB::build($order, $settlement, []);

		if (self::receipt_has_no_payable_amount($receipt_data)) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'rb_skip_zero_marked_after_filter', [
				'total_items_amount' => isset($receipt_data['total_items_amount']) ? (float) $receipt_data['total_items_amount'] : 0.0,
				'warnings' => $receipt_data['warnings'] ?? [],
				'manual' => $manual,
			]);

			return;
		}

		$fields = self::build_rb_api_fields($order, $resolved, $receipt_data);

		/**
		 * §21: полное тело RoboFiscal Attach (MerchantLogin, InvoiceID, SourceInvoiceId, OutSum, Receipt) перед подписью и POST.
		 *
		 * @param array $fields
		 * @param WC_Order $order
		 * @param array $resolved Результат `OrderLinks_RB::resolve_for_order`.
		 * @param bool $manual Ручная отправка из заказа.
		 */
		$fields = apply_filters('mp_mpr_rb_receipt_payload', $fields, $order, $resolved, $manual);
		if (!is_array($fields)) {
			$fields = self::build_rb_api_fields($order, $resolved, $receipt_data);
		}

		$rb_receipt = isset($fields['Receipt']) && is_array($fields['Receipt']) ? $fields['Receipt'] : [];
		$rb_items = isset($rb_receipt['items']) && is_array($rb_receipt['items']) ? $rb_receipt['items'] : [];
		$rb_check = [
			'items' => $rb_items,
			'total_items_amount' => self::sum_rb_items_amounts($rb_items),
		];
		if (self::receipt_has_no_payable_amount($rb_check)) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'rb_skip_zero_marked_after_payload_filter', [
				'manual' => $manual,
			]);

			return;
		}

		$items_count = count($rb_items);

		$api = MP_Marked_Products_Receipt_ApiClient_RB::send_second_receipt($fields, $order_id);

		// §32: X-Request-ID последней попытки — и при ошибке (как след для поддержки).
		$order->update_meta_data(self::META_RB_REQUEST_ID, (string) ($api['request_id'] ?? ''));

		if (!empty($api['ok'])) {
			$order->update_meta_data(self::META_RB_SENT, 'yes');
			$order->update_meta_data(self::META_RB_RECEIPT_ID, (string) ($api['receipt_id'] ?? ''));
			$order->update_meta_data(self::META_RB_RECEIPT_URL, (string) ($api['receipt_url'] ?? ''));
			$order->delete_meta_data(self::META_RB_ERROR);
			$order->save();

			MP_Marked_Products_Receipt_Logger::log('INFO', $order_id, 'rb_send_success', [
				'receipt_id' => $api['receipt_id'] ?? '',
				'request_id' => $api['request_id'] ?? '',
				'status_code' => $api['status_code'] ?? 0,
				'items_count' => $items_count,
				'settlement_amount' => $settlement,
				'manual' => $manual,
			]);

			$receipt_url = isset($api['receipt_url']) ? trim((string) $api['receipt_url']) : '';
			if ($receipt_url !== '') {
				$order->add_order_note(
					sprintf(
						/* translators: %s: receipt URL */
						__('Ссылка на чек маркированных (Robokassa): %s', 'mp-marked-products-receipt'),
						esc_url_raw($receipt_url)
					)
				);
			}

			if ($manual) {
				$order->add_order_note(__('Отдельный чек маркированных (Robokassa) отправлен вручную успешно.', 'mp-marked-products-receipt'));
			}

			return;
		}

		$err = isset($api['error']) && is_string($api['error']) && $api['error'] !== ''
			? $api['error']
			: __('Неизвестная ошибка API Robokassa.', 'mp-marked-products-receipt');
		$order->update_meta_data(self::META_RB_ERROR, $err);
		$order->save();

		MP_Marked_Products_Receipt_Logger::log('ERROR', $order_id, 'rb_send_failed', [
			'error' => $err,
			'status_code' => $api['status_code'] ?? 0,
			'response' => $api['response'] ?? null,
			'manual' => $manual,
		]);

		$sc_rb = (int) ($api['status_code'] ?? 0);
		if (!$manual && $sc_rb >= 400 && $sc_rb <= 499) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', $order_id, 'rb_4xx_expect_manual_fix', [
				'status_code' => $sc_rb,
				'request_id' => (string) ($api['request_id'] ?? ''),
			]);
		}

		if ($manual) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: error message */
					__('Ошибка ручной отправки чека маркированных (Robokassa): %s', 'mp-marked-products-receipt'),
					$err
				)
			);
		}
	}

	/**
	 * @param WC_Order $order
	 * @param array<string,mixed> $resolved
	 * @param array<string,mixed> $receipt_data
	 * @return array<string,mixed>
	 */
	private static function build_rb_api_fields(WC_Order $order, array $resolved, array $receipt_data): array {
		$login = MP_Marked_Products_Receipt_Settings::get_rb_login();
		if ($login === '') {
			$login = trim((string) get_option('robokassa_payment_MerchantLogin', ''));
		}

		return [
			'MerchantLogin' => $login,
			'InvoiceID' => (int) $order->get_id(),
			'SourceInvoiceId' => (string) $resolved['source_id'],
			'OutSum' => (string) wc_format_decimal((float) ($resolved['settlement_amount'] ?? 0.0), wc_get_price_decimals()),
			'Receipt' => [
				'items' => isset($receipt_data['items']) && is_array($receipt_data['items']) ? $receipt_data['items'] : [],
				'settlements' => isset($receipt_data['settlements']) && is_array($receipt_data['settlements']) ? $receipt_data['settlements'] : [],
			],
		];
	}

	/**
	 * §20: только RUB; иначе мета ошибки + ERROR в лог, без вызова API.
	 *
	 * @param WC_Order $order
	 * @param string $provider yk|rb
	 * @param bool $manual
	 * @return bool true = остановить обработку
	 */
	private static function bail_if_order_currency_not_rub(WC_Order $order, string $provider, bool $manual): bool {
		if (MP_Marked_Products_Receipt_Settings::is_order_currency_allowed_for_fiscal($order)) {
			return false;
		}

		$order_id = (int) $order->get_id();
		$cur = $order->get_currency();
		$msg = sprintf(
			/* translators: %s: order currency code (e.g. USD) */
			__('Валюта заказа (%s) не RUB; отправка чека маркированных невозможна.', 'mp-marked-products-receipt'),
			$cur
		);

		if ($provider === 'yk') {
			$order->update_meta_data(self::META_YK_ERROR, $msg);
			MP_Marked_Products_Receipt_Logger::log('ERROR', $order_id, 'yk_skip_non_rub_currency', [
				'currency' => $cur,
				'manual' => $manual,
			]);
		} else {
			$order->update_meta_data(self::META_RB_ERROR, $msg);
			MP_Marked_Products_Receipt_Logger::log('ERROR', $order_id, 'rb_skip_non_rub_currency', [
				'currency' => $cur,
				'manual' => $manual,
			]);
		}

		$order->save();

		return true;
	}

	/**
	 * §20: после фильтров/КИЗ нет ни одной оплачиваемой позиции или сумма 0 — не вызывать API (DEBUG).
	 *
	 * @param array<string,mixed> $receipt_data
	 * @return bool
	 */
	private static function receipt_has_no_payable_amount(array $receipt_data): bool {
		$items = isset($receipt_data['items']) && is_array($receipt_data['items']) ? $receipt_data['items'] : [];
		$total = isset($receipt_data['total_items_amount']) ? (float) $receipt_data['total_items_amount'] : 0.0;

		if (empty($items)) {
			return true;
		}

		return $total <= 0.0;
	}

	/**
	 * Сумма позиций чека ЮKassa по полю `amount.value` (после §21 payload filter).
	 *
	 * @param array<int,mixed> $items
	 * @return float
	 */
	private static function sum_yk_items_amounts(array $items): float {
		$total = 0.0;
		foreach ($items as $row) {
			if (!is_array($row)) {
				continue;
			}
			$amount = isset($row['amount']) && is_array($row['amount']) ? $row['amount'] : [];
			$val = $amount['value'] ?? null;
			if ($val === null || $val === '') {
				continue;
			}
			$total += (float) wc_format_decimal((string) $val, wc_get_price_decimals());
		}

		return (float) wc_format_decimal($total, wc_get_price_decimals());
	}

	/**
	 * Сумма позиций чека Robokassa по полю `sum` (после §21 payload filter).
	 *
	 * @param array<int,mixed> $items
	 * @return float
	 */
	private static function sum_rb_items_amounts(array $items): float {
		$total = 0.0;
		foreach ($items as $row) {
			if (!is_array($row)) {
				continue;
			}
			$sum = $row['sum'] ?? null;
			if ($sum === null || $sum === '') {
				continue;
			}
			$total += (float) wc_format_decimal((string) $sum, wc_get_price_decimals());
		}

		return (float) wc_format_decimal($total, wc_get_price_decimals());
	}
}
