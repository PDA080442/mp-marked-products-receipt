<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Settings page: WooCommerce → Marked receipts (tabs: YooKassa, Robokassa).
 */
final class MP_Marked_Products_Receipt_Admin {
	private const PAGE_SLUG = 'mp-marked-products-receipt';
	private const OPTION_GROUP = 'mp_mpr_settings';

	private const NONCE_YK_API_CHECK = 'mp_mpr_yk_api_check';
	private const NONCE_RB_API_CHECK = 'mp_mpr_rb_api_check';
	private const NONCE_INSPECT_ORDER_YK = 'mp_mpr_inspect_order_yk';
	private const NONCE_INSPECT_ORDER_RB = 'mp_mpr_inspect_order_rb';

	/** §26: максимум позиций в preview инспектора заказа. */
	private const INSPECT_PREVIEW_MAX_ITEMS = 50;

	/** @var bool */
	private static $settings_registered = false;

	public static function init(): void {
		add_action('admin_menu', [self::class, 'register_menu']);
		add_action('admin_init', [self::class, 'register_settings']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
		add_filter('wp_redirect', [self::class, 'filter_redirect_after_options_save'], 10, 2);
		add_action('wp_ajax_mp_mpr_inspect_product', [self::class, 'ajax_inspect_product']);
	}

	public static function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__('MP Marked Products Receipt', 'mp-marked-products-receipt'),
			__('Marked receipts', 'mp-marked-products-receipt'),
			'manage_woocommerce',
			self::PAGE_SLUG,
			[self::class, 'render_page']
		);
	}

	/**
	 * §25: Settings API — один раз на `admin_init` (guard ниже), группа `mp_mpr_settings`
	 * (в плане фигурировало имя `mp_mpr_common_group`; фактическое имя должно совпадать с `settings_fields()`).
	 * Правила: `type => array`, `sanitize_rules_yk` / `sanitize_rules_rb`. Секреты: `sanitize_yk_secret_key` /
	 * `sanitize_rb_password1` не затирают значение при пустой строке в POST.
	 */
	public static function register_settings(): void {
		if (self::$settings_registered) {
			return;
		}
		self::$settings_registered = true;

		$reg = static function (string $option, array $args): void {
			register_setting(self::OPTION_GROUP, $option, $args);
		};

		$reg(MP_Marked_Products_Receipt_Settings::OPTION_COMMON_ENABLED, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_DEBUG, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_DELETE_LOGS_ON_UNINSTALL, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);

		$reg(MP_Marked_Products_Receipt_Settings::OPTION_MARKING_SOURCE, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_marking_source'],
			'default' => 'meta',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_MARKING_META_KEY, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_text'],
			'default' => '',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_MARKING_TAXONOMY, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_text'],
			'default' => '',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_MARKING_TERM_IDS, [
			'type' => 'array',
			'sanitize_callback' => [self::class, 'sanitize_int_list'],
			'default' => [],
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_MARKING_CATEGORY_IDS, [
			'type' => 'array',
			'sanitize_callback' => [self::class, 'sanitize_int_list'],
			'default' => [],
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_REQUIRE_CIS_ORDER_ITEM, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_ORDER_ITEM_CIS_META_KEY, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_text'],
			'default' => '',
		]);

		$reg(MP_Marked_Products_Receipt_Settings::OPTION_YK_ENABLED, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_YK_SANDBOX, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_YK_SHOP_ID, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_text'],
			'default' => '',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_YK_SECRET_KEY, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_yk_secret_key'],
			'default' => '',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_YK_DEFAULT_PAYMENT_MODE, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_payment_mode'],
			'default' => 'full_payment',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_YK_DEFAULT_PAYMENT_SUBJECT, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_payment_subject'],
			'default' => 'commodity',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_YK_RULES, [
			'type' => 'array',
			'sanitize_callback' => [self::class, 'sanitize_rules_yk'],
			'default' => [],
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_YK_SKIP_WITHOUT_CIS, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);

		$reg(MP_Marked_Products_Receipt_Settings::OPTION_RB_ENABLED, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_RB_SANDBOX, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_RB_LOGIN, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_text'],
			'default' => '',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_RB_PASSWORD1, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_rb_password1'],
			'default' => '',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_RB_DEFAULT_PAYMENT_MODE, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_payment_mode'],
			'default' => 'full_payment',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_RB_DEFAULT_PAYMENT_SUBJECT, [
			'type' => 'string',
			'sanitize_callback' => [self::class, 'sanitize_payment_subject'],
			'default' => 'commodity',
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_RB_RULES, [
			'type' => 'array',
			'sanitize_callback' => [self::class, 'sanitize_rules_rb'],
			'default' => [],
		]);
		$reg(MP_Marked_Products_Receipt_Settings::OPTION_RB_SKIP_WITHOUT_CIS, [
			'type' => 'boolean',
			'sanitize_callback' => [self::class, 'sanitize_checkbox'],
			'default' => false,
		]);
	}

	/**
	 * @param mixed $value
	 * @return bool
	 */
	public static function sanitize_checkbox($value): bool {
		return (bool) $value;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_text($value): string {
		return trim((string) $value);
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_marking_source($value): string {
		$value = trim((string) $value);
		if (!in_array($value, MP_Marked_Products_Receipt_Settings::allowed_marking_sources(), true)) {
			return 'meta';
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 * @return array<int,int>
	 */
	public static function sanitize_int_list($value): array {
		if (is_array($value)) {
			$out = [];
			foreach ($value as $id) {
				$id = (int) $id;
				if ($id > 0) {
					$out[] = $id;
				}
			}

			return array_values(array_unique($out));
		}
		$s = trim((string) $value);
		if ($s === '') {
			return [];
		}
		$parts = preg_split('/[\s,;]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($parts)) {
			return [];
		}
		$out = [];
		foreach ($parts as $p) {
			$id = (int) $p;
			if ($id > 0) {
				$out[] = $id;
			}
		}

		return array_values(array_unique($out));
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_yk_secret_key($value): string {
		$value = trim((string) $value);
		if ($value === '') {
			return (string) get_option(MP_Marked_Products_Receipt_Settings::OPTION_YK_SECRET_KEY, '');
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_rb_password1($value): string {
		$value = trim((string) $value);
		if ($value === '') {
			return (string) get_option(MP_Marked_Products_Receipt_Settings::OPTION_RB_PASSWORD1, '');
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_payment_mode($value): string {
		$value = trim((string) $value);
		if (!in_array($value, MP_Marked_Products_Receipt_Settings::allowed_payment_modes(), true)) {
			return 'full_payment';
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public static function sanitize_payment_subject($value): string {
		$value = trim((string) $value);
		if (!in_array($value, MP_Marked_Products_Receipt_Settings::allowed_payment_subjects(), true)) {
			return 'commodity';
		}

		return $value;
	}

	/**
	 * @param mixed $rules
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_rules_yk($rules): array {
		return self::sanitize_rules_generic($rules);
	}

	/**
	 * @param mixed $rules
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize_rules_rb($rules): array {
		return self::sanitize_rules_generic($rules);
	}

	/**
	 * @param mixed $rules
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_rules_generic($rules): array {
		if (!is_array($rules)) {
			return [];
		}

		$modes = MP_Marked_Products_Receipt_Settings::allowed_payment_modes();
		$subjects = MP_Marked_Products_Receipt_Settings::allowed_payment_subjects();
		$valid_category_ids = self::get_valid_product_category_ids();
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
			if (!empty($rule['category_ids']) && is_array($rule['category_ids'])) {
				foreach ($rule['category_ids'] as $cat_id) {
					$cat_id = (int) $cat_id;
					if ($cat_id > 0 && isset($valid_category_ids[$cat_id])) {
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
	 * @return array<int,bool>
	 */
	private static function get_valid_product_category_ids(): array {
		$result = [];
		$terms = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
			'fields' => 'ids',
		]);
		if (is_wp_error($terms) || !is_array($terms)) {
			return $result;
		}
		foreach ($terms as $term_id) {
			$term_id = (int) $term_id;
			if ($term_id > 0) {
				$result[$term_id] = true;
			}
		}

		return $result;
	}

	/**
	 * @param string $location
	 * @param int $status
	 * @return string
	 */
	public static function filter_redirect_after_options_save($location, $status) {
		if (!isset($_POST['option_page']) || $_POST['option_page'] !== self::OPTION_GROUP) {
			return $location;
		}
		if (!isset($_POST['mp_mpr_return_tab'])) {
			return $location;
		}
		$tab = sanitize_key((string) wp_unslash($_POST['mp_mpr_return_tab']));
		if (!in_array($tab, ['yookassa', 'robokassa'], true)) {
			$tab = 'yookassa';
		}

		return admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab . '&settings-updated=true');
	}

	/**
	 * @param string $hook
	 * @return void
	 */
	public static function enqueue_assets(string $hook): void {
		if ($hook !== 'woocommerce_page_' . self::PAGE_SLUG) {
			return;
		}

		$plugin_file = dirname(__DIR__) . '/mp-marked-products-receipt.php';
		wp_enqueue_style(
			'mp-mpr-admin',
			plugins_url('assets/admin/css/mp-mpr-admin.css', $plugin_file),
			[],
			MP_Marked_Products_Receipt_Plugin::VERSION
		);
		wp_enqueue_script(
			'mp-mpr-admin',
			plugins_url('assets/admin/js/mp-mpr-admin.js', $plugin_file),
			['jquery'],
			MP_Marked_Products_Receipt_Plugin::VERSION,
			true
		);
		wp_localize_script(
			'mp-mpr-admin',
			'mpMprAdmin',
			[
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonceInspectProduct' => wp_create_nonce('mp_mpr_inspect_product'),
				'i18nInspectError' => __('Не удалось выполнить запрос.', 'mp-marked-products-receipt'),
			]
		);
	}

	public static function render_page(): void {
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Access denied', 'mp-marked-products-receipt'));
		}

		$tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'yookassa';
		if (!in_array($tab, ['yookassa', 'robokassa'], true)) {
			$tab = 'yookassa';
		}

		$yk_api_result = null;
		if (isset($_POST['mp_mpr_yk_api_check'])) {
			check_admin_referer(self::NONCE_YK_API_CHECK);
			$yk_api_result = MP_Marked_Products_Receipt_ApiClient_YK::ping();
			MP_Marked_Products_Receipt_Logger::log('INFO', 0, 'api_check_yk', [
				'status_code' => $yk_api_result['status_code'],
				'ok' => $yk_api_result['ok'],
			]);
		}

		$rb_api_result = null;
		if (isset($_POST['mp_mpr_rb_api_check'])) {
			check_admin_referer(self::NONCE_RB_API_CHECK);
			$rb_api_result = MP_Marked_Products_Receipt_ApiClient_RB::ping_reachability();
			MP_Marked_Products_Receipt_Logger::log('INFO', 0, 'api_check_rb', [
				'status_code' => $rb_api_result['status_code'],
				'ok' => $rb_api_result['ok'],
			]);
		}

		$inspect_yk_order_id = 0;
		$inspect_yk_result = null;
		if (isset($_POST['mp_mpr_inspect_order_yk_submit'])) {
			check_admin_referer(self::NONCE_INSPECT_ORDER_YK);
			$inspect_yk_order_id = isset($_POST['mp_mpr_inspect_order_yk']) ? (int) $_POST['mp_mpr_inspect_order_yk'] : 0;
			if ($inspect_yk_order_id > 0) {
				$inspect_yk_result = self::inspect_order_yk($inspect_yk_order_id);
			}
		}

		$inspect_rb_order_id = 0;
		$inspect_rb_result = null;
		if (isset($_POST['mp_mpr_inspect_order_rb_submit'])) {
			check_admin_referer(self::NONCE_INSPECT_ORDER_RB);
			$inspect_rb_order_id = isset($_POST['mp_mpr_inspect_order_rb']) ? (int) $_POST['mp_mpr_inspect_order_rb'] : 0;
			if ($inspect_rb_order_id > 0) {
				$inspect_rb_result = self::inspect_order_rb($inspect_rb_order_id);
			}
		}

		$log_tail = self::read_log_tail(40);
		$preflight_yk = self::build_preflight_yk();
		$preflight_rb = self::build_preflight_rb();

		$base_url = admin_url('admin.php?page=' . self::PAGE_SLUG);

		?>
		<div class="wrap mp-mpr-wrap">
			<h1><?php echo esc_html__('MP Marked Products Receipt', 'mp-marked-products-receipt'); ?></h1>
			<p class="mp-mpr-muted"><?php echo esc_html__('Отдельные чеки для маркированных товаров (ЮKassa и/или Robokassa).', 'mp-marked-products-receipt'); ?></p>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields(self::OPTION_GROUP); ?>
				<input type="hidden" name="mp_mpr_return_tab" value="<?php echo esc_attr($tab); ?>" />

				<?php self::render_common_section(); ?>

				<h2 class="nav-tab-wrapper wp-clearfix" style="margin-top:18px;">
					<a href="<?php echo esc_url(add_query_arg('tab', 'yookassa', $base_url)); ?>" class="nav-tab <?php echo $tab === 'yookassa' ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html__('ЮKassa', 'mp-marked-products-receipt'); ?>
					</a>
					<a href="<?php echo esc_url(add_query_arg('tab', 'robokassa', $base_url)); ?>" class="nav-tab <?php echo $tab === 'robokassa' ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html__('Robokassa', 'mp-marked-products-receipt'); ?>
					</a>
				</h2>

				<div class="mp-mpr-section" id="mp-mpr-tab-yookassa" style="<?php echo $tab === 'yookassa' ? '' : 'display:none;'; ?>">
					<?php self::render_tab_yookassa(); ?>
				</div>
				<div class="mp-mpr-section" id="mp-mpr-tab-robokassa" style="<?php echo $tab === 'robokassa' ? '' : 'display:none;'; ?>">
					<?php self::render_tab_robokassa(); ?>
				</div>

				<p class="submit">
					<?php submit_button(__('Сохранить настройки', 'mp-marked-products-receipt'), 'primary', 'submit', false); ?>
				</p>
			</form>

			<?php
			if ($tab === 'yookassa') {
				self::render_diagnostics_yk($yk_api_result, $inspect_yk_order_id, $inspect_yk_result, $preflight_yk);
			} else {
				self::render_diagnostics_rb($rb_api_result, $inspect_rb_order_id, $inspect_rb_result, $preflight_rb);
			}
			?>

			<div class="mp-mpr-section mp-mpr-log-tail">
				<h2><?php echo esc_html__('Хвост лога', 'mp-marked-products-receipt'); ?></h2>
				<pre class="mp-mpr-log-pre"><?php echo esc_html($log_tail); ?></pre>
				<p class="mp-mpr-muted"><?php echo esc_html__('Обновите страницу, чтобы перечитать файл.', 'mp-marked-products-receipt'); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	private static function render_common_section(): void {
		$enabled = MP_Marked_Products_Receipt_Settings::is_common_enabled();
		$debug = MP_Marked_Products_Receipt_Settings::is_debug();
		$delete_logs_uninstall = MP_Marked_Products_Receipt_Settings::should_delete_logs_on_uninstall();
		$src = MP_Marked_Products_Receipt_Settings::get_marking_source();
		$meta_key = MP_Marked_Products_Receipt_Settings::get_marking_meta_key();
		$taxonomy = MP_Marked_Products_Receipt_Settings::get_marking_taxonomy();
		$term_ids = MP_Marked_Products_Receipt_Settings::get_marking_term_ids();
		$cat_ids = MP_Marked_Products_Receipt_Settings::get_marking_category_ids();
		$req_cis = MP_Marked_Products_Receipt_Settings::is_require_cis_in_order_item_meta();
		$cis_line_key = MP_Marked_Products_Receipt_Settings::get_order_item_cis_meta_key();
		$skip_yk = MP_Marked_Products_Receipt_Settings::is_yk_skip_without_cis();
		$skip_rb = MP_Marked_Products_Receipt_Settings::is_rb_skip_without_cis();

		$categories = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
		]);
		if (!is_array($categories)) {
			$categories = [];
		}

		$o = MP_Marked_Products_Receipt_Settings::class;

		?>
		<div class="mp-mpr-section">
			<h2><?php echo esc_html__('Общие настройки', 'mp-marked-products-receipt'); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__('Плагин включён', 'mp-marked-products-receipt'); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr($o::OPTION_COMMON_ENABLED); ?>" value="0" />
						<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_COMMON_ENABLED); ?>" value="1" <?php checked($enabled); ?> /> <?php echo esc_html__('Да', 'mp-marked-products-receipt'); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Debug', 'mp-marked-products-receipt'); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr($o::OPTION_DEBUG); ?>" value="0" />
						<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_DEBUG); ?>" value="1" <?php checked($debug); ?> /> <?php echo esc_html__('Писать DEBUG в лог', 'mp-marked-products-receipt'); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Удаление логов', 'mp-marked-products-receipt'); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr($o::OPTION_DELETE_LOGS_ON_UNINSTALL); ?>" value="0" />
						<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_DELETE_LOGS_ON_UNINSTALL); ?>" value="1" <?php checked($delete_logs_uninstall); ?> /> <?php echo esc_html__('При удалении плагина удалять файлы логов из каталога uploads', 'mp-marked-products-receipt'); ?></label>
						<p class="description"><?php echo esc_html__('Мета заказов и данные WooCommerce не затрагиваются.', 'mp-marked-products-receipt'); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Источник признака маркировки', 'mp-marked-products-receipt'); ?></th>
					<td>
						<select name="<?php echo esc_attr($o::OPTION_MARKING_SOURCE); ?>" id="mp_mpr_marking_source">
							<?php foreach (MP_Marked_Products_Receipt_Settings::allowed_marking_sources() as $s) : ?>
								<option value="<?php echo esc_attr($s); ?>" <?php selected($src, $s); ?>><?php echo esc_html($s); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr class="mp-mpr-if-meta">
					<th scope="row"><?php echo esc_html__('Meta key товара', 'mp-marked-products-receipt'); ?></th>
					<td><input class="regular-text" type="text" name="<?php echo esc_attr($o::OPTION_MARKING_META_KEY); ?>" value="<?php echo esc_attr($meta_key); ?>"></td>
				</tr>
				<tr class="mp-mpr-if-category">
					<th scope="row"><?php echo esc_html__('Категории (product_cat)', 'mp-marked-products-receipt'); ?></th>
					<td>
						<select multiple size="8" style="min-width:320px;" name="<?php echo esc_attr($o::OPTION_MARKING_CATEGORY_IDS); ?>[]">
							<?php foreach ($categories as $cat) : ?>
								<option value="<?php echo esc_attr((string) $cat->term_id); ?>" <?php selected(in_array((int) $cat->term_id, $cat_ids, true)); ?>>
									<?php echo esc_html($cat->name . ' (#' . $cat->term_id . ')'); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr class="mp-mpr-if-taxonomy">
					<th scope="row"><?php echo esc_html__('Taxonomy slug', 'mp-marked-products-receipt'); ?></th>
					<td><input class="regular-text" type="text" name="<?php echo esc_attr($o::OPTION_MARKING_TAXONOMY); ?>" value="<?php echo esc_attr($taxonomy); ?>"></td>
				</tr>
				<tr class="mp-mpr-if-taxonomy">
					<th scope="row"><?php echo esc_html__('ID терминов (через запятую)', 'mp-marked-products-receipt'); ?></th>
					<td>
						<input class="large-text" type="text" name="<?php echo esc_attr($o::OPTION_MARKING_TERM_IDS); ?>" value="<?php echo esc_attr(implode(', ', $term_ids)); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Требовать КИЗ в позиции заказа', 'mp-marked-products-receipt'); ?></th>
					<td>
						<input type="hidden" name="<?php echo esc_attr($o::OPTION_REQUIRE_CIS_ORDER_ITEM); ?>" value="0" />
						<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_REQUIRE_CIS_ORDER_ITEM); ?>" value="1" <?php checked($req_cis); ?> /> <?php echo esc_html__('Да', 'mp-marked-products-receipt'); ?></label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Ключ мета позиции заказа (КИЗ)', 'mp-marked-products-receipt'); ?></th>
					<td><input class="regular-text" type="text" name="<?php echo esc_attr($o::OPTION_ORDER_ITEM_CIS_META_KEY); ?>" value="<?php echo esc_attr($cis_line_key); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Пропускать строки без КИЗ', 'mp-marked-products-receipt'); ?></th>
					<td>
						<p>
							<input type="hidden" name="<?php echo esc_attr($o::OPTION_YK_SKIP_WITHOUT_CIS); ?>" value="0" />
							<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_YK_SKIP_WITHOUT_CIS); ?>" value="1" <?php checked($skip_yk); ?> /> <?php echo esc_html__('ЮKassa', 'mp-marked-products-receipt'); ?></label>
						</p>
						<p>
							<input type="hidden" name="<?php echo esc_attr($o::OPTION_RB_SKIP_WITHOUT_CIS); ?>" value="0" />
							<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_RB_SKIP_WITHOUT_CIS); ?>" value="1" <?php checked($skip_rb); ?> /> <?php echo esc_html__('Robokassa', 'mp-marked-products-receipt'); ?></label>
						</p>
					</td>
				</tr>
			</table>

			<h3><?php echo esc_html__('Контрольная проверка товара', 'mp-marked-products-receipt'); ?></h3>
			<p class="mp-mpr-inspect-product">
				<label for="mp_mpr_inspect_product_id"><?php echo esc_html__('ID товара', 'mp-marked-products-receipt'); ?></label>
				<input type="number" min="1" id="mp_mpr_inspect_product_id" class="small-text" value="" />
				<button type="button" class="button" id="mp_mpr_inspect_product_btn"><?php echo esc_html__('Проверить', 'mp-marked-products-receipt'); ?></button>
			</p>
			<pre id="mp_mpr_inspect_product_out" class="mp-mpr-inspect-out" style="display:none;max-width:960px;white-space:pre-wrap;background:#f6f7f7;padding:10px;border:1px solid #c3c4c7;"></pre>

			<p class="mp-mpr-muted"><?php echo esc_html__('Путь к логам:', 'mp-marked-products-receipt'); ?> <code><?php echo esc_html(MP_Marked_Products_Receipt_Logger::get_log_dir()); ?></code></p>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	private static function render_tab_yookassa(): void {
		$o = MP_Marked_Products_Receipt_Settings::class;
		$rules = MP_Marked_Products_Receipt_Settings::get_yk_rules();
		$categories = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
		]);
		if (!is_array($categories)) {
			$categories = [];
		}

		?>
		<h2><?php echo esc_html__('ЮKassa', 'mp-marked-products-receipt'); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__('Включить', 'mp-marked-products-receipt'); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr($o::OPTION_YK_ENABLED); ?>" value="0" />
					<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_YK_ENABLED); ?>" value="1" <?php checked(MP_Marked_Products_Receipt_Settings::is_yk_enabled()); ?> /> <?php echo esc_html__('Да', 'mp-marked-products-receipt'); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__('Sandbox', 'mp-marked-products-receipt'); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr($o::OPTION_YK_SANDBOX); ?>" value="0" />
					<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_YK_SANDBOX); ?>" value="1" <?php checked(MP_Marked_Products_Receipt_Settings::is_yk_sandbox()); ?> /> <?php echo esc_html__('Да', 'mp-marked-products-receipt'); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row">shop_id</th>
				<td><input class="regular-text" type="text" name="<?php echo esc_attr($o::OPTION_YK_SHOP_ID); ?>" value="<?php echo esc_attr(MP_Marked_Products_Receipt_Settings::get_yk_shop_id()); ?>"></td>
			</tr>
			<tr>
				<th scope="row">secret_key</th>
				<td>
					<input class="regular-text" type="password" name="<?php echo esc_attr($o::OPTION_YK_SECRET_KEY); ?>" value="" placeholder="<?php echo esc_attr(MP_Marked_Products_Receipt_Settings::get_yk_secret_key() !== '' ? __('оставьте пустым, чтобы не менять', 'mp-marked-products-receipt') : ''); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row">payment_mode</th>
				<td>
					<select name="<?php echo esc_attr($o::OPTION_YK_DEFAULT_PAYMENT_MODE); ?>">
						<?php foreach (MP_Marked_Products_Receipt_Settings::allowed_payment_modes() as $mode) : ?>
							<option value="<?php echo esc_attr($mode); ?>" <?php selected(MP_Marked_Products_Receipt_Settings::get_yk_default_payment_mode(), $mode); ?>><?php echo esc_html($mode); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">payment_subject</th>
				<td>
					<select name="<?php echo esc_attr($o::OPTION_YK_DEFAULT_PAYMENT_SUBJECT); ?>">
						<?php foreach (MP_Marked_Products_Receipt_Settings::allowed_payment_subjects() as $sub) : ?>
							<option value="<?php echo esc_attr($sub); ?>" <?php selected(MP_Marked_Products_Receipt_Settings::get_yk_default_payment_subject(), $sub); ?>><?php echo esc_html($sub); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<h3><?php echo esc_html__('Правила по категориям', 'mp-marked-products-receipt'); ?></h3>
		<table class="widefat striped" id="mp-mpr-yk-rules-table">
			<thead>
			<tr>
				<th style="width:90px;"><?php echo esc_html__('Вкл', 'mp-marked-products-receipt'); ?></th>
				<th style="width:120px;">Priority</th>
				<th><?php echo esc_html__('Категории', 'mp-marked-products-receipt'); ?></th>
				<th style="width:220px;">payment_mode</th>
				<th style="width:220px;">payment_subject</th>
				<th style="width:80px;"></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($rules as $idx => $rule) : ?>
				<tr>
					<td>
						<input type="hidden" name="<?php echo esc_attr($o::OPTION_YK_RULES . '[' . $idx . '][enabled]'); ?>" value="0">
						<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_YK_RULES . '[' . $idx . '][enabled]'); ?>" value="1" <?php checked(!empty($rule['enabled'])); ?>> <?php echo esc_html__('Да', 'mp-marked-products-receipt'); ?></label>
					</td>
					<td><input type="number" style="width:100px;" name="<?php echo esc_attr($o::OPTION_YK_RULES . '[' . $idx . '][priority]'); ?>" value="<?php echo esc_attr((string) (int) $rule['priority']); ?>"></td>
					<td>
						<select multiple size="5" style="min-width:280px;" name="<?php echo esc_attr($o::OPTION_YK_RULES . '[' . $idx . '][category_ids][]'); ?>">
							<?php foreach ($categories as $cat) : ?>
								<option value="<?php echo esc_attr((string) $cat->term_id); ?>" <?php selected(in_array((int) $cat->term_id, (array) $rule['category_ids'], true)); ?>>
									<?php echo esc_html($cat->name . ' (#' . $cat->term_id . ')'); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<select name="<?php echo esc_attr($o::OPTION_YK_RULES . '[' . $idx . '][payment_mode]'); ?>">
							<?php foreach (MP_Marked_Products_Receipt_Settings::allowed_payment_modes() as $mode) : ?>
								<option value="<?php echo esc_attr($mode); ?>" <?php selected($rule['payment_mode'], $mode); ?>><?php echo esc_html($mode); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<select name="<?php echo esc_attr($o::OPTION_YK_RULES . '[' . $idx . '][payment_subject]'); ?>">
							<?php foreach (MP_Marked_Products_Receipt_Settings::allowed_payment_subjects() as $sub) : ?>
								<option value="<?php echo esc_attr($sub); ?>" <?php selected($rule['payment_subject'], $sub); ?>><?php echo esc_html($sub); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><button type="button" class="button mp-mpr-yk-remove-rule"><?php echo esc_html__('Удалить', 'mp-marked-products-receipt'); ?></button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="mp-mpr-yk-add-rule"><?php echo esc_html__('Добавить правило', 'mp-marked-products-receipt'); ?></button></p>

		<script>
		(function() {
			const table = document.getElementById('mp-mpr-yk-rules-table');
			const addBtn = document.getElementById('mp-mpr-yk-add-rule');
			if (!table || !addBtn) return;
			const tbody = table.querySelector('tbody');
			const categoriesHtml = <?php echo wp_json_encode(implode('', array_map(static function ($cat) {
				return '<option value="' . (int) $cat->term_id . '">' . esc_html($cat->name . ' (#' . $cat->term_id . ')') . '</option>';
			}, $categories))); ?>;
			const modes = <?php echo wp_json_encode(MP_Marked_Products_Receipt_Settings::allowed_payment_modes()); ?>;
			const subjects = <?php echo wp_json_encode(MP_Marked_Products_Receipt_Settings::allowed_payment_subjects()); ?>;
			const optionName = <?php echo wp_json_encode($o::OPTION_YK_RULES); ?>;

			function buildOptions(items, selected) {
				return items.map((v) => '<option value="' + v + '"' + (v === selected ? ' selected' : '') + '>' + v + '</option>').join('');
			}

			function rowHtml(idx) {
				return '' +
					'<tr>' +
					'<td><input type="hidden" name="' + optionName + '[' + idx + '][enabled]" value="0"><label><input type="checkbox" name="' + optionName + '[' + idx + '][enabled]" value="1" checked> <?php echo esc_js(__('Да', 'mp-marked-products-receipt')); ?></label></td>' +
					'<td><input type="number" name="' + optionName + '[' + idx + '][priority]" value="100" style="width:100px;"></td>' +
					'<td><select multiple size="5" name="' + optionName + '[' + idx + '][category_ids][]" style="min-width:280px;">' + categoriesHtml + '</select></td>' +
					'<td><select name="' + optionName + '[' + idx + '][payment_mode]">' + buildOptions(modes, 'full_payment') + '</select></td>' +
					'<td><select name="' + optionName + '[' + idx + '][payment_subject]">' + buildOptions(subjects, 'commodity') + '</select></td>' +
					'<td><button type="button" class="button mp-mpr-yk-remove-rule"><?php echo esc_js(__('Удалить', 'mp-marked-products-receipt')); ?></button></td>' +
					'</tr>';
			}

			addBtn.addEventListener('click', function() {
				const idx = tbody.querySelectorAll('tr').length;
				tbody.insertAdjacentHTML('beforeend', rowHtml(idx));
			});

			tbody.addEventListener('click', function(e) {
				if (e.target && e.target.classList.contains('mp-mpr-yk-remove-rule')) {
					const tr = e.target.closest('tr');
					if (tr) tr.remove();
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * @return void
	 */
	private static function render_tab_robokassa(): void {
		$o = MP_Marked_Products_Receipt_Settings::class;
		$rules = MP_Marked_Products_Receipt_Settings::get_rb_rules();
		$categories = get_terms([
			'taxonomy' => 'product_cat',
			'hide_empty' => false,
		]);
		if (!is_array($categories)) {
			$categories = [];
		}

		?>
		<h2><?php echo esc_html__('Robokassa', 'mp-marked-products-receipt'); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php echo esc_html__('Включить', 'mp-marked-products-receipt'); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr($o::OPTION_RB_ENABLED); ?>" value="0" />
					<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_RB_ENABLED); ?>" value="1" <?php checked(MP_Marked_Products_Receipt_Settings::is_rb_enabled()); ?> /> <?php echo esc_html__('Да', 'mp-marked-products-receipt'); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php echo esc_html__('Sandbox / тест', 'mp-marked-products-receipt'); ?></th>
				<td>
					<input type="hidden" name="<?php echo esc_attr($o::OPTION_RB_SANDBOX); ?>" value="0" />
					<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_RB_SANDBOX); ?>" value="1" <?php checked(MP_Marked_Products_Receipt_Settings::is_rb_sandbox()); ?> /> <?php echo esc_html__('Да', 'mp-marked-products-receipt'); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row">Login</th>
				<td><input class="regular-text" type="text" name="<?php echo esc_attr($o::OPTION_RB_LOGIN); ?>" value="<?php echo esc_attr(MP_Marked_Products_Receipt_Settings::get_rb_login()); ?>"></td>
			</tr>
			<tr>
				<th scope="row">Password1</th>
				<td>
					<input class="regular-text" type="password" name="<?php echo esc_attr($o::OPTION_RB_PASSWORD1); ?>" value="" placeholder="<?php echo esc_attr(MP_Marked_Products_Receipt_Settings::get_rb_password1() !== '' ? __('оставьте пустым, чтобы не менять', 'mp-marked-products-receipt') : ''); ?>">
				</td>
			</tr>
			<tr>
				<th scope="row">payment_mode</th>
				<td>
					<select name="<?php echo esc_attr($o::OPTION_RB_DEFAULT_PAYMENT_MODE); ?>">
						<?php foreach (MP_Marked_Products_Receipt_Settings::allowed_payment_modes() as $mode) : ?>
							<option value="<?php echo esc_attr($mode); ?>" <?php selected(MP_Marked_Products_Receipt_Settings::get_rb_default_payment_mode(), $mode); ?>><?php echo esc_html($mode); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row">payment_subject</th>
				<td>
					<select name="<?php echo esc_attr($o::OPTION_RB_DEFAULT_PAYMENT_SUBJECT); ?>">
						<?php foreach (MP_Marked_Products_Receipt_Settings::allowed_payment_subjects() as $sub) : ?>
							<option value="<?php echo esc_attr($sub); ?>" <?php selected(MP_Marked_Products_Receipt_Settings::get_rb_default_payment_subject(), $sub); ?>><?php echo esc_html($sub); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>

		<h3><?php echo esc_html__('Правила по категориям', 'mp-marked-products-receipt'); ?></h3>
		<table class="widefat striped" id="mp-mpr-rb-rules-table">
			<thead>
			<tr>
				<th style="width:90px;"><?php echo esc_html__('Вкл', 'mp-marked-products-receipt'); ?></th>
				<th style="width:120px;">Priority</th>
				<th><?php echo esc_html__('Категории', 'mp-marked-products-receipt'); ?></th>
				<th style="width:220px;">payment_mode</th>
				<th style="width:220px;">payment_subject</th>
				<th style="width:80px;"></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ($rules as $idx => $rule) : ?>
				<tr>
					<td>
						<input type="hidden" name="<?php echo esc_attr($o::OPTION_RB_RULES . '[' . $idx . '][enabled]'); ?>" value="0">
						<label><input type="checkbox" name="<?php echo esc_attr($o::OPTION_RB_RULES . '[' . $idx . '][enabled]'); ?>" value="1" <?php checked(!empty($rule['enabled'])); ?>> <?php echo esc_html__('Да', 'mp-marked-products-receipt'); ?></label>
					</td>
					<td><input type="number" style="width:100px;" name="<?php echo esc_attr($o::OPTION_RB_RULES . '[' . $idx . '][priority]'); ?>" value="<?php echo esc_attr((string) (int) $rule['priority']); ?>"></td>
					<td>
						<select multiple size="5" style="min-width:280px;" name="<?php echo esc_attr($o::OPTION_RB_RULES . '[' . $idx . '][category_ids][]'); ?>">
							<?php foreach ($categories as $cat) : ?>
								<option value="<?php echo esc_attr((string) $cat->term_id); ?>" <?php selected(in_array((int) $cat->term_id, (array) $rule['category_ids'], true)); ?>>
									<?php echo esc_html($cat->name . ' (#' . $cat->term_id . ')'); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<select name="<?php echo esc_attr($o::OPTION_RB_RULES . '[' . $idx . '][payment_mode]'); ?>">
							<?php foreach (MP_Marked_Products_Receipt_Settings::allowed_payment_modes() as $mode) : ?>
								<option value="<?php echo esc_attr($mode); ?>" <?php selected($rule['payment_mode'], $mode); ?>><?php echo esc_html($mode); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td>
						<select name="<?php echo esc_attr($o::OPTION_RB_RULES . '[' . $idx . '][payment_subject]'); ?>">
							<?php foreach (MP_Marked_Products_Receipt_Settings::allowed_payment_subjects() as $sub) : ?>
								<option value="<?php echo esc_attr($sub); ?>" <?php selected($rule['payment_subject'], $sub); ?>><?php echo esc_html($sub); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
					<td><button type="button" class="button mp-mpr-rb-remove-rule"><?php echo esc_html__('Удалить', 'mp-marked-products-receipt'); ?></button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="mp-mpr-rb-add-rule"><?php echo esc_html__('Добавить правило', 'mp-marked-products-receipt'); ?></button></p>

		<script>
		(function() {
			const table = document.getElementById('mp-mpr-rb-rules-table');
			const addBtn = document.getElementById('mp-mpr-rb-add-rule');
			if (!table || !addBtn) return;
			const tbody = table.querySelector('tbody');
			const categoriesHtml = <?php echo wp_json_encode(implode('', array_map(static function ($cat) {
				return '<option value="' . (int) $cat->term_id . '">' . esc_html($cat->name . ' (#' . $cat->term_id . ')') . '</option>';
			}, $categories))); ?>;
			const modes = <?php echo wp_json_encode(MP_Marked_Products_Receipt_Settings::allowed_payment_modes()); ?>;
			const subjects = <?php echo wp_json_encode(MP_Marked_Products_Receipt_Settings::allowed_payment_subjects()); ?>;
			const optionName = <?php echo wp_json_encode($o::OPTION_RB_RULES); ?>;

			function buildOptions(items, selected) {
				return items.map((v) => '<option value="' + v + '"' + (v === selected ? ' selected' : '') + '>' + v + '</option>').join('');
			}

			function rowHtml(idx) {
				return '' +
					'<tr>' +
					'<td><input type="hidden" name="' + optionName + '[' + idx + '][enabled]" value="0"><label><input type="checkbox" name="' + optionName + '[' + idx + '][enabled]" value="1" checked> <?php echo esc_js(__('Да', 'mp-marked-products-receipt')); ?></label></td>' +
					'<td><input type="number" name="' + optionName + '[' + idx + '][priority]" value="100" style="width:100px;"></td>' +
					'<td><select multiple size="5" name="' + optionName + '[' + idx + '][category_ids][]" style="min-width:280px;">' + categoriesHtml + '</select></td>' +
					'<td><select name="' + optionName + '[' + idx + '][payment_mode]">' + buildOptions(modes, 'full_payment') + '</select></td>' +
					'<td><select name="' + optionName + '[' + idx + '][payment_subject]">' + buildOptions(subjects, 'commodity') + '</select></td>' +
					'<td><button type="button" class="button mp-mpr-rb-remove-rule"><?php echo esc_js(__('Удалить', 'mp-marked-products-receipt')); ?></button></td>' +
					'</tr>';
			}

			addBtn.addEventListener('click', function() {
				const idx = tbody.querySelectorAll('tr').length;
				tbody.insertAdjacentHTML('beforeend', rowHtml(idx));
			});

			tbody.addEventListener('click', function(e) {
				if (e.target && e.target.classList.contains('mp-mpr-rb-remove-rule')) {
					const tr = e.target.closest('tr');
					if (tr) tr.remove();
				}
			});
		})();
		</script>
		<?php
	}

	/**
	 * @param array<string,mixed>|null $api_result
	 * @param int $inspect_order_id
	 * @param array<string,mixed>|null $inspect_result
	 * @param array{checks:array<int,array{label:string,ok:bool}>,warnings:array<int,string>} $preflight
	 * @return void
	 */
	private static function render_diagnostics_yk($api_result, int $inspect_order_id, $inspect_result, array $preflight): void {
		$readiness_ok = empty($preflight['warnings']);
		$form_action = admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=yookassa');
		?>
		<div class="mp-mpr-section mp-mpr-diagnostics">
			<h2><?php echo esc_html__('Проверка API ЮKassa', 'mp-marked-products-receipt'); ?></h2>
			<p class="mp-mpr-muted"><?php echo esc_html__('GET несуществующего платежа: 404 при успешной авторизации.', 'mp-marked-products-receipt'); ?></p>
			<form method="post" action="<?php echo esc_url($form_action); ?>">
				<?php wp_nonce_field(self::NONCE_YK_API_CHECK); ?>
				<input type="hidden" name="mp_mpr_yk_api_check" value="1" />
				<?php submit_button(__('Проверить API ЮKassa', 'mp-marked-products-receipt'), 'secondary', 'submit', false); ?>
			</form>
			<?php if (is_array($api_result)) : ?>
				<div class="mp-mpr-api-result mp-mpr-api-result-<?php echo !empty($api_result['ok']) ? 'ok' : 'fail'; ?>">
					<strong><?php echo !empty($api_result['ok']) ? esc_html__('Успех', 'mp-marked-products-receipt') : esc_html__('Ошибка', 'mp-marked-products-receipt'); ?></strong>
					<div>HTTP: <?php echo esc_html((string) $api_result['status_code']); ?></div>
					<div><?php echo esc_html((string) $api_result['message']); ?></div>
					<?php if (!empty($api_result['body_excerpt'])) : ?>
						<pre class="mp-mpr-log-pre" style="margin-top:8px;"><?php echo esc_html((string) $api_result['body_excerpt']); ?></pre>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<h2><?php echo esc_html__('Preflight', 'mp-marked-products-receipt'); ?></h2>
			<?php if (!empty($preflight['warnings'])) : ?>
				<ul class="mp-mpr-warn-list">
					<?php foreach ($preflight['warnings'] as $warning) : ?>
						<li><?php echo esc_html((string) $warning); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="mp-mpr-ok"><?php echo esc_html__('Критичных предупреждений нет.', 'mp-marked-products-receipt'); ?></p>
			<?php endif; ?>

			<h2><?php echo esc_html__('Готовность', 'mp-marked-products-receipt'); ?></h2>
			<p>
				<strong><?php echo esc_html__('Статус:', 'mp-marked-products-receipt'); ?></strong>
				<span class="<?php echo $readiness_ok ? 'mp-mpr-ok' : 'mp-mpr-warn'; ?>"><?php echo $readiness_ok ? esc_html__('PASS', 'mp-marked-products-receipt') : esc_html__('WARN', 'mp-marked-products-receipt'); ?></span>
			</p>
			<ul>
				<?php foreach ($preflight['checks'] as $check) : ?>
					<li>
						<span class="<?php echo !empty($check['ok']) ? 'mp-mpr-ok' : 'mp-mpr-warn'; ?>"><?php echo !empty($check['ok']) ? esc_html__('PASS', 'mp-marked-products-receipt') : esc_html__('WARN', 'mp-marked-products-receipt'); ?></span>
						— <?php echo esc_html((string) $check['label']); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h2><?php echo esc_html__('Инспектор заказа (ЮKassa)', 'mp-marked-products-receipt'); ?></h2>
			<form method="post" action="<?php echo esc_url($form_action); ?>">
				<?php wp_nonce_field(self::NONCE_INSPECT_ORDER_YK); ?>
				<label for="mp_mpr_inspect_order_yk"><?php echo esc_html__('Order ID', 'mp-marked-products-receipt'); ?></label>
				<input id="mp_mpr_inspect_order_yk" type="number" name="mp_mpr_inspect_order_yk" value="<?php echo $inspect_order_id > 0 ? esc_attr((string) $inspect_order_id) : ''; ?>" min="1" class="small-text" />
				<?php submit_button(__('Собрать preview', 'mp-marked-products-receipt'), 'secondary', 'mp_mpr_inspect_order_yk_submit', false); ?>
			</form>
			<?php if (is_array($inspect_result)) : ?>
				<?php if (empty($inspect_result['order_found'])) : ?>
					<p class="mp-mpr-warn"><?php echo esc_html__('Заказ не найден.', 'mp-marked-products-receipt'); ?></p>
				<?php else : ?>
					<p><strong><?php echo esc_html__('Order', 'mp-marked-products-receipt'); ?> #<?php echo esc_html((string) $inspect_result['order_id']); ?></strong></p>
					<p><strong><?php echo esc_html__('resolved', 'mp-marked-products-receipt'); ?>:</strong></p>
					<pre class="mp-mpr-log-pre"><?php echo esc_html(wp_json_encode($inspect_result['resolved'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
					<p><strong><?php echo esc_html__('built_preview', 'mp-marked-products-receipt'); ?>:</strong></p>
					<pre class="mp-mpr-log-pre"><?php echo esc_html(wp_json_encode($inspect_result['built'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
					<p><strong><?php echo esc_html__('meta', 'mp-marked-products-receipt'); ?>:</strong></p>
					<pre class="mp-mpr-log-pre"><?php echo esc_html(wp_json_encode($inspect_result['meta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed>|null $api_result
	 * @param int $inspect_order_id
	 * @param array<string,mixed>|null $inspect_result
	 * @param array{checks:array<int,array{label:string,ok:bool}>,warnings:array<int,string>} $preflight
	 * @return void
	 */
	private static function render_diagnostics_rb($api_result, int $inspect_order_id, $inspect_result, array $preflight): void {
		$readiness_ok = empty($preflight['warnings']);
		$form_action = admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=robokassa');
		?>
		<div class="mp-mpr-section mp-mpr-diagnostics">
			<h2><?php echo esc_html__('Проверка API Robokassa (RoboFiscal)', 'mp-marked-products-receipt'); ?></h2>
			<p class="mp-mpr-muted"><?php echo esc_html__('Доступность endpoint и сети (как в mp_robokassa_receipt2).', 'mp-marked-products-receipt'); ?></p>
			<form method="post" action="<?php echo esc_url($form_action); ?>">
				<?php wp_nonce_field(self::NONCE_RB_API_CHECK); ?>
				<input type="hidden" name="mp_mpr_rb_api_check" value="1" />
				<?php submit_button(__('Проверить API Robokassa', 'mp-marked-products-receipt'), 'secondary', 'submit', false); ?>
			</form>
			<?php if (is_array($api_result)) : ?>
				<div class="mp-mpr-api-result mp-mpr-api-result-<?php echo !empty($api_result['ok']) ? 'ok' : 'fail'; ?>">
					<strong><?php echo !empty($api_result['ok']) ? esc_html__('Доступен', 'mp-marked-products-receipt') : esc_html__('Недоступен', 'mp-marked-products-receipt'); ?></strong>
					<div>HTTP: <?php echo esc_html((string) $api_result['status_code']); ?></div>
					<div><?php echo esc_html((string) $api_result['message']); ?></div>
				</div>
			<?php endif; ?>

			<h2><?php echo esc_html__('Preflight', 'mp-marked-products-receipt'); ?></h2>
			<?php if (!empty($preflight['warnings'])) : ?>
				<ul class="mp-mpr-warn-list">
					<?php foreach ($preflight['warnings'] as $warning) : ?>
						<li><?php echo esc_html((string) $warning); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="mp-mpr-ok"><?php echo esc_html__('Критичных предупреждений нет.', 'mp-marked-products-receipt'); ?></p>
			<?php endif; ?>

			<h2><?php echo esc_html__('Готовность', 'mp-marked-products-receipt'); ?></h2>
			<p>
				<strong><?php echo esc_html__('Статус:', 'mp-marked-products-receipt'); ?></strong>
				<span class="<?php echo $readiness_ok ? 'mp-mpr-ok' : 'mp-mpr-warn'; ?>"><?php echo $readiness_ok ? esc_html__('PASS', 'mp-marked-products-receipt') : esc_html__('WARN', 'mp-marked-products-receipt'); ?></span>
			</p>
			<ul>
				<?php foreach ($preflight['checks'] as $check) : ?>
					<li>
						<span class="<?php echo !empty($check['ok']) ? 'mp-mpr-ok' : 'mp-mpr-warn'; ?>"><?php echo !empty($check['ok']) ? esc_html__('PASS', 'mp-marked-products-receipt') : esc_html__('WARN', 'mp-marked-products-receipt'); ?></span>
						— <?php echo esc_html((string) $check['label']); ?>
					</li>
				<?php endforeach; ?>
			</ul>

			<h2><?php echo esc_html__('Инспектор заказа (Robokassa)', 'mp-marked-products-receipt'); ?></h2>
			<form method="post" action="<?php echo esc_url($form_action); ?>">
				<?php wp_nonce_field(self::NONCE_INSPECT_ORDER_RB); ?>
				<label for="mp_mpr_inspect_order_rb"><?php echo esc_html__('Order ID', 'mp-marked-products-receipt'); ?></label>
				<input id="mp_mpr_inspect_order_rb" type="number" name="mp_mpr_inspect_order_rb" value="<?php echo $inspect_order_id > 0 ? esc_attr((string) $inspect_order_id) : ''; ?>" min="1" class="small-text" />
				<?php submit_button(__('Собрать preview', 'mp-marked-products-receipt'), 'secondary', 'mp_mpr_inspect_order_rb_submit', false); ?>
			</form>
			<?php if (is_array($inspect_result)) : ?>
				<?php if (empty($inspect_result['order_found'])) : ?>
					<p class="mp-mpr-warn"><?php echo esc_html__('Заказ не найден.', 'mp-marked-products-receipt'); ?></p>
				<?php else : ?>
					<p><strong><?php echo esc_html__('Order', 'mp-marked-products-receipt'); ?> #<?php echo esc_html((string) $inspect_result['order_id']); ?></strong></p>
					<p><strong><?php echo esc_html__('resolved', 'mp-marked-products-receipt'); ?>:</strong></p>
					<pre class="mp-mpr-log-pre"><?php echo esc_html(wp_json_encode($inspect_result['resolved'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
					<p><strong><?php echo esc_html__('built_preview', 'mp-marked-products-receipt'); ?>:</strong></p>
					<pre class="mp-mpr-log-pre"><?php echo esc_html(wp_json_encode($inspect_result['built'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
					<p><strong><?php echo esc_html__('meta', 'mp-marked-products-receipt'); ?>:</strong></p>
					<pre class="mp-mpr-log-pre"><?php echo esc_html(wp_json_encode($inspect_result['meta'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); ?></pre>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @return array{checks:array<int,array{label:string,ok:bool}>,warnings:array<int,string>}
	 */
	private static function build_preflight_yk(): array {
		$rules = MP_Marked_Products_Receipt_Settings::get_yk_rules();
		$errors = array_merge(
			MP_Marked_Products_Receipt_Settings::validate_yk_for_api(),
			MP_Marked_Products_Receipt_Settings::validate_marking_detection()
		);

		$checks = [];
		$checks[] = [
			'label' => __('Плагин включён', 'mp-marked-products-receipt'),
			'ok' => MP_Marked_Products_Receipt_Settings::is_common_enabled(),
		];
		$checks[] = [
			'label' => __('ЮKassa: учётные данные заполнены', 'mp-marked-products-receipt'),
			'ok' => MP_Marked_Products_Receipt_Settings::is_common_enabled()
				&& MP_Marked_Products_Receipt_Settings::is_yk_enabled()
				&& MP_Marked_Products_Receipt_Settings::get_yk_shop_id() !== ''
				&& MP_Marked_Products_Receipt_Settings::get_yk_secret_key() !== '',
		];
		$checks[] = [
			'label' => __('Есть хотя бы одно включённое правило по категориям', 'mp-marked-products-receipt'),
			'ok' => self::has_enabled_rules($rules),
		];
		$checks[] = [
			'label' => __('Каталог логов доступен для записи', 'mp-marked-products-receipt'),
			'ok' => self::is_log_dir_writable(),
		];
		$checks[] = [
			'label' => __('Нет ошибок validate / детектора маркировки', 'mp-marked-products-receipt'),
			'ok' => empty($errors),
		];

		$warnings = [];
		foreach ($checks as $c) {
			if (empty($c['ok'])) {
				$warnings[] = $c['label'];
			}
		}
		foreach ($errors as $e) {
			$warnings[] = (string) $e;
		}

		return [
			'checks' => $checks,
			'warnings' => $warnings,
		];
	}

	/**
	 * @return array{checks:array<int,array{label:string,ok:bool}>,warnings:array<int,string>}
	 */
	private static function build_preflight_rb(): array {
		$rules = MP_Marked_Products_Receipt_Settings::get_rb_rules();
		$errors = array_merge(
			MP_Marked_Products_Receipt_Settings::validate_rb_for_api(),
			MP_Marked_Products_Receipt_Settings::validate_marking_detection()
		);

		$checks = [];
		$checks[] = [
			'label' => __('Плагин включён', 'mp-marked-products-receipt'),
			'ok' => MP_Marked_Products_Receipt_Settings::is_common_enabled(),
		];
		$checks[] = [
			'label' => __('Robokassa: login и password1 заполнены', 'mp-marked-products-receipt'),
			'ok' => MP_Marked_Products_Receipt_Settings::is_common_enabled()
				&& MP_Marked_Products_Receipt_Settings::is_rb_enabled()
				&& MP_Marked_Products_Receipt_Settings::get_rb_login() !== ''
				&& MP_Marked_Products_Receipt_Settings::get_rb_password1() !== '',
		];
		$checks[] = [
			'label' => __('Есть хотя бы одно включённое правило по категориям', 'mp-marked-products-receipt'),
			'ok' => self::has_enabled_rules($rules),
		];
		$checks[] = [
			'label' => __('Каталог логов доступен для записи', 'mp-marked-products-receipt'),
			'ok' => self::is_log_dir_writable(),
		];
		$checks[] = [
			'label' => __('Нет ошибок validate / детектора маркировки', 'mp-marked-products-receipt'),
			'ok' => empty($errors),
		];

		$warnings = [];
		foreach ($checks as $c) {
			if (empty($c['ok'])) {
				$warnings[] = $c['label'];
			}
		}
		foreach ($errors as $e) {
			$warnings[] = (string) $e;
		}

		return [
			'checks' => $checks,
			'warnings' => $warnings,
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $rules
	 * @return bool
	 */
	private static function has_enabled_rules(array $rules): bool {
		foreach ($rules as $rule) {
			if (!empty($rule['enabled'])) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return bool
	 */
	private static function is_log_dir_writable(): bool {
		$dir = MP_Marked_Products_Receipt_Logger::get_log_dir();
		if (!is_dir($dir) && function_exists('wp_mkdir_p')) {
			wp_mkdir_p($dir);
		}

		return is_dir($dir) && is_writable($dir);
	}

	/**
	 * @param int $lines
	 * @return string
	 */
	private static function read_log_tail(int $lines = 40): string {
		return MP_Marked_Products_Receipt_Logger::read_log_tail($lines);
	}

	/**
	 * §26: локальный preview для ЮKassa (без HTTP к API): OrderLinks → ReceiptBuilder → усечённый `built` + мета заказа.
	 *
	 * @param int $order_id
	 * @return array<string,mixed> Ключи: `order_found`, `order_id`, при успехе — `resolved`, `built`, `meta`.
	 */
	public static function inspect_order_yk(int $order_id): array {
		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
		if (!$order instanceof WC_Order) {
			return [
				'order_found' => false,
				'order_id' => $order_id,
			];
		}

		$resolved = MP_Marked_Products_Receipt_OrderLinks_YK::resolve_for_order($order);
		$built = MP_Marked_Products_Receipt_ReceiptBuilder_YK::build($order, (float) $resolved['settlement_amount']);

		return [
			'order_found' => true,
			'order_id' => $order_id,
			'resolved' => $resolved,
			'built' => self::truncate_built_preview($built),
			'meta' => [
				'mp_mpr_yk_sent' => $order->get_meta('mp_mpr_yk_sent', true),
				'mp_mpr_yk_receipt_id' => $order->get_meta('mp_mpr_yk_receipt_id', true),
				'mp_mpr_yk_error' => $order->get_meta('mp_mpr_yk_error', true),
				'mp_mpr_yk_idempotence_key' => $order->get_meta('mp_mpr_yk_idempotence_key', true),
				'mp_mpr_yk_source_payment_id' => $order->get_meta(MP_Marked_Products_Receipt_OrderLinks_YK::META_SOURCE_PAYMENT_ID, true),
				'mp_mpr_yk_settlement_amount' => $order->get_meta(MP_Marked_Products_Receipt_OrderLinks_YK::META_SETTLEMENT_AMOUNT, true),
			],
		];
	}

	/**
	 * §26: локальный preview для Robokassa (без HTTP к API): OrderLinks → ReceiptBuilder → усечённый `built` + мета заказа.
	 *
	 * @param int $order_id
	 * @return array<string,mixed> Ключи: `order_found`, `order_id`, при успехе — `resolved`, `built`, `meta`.
	 */
	public static function inspect_order_rb(int $order_id): array {
		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
		if (!$order instanceof WC_Order) {
			return [
				'order_found' => false,
				'order_id' => $order_id,
			];
		}

		$resolved = MP_Marked_Products_Receipt_OrderLinks_RB::resolve_for_order($order);
		$built = MP_Marked_Products_Receipt_ReceiptBuilder_RB::build($order, (float) $resolved['settlement_amount']);

		return [
			'order_found' => true,
			'order_id' => $order_id,
			'resolved' => $resolved,
			'built' => self::truncate_built_preview($built),
			'meta' => [
				'mp_mpr_rb_sent' => $order->get_meta('mp_mpr_rb_sent', true),
				'mp_mpr_rb_receipt_id' => $order->get_meta('mp_mpr_rb_receipt_id', true),
				'mp_mpr_rb_receipt_url' => $order->get_meta('mp_mpr_rb_receipt_url', true),
				'mp_mpr_rb_error' => $order->get_meta('mp_mpr_rb_error', true),
				'mp_mpr_rb_request_id' => $order->get_meta('mp_mpr_rb_request_id', true),
				'mp_mpr_rb_source_id' => $order->get_meta(MP_Marked_Products_Receipt_OrderLinks_RB::META_SOURCE_ID, true),
				'mp_mpr_rb_settlement_amount' => $order->get_meta(MP_Marked_Products_Receipt_OrderLinks_RB::META_SETTLEMENT_AMOUNT, true),
			],
		];
	}

	/**
	 * §26 п.148: не отдавать в UI сотни строк позиций — максимум 50 + служебные поля.
	 *
	 * @param array<string,mixed> $built
	 * @return array<string,mixed>
	 */
	private static function truncate_built_preview(array $built): array {
		$out = $built;
		$max = self::INSPECT_PREVIEW_MAX_ITEMS;
		if (!empty($out['items']) && is_array($out['items']) && count($out['items']) > $max) {
			$n = count($out['items']);
			$out['items'] = array_slice($out['items'], 0, $max);
			$out['_truncated'] = true;
			$out['_items_total'] = $n;
		}

		return $out;
	}

	/**
	 * @return void
	 */
	public static function ajax_inspect_product(): void {
		check_ajax_referer('mp_mpr_inspect_product', 'nonce');
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(['message' => __('Недостаточно прав.', 'mp-marked-products-receipt')]);
		}

		$raw = isset($_POST['product_id']) ? sanitize_text_field(wp_unslash((string) $_POST['product_id'])) : '';
		$product_id = (int) $raw;
		if ($product_id <= 0) {
			wp_send_json_error(['message' => __('Некорректный ID товара.', 'mp-marked-products-receipt')]);
		}

		if (!function_exists('wc_get_product')) {
			wp_send_json_error(['message' => __('WooCommerce не загружен.', 'mp-marked-products-receipt')]);
		}

		$product = wc_get_product($product_id);
		if (!$product instanceof WC_Product) {
			wp_send_json_success(
				[
					'marked' => false,
					'reasons' => ['product_not_found'],
				]
			);
		}

		$marked = MP_Marked_Products_Receipt_ProductMarker::is_product_marked($product);
		$reasons = $marked ? ['marked_true'] : ['not_marked_by_rules'];

		wp_send_json_success(
			[
				'marked' => $marked,
				'reasons' => $reasons,
			]
		);
	}
}
