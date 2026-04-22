<?php
/**
 * Scanner.
 *
 * Periodically fetches a sample of pages on the site and records which
 * services are present. Results feed the auto-generated cookie policy
 * and the admin dashboard's "detected services" panel.
 *
 * Note: the fetch is done with blocker output-filtering disabled so we
 * see the raw marketing scripts, not their blocked placeholders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFW_Consent_Scanner {

	public function __construct() {
		add_action( 'gfw_consent_daily_scan', array( $this, 'run_scan' ) );
		add_action( 'wp_ajax_gfw_consent_scan_now', array( $this, 'ajax_scan_now' ) );
	}

	public function ajax_scan_now() {
		check_ajax_referer( 'gfw_consent_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		$found = $this->run_scan();
		wp_send_json_success( array( 'services' => $found ) );
	}

	/**
	 * Run a scan. Returns the list of detected service keys.
	 */
	public function run_scan() {
		$urls = $this->sample_urls();
		$all_found = array();

		foreach ( $urls as $url ) {
			$response = wp_remote_get(
				add_query_arg( 'gfw_consent_scan', '1', $url ),
				array(
					'timeout'     => 20,
					'redirection' => 3,
					'sslverify'   => false,
					'user-agent'  => 'GFWConsentScanner/1.0 (+' . home_url() . ')',
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			$body  = wp_remote_retrieve_body( $response );
			$found = GFW_Consent_Services::detect_in_html( $body );
			$all_found = array_merge( $all_found, $found );
		}

		$all_found = array_values( array_unique( $all_found ) );

		update_option( GFW_CONSENT_SERVICES_KEY, $all_found );
		update_option( 'gfw_consent_last_scan', current_time( 'mysql' ) );

		return $all_found;
	}

	/**
	 * A representative sample of the site: home, a recent post, a page,
	 * WooCommerce shop/cart if present, contact page heuristic.
	 */
	private function sample_urls() {
		$urls = array( home_url( '/' ) );

		$latest_post = get_posts( array(
			'numberposts' => 1,
			'post_status' => 'publish',
			'post_type'   => 'post',
		) );
		if ( ! empty( $latest_post ) ) {
			$urls[] = get_permalink( $latest_post[0] );
		}

		$sample_page = get_posts( array(
			'numberposts' => 1,
			'post_status' => 'publish',
			'post_type'   => 'page',
		) );
		if ( ! empty( $sample_page ) ) {
			$urls[] = get_permalink( $sample_page[0] );
		}

		// WooCommerce.
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );
			$cart = wc_get_page_permalink( 'cart' );
			if ( $shop ) { $urls[] = $shop; }
			if ( $cart ) { $urls[] = $cart; }
		}

		// Contact heuristic.
		$contact = get_page_by_path( 'contact' );
		if ( $contact ) {
			$urls[] = get_permalink( $contact );
		}

		/**
		 * Filter scan target URLs. Sites with unusual page structures
		 * can hook this to add their key marketing landing pages.
		 */
		$urls = apply_filters( 'gfw_consent_scan_urls', array_values( array_unique( $urls ) ) );

		return $urls;
	}
}
