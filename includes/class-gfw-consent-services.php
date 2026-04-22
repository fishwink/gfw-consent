<?php
/**
 * Service/tracker catalog.
 *
 * Central registry of known third-party services. Each entry:
 *   - key:        unique slug
 *   - name:       display name
 *   - vendor:     company
 *   - category:   essential | functional | analytics | marketing | preferences
 *   - patterns:   array of substrings found in <script src> or inline script bodies
 *   - cookies:    array of cookie name patterns (can include wildcards via *)
 *   - privacy:    URL to privacy policy
 *   - purpose:    short human description for auto-generated cookie policy
 *   - retention:  human-readable retention (best effort)
 *
 * Add new services here. This is your primary maintenance surface going forward.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFW_Consent_Services {

	/**
	 * Full service catalog = built-in registry + per-site custom services
	 * (managed via the "Custom services" admin tab) + optional filter.
	 *
	 * Built-in slugs always win on collision — we use `+` (not array_merge)
	 * to preserve left-hand keys. Custom entries missing patterns or slug
	 * are dropped during normalization.
	 */
	public static function catalog() {
		$merged = self::built_in() + self::normalize_custom( get_option( GFW_CONSENT_CUSTOM_KEY, array() ) );
		return apply_filters( 'gfw_consent_services_catalog', $merged );
	}

	/**
	 * Normalize the stored custom-services list (flat indexed array) into
	 * the slug=>entry map that the rest of the plugin expects. Entries
	 * missing a slug or patterns are silently dropped.
	 */
	private static function normalize_custom( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			if ( empty( $entry['slug'] ) || empty( $entry['patterns'] ) ) {
				continue;
			}
			$out[ $entry['slug'] ] = $entry;
		}
		return $out;
	}

	/**
	 * Built-in service registry. Add new well-known services here.
	 */
	private static function built_in() {
		return array(

			// ---------------------------------------------------------------
			// ANALYTICS
			// ---------------------------------------------------------------
			'google-analytics' => array(
				'name'      => 'Google Analytics 4',
				'vendor'    => 'Google LLC',
				'category'  => 'analytics',
				'patterns'  => array( 'googletagmanager.com/gtag/js', 'google-analytics.com/analytics.js', 'google-analytics.com/g/collect' ),
				'cookies'   => array( '_ga', '_ga_*', '_gid', '_gat*', '_gac_*', '__utm*' ),
				'privacy'   => 'https://policies.google.com/privacy',
				'purpose'   => 'Measures website usage and performance.',
				'retention' => 'Up to 2 years',
			),
			'google-tag-manager' => array(
				'name'      => 'Google Tag Manager',
				'vendor'    => 'Google LLC',
				'category'  => 'analytics',
				'patterns'  => array( 'googletagmanager.com/gtm.js', 'googletagmanager.com/ns.html' ),
				'cookies'   => array(),
				'privacy'   => 'https://policies.google.com/privacy',
				'purpose'   => 'Tag management container used to load marketing and analytics scripts.',
				'retention' => 'Session',
			),
			'microsoft-clarity' => array(
				'name'      => 'Microsoft Clarity',
				'vendor'    => 'Microsoft',
				'category'  => 'analytics',
				'patterns'  => array( 'clarity.ms/tag', 'www.clarity.ms' ),
				'cookies'   => array( '_clck', '_clsk', 'CLID', 'ANONCHK', 'MR', 'MUID', 'SM' ),
				'privacy'   => 'https://privacy.microsoft.com/privacystatement',
				'purpose'   => 'Session recordings and heatmaps to improve site usability.',
				'retention' => 'Up to 1 year',
			),
			'hotjar' => array(
				'name'      => 'Hotjar',
				'vendor'    => 'Hotjar Ltd.',
				'category'  => 'analytics',
				'patterns'  => array( 'static.hotjar.com', 'script.hotjar.com' ),
				'cookies'   => array( '_hjSessionUser_*', '_hjSession_*', '_hjid', '_hjIncludedInSessionSample*', '_hjIncludedInPageviewSample', '_hjAbsoluteSessionInProgress', '_hjFirstSeen', '_hjViewportId', '_hjTLDTest' ),
				'privacy'   => 'https://www.hotjar.com/legal/policies/privacy/',
				'purpose'   => 'Session recordings, heatmaps, and behavioral analytics.',
				'retention' => 'Up to 365 days',
			),
			'matomo' => array(
				'name'      => 'Matomo',
				'vendor'    => 'Matomo',
				'category'  => 'analytics',
				'patterns'  => array( 'matomo.js', 'matomo.php', 'piwik.js' ),
				'cookies'   => array( '_pk_id*', '_pk_ses*', '_pk_ref*', '_pk_cvar*' ),
				'privacy'   => 'https://matomo.org/privacy-policy/',
				'purpose'   => 'Privacy-focused website analytics.',
				'retention' => 'Up to 13 months',
			),
			'fullstory' => array(
				'name'      => 'FullStory',
				'vendor'    => 'FullStory, Inc.',
				'category'  => 'analytics',
				'patterns'  => array( 'edge.fullstory.com', 'fs.js' ),
				'cookies'   => array( 'fs_uid', 'fs_lua' ),
				'privacy'   => 'https://www.fullstory.com/legal/privacy-policy/',
				'purpose'   => 'Session replay and digital experience analytics.',
				'retention' => 'Up to 1 year',
			),
			'mixpanel' => array(
				'name'      => 'Mixpanel',
				'vendor'    => 'Mixpanel, Inc.',
				'category'  => 'analytics',
				'patterns'  => array( 'cdn.mxpnl.com', 'mixpanel' ),
				'cookies'   => array( 'mp_*' ),
				'privacy'   => 'https://mixpanel.com/legal/privacy-policy/',
				'purpose'   => 'Product analytics.',
				'retention' => 'Up to 1 year',
			),

			// ---------------------------------------------------------------
			// MARKETING / ADVERTISING
			// ---------------------------------------------------------------
			'google-ads' => array(
				'name'      => 'Google Ads / Conversion Tracking',
				'vendor'    => 'Google LLC',
				'category'  => 'marketing',
				'patterns'  => array( 'googleadservices.com', 'googlesyndication.com', 'doubleclick.net' ),
				'cookies'   => array( 'IDE', 'test_cookie', 'DSID', 'FLC', 'AID', 'TAID', '_gcl_*' ),
				'privacy'   => 'https://policies.google.com/privacy',
				'purpose'   => 'Ad conversion tracking and remarketing.',
				'retention' => 'Up to 2 years',
			),
			'meta-pixel' => array(
				'name'      => 'Meta Pixel (Facebook)',
				'vendor'    => 'Meta Platforms, Inc.',
				'category'  => 'marketing',
				'patterns'  => array( 'connect.facebook.net', 'facebook.com/tr' ),
				'cookies'   => array( '_fbp', 'fr', '_fbc' ),
				'privacy'   => 'https://www.facebook.com/privacy/policy/',
				'purpose'   => 'Tracks conversions from Facebook/Instagram ads and enables remarketing audiences.',
				'retention' => 'Up to 90 days',
			),
			'tiktok-pixel' => array(
				'name'      => 'TikTok Pixel',
				'vendor'    => 'TikTok / ByteDance',
				'category'  => 'marketing',
				'patterns'  => array( 'analytics.tiktok.com' ),
				'cookies'   => array( '_ttp' ),
				'privacy'   => 'https://www.tiktok.com/legal/privacy-policy',
				'purpose'   => 'TikTok ad conversion tracking and audience building.',
				'retention' => 'Up to 13 months',
			),
			'linkedin-insight' => array(
				'name'      => 'LinkedIn Insight Tag',
				'vendor'    => 'LinkedIn Corporation',
				'category'  => 'marketing',
				'patterns'  => array( 'snap.licdn.com', 'px.ads.linkedin.com' ),
				'cookies'   => array( 'li_sugr', 'bcookie', 'lidc', 'UserMatchHistory', 'AnalyticsSyncHistory' ),
				'privacy'   => 'https://www.linkedin.com/legal/privacy-policy',
				'purpose'   => 'LinkedIn ad conversion tracking and remarketing.',
				'retention' => 'Up to 2 years',
			),
			'pinterest-tag' => array(
				'name'      => 'Pinterest Tag',
				'vendor'    => 'Pinterest, Inc.',
				'category'  => 'marketing',
				'patterns'  => array( 's.pinimg.com/ct/', 'ct.pinterest.com' ),
				'cookies'   => array( '_pinterest_*', '_pin_unauth' ),
				'privacy'   => 'https://policy.pinterest.com/en/privacy-policy',
				'purpose'   => 'Pinterest ad conversion tracking.',
				'retention' => 'Up to 1 year',
			),
			'snap-pixel' => array(
				'name'      => 'Snap Pixel',
				'vendor'    => 'Snap Inc.',
				'category'  => 'marketing',
				'patterns'  => array( 'sc-static.net/scevent', 'tr.snapchat.com' ),
				'cookies'   => array( 'sc_at', '_scid' ),
				'privacy'   => 'https://snap.com/en-US/privacy/privacy-policy',
				'purpose'   => 'Snapchat ad conversion tracking.',
				'retention' => 'Up to 1 year',
			),
			'twitter-pixel' => array(
				'name'      => 'X / Twitter Pixel',
				'vendor'    => 'X Corp.',
				'category'  => 'marketing',
				'patterns'  => array( 'static.ads-twitter.com', 'analytics.twitter.com' ),
				'cookies'   => array( 'personalization_id', 'muc_ads' ),
				'privacy'   => 'https://twitter.com/en/privacy',
				'purpose'   => 'X (Twitter) ad conversion tracking.',
				'retention' => 'Up to 2 years',
			),
			'bing-uet' => array(
				'name'      => 'Microsoft Advertising UET',
				'vendor'    => 'Microsoft',
				'category'  => 'marketing',
				'patterns'  => array( 'bat.bing.com' ),
				'cookies'   => array( 'MUID', '_uetsid', '_uetvid' ),
				'privacy'   => 'https://privacy.microsoft.com/privacystatement',
				'purpose'   => 'Bing/Microsoft ads conversion tracking.',
				'retention' => 'Up to 13 months',
			),
			'reddit-pixel' => array(
				'name'      => 'Reddit Pixel',
				'vendor'    => 'Reddit, Inc.',
				'category'  => 'marketing',
				'patterns'  => array( 'www.redditstatic.com/ads/pixel.js' ),
				'cookies'   => array( '_rdt_uuid' ),
				'privacy'   => 'https://www.reddit.com/policies/privacy-policy',
				'purpose'   => 'Reddit ad conversion tracking.',
				'retention' => 'Up to 90 days',
			),
			'marchex' => array(
				'name'      => 'Marchex Call Tracking',
				'vendor'    => 'Marchex',
				'category'  => 'marketing',
				'patterns'  => array( 'marchex.io', 'mchx.tv' ),
				'cookies'   => array( 'mchx_*' ),
				'privacy'   => 'https://www.marchex.com/privacy/',
				'purpose'   => 'Phone call tracking and attribution.',
				'retention' => 'Up to 2 years',
			),
			'callrail' => array(
				'name'      => 'CallRail',
				'vendor'    => 'CallRail',
				'category'  => 'marketing',
				'patterns'  => array( 'cdn.callrail.com' ),
				'cookies'   => array( '_cr_sess', '_cr_cs' ),
				'privacy'   => 'https://www.callrail.com/privacy',
				'purpose'   => 'Phone call tracking and attribution.',
				'retention' => 'Up to 2 years',
			),
			'hubspot' => array(
				'name'      => 'HubSpot',
				'vendor'    => 'HubSpot, Inc.',
				'category'  => 'marketing',
				'patterns'  => array( 'js.hs-scripts.com', 'js.hs-analytics.net', 'js.hsforms.net', 'js.hubspot.com' ),
				'cookies'   => array( 'hubspotutk', '__hssc', '__hssrc', '__hstc' ),
				'privacy'   => 'https://legal.hubspot.com/privacy-policy',
				'purpose'   => 'Marketing automation and visitor tracking.',
				'retention' => 'Up to 13 months',
			),

			// ---------------------------------------------------------------
			// EMBEDS (treat as marketing/functional per use)
			// ---------------------------------------------------------------
			'youtube' => array(
				'name'      => 'YouTube Embeds',
				'vendor'    => 'Google LLC',
				'category'  => 'marketing',
				'patterns'  => array( 'youtube.com/embed', 'youtube-nocookie.com/embed' ),
				'cookies'   => array( 'VISITOR_INFO1_LIVE', 'YSC', 'PREF', 'GPS' ),
				'privacy'   => 'https://policies.google.com/privacy',
				'purpose'   => 'Embedded YouTube video player.',
				'retention' => 'Up to 8 months',
			),
			'vimeo' => array(
				'name'      => 'Vimeo Embeds',
				'vendor'    => 'Vimeo, Inc.',
				'category'  => 'marketing',
				'patterns'  => array( 'player.vimeo.com' ),
				'cookies'   => array( 'vuid' ),
				'privacy'   => 'https://vimeo.com/privacy',
				'purpose'   => 'Embedded Vimeo video player.',
				'retention' => 'Up to 2 years',
			),
			'google-maps' => array(
				'name'      => 'Google Maps',
				'vendor'    => 'Google LLC',
				'category'  => 'functional',
				'patterns'  => array( 'maps.googleapis.com', 'maps.google.com' ),
				'cookies'   => array( 'NID' ),
				'privacy'   => 'https://policies.google.com/privacy',
				'purpose'   => 'Embedded map display.',
				'retention' => 'Up to 6 months',
			),
			'google-recaptcha' => array(
				'name'      => 'Google reCAPTCHA',
				'vendor'    => 'Google LLC',
				'category'  => 'functional',
				'patterns'  => array( 'www.google.com/recaptcha', 'www.gstatic.com/recaptcha' ),
				'cookies'   => array( '_GRECAPTCHA' ),
				'privacy'   => 'https://policies.google.com/privacy',
				'purpose'   => 'Spam and bot protection on forms.',
				'retention' => 'Up to 6 months',
			),

			// ---------------------------------------------------------------
			// CHAT / FUNCTIONAL
			// ---------------------------------------------------------------
			'intercom' => array(
				'name'      => 'Intercom',
				'vendor'    => 'Intercom, Inc.',
				'category'  => 'functional',
				'patterns'  => array( 'widget.intercom.io', 'js.intercomcdn.com' ),
				'cookies'   => array( 'intercom-*' ),
				'privacy'   => 'https://www.intercom.com/legal/privacy',
				'purpose'   => 'Customer messaging and live chat.',
				'retention' => 'Up to 9 months',
			),
			'tawkto' => array(
				'name'      => 'Tawk.to',
				'vendor'    => 'Tawk.to',
				'category'  => 'functional',
				'patterns'  => array( 'embed.tawk.to' ),
				'cookies'   => array( 'TawkConnectionTime', '__tawkuuid', 'ss' ),
				'privacy'   => 'https://www.tawk.to/privacy-policy/',
				'purpose'   => 'Live chat widget.',
				'retention' => 'Up to 1 year',
			),
			'drift' => array(
				'name'      => 'Drift',
				'vendor'    => 'Drift.com, Inc.',
				'category'  => 'functional',
				'patterns'  => array( 'js.driftt.com', 'widget.drift.com' ),
				'cookies'   => array( 'drift_*' ),
				'privacy'   => 'https://www.drift.com/privacy-policy/',
				'purpose'   => 'Live chat and conversational marketing.',
				'retention' => 'Up to 1 year',
			),
		);
	}

	/**
	 * Detect which known services are referenced in an arbitrary block of HTML.
	 *
	 * @param string $html  Page HTML.
	 * @return array        Array of service keys detected.
	 */
	public static function detect_in_html( $html ) {
		$found = array();
		foreach ( self::catalog() as $key => $svc ) {
			foreach ( $svc['patterns'] as $pattern ) {
				if ( false !== stripos( $html, $pattern ) ) {
					$found[] = $key;
					break;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Return the category of a given service key.
	 */
	public static function category_of( $key ) {
		$cat = self::catalog();
		return isset( $cat[ $key ] ) ? $cat[ $key ]['category'] : 'marketing';
	}

	/**
	 * Get all blocking patterns for a list of categories. Used by the blocker.
	 *
	 * @param array $categories  Category slugs to block.
	 * @return array             Flat array of URL substrings.
	 */
	public static function patterns_for_categories( $categories ) {
		$patterns = array();
		foreach ( self::catalog() as $key => $svc ) {
			if ( in_array( $svc['category'], $categories, true ) ) {
				foreach ( $svc['patterns'] as $p ) {
					$patterns[] = $p;
				}
			}
		}
		return array_values( array_unique( $patterns ) );
	}
}
