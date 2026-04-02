<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Options and validation for mp-marked-products-receipt.
 *
 * Optional overrides via wp-config.php constants (deploy / emergency):
 * - MP_MPR_COMMON_ENABLED (bool)
 * - MP_MPR_DEBUG (bool)
 * - MP_MPR_YK_ENABLED, MP_MPR_YK_SANDBOX, MP_MPR_YK_SHOP_ID, MP_MPR_YK_SECRET_KEY
 * - MP_MPR_RB_ENABLED, MP_MPR_RB_SANDBOX, MP_MPR_RB_LOGIN, MP_MPR_RB_PASSWORD1
 */
final class MP_Marked_Products_Receipt_Settings {
	public const OPTION_COMMON_ENABLED = 'mp_mpr_common_enabled';
	public const OPTION_DEBUG = 'mp_mpr_debug';

	public const OPTION_YK_ENABLED = 'mp_mpr_yk_enabled';
	public const OPTION_YK_SANDBOX = 'mp_mpr_yk_sandbox';
	public const OPTION_YK_SHOP_ID = 'mp_mpr_yk_shop_id';
	public const OPTION_YK_SECRET_KEY = 'mp_mpr_yk_secret_key';
	public const OPTION_YK_DEFAULT_PAYMENT_MODE = 'mp_mpr_yk_default_payment_mode';
	public const OPTION_YK_DEFAULT_PAYMENT_SUBJECT = 'mp_mpr_yk_default_payment_subject';
	public const OPTION_YK_RULES = 'mp_mpr_yk_rules';
	public const OPTION_YK_SKIP_WITHOUT_CIS = 'mp_mpr_yk_skip_without_cis';

	public const OPTION_RB_ENABLED = 'mp_mpr_rb_enabled';
	public const OPTION_RB_SANDBOX = 'mp_mpr_rb_sandbox';
	public const OPTION_RB_LOGIN = 'mp_mpr_rb_login';
	public const OPTION_RB_PASSWORD1 = 'mp_mpr_rb_password1';
	public const OPTION_RB_DEFAULT_PAYMENT_MODE = 'mp_mpr_rb_default_payment_mode';
	public const OPTION_RB_DEFAULT_PAYMENT_SUBJECT = 'mp_mpr_rb_default_payment_subject';
	public const OPTION_RB_RULES = 'mp_mpr_rb_rules';
	public const OPTION_RB_SKIP_WITHOUT_CIS = 'mp_mpr_rb_skip_without_cis';

	public const OPTION_MARKING_SOURCE = 'mp_mpr_marking_source';
	public const OPTION_MARKING_META_KEY = 'mp_mpr_marking_meta_key';
	public const OPTION_MARKING_TAXONOMY = 'mp_mpr_marking_taxonomy';
	public const OPTION_MARKING_TERM_IDS = 'mp_mpr_marking_term_ids';
	public const OPTION_MARKING_CATEGORY_IDS = 'mp_mpr_marking_category_ids';
	public const OPTION_REQUIRE_CIS_ORDER_ITEM = 'mp_mpr_require_cis_in_order_item_meta';
	public const OPTION_ORDER_ITEM_CIS_META_KEY = 'mp_mpr_order_item_cis_meta_key';

	/**
	 * @return bool
	 */
	public static function is_common_enabled(): bool {
		if (defined('MP_MPR_COMMON_ENABLED')) {
			return (bool) MP_MPR_COMMON_ENABLED;
		}

		return (bool) get_option(self::OPTION_COMMON_ENABLED, false);
	}

	/**
	 * @return bool
	 */
	public static function is_debug(): bool {
		if (defined('MP_MPR_DEBUG')) {
			return (bool) MP_MPR_DEBUG;
		}

		return (bool) get_option(self::OPTION_DEBUG, false);
	}

	/**
	 * @return bool
	 */
	public static function is_yk_enabled(): bool {
		if (!self::is_common_enabled()) {
			return false;
		}
		if (defined('MP_MPR_YK_ENABLED')) {
			return (bool) MP_MPR_YK_ENABLED;
		}

		return (bool) get_option(self::OPTION_YK_ENABLED, false);
	}

	/**
	 * @return bool
	 */
	public static function is_yk_sandbox(): bool {
		if (defined('MP_MPR_YK_SANDBOX')) {
			return (bool) MP_MPR_YK_SANDBOX;
		}

		return (bool) get_option(self::OPTION_YK_SANDBOX, false);
	}

	/**
	 * @return string
	 */
	public static function get_yk_shop_id(): string {
		if (defined('MP_MPR_YK_SHOP_ID')) {
			return trim((string) MP_MPR_YK_SHOP_ID);
		}

		$val = get_option(self::OPTION_YK_SHOP_ID, '');
		return is_string($val) ? trim($val) : '';
	}

	/**
	 * @return string
	 */
	public static function get_yk_secret_key(): string {
		if (defined('MP_MPR_YK_SECRET_KEY')) {
			return trim((string) MP_MPR_YK_SECRET_KEY);
		}

		$val = get_option(self::OPTION_YK_SECRET_KEY, '');
		return is_string($val) ? trim($val) : '';
	}

	/**
	 * @return string
	 */
	public static function get_yk_api_base_url(): string {
		return self::is_yk_sandbox()
			? 'https://api-preprod.yookassa.ru/v3/receipts'
			: 'https://api.yookassa.ru/v3/receipts';
	}

	/**
	 * @return bool
	 */
	public static function is_rb_enabled(): bool {
		if (!self::is_common_enabled()) {
			return false;
		}
		if (defined('MP_MPR_RB_ENABLED')) {
			return (bool) MP_MPR_RB_ENABLED;
		}

		return (bool) get_option(self::OPTION_RB_ENABLED, false);
	}

	/**
	 * @return bool
	 */
	public static function is_rb_sandbox(): bool {
		if (defined('MP_MPR_RB_SANDBOX')) {
			return (bool) MP_MPR_RB_SANDBOX;
		}

		return (bool) get_option(self::OPTION_RB_SANDBOX, false);
	}

	/**
	 * @return string
	 */
	public static function get_rb_login(): string {
		if (defined('MP_MPR_RB_LOGIN')) {
			return trim((string) MP_MPR_RB_LOGIN);
		}

		return trim((string) get_option(self::OPTION_RB_LOGIN, ''));
	}

	/**
	 * @return string
	 */
	public static function get_rb_password1(): string {
		if (defined('MP_MPR_RB_PASSWORD1')) {
			return trim((string) MP_MPR_RB_PASSWORD1);
		}

		return trim((string) get_option(self::OPTION_RB_PASSWORD1, ''));
	}

	/**
	 * Meta keys used by Robokassa / WooCommerce plugins to store transaction id (fallback search in OrderLinks_RB).
	 *
	 * @return array<int,string>
	 */
	public static function get_rb_source_meta_keys(): array {
		return [
			'_transaction_id',
			'transaction_id',
			'robokassa_invoice_id',
			'robokassa_payment_id',
			'robokassa_transaction_id',
			'robokassa_reference',
			'rbk_payment_id',
			'payment_id',
		];
	}

	/**
	 * Allowed receipt item payment_mode values (YooKassa; Robokassa uses same string list in this project).
	 *
	 * @return array<int,string>
	 */
	public static function allowed_payment_modes(): array {
		return [
			'full_payment',
			'full_prepayment',
			'advance',
			'partial_payment',
			'partial_prepayment',
			'credit',
			'credit_payment',
		];
	}

	/**
	 * Allowed receipt item payment_subject / payment_object values.
	 *
	 * @return array<int,string>
	 */
	public static function allowed_payment_subjects(): array {
		return [
			'commodity',
			'excise',
			'job',
			'service',
			'payment',
			'another',
		];
	}

	/**
	 * @return string
	 */
	public static function get_yk_default_payment_mode(): string {
		$value = trim((string) get_option(self::OPTION_YK_DEFAULT_PAYMENT_MODE, 'full_payment'));
		if (!in_array($value, self::allowed_payment_modes(), true)) {
			return 'full_payment';
		}
		return $value;
	}

	/**
	 * @return string
	 */
	public static function get_yk_default_payment_subject(): string {
		$value = trim((string) get_option(self::OPTION_YK_DEFAULT_PAYMENT_SUBJECT, 'commodity'));
		if (!in_array($value, self::allowed_payment_subjects(), true)) {
			return 'commodity';
		}
		return $value;
	}

	/**
	 * @return string
	 */
	public static function get_rb_default_payment_mode(): string {
		$value = trim((string) get_option(self::OPTION_RB_DEFAULT_PAYMENT_MODE, 'full_payment'));
		if (!in_array($value, self::allowed_payment_modes(), true)) {
			return 'full_payment';
		}
		return $value;
	}

	/**
	 * @return string
	 */
	public static function get_rb_default_payment_subject(): string {
		$value = trim((string) get_option(self::OPTION_RB_DEFAULT_PAYMENT_SUBJECT, 'commodity'));
		if (!in_array($value, self::allowed_payment_subjects(), true)) {
			return 'commodity';
		}
		return $value;
	}

	/**
	 * @return bool
	 */
	public static function is_yk_skip_without_cis(): bool {
		return (bool) get_option(self::OPTION_YK_SKIP_WITHOUT_CIS, false);
	}

	/**
	 * @return bool
	 */
	public static function is_rb_skip_without_cis(): bool {
		return (bool) get_option(self::OPTION_RB_SKIP_WITHOUT_CIS, false);
	}

	/**
	 * @return array<int,string>
	 */
	public static function allowed_marking_sources(): array {
		return [ 'meta', 'taxonomy', 'category', 'filter_only' ];
	}

	/**
	 * @return string
	 */
	public static function get_marking_source(): string {
		$value = trim((string) get_option(self::OPTION_MARKING_SOURCE, 'meta'));
		if (!in_array($value, self::allowed_marking_sources(), true)) {
			return 'meta';
		}
		return $value;
	}

	/**
	 * @return string
	 */
	public static function get_marking_meta_key(): string {
		return trim((string) get_option(self::OPTION_MARKING_META_KEY, ''));
	}

	/**
	 * @return string
	 */
	public static function get_marking_taxonomy(): string {
		return trim((string) get_option(self::OPTION_MARKING_TAXONOMY, ''));
	}

	/**
	 * @return array<int,int>
	 */
	public static function get_marking_term_ids(): array {
		$raw = get_option(self::OPTION_MARKING_TERM_IDS, []);
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach ($raw as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$out[] = $id;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @return array<int,int>
	 */
	public static function get_marking_category_ids(): array {
		$raw = get_option(self::OPTION_MARKING_CATEGORY_IDS, []);
		if (!is_array($raw)) {
			return [];
		}
		$out = [];
		foreach ($raw as $id) {
			$id = (int) $id;
			if ($id > 0) {
				$out[] = $id;
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * @return bool
	 */
	public static function is_require_cis_in_order_item_meta(): bool {
		return (bool) get_option(self::OPTION_REQUIRE_CIS_ORDER_ITEM, false);
	}

	/**
	 * @return string
	 */
	public static function get_order_item_cis_meta_key(): string {
		return trim((string) get_option(self::OPTION_ORDER_ITEM_CIS_META_KEY, ''));
	}

	/**
	 * Normalized config for ProductMarker (implementation uses this in a later step).
	 *
	 * @return array<string,mixed>
	 */
	public static function get_marking_rules_config(): array {
		return [
			'source' => self::get_marking_source(),
			'meta_key' => self::get_marking_meta_key(),
			'taxonomy' => self::get_marking_taxonomy(),
			'term_ids' => self::get_marking_term_ids(),
			'category_ids' => self::get_marking_category_ids(),
			'require_cis_in_order_item' => self::is_require_cis_in_order_item_meta(),
			'order_item_cis_meta_key' => self::get_order_item_cis_meta_key(),
			'skip_without_cis_yk' => self::is_yk_skip_without_cis(),
			'skip_without_cis_rb' => self::is_rb_skip_without_cis(),
		];
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_yk_rules(): array {
		return self::normalize_category_rules(get_option(self::OPTION_YK_RULES, []));
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_rb_rules(): array {
		return self::normalize_category_rules(get_option(self::OPTION_RB_RULES, []));
	}

	/**
	 * @param mixed $rules
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_category_rules($rules): array {
		if (!is_array($rules)) {
			return [];
		}

		$modes = self::allowed_payment_modes();
		$subjects = self::allowed_payment_subjects();
		$normalized = [];

		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}

			$enabled = !empty($rule['enabled']);
			$priority = isset($rule['priority']) ? (int) $rule['priority'] : 100;
			$payment_mode = isset($rule['payment_mode']) ? trim((string) $rule['payment_mode']) : '';
			$payment_subject = isset($rule['payment_subject']) ? trim((string) $rule['payment_subject']) : '';

			if (!in_array($payment_mode, $modes, true) || !in_array($payment_subject, $subjects, true)) {
				continue;
			}

			$category_ids = [];
			if (isset($rule['category_ids']) && is_array($rule['category_ids'])) {
				foreach ($rule['category_ids'] as $cat_id) {
					$cat_id = (int) $cat_id;
					if ($cat_id > 0) {
						$category_ids[] = $cat_id;
					}
				}
			}
			$category_ids = array_values(array_unique($category_ids));
			if (empty($category_ids)) {
				continue;
			}

			$normalized[] = [
				'enabled' => $enabled,
				'priority' => $priority,
				'category_ids' => $category_ids,
				'payment_mode' => $payment_mode,
				'payment_subject' => $payment_subject,
			];
		}

		usort($normalized, static function ($a, $b) {
			return ((int) $b['priority']) <=> ((int) $a['priority']);
		});

		return $normalized;
	}

	/**
	 * Validation for YooKassa API calls (credentials + defaults).
	 *
	 * @return array<int,string>
	 */
	public static function validate_yk_for_api(): array {
		$errors = [];

		if (!self::is_common_enabled()) {
			$errors[] = __('Плагин отключён.', 'mp-marked-products-receipt');
			return $errors;
		}

		if (!self::is_yk_enabled()) {
			$errors[] = __('Интеграция ЮKassa отключена.', 'mp-marked-products-receipt');
			return $errors;
		}

		if (self::get_yk_shop_id() === '') {
			$errors[] = __('Не задан YooKassa shop_id (mp_mpr_yk_shop_id или MP_MPR_YK_SHOP_ID).', 'mp-marked-products-receipt');
		}
		if (self::get_yk_secret_key() === '') {
			$errors[] = __('Не задан YooKassa secret_key (mp_mpr_yk_secret_key или MP_MPR_YK_SECRET_KEY).', 'mp-marked-products-receipt');
		}

		if (!in_array(self::get_yk_default_payment_mode(), self::allowed_payment_modes(), true)) {
			$errors[] = __('Некорректный payment_mode ЮKassa по умолчанию.', 'mp-marked-products-receipt');
		}
		if (!in_array(self::get_yk_default_payment_subject(), self::allowed_payment_subjects(), true)) {
			$errors[] = __('Некорректный payment_subject ЮKassa по умолчанию.', 'mp-marked-products-receipt');
		}

		return $errors;
	}

	/**
	 * Validation for Robokassa API calls.
	 *
	 * @return array<int,string>
	 */
	public static function validate_rb_for_api(): array {
		$errors = [];

		if (!self::is_common_enabled()) {
			$errors[] = __('Плагин отключён.', 'mp-marked-products-receipt');
			return $errors;
		}

		if (!self::is_rb_enabled()) {
			$errors[] = __('Интеграция Robokassa отключена.', 'mp-marked-products-receipt');
			return $errors;
		}

		if (self::get_rb_login() === '') {
			$errors[] = __('Не задан Robokassa login (mp_mpr_rb_login или MP_MPR_RB_LOGIN).', 'mp-marked-products-receipt');
		}
		if (self::get_rb_password1() === '') {
			$errors[] = __('Не задан Robokassa password1 (mp_mpr_rb_password1 или MP_MPR_RB_PASSWORD1).', 'mp-marked-products-receipt');
		}

		if (!in_array(self::get_rb_default_payment_mode(), self::allowed_payment_modes(), true)) {
			$errors[] = __('Некорректный payment_mode Robokassa по умолчанию.', 'mp-marked-products-receipt');
		}
		if (!in_array(self::get_rb_default_payment_subject(), self::allowed_payment_subjects(), true)) {
			$errors[] = __('Некорректный payment_subject Robokassa по умолчанию.', 'mp-marked-products-receipt');
		}

		return $errors;
	}

	/**
	 * Validates marking detection configuration when the plugin is enabled.
	 *
	 * @return array<int,string>
	 */
	public static function validate_marking_detection(): array {
		$errors = [];

		if (!self::is_common_enabled()) {
			return $errors;
		}

		$source = self::get_marking_source();
		if (!in_array($source, self::allowed_marking_sources(), true)) {
			$errors[] = __('Некорректный источник маркировки.', 'mp-marked-products-receipt');
			return $errors;
		}

		if ($source === 'meta' && self::get_marking_meta_key() === '') {
			$errors[] = __('Режим «meta», но не задан meta key товара.', 'mp-marked-products-receipt');
		}

		if ($source === 'taxonomy') {
			if (self::get_marking_taxonomy() === '') {
				$errors[] = __('Режим «taxonomy», но пустой slug таксономии.', 'mp-marked-products-receipt');
			}
			if (empty(self::get_marking_term_ids())) {
				$errors[] = __('Режим «taxonomy», но список терминов пуст.', 'mp-marked-products-receipt');
			}
		}

		if ($source === 'category' && empty(self::get_marking_category_ids())) {
			$errors[] = __('Режим «category», но не выбраны категории.', 'mp-marked-products-receipt');
		}

		if (self::is_require_cis_in_order_item_meta() && self::get_order_item_cis_meta_key() === '') {
			$errors[] = __('Требуется КИЗ в позиции заказа, но не задан ключ мета позиции.', 'mp-marked-products-receipt');
		}

		return $errors;
	}
}
