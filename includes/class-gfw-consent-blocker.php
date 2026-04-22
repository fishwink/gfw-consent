<?php
/**
 * Script Blocker.
 *
 * Captures rendered HTML via output buffering and rewrites any <script> tag
 * (by src or by known inline substrings) whose service category has not been
 * consented to. Blocked scripts become type="text/plain" and gain a
 * data-gfw-src / data-gfw-category attribute so the frontend JS can rehydrate
 * them on consent.
 *
 * Iframes (YouTube, Vimeo, Maps) are swapped to click-to-load placeholders
 * when their category isn't consented.
 *
 * Notes on caching: the page output is identical for every visitor (banner
 * always rendered, all marketing scripts always blocked at PHP time). The
 * frontend JS reads the consent cookie and restores scripts client-side.
 * This keeps LiteSpeed Cache / Cloudflare full-page caching compatible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFW_Consent_Blocker {

	/**
	 * Patterns to block (populated from services catalog).
	 */
	private $patterns = array();

	/**
	 * Map of pattern => service key for debug / attribution.
	 */
	private $pattern_map = array();

	public function __construct() {
		if ( ! GFW_Consent_Core::get_setting( 'enabled', 1 ) ) {
			return;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$this->build_patterns();

		// Start output buffering as early as possible.
		add_action( 'template_redirect', array( $this, 'start_buffer' ), -9999 );
	}

	private function build_patterns() {
		// We always block everything non-essential at PHP render time and let
		// JS selectively re-enable post-consent. This means the blocker's
		// pattern list is every non-essential category.
		$categories_to_block = array( 'analytics', 'marketing' );
		// Functional services (reCAPTCHA, Maps, Chat) are considered opt-out via preferences
		// — we still block them until consent so users can decline them in the preferences modal.
		$categories_to_block[] = 'functional';

		foreach ( GFW_Consent_Services::catalog() as $key => $svc ) {
			if ( ! in_array( $svc['category'], $categories_to_block, true ) ) {
				continue;
			}
			foreach ( $svc['patterns'] as $p ) {
				$this->patterns[]           = $p;
				$this->pattern_map[ $p ]    = $key;
			}
		}
	}

	public function start_buffer() {
		// Don't buffer admin-ajax, REST, feeds, XML output, RSS, sitemaps.
		if ( is_feed() || is_robots() || is_trackback() ) {
			return;
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			return;
		}
		ob_start( array( $this, 'filter_output' ) );
	}

	/**
	 * Rewrite the buffered HTML.
	 */
	public function filter_output( $html ) {
		if ( empty( $html ) || false === stripos( $html, '<script' ) ) {
			return $html;
		}

		// Process <script src="..."> tags.
		$html = preg_replace_callback(
			'#<script\b([^>]*?)\bsrc\s*=\s*(["\'])(.*?)\2([^>]*)></script>#is',
			array( $this, 'replace_src_script' ),
			$html
		);

		// Process inline <script>...</script> tags with known service fingerprints.
		$html = preg_replace_callback(
			'#<script\b(?![^>]*\bsrc\s*=)([^>]*)>(.*?)</script>#is',
			array( $this, 'replace_inline_script' ),
			$html
		);

		// Process iframes for known embed services (YouTube, Vimeo, Maps).
		$html = preg_replace_callback(
			'#<iframe\b([^>]*?)\bsrc\s*=\s*(["\'])(.*?)\2([^>]*)></iframe>#is',
			array( $this, 'replace_iframe' ),
			$html
		);

		return $html;
	}

	private function match_category( $haystack ) {
		foreach ( $this->patterns as $pattern ) {
			if ( false !== stripos( $haystack, $pattern ) ) {
				$key      = $this->pattern_map[ $pattern ];
				$category = GFW_Consent_Services::category_of( $key );
				return array( $key, $category );
			}
		}
		return false;
	}

	/**
	 * Replace a <script src=...> that matches a blocked service.
	 */
	public function replace_src_script( $m ) {
		list( $full, $attrs_before, $quote, $src, $attrs_after ) = $m;

		// Skip our own scripts.
		if ( false !== stripos( $full, 'gfw-consent' ) ) {
			return $full;
		}

		$match = $this->match_category( $src );
		if ( ! $match ) {
			return $full;
		}

		list( $svc_key, $category ) = $match;

		// Strip any existing type= from the attribute strings.
		$attrs_before = preg_replace( '/\btype\s*=\s*(["\']).*?\1/i', '', $attrs_before );
		$attrs_after  = preg_replace( '/\btype\s*=\s*(["\']).*?\1/i', '', $attrs_after );

		return sprintf(
			'<script type="text/plain" data-gfw-category="%s" data-gfw-service="%s" data-gfw-src="%s"%s%s></script>',
			esc_attr( $category ),
			esc_attr( $svc_key ),
			esc_attr( $src ),
			$attrs_before,
			$attrs_after
		);
	}

	/**
	 * Replace an inline <script> whose body contains a known service fingerprint.
	 * This catches things like the Meta Pixel or GTM inline snippets.
	 */
	public function replace_inline_script( $m ) {
		list( $full, $attrs, $body ) = $m;

		// Skip our own scripts + empty/JSON-LD.
		if ( false !== stripos( $attrs, 'application/ld+json' ) ) {
			return $full;
		}
		if ( false !== stripos( $attrs, 'gfw-consent' ) ) {
			return $full;
		}

		$match = $this->match_category( $body );
		if ( ! $match ) {
			return $full;
		}

		list( $svc_key, $category ) = $match;

		$attrs = preg_replace( '/\btype\s*=\s*(["\']).*?\1/i', '', $attrs );

		return sprintf(
			'<script type="text/plain" data-gfw-category="%s" data-gfw-service="%s"%s>%s</script>',
			esc_attr( $category ),
			esc_attr( $svc_key ),
			$attrs,
			$body
		);
	}

	/**
	 * Swap blocked iframes with click-to-load placeholders.
	 */
	public function replace_iframe( $m ) {
		list( $full, $attrs_before, $quote, $src, $attrs_after ) = $m;

		$match = $this->match_category( $src );
		if ( ! $match ) {
			return $full;
		}

		list( $svc_key, $category ) = $match;
		$svc   = GFW_Consent_Services::catalog()[ $svc_key ];
		$label = sprintf(
			/* translators: 1: service name */
			__( 'Load %s content', 'gfw-consent' ),
			$svc['name']
		);
		$desc = sprintf(
			/* translators: 1: service name */
			__( 'This embed is blocked until you consent to %s cookies.', 'gfw-consent' ),
			$svc['name']
		);

		return sprintf(
			'<div class="gfw-consent-placeholder" data-gfw-category="%s" data-gfw-service="%s" data-gfw-src="%s" data-gfw-attrs-before="%s" data-gfw-attrs-after="%s">
				<div class="gfw-consent-placeholder-inner">
					<p class="gfw-consent-placeholder-desc">%s</p>
					<button type="button" class="gfw-consent-placeholder-btn">%s</button>
				</div>
			</div>',
			esc_attr( $category ),
			esc_attr( $svc_key ),
			esc_attr( $src ),
			esc_attr( $attrs_before ),
			esc_attr( $attrs_after ),
			esc_html( $desc ),
			esc_html( $label )
		);
	}
}
