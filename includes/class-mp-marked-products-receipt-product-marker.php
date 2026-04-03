<?php
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Detects marked (traceable) products using Settings and optional order-line CIS checks.
 *
 * Режимы (§27–§30):
 * - `taxonomy` — `wp_get_post_terms` по ID товара/родителя, пересечение с `mp_mpr_marking_term_ids`.
 * - `category` — категории через WC и при необходимости `wp_get_post_terms( product_cat )`, пересечение с настройками.
 * - `meta` — `get_post_meta` по вариации и родителю; непустая строка после trim → marked.
 * - `filter_only` — базово false; маркировка только через `mp_mpr_is_product_marked`. DEBUG в лог, если хука нет и нет ни одной marked-строки.
 *
 * Filters:
 * - `mp_mpr_is_product_marked` (bool $marked, WC_Product $product, ?WC_Order_Item_Product $item)
 * - `mp_mpr_marking_payload` (array|null $payload, WC_Order_Item_Product $item)
 */
final class MP_Marked_Products_Receipt_ProductMarker {
	/**
	 * @var array<string,bool>
	 */
	private static $base_marked_cache = [];

	/**
	 * Product-level marking (no CIS on order line). Variations: checks variation + parent.
	 *
	 * @param WC_Product $product
	 * @return bool
	 */
	public static function is_product_marked(WC_Product $product): bool {
		$marked = self::resolve_base_marked_cached($product);

		/**
		 * @param bool $marked
		 * @param WC_Product $product
		 * @param WC_Order_Item_Product|null $item
		 */
		return (bool) apply_filters('mp_mpr_is_product_marked', $marked, $product, null);
	}

	/**
	 * Line item: base rules + optional required CIS in order item meta + filter.
	 *
	 * @param WC_Order_Item_Product $item
	 * @return bool
	 */
	public static function line_item_is_marked(WC_Order_Item_Product $item): bool {
		$product = $item->get_product();
		if (!$product instanceof WC_Product) {
			return false;
		}

		$marked = self::resolve_base_marked_cached($product);

		if ($marked && MP_Marked_Products_Receipt_Settings::is_require_cis_in_order_item_meta()) {
			$key = MP_Marked_Products_Receipt_Settings::get_order_item_cis_meta_key();
			if ($key === '') {
				$marked = false;
			} else {
				$raw = $item->get_meta($key, true);
				$marked = !self::cis_raw_is_empty($raw);
			}
		}

		/**
		 * @param bool $marked
		 * @param WC_Product $product
		 * @param WC_Order_Item_Product|null $item
		 */
		return (bool) apply_filters('mp_mpr_is_product_marked', $marked, $product, $item);
	}

	/**
	 * Raw marking data for fiscal APIs (YooKassa / Robokassa). Extend in receipt builders.
	 *
	 * Typical keys: `cis` (string), `cis_list` (string[]), `raw` (mixed).
	 *
	 * @param WC_Order_Item_Product $item
	 * @return array<string,mixed>|null
	 */
	public static function get_marking_payload_for_line_item(WC_Order_Item_Product $item): ?array {
		$key = MP_Marked_Products_Receipt_Settings::get_order_item_cis_meta_key();
		if ($key === '') {
			$payload = null;
		} else {
			$raw = $item->get_meta($key, true);
			if (self::cis_raw_is_empty($raw)) {
				$payload = null;
			} else {
				$cis_list = self::normalize_cis_list($raw);
				$payload = [
					'cis' => $cis_list[0] ?? '',
					'cis_list' => $cis_list,
					'raw' => $raw,
				];
			}
		}

		/**
		 * @param array<string,mixed>|null $payload
		 * @param WC_Order_Item_Product $item
		 */
		$payload = apply_filters('mp_mpr_marking_payload', $payload, $item);

		if (!is_array($payload) || empty($payload)) {
			return null;
		}

		return $payload;
	}

	/**
	 * @param WC_Order $order
	 * @return array{marked:array<int,WC_Order_Item_Product>,unmarked:array<int,WC_Order_Item_Product>}
	 */
	public static function split_order_line_items(WC_Order $order): array {
		$marked = [];
		$unmarked = [];

		foreach ($order->get_items('line_item') as $item_id => $item) {
			if (!$item instanceof WC_Order_Item_Product) {
				continue;
			}

			if (self::line_item_is_marked($item)) {
				$marked[(int) $item_id] = $item;
			} else {
				$unmarked[(int) $item_id] = $item;
			}
		}

		// §30: режим `filter_only` без зарегистрированного фильтра и без marked-позиций — подсказка в DEBUG.
		if (
			MP_Marked_Products_Receipt_Settings::get_marking_source() === 'filter_only'
			&& $marked === []
			&& !has_filter('mp_mpr_is_product_marked')
		) {
			MP_Marked_Products_Receipt_Logger::log('DEBUG', (int) $order->get_id(), 'filter_only_no_hook_no_marked', [
				'note' => 'Register mp_mpr_is_product_marked or change marking source in settings.',
			]);
		}

		return [
			'marked' => $marked,
			'unmarked' => $unmarked,
		];
	}

	/**
	 * @param WC_Product $product
	 * @return bool
	 */
	private static function resolve_base_marked_cached(WC_Product $product): bool {
		$key = self::cache_key($product);
		if (isset(self::$base_marked_cache[$key])) {
			return self::$base_marked_cache[$key];
		}

		$result = self::resolve_base_marked($product);
		self::$base_marked_cache[$key] = $result;

		return $result;
	}

	/**
	 * @param WC_Product $product
	 * @return string
	 */
	private static function cache_key(WC_Product $product): string {
		$cfg = MP_Marked_Products_Receipt_Settings::get_marking_rules_config();

		return (string) (int) $product->get_id()
			. '|' . (string) $cfg['source']
			. '|' . md5((string) wp_json_encode($cfg));
	}

	/**
	 * @param WC_Product $product
	 * @return bool
	 */
	private static function resolve_base_marked(WC_Product $product): bool {
		$source = MP_Marked_Products_Receipt_Settings::get_marking_source();

		// §30: истинная маркировка только через фильтр `mp_mpr_is_product_marked`.
		if ($source === 'filter_only') {
			return false;
		}

		if ($source === 'meta') {
			$meta_key = MP_Marked_Products_Receipt_Settings::get_marking_meta_key();

			return $meta_key !== '' && self::product_meta_nonempty($product, $meta_key);
		}

		// §27: термы через `wp_get_post_terms` по вариации/родителю, пересечение с настроенными term_id.
		if ($source === 'taxonomy') {
			$taxonomy = MP_Marked_Products_Receipt_Settings::get_marking_taxonomy();
			$want = MP_Marked_Products_Receipt_Settings::get_marking_term_ids();
			if ($taxonomy === '' || empty($want)) {
				return false;
			}
			if (!taxonomy_exists($taxonomy)) {
				return false;
			}

			$have = self::get_product_term_ids_for_taxonomy($product, $taxonomy);

			return count(array_intersect($want, $have)) > 0;
		}

		// §28: `product_cat` — WC API и резерв `wp_get_post_terms( product_cat )`.
		if ($source === 'category') {
			$want = MP_Marked_Products_Receipt_Settings::get_marking_category_ids();
			if (empty($want)) {
				return false;
			}

			$have = self::get_product_category_ids($product);

			return count(array_intersect($want, $have)) > 0;
		}

		return false;
	}

	/**
	 * @param WC_Product $product
	 * @param string $meta_key
	 * @return bool
	 */
	private static function product_meta_nonempty(WC_Product $product, string $meta_key): bool {
		foreach (self::get_product_post_ids_to_scan($product) as $post_id) {
			$val = get_post_meta((int) $post_id, $meta_key, true);
			if (self::is_nonempty_meta_value($val)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * §29: признак маркировки по meta — непустая строка после trim (скаляры приводим к строке).
	 *
	 * @param mixed $val
	 * @return bool
	 */
	private static function is_nonempty_meta_value($val): bool {
		if (is_string($val)) {
			return trim($val) !== '';
		}
		if (is_numeric($val)) {
			if ((float) $val === 0.0) {
				return false;
			}

			return trim((string) $val) !== '';
		}
		if (is_array($val)) {
			foreach ($val as $v) {
				if (is_string($v) && trim($v) !== '') {
					return true;
				}
				if (is_numeric($v) && (float) $v !== 0.0 && trim((string) $v) !== '') {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param WC_Product $product
	 * @return array<int,int>
	 */
	private static function get_product_post_ids_to_scan(WC_Product $product): array {
		$ids = [ (int) $product->get_id() ];
		$parent = (int) $product->get_parent_id();
		if ($parent > 0) {
			$ids[] = $parent;
		}

		$ids = array_values(array_unique(array_filter($ids, static function ($id) {
			return (int) $id > 0;
		})));

		return $ids;
	}

	/**
	 * @param WC_Product $product
	 * @param string $taxonomy
	 * @return array<int,int>
	 */
	private static function get_product_term_ids_for_taxonomy(WC_Product $product, string $taxonomy): array {
		$out = [];

		foreach (self::get_product_post_ids_to_scan($product) as $post_id) {
			$terms = wp_get_post_terms((int) $post_id, $taxonomy, [
				'fields' => 'ids',
			]);
			if (is_wp_error($terms) || !is_array($terms)) {
				continue;
			}
			foreach ($terms as $tid) {
				$tid = (int) $tid;
				if ($tid > 0) {
					$out[] = $tid;
				}
			}
		}

		return array_values(array_unique($out));
	}

	/**
	 * §28: сначала WC `get_category_ids` (вариация + при необходимости родитель), затем резерв — `wp_get_post_terms( product_cat )`.
	 *
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

		if (empty($ids)) {
			foreach (self::get_product_post_ids_to_scan($product) as $post_id) {
				$terms = wp_get_post_terms((int) $post_id, 'product_cat', [
					'fields' => 'ids',
				]);
				if (is_wp_error($terms) || !is_array($terms)) {
					continue;
				}
				foreach ($terms as $tid) {
					$tid = (int) $tid;
					if ($tid > 0) {
						$ids[] = $tid;
					}
				}
			}
		}

		$ids = array_values(array_unique(array_filter($ids, static function ($id) {
			return (int) $id > 0;
		})));

		return $ids;
	}

	/**
	 * @param mixed $raw
	 * @return bool
	 */
	private static function cis_raw_is_empty($raw): bool {
		if ($raw === null || $raw === '') {
			return true;
		}
		if (is_string($raw)) {
			return trim($raw) === '';
		}
		if (is_array($raw)) {
			foreach ($raw as $v) {
				if (is_string($v) && trim($v) !== '') {
					return false;
				}
			}

			return true;
		}

		return false;
	}

	/**
	 * @param mixed $raw
	 * @return array<int,string>
	 */
	private static function normalize_cis_list($raw): array {
		$out = [];

		if (is_string($raw)) {
			$s = trim($raw);
			if ($s !== '') {
				$out[] = $s;
			}

			return $out;
		}

		if (is_array($raw)) {
			foreach ($raw as $v) {
				if (!is_string($v)) {
					continue;
				}
				$s = trim($v);
				if ($s !== '') {
					$out[] = $s;
				}
			}
		}

		return array_values(array_unique($out));
	}
}
