<?php
/**
 * Plugin Name:       FISHWINK Consent
 * Plugin URI:        https://github.com/fishwink/gfw-consent
 * Description:       Lightweight cookie consent for FISHWINK client sites. Blocks marketing scripts until consent, logs all consent for audit trail, auto-generates cookie policy, and supports Google Consent Mode v2 + Global Privacy Control.
 * Version:           1.0.14
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            FISHWINK
 * Author URI:        https://gofishwink.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gfw-consent
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GFW_CONSENT_VERSION', '1.0.14' );
define( 'GFW_CONSENT_FILE', __FILE__ );
define( 'GFW_CONSENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'GFW_CONSENT_URL', plugin_dir_url( __FILE__ ) );
define( 'GFW_CONSENT_BASENAME', plugin_basename( __FILE__ ) );
define( 'GFW_CONSENT_OPT_KEY', 'gfw_consent_settings' );
define( 'GFW_CONSENT_SERVICES_KEY', 'gfw_consent_services' );
define( 'GFW_CONSENT_CUSTOM_KEY', 'gfw_consent_custom_services' );
define( 'GFW_CONSENT_COOKIE', 'gfw_consent' );

/**
 * GitHub Plugin Update Checker.
 * Drop the Plugin Update Checker library into /lib/plugin-update-checker/
 * and uncomment. Replace the URL with your private or public repo.
 */
if ( file_exists( GFW_CONSENT_PATH . 'lib/plugin-update-checker/plugin-update-checker.php' ) ) {
	require GFW_CONSENT_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';
	$gfw_consent_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/fishwink/gfw-consent/',
		__FILE__,
		'gfw-consent'
	);
	$gfw_consent_updater->setBranch( 'main' );
	// $gfw_consent_updater->setAuthentication( 'your-github-pat-if-private' );
	$gfw_consent_updater->getVcsApi()->enableReleaseAssets();
}

// Autoload core classes.
require_once GFW_CONSENT_PATH . 'includes/class-gfw-consent-services.php';
require_once GFW_CONSENT_PATH . 'includes/class-gfw-consent-blocker.php';
require_once GFW_CONSENT_PATH . 'includes/class-gfw-consent-scanner.php';
require_once GFW_CONSENT_PATH . 'includes/class-gfw-consent-logger.php';
require_once GFW_CONSENT_PATH . 'includes/class-gfw-consent-policy.php';
require_once GFW_CONSENT_PATH . 'includes/class-gfw-consent-frontend.php';
require_once GFW_CONSENT_PATH . 'includes/class-gfw-consent-rest.php';
require_once GFW_CONSENT_PATH . 'includes/class-gfw-consent-admin.php';
require_once GFW_CONSENT_PATH . 'includes/class-gfw-consent-core.php';

register_activation_hook( __FILE__, array( 'GFW_Consent_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GFW_Consent_Core', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'GFW_Consent_Core', 'instance' ) );
