(function ($) {
	'use strict';

	function syncMarkingUi() {
		var $src = $('#mp_mpr_marking_source');
		if (!$src.length) {
			return;
		}
		var v = $src.val();
		$('.mp-mpr-if-meta').toggle(v === 'meta');
		$('.mp-mpr-if-category').toggle(v === 'category');
		$('.mp-mpr-if-taxonomy').toggle(v === 'taxonomy');
	}

	$(function () {
		$(document).on('change', '#mp_mpr_marking_source', syncMarkingUi);
		syncMarkingUi();

		$(document).on('click', '#mp_mpr_inspect_product_btn', function () {
			var $out = $('#mp_mpr_inspect_product_out');
			var id = $.trim($('#mp_mpr_inspect_product_id').val() || '');
			if (!id || !window.mpMprAdmin) {
				return;
			}
			$out.show().text('…');
			$.post(
				mpMprAdmin.ajaxUrl,
				{
					action: 'mp_mpr_inspect_product',
					nonce: mpMprAdmin.nonceInspectProduct,
					product_id: id
				}
			)
				.done(function (res) {
					if (res && res.success) {
						$out.text(JSON.stringify(res.data, null, 2));
					} else if (res && res.data && res.data.message) {
						$out.text(String(res.data.message));
					} else {
						$out.text(JSON.stringify(res, null, 2));
					}
				})
				.fail(function () {
					$out.text(mpMprAdmin.i18nInspectError || 'Error');
				});
		});
	});
})(jQuery);
