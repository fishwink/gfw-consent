<?php
/**
 * Admin UI.
 *
 * Single top-level menu "Consent" with tabs: Settings, Branding, Services, Logs, Policy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFW_Consent_Admin {

	private $slug = 'gfw-consent';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		// 1.0.7: admin-post handlers for Apply Recommended + Import/Export.
		add_action( 'admin_post_gfw_apply_recommended', array( $this, 'handle_apply_recommended' ) );
		add_action( 'admin_post_gfw_export_settings',  array( $this, 'handle_export_settings' ) );
		add_action( 'admin_post_gfw_import_settings',  array( $this, 'handle_import_settings' ) );
	}

	public function menu() {
		add_menu_page(
			__( 'Consent', 'gfw-consent' ),
			__( 'Consent', 'gfw-consent' ),
			'manage_options',
			$this->slug,
			array( $this, 'render_page' ),
			'dashicons-privacy',
			80
		);
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, $this->slug ) ) {
			return;
		}
		wp_enqueue_style(
			'gfw-consent-admin',
			GFW_CONSENT_URL . 'assets/css/admin.css',
			array(),
			GFW_CONSENT_VERSION
		);
		wp_enqueue_script(
			'gfw-consent-admin',
			GFW_CONSENT_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			GFW_CONSENT_VERSION,
			true
		);
		wp_enqueue_style( 'wp-color-picker' );
		wp_localize_script( 'gfw-consent-admin', 'GFWConsentAdmin', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'gfw_consent_admin' ),
		) );
	}

	public function register_settings() {
		register_setting(
			'gfw_consent_group',
			GFW_CONSENT_OPT_KEY,
			array( $this, 'sanitize_settings' )
		);
	}

	public function sanitize_settings( $input ) {
		// CRITICAL: start from the currently-saved option so fields belonging
		// to OTHER tabs are preserved when one tab is submitted. Previously
		// this was $clean = $defaults which wiped unrelated tabs on every save.
		$current  = get_option( GFW_CONSENT_OPT_KEY, array() );
		$defaults = GFW_Consent_Core::default_settings();
		$clean    = wp_parse_args( $current, $defaults );

		// Identify which tab submitted this form via hidden field.
		$tab = '';
		if ( isset( $input['_gfw_tab'] ) ) {
			$tab = sanitize_key( $input['_gfw_tab'] );
			unset( $input['_gfw_tab'] ); // never persist
		}

		// Declare which keys belong to which tab. Checkboxes are absent from
		// $_POST when unchecked, so bool handling is only safe for keys owned
		// by the submitted tab — otherwise we'd clear unrelated checkboxes.
		$tab_fields = array(
			'settings' => array(
				'text'  => array( 'banner_title', 'banner_body', 'btn_accept', 'btn_reject', 'btn_preferences', 'btn_save', 'company_name', 'company_contact', 'policy_version' ),
				'int'   => array( 'log_retention_days', 'policy_page_id' ),
				'bool'  => array( 'enabled', 'honor_gpc', 'consent_mode_v2', 'reject_equals_accept', 'cat_preferences', 'cat_analytics', 'cat_marketing' ),
				'enum'  => array(
					'jurisdiction_mode' => array( 'auto', 'eu', 'us' ),
					'privacy_level'     => array( 'minimal', 'standard', 'enhanced' ),
				),
			),
			'branding' => array(
				'text'  => array( 'brand_font' ),
				'color' => array( 'brand_primary', 'brand_primary_text', 'brand_bg', 'brand_text', 'brand_border' ),
				'int'   => array( 'brand_radius' ),
				'enum'  => array(
					'brand_layout'   => array( 'bar', 'box' ),
					'brand_position' => array( 'bottom', 'bottom-left', 'bottom-right', 'center' ),
					'brand_theme'    => array( 'light', 'dark' ),
				),
			),
		);

		// Custom services tab writes to its own dedicated option; main option
		// is returned unchanged (WP still saves it, but as a no-op).
		if ( 'custom-services' === $tab ) {
			$this->save_custom_services( isset( $input['custom_services'] ) ? $input['custom_services'] : array() );
			return $clean;
		}

		if ( ! isset( $tab_fields[ $tab ] ) ) {
			// Unknown / missing tab marker — return state unchanged
			return $clean;
		}

		$fields = $tab_fields[ $tab ];

		if ( ! empty( $fields['text'] ) ) {
			foreach ( $fields['text'] as $k ) {
				if ( isset( $input[ $k ] ) ) {
					$clean[ $k ] = sanitize_text_field( $input[ $k ] );
				}
			}
		}

		if ( ! empty( $fields['color'] ) ) {
			foreach ( $fields['color'] as $k ) {
				if ( isset( $input[ $k ] ) ) {
					$v = sanitize_hex_color( $input[ $k ] );
					if ( $v ) {
						$clean[ $k ] = $v;
					}
				}
			}
		}

		if ( ! empty( $fields['int'] ) ) {
			foreach ( $fields['int'] as $k ) {
				if ( isset( $input[ $k ] ) ) {
					$clean[ $k ] = absint( $input[ $k ] );
				}
			}
		}

		if ( ! empty( $fields['bool'] ) ) {
			foreach ( $fields['bool'] as $k ) {
				$clean[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
			}
		}

		if ( ! empty( $fields['enum'] ) ) {
			foreach ( $fields['enum'] as $k => $allowed ) {
				if ( isset( $input[ $k ] ) && in_array( $input[ $k ], $allowed, true ) ) {
					$clean[ $k ] = $input[ $k ];
				}
			}
		}

		// 1.0.7: on branding save, auto-derive bg/text/border from theme,
		// and primary_text from WCAG luminance of the primary color.
		if ( 'branding' === $tab ) {
			$theme = isset( $clean['brand_theme'] ) ? $clean['brand_theme'] : 'light';
			if ( 'dark' === $theme ) {
				$clean['brand_bg']     = '#0f172a';
				$clean['brand_text']   = '#f1f5f9';
				$clean['brand_border'] = '#1e293b';
			} else {
				$clean['brand_bg']     = '#ffffff';
				$clean['brand_text']   = '#1a1a1a';
				$clean['brand_border'] = '#e5e5e5';
			}
			$clean['brand_primary_text'] = self::luminance_fg( $clean['brand_primary'] );
		}

		return $clean;
	}

	public function render_page() {
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$s       = wp_parse_args( get_option( GFW_CONSENT_OPT_KEY, array() ), GFW_Consent_Core::default_settings() );
		$enabled = ! empty( $s['enabled'] );
		$level   = isset( $s['privacy_level'] ) ? $s['privacy_level'] : 'minimal';

		$cat_count = 0;
		foreach ( array( 'cat_preferences', 'cat_analytics', 'cat_marketing' ) as $k ) {
			if ( ! empty( $s[ $k ] ) ) {
				$cat_count++;
			}
		}
		?>
		<div class="wrap gfw-consent-wrap">

			<header class="gfw-header">
				<div class="gfw-header-brand">
					<div class="gfw-header-logo" aria-hidden="true">
						<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
							<path d="M12 2a10 10 0 1 0 10 10 4 4 0 0 1-5-5 4 4 0 0 1-5-5"/>
							<path d="M8.5 8.5v.01"/><path d="M16 15.5v.01"/><path d="M12 12v.01"/>
							<path d="M11 17v.01"/><path d="M7 14v.01"/>
						</svg>
					</div>
					<div>
						<h1 class="gfw-header-title"><?php esc_html_e( 'FISHWINK Consent', 'gfw-consent' ); ?></h1>
						<p class="gfw-header-sub"><?php printf( esc_html__( 'Cookie consent manager · v%s · FISHWINK', 'gfw-consent' ), esc_html( GFW_CONSENT_VERSION ) ); ?></p>
					</div>
				</div>
				<div class="gfw-header-status">
					<span class="gfw-pill <?php echo $enabled ? 'gfw-pill--active' : 'gfw-pill--off'; ?>">
						<span class="gfw-pill-dot"></span>
						<?php echo $enabled ? esc_html__( 'Active', 'gfw-consent' ) : esc_html__( 'Disabled', 'gfw-consent' ); ?>
					</span>
					<span class="gfw-pill gfw-pill--meta"><?php printf( esc_html( _n( '%d category', '%d categories', $cat_count, 'gfw-consent' ) ), $cat_count ); ?></span>
					<span class="gfw-pill gfw-pill--meta"><?php
						$llabel = array( 'minimal' => __( 'Minimal logging', 'gfw-consent' ), 'standard' => __( 'Standard logging', 'gfw-consent' ), 'enhanced' => __( 'Enhanced logging', 'gfw-consent' ) );
						echo esc_html( isset( $llabel[ $level ] ) ? $llabel[ $level ] : $level );
					?></span>
				</div>
			</header>

			<nav class="gfw-tabs">
				<?php
				$tabs = array(
					'settings'        => array( __( 'Settings', 'gfw-consent' ), 'sliders' ),
					'branding'        => array( __( 'Branding', 'gfw-consent' ), 'droplet' ),
					'services'        => array( __( 'Services', 'gfw-consent' ), 'radar' ),
					'custom-services' => array( __( 'Custom services', 'gfw-consent' ), 'plus' ),
					'logs'            => array( __( 'Consent Log', 'gfw-consent' ), 'list' ),
					'policy'          => array( __( 'Policy', 'gfw-consent' ), 'shield' ),
				);
				foreach ( $tabs as $k => $meta ) {
					$active = $tab === $k ? ' is-active' : '';
					printf(
						'<a href="?page=%s&tab=%s" class="gfw-tab%s">%s</a>',
						esc_attr( $this->slug ),
						esc_attr( $k ),
						esc_attr( $active ),
						esc_html( $meta[0] )
					);
				}
				?>
			</nav>

			<div class="gfw-content">
			<?php
			switch ( $tab ) {
				case 'branding':        $this->tab_branding();        break;
				case 'services':        $this->tab_services();        break;
				case 'custom-services': $this->tab_custom_services(); break;
				case 'logs':            $this->tab_logs();            break;
				case 'policy':          $this->tab_policy();          break;
				default:                $this->tab_settings();
			}
			?>
			</div>
		</div>
		<?php
	}

	private function tab_settings() {
		$s = wp_parse_args( get_option( GFW_CONSENT_OPT_KEY, array() ), GFW_Consent_Core::default_settings() );
		?>
		<form method="post" action="options.php" class="gfw-admin-form">
			<?php settings_fields( 'gfw_consent_group' ); ?>
			<input type="hidden" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[_gfw_tab]" value="settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable consent banner', 'gfw-consent' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?>> <?php esc_html_e( 'Block scripts and show banner', 'gfw-consent' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Jurisdiction mode', 'gfw-consent' ); ?></th>
					<td>
						<select name="<?php echo GFW_CONSENT_OPT_KEY; ?>[jurisdiction_mode]">
							<option value="auto" <?php selected( $s['jurisdiction_mode'], 'auto' ); ?>><?php esc_html_e( 'Auto-detect (Cloudflare country header)', 'gfw-consent' ); ?></option>
							<option value="eu" <?php selected( $s['jurisdiction_mode'], 'eu' ); ?>><?php esc_html_e( 'Always EU/UK (prior consent)', 'gfw-consent' ); ?></option>
							<option value="us" <?php selected( $s['jurisdiction_mode'], 'us' ); ?>><?php esc_html_e( 'Always US (opt-out notice)', 'gfw-consent' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'EU mode requires consent before loading any non-essential scripts. US mode uses an opt-out notice style banner.', 'gfw-consent' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Honor Global Privacy Control', 'gfw-consent' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[honor_gpc]" value="1" <?php checked( $s['honor_gpc'], 1 ); ?>> <?php esc_html_e( 'Treat GPC browser signal as a reject', 'gfw-consent' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Google Consent Mode v2', 'gfw-consent' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[consent_mode_v2]" value="1" <?php checked( $s['consent_mode_v2'], 1 ); ?>> <?php esc_html_e( 'Inject default=denied and update on consent', 'gfw-consent' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( '"Reject" as prominent as "Accept"', 'gfw-consent' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[reject_equals_accept]" value="1" <?php checked( $s['reject_equals_accept'], 1 ); ?>> <?php esc_html_e( 'Required for GDPR compliance', 'gfw-consent' ); ?></label></td>
				</tr>

				<tr><th colspan="2"><h2><?php esc_html_e( 'Privacy & logging', 'gfw-consent' ); ?></h2></th></tr>
				<tr>
					<td colspan="2">
						<div style="background: #f0f6fc; border-left: 4px solid #2271b1; padding: 12px 16px; margin-bottom: 12px;">
							<strong><?php esc_html_e( 'Consent logs exist to prove consent was given — not to identify users.', 'gfw-consent' ); ?></strong><br>
							<span style="color: #555;"><?php esc_html_e( 'The "Minimal" level records only the random consent ID, choices made, policy version, and timestamp. That is legally sufficient under GDPR and CCPA. Only increase the level if you have a specific audit need.', 'gfw-consent' ); ?></span>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Privacy level', 'gfw-consent' ); ?></th>
					<td>
						<?php $lvl = isset( $s['privacy_level'] ) ? $s['privacy_level'] : 'minimal'; ?>
						<label style="display:block; margin-bottom:6px;">
							<input type="radio" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[privacy_level]" value="minimal" <?php checked( $lvl, 'minimal' ); ?>>
							<strong><?php esc_html_e( 'Minimal', 'gfw-consent' ); ?></strong>
							<span style="color:#666;"> — <?php esc_html_e( 'consent ID + choices + policy version + timestamp. No IP, no user agent, no URL. (Recommended default.)', 'gfw-consent' ); ?></span>
						</label>
						<label style="display:block; margin-bottom:6px;">
							<input type="radio" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[privacy_level]" value="standard" <?php checked( $lvl, 'standard' ); ?>>
							<strong><?php esc_html_e( 'Standard', 'gfw-consent' ); ?></strong>
							<span style="color:#666;"> — <?php esc_html_e( 'adds truncated hashed IP (last octet zeroed, then salted SHA-256) and user-agent family only (e.g. "Chrome/macOS"). GDPR-compliant.', 'gfw-consent' ); ?></span>
						</label>
						<label style="display:block;">
							<input type="radio" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[privacy_level]" value="enhanced" <?php checked( $lvl, 'enhanced' ); ?>>
							<strong><?php esc_html_e( 'Enhanced', 'gfw-consent' ); ?></strong>
							<span style="color:#666;"> — <?php esc_html_e( 'adds the consent page path (no query string, no fragment). Use only for sites with specific audit requirements.', 'gfw-consent' ); ?></span>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Log retention (days)', 'gfw-consent' ); ?></th>
					<td>
						<input type="number" min="30" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[log_retention_days]" value="<?php echo esc_attr( $s['log_retention_days'] ); ?>">
						<p class="description"><?php esc_html_e( 'Entries older than this are automatically deleted. Minimum 30 days. 365 days is typical for consent audit purposes.', 'gfw-consent' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Policy version', 'gfw-consent' ); ?></th>
					<td>
						<input type="text" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[policy_version]" value="<?php echo esc_attr( $s['policy_version'] ); ?>">
						<p class="description"><?php esc_html_e( 'Bump this when you materially change your cookie policy to force re-consent.', 'gfw-consent' ); ?></p>
					</td>
				</tr>
				<tr><th colspan="2"><h2><?php esc_html_e( 'Categories offered to users', 'gfw-consent' ); ?></h2></th></tr>
				<tr>
					<td colspan="2">
						<div style="background: #fffbea; border-left: 4px solid #f0b429; padding: 12px 16px; margin-bottom: 12px;">
							<strong><?php esc_html_e( 'Only enable categories that this specific site actually uses.', 'gfw-consent' ); ?></strong><br>
							<span style="color: #555;"><?php esc_html_e( 'Showing a "Marketing" toggle on a site with no ad pixels is misleading and a compliance red flag. Check the Services tab to see what the scanner has detected, then enable matching categories only. If none are enabled, the banner will not display (essential cookies do not require consent).', 'gfw-consent' ); ?></span>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Functional', 'gfw-consent' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[cat_preferences]" value="1" <?php checked( $s['cat_preferences'], 1 ); ?>> <?php esc_html_e( 'Maps, chat, embedded content (YouTube, Vimeo, Intercom, etc.)', 'gfw-consent' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Analytics', 'gfw-consent' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[cat_analytics]" value="1" <?php checked( $s['cat_analytics'], 1 ); ?>> <?php esc_html_e( 'GA4, Clarity, Hotjar, etc.', 'gfw-consent' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Marketing', 'gfw-consent' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[cat_marketing]" value="1" <?php checked( $s['cat_marketing'], 1 ); ?>> <?php esc_html_e( 'Meta Pixel, Google Ads, TikTok, etc.', 'gfw-consent' ); ?></label></td>
				</tr>

				<tr><th colspan="2"><h2><?php esc_html_e( 'Company info (for policy)', 'gfw-consent' ); ?></h2></th></tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Company name', 'gfw-consent' ); ?></th>
					<td><input type="text" class="regular-text" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[company_name]" value="<?php echo esc_attr( $s['company_name'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Privacy contact email', 'gfw-consent' ); ?></th>
					<td><input type="email" class="regular-text" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[company_contact]" value="<?php echo esc_attr( $s['company_contact'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cookie policy page', 'gfw-consent' ); ?></th>
					<td>
						<?php
						wp_dropdown_pages( array(
							'name'              => GFW_CONSENT_OPT_KEY . '[policy_page_id]',
							'selected'          => $s['policy_page_id'],
							'show_option_none'  => __( '— Select —', 'gfw-consent' ),
							'option_none_value' => 0,
						) );
						?>
						<p class="description"><?php esc_html_e( 'Place the shortcode [gfw_consent_policy] on this page.', 'gfw-consent' ); ?></p>
					</td>
				</tr>

				<tr><th colspan="2"><h2><?php esc_html_e( 'Banner text', 'gfw-consent' ); ?></h2></th></tr>
				<tr><th><?php esc_html_e( 'Title', 'gfw-consent' ); ?></th><td><input type="text" class="regular-text" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[banner_title]" value="<?php echo esc_attr( $s['banner_title'] ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Body', 'gfw-consent' ); ?></th><td><textarea class="large-text" rows="3" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[banner_body]"><?php echo esc_textarea( $s['banner_body'] ); ?></textarea></td></tr>
				<tr><th><?php esc_html_e( 'Accept button', 'gfw-consent' ); ?></th><td><input type="text" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[btn_accept]" value="<?php echo esc_attr( $s['btn_accept'] ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Reject button', 'gfw-consent' ); ?></th><td><input type="text" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[btn_reject]" value="<?php echo esc_attr( $s['btn_reject'] ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Preferences button', 'gfw-consent' ); ?></th><td><input type="text" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[btn_preferences]" value="<?php echo esc_attr( $s['btn_preferences'] ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Save preferences button', 'gfw-consent' ); ?></th><td><input type="text" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[btn_save]" value="<?php echo esc_attr( $s['btn_save'] ); ?>"></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function tab_branding() {
		$s     = wp_parse_args( get_option( GFW_CONSENT_OPT_KEY, array() ), GFW_Consent_Core::default_settings() );
		$theme = isset( $s['brand_theme'] ) ? $s['brand_theme'] : 'light';
		?>
		<form method="post" action="options.php" class="gfw-admin-form">
			<?php settings_fields( 'gfw_consent_group' ); ?>
			<input type="hidden" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[_gfw_tab]" value="branding">

			<p style="margin:0 0 20px; color:var(--gfw-admin-text-soft);">
				<?php esc_html_e( 'Pick a theme and a primary color — background, text, and border colors are derived automatically for best contrast.', 'gfw-consent' ); ?>
			</p>

			<div class="gfw-branding-grid">

				<div class="gfw-branding-controls">

					<div class="gfw-field">
						<label class="gfw-field-label"><?php esc_html_e( 'Theme', 'gfw-consent' ); ?></label>
						<div class="gfw-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Theme', 'gfw-consent' ); ?>">
							<label class="gfw-seg <?php echo 'light' === $theme ? 'is-active' : ''; ?>">
								<input type="radio" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[brand_theme]" value="light" <?php checked( $theme, 'light' ); ?>>
								<span><?php esc_html_e( 'Light', 'gfw-consent' ); ?></span>
							</label>
							<label class="gfw-seg <?php echo 'dark' === $theme ? 'is-active' : ''; ?>">
								<input type="radio" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[brand_theme]" value="dark" <?php checked( $theme, 'dark' ); ?>>
								<span><?php esc_html_e( 'Dark', 'gfw-consent' ); ?></span>
							</label>
						</div>
					</div>

					<div class="gfw-field">
						<label class="gfw-field-label" for="gfw_brand_primary"><?php esc_html_e( 'Primary color', 'gfw-consent' ); ?></label>
						<input type="text" id="gfw_brand_primary" class="gfw-color" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[brand_primary]" value="<?php echo esc_attr( $s['brand_primary'] ); ?>">
						<p class="description"><?php esc_html_e( 'Used for Accept button and focus rings. Button text color is chosen automatically for WCAG contrast.', 'gfw-consent' ); ?></p>
					</div>

					<div class="gfw-field">
						<label class="gfw-field-label"><?php esc_html_e( 'Layout', 'gfw-consent' ); ?></label>
						<div class="gfw-segmented" role="radiogroup" aria-label="<?php esc_attr_e( 'Layout', 'gfw-consent' ); ?>">
							<label class="gfw-seg <?php echo 'bar' === $s['brand_layout'] ? 'is-active' : ''; ?>">
								<input type="radio" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[brand_layout]" value="bar" <?php checked( $s['brand_layout'], 'bar' ); ?>>
								<span><?php esc_html_e( 'Bar', 'gfw-consent' ); ?></span>
							</label>
							<label class="gfw-seg <?php echo 'box' === $s['brand_layout'] ? 'is-active' : ''; ?>">
								<input type="radio" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[brand_layout]" value="box" <?php checked( $s['brand_layout'], 'box' ); ?>>
								<span><?php esc_html_e( 'Box', 'gfw-consent' ); ?></span>
							</label>
						</div>
					</div>

					<div class="gfw-field">
						<label class="gfw-field-label"><?php esc_html_e( 'Position', 'gfw-consent' ); ?></label>
						<div class="gfw-segmented gfw-segmented--4" role="radiogroup" aria-label="<?php esc_attr_e( 'Position', 'gfw-consent' ); ?>">
							<?php
							$positions = array(
								'bottom'       => __( 'Bottom', 'gfw-consent' ),
								'bottom-left'  => __( 'BL', 'gfw-consent' ),
								'bottom-right' => __( 'BR', 'gfw-consent' ),
								'center'       => __( 'Center', 'gfw-consent' ),
							);
							foreach ( $positions as $val => $label ) : ?>
								<label class="gfw-seg <?php echo $val === $s['brand_position'] ? 'is-active' : ''; ?>">
									<input type="radio" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[brand_position]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $s['brand_position'], $val ); ?>>
									<span><?php echo esc_html( $label ); ?></span>
								</label>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="gfw-field">
						<label class="gfw-field-label" for="gfw_brand_radius">
							<?php esc_html_e( 'Border radius', 'gfw-consent' ); ?>
							<span class="gfw-range-val" id="gfw_brand_radius_val"><?php echo esc_html( $s['brand_radius'] ); ?>px</span>
						</label>
						<div class="gfw-range-wrap">
							<input type="range" id="gfw_brand_radius" min="0" max="32" step="1" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[brand_radius]" value="<?php echo esc_attr( $s['brand_radius'] ); ?>">
						</div>
					</div>

					<div class="gfw-field">
						<label class="gfw-field-label" for="gfw_brand_font"><?php esc_html_e( 'Font family (CSS)', 'gfw-consent' ); ?></label>
						<input type="text" id="gfw_brand_font" class="regular-text" name="<?php echo GFW_CONSENT_OPT_KEY; ?>[brand_font]" value="<?php echo esc_attr( $s['brand_font'] ); ?>" placeholder="inherit">
						<p class="description"><?php esc_html_e( 'Leave as "inherit" to match site fonts, or enter a custom stack.', 'gfw-consent' ); ?></p>
					</div>

				</div>

				<div class="gfw-branding-preview">
					<div class="gfw-preview-label"><?php esc_html_e( 'Live preview', 'gfw-consent' ); ?></div>
					<div class="gfw-preview-stage" id="gfw_preview_stage" data-theme="<?php echo esc_attr( $theme ); ?>" data-layout="<?php echo esc_attr( $s['brand_layout'] ); ?>" data-position="<?php echo esc_attr( $s['brand_position'] ); ?>">
						<div class="gfw-preview-page"></div>
						<div class="gfw-preview-banner" style="--gfw-p: <?php echo esc_attr( $s['brand_primary'] ); ?>; --gfw-r: <?php echo esc_attr( $s['brand_radius'] ); ?>px;">
							<div class="gfw-preview-copy">
								<strong class="gfw-preview-title"><?php echo esc_html( $s['banner_title'] ? $s['banner_title'] : __( 'We value your privacy', 'gfw-consent' ) ); ?></strong>
								<span class="gfw-preview-body"><?php esc_html_e( 'We use cookies to improve your experience. Choose which categories to allow.', 'gfw-consent' ); ?></span>
							</div>
							<div class="gfw-preview-actions">
								<button type="button" class="gfw-preview-btn gfw-preview-btn--ghost" disabled><?php echo esc_html( $s['btn_reject'] ); ?></button>
								<button type="button" class="gfw-preview-btn gfw-preview-btn--primary" disabled><?php echo esc_html( $s['btn_accept'] ); ?></button>
							</div>
						</div>
					</div>
					<p class="description" style="margin-top:10px;"><?php esc_html_e( 'Preview is illustrative — actual banner will inherit your site\'s typography.', 'gfw-consent' ); ?></p>
				</div>

			</div>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	private function tab_services() {
		$detected = get_option( GFW_CONSENT_SERVICES_KEY, array() );
		$catalog  = GFW_Consent_Services::catalog();
		$last     = get_option( 'gfw_consent_last_scan', '' );
		$opts     = wp_parse_args( get_option( GFW_CONSENT_OPT_KEY, array() ), GFW_Consent_Core::default_settings() );

		// 1.0.7: compute recommended categories from detected services.
		// catalog category -> settings key.
		$cat_to_setting = array(
			'functional' => 'cat_preferences',
			'analytics'  => 'cat_analytics',
			'marketing'  => 'cat_marketing',
		);
		$recommended    = array();
		$rec_services   = array();
		foreach ( $detected as $key ) {
			if ( ! isset( $catalog[ $key ] ) ) { continue; }
			$cat = $catalog[ $key ]['category'];
			if ( isset( $cat_to_setting[ $cat ] ) ) {
				$recommended[ $cat_to_setting[ $cat ] ] = true;
				$rec_services[ $cat ][] = $catalog[ $key ]['name'];
			}
		}
		$needs_change = false;
		foreach ( $recommended as $key => $_ ) {
			if ( empty( $opts[ $key ] ) ) { $needs_change = true; break; }
		}
		?>
		<?php if ( ! empty( $_GET['gfw_applied'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Recommended categories applied.', 'gfw-consent' ); ?></p></div>
		<?php endif; ?>
		<p>
			<?php if ( $last ) : ?>
				<?php printf( esc_html__( 'Last scan: %s', 'gfw-consent' ), esc_html( $last ) ); ?>
			<?php else : ?>
				<?php esc_html_e( 'No scan yet.', 'gfw-consent' ); ?>
			<?php endif; ?>
			&nbsp;<button type="button" class="button button-primary" id="gfw-consent-scan-now"><?php esc_html_e( 'Scan site now', 'gfw-consent' ); ?></button>
		</p>

		<?php if ( ! empty( $recommended ) ) : ?>
			<div class="gfw-recommendation <?php echo $needs_change ? '' : 'gfw-recommendation--ok'; ?>">
				<div class="gfw-recommendation__body">
					<strong>
						<?php if ( $needs_change ) : ?>
							<?php esc_html_e( 'Recommended categories based on the last scan', 'gfw-consent' ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Your enabled categories match what the scanner detected ✓', 'gfw-consent' ); ?>
						<?php endif; ?>
					</strong>
					<p>
						<?php
						$labels = array(
							'cat_preferences' => __( 'Functional', 'gfw-consent' ),
							'cat_analytics'   => __( 'Analytics', 'gfw-consent' ),
							'cat_marketing'   => __( 'Marketing', 'gfw-consent' ),
						);
						$rec_labels = array();
						foreach ( $recommended as $key => $_ ) {
							$rec_labels[] = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
						}
						printf(
							/* translators: %s = comma-separated category list */
							esc_html__( 'Based on detected services, these categories should be enabled: %s', 'gfw-consent' ),
							'<strong>' . esc_html( implode( ', ', $rec_labels ) ) . '</strong>'
						);
						?>
					</p>
				</div>
				<?php if ( $needs_change ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gfw-recommendation__form">
						<input type="hidden" name="action" value="gfw_apply_recommended">
						<?php wp_nonce_field( 'gfw_apply_recommended', 'gfw_apply_nonce' ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Apply recommended categories', 'gfw-consent' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $detected ) ) : ?>
			<h2><?php esc_html_e( 'Detected services', 'gfw-consent' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'gfw-consent' ); ?></th>
						<th><?php esc_html_e( 'Vendor', 'gfw-consent' ); ?></th>
						<th><?php esc_html_e( 'Category', 'gfw-consent' ); ?></th>
						<th><?php esc_html_e( 'Purpose', 'gfw-consent' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $detected as $key ) :
					if ( ! isset( $catalog[ $key ] ) ) { continue; }
					$s = $catalog[ $key ];
					?>
					<tr>
						<td><strong><?php echo esc_html( $s['name'] ); ?></strong></td>
						<td><?php echo esc_html( $s['vendor'] ); ?></td>
						<td><code><?php echo esc_html( $s['category'] ); ?></code></td>
						<td><?php echo esc_html( $s['purpose'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'No known tracking services detected yet. Run a scan to populate this list.', 'gfw-consent' ); ?></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Full catalog', 'gfw-consent' ); ?></h2>
		<p class="description"><?php esc_html_e( 'All services this plugin knows how to block. Add more by editing includes/class-gfw-consent-services.php.', 'gfw-consent' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Service', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'Category', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'Patterns', 'gfw-consent' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $catalog as $key => $s ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $s['name'] ); ?></strong><br><small><?php echo esc_html( $s['vendor'] ); ?></small></td>
					<td><code><?php echo esc_html( $s['category'] ); ?></code></td>
					<td><code><?php echo esc_html( implode( ', ', $s['patterns'] ) ); ?></code></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function tab_custom_services() {
		$custom = get_option( GFW_CONSENT_CUSTOM_KEY, array() );
		if ( ! is_array( $custom ) ) {
			$custom = array();
		}
		?>
		<form method="post" action="options.php" class="gfw-admin-form">
			<?php settings_fields( 'gfw_consent_group' ); ?>
			<input type="hidden" name="<?php echo esc_attr( GFW_CONSENT_OPT_KEY ); ?>[_gfw_tab]" value="custom-services">

			<div class="gfw-form-intro">
				<h2><?php esc_html_e( 'Custom services', 'gfw-consent' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Add trackers that aren\'t in the built-in catalog. Entries here are blocked until consent and appear in the auto-generated Cookie Policy like any other service. Built-in services always take priority if a slug collides.', 'gfw-consent' ); ?>
				</p>
			</div>

			<div id="gfw-custom-services-list">
				<?php
				foreach ( $custom as $i => $svc ) {
					$this->render_custom_service_row( (string) $i, $svc );
				}
				// Always render one blank row for quick first-time use.
				$this->render_custom_service_row( 'new_' . count( $custom ), array() );
				?>
			</div>

			<p class="gfw-custom-actions">
				<button type="button" class="button" id="gfw-add-custom-service">
					<?php esc_html_e( '+ Add another custom service', 'gfw-consent' ); ?>
				</button>
			</p>

			<?php submit_button(); ?>
		</form>

		<template id="gfw-custom-service-template">
			<?php $this->render_custom_service_row( '__INDEX__', array() ); ?>
		</template>
		<?php
	}

	private function render_custom_service_row( $index, $svc ) {
		$prefix    = esc_attr( GFW_CONSENT_OPT_KEY ) . '[custom_services][' . esc_attr( $index ) . ']';
		$name      = isset( $svc['name'] ) ? $svc['name'] : '';
		$vendor    = isset( $svc['vendor'] ) ? $svc['vendor'] : '';
		$category  = isset( $svc['category'] ) ? $svc['category'] : 'analytics';
		$patterns  = ( isset( $svc['patterns'] ) && is_array( $svc['patterns'] ) ) ? implode( "\n", $svc['patterns'] ) : '';
		$cookies   = ( isset( $svc['cookies'] ) && is_array( $svc['cookies'] ) ) ? implode( ', ', $svc['cookies'] ) : '';
		$privacy   = isset( $svc['privacy'] ) ? $svc['privacy'] : '';
		$purpose   = isset( $svc['purpose'] ) ? $svc['purpose'] : '';
		$retention = isset( $svc['retention'] ) ? $svc['retention'] : '';
		?>
		<fieldset class="gfw-custom-svc" data-index="<?php echo esc_attr( $index ); ?>">
			<div class="gfw-custom-svc-head">
				<strong><?php esc_html_e( 'Custom service', 'gfw-consent' ); ?></strong>
				<button type="button" class="gfw-custom-svc-remove">
					&times; <?php esc_html_e( 'Remove', 'gfw-consent' ); ?>
				</button>
			</div>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Name', 'gfw-consent' ); ?></label></th>
					<td><input type="text" name="<?php echo $prefix; ?>[name]" value="<?php echo esc_attr( $name ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Niche Tracker', 'gfw-consent' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Vendor', 'gfw-consent' ); ?></label></th>
					<td><input type="text" name="<?php echo $prefix; ?>[vendor]" value="<?php echo esc_attr( $vendor ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Niche Inc.', 'gfw-consent' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Category', 'gfw-consent' ); ?></th>
					<td>
						<select name="<?php echo $prefix; ?>[category]">
							<option value="functional" <?php selected( $category, 'functional' ); ?>><?php esc_html_e( 'Functional', 'gfw-consent' ); ?></option>
							<option value="analytics"  <?php selected( $category, 'analytics'  ); ?>><?php esc_html_e( 'Analytics', 'gfw-consent' ); ?></option>
							<option value="marketing"  <?php selected( $category, 'marketing'  ); ?>><?php esc_html_e( 'Marketing', 'gfw-consent' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Patterns', 'gfw-consent' ); ?></label></th>
					<td>
						<textarea name="<?php echo $prefix; ?>[patterns]" rows="3" class="large-text code" placeholder="cdn.niche.com/track.js&#10;niche.com/pixel"><?php echo esc_textarea( $patterns ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One pattern per line. Case-insensitive substring match against script URLs and inline script bodies. Leave blank to drop this entry on save.', 'gfw-consent' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Cookie names', 'gfw-consent' ); ?></label></th>
					<td><input type="text" name="<?php echo $prefix; ?>[cookies]" value="<?php echo esc_attr( $cookies ); ?>" class="regular-text" placeholder="_niche, _niche_sid"></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Privacy URL', 'gfw-consent' ); ?></label></th>
					<td><input type="url" name="<?php echo $prefix; ?>[privacy]" value="<?php echo esc_url( $privacy ); ?>" class="regular-text" placeholder="https://example.com/privacy"></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Purpose', 'gfw-consent' ); ?></label></th>
					<td><textarea name="<?php echo $prefix; ?>[purpose]" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Short plain-English description for the cookie policy.', 'gfw-consent' ); ?>"><?php echo esc_textarea( $purpose ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Retention', 'gfw-consent' ); ?></label></th>
					<td><input type="text" name="<?php echo $prefix; ?>[retention]" value="<?php echo esc_attr( $retention ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g., Up to 1 year', 'gfw-consent' ); ?>"></td>
				</tr>
			</table>
		</fieldset>
		<?php
	}

	/**
	 * Sanitize the posted custom_services payload and write to the dedicated
	 * wp_option. Entries with no patterns are dropped silently. Slug is
	 * auto-generated from the name (or first pattern if name is blank) to
	 * keep URLs / lookups stable and prevent collision with built-ins.
	 */
	private function save_custom_services( $raw ) {
		$allowed_cats = array( 'functional', 'analytics', 'marketing' );
		$clean        = array();

		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$patterns_raw = isset( $entry['patterns'] ) ? (string) $entry['patterns'] : '';
			$patterns     = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $patterns_raw ) ) ) );
			if ( empty( $patterns ) ) {
				continue; // drop empty rows silently
			}

			$name = isset( $entry['name'] ) ? sanitize_text_field( $entry['name'] ) : '';
			if ( '' === $name ) {
				$name = $patterns[0]; // fallback — so the policy page never shows a blank row
			}

			$category = ( isset( $entry['category'] ) && in_array( $entry['category'], $allowed_cats, true ) )
				? $entry['category']
				: 'analytics';

			$cookies_raw = isset( $entry['cookies'] ) ? (string) $entry['cookies'] : '';
			$cookies     = array_values( array_filter( array_map( 'trim', explode( ',', $cookies_raw ) ) ) );

			$slug = 'custom_' . sanitize_title( $name );

			$clean[] = array(
				'slug'      => $slug,
				'name'      => $name,
				'vendor'    => isset( $entry['vendor'] ) ? sanitize_text_field( $entry['vendor'] ) : '',
				'category'  => $category,
				'patterns'  => array_values( array_unique( $patterns ) ),
				'cookies'   => array_values( array_unique( $cookies ) ),
				'privacy'   => isset( $entry['privacy'] ) ? esc_url_raw( $entry['privacy'] ) : '',
				'purpose'   => isset( $entry['purpose'] ) ? sanitize_textarea_field( $entry['purpose'] ) : '',
				'retention' => isset( $entry['retention'] ) ? sanitize_text_field( $entry['retention'] ) : '',
			);
		}

		// De-dupe by slug, last write wins.
		$by_slug = array();
		foreach ( $clean as $c ) {
			$by_slug[ $c['slug'] ] = $c;
		}

		update_option( GFW_CONSENT_CUSTOM_KEY, array_values( $by_slug ) );
	}

	private function tab_logs() {
		// Handle erasure request
		if ( isset( $_POST['gfw_erase_consent_id'] ) && check_admin_referer( 'gfw_erase_consent', 'gfw_erase_nonce' ) ) {
			$cid = sanitize_text_field( wp_unslash( $_POST['gfw_erase_consent_id'] ) );
			$deleted = GFW_Consent_Logger::erase_by_consent_id( $cid );
			echo '<div class="notice notice-success"><p>' . sprintf(
				/* translators: %d = number of log entries removed */
				esc_html( _n( 'Erased %d log entry.', 'Erased %d log entries.', $deleted, 'gfw-consent' ) ),
				(int) $deleted
			) . '</p></div>';
		}

		$stats        = GFW_Consent_Logger::stats();
		$rows         = GFW_Consent_Logger::recent( 200 );
		$current_opts = get_option( GFW_CONSENT_OPT_KEY, array() );
		$level        = isset( $current_opts['privacy_level'] ) ? $current_opts['privacy_level'] : 'minimal';
		$level_label  = array(
			'minimal'  => __( 'Minimal (consent proof only)', 'gfw-consent' ),
			'standard' => __( 'Standard (+ anonymized IP, UA family)', 'gfw-consent' ),
			'enhanced' => __( 'Enhanced (+ page path)', 'gfw-consent' ),
		);
		?>
		<p style="margin: 4px 0 16px; color: #555;">
			<strong><?php esc_html_e( 'Current logging level:', 'gfw-consent' ); ?></strong>
			<?php echo esc_html( isset( $level_label[ $level ] ) ? $level_label[ $level ] : $level ); ?>
			— <a href="?page=gfw-consent&tab=settings"><?php esc_html_e( 'change in Settings', 'gfw-consent' ); ?></a>
		</p>

		<div class="gfw-consent-stats">
			<div class="gfw-consent-stat"><span><?php echo esc_html( $stats['total'] ); ?></span><label><?php esc_html_e( 'Total', 'gfw-consent' ); ?></label></div>
			<div class="gfw-consent-stat"><span><?php echo esc_html( $stats['accepts'] ); ?></span><label><?php esc_html_e( 'Accepts', 'gfw-consent' ); ?></label></div>
			<div class="gfw-consent-stat"><span><?php echo esc_html( $stats['rejects'] ); ?></span><label><?php esc_html_e( 'Rejects', 'gfw-consent' ); ?></label></div>
			<div class="gfw-consent-stat"><span><?php echo esc_html( $stats['custom'] ); ?></span><label><?php esc_html_e( 'Custom', 'gfw-consent' ); ?></label></div>
			<div class="gfw-consent-stat"><span><?php echo esc_html( $stats['last_30'] ); ?></span><label><?php esc_html_e( 'Last 30d', 'gfw-consent' ); ?></label></div>
		</div>

		<h3 style="margin-top: 24px;"><?php esc_html_e( 'Erase by consent ID (GDPR Article 17 / CCPA)', 'gfw-consent' ); ?></h3>
		<p style="color:#555; max-width:720px;"><?php esc_html_e( 'If a user requests deletion of their consent record, paste their consent ID below. The user can find their ID in the gfw_consent cookie on their browser, or you can look it up in the log table below.', 'gfw-consent' ); ?></p>
		<form method="post" style="margin-bottom: 24px;">
			<?php wp_nonce_field( 'gfw_erase_consent', 'gfw_erase_nonce' ); ?>
			<input type="text" name="gfw_erase_consent_id" placeholder="<?php esc_attr_e( 'Paste consent_id here', 'gfw-consent' ); ?>" class="regular-text" required>
			<button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Permanently delete all log entries for this consent ID?', 'gfw-consent' ); ?>');"><?php esc_html_e( 'Erase matching log entries', 'gfw-consent' ); ?></button>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'Consent ID', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'Event', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'Categories', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'Version', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'Juris.', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'GPC', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'IP (hash)', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'UA', 'gfw-consent' ); ?></th>
					<th><?php esc_html_e( 'Path', 'gfw-consent' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $r ) : ?>
				<tr>
					<td><?php echo esc_html( $r->created_at ); ?></td>
					<td><code title="<?php echo esc_attr( $r->consent_id ); ?>"><?php echo esc_html( substr( $r->consent_id, 0, 12 ) ); ?>…</code></td>
					<td><code><?php echo esc_html( $r->event ); ?></code></td>
					<td><?php echo esc_html( $r->categories ); ?></td>
					<td><?php echo esc_html( $r->policy_version ); ?></td>
					<td><?php echo esc_html( $r->jurisdiction ); ?></td>
					<td><?php echo $r->gpc_signal ? '✓' : ''; ?></td>
					<td><?php echo $r->ip_hash ? '<code title="' . esc_attr( $r->ip_hash ) . '">' . esc_html( substr( $r->ip_hash, 0, 8 ) ) . '…</code>' : '<span style="color:#aaa;">—</span>'; ?></td>
					<td><?php echo $r->user_agent ? esc_html( $r->user_agent ) : '<span style="color:#aaa;">—</span>'; ?></td>
					<td><?php echo $r->url ? esc_html( $r->url ) : '<span style="color:#aaa;">—</span>'; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function tab_policy() {
		$import_err = isset( $_GET['gfw_import_error'] ) ? sanitize_key( $_GET['gfw_import_error'] ) : '';
		?>
		<?php if ( ! empty( $_GET['gfw_imported'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings imported successfully.', 'gfw-consent' ); ?></p></div>
		<?php elseif ( 'nofile' === $import_err ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'No file was uploaded.', 'gfw-consent' ); ?></p></div>
		<?php elseif ( 'malformed' === $import_err ) : ?>
			<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The uploaded file is not a valid FISHWINK Consent export.', 'gfw-consent' ); ?></p></div>
		<?php endif; ?>
		<h2><?php esc_html_e( 'Cookie policy', 'gfw-consent' ); ?></h2>
		<p><?php esc_html_e( 'Place this shortcode on a page (e.g. /cookie-policy):', 'gfw-consent' ); ?></p>
		<p><code>[gfw_consent_policy]</code></p>
		<p><?php esc_html_e( 'Place this shortcode in your footer for a "Cookie preferences" link:', 'gfw-consent' ); ?></p>
		<p><code>[gfw_consent_preferences label="Cookie preferences"]</code></p>
		<h3><?php esc_html_e( 'Preview', 'gfw-consent' ); ?></h3>
		<div style="border:1px solid #ccc;padding:20px;background:#fff;max-height:600px;overflow:auto;">
			<?php echo do_shortcode( '[gfw_consent_policy]' ); ?>
		</div>

		<h2 style="margin-top:32px;"><?php esc_html_e( 'Tools', 'gfw-consent' ); ?></h2>
		<div class="gfw-tools-grid">

			<div class="gfw-tool-card">
				<h3><?php esc_html_e( 'Export settings', 'gfw-consent' ); ?></h3>
				<p><?php esc_html_e( 'Download a JSON file containing all plugin settings — useful for cloning consent configuration across client sites.', 'gfw-consent' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="gfw_export_settings">
					<?php wp_nonce_field( 'gfw_export_settings', 'gfw_export_nonce' ); ?>
					<button type="submit" class="button"><?php esc_html_e( 'Download settings.json', 'gfw-consent' ); ?></button>
				</form>
			</div>

			<div class="gfw-tool-card">
				<h3><?php esc_html_e( 'Import settings', 'gfw-consent' ); ?></h3>
				<p><?php esc_html_e( 'Upload a settings JSON file exported from another site. All fields are revalidated through the tab sanitizers — invalid values are discarded.', 'gfw-consent' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="gfw_import_settings">
					<?php wp_nonce_field( 'gfw_import_settings', 'gfw_import_nonce' ); ?>
					<input type="file" name="gfw_settings_file" accept="application/json,.json" required>
					<button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'This will overwrite your current settings. Continue?', 'gfw-consent' ); ?>');"><?php esc_html_e( 'Import', 'gfw-consent' ); ?></button>
				</form>
			</div>

		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * 1.0.7 helpers & admin-post handlers
	 * ------------------------------------------------------------------ */

	/**
	 * WCAG-style luminance check — returns '#000000' for light primaries,
	 * '#ffffff' for dark ones. Used to auto-set primary_text on brand save.
	 */
	public static function luminance_fg( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return '#ffffff';
		}
		$r = hexdec( substr( $hex, 0, 2 ) ) / 255;
		$g = hexdec( substr( $hex, 2, 2 ) ) / 255;
		$b = hexdec( substr( $hex, 4, 2 ) ) / 255;
		// Simple perceptual luminance — good enough for contrast picking.
		$lum = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		return $lum > 0.55 ? '#000000' : '#ffffff';
	}

	/**
	 * Apply scanner-recommended categories to settings.
	 */
	public function handle_apply_recommended() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gfw-consent' ) );
		}
		check_admin_referer( 'gfw_apply_recommended', 'gfw_apply_nonce' );

		$detected = get_option( GFW_CONSENT_SERVICES_KEY, array() );
		$catalog  = GFW_Consent_Services::catalog();
		$opts     = wp_parse_args( get_option( GFW_CONSENT_OPT_KEY, array() ), GFW_Consent_Core::default_settings() );

		$cat_to_setting = array(
			'functional' => 'cat_preferences',
			'analytics'  => 'cat_analytics',
			'marketing'  => 'cat_marketing',
		);
		foreach ( $detected as $key ) {
			if ( ! isset( $catalog[ $key ] ) ) { continue; }
			$cat = $catalog[ $key ]['category'];
			if ( isset( $cat_to_setting[ $cat ] ) ) {
				$opts[ $cat_to_setting[ $cat ] ] = 1;
			}
		}
		update_option( GFW_CONSENT_OPT_KEY, $opts );

		wp_safe_redirect( add_query_arg(
			array( 'page' => $this->slug, 'tab' => 'services', 'gfw_applied' => '1' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Export settings as a downloadable JSON file.
	 */
	public function handle_export_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gfw-consent' ) );
		}
		check_admin_referer( 'gfw_export_settings', 'gfw_export_nonce' );

		$opts    = wp_parse_args( get_option( GFW_CONSENT_OPT_KEY, array() ), GFW_Consent_Core::default_settings() );
		$payload = array(
			'_meta' => array(
				'plugin'        => 'gfw-consent',
				'version'       => GFW_CONSENT_VERSION,
				'exported_at'   => gmdate( 'c' ),
				'source_site'   => home_url(),
			),
			'settings' => $opts,
		);

		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$filename = 'fishwink-consent-' . sanitize_title( $host ) . '-' . gmdate( 'Ymd' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Import settings from an uploaded JSON file. Revalidates through the
	 * tab sanitizer for each tab so invalid values are dropped safely.
	 */
	public function handle_import_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'gfw-consent' ) );
		}
		check_admin_referer( 'gfw_import_settings', 'gfw_import_nonce' );

		$redirect = add_query_arg( array( 'page' => $this->slug, 'tab' => 'policy' ), admin_url( 'admin.php' ) );

		if ( empty( $_FILES['gfw_settings_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['gfw_settings_file']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'gfw_import_error', 'nofile', $redirect ) );
			exit;
		}

		$raw  = file_get_contents( $_FILES['gfw_settings_file']['tmp_name'] );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) || empty( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			wp_safe_redirect( add_query_arg( 'gfw_import_error', 'malformed', $redirect ) );
			exit;
		}

		// Revalidate by running each tab's fields through sanitize_settings().
		// We push all incoming keys through both 'settings' and 'branding' tab
		// sanitizer passes so every whitelisted key gets validated.
		$incoming = $data['settings'];
		$tabs     = array( 'settings', 'branding' );

		foreach ( $tabs as $tab ) {
			$payload            = $incoming;
			$payload['_gfw_tab'] = $tab;
			// sanitize_settings reads from the current option and merges safely.
			$clean = $this->sanitize_settings( $payload );
			update_option( GFW_CONSENT_OPT_KEY, $clean );
		}

		wp_safe_redirect( add_query_arg( 'gfw_imported', '1', $redirect ) );
		exit;
	}
}
