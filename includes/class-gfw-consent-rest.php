<?php
/**
 * REST endpoints.
 *
 * POST /gfw-consent/v1/log
 *   body: { consent_id, event (accept|reject|custom|withdraw), categories[], gpc_signal, jurisdiction }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFW_Consent_REST {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'gfw-consent/v1', '/log', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'log_consent' ),
			'permission_callback' => '__return_true',
			'args' => array(
				'consent_id' => array( 'required' => true, 'type' => 'string' ),
				'event'      => array( 'required' => true, 'type' => 'string' ),
				'categories' => array( 'required' => false, 'type' => 'array' ),
				'gpc_signal' => array( 'required' => false, 'type' => 'boolean' ),
				'jurisdiction' => array( 'required' => false, 'type' => 'string' ),
			),
		) );
	}

	public function log_consent( WP_REST_Request $req ) {
		$valid_events = array( 'accept', 'reject', 'custom', 'withdraw', 'gpc_auto' );
		$event        = $req->get_param( 'event' );
		if ( ! in_array( $event, $valid_events, true ) ) {
			return new WP_Error( 'invalid_event', 'Invalid event', array( 'status' => 400 ) );
		}

		$categories = $req->get_param( 'categories' );
		if ( ! is_array( $categories ) ) {
			$categories = array();
		}

		$inserted = GFW_Consent_Logger::record( array(
			'consent_id'   => $req->get_param( 'consent_id' ),
			'event'        => $event,
			'categories'   => $categories,
			'jurisdiction' => $req->get_param( 'jurisdiction' ),
			'gpc_signal'   => $req->get_param( 'gpc_signal' ) ? 1 : 0,
		) );

		return rest_ensure_response( array( 'ok' => (bool) $inserted ) );
	}
}
