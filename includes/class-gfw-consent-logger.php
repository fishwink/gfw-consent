<?php
/**
 * Consent Logger.
 *
 * Privacy-first design. Default mode logs only the minimum needed to prove
 * consent: a random consent_id, the categories chosen, policy version,
 * timestamp, and coarse jurisdiction. No IP, no UA, no URL.
 *
 * Admin can opt in to additional fields via the "Privacy level" setting:
 *
 *   minimal (default)
 *     consent_id, event, categories, policy_version, jurisdiction, gpc_signal
 *
 *   standard
 *     + truncated+hashed IP (last octet zeroed for IPv4, /64 prefix for IPv6,
 *       THEN SHA-256 with wp_salt — not reversible to an individual)
 *     + UA family only (e.g. "Chrome/macOS" — not the full fingerprintable string)
 *
 *   enhanced
 *     + page path only (no query string, no fragment)
 *
 * Article 17 (right to erasure) is supported via erase_by_consent_id().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFW_Consent_Logger {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gfw_consent_log';
	}

	public static function record( $args ) {
		global $wpdb;

		$level = GFW_Consent_Core::get_setting( 'privacy_level', 'minimal' );
		if ( ! in_array( $level, array( 'minimal', 'standard', 'enhanced' ), true ) ) {
			$level = 'minimal';
		}

		$defaults = array(
			'consent_id'     => '',
			'event'          => 'accept',
			'categories'     => array(),
			'policy_version' => GFW_Consent_Core::get_setting( 'policy_version', '1.0' ),
			'jurisdiction'   => 'us',
			'gpc_signal'     => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		// Always logged — compliance minimum
		$row = array(
			'consent_id'     => substr( sanitize_text_field( $args['consent_id'] ), 0, 64 ),
			'event'          => substr( sanitize_key( $args['event'] ), 0, 32 ),
			'categories'     => substr( implode( ',', array_map( 'sanitize_key', (array) $args['categories'] ) ), 0, 255 ),
			'policy_version' => substr( sanitize_text_field( $args['policy_version'] ), 0, 32 ),
			'ip_hash'        => '',
			'user_agent'     => '',
			'url'            => '',
			'referer'        => '',
			'jurisdiction'   => substr( sanitize_key( $args['jurisdiction'] ), 0, 8 ),
			'gpc_signal'     => $args['gpc_signal'] ? 1 : 0,
			'created_at'     => current_time( 'mysql' ),
		);

		// Standard + Enhanced: anonymized IP + UA family
		if ( 'standard' === $level || 'enhanced' === $level ) {
			$ip_raw       = self::client_ip();
			$ip_truncated = self::anonymize_ip( $ip_raw );
			$row['ip_hash'] = $ip_truncated ? hash( 'sha256', $ip_truncated . wp_salt( 'auth' ) ) : '';

			$ua_raw = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';
			$row['user_agent'] = substr( self::simplify_ua( $ua_raw ), 0, 64 );
		}

		// Enhanced: page path only
		if ( 'enhanced' === $level ) {
			$ref = isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '';
			$row['url'] = substr( self::simplify_url( $ref ), 0, 255 );
		}

		$inserted = $wpdb->insert(
			self::table(),
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);

		self::prune();
		return $inserted;
	}

	/**
	 * Delete all log entries matching a consent_id. For Article 17 /
	 * "Do Not Sell" / CCPA erasure requests.
	 */
	public static function erase_by_consent_id( $consent_id ) {
		global $wpdb;
		$consent_id = sanitize_text_field( $consent_id );
		if ( empty( $consent_id ) ) {
			return 0;
		}
		return $wpdb->delete(
			self::table(),
			array( 'consent_id' => $consent_id ),
			array( '%s' )
		);
	}

	public static function prune() {
		global $wpdb;
		$days = absint( GFW_Consent_Core::get_setting( 'log_retention_days', 365 ) );
		if ( $days < 30 ) {
			$days = 30;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare(
			'DELETE FROM ' . self::table() . ' WHERE created_at < %s',
			$cutoff
		) );
	}

	// ---------------------------------------------------------------------
	// Anonymization helpers
	// ---------------------------------------------------------------------

	/**
	 * Truncate IP address before hashing.
	 *   IPv4 -> zero the last octet     (192.168.1.123 -> 192.168.1.0)
	 *   IPv6 -> keep /64 prefix         (2001:db8:a:b:1:2:3:4 -> 2001:db8:a:b::)
	 *
	 * This aligns with EDPB Opinion 05/2014 and Google Analytics' own
	 * "IP anonymization" behaviour. Resulting hash is not reversible to
	 * an individual household.
	 */
	private static function anonymize_ip( $ip ) {
		if ( empty( $ip ) ) {
			return '';
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			if ( count( $parts ) !== 4 ) {
				return '';
			}
			$parts[3] = '0';
			return implode( '.', $parts );
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = @inet_pton( $ip );
			if ( false === $packed ) {
				return '';
			}
			// Keep the first 8 bytes (64 bits), zero the rest.
			$truncated = substr( $packed, 0, 8 ) . str_repeat( "\0", 8 );
			$back      = @inet_ntop( $truncated );
			return $back ? $back : '';
		}

		return '';
	}

	/**
	 * Reduce a User-Agent string to browser family + OS family.
	 * Strips version numbers, device identifiers, and build strings.
	 */
	private static function simplify_ua( $ua ) {
		if ( empty( $ua ) ) {
			return '';
		}

		// Browser detection — order matters (Edge contains "Chrome", etc.)
		$browser = 'Other';
		if ( stripos( $ua, 'Edg/' ) !== false || stripos( $ua, 'EdgA/' ) !== false ) {
			$browser = 'Edge';
		} elseif ( stripos( $ua, 'OPR/' ) !== false || stripos( $ua, 'Opera' ) !== false ) {
			$browser = 'Opera';
		} elseif ( stripos( $ua, 'Firefox/' ) !== false || stripos( $ua, 'FxiOS' ) !== false ) {
			$browser = 'Firefox';
		} elseif ( stripos( $ua, 'CriOS' ) !== false || stripos( $ua, 'Chrome/' ) !== false ) {
			$browser = 'Chrome';
		} elseif ( stripos( $ua, 'Safari/' ) !== false ) {
			$browser = 'Safari';
		} elseif ( stripos( $ua, 'bot' ) !== false || stripos( $ua, 'crawler' ) !== false || stripos( $ua, 'spider' ) !== false ) {
			$browser = 'Bot';
		}

		// OS family
		$os = 'Other';
		if ( stripos( $ua, 'Windows' ) !== false ) {
			$os = 'Windows';
		} elseif ( stripos( $ua, 'iPhone' ) !== false || stripos( $ua, 'iPad' ) !== false || stripos( $ua, 'iOS' ) !== false ) {
			$os = 'iOS';
		} elseif ( stripos( $ua, 'Mac OS' ) !== false || stripos( $ua, 'Macintosh' ) !== false ) {
			$os = 'macOS';
		} elseif ( stripos( $ua, 'Android' ) !== false ) {
			$os = 'Android';
		} elseif ( stripos( $ua, 'Linux' ) !== false ) {
			$os = 'Linux';
		}

		return $browser . '/' . $os;
	}

	/**
	 * Keep only the path component. Drop query, fragment, host, scheme.
	 */
	private static function simplify_url( $url ) {
		if ( empty( $url ) ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		return isset( $parts['path'] ) ? $parts['path'] : '/';
	}

	/**
	 * Best-effort client IP. Used only when privacy_level > minimal, and
	 * always fed through anonymize_ip() before hashing.
	 */
	private static function client_ip() {
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$ip = explode( ',', $_SERVER[ $k ] )[0];
				$ip = trim( $ip );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}

	// ---------------------------------------------------------------------
	// Read helpers (for admin UI)
	// ---------------------------------------------------------------------

	public static function recent( $limit = 100 ) {
		global $wpdb;
		$limit = absint( $limit );
		return $wpdb->get_results( "SELECT * FROM " . self::table() . " ORDER BY id DESC LIMIT $limit" );
	}

	public static function stats() {
		global $wpdb;
		$t = self::table();
		return array(
			'total'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" ),
			'accepts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE event='accept'" ),
			'rejects'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE event='reject'" ),
			'custom'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE event='custom'" ),
			'last_30'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)" ),
		);
	}
}
