<?php
/**
 * Core plugin orchestrator. Loads modules and handles lifecycle hooks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFW_Consent_Core {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		load_plugin_textdomain( 'gfw-consent', false, dirname( GFW_CONSENT_BASENAME ) . '/languages' );

		// Modules.
		new GFW_Consent_Blocker();
		new GFW_Consent_Frontend();
		new GFW_Consent_REST();
		new GFW_Consent_Admin();
		new GFW_Consent_Scanner();
		new GFW_Consent_Policy();
	}

	/**
	 * Plugin activation: create DB tables, set defaults, schedule cron.
	 */
	public static function activate() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'gfw_consent_log';

		$sql = "CREATE TABLE $table (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			consent_id VARCHAR(64) NOT NULL,
			event VARCHAR(32) NOT NULL,
			categories VARCHAR(255) NOT NULL,
			policy_version VARCHAR(32) NOT NULL,
			ip_hash VARCHAR(64) NOT NULL,
			user_agent VARCHAR(255) NOT NULL,
			url TEXT NOT NULL,
			referer VARCHAR(255) NOT NULL,
			jurisdiction VARCHAR(8) NOT NULL,
			gpc_signal TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY consent_id (consent_id),
			KEY event (event),
			KEY created_at (created_at)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Default settings.
		if ( ! get_option( GFW_CONSENT_OPT_KEY ) ) {
			update_option( GFW_CONSENT_OPT_KEY, self::default_settings() );
		}

		// Initialize detected services array.
		if ( false === get_option( GFW_CONSENT_SERVICES_KEY ) ) {
			update_option( GFW_CONSENT_SERVICES_KEY, array() );
		}

		// Schedule daily scan.
		if ( ! wp_next_scheduled( 'gfw_consent_daily_scan' ) ) {
			wp_schedule_event( time() + 300, 'daily', 'gfw_consent_daily_scan' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'gfw_consent_daily_scan' );
	}

	public static function default_settings() {
		return array(
			// Behavior.
			'enabled'             => 1,
			'jurisdiction_mode'   => 'auto', // auto | eu | us
			'honor_gpc'           => 1,
			'consent_mode_v2'     => 1,
			'log_retention_days'  => 365,
			'privacy_level'       => 'minimal', // minimal | standard | enhanced
			'policy_version'      => '1.0',
			'reject_equals_accept'=> 1,
			// Banner text. Leave body empty so smart copy generates honest text
			// based on which categories are enabled.
			'banner_title'        => __( 'We value your privacy', 'gfw-consent' ),
			'banner_body'         => '',
			'btn_accept'          => __( 'Accept all', 'gfw-consent' ),
			'btn_reject'          => __( 'Reject non-essential', 'gfw-consent' ),
			'btn_preferences'     => __( 'Preferences', 'gfw-consent' ),
			'btn_save'            => __( 'Save preferences', 'gfw-consent' ),
			// Branding.
			'brand_theme'         => 'light', // light | dark (drives bg/text/border auto-derivation in 1.0.7+)
			'brand_primary'       => '#1a1a1a',
			'brand_primary_text'  => '#ffffff',
			'brand_bg'            => '#ffffff',
			'brand_text'          => '#1a1a1a',
			'brand_border'        => '#e5e5e5',
			'brand_radius'        => '8',
			'brand_position'      => 'bottom', // bottom | bottom-left | bottom-right | center
			'brand_layout'        => 'bar',    // bar | box
			'brand_font'          => 'inherit',
			// Categories (functional is always-on).
			'cat_analytics'       => 1,
			'cat_marketing'       => 1,
			'cat_preferences'     => 1,
			// Policy page.
			'policy_page_id'      => 0,
			'company_name'        => '',
			'company_contact'     => '',
		);
	}

	public static function get_setting( $key, $default = '' ) {
		$opts = get_option( GFW_CONSENT_OPT_KEY, array() );
		return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
	}

	/**
	 * True when the current request is a page-builder / customizer / preview
	 * context, or the current user has content-editing capability.
	 *
	 * Used by the blocker (skip output-buffer rewrite) and the frontend
	 * (skip banner render) so builders like Bricks, Elementor, Beaver, etc.
	 * aren't disrupted by consent UI or script mangling while editing.
	 */
	public static function is_editor_context() {
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return true;
		}
		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return true;
		}
		if ( isset( $_GET['preview'] ) && 'true' === $_GET['preview'] ) {
			return true;
		}
		// Known builder activation flags (defensive — builders normally require
		// edit_posts, but some expose token-gated preview modes to logged-out reviewers).
		$builder_flags = array( 'bricks', 'elementor-preview', 'fl_builder', 'ct_builder', 'et_fb', 'brizy-edit', 'brizy-edit-iframe', 'tve' );
		foreach ( $builder_flags as $flag ) {
			if ( isset( $_GET[ $flag ] ) ) {
				return true;
			}
		}
		return false;
	}
}
