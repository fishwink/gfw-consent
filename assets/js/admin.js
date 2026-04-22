/**
 * FISHWINK Consent — admin UI
 *
 * 1.0.7:
 *  - Color picker init (unchanged)
 *  - "Scan now" handler (unchanged)
 *  - Segmented control click handling (keeps native radios authoritative)
 *  - Live branding preview (theme, layout, position, primary color, radius)
 *  - Range slider value sync
 */
(function ($) {
	'use strict';

	$(function () {

		/* ------------------------------------------------------------------
		 * Color picker (brand primary)
		 * ------------------------------------------------------------------ */
		if ($.fn.wpColorPicker) {
			$('.gfw-color').wpColorPicker({
				change: function (event, ui) {
					// wpColorPicker debounces change events; reflect immediately in preview
					var hex = ui.color.toString();
					updatePreviewPrimary(hex);
				},
				clear: function () {
					updatePreviewPrimary($('#gfw_brand_primary').val());
				}
			});
		}

		/* ------------------------------------------------------------------
		 * Services tab — "Scan site now"
		 * ------------------------------------------------------------------ */
		$('#gfw-consent-scan-now').on('click', function () {
			var $btn = $(this).prop('disabled', true).text('Scanning…');
			$.post(GFWConsentAdmin.ajaxUrl, {
				action: 'gfw_consent_scan_now',
				nonce:  GFWConsentAdmin.nonce
			}).done(function () {
				location.reload();
			}).fail(function () {
				$btn.prop('disabled', false).text('Scan failed — retry');
			});
		});

		/* ------------------------------------------------------------------
		 * Segmented controls (Theme / Layout / Position)
		 * Click a .gfw-seg → check its inner radio, mark it active,
		 * unmark siblings. Keeps the form submission working via the
		 * native radio inputs; the CSS class is just visual.
		 * ------------------------------------------------------------------ */
		$(document).on('click', '.gfw-seg', function (e) {
			// Let the native label->radio click also run; we just add class state.
			var $seg = $(this);
			var $group = $seg.closest('.gfw-segmented');
			$group.find('.gfw-seg').removeClass('is-active');
			$seg.addClass('is-active');
			// If the user clicked the text span, the label still propagates the
			// click to the radio, so we don't need to manually set .checked.
			// But force a change event so our preview driver picks it up:
			setTimeout(function () {
				$seg.find('input[type="radio"]').trigger('change');
			}, 0);
		});

		// Keyboard accessibility — arrow keys navigate within a segmented group
		$(document).on('keydown', '.gfw-seg input[type="radio"]', function (e) {
			if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') { return; }
			e.preventDefault();
			var $current = $(this).closest('.gfw-seg');
			var $siblings = $current.siblings('.gfw-seg');
			if (!$siblings.length) { return; }
			var $target = (e.key === 'ArrowRight')
				? ($current.next('.gfw-seg').length ? $current.next('.gfw-seg') : $current.parent().children('.gfw-seg').first())
				: ($current.prev('.gfw-seg').length ? $current.prev('.gfw-seg') : $current.parent().children('.gfw-seg').last());
			$target.find('input[type="radio"]').prop('checked', true).trigger('change').focus();
		});

		/* ------------------------------------------------------------------
		 * Range slider value sync (border radius)
		 * ------------------------------------------------------------------ */
		var $range    = $('#gfw_brand_radius');
		var $rangeVal = $('#gfw_brand_radius_val');
		if ($range.length) {
			$range.on('input change', function () {
				var v = parseInt($range.val(), 10) || 0;
				$rangeVal.text(v + 'px');
				updatePreviewRadius(v);
			});
		}

		/* ------------------------------------------------------------------
		 * Live preview driver
		 * ------------------------------------------------------------------ */
		var $stage = $('#gfw_preview_stage');

		function updatePreviewTheme(theme) {
			if (!$stage.length) { return; }
			$stage.attr('data-theme', theme);
		}
		function updatePreviewLayout(layout) {
			if (!$stage.length) { return; }
			$stage.attr('data-layout', layout);
		}
		function updatePreviewPosition(position) {
			if (!$stage.length) { return; }
			$stage.attr('data-position', position);
		}
		function updatePreviewPrimary(hex) {
			if (!$stage.length || !hex) { return; }
			var $banner = $stage.find('.gfw-preview-banner');
			$banner.css('--gfw-p', hex);
			$banner.css('--gfw-p-text', luminanceFg(hex));
		}
		function updatePreviewRadius(px) {
			if (!$stage.length) { return; }
			var $banner = $stage.find('.gfw-preview-banner');
			$banner.css('--gfw-r', px + 'px');
		}

		// Bind change listeners for each branding input
		$(document).on('change', 'input[name$="[brand_theme]"]', function () {
			updatePreviewTheme(this.value);
		});
		$(document).on('change', 'input[name$="[brand_layout]"]', function () {
			updatePreviewLayout(this.value);
		});
		$(document).on('change', 'input[name$="[brand_position]"]', function () {
			updatePreviewPosition(this.value);
		});

		// Also watch the primary color input for direct typing (wpColorPicker
		// hides the raw input but other integrations may swap it)
		$(document).on('input change', '#gfw_brand_primary', function () {
			updatePreviewPrimary($(this).val());
		});

		// Initial sync on page load — ensures the button text color is correct
		// even before the user interacts with anything.
		if ($stage.length) {
			updatePreviewPrimary($('#gfw_brand_primary').val());
		}

		/* ------------------------------------------------------------------
		 * WCAG-ish luminance check — mirrors the PHP helper so the preview
		 * button text flips to black/white as the primary color changes.
		 * ------------------------------------------------------------------ */
		function luminanceFg(hex) {
			if (!hex) { return '#ffffff'; }
			hex = String(hex).replace(/^#/, '');
			if (hex.length === 3) {
				hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
			}
			if (hex.length !== 6 || /[^0-9a-f]/i.test(hex)) { return '#ffffff'; }
			var r = parseInt(hex.substr(0,2), 16) / 255;
			var g = parseInt(hex.substr(2,2), 16) / 255;
			var b = parseInt(hex.substr(4,2), 16) / 255;
			var lum = 0.2126*r + 0.7152*g + 0.0722*b;
			return lum > 0.55 ? '#000000' : '#ffffff';
		}

	});
})(jQuery);
