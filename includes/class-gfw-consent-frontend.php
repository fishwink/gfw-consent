<?php
/**
 * Frontend.
 *
 * - Injects Google Consent Mode v2 defaults (before any blocked scripts
 *   could in theory fire).
 * - Enqueues banner CSS/JS.
 * - Renders the banner container in the footer.
 * - Exposes runtime settings to JS via localized data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFW_Consent_Frontend {

	public function __construct() {
		if ( ! GFW_Consent_Core::get_setting( 'enabled', 1 ) ) {
			return;
		}
		if ( is_admin() ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'consent_mode_defaults' ), 0 );
		add_action( 'wp_head', array( $this, 'inline_css_vars' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'render_banner' ), 99 );
	}

	/**
	 * Whether any non-essential category is enabled in settings.
	 * If none are, we suppress the consent UI entirely — essential cookies
	 * don't require consent under GDPR/CCPA, and showing a banner for
	 * nothing is misleading UX.
	 */
	public static function has_non_essential_categories() {
		$s = get_option( GFW_CONSENT_OPT_KEY, array() );
		return ! empty( $s['cat_preferences'] )
			|| ! empty( $s['cat_analytics'] )
			|| ! empty( $s['cat_marketing'] );
	}

	/**
	 * Generate honest default banner body text based on which categories
	 * are actually enabled. Admins can override via Settings.
	 */
	public static function get_smart_body_text() {
		$s    = get_option( GFW_CONSENT_OPT_KEY, array() );
		$bits = array();

		if ( ! empty( $s['cat_analytics'] ) ) {
			$bits[] = __( 'understand how visitors use our site', 'gfw-consent' );
		}
		if ( ! empty( $s['cat_marketing'] ) ) {
			$bits[] = __( 'measure advertising performance', 'gfw-consent' );
		}
		if ( ! empty( $s['cat_preferences'] ) ) {
			$bits[] = __( 'enable features like embedded content and chat', 'gfw-consent' );
		}

		if ( empty( $bits ) ) {
			return __( 'This site only uses cookies required for basic functionality.', 'gfw-consent' );
		}

		// Grammatical join: "x", "x and y", "x, y, and z"
		if ( 1 === count( $bits ) ) {
			$list = $bits[0];
		} elseif ( 2 === count( $bits ) ) {
			$list = $bits[0] . ' ' . __( 'and', 'gfw-consent' ) . ' ' . $bits[1];
		} else {
			$last = array_pop( $bits );
			$list = implode( ', ', $bits ) . ', ' . __( 'and', 'gfw-consent' ) . ' ' . $last;
		}

		return sprintf(
			/* translators: %s: human-readable list of purposes, e.g. "analyze traffic and measure ads" */
			__( 'We use cookies to %s. You can accept, reject non-essential, or manage your preferences.', 'gfw-consent' ),
			$list
		);
	}

	/**
	 * Google Consent Mode v2 defaults — DENIED until user acts.
	 * This runs before any gtag script. It must exist even if gtag
	 * is never loaded (harmless if not).
	 */
	public function consent_mode_defaults() {
		if ( GFW_Consent_Core::is_editor_context() ) {
			return;
		}
		if ( ! GFW_Consent_Core::get_setting( 'consent_mode_v2', 1 ) ) {
			return;
		}
		?>
		<!-- GFW Consent: Google Consent Mode v2 defaults -->
		<script data-gfw-consent-mode="1">
			window.dataLayer = window.dataLayer || [];
			function gtag(){dataLayer.push(arguments);}
			gtag('consent', 'default', {
				'ad_storage':            'denied',
				'ad_user_data':          'denied',
				'ad_personalization':    'denied',
				'analytics_storage':     'denied',
				'functionality_storage': 'denied',
				'personalization_storage':'denied',
				'security_storage':      'granted',
				'wait_for_update':       500
			});
			gtag('set', 'ads_data_redaction', true);
			gtag('set', 'url_passthrough', true);
		</script>
		<?php
	}

	/**
	 * Output branding CSS custom properties inline so styling applies
	 * before external CSS loads (no flash of unbranded banner).
	 */
	public function inline_css_vars() {
		if ( GFW_Consent_Core::is_editor_context() ) {
			return;
		}
		$s = get_option( GFW_CONSENT_OPT_KEY, array() );
		$vars = array(
			'--gfw-primary'         => isset( $s['brand_primary'] ) ? $s['brand_primary'] : '#1a1a1a',
			'--gfw-primary-text'    => isset( $s['brand_primary_text'] ) ? $s['brand_primary_text'] : '#ffffff',
			'--gfw-bg'              => isset( $s['brand_bg'] ) ? $s['brand_bg'] : '#ffffff',
			'--gfw-text'            => isset( $s['brand_text'] ) ? $s['brand_text'] : '#1a1a1a',
			'--gfw-border'          => isset( $s['brand_border'] ) ? $s['brand_border'] : '#e5e5e5',
			'--gfw-radius'          => ( isset( $s['brand_radius'] ) ? intval( $s['brand_radius'] ) : 8 ) . 'px',
			'--gfw-font'            => isset( $s['brand_font'] ) ? $s['brand_font'] : 'inherit',
		);

		$css = ':root{';
		foreach ( $vars as $k => $v ) {
			$css .= $k . ':' . esc_attr( $v ) . ';';
		}
		$css .= '}';

		echo '<style id="gfw-consent-vars">' . $css . '</style>';
	}

	public function enqueue_assets() {
		if ( GFW_Consent_Core::is_editor_context() ) {
			return;
		}
		// If no non-essential categories are enabled, no banner will render —
		// skip the CSS/JS entirely.
		if ( ! self::has_non_essential_categories() ) {
			return;
		}

		wp_register_style(
			'gfw-consent',
			GFW_CONSENT_URL . 'assets/css/banner.css',
			array(),
			GFW_CONSENT_VERSION
		);
		wp_register_script(
			'gfw-consent',
			GFW_CONSENT_URL . 'assets/js/banner.js',
			array(),
			GFW_CONSENT_VERSION,
			true
		);

		$s            = get_option( GFW_CONSENT_OPT_KEY, array() );
		$policy_page  = ! empty( $s['policy_page_id'] ) ? get_permalink( $s['policy_page_id'] ) : '';

		wp_localize_script( 'gfw-consent', 'GFWConsent', array(
			'cookie'         => GFW_CONSENT_COOKIE,
			'cookieDays'     => 180,
			'policyVersion'  => isset( $s['policy_version'] ) ? $s['policy_version'] : '1.0',
			'policyUrl'      => $policy_page,
			'restUrl'        => esc_url_raw( rest_url( 'gfw-consent/v1/log' ) ),
			'restNonce'      => wp_create_nonce( 'wp_rest' ),
			'honorGpc'       => ! empty( $s['honor_gpc'] ) ? 1 : 0,
			'rejectEquals'   => ! empty( $s['reject_equals_accept'] ) ? 1 : 0,
			'jurisdiction'   => $this->detect_jurisdiction(),
			'layout'         => isset( $s['brand_layout'] ) ? $s['brand_layout'] : 'bar',
			'position'       => isset( $s['brand_position'] ) ? $s['brand_position'] : 'bottom',
			'categories'     => array(
				'functional'  => ! empty( $s['cat_preferences'] ),
				'analytics'   => ! empty( $s['cat_analytics'] ),
				'marketing'   => ! empty( $s['cat_marketing'] ),
			),
			'strings'        => array(
				'essential_on'        => __( 'Always on', 'gfw-consent' ),
				'cat_essential'       => __( 'Strictly necessary', 'gfw-consent' ),
				'cat_functional'      => __( 'Functional', 'gfw-consent' ),
				'cat_analytics'       => __( 'Analytics', 'gfw-consent' ),
				'cat_marketing'       => __( 'Marketing', 'gfw-consent' ),
				'cat_essential_desc'  => __( 'Required for the site to function. Cannot be disabled.', 'gfw-consent' ),
				'cat_functional_desc' => __( 'Enable features like maps, chat, and embedded content.', 'gfw-consent' ),
				'cat_analytics_desc'  => __( 'Help us understand how visitors use the site.', 'gfw-consent' ),
				'cat_marketing_desc'  => __( 'Used for ad measurement and personalized marketing.', 'gfw-consent' ),
				'policy_link'         => __( 'View Cookie Policy', 'gfw-consent' ),
				'close'               => __( 'Close', 'gfw-consent' ),
				'fab_label'           => __( 'Cookie preferences', 'gfw-consent' ),
				'toast_accept'        => __( 'All cookies accepted', 'gfw-consent' ),
				'toast_reject'        => __( 'Non-essential cookies rejected', 'gfw-consent' ),
				'toast_custom'        => __( 'Preferences saved', 'gfw-consent' ),
			),
			'texts'          => array(
				'title'       => isset( $s['banner_title'] ) ? $s['banner_title'] : '',
				'body'        => ! empty( $s['banner_body'] ) ? $s['banner_body'] : self::get_smart_body_text(),
				'accept'      => isset( $s['btn_accept'] ) ? $s['btn_accept'] : '',
				'reject'      => isset( $s['btn_reject'] ) ? $s['btn_reject'] : '',
				'preferences' => isset( $s['btn_preferences'] ) ? $s['btn_preferences'] : '',
				'save'        => isset( $s['btn_save'] ) ? $s['btn_save'] : '',
			),
		) );

		wp_enqueue_style( 'gfw-consent' );
		wp_enqueue_script( 'gfw-consent' );
	}

	/**
	 * Best-effort jurisdiction detection from Cloudflare country header,
	 * with fallback to manual override.
	 */
	private function detect_jurisdiction() {
		$mode = GFW_Consent_Core::get_setting( 'jurisdiction_mode', 'auto' );
		if ( 'eu' === $mode ) {
			return 'eu';
		}
		if ( 'us' === $mode ) {
			return 'us';
		}

		$eu_countries = array(
			'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','GR','HU','IE','IT',
			'LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','IS','LI','NO','GB',
		);

		$country = '';
		if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$country = strtoupper( substr( $_SERVER['HTTP_CF_IPCOUNTRY'], 0, 2 ) );
		}
		if ( $country && in_array( $country, $eu_countries, true ) ) {
			return 'eu';
		}
		return 'us';
	}

	public function render_banner() {
		if ( GFW_Consent_Core::is_editor_context() ) {
			return;
		}
		// Suppress banner entirely if no non-essential categories are enabled.
		// Essential-only sites don't need consent under GDPR/CCPA, and showing
		// a banner for nothing misleads users about what the site actually does.
		if ( ! self::has_non_essential_categories() ) {
			return;
		}
		include GFW_CONSENT_PATH . 'templates/banner.php';
	}
}
