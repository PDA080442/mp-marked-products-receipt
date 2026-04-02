<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * YooKassa REST: create receipt attached to payment (`POST /v3/receipts`).
 */
final class MP_Marked_Products_Receipt_ApiClient_YK {
	/**
	 * @param string $payment_id
	 * @param array{items:array,settlements:array} $receipt_data
	 * @return array{
	 *   ok:bool,
	 *   status_code:int,
	 *   receipt_id:string,
	 *   idempotence_key:string,
	 *   error:string,
	 *   response_body:array|string
	 * }
	 */
	public static function send_receipt(string $payment_id, array $receipt_data): array {
		$url = MP_Marked_Products_Receipt_Settings::get_yk_api_base_url();
		$shop_id = trim(MP_Marked_Products_Receipt_Settings::get_yk_shop_id());
		$secret_key = trim(MP_Marked_Products_Receipt_Settings::get_yk_secret_key());
		$idempotence_key = self::generate_idempotence_key();

		$result = [
			'ok' => false,
			'status_code' => 0,
			'receipt_id' => '',
			'idempotence_key' => $idempotence_key,
			'error' => '',
			'response_body' => [],
		];

		$payload = [
			'type' => 'payment',
			'payment_id' => $payment_id,
			'send' => true,
			'items' => isset($receipt_data['items']) ? $receipt_data['items'] : [],
			'settlements' => isset($receipt_data['settlements']) ? $receipt_data['settlements'] : [],
		];

		$args = [
			'timeout' => 12,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($shop_id . ':' . $secret_key),
				'Content-Type' => 'application/json',
				'Idempotence-Key' => $idempotence_key,
			],
			'body' => wp_json_encode($payload),
		];

		$max_attempts = 3;
		$attempt = 1;

		while ($attempt <= $max_attempts) {
			$response = wp_remote_post($url, $args);

			if (is_wp_error($response)) {
				$result['error'] = sprintf(
					/* translators: %s: WordPress error message */
					__('WP_Error: %s', 'mp-marked-products-receipt'),
					$response->get_error_message()
				);
				if ($attempt < $max_attempts) {
					self::sleep_backoff($attempt);
					$attempt++;
					continue;
				}

				return $result;
			}

			$status_code = (int) wp_remote_retrieve_response_code($response);
			$body_raw = (string) wp_remote_retrieve_body($response);
			$body_json = json_decode($body_raw, true);
			$result['status_code'] = $status_code;
			$result['response_body'] = is_array($body_json) ? $body_json : $body_raw;

			if ($status_code >= 200 && $status_code < 300) {
				$result['ok'] = true;
				if (is_array($body_json) && isset($body_json['id']) && is_string($body_json['id'])) {
					$result['receipt_id'] = $body_json['id'];
				}

				return $result;
			}

			if ($status_code >= 500 && $status_code <= 599 && $attempt < $max_attempts) {
				self::sleep_backoff($attempt);
				$attempt++;
				continue;
			}

			$result['error'] = sprintf(
				/* translators: %d: HTTP status code */
				__('HTTP %d', 'mp-marked-products-receipt'),
				$status_code
			);

			return $result;
		}

		$result['error'] = __('Неизвестная ошибка API.', 'mp-marked-products-receipt');

		return $result;
	}

	/**
	 * Diagnostic: GET a non-existent payment by UUID. HTTP 404 means credentials are accepted; 401/403 means auth failure.
	 * Does not create a receipt.
	 *
	 * @return array{ok:bool,status_code:int,message:string,body_excerpt:string}
	 */
	public static function ping(): array {
		$shop_id = trim(MP_Marked_Products_Receipt_Settings::get_yk_shop_id());
		$secret_key = trim(MP_Marked_Products_Receipt_Settings::get_yk_secret_key());
		$out = [
			'ok' => false,
			'status_code' => 0,
			'message' => '',
			'body_excerpt' => '',
		];

		if ($shop_id === '' || $secret_key === '') {
			$out['message'] = __('Нет shop_id или secret_key.', 'mp-marked-products-receipt');

			return $out;
		}

		$base = MP_Marked_Products_Receipt_Settings::is_yk_sandbox()
			? 'https://api-preprod.yookassa.ru/v3/payments'
			: 'https://api.yookassa.ru/v3/payments';
		$fake_id = '00000000-0000-0000-0000-000000000001';
		$url = $base . '/' . $fake_id;

		$response = wp_remote_get($url, [
			'timeout' => 12,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode($shop_id . ':' . $secret_key),
				'Content-Type' => 'application/json',
			],
		]);

		if (is_wp_error($response)) {
			$out['message'] = sprintf(
				/* translators: %s: WordPress error message */
				__('WP_Error: %s', 'mp-marked-products-receipt'),
				$response->get_error_message()
			);

			return $out;
		}

		$status_code = (int) wp_remote_retrieve_response_code($response);
		$body_raw = (string) wp_remote_retrieve_body($response);
		$out['status_code'] = $status_code;
		$out['body_excerpt'] = strlen($body_raw) > 400 ? substr($body_raw, 0, 400) . '…' : $body_raw;

		if ($status_code === 404) {
			$out['ok'] = true;
			$out['message'] = __('Авторизация прошла (404 для несуществующего платежа — ожидаемо).', 'mp-marked-products-receipt');

			return $out;
		}

		if ($status_code === 401 || $status_code === 403) {
			$out['message'] = __('Ошибка авторизации (проверьте shop_id и secret_key).', 'mp-marked-products-receipt');

			return $out;
		}

		$out['message'] = sprintf(
			/* translators: %d HTTP status */
			__('Неожиданный ответ API (HTTP %d).', 'mp-marked-products-receipt'),
			$status_code
		);

		return $out;
	}

	/**
	 * @return string
	 */
	private static function generate_idempotence_key(): string {
		if (function_exists('wp_generate_uuid4')) {
			return wp_generate_uuid4();
		}

		return md5(uniqid('mp-mpr-yk-', true));
	}

	/**
	 * @param int $attempt
	 * @return void
	 */
	private static function sleep_backoff(int $attempt): void {
		$delay_seconds = 1;
		if ($attempt === 2) {
			$delay_seconds = 3;
		} elseif ($attempt >= 3) {
			$delay_seconds = 10;
		}
		sleep($delay_seconds);
	}
}
