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
				$result['error'] = 'WP_Error: ' . $response->get_error_message();
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

			$result['error'] = 'HTTP ' . $status_code;

			return $result;
		}

		$result['error'] = 'Unknown API error';

		return $result;
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
