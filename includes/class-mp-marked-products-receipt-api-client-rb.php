<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * RoboFiscal: attach second receipt (`POST .../RoboFiscal/Receipt/Attach`), signed body like `mp_robokassa_receipt2`.
 */
final class MP_Marked_Products_Receipt_ApiClient_RB {
	/**
	 * @param array<string,mixed> $fields Fiscal payload (JSON + signature).
	 * @param int|string $order_id
	 * @return array{
	 *   ok:bool,
	 *   status_code:int,
	 *   receipt_id:string,
	 *   receipt_url:string,
	 *   request_id:string,
	 *   error:string,
	 *   response:mixed
	 * }
	 */
	public static function send_second_receipt(array $fields, $order_id = 0): array {
		$credentials = self::resolve_credentials();
		$request_id = self::generate_request_id();
		$result = [
			'ok' => false,
			'status_code' => 0,
			'receipt_id' => '',
			'receipt_url' => '',
			'request_id' => $request_id,
			'error' => '',
			'response' => null,
		];

		if ($credentials['login'] === '' || $credentials['password1'] === '') {
			$result['error'] = 'Missing Robokassa credentials';

			return $result;
		}

		$payload = self::build_attach_payload($fields, $credentials['password1']);
		if ($payload === '') {
			$result['error'] = 'Failed to build signed payload';

			return $result;
		}

		$url = self::attach_endpoint($credentials['country']);
		$args = [
			'timeout' => 15,
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Request-ID' => $request_id,
			],
			'body' => $payload,
		];

		$max_attempts = 3;
		for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
			$response = wp_remote_post($url, $args);

			if (is_wp_error($response)) {
				$result['error'] = 'WP_Error: ' . $response->get_error_message();
				if ($attempt < $max_attempts) {
					self::sleep_backoff($attempt);
					continue;
				}

				return $result;
			}

			$status_code = (int) wp_remote_retrieve_response_code($response);
			$body_raw = (string) wp_remote_retrieve_body($response);
			$body_json = json_decode($body_raw, true);
			$result['status_code'] = $status_code;
			$result['response'] = is_array($body_json) ? $body_json : $body_raw;

			if ($status_code >= 200 && $status_code < 300) {
				$result['ok'] = true;
				$result['receipt_id'] = self::extract_receipt_id($result['response']);
				$result['receipt_url'] = self::extract_receipt_url($result['response']);

				return $result;
			}

			if ($status_code >= 500 && $status_code <= 599 && $attempt < $max_attempts) {
				self::sleep_backoff($attempt);
				continue;
			}

			$result['error'] = 'HTTP ' . $status_code;

			return $result;
		}

		$result['error'] = 'Unknown API failure';

		return $result;
	}

	/**
	 * Reachability check (same pattern as mp_robokassa_receipt2): POST `{}` to RoboFiscal Attach.
	 *
	 * @return array{ok:bool,status_code:int,message:string}
	 */
	public static function ping_reachability(): array {
		$url = 'https://ws.roboxchange.com/RoboFiscal/Receipt/Attach';
		$response = wp_remote_post($url, [
			'timeout' => 10,
			'headers' => ['Content-Type' => 'application/json'],
			'body' => '{}',
		]);

		if (is_wp_error($response)) {
			return [
				'ok' => false,
				'status_code' => 0,
				'message' => 'WP_Error: ' . $response->get_error_message(),
			];
		}

		$status_code = (int) wp_remote_retrieve_response_code($response);
		$ok = $status_code > 0;
		$msg = $ok
			? __('Точка доступна (тело запроса невалидно для API — это нормально для проверки сети).', 'mp-marked-products-receipt')
			: __('Пустой или неожиданный HTTP-статус.', 'mp-marked-products-receipt');

		return [
			'ok' => $ok,
			'status_code' => $status_code,
			'message' => $msg,
		];
	}

	/**
	 * @return array{login:string,password1:string,country:string}
	 */
	private static function resolve_credentials(): array {
		$login = MP_Marked_Products_Receipt_Settings::get_rb_login();
		$password1 = MP_Marked_Products_Receipt_Settings::get_rb_password1();
		$country = get_option('robokassa_country_code', 'RU');

		if ($login === '') {
			$login = trim((string) get_option('robokassa_payment_MerchantLogin', ''));
		}
		if ($password1 === '') {
			$is_test = MP_Marked_Products_Receipt_Settings::is_rb_sandbox() || get_option('robokassa_payment_test_onoff') === 'true';
			$password1 = trim((string) get_option($is_test ? 'robokassa_payment_testshoppass1' : 'robokassa_payment_shoppass1', ''));
		}

		return [
			'login' => $login,
			'password1' => $password1,
			'country' => trim((string) $country) !== '' ? (string) $country : 'RU',
		];
	}

	/**
	 * @param array<string,mixed> $fields
	 * @param string $password1
	 * @return string
	 */
	private static function build_attach_payload(array $fields, string $password1): string {
		$json = wp_json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if (!is_string($json) || $json === '') {
			return '';
		}

		$startup_hash = self::base64_url_trimmed($json);
		$sign_source = $startup_hash . $password1;
		$sign_md5 = md5($sign_source);
		$sign = self::base64_url_trimmed($sign_md5);

		return $startup_hash . '.' . $sign;
	}

	private static function base64_url_trimmed(string $string): string {
		$base64 = base64_encode($string);
		$replaced = strtr($base64, ['+' => '-', '/' => '_']);

		return preg_replace('/=+$/', '', $replaced) ?: '';
	}

	private static function attach_endpoint(string $country): string {
		return 'https://ws.roboxchange.com/RoboFiscal/Receipt/Attach';
	}

	private static function generate_request_id(): string {
		if (function_exists('wp_generate_uuid4')) {
			return wp_generate_uuid4();
		}

		return md5(uniqid('mp-mpr-rb-', true));
	}

	private static function sleep_backoff(int $attempt): void {
		$delay = 1;
		if ($attempt === 2) {
			$delay = 3;
		} elseif ($attempt >= 3) {
			$delay = 8;
		}
		sleep($delay);
	}

	/**
	 * @param mixed $response
	 * @return string
	 */
	private static function extract_receipt_id($response): string {
		if (is_array($response)) {
			foreach (['receipt_id', 'ReceiptId', 'id', 'Id', 'invoice_id', 'InvoiceID'] as $key) {
				if (isset($response[$key]) && is_scalar($response[$key])) {
					return (string) $response[$key];
				}
			}
		}

		return '';
	}

	/**
	 * @param mixed $response
	 * @return string
	 */
	private static function extract_receipt_url($response): string {
		$candidates = self::flatten_scalar_values($response);
		foreach ($candidates as $key => $value) {
			$key_l = strtolower((string) $key);
			$val = trim((string) $value);
			if ($val === '') {
				continue;
			}
			$is_url = filter_var($val, FILTER_VALIDATE_URL) !== false;
			$is_receiptish_key = strpos($key_l, 'url') !== false
				|| strpos($key_l, 'link') !== false
				|| strpos($key_l, 'receipt') !== false
				|| strpos($key_l, 'check') !== false;
			if ($is_url && $is_receiptish_key) {
				return $val;
			}
		}

		return '';
	}

	/**
	 * @param mixed $data
	 * @param string $prefix
	 * @return array<string,string>
	 */
	private static function flatten_scalar_values($data, string $prefix = ''): array {
		$result = [];
		if (is_array($data)) {
			foreach ($data as $key => $value) {
				$k = $prefix === '' ? (string) $key : $prefix . '.' . (string) $key;
				$result = array_merge($result, self::flatten_scalar_values($value, $k));
			}

			return $result;
		}
		if (is_scalar($data)) {
			$result[$prefix] = (string) $data;
		}

		return $result;
	}
}
