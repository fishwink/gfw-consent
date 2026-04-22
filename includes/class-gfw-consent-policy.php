<?php
/**
 * Policy generator.
 *
 * Provides the [gfw_consent_policy] shortcode which renders the current
 * cookie policy based on services actually detected on the site.
 *
 * Also provides [gfw_consent_preferences] to render a "Manage preferences"
 * link that reopens the banner, and a small settings button for footer use.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GFW_Consent_Policy {

	public function __construct() {
		add_shortcode( 'gfw_consent_policy', array( $this, 'shortcode_policy' ) );
		add_shortcode( 'gfw_consent_preferences', array( $this, 'shortcode_preferences' ) );
	}

	public function shortcode_preferences( $atts ) {
		$atts = shortcode_atts(
			array( 'label' => __( 'Cookie preferences', 'gfw-consent' ) ),
			$atts
		);
		return sprintf(
			'<button type="button" class="gfw-consent-open-preferences" data-gfw-open="preferences">%s</button>',
			esc_html( $atts['label'] )
		);
	}

	public function shortcode_policy() {
		$services = get_option( GFW_CONSENT_SERVICES_KEY, array() );
		$catalog  = GFW_Consent_Services::catalog();
		$company  = GFW_Consent_Core::get_setting( 'company_name', get_bloginfo( 'name' ) );
		$contact  = GFW_Consent_Core::get_setting( 'company_contact', get_option( 'admin_email' ) );
		$last     = get_option( 'gfw_consent_last_scan', '' );

		ob_start();
		?>
		<div class="gfw-consent-policy">
			<h2><?php esc_html_e( 'Cookie Policy', 'gfw-consent' ); ?></h2>
			<p><?php
				printf(
					/* translators: 1: company name */
					esc_html__( 'This Cookie Policy explains how %s uses cookies and similar technologies on this website. It should be read alongside our Privacy Policy.', 'gfw-consent' ),
					'<strong>' . esc_html( $company ) . '</strong>'
				);
			?></p>

			<h3><?php esc_html_e( 'What are cookies?', 'gfw-consent' ); ?></h3>
			<p><?php esc_html_e( 'Cookies are small text files placed on your device when you visit a website. They are widely used to make websites work efficiently and to provide information to site owners.', 'gfw-consent' ); ?></p>

			<h3><?php esc_html_e( 'Your consent', 'gfw-consent' ); ?></h3>
			<p><?php esc_html_e( 'When you first visit this website, you are presented with a consent banner. You may accept all cookies, reject non-essential cookies, or choose specific categories. You can change your preferences at any time using the button below.', 'gfw-consent' ); ?></p>
			<p><?php echo $this->shortcode_preferences( array( 'label' => __( 'Change my cookie preferences', 'gfw-consent' ) ) ); ?></p>

			<h3><?php esc_html_e( 'Categories of cookies we use', 'gfw-consent' ); ?></h3>

			<h4><?php esc_html_e( 'Strictly necessary', 'gfw-consent' ); ?></h4>
			<p><?php esc_html_e( 'These cookies are required for the basic functions of the website (such as page navigation, security, and remembering your cookie choice). They do not store personally identifiable information and cannot be disabled.', 'gfw-consent' ); ?></p>

			<?php
			$by_category = array(
				'functional' => __( 'Functional', 'gfw-consent' ),
				'analytics'  => __( 'Analytics', 'gfw-consent' ),
				'marketing'  => __( 'Marketing', 'gfw-consent' ),
			);

			foreach ( $by_category as $cat_key => $cat_label ) :
				$cat_services = array_filter( $services, function( $key ) use ( $catalog, $cat_key ) {
					return isset( $catalog[ $key ] ) && $catalog[ $key ]['category'] === $cat_key;
				} );
				if ( empty( $cat_services ) ) {
					continue;
				}
				?>
				<h4><?php echo esc_html( $cat_label ); ?></h4>
				<p><?php echo esc_html( $this->category_description( $cat_key ) ); ?></p>
				<table class="gfw-consent-policy-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Service', 'gfw-consent' ); ?></th>
							<th><?php esc_html_e( 'Provider', 'gfw-consent' ); ?></th>
							<th><?php esc_html_e( 'Purpose', 'gfw-consent' ); ?></th>
							<th><?php esc_html_e( 'Cookies', 'gfw-consent' ); ?></th>
							<th><?php esc_html_e( 'Retention', 'gfw-consent' ); ?></th>
							<th><?php esc_html_e( 'Privacy policy', 'gfw-consent' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $cat_services as $key ) :
						$s = $catalog[ $key ];
						?>
						<tr>
							<td><?php echo esc_html( $s['name'] ); ?></td>
							<td><?php echo esc_html( $s['vendor'] ); ?></td>
							<td><?php echo esc_html( $s['purpose'] ); ?></td>
							<td><?php echo esc_html( implode( ', ', $s['cookies'] ) ); ?></td>
							<td><?php echo esc_html( $s['retention'] ); ?></td>
							<td><a href="<?php echo esc_url( $s['privacy'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View', 'gfw-consent' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>

			<h3><?php esc_html_e( 'Your rights', 'gfw-consent' ); ?></h3>
			<p><?php esc_html_e( 'Depending on your jurisdiction, you may have the right to access, correct, delete, or restrict the use of your personal information. You may also have the right to opt out of the sale or sharing of your personal information and to object to certain processing.', 'gfw-consent' ); ?></p>
			<p><?php esc_html_e( 'This site honors the Global Privacy Control (GPC) browser signal where applicable law requires it.', 'gfw-consent' ); ?></p>

			<h3><?php esc_html_e( 'Contact', 'gfw-consent' ); ?></h3>
			<p><?php
				printf(
					/* translators: 1: contact email/address */
					esc_html__( 'For any privacy-related questions, contact us at: %s', 'gfw-consent' ),
					'<a href="mailto:' . esc_attr( $contact ) . '">' . esc_html( $contact ) . '</a>'
				);
			?></p>

			<?php if ( $last ) : ?>
				<p class="gfw-consent-policy-meta"><em><?php
					printf(
						/* translators: 1: datetime */
						esc_html__( 'This policy was automatically updated based on services detected on this website. Last scan: %s', 'gfw-consent' ),
						esc_html( $last )
					);
				?></em></p>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private function category_description( $cat ) {
		switch ( $cat ) {
			case 'functional':
				return __( 'These cookies enable enhanced site functionality, such as embedded maps, chat widgets, or form protection. The site may still work without them but may be less convenient to use.', 'gfw-consent' );
			case 'analytics':
				return __( 'These cookies help us understand how visitors use the site so we can improve it. They collect information in an aggregated, anonymous form where possible.', 'gfw-consent' );
			case 'marketing':
				return __( 'These cookies are used to deliver advertising that is more relevant to you and your interests. They are also used to measure the effectiveness of our marketing campaigns across platforms.', 'gfw-consent' );
		}
		return '';
	}
}
