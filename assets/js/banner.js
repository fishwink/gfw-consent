/**
 * GFW Consent — frontend runtime.
 *
 * Responsibilities:
 *   1. Read consent state from cookie
 *   2. Show/hide banner based on state + policy version
 *   3. Honor Global Privacy Control signal
 *   4. On consent, rehydrate blocked <script type="text/plain"> tags
 *   5. On consent, restore blocked iframes
 *   6. Send Google Consent Mode v2 update
 *   7. Log consent event to REST endpoint
 *   8. Handle preferences modal UX
 *   9. Show toast confirmation
 *  10. Show floating FAB for persistent preferences access
 */
(function () {
	'use strict';

	if ( typeof window.GFWConsent === 'undefined' ) return;

	var CFG = window.GFWConsent;
	var root;  // populated in boot() after DOM is parsed
	var toastTimeout = null;

	// -------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------

	function uuid() {
		if ( window.crypto && crypto.randomUUID ) return crypto.randomUUID();
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
			return v.toString(16);
		});
	}

	function setCookie(name, value, days) {
		var d = new Date();
		d.setTime(d.getTime() + (days * 86400000));
		var secure = location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax' + secure;
	}

	function getCookie(name) {
		var v = document.cookie.match('(^|;)\\s*' + name + '\\s*=\\s*([^;]+)');
		return v ? decodeURIComponent(v.pop()) : null;
	}

	function loadState() {
		var raw = getCookie(CFG.cookie);
		if ( ! raw ) return null;
		try {
			var s = JSON.parse(raw);
			if ( s.v !== CFG.policyVersion ) return null;
			return s;
		} catch (e) { return null; }
	}

	function saveState(state) {
		state.v = CFG.policyVersion;
		state.t = Date.now();
		setCookie(CFG.cookie, JSON.stringify(state), CFG.cookieDays || 180);
	}

	function gpcActive() {
		return !! (CFG.honorGpc && navigator.globalPrivacyControl === true);
	}

	function allCategories() {
		var cats = [];
		if ( CFG.categories.functional ) cats.push('functional');
		if ( CFG.categories.analytics )  cats.push('analytics');
		if ( CFG.categories.marketing )  cats.push('marketing');
		return cats;
	}

	// -------------------------------------------------------------
	// Script + iframe rehydration
	// -------------------------------------------------------------

	function categoryAllowed(cat, state) {
		if ( cat === 'essential' ) return true;
		return !! (state && state.c && state.c.indexOf(cat) !== -1);
	}

	function restoreScripts(state) {
		var blocked = document.querySelectorAll('script[type="text/plain"][data-gfw-category]');
		blocked.forEach(function (el) {
			var cat = el.getAttribute('data-gfw-category');
			if ( ! categoryAllowed(cat, state) ) return;

			var s = document.createElement('script');
			for ( var i = 0; i < el.attributes.length; i++ ) {
				var a = el.attributes[i];
				if ( a.name === 'type' ) continue;
				if ( a.name === 'data-gfw-src' ) { s.src = a.value; continue; }
				if ( a.name.indexOf('data-gfw-') === 0 ) continue;
				s.setAttribute(a.name, a.value);
			}
			if ( el.textContent ) s.text = el.textContent;
			el.parentNode.replaceChild(s, el);
		});
	}

	function restoreIframes(state) {
		var placeholders = document.querySelectorAll('.gfw-consent-placeholder');
		placeholders.forEach(function (el) {
			var cat = el.getAttribute('data-gfw-category');
			if ( ! categoryAllowed(cat, state) ) return;

			var src = el.getAttribute('data-gfw-src');
			var before = el.getAttribute('data-gfw-attrs-before') || '';
			var after = el.getAttribute('data-gfw-attrs-after') || '';
			var wrap = document.createElement('div');
			wrap.innerHTML = '<iframe' + before + ' src="' + src + '"' + after + '></iframe>';
			el.parentNode.replaceChild(wrap.firstChild, el);
		});
	}

	function pushConsentModeUpdate(state) {
		if ( typeof window.gtag !== 'function' ) return;
		var marketing = categoryAllowed('marketing', state) ? 'granted' : 'denied';
		var analytics = categoryAllowed('analytics', state) ? 'granted' : 'denied';
		var functional = categoryAllowed('functional', state) ? 'granted' : 'denied';
		window.gtag('consent', 'update', {
			ad_storage:              marketing,
			ad_user_data:            marketing,
			ad_personalization:      marketing,
			analytics_storage:       analytics,
			functionality_storage:   functional,
			personalization_storage: functional
		});
	}

	// -------------------------------------------------------------
	// Logging
	// -------------------------------------------------------------

	function logConsent(event, state) {
		if ( ! CFG.restUrl ) return;
		try {
			fetch(CFG.restUrl, {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CFG.restNonce
				},
				credentials: 'same-origin',
				body: JSON.stringify({
					consent_id:   state.id,
					event:        event,
					categories:   state.c || [],
					gpc_signal:   !! navigator.globalPrivacyControl,
					jurisdiction: CFG.jurisdiction
				})
			});
		} catch (e) {}
	}

	// -------------------------------------------------------------
	// Toast
	// -------------------------------------------------------------

	function showToast(message) {
		var toast = root.querySelector('.gfw-consent__toast');
		var msg = root.querySelector('.gfw-consent__toast-msg');
		if ( ! toast || ! msg ) return;

		msg.textContent = message || '';
		toast.classList.add('is-visible');

		if ( toastTimeout ) clearTimeout(toastTimeout);
		toastTimeout = setTimeout(function () {
			toast.classList.remove('is-visible');
		}, 2400);
	}

	// -------------------------------------------------------------
	// FAB (floating preferences button)
	// -------------------------------------------------------------

	function showFab() {
		var fab = root.querySelector('.gfw-consent__fab');
		if ( fab ) fab.classList.add('is-visible');
	}

	function hideFab() {
		var fab = root.querySelector('.gfw-consent__fab');
		if ( fab ) fab.classList.remove('is-visible');
	}

	// -------------------------------------------------------------
	// UI wiring
	// -------------------------------------------------------------

	function setupText() {
		// Titles (banner + modal)
		root.querySelectorAll('.gfw-consent__title').forEach(function (el) {
			el.textContent = CFG.texts.title || '';
		});
		// Bodies
		root.querySelectorAll('.gfw-consent__body').forEach(function (el) {
			el.textContent = CFG.texts.body || '';
		});
		// Action buttons (use querySelectorAll since each action label may appear in both banner + modal)
		root.querySelectorAll('[data-gfw-action="accept"]').forEach(function (el) {
			el.textContent = CFG.texts.accept || '';
		});
		root.querySelectorAll('[data-gfw-action="reject"]').forEach(function (el) {
			el.textContent = CFG.texts.reject || '';
		});
		var prefBtn = root.querySelector('.gfw-consent__banner [data-gfw-action="preferences"]');
		if ( prefBtn ) prefBtn.textContent = CFG.texts.preferences || '';
		var saveBtn = root.querySelector('[data-gfw-action="save"]');
		if ( saveBtn ) saveBtn.textContent = CFG.texts.save || '';

		// FAB aria label
		var fab = root.querySelector('.gfw-consent__fab');
		if ( fab ) fab.setAttribute('aria-label', CFG.strings.fab_label || 'Cookie preferences');

		// Policy link
		var pl = root.querySelector('.gfw-consent__policy-link');
		if ( pl ) {
			if ( CFG.policyUrl ) {
				pl.href = CFG.policyUrl;
				pl.textContent = CFG.strings.policy_link || '';
			} else {
				pl.parentNode.style.display = 'none';
			}
		}

		root.setAttribute('data-layout', CFG.layout);
		root.setAttribute('data-position', CFG.position);
	}

	function buildCategories(state) {
		var container = root.querySelector('.gfw-consent__categories');
		if ( ! container ) return;
		container.innerHTML = '';

		var cats = [
			{ key: 'essential',  label: CFG.strings.cat_essential,  desc: CFG.strings.cat_essential_desc,  always: true,  show: true },
			{ key: 'functional', label: CFG.strings.cat_functional, desc: CFG.strings.cat_functional_desc, show: CFG.categories.functional },
			{ key: 'analytics',  label: CFG.strings.cat_analytics,  desc: CFG.strings.cat_analytics_desc,  show: CFG.categories.analytics },
			{ key: 'marketing',  label: CFG.strings.cat_marketing,  desc: CFG.strings.cat_marketing_desc,  show: CFG.categories.marketing }
		];

		cats.forEach(function (cat) {
			if ( ! cat.show ) return;
			var checked = cat.always ? true : categoryAllowed(cat.key, state);
			// Using divs (not h3) for titles — themes often style headings globally
			var html = '' +
				'<div class="gfw-consent__category">' +
					'<div class="gfw-consent__category-info">' +
						'<div class="gfw-consent__category-title"></div>' +
						'<div class="gfw-consent__category-desc"></div>' +
					'</div>' +
					( cat.always
						? '<span class="gfw-consent__category-status"></span>'
						: '<label class="gfw-consent__switch"><input type="checkbox" data-gfw-cat="' + cat.key + '"' + (checked ? ' checked' : '') + '><span class="gfw-consent__slider"></span></label>'
					) +
				'</div>';

			var wrap = document.createElement('div');
			wrap.innerHTML = html;
			var node = wrap.firstChild;

			// Set text via textContent to be safe against any user-supplied markup
			node.querySelector('.gfw-consent__category-title').textContent = cat.label || '';
			node.querySelector('.gfw-consent__category-desc').textContent = cat.desc || '';
			if ( cat.always ) {
				node.querySelector('.gfw-consent__category-status').textContent = CFG.strings.essential_on || '';
			}

			container.appendChild(node);
		});
	}

	function showBanner() { root.setAttribute('data-state', 'banner'); root.setAttribute('aria-hidden', 'false'); }
	function showModal()  { root.setAttribute('data-state', 'modal');  root.setAttribute('aria-hidden', 'false'); }
	function hideAll()    { root.setAttribute('data-state', 'hidden'); root.setAttribute('aria-hidden', 'true'); }

	// -------------------------------------------------------------
	// Actions
	// -------------------------------------------------------------

	function handleAction(action) {
		var existing = loadState() || { id: uuid(), c: [] };
		var state;
		var inModal = root.getAttribute('data-state') === 'modal';

		if ( action === 'accept' ) {
			state = { id: existing.id, c: allCategories() };
			saveState(state);
			logConsent('accept', state);
			applyConsent(state);
			hideAll();
			showFab();
			showToast(CFG.strings.toast_accept);
		} else if ( action === 'reject' ) {
			state = { id: existing.id, c: [] };
			saveState(state);
			logConsent('reject', state);
			applyConsent(state);
			hideAll();
			showFab();
			showToast(CFG.strings.toast_reject);
		} else if ( action === 'preferences' ) {
			buildCategories(existing);
			showModal();
		} else if ( action === 'save' ) {
			var selected = [];
			root.querySelectorAll('[data-gfw-cat]').forEach(function (el) {
				if ( el.checked ) selected.push(el.getAttribute('data-gfw-cat'));
			});
			state = { id: existing.id, c: selected };
			saveState(state);
			logConsent('custom', state);
			applyConsent(state);
			hideAll();
			showFab();
			showToast(CFG.strings.toast_custom);
		} else if ( action === 'close' ) {
			// If consent already exists, just close the modal fully
			// Otherwise return to the banner (don't lose first-visit state)
			if ( loadState() ) {
				hideAll();
			} else {
				showBanner();
			}
		}
	}

	function applyConsent(state) {
		pushConsentModeUpdate(state);
		restoreScripts(state);
		restoreIframes(state);
		document.dispatchEvent(new CustomEvent('gfw:consent', { detail: state }));
	}

	function bindEvents() {
		// Delegate clicks within the consent UI
		root.addEventListener('click', function (e) {
			var btn = e.target.closest('[data-gfw-action]');
			if ( btn ) {
				e.preventDefault();
				handleAction(btn.getAttribute('data-gfw-action'));
			}
		});

		// Allow preferences trigger from anywhere on the page (footer shortcode, etc.)
		document.addEventListener('click', function (e) {
			var open = e.target.closest('.gfw-consent-open-preferences, [data-gfw-open="preferences"]');
			if ( open && ! root.contains(open) ) {
				e.preventDefault();
				handleAction('preferences');
			}
		});

		// Esc to close modal
		document.addEventListener('keydown', function (e) {
			if ( e.key === 'Escape' && root.getAttribute('data-state') === 'modal' ) {
				handleAction('close');
			}
		});

		// Click outside modal to close (only if consent already exists)
		var modal = root.querySelector('.gfw-consent__modal');
		if ( modal ) {
			modal.addEventListener('click', function (e) {
				if ( e.target === modal && loadState() ) {
					handleAction('close');
				}
			});
		}
	}

	// -------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------

	function boot() {
		root = document.getElementById('gfw-consent');
		if ( ! root ) return;

		setupText();
		bindEvents();

		var state = loadState();

		// GPC takes precedence over absence of consent — auto-reject silently
		if ( ! state && gpcActive() ) {
			var auto = { id: uuid(), c: [] };
			saveState(auto);
			logConsent('gpc_auto', auto);
			applyConsent(auto);
			hideAll();
			showFab();
			return;
		}

		if ( state ) {
			applyConsent(state);
			hideAll();
			showFab();
		} else {
			showBanner();
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	// Public API for theme developers
	window.GFWConsentAPI = {
		openPreferences: function () { if ( root ) handleAction('preferences'); },
		getState:        function () { return loadState(); },
		withdraw:        function () {
			var state = { id: (loadState() || {}).id || uuid(), c: [] };
			saveState(state);
			logConsent('withdraw', state);
			if ( root ) {
				applyConsent(state);
				showToast(CFG.strings.toast_reject);
			}
		}
	};
})();
