<?php
/**
 * Class Google\Site_Kit\Core\Consent_Mode\Consent_Mode
 *
 * @package   Google\Site_Kit\Core\Consent_Mode
 * @copyright 2024 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Core\Consent_Mode;

use Google\Site_Kit\Context;
use Google\Site_Kit\Core\Storage\Options;
use Google\Site_Kit\Core\Util\Feature_Flags;
use Google\Site_Kit\Core\Util\Method_Proxy_Trait;

/**
 * Class for handling Consent Mode.
 *
 * @since n.e.x.t
 * @access private
 * @ignore
 */
class Consent_Mode {
	use Method_Proxy_Trait;

	/**
	 * Consent_Mode_Settings instance.
	 *
	 * @since n.e.x.t
	 * @var Consent_Mode_Settings
	 */
	protected $consent_mode_settings;

	/**
	 * REST_Consent_Mode_Controller instance.
	 *
	 * @since n.e.x.t
	 * @var REST_Consent_Mode_Controller
	 */
	protected $rest_controller;

	/**
	 * Constructor.
	 *
	 * @since n.e.x.t
	 *
	 * @param Context $context Plugin context.
	 * @param Options $options Optional. Option API instance. Default is a new instance.
	 */
	public function __construct( Context $context, Options $options = null ) {
		$options                     = $options ?: new Options( $context );
		$this->consent_mode_settings = new Consent_Mode_Settings( $options );
		$this->rest_controller       = new REST_Consent_Mode_Controller( $this->consent_mode_settings );
	}

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since n.e.x.t
	 */
	public function register() {
		$this->consent_mode_settings->register();
		$this->rest_controller->register();

		if ( Feature_Flags::enabled( 'consentMode' ) && $this->consent_mode_settings->is_consent_mode_enabled() ) {
			// The `wp_head` action is used to ensure the snippets are printed in the head on the front-end only, not admin pages.
			add_action(
				'wp_head',
				$this->get_method_proxy( 'render_gtag_consent_snippet' ),
				1 // Set priority to 1 to ensure the snippet is printed with top priority in the head.
			);
		}
	}

	/**
	 * Prints the gtag consent snippet.
	 *
	 * @since n.e.x.t
	 */
	protected function render_gtag_consent_snippet() {
		$consent_defaults = array(
			'ad_personalization' => 'denied',
			'ad_storage'         => 'denied',
			'ad_user_data'       => 'denied',
			'analytics_storage'  => 'denied',
			'regions'            => $this->consent_mode_settings->get_regions(),
			'wait_for_update'    => 500, // Allow 500ms for Consent Management Platforms (CMPs) to update the consent status.
		);

		$consent_category_map = apply_filters(
			'googlesitekit_consent_category_map',
			array(
				'statistics' => array( 'analytics_storage' ),
				'marketing'  => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
			)
		);
		// TODO: We should extract this JS and make it a bit more readable.
		?>
<!-- <?php echo esc_html__( 'Google tag (gtag.js) Consent Mode snippet added by Site Kit', 'google-site-kit' ); ?> -->
<script id='google_gtagjs-js-consent-mode'>
window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}
gtag("consent","default",<?php echo wp_json_encode( $consent_defaults ); ?>);
window._googlesitekitConsentCategoryMap = <?php	echo wp_json_encode( $consent_category_map ); ?>;
document.addEventListener('wp_listen_for_consent_change', function(event) {
	if (event.detail) {
		var consentParameters = Object.entries(event.detail).reduce((parameters,[category,status]) => {
			if (window._googlesitekitConsentCategoryMap[category]) {
				var mappedStatus = status === 'allow' ? 'granted' : 'denied';
				window._googlesitekitConsentCategoryMap[category].forEach((parameter) => {
					parameters[parameter] = mappedStatus;
				});
			}
			return parameters;
		}, {});
		if (Object.keys(consentParameters).length) {
			gtag('consent','update',consentParameters);
		}
	}
});
function updateGrantedConsent(){
	if (!(window.wp_consent_type || window.wp_fallback_consent_type)){ return; }
	if (window._googlesitekitHasUpdatedConsent) {	return; } else { window._googlesitekitHasUpdatedConsent = true; }
	var consentParameters = Object.entries(window._googlesitekitConsentCategoryMap).reduce((consentParams,[category,parameters]) => {
		if (window.wp_has_consent && window.wp_has_consent(category)) {
			parameters.forEach((parameter) => consentParams[parameter] = 'granted');
		}
		return consentParams;
	},{});
	if (Object.keys(consentParameters).length) {
		gtag('consent','update',consentParameters);
	}
}
document.addEventListener('wp_consent_type_defined',updateGrantedConsent);
document.addEventListener("DOMContentLoaded",() => {
	if (!window.waitfor_consent_hook) {
		updateGrantedConsent();
	}
});
</script>
<!-- <?php echo esc_html__( 'End Google tag (gtag.js) Consent Mode snippet added by Site Kit', 'google-site-kit' ); ?> -->
			<?php
	}
}
