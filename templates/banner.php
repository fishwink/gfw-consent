<?php
/**
 * Banner HTML output in the footer.
 * Banner, preferences modal, floating action button, and toast.
 * All text populated via JS from localized data so it stays cacheable.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div id="gfw-consent" class="gfw-consent" data-state="hidden" aria-hidden="true">

	<!-- Main banner shown on first visit -->
	<div class="gfw-consent__banner" role="dialog" aria-modal="false" aria-labelledby="gfw-consent-title" aria-describedby="gfw-consent-body">
		<div class="gfw-consent__content">
			<h2 id="gfw-consent-title" class="gfw-consent__title"></h2>
			<p id="gfw-consent-body" class="gfw-consent__body"></p>
			<p class="gfw-consent__policy-link-wrap"><a href="#" class="gfw-consent__policy-link" target="_blank" rel="noopener"></a></p>
		</div>
		<div class="gfw-consent__actions">
			<button type="button" class="gfw-consent__btn gfw-consent__btn--secondary" data-gfw-action="preferences"></button>
			<button type="button" class="gfw-consent__btn gfw-consent__btn--secondary" data-gfw-action="reject"></button>
			<button type="button" class="gfw-consent__btn gfw-consent__btn--primary" data-gfw-action="accept"></button>
		</div>
	</div>

	<!-- Granular preferences modal -->
	<div class="gfw-consent__modal" role="dialog" aria-modal="true" aria-labelledby="gfw-consent-modal-title">
		<div class="gfw-consent__modal-inner">
			<div class="gfw-consent__modal-header">
				<h2 id="gfw-consent-modal-title" class="gfw-consent__title"></h2>
				<button type="button" class="gfw-consent__close" data-gfw-action="close" aria-label="Close">&times;</button>
			</div>
			<div class="gfw-consent__modal-body">
				<p class="gfw-consent__body"></p>
				<div class="gfw-consent__categories" role="group"></div>
			</div>
			<div class="gfw-consent__modal-footer">
				<button type="button" class="gfw-consent__btn gfw-consent__btn--secondary" data-gfw-action="reject"></button>
				<button type="button" class="gfw-consent__btn gfw-consent__btn--secondary" data-gfw-action="save"></button>
				<button type="button" class="gfw-consent__btn gfw-consent__btn--primary" data-gfw-action="accept"></button>
			</div>
		</div>
	</div>

	<!-- Floating "Cookie preferences" button — shown after initial consent -->
	<button type="button" class="gfw-consent__fab" data-gfw-action="preferences" aria-label="Cookie preferences">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"></path>
			<path d="M8.5 8.5v.01"></path>
			<path d="M16 15.5v.01"></path>
			<path d="M12 12v.01"></path>
			<path d="M11 17v.01"></path>
			<path d="M7 14v.01"></path>
		</svg>
	</button>

	<!-- Toast notification -->
	<div class="gfw-consent__toast" role="status" aria-live="polite">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
			<polyline points="20 6 9 17 4 12"></polyline>
		</svg>
		<span class="gfw-consent__toast-msg"></span>
	</div>

</div>
