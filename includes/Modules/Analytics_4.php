<?php
/**
 * Class Google\Site_Kit\Modules\Analytics_4
 *
 * @package   Google\Site_Kit
 * @copyright 2021 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Modules;

use Exception;
use Google\Site_Kit\Context;
use Google\Site_Kit\Core\Assets\Asset;
use Google\Site_Kit\Core\Assets\Assets;
use Google\Site_Kit\Core\Assets\Script;
use Google\Site_Kit\Core\Authentication\Authentication;
use Google\Site_Kit\Core\Authentication\Clients\Google_Site_Kit_Client;
use Google\Site_Kit\Core\Dismissals\Dismissed_Items;
use Google\Site_Kit\Core\Modules\Analytics_4\Tag_Matchers;
use Google\Site_Kit\Core\Modules\Module;
use Google\Site_Kit\Core\Modules\Module_Settings;
use Google\Site_Kit\Core\Modules\Module_With_Activation;
use Google\Site_Kit\Core\Modules\Module_With_Deactivation;
use Google\Site_Kit\Core\Modules\Module_With_Debug_Fields;
use Google\Site_Kit\Core\Modules\Module_With_Assets;
use Google\Site_Kit\Core\Modules\Module_With_Assets_Trait;
use Google\Site_Kit\Core\Modules\Module_With_Data_Available_State;
use Google\Site_Kit\Core\Modules\Module_With_Data_Available_State_Trait;
use Google\Site_Kit\Core\Modules\Module_With_Scopes;
use Google\Site_Kit\Core\Modules\Module_With_Scopes_Trait;
use Google\Site_Kit\Core\Modules\Module_With_Settings;
use Google\Site_Kit\Core\Modules\Module_With_Settings_Trait;
use Google\Site_Kit\Core\Modules\Module_With_Owner;
use Google\Site_Kit\Core\Modules\Module_With_Owner_Trait;
use Google\Site_Kit\Core\Modules\Module_With_Service_Entity;
use Google\Site_Kit\Core\Permissions\Permissions;
use Google\Site_Kit\Core\Modules\Module_With_Tag;
use Google\Site_Kit\Core\Modules\Module_With_Tag_Trait;
use Google\Site_Kit\Core\Modules\Tags\Module_Tag_Matchers;
use Google\Site_Kit\Core\REST_API\Exception\Invalid_Datapoint_Exception;
use Google\Site_Kit\Core\REST_API\Data_Request;
use Google\Site_Kit\Core\REST_API\Exception\Missing_Required_Param_Exception;
use Google\Site_Kit\Core\Site_Health\Debug_Data;
use Google\Site_Kit\Core\Storage\Options;
use Google\Site_Kit\Core\Storage\User_Options;
use Google\Site_Kit\Core\Tags\Guards\Tag_Environment_Type_Guard;
use Google\Site_Kit\Core\Tags\Guards\Tag_Verify_Guard;
use Google\Site_Kit\Core\Util\BC_Functions;
use Google\Site_Kit\Core\Util\Feature_Flags;
use Google\Site_Kit\Core\Util\Method_Proxy_Trait;
use Google\Site_Kit\Core\Util\Sort;
use Google\Site_Kit\Core\Util\URL;
use Google\Site_Kit\Modules\AdSense\Settings as AdSense_Settings;
use Google\Site_Kit\Modules\Analytics_4\Account_Ticket;
use Google\Site_Kit\Modules\Analytics_4\Advanced_Tracking;
use Google\Site_Kit\Modules\Analytics_4\AMP_Tag;
use Google\Site_Kit\Modules\Analytics_4\Custom_Dimensions_Data_Available;
use Google\Site_Kit\Modules\Analytics_4\Synchronize_Property;
use Google\Site_Kit\Modules\Analytics_4\GoogleAnalyticsAdmin\AccountProvisioningService;
use Google\Site_Kit\Modules\Analytics_4\GoogleAnalyticsAdmin\EnhancedMeasurementSettingsModel;
use Google\Site_Kit\Modules\Analytics_4\GoogleAnalyticsAdmin\PropertiesAdSenseLinksService;
use Google\Site_Kit\Modules\Analytics_4\GoogleAnalyticsAdmin\PropertiesAudiencesService;
use Google\Site_Kit\Modules\Analytics_4\GoogleAnalyticsAdmin\PropertiesEnhancedMeasurementService;
use Google\Site_Kit\Modules\Analytics_4\GoogleAnalyticsAdmin\Proxy_GoogleAnalyticsAdminProvisionAccountTicketRequest;
use Google\Site_Kit\Modules\Analytics_4\Report\Request as Analytics_4_Report_Request;
use Google\Site_Kit\Modules\Analytics_4\Report\Response as Analytics_4_Report_Response;
use Google\Site_Kit\Modules\Analytics_4\Settings;
use Google\Site_Kit\Modules\Analytics_4\Tag_Guard;
use Google\Site_Kit\Modules\Analytics_4\Tag_Interface;
use Google\Site_Kit\Modules\Analytics_4\Web_Tag;
use Google\Site_Kit_Dependencies\Google\Model as Google_Model;
use Google\Site_Kit_Dependencies\Google\Service\AnalyticsData as Google_Service_AnalyticsData;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin as Google_Service_GoogleAnalyticsAdmin;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin\GoogleAnalyticsAdminV1alphaAudience;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin\GoogleAnalyticsAdminV1betaAccount;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin\GoogleAnalyticsAdminV1betaCustomDimension;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin\GoogleAnalyticsAdminV1betaDataStream;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin\GoogleAnalyticsAdminV1betaDataStreamWebStreamData;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin\GoogleAnalyticsAdminV1betaListDataStreamsResponse;
use Google\Site_Kit_Dependencies\Google\Service\GoogleAnalyticsAdmin\GoogleAnalyticsAdminV1betaProperty as Google_Service_GoogleAnalyticsAdmin_GoogleAnalyticsAdminV1betaProperty;
use Google\Site_Kit_Dependencies\Google\Service\TagManager as Google_Service_TagManager;
use Google\Site_Kit_Dependencies\Google_Service_TagManager_Container;
use Google\Site_Kit_Dependencies\Psr\Http\Message\RequestInterface;
use stdClass;
use WP_Error;

/**
 * Class representing the Analytics 4 module.
 *
 * @since 1.30.0
 * @access private
 * @ignore
 */
final class Analytics_4 extends Module
	implements Module_With_Scopes, Module_With_Settings, Module_With_Debug_Fields, Module_With_Owner, Module_With_Assets, Module_With_Service_Entity, Module_With_Activation, Module_With_Deactivation, Module_With_Data_Available_State, Module_With_Tag {

	use Method_Proxy_Trait;
	use Module_With_Assets_Trait;
	use Module_With_Owner_Trait;
	use Module_With_Scopes_Trait;
	use Module_With_Settings_Trait;
	use Module_With_Data_Available_State_Trait;
	use Module_With_Tag_Trait;

	const PROVISION_ACCOUNT_TICKET_ID = 'googlesitekit_analytics_provision_account_ticket_id';

	const READONLY_SCOPE  = 'https://www.googleapis.com/auth/analytics.readonly';
	const PROVISION_SCOPE = 'https://www.googleapis.com/auth/analytics.provision';
	const EDIT_SCOPE      = 'https://www.googleapis.com/auth/analytics.edit';

	/**
	 * Module slug name.
	 */
	const MODULE_SLUG = 'analytics-4';

	/**
	 * Prefix used to fetch custom dimensions in reports.
	 */
	const CUSTOM_EVENT_PREFIX = 'customEvent:';

	/**
	 * Custom dimensions tracked by Site Kit.
	 */
	const CUSTOM_DIMENSION_POST_AUTHOR     = 'googlesitekit_post_author';
	const CUSTOM_DIMENSION_POST_CATEGORIES = 'googlesitekit_post_categories';

	/**
	 * Custom_Dimensions_Data_Available instance.
	 *
	 * @since 1.113.0
	 * @var Custom_Dimensions_Data_Available
	 */
	protected $custom_dimensions_data_available;

	/**
	 * Constructor.
	 *
	 * @since 1.113.0
	 *
	 * @param Context        $context        Plugin context.
	 * @param Options        $options        Optional. Option API instance. Default is a new instance.
	 * @param User_Options   $user_options   Optional. User Option API instance. Default is a new instance.
	 * @param Authentication $authentication Optional. Authentication instance. Default is a new instance.
	 * @param Assets         $assets  Optional. Assets API instance. Default is a new instance.
	 */
	public function __construct(
		Context $context,
		Options $options = null,
		User_Options $user_options = null,
		Authentication $authentication = null,
		Assets $assets = null
	) {
		parent::__construct( $context, $options, $user_options, $authentication, $assets );
		$this->custom_dimensions_data_available = new Custom_Dimensions_Data_Available( $this->transients );
	}

	/**
	 * Registers functionality through WordPress hooks.
	 *
	 * @since 1.30.0
	 * @since 1.101.0 Added a filter hook to add the required `https://www.googleapis.com/auth/tagmanager.readonly` scope for GTE support.
	 */
	public function register() {
		$this->register_scopes_hook();

		$synchronize_property = new Synchronize_Property(
			$this,
			$this->user_options
		);
		$synchronize_property->register();

		( new Advanced_Tracking( $this->context ) )->register();

		add_action(
			'admin_init',
			function() use ( $synchronize_property ) {
				$synchronize_property->maybe_schedule_synchronize_property();
			}
		);

		add_action( 'admin_init', $this->get_method_proxy( 'handle_provisioning_callback' ) );

		// For non-AMP and AMP.
		add_action( 'wp_head', $this->get_method_proxy( 'print_tracking_opt_out' ), 0 );
		// For Web Stories plugin.
		add_action( 'web_stories_story_head', $this->get_method_proxy( 'print_tracking_opt_out' ), 0 );

		// Analytics 4 tag placement logic.
		add_action( 'template_redirect', array( $this, 'register_tag' ) );

		$this->get_settings()->on_change(
			function( $old_value, $new_value ) {
				// Ensure that the data available state is reset when the property ID or measurement ID changes.
				if ( $old_value['propertyID'] !== $new_value['propertyID'] || $old_value['measurementID'] !== $new_value['measurementID'] ) {
					$this->reset_data_available();
					$this->custom_dimensions_data_available->reset_data_available();
				}

				// Reset AdSense link settings when propertyID changes.
				if ( $old_value['propertyID'] !== $new_value['propertyID'] ) {
					$this->get_settings()->merge(
						array(
							'adSenseLinked'             => false,
							'adSenseLinkedLastSyncedAt' => 0,
						)
					);
				}
			}
		);

		// Check if the property ID has changed and reset availableCustomDimensions setting to null.
		add_filter(
			'pre_update_option_googlesitekit_analytics-4_settings',
			function ( $new_value, $old_value ) {
				if ( $new_value['propertyID'] !== $old_value['propertyID'] ) {
					$new_value['availableCustomDimensions'] = null;
				}

				return $new_value;
			},
			10,
			2
		);

		add_filter( 'googlesitekit_inline_modules_data', $this->get_method_proxy( 'inline_custom_dimensions_data' ) );

		add_filter(
			'googlesitekit_auth_scopes',
			function( array $scopes ) {
				$oauth_client = $this->authentication->get_oauth_client();

				$needs_tagmanager_scope = false;

				if ( $oauth_client->has_sufficient_scopes(
					array(
						self::READONLY_SCOPE,
						'https://www.googleapis.com/auth/tagmanager.readonly',
					)
				) ) {
					$needs_tagmanager_scope = true;
				} else {
					// Ensure the Tag Manager scope is not added as a required scope in the case where the user has
					// granted the Analytics scope but not the Tag Manager scope, in order to allow the GTE-specific
					// Unsatisfied Scopes notification to be displayed without the Additional Permissions Required
					// modal also appearing.
					if ( ! $oauth_client->has_sufficient_scopes(
						array(
							self::READONLY_SCOPE,
						)
					) ) {
						$needs_tagmanager_scope = true;
					}
				}

				if ( $needs_tagmanager_scope ) {
					$scopes[] = 'https://www.googleapis.com/auth/tagmanager.readonly';
				}

				return $scopes;
			}
		);

		add_filter( 'googlesitekit_allow_tracking_disabled', $this->get_method_proxy( 'filter_analytics_allow_tracking_disabled' ) );
	}

	/**
	 * Gets required Google OAuth scopes for the module.
	 *
	 * @since 1.30.0
	 *
	 * @return array List of Google OAuth scopes.
	 */
	public function get_scopes() {
		return array( self::READONLY_SCOPE );
	}

	/**
	 * Checks whether the module is connected.
	 *
	 * A module being connected means that all steps required as part of its activation are completed.
	 *
	 * @since 1.30.0
	 *
	 * @return bool True if module is connected, false otherwise.
	 */
	public function is_connected() {
		$required_keys = array(
			'accountID',
			'propertyID',
			'webDataStreamID',
			'measurementID',
		);

		$options = $this->get_settings()->get();
		foreach ( $required_keys as $required_key ) {
			if ( empty( $options[ $required_key ] ) ) {
				return false;
			}
		}

		return parent::is_connected();
	}

	/**
	 * Cleans up when the module is activated.
	 *
	 * @since 1.107.0
	 */
	public function on_activation() {
		$dismissed_items = new Dismissed_Items( $this->user_options );
		$dismissed_items->remove( 'key-metrics-connect-ga4-cta-widget' );
	}

	/**
	 * Cleans up when the module is deactivated.
	 *
	 * @since 1.30.0
	 */
	public function on_deactivation() {
		$this->get_settings()->delete();
		$this->reset_data_available();
		$this->custom_dimensions_data_available->reset_data_available();
	}

	/**
	 * Checks whether the AdSense module is connected.
	 *
	 * @since 1.121.0
	 *
	 * @return bool True if AdSense is connected, false otherwise.
	 */
	private function is_adsense_connected() {
		$adsense_settings = ( new AdSense_Settings( $this->options ) )->get();

		if ( empty( $adsense_settings['accountSetupComplete'] ) || empty( $adsense_settings['siteSetupComplete'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Gets an array of debug field definitions.
	 *
	 * @since 1.30.0
	 *
	 * @return array
	 */
	public function get_debug_fields() {
		$settings = $this->get_settings()->get();

		$debug_fields = array(
			'analytics_4_account_id'                  => array(
				'label' => __( 'Analytics 4 account ID', 'google-site-kit' ),
				'value' => $settings['accountID'],
				'debug' => Debug_Data::redact_debug_value( $settings['accountID'] ),
			),
			'analytics_4_ads_conversion_id'           => array(
				'label' => __( 'Analytics 4 ads conversion ID', 'google-site-kit' ),
				'value' => $settings['adsConversionID'],
				'debug' => Debug_Data::redact_debug_value( $settings['adsConversionID'] ),
			),
			'analytics_4_property_id'                 => array(
				'label' => __( 'Analytics 4 property ID', 'google-site-kit' ),
				'value' => $settings['propertyID'],
				'debug' => Debug_Data::redact_debug_value( $settings['propertyID'], 7 ),
			),
			'analytics_4_web_data_stream_id'          => array(
				'label' => __( 'Analytics 4 web data stream ID', 'google-site-kit' ),
				'value' => $settings['webDataStreamID'],
				'debug' => Debug_Data::redact_debug_value( $settings['webDataStreamID'] ),
			),
			'analytics_4_measurement_id'              => array(
				'label' => __( 'Analytics 4 measurement ID', 'google-site-kit' ),
				'value' => $settings['measurementID'],
				'debug' => Debug_Data::redact_debug_value( $settings['measurementID'] ),
			),
			'analytics_4_use_snippet'                 => array(
				'label' => __( 'Analytics 4 snippet placed', 'google-site-kit' ),
				'value' => $settings['useSnippet'] ? __( 'Yes', 'google-site-kit' ) : __( 'No', 'google-site-kit' ),
				'debug' => $settings['useSnippet'] ? 'yes' : 'no',
			),
			'analytics_4_available_custom_dimensions' => array(
				'label' => __( 'Analytics 4 available custom dimensions', 'google-site-kit' ),
				'value' => empty( $settings['availableCustomDimensions'] )
					? __( 'None', 'google-site-kit' )
					: join(
						/* translators: used between list items, there is a space after the comma */
						__( ', ', 'google-site-kit' ),
						$settings['availableCustomDimensions']
					),
				'debug' => empty( $settings['availableCustomDimensions'] )
					? 'none'
					: join( ', ', $settings['availableCustomDimensions'] ),
			),
		);

		if ( $this->is_adsense_connected() ) {
			$debug_fields['adsense_linked'] = array(
				'label' => __( 'AdSense Linked', 'google-site-kit' ),
				'value' => $settings['adSenseLinked'] ? __( 'Connected', 'google-site-kit' ) : __( 'Not connected', 'google-site-kit' ),
				'debug' => Debug_Data::redact_debug_value( $settings['adSenseLinked'] ),
			);

			$debug_fields['adsense_linked_last_synced_at'] = array(
				'label' => __( 'AdSense Linked Last Synced At', 'google-site-kit' ),
				'value' => $settings['adSenseLinkedLastSyncedAt'] ? gmdate( 'Y-m-d H:i:s', $settings['adSenseLinkedLastSyncedAt'] ) : __( 'Never synced', 'google-site-kit' ),
				'debug' => Debug_Data::redact_debug_value( $settings['adSenseLinkedLastSyncedAt'] ),
			);
		}

		return $debug_fields;
	}

	/**
	 * Gets map of datapoint to definition data for each.
	 *
	 * @since 1.30.0
	 *
	 * @return array Map of datapoints to their definitions.
	 */
	protected function get_datapoint_definitions() {
		$datapoints = array(
			'GET:account-summaries'                => array( 'service' => 'analyticsadmin' ),
			'GET:accounts'                         => array( 'service' => 'analyticsadmin' ),
			'GET:adsense-links'                    => array( 'service' => 'analyticsadsenselinks' ),
			'GET:container-lookup'                 => array(
				'service' => 'tagmanager',
				'scopes'  => array(
					'https://www.googleapis.com/auth/tagmanager.readonly',
				),
			),
			'GET:container-destinations'           => array(
				'service' => 'tagmanager',
				'scopes'  => array(
					'https://www.googleapis.com/auth/tagmanager.readonly',
				),
			),
			'GET:conversion-events'                => array(
				'service'   => 'analyticsadmin',
				'shareable' => true,
			),
			'POST:create-account-ticket'           => array(
				'service'                => 'analyticsprovisioning',
				'scopes'                 => array( self::EDIT_SCOPE ),
				'request_scopes_message' => __( 'You’ll need to grant Site Kit permission to create a new Analytics account on your behalf.', 'google-site-kit' ),
			),
			'GET:google-tag-settings'              => array(
				'service' => 'tagmanager',
				'scopes'  => array(
					'https://www.googleapis.com/auth/tagmanager.readonly',
				),
			),
			'POST:create-property'                 => array(
				'service'                => 'analyticsadmin',
				'scopes'                 => array( self::EDIT_SCOPE ),
				'request_scopes_message' => __( 'You’ll need to grant Site Kit permission to create a new Analytics 4 property on your behalf.', 'google-site-kit' ),
			),
			'POST:create-webdatastream'            => array(
				'service'                => 'analyticsadmin',
				'scopes'                 => array( self::EDIT_SCOPE ),
				'request_scopes_message' => __( 'You’ll need to grant Site Kit permission to create a new Analytics 4 web data stream for this site on your behalf.', 'google-site-kit' ),
			),
			'GET:properties'                       => array( 'service' => 'analyticsadmin' ),
			'GET:property'                         => array( 'service' => 'analyticsadmin' ),
			'GET:report'                           => array(
				'service'   => 'analyticsdata',
				'shareable' => true,
			),
			'GET:webdatastreams'                   => array( 'service' => 'analyticsadmin' ),
			'GET:webdatastreams-batch'             => array( 'service' => 'analyticsadmin' ),
			'GET:enhanced-measurement-settings'    => array( 'service' => 'analyticsenhancedmeasurement' ),
			'POST:enhanced-measurement-settings'   => array(
				'service'                => 'analyticsenhancedmeasurement',
				'scopes'                 => array( self::EDIT_SCOPE ),
				'request_scopes_message' => __( 'You’ll need to grant Site Kit permission to update enhanced measurement settings for this Analytics 4 web data stream on your behalf.', 'google-site-kit' ),
			),
			'POST:create-custom-dimension'         => array(
				'service'                => 'analyticsdata',
				'scopes'                 => array( self::EDIT_SCOPE ),
				'request_scopes_message' => __( 'You’ll need to grant Site Kit permission to create a new Analytics 4 custom dimension on your behalf.', 'google-site-kit' ),
			),
			'POST:sync-custom-dimensions'          => array(
				'service' => 'analyticsadmin',
			),
			'POST:custom-dimension-data-available' => array(
				'service' => '',
			),
		);

		if ( Feature_Flags::enabled( 'audienceSegmentation' ) ) {
			$datapoints['GET:audiences']        = array( 'service' => 'analyticsaudiences' );
			$datapoints['POST:create-audience'] = array(
				'service'                => 'analyticsaudiences',
				'scopes'                 => array( self::EDIT_SCOPE ),
				'request_scopes_message' => __( 'You’ll need to grant Site Kit permission to create new audiences for your Analytics 4 property on your behalf.', 'google-site-kit' ),
			);
		}

		return $datapoints;
	}

	/**
	 * Creates a new property for provided account.
	 *
	 * @since 1.35.0
	 * @since 1.98.0 Added `$options` parameter.
	 *
	 * @param string $account_id Account ID.
	 * @param array  $options {
	 *     Property options.
	 *
	 *     @type string $displayName Display name.
	 *     @type string $timezone    Timezone.
	 * }
	 * @return Google_Service_GoogleAnalyticsAdmin_GoogleAnalyticsAdminV1betaProperty A new property.
	 */
	private function create_property( $account_id, $options = array() ) {
		if ( ! empty( $options['displayName'] ) ) {
			$display_name = sanitize_text_field( $options['displayName'] );
		} else {
			$display_name = URL::parse( $this->context->get_reference_site_url(), PHP_URL_HOST );
		}

		if ( ! empty( $options['timezone'] ) ) {
			$timezone = $options['timezone'];
		} else {
			$timezone = get_option( 'timezone_string' ) ?: 'UTC';
		}

		$property = new Google_Service_GoogleAnalyticsAdmin_GoogleAnalyticsAdminV1betaProperty();
		$property->setParent( self::normalize_account_id( $account_id ) );
		$property->setDisplayName( $display_name );
		$property->setTimeZone( $timezone );

		return $this->get_service( 'analyticsadmin' )->properties->create( $property );
	}

	/**
	 * Creates a new web data stream for provided property.
	 *
	 * @since 1.35.0
	 * @since 1.98.0 Added `$options` parameter.
	 *
	 * @param string $property_id Property ID.
	 * @param array  $options {
	 *     Web data stream options.
	 *
	 *     @type string $displayName Display name.
	 * }
	 * @return GoogleAnalyticsAdminV1betaDataStream A new web data stream.
	 */
	private function create_webdatastream( $property_id, $options = array() ) {
		$site_url = $this->context->get_reference_site_url();

		if ( ! empty( $options['displayName'] ) ) {
			$display_name = sanitize_text_field( $options['displayName'] );
		} else {
			$display_name = URL::parse( $site_url, PHP_URL_HOST );
		}

		$data = new GoogleAnalyticsAdminV1betaDataStreamWebStreamData();
		$data->setDefaultUri( $site_url );

		$datastream = new GoogleAnalyticsAdminV1betaDataStream();
		$datastream->setDisplayName( $display_name );
		$datastream->setType( 'WEB_DATA_STREAM' );
		$datastream->setWebStreamData( $data );

		/* @var Google_Service_GoogleAnalyticsAdmin $analyticsadmin phpcs:ignore Squiz.PHP.CommentedOutCode.Found */
		$analyticsadmin = $this->get_service( 'analyticsadmin' );

		return $analyticsadmin
			->properties_dataStreams // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			->create(
				self::normalize_property_id( $property_id ),
				$datastream
			);
	}

	/**
	 * Outputs the user tracking opt-out script.
	 *
	 * This script opts out of all Google Analytics tracking, for all measurement IDs, regardless of implementation.
	 * E.g. via Tag Manager, etc.
	 *
	 * @since 1.5.0
	 * @since 1.121.0 Migrated from the Analytics (UA) class and adapted to only work for GA4 properties.
	 * @link https://developers.google.com/analytics/devguides/collection/analyticsjs/user-opt-out
	 */
	private function print_tracking_opt_out() {
		$settings    = $this->get_settings()->get();
		$account_id  = $settings['accountID'];
		$property_id = $settings['propertyID'];

		if ( ! $this->is_tracking_disabled() ) {
			return;
		}

		if ( $this->context->is_amp() ) : ?>
			<!-- <?php esc_html_e( 'Google Analytics AMP opt-out snippet added by Site Kit', 'google-site-kit' ); ?> -->
			<meta name="ga-opt-out" content="" id="__gaOptOutExtension">
			<!-- <?php esc_html_e( 'End Google Analytics AMP opt-out snippet added by Site Kit', 'google-site-kit' ); ?> -->
		<?php else : ?>
			<!-- <?php esc_html_e( 'Google Analytics opt-out snippet added by Site Kit', 'google-site-kit' ); ?> -->
			<?php
			// Opt-out should always use the measurement ID, even when using a GT tag.
			$tag_id = $this->get_measurement_id();
			if ( ! empty( $tag_id ) ) {
				BC_Functions::wp_print_inline_script_tag( sprintf( 'window["ga-disable-%s"] = true;', esc_attr( $tag_id ) ) );
			}
			?>
			<?php do_action( 'googlesitekit_analytics_tracking_opt_out', $property_id, $account_id ); ?>
			<!-- <?php esc_html_e( 'End Google Analytics opt-out snippet added by Site Kit', 'google-site-kit' ); ?> -->
			<?php
		endif;
	}

	/**
	 * Checks whether or not tracking snippet should be contextually disabled for this request.
	 *
	 * @since 1.1.0
	 * @since 1.121.0 Migrated here from the Analytics (UA) class.
	 *
	 * @return bool
	 */
	protected function is_tracking_disabled() {
		$settings = $this->get_settings()->get();

		// This filter is documented in Tag_Manager::filter_analytics_allow_tracking_disabled.
		if ( ! apply_filters( 'googlesitekit_allow_tracking_disabled', $settings['useSnippet'] ) ) {
			return false;
		}

		$disable_logged_in_users  = in_array( 'loggedinUsers', $settings['trackingDisabled'], true ) && is_user_logged_in();
		$disable_content_creators = in_array( 'contentCreators', $settings['trackingDisabled'], true ) && current_user_can( 'edit_posts' );

		$disabled = $disable_logged_in_users || $disable_content_creators;

		/**
		 * Filters whether or not the Analytics tracking snippet is output for the current request.
		 *
		 * @since 1.1.0
		 *
		 * @param $disabled bool Whether to disable tracking or not.
		 */
		return (bool) apply_filters( 'googlesitekit_analytics_tracking_disabled', $disabled );
	}

	/**
	 * Handles the provisioning callback after the user completes the terms of service.
	 *
	 * @since 1.9.0
	 * @since 1.98.0 Extended to handle callback from Admin API (no UA entities).
	 * @since 1.121.0 Migrated method from original Analytics class to Analytics_4 class.
	 */
	protected function handle_provisioning_callback() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		if ( ! current_user_can( Permissions::MANAGE_OPTIONS ) ) {
			return;
		}

		$input = $this->context->input();

		if ( ! $input->filter( INPUT_GET, 'gatoscallback' ) ) {
			return;
		}

		// First check that the accountTicketId matches one stored for the user.
		// This is always provided, even in the event of an error.
		$account_ticket_id = htmlspecialchars( $input->filter( INPUT_GET, 'accountTicketId' ) );
		// The create-account-ticket request stores the created account ticket in a transient before
		// sending the user off to the terms of service page.
		$account_ticket_transient_key = self::PROVISION_ACCOUNT_TICKET_ID . '::' . get_current_user_id();
		$account_ticket_params        = $this->transients->get( $account_ticket_transient_key );
		$account_ticket               = new Account_Ticket( $account_ticket_params );

		// Backwards compat for previous storage type which stored ID only.
		if ( is_scalar( $account_ticket_params ) ) {
			$account_ticket->set_id( $account_ticket_params );
		}

		if ( $account_ticket->get_id() !== $account_ticket_id ) {
			wp_safe_redirect(
				$this->context->admin_url( 'dashboard', array( 'error_code' => 'account_ticket_id_mismatch' ) )
			);
			exit;
		}

		// At this point, the accountTicketId is a match and params are loaded, so we can safely delete the transient.
		$this->transients->delete( $account_ticket_transient_key );

		// Next, check for a returned error.
		$error = $input->filter( INPUT_GET, 'error' );
		if ( ! empty( $error ) ) {
			wp_safe_redirect(
				$this->context->admin_url( 'dashboard', array( 'error_code' => htmlspecialchars( $error ) ) )
			);
			exit;
		}

		$account_id = htmlspecialchars( $input->filter( INPUT_GET, 'accountId' ) );

		if ( empty( $account_id ) ) {
			wp_safe_redirect(
				$this->context->admin_url( 'dashboard', array( 'error_code' => 'callback_missing_parameter' ) )
			);
			exit;
		}

		$new_settings = array();

		// At this point, account creation was successful.
		$new_settings['accountID'] = $account_id;

		$this->get_settings()->merge( $new_settings );

		$this->provision_property_webdatastream( $account_id, $account_ticket );

		wp_safe_redirect(
			$this->context->admin_url(
				'dashboard',
				array(
					'notification' => 'authentication_success',
					'slug'         => 'analytics-4',
				)
			)
		);
		exit;
	}

	/**
	 * Provisions new GA4 property and web data stream for provided account.
	 *
	 * @since 1.35.0
	 * @since 1.98.0 Added $account_ticket.
	 *
	 * @param string         $account_id     Account ID.
	 * @param Account_Ticket $account_ticket Account ticket instance.
	 */
	private function provision_property_webdatastream( $account_id, $account_ticket ) {
		// Reset the current GA4 settings.
		$this->get_settings()->merge(
			array(
				'propertyID'      => '',
				'webDataStreamID' => '',
				'measurementID'   => '',
			)
		);

		$property = $this->create_property(
			$account_id,
			array(
				'displayName' => $account_ticket->get_property_name(),
				'timezone'    => $account_ticket->get_timezone(),
			)
		);
		$property = self::filter_property_with_ids( $property );

		if ( empty( $property->_id ) ) {
			return;
		}

		$create_time    = isset( $property->createTime ) ? $property->createTime : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$create_time_ms = 0;
		if ( $create_time ) {
			$create_time_ms = Synchronize_Property::convert_time_to_unix_ms( $create_time );
		}

		$this->get_settings()->merge(
			array(
				'propertyID'         => $property->_id,
				'propertyCreateTime' => $create_time_ms,
			)
		);

		$web_datastream = $this->create_webdatastream(
			$property->_id,
			array(
				'displayName' => $account_ticket->get_data_stream_name(),
			)
		);
		$web_datastream = self::filter_webdatastream_with_ids( $web_datastream );

		if ( empty( $web_datastream->_id ) ) {
			return;
		}

		$measurement_id = $web_datastream->webStreamData->measurementId; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

		$this->get_settings()->merge(
			array(
				'webDataStreamID' => $web_datastream->_id,
				'measurementID'   => $measurement_id,
			)
		);

		if ( $account_ticket->get_enhanced_measurement_stream_enabled() ) {
			$this->set_data(
				'enhanced-measurement-settings',
				array(
					'propertyID'                  => $property->_id,
					'webDataStreamID'             => $web_datastream->_id,
					'enhancedMeasurementSettings' => array(
						// We can hardcode this to `true` here due to the conditional invocation.
						'streamEnabled' => true,
					),
				)
			);
		}

		$this->sync_google_tag_settings();
	}

	/**
	 * Syncs Google tag settings for the currently configured measurementID.
	 *
	 * @since 1.102.0
	 */
	protected function sync_google_tag_settings() {
		$settings       = $this->get_settings();
		$measurement_id = $settings->get()['measurementID'];

		if ( ! $measurement_id ) {
			return;
		}

		$google_tag_settings = $this->get_data( 'google-tag-settings', array( 'measurementID' => $measurement_id ) );

		if ( is_wp_error( $google_tag_settings ) ) {
			return;
		}

		$settings->merge( $google_tag_settings );
	}

	/**
	 * Creates a request object for the given datapoint.
	 *
	 * @since 1.30.0
	 *
	 * @param Data_Request $data Data request object.
	 * @return RequestInterface|callable|WP_Error Request object or callable on success, or WP_Error on failure.
	 *
	 * @throws Invalid_Datapoint_Exception Thrown if the datapoint does not exist.
	 * @throws Missing_Required_Param_Exception Thrown if a required parameter is missing or empty.
	 * phpcs:ignore Squiz.Commenting.FunctionCommentThrowTag.WrongNumber
	 */
	protected function create_data_request( Data_Request $data ) {
		switch ( "{$data->method}:{$data->datapoint}" ) {
			case 'GET:accounts':
				return $this->get_service( 'analyticsadmin' )->accounts->listAccounts();
			case 'GET:account-summaries':
				return $this->get_service( 'analyticsadmin' )->accountSummaries->listAccountSummaries( array( 'pageSize' => 200 ) );
			case 'GET:adsense-links':
				if ( empty( $data['propertyID'] ) ) {
					throw new Missing_Required_Param_Exception( 'propertyID' );
				}

				$parent = self::normalize_property_id( $data['propertyID'] );

				return $this->get_analyticsadsenselinks_service()->properties_adSenseLinks->listPropertiesAdSenseLinks( $parent );
			case 'GET:audiences':
				$settings = $this->get_settings()->get();
				if ( empty( $settings['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_setting',
						__( 'No connected Google Analytics 4 property ID.', 'google-site-kit' ),
						array( 'status' => 500 )
					);
				}

				$analyticsadmin = $this->get_analyticsaudiences_service();
				$property_id    = self::normalize_property_id( $settings['propertyID'] );

				return $analyticsadmin
					->properties_audiences
					->listPropertiesAudiences( $property_id );
			case 'POST:create-audience':
				$settings = $this->get_settings()->get();
				if ( ! isset( $settings['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_setting',
						__( 'No connected Google Analytics 4 property ID.', 'google-site-kit' ),
						array( 'status' => 500 )
					);
				}

				if ( ! isset( $data['audience'] ) ) {
					throw new Missing_Required_Param_Exception( 'audience' );
				}

				$property_id = $settings['propertyID'];
				$audience    = $data['audience'];

				$fields = array(
					'displayName',
					'description',
					'membershipDurationDays',
					'eventTrigger',
					'exclusionDurationMode',
					'filterClauses',
				);

				$invalid_keys = array_diff( array_keys( $audience ), $fields );

				if ( ! empty( $invalid_keys ) ) {
					return new WP_Error(
						'invalid_property_name',
						/* translators: %s: Invalid property names */
						sprintf( __( 'Invalid properties in audience: %s.', 'google-site-kit' ), implode( ', ', $invalid_keys ) ),
						array( 'status' => 400 )
					);
				}

				$property_id = self::normalize_property_id( $property_id );

				$post_body = new GoogleAnalyticsAdminV1alphaAudience( $audience );

				$analyticsadmin = $this->get_analyticsaudiences_service();

				return $analyticsadmin
					->properties_audiences
					->create(
						$property_id,
						$post_body
					);
			case 'POST:create-account-ticket':
				if ( empty( $data['displayName'] ) ) {
					throw new Missing_Required_Param_Exception( 'displayName' );
				}
				if ( empty( $data['regionCode'] ) ) {
					throw new Missing_Required_Param_Exception( 'regionCode' );
				}
				if ( empty( $data['propertyName'] ) ) {
					throw new Missing_Required_Param_Exception( 'propertyName' );
				}
				if ( empty( $data['dataStreamName'] ) ) {
					throw new Missing_Required_Param_Exception( 'dataStreamName' );
				}
				if ( empty( $data['timezone'] ) ) {
					throw new Missing_Required_Param_Exception( 'timezone' );
				}

				$account = new GoogleAnalyticsAdminV1betaAccount();
				$account->setDisplayName( $data['displayName'] );
				$account->setRegionCode( $data['regionCode'] );

				$credentials            = $this->authentication->credentials()->get();
				$account_ticket_request = new Proxy_GoogleAnalyticsAdminProvisionAccountTicketRequest();
				$account_ticket_request->setSiteId( $credentials['oauth2_client_id'] );
				$account_ticket_request->setSiteSecret( $credentials['oauth2_client_secret'] );
				$account_ticket_request->setRedirectUri( $this->get_provisioning_redirect_uri() );
				$account_ticket_request->setAccount( $account );

				return $this->get_service( 'analyticsprovisioning' )
					->accounts->provisionAccountTicket( $account_ticket_request );
			case 'POST:create-property':
				if ( ! isset( $data['accountID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'accountID' ),
						array( 'status' => 400 )
					);
				}

				$options = array(
					'displayName' => $data['displayName'],
					'timezone'    => $data['timezone'],
				);

				return $this->create_property( $data['accountID'], $options );
			case 'POST:create-webdatastream':
				if ( ! isset( $data['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'propertyID' ),
						array( 'status' => 400 )
					);
				}

				$options = array(
					'displayName' => $data['displayName'],
				);

				return $this->create_webdatastream( $data['propertyID'], $options );
			case 'GET:properties':
				if ( ! isset( $data['accountID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'accountID' ),
						array( 'status' => 400 )
					);
				}

				return $this->get_service( 'analyticsadmin' )->properties->listProperties(
					array(
						'filter'   => 'parent:' . self::normalize_account_id( $data['accountID'] ),
						'pageSize' => 200,
					)
				);
			case 'GET:property':
				if ( ! isset( $data['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'propertyID' ),
						array( 'status' => 400 )
					);
				}

				return $this->get_service( 'analyticsadmin' )->properties->get( self::normalize_property_id( $data['propertyID'] ) );
			case 'GET:report':
				if ( empty( $data['metrics'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'metrics' ),
						array( 'status' => 400 )
					);
				}

				$settings = $this->get_settings()->get();
				if ( empty( $settings['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_setting',
						__( 'No connected Google Analytics 4 property ID.', 'google-site-kit' ),
						array( 'status' => 500 )
					);
				}

				$report  = new Analytics_4_Report_Request( $this->context );
				$request = $report->create_request( $data, $this->is_shared_data_request( $data ) );
				if ( is_wp_error( $request ) ) {
					return $request;
				}

				$property_id = self::normalize_property_id( $settings['propertyID'] );
				$request->setProperty( $property_id );

				return $this->get_analyticsdata_service()->properties->runReport( $property_id, $request );
			case 'GET:enhanced-measurement-settings':
				if ( ! isset( $data['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'propertyID' ),
						array( 'status' => 400 )
					);
				}

				if ( ! isset( $data['webDataStreamID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'webDataStreamID' ),
						array( 'status' => 400 )
					);
				}

				$name = self::normalize_property_id(
					$data['propertyID']
				) . '/dataStreams/' . $data['webDataStreamID'] . '/enhancedMeasurementSettings';

				$analyticsadmin = $this->get_analyticsenhancedmeasurements_service();

				return $analyticsadmin
					->properties_enhancedMeasurements // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					->getEnhancedMeasurementSettings( $name );
			case 'POST:enhanced-measurement-settings':
				if ( ! isset( $data['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'propertyID' ),
						array( 'status' => 400 )
					);
				}

				if ( ! isset( $data['webDataStreamID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'webDataStreamID' ),
						array( 'status' => 400 )
					);
				}

				if ( ! isset( $data['enhancedMeasurementSettings'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'enhancedMeasurementSettings' ),
						array( 'status' => 400 )
					);
				}

				$enhanced_measurement_settings = $data['enhancedMeasurementSettings'];

				$fields = array(
					'name',
					'streamEnabled',
					'scrollsEnabled',
					'outboundClicksEnabled',
					'siteSearchEnabled',
					'videoEngagementEnabled',
					'fileDownloadsEnabled',
					'pageChangesEnabled',
					'formInteractionsEnabled',
					'searchQueryParameter',
					'uriQueryParameter',
				);

				$invalid_keys = array_diff( array_keys( $enhanced_measurement_settings ), $fields );

				if ( ! empty( $invalid_keys ) ) {
					return new WP_Error(
						'invalid_property_name',
						/* translators: %s: Invalid property names */
						sprintf( __( 'Invalid properties in enhancedMeasurementSettings: %s.', 'google-site-kit' ), implode( ', ', $invalid_keys ) ),
						array( 'status' => 400 )
					);
				}

				$name = self::normalize_property_id(
					$data['propertyID']
				) . '/dataStreams/' . $data['webDataStreamID'] . '/enhancedMeasurementSettings';

				$post_body = new EnhancedMeasurementSettingsModel( $data['enhancedMeasurementSettings'] );

				$analyticsadmin = $this->get_analyticsenhancedmeasurements_service();

				return $analyticsadmin
					->properties_enhancedMeasurements // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					->updateEnhancedMeasurementSettings(
						$name,
						$post_body,
						array(
							'updateMask' => 'streamEnabled', // Only allow updating the streamEnabled field for now.
						)
					);
			case 'POST:create-custom-dimension':
				if ( ! isset( $data['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'propertyID' ),
						array( 'status' => 400 )
					);
				}

				if ( ! isset( $data['customDimension'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'customDimension' ),
						array( 'status' => 400 )
					);
				}

				$custom_dimension_data = $data['customDimension'];

				$fields = array(
					'parameterName',
					'displayName',
					'description',
					'scope',
					'disallowAdsPersonalization',
				);

				$invalid_keys = array_diff( array_keys( $custom_dimension_data ), $fields );

				if ( ! empty( $invalid_keys ) ) {
					return new WP_Error(
						'invalid_property_name',
						/* translators: %s: Invalid property names */
						sprintf( __( 'Invalid properties in customDimension: %s.', 'google-site-kit' ), implode( ', ', $invalid_keys ) ),
						array( 'status' => 400 )
					);
				}

				// Define the valid `DimensionScope` enum values.
				$valid_scopes = array( 'EVENT', 'USER', 'ITEM' );

				// If the scope field is not set, default to `EVENT`.
				// Otherwise, validate against the enum values.
				if ( ! isset( $custom_dimension_data['scope'] ) ) {
					$custom_dimension_data['scope'] = 'EVENT';
				} elseif ( ! in_array( $custom_dimension_data['scope'], $valid_scopes, true ) ) {
					return new WP_Error(
						'invalid_scope',
						/* translators: %s: Invalid scope */
						sprintf( __( 'Invalid scope: %s.', 'google-site-kit' ), $custom_dimension_data['scope'] ),
						array( 'status' => 400 )
					);
				}

				$custom_dimension = new GoogleAnalyticsAdminV1betaCustomDimension();
				$custom_dimension->setParameterName( $custom_dimension_data['parameterName'] );
				$custom_dimension->setDisplayName( $custom_dimension_data['displayName'] );
				$custom_dimension->setDescription( $custom_dimension_data['description'] );
				$custom_dimension->setScope( $custom_dimension_data['scope'] );
				$custom_dimension->setDisallowAdsPersonalization( $custom_dimension_data['disallowAdsPersonalization'] );

				$analyticsadmin = $this->get_service( 'analyticsadmin' );

				return $analyticsadmin
					->properties_customDimensions // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					->create(
						self::normalize_property_id( $data['propertyID'] ),
						$custom_dimension
					);
			case 'POST:sync-custom-dimensions':
				$settings = $this->get_settings()->get();
				if ( empty( $settings['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_setting',
						__( 'No connected Google Analytics 4 property ID.', 'google-site-kit' ),
						array( 'status' => 500 )
					);
				}

				$analyticsadmin = $this->get_service( 'analyticsadmin' );

				return $analyticsadmin
					->properties_customDimensions // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					->listPropertiesCustomDimensions( self::normalize_property_id( $settings['propertyID'] ) );
			case 'POST:custom-dimension-data-available':
				if ( ! isset( $data['customDimension'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'customDimension' ),
						array( 'status' => 400 )
					);
				}

				if ( ! $this->custom_dimensions_data_available->is_valid_custom_dimension( $data['customDimension'] ) ) {
					return new WP_Error(
						'invalid_custom_dimension_slug',
						/* translators: %s: Invalid custom dimension slug */
						sprintf( __( 'Invalid custom dimension slug: %s.', 'google-site-kit' ), $data['customDimension'] ),
						array( 'status' => 400 )
					);
				}

				return function() use ( $data ) {
					return $this->custom_dimensions_data_available->set_data_available( $data['customDimension'] );
				};
			case 'GET:webdatastreams':
				if ( ! isset( $data['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'propertyID' ),
						array( 'status' => 400 )
					);
				}

				$analyticsadmin = $this->get_service( 'analyticsadmin' );

				return $analyticsadmin
					->properties_dataStreams // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					->listPropertiesDataStreams(
						self::normalize_property_id( $data['propertyID'] )
					);
			case 'GET:webdatastreams-batch':
				if ( ! isset( $data['propertyIDs'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'propertyIDs' ),
						array( 'status' => 400 )
					);
				}

				if ( ! is_array( $data['propertyIDs'] ) || count( $data['propertyIDs'] ) > 10 ) {
					return new WP_Error(
						'rest_invalid_param',
						/* translators: %s: List of invalid parameters. */
						sprintf( __( 'Invalid parameter(s): %s', 'google-site-kit' ), 'propertyIDs' ),
						array( 'status' => 400 )
					);
				}

				$analyticsadmin = $this->get_service( 'analyticsadmin' );
				$batch_request  = $analyticsadmin->createBatch();

				foreach ( $data['propertyIDs'] as $property_id ) {
					$batch_request->add(
						$analyticsadmin
							->properties_dataStreams // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							->listPropertiesDataStreams(
								self::normalize_property_id( $property_id )
							)
					);
				}

				return function() use ( $batch_request ) {
					return $batch_request->execute();
				};
			case 'GET:container-lookup':
				if ( ! isset( $data['destinationID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'destinationID' ),
						array( 'status' => 400 )
					);
				}

				return $this->get_tagmanager_service()->accounts_containers->lookup( array( 'destinationId' => $data['destinationID'] ) );
			case 'GET:container-destinations':
				if ( ! isset( $data['accountID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'accountID' ),
						array( 'status' => 400 )
					);
				}
				if ( ! isset( $data['containerID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'containerID' ),
						array( 'status' => 400 )
					);
				}

				return $this->get_tagmanager_service()->accounts_containers_destinations->listAccountsContainersDestinations(
					"accounts/{$data['accountID']}/containers/{$data['containerID']}"
				);
			case 'GET:google-tag-settings':
				if ( ! isset( $data['measurementID'] ) ) {
					return new WP_Error(
						'missing_required_param',
						/* translators: %s: Missing parameter name */
						sprintf( __( 'Request parameter is empty: %s.', 'google-site-kit' ), 'measurementID' ),
						array( 'status' => 400 )
					);
				}

				return $this->get_tagmanager_service()->accounts_containers->lookup( array( 'destinationId' => $data['measurementID'] ) );
			case 'GET:conversion-events':
				$settings = $this->get_settings()->get();
				if ( empty( $settings['propertyID'] ) ) {
					return new WP_Error(
						'missing_required_setting',
						__( 'No connected Google Analytics 4 property ID.', 'google-site-kit' ),
						array( 'status' => 500 )
					);
				}

				$analyticsadmin = $this->get_service( 'analyticsadmin' );
				$property_id    = self::normalize_property_id( $settings['propertyID'] );

				return $analyticsadmin
					->properties_conversionEvents // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					->listPropertiesConversionEvents( $property_id );
		}

		return parent::create_data_request( $data );
	}

	/**
	 * Parses a response for the given datapoint.
	 *
	 * @since 1.30.0
	 *
	 * @param Data_Request $data     Data request object.
	 * @param mixed        $response Request response.
	 *
	 * @return mixed Parsed response data on success, or WP_Error on failure.
	 */
	protected function parse_data_response( Data_Request $data, $response ) {
		switch ( "{$data->method}:{$data->datapoint}" ) {
			case 'GET:accounts':
				return array_map( array( self::class, 'filter_account_with_ids' ), $response->getAccounts() );
			case 'GET:account-summaries':
				$account_summaries = array_map(
					function( $account ) {
						$obj                    = self::filter_account_with_ids( $account, 'account' );
						$obj->propertySummaries = array_map( // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
							function( $property ) {
								return self::filter_property_with_ids( $property, 'property' );
							},
							$account->getPropertySummaries()
						);

						return $obj;
					},
					$response->getAccountSummaries()
				);
				return Sort::case_insensitive_list_sort(
					$account_summaries,
					'displayName'
				);
			case 'GET:adsense-links':
				return (array) $response->getAdsenseLinks();
			case 'POST:create-account-ticket':
				$account_ticket = new Account_Ticket();
				$account_ticket->set_id( $response->getAccountTicketId() );
				// Required in create_data_request.
				$account_ticket->set_property_name( $data['propertyName'] );
				$account_ticket->set_data_stream_name( $data['dataStreamName'] );
				$account_ticket->set_timezone( $data['timezone'] );
				$account_ticket->set_enhanced_measurement_stream_enabled( ! empty( $data['enhancedMeasurementStreamEnabled'] ) );
				// Cache the create ticket id long enough to verify it upon completion of the terms of service.
				set_transient(
					self::PROVISION_ACCOUNT_TICKET_ID . '::' . get_current_user_id(),
					$account_ticket->to_array(),
					15 * MINUTE_IN_SECONDS
				);

				return $response;
			case 'POST:create-property':
				return self::filter_property_with_ids( $response );
			case 'POST:create-webdatastream':
				return self::filter_webdatastream_with_ids( $response );
			case 'GET:properties':
				return Sort::case_insensitive_list_sort(
					array_map( array( self::class, 'filter_property_with_ids' ), $response->getProperties() ),
					'displayName'
				);
			case 'GET:property':
				return self::filter_property_with_ids( $response );
			case 'GET:webdatastreams':
				/* @var GoogleAnalyticsAdminV1betaListDataStreamsResponse $response phpcs:ignore Squiz.PHP.CommentedOutCode.Found */
				$webdatastreams = self::filter_web_datastreams( $response->getDataStreams() );
				return array_map( array( self::class, 'filter_webdatastream_with_ids' ), $webdatastreams );
			case 'GET:webdatastreams-batch':
				return self::parse_webdatastreams_batch( $response );
			case 'GET:container-destinations':
				return (array) $response->getDestination();
			case 'GET:google-tag-settings':
				return $this->get_google_tag_settings_for_measurement_id( $response, $data['measurementID'] );
			case 'GET:conversion-events':
				return (array) $response->getConversionEvents();
			case 'GET:report':
				$report = new Analytics_4_Report_Response( $this->context );
				return $report->parse_response( $data, $response );
			case 'POST:sync-custom-dimensions':
				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$custom_dimensions   = wp_list_pluck( $response->getCustomDimensions(), 'parameterName' );
				$matching_dimensions = array_values(
					array_filter(
						$custom_dimensions,
						function( $dimension ) {
							return strpos( $dimension, 'googlesitekit_' ) === 0;
						}
					)
				);
				$this->get_settings()->merge(
					array(
						'availableCustomDimensions' => $matching_dimensions,
					)
				);

				// Reset the data available state for custom dimensions that are no longer available.
				$missing_custom_dimensions_with_data_available = array_diff(
					array_keys(
						// Only compare against custom dimensions that have data available.
						array_filter(
							$this->custom_dimensions_data_available->get_data_availability()
						)
					),
					$matching_dimensions
				);

				if ( count( $missing_custom_dimensions_with_data_available ) > 0 ) {
					$this->custom_dimensions_data_available->reset_data_available(
						$missing_custom_dimensions_with_data_available
					);
				}

				return $matching_dimensions;
		}

		return parent::parse_data_response( $data, $response );
	}

	/**
	 * Gets the configured TagManager service instance.
	 *
	 * @since 1.92.0
	 *
	 * @return Google_Service_TagManager instance.
	 * @throws Exception Thrown if the module did not correctly set up the service.
	 */
	private function get_tagmanager_service() {
		return $this->get_service( 'tagmanager' );
	}

	/**
	 * Sets up information about the module.
	 *
	 * @since 1.30.0
	 * @since n.e.x.t Updated to include in the module setup.
	 *
	 * @return array Associative array of module info.
	 */
	protected function setup_info() {
		return array(
			'slug'        => self::MODULE_SLUG,
			'name'        => _x( 'Analytics', 'Service name', 'google-site-kit' ),
			'description' => __( 'Get a deeper understanding of your customers. Google Analytics gives you the free tools you need to analyze data for your business in one place.', 'google-site-kit' ),
			'order'       => 3,
			'homepage'    => __( 'https://analytics.google.com/analytics/web', 'google-site-kit' ),
		);
	}

	/**
	 * Gets the configured Analytics Data service object instance.
	 *
	 * @since 1.93.0
	 *
	 * @return Google_Service_AnalyticsData The Analytics Data API service.
	 */
	protected function get_analyticsdata_service() {
		return $this->get_service( 'analyticsdata' );
	}

	/**
	 * Gets the configured Analytics Data service object instance.
	 *
	 * @since 1.110.0
	 *
	 * @return PropertiesEnhancedMeasurementService The Analytics Admin API service.
	 */
	protected function get_analyticsenhancedmeasurements_service() {
		return $this->get_service( 'analyticsenhancedmeasurement' );
	}

	/**
	 * Gets the configured Analytics Admin service object instance that includes `adSenseLinks` related methods.
	 *
	 * @since 1.120.0
	 *
	 * @return PropertiesAdSenseLinksService The Analytics Admin API service.
	 */
	protected function get_analyticsadsenselinks_service() {
		return $this->get_service( 'analyticsadsenselinks' );
	}

	/**
	 * Gets the configured Analytics Data service object instance.
	 *
	 * @since 1.120.0
	 *
	 * @return PropertiesAudiencesService The Analytics Admin API service.
	 */
	protected function get_analyticsaudiences_service() {
		return $this->get_service( 'analyticsaudiences' );
	}

	/**
	 * Sets up the Google services the module should use.
	 *
	 * This method is invoked once by {@see Module::get_service()} to lazily set up the services when one is requested
	 * for the first time.
	 *
	 * @since 1.30.0
	 *
	 * @param Google_Site_Kit_Client $client Google client instance.
	 * @return array Google services as $identifier => $service_instance pairs. Every $service_instance must be an
	 *               instance of Google_Service.
	 */
	protected function setup_services( Google_Site_Kit_Client $client ) {
		$google_proxy = $this->authentication->get_google_proxy();

		return array(
			'analyticsadmin'               => new Google_Service_GoogleAnalyticsAdmin( $client ),
			'analyticsdata'                => new Google_Service_AnalyticsData( $client ),
			'analyticsprovisioning'        => new AccountProvisioningService( $client, $google_proxy->url() ),
			'analyticsenhancedmeasurement' => new PropertiesEnhancedMeasurementService( $client ),
			'analyticsaudiences'           => new PropertiesAudiencesService( $client ),
			'analyticsadsenselinks'        => new PropertiesAdSenseLinksService( $client ),
			'tagmanager'                   => new Google_Service_TagManager( $client ),
		);
	}

	/**
	 * Sets up the module's settings instance.
	 *
	 * @since 1.30.0
	 *
	 * @return Module_Settings
	 */
	protected function setup_settings() {
		return new Settings( $this->options );
	}

	/**
	 * Sets up the module's assets to register.
	 *
	 * @since 1.31.0
	 *
	 * @return Asset[] List of Asset objects.
	 */
	protected function setup_assets() {
		$base_url = $this->context->url( 'dist/assets/' );

		return array(
			new Script(
				'googlesitekit-modules-analytics-4',
				array(
					'src'          => $base_url . 'js/googlesitekit-modules-analytics-4.js',
					'dependencies' => array(
						'googlesitekit-vendor',
						'googlesitekit-api',
						'googlesitekit-data',
						'googlesitekit-modules',
						'googlesitekit-datastore-site',
						'googlesitekit-datastore-user',
						'googlesitekit-datastore-forms',
						'googlesitekit-components',
						'googlesitekit-modules-data',
					),
				)
			),
		);
	}

	/**
	 * Gets the provisioning redirect URI that listens for the Terms of Service redirect.
	 *
	 * @since 1.98.0
	 *
	 * @return string Provisioning redirect URI.
	 */
	private function get_provisioning_redirect_uri() {
		return $this->authentication->get_google_proxy()
			->get_site_fields()['analytics_redirect_uri'];
	}

	/**
	 * Registers the Analytics 4 tag.
	 *
	 * @since 1.31.0
	 * @since 1.104.0 Added support for AMP tag.
	 * @since 1.119.0 Made method public.
	 */
	public function register_tag() {
		$tag = $this->context->is_amp()
			? new AMP_Tag( $this->get_measurement_id(), self::MODULE_SLUG ) // AMP currently only works with the measurement ID.
			: new Web_Tag( $this->get_tag_id(), self::MODULE_SLUG );

		if ( $tag->is_tag_blocked() ) {
			return;
		}

		$tag->use_guard( new Tag_Verify_Guard( $this->context->input() ) );
		$tag->use_guard( new Tag_Guard( $this->get_settings() ) );
		$tag->use_guard( new Tag_Environment_Type_Guard() );

		if ( ! $tag->can_register() ) {
			return;
		}

		$home_domain = URL::parse( $this->context->get_canonical_home_url(), PHP_URL_HOST );
		$tag->set_home_domain( $home_domain );

		$custom_dimensions_data = $this->get_custom_dimensions_data();
		if ( ! empty( $custom_dimensions_data ) && $tag instanceof Tag_Interface ) {
			$tag->set_custom_dimensions( $custom_dimensions_data );
		}

		$tag->set_ads_conversion_id(
			$this->get_settings()->get()['adsConversionID']
		);

		$tag->register();
	}

	/**
	 * Returns the Module_Tag_Matchers instance.
	 *
	 * @since 1.119.0
	 *
	 * @return Module_Tag_Matchers Module_Tag_Matchers instance.
	 */
	public function get_tag_matchers() {
		return new Tag_Matchers();
	}

	/**
	 * Gets custom dimensions data based on available custom dimensions.
	 *
	 * @since 1.113.0
	 *
	 * @return array An associated array of custom dimensions data.
	 */
	private function get_custom_dimensions_data() {
		if ( ! is_singular() ) {
			return array();
		}

		$settings = $this->get_settings()->get();
		if ( empty( $settings['availableCustomDimensions'] ) ) {
			return array();
		}

		/**
		 * Filters the allowed post types for custom dimensions tracking.
		 *
		 * @since 1.113.0
		 *
		 * @param array $allowed_post_types The array of allowed post types.
		 */
		$allowed_post_types = apply_filters( 'googlesitekit_custom_dimension_valid_post_types', array( 'post' ) );

		$data = array();
		$post = get_queried_object();

		if ( in_array( 'googlesitekit_post_type', $settings['availableCustomDimensions'], true ) ) {
			$data['googlesitekit_post_type'] = $post->post_type;
		}

		if ( is_singular( $allowed_post_types ) ) {
			foreach ( $settings['availableCustomDimensions'] as $custom_dimension ) {
				switch ( $custom_dimension ) {
					case 'googlesitekit_post_author':
						$author = get_userdata( $post->post_author );

						if ( $author ) {
							$data[ $custom_dimension ] = $author->display_name ? $author->display_name : $author->user_login;
						}

						break;
					case 'googlesitekit_post_categories':
						$categories = get_the_category( $post->ID );

						if ( ! empty( $categories ) ) {
							$category_names = wp_list_pluck( $categories, 'name' );

							$data[ $custom_dimension ] = implode( '; ', $category_names );
						}

						break;
					case 'googlesitekit_post_date':
						$data[ $custom_dimension ] = get_the_date( 'Ymd', $post );
						break;
				}
			}
		}

		return $data;
	}

	/**
	 * Parses account ID, adds it to the model object and returns updated model.
	 *
	 * @since 1.31.0
	 *
	 * @param Google_Model $account Account model.
	 * @param string       $id_key   Attribute name that contains account id.
	 * @return stdClass Updated model with _id attribute.
	 */
	public static function filter_account_with_ids( $account, $id_key = 'name' ) {
		$obj = $account->toSimpleObject();

		$matches = array();
		if ( preg_match( '#accounts/([^/]+)#', $account[ $id_key ], $matches ) ) {
			$obj->_id = $matches[1];
		}

		return $obj;
	}

	/**
	 * Parses account and property IDs, adds it to the model object and returns updated model.
	 *
	 * @since 1.31.0
	 *
	 * @param Google_Model $property Property model.
	 * @param string       $id_key   Attribute name that contains property id.
	 * @return stdClass Updated model with _id and _accountID attributes.
	 */
	public static function filter_property_with_ids( $property, $id_key = 'name' ) {
		$obj = $property->toSimpleObject();

		$matches = array();
		if ( preg_match( '#properties/([^/]+)#', $property[ $id_key ], $matches ) ) {
			$obj->_id = $matches[1];
		}

		$matches = array();
		if ( preg_match( '#accounts/([^/]+)#', $property['parent'], $matches ) ) {
			$obj->_accountID = $matches[1]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		return $obj;
	}

	/**
	 * Parses property and web datastream IDs, adds it to the model object and returns updated model.
	 *
	 * @since 1.31.0
	 *
	 * @param Google_Model $webdatastream Web datastream model.
	 * @return stdClass Updated model with _id and _propertyID attributes.
	 */
	public static function filter_webdatastream_with_ids( $webdatastream ) {
		$obj = $webdatastream->toSimpleObject();

		$matches = array();
		if ( preg_match( '#properties/([^/]+)/dataStreams/([^/]+)#', $webdatastream['name'], $matches ) ) {
			$obj->_id         = $matches[2];
			$obj->_propertyID = $matches[1]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		}

		return $obj;
	}

	/**
	 * Filters a list of data stream objects and returns only web data streams.
	 *
	 * @since 1.49.1
	 *
	 * @param GoogleAnalyticsAdminV1betaDataStream[] $datastreams Data streams to filter.
	 * @return GoogleAnalyticsAdminV1betaDataStream[] Web data streams.
	 */
	public static function filter_web_datastreams( array $datastreams ) {
		return array_filter(
			$datastreams,
			function ( GoogleAnalyticsAdminV1betaDataStream $datastream ) {
				return $datastream->getType() === 'WEB_DATA_STREAM';
			}
		);
	}

	/**
	 * Parses a response, adding the _id and _propertyID params and converting to an array keyed by the propertyID and web datastream IDs.
	 *
	 * @since 1.39.0
	 *
	 * @param GoogleAnalyticsAdminV1betaListDataStreamsResponse[] $batch_response Array of GoogleAnalyticsAdminV1betaListWebDataStreamsResponse objects.
	 * @return stdClass[] Array of models containing _id and _propertyID attributes, keyed by the propertyID.
	 */
	public static function parse_webdatastreams_batch( $batch_response ) {
		$mapped = array();

		foreach ( $batch_response as $response ) {
			if ( $response instanceof Exception ) {
				continue;
			}

			$webdatastreams = self::filter_web_datastreams( $response->getDataStreams() );

			foreach ( $webdatastreams as $webdatastream ) {
				$value            = self::filter_webdatastream_with_ids( $webdatastream );
				$key              = $value->_propertyID; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$mapped[ $key ]   = isset( $mapped[ $key ] ) ? $mapped[ $key ] : array();
				$mapped[ $key ][] = $value;
			}
		}

		return $mapped;
	}

	/**
	 * Normalizes account ID and returns it.
	 *
	 * @since 1.31.0
	 *
	 * @param string $account_id Account ID.
	 * @return string Updated account ID with "accounts/" prefix.
	 */
	public static function normalize_account_id( $account_id ) {
		return 'accounts/' . $account_id;
	}

	/**
	 * Normalizes property ID and returns it.
	 *
	 * @since 1.31.0
	 *
	 * @param string $property_id Property ID.
	 * @return string Updated property ID with "properties/" prefix.
	 */
	public static function normalize_property_id( $property_id ) {
		return 'properties/' . $property_id;
	}

	/**
	 * Checks if the current user has access to the current configured service entity.
	 *
	 * @since 1.70.0
	 *
	 * @return boolean|WP_Error
	 */
	public function check_service_entity_access() {
		$analyticsadmin = $this->get_service( 'analyticsadmin' );
		$settings       = $this->settings->get();

		try {
			$analyticsadmin
			->properties_dataStreams // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			->listPropertiesDataStreams(
				self::normalize_property_id( $settings['propertyID'] )
			);
		} catch ( Exception $e ) {
			if ( $e->getCode() === 403 ) {
				return false;
			}
			return $this->exception_to_error( $e );
		}

		return true;
	}

	/**
	 * Gets the Google Tag Settings for the given measurement ID.
	 *
	 * @since 1.94.0
	 *
	 * @param Google_Service_TagManager_Container $container Tag Manager container.
	 * @param string                              $measurement_id Measurement ID.
	 * @return array Google Tag Settings.
	 */
	protected function get_google_tag_settings_for_measurement_id( $container, $measurement_id ) {
		return array(
			'googleTagAccountID'   => $container->getAccountId(),
			'googleTagContainerID' => $container->getContainerId(),
			'googleTagID'          => $this->determine_google_tag_id_from_tag_ids( $container->getTagIds(), $measurement_id ),
		);
	}

	/**
	 * Determines Google Tag ID from the given Tag IDs.
	 *
	 * @since 1.94.0
	 *
	 * @param array  $tag_ids Tag IDs.
	 * @param string $measurement_id Measurement ID.
	 * @return string Google Tag ID.
	 */
	private function determine_google_tag_id_from_tag_ids( $tag_ids, $measurement_id ) {
		// If there is only one tag id in the array, return it.
		if ( count( $tag_ids ) === 1 ) {
			return $tag_ids[0];
		}

		// If there are multiple tags, return the first one that starts with `GT-`.
		foreach ( $tag_ids as $tag_id ) {
			if ( substr( $tag_id, 0, 3 ) === 'GT-' ) { // strlen( 'GT-' ) === 3.
				return $tag_id;
			}
		}

		// Otherwise, return the `$measurement_id` if it is in the array.
		if ( in_array( $measurement_id, $tag_ids, true ) ) {
			return $measurement_id;
		}

		// Otherwise, return the first one that starts with `G-`.
		foreach ( $tag_ids as $tag_id ) {
			if ( substr( $tag_id, 0, 2 ) === 'G-' ) { // strlen( 'G-' ) === 2.
				return $tag_id;
			}
		}

		// If none of the above, return the first one.
		return $tag_ids[0];
	}

	/**
	 * Gets the Google Analytics 4 tag ID.
	 *
	 * @since 1.96.0
	 *
	 * @return string Google Analytics 4 tag ID.
	 */
	private function get_tag_id() {
		$settings = $this->get_settings()->get();

		if ( ! empty( $settings['googleTagID'] ) ) {
			return $settings['googleTagID'];
		}
		return $settings['measurementID'];
	}

	/**
	 * Gets the currently configured measurement ID.
	 *
	 * @since 1.104.0
	 *
	 * @return string Google Analytics 4 measurement ID.
	 */
	protected function get_measurement_id() {
		$settings = $this->get_settings()->get();

		return $settings['measurementID'];
	}

	/**
	 * Populates custom dimension data to pass to JS via _googlesitekitModulesData.
	 *
	 * @since 1.113.0
	 *
	 * @param array $modules_data Inline modules data.
	 * @return array Inline modules data.
	 */
	private function inline_custom_dimensions_data( $modules_data ) {
		if ( $this->is_connected() ) {
			// Add the data under the `analytics-4` key to make it clear it's scoped to this module.
			$modules_data['analytics-4'] = array(
				'customDimensionsDataAvailable' => $this->custom_dimensions_data_available->get_data_availability(),
			);
		}

		return $modules_data;
	}

	/**
	 * Filters whether or not the option to exclude certain users from tracking should be displayed.
	 *
	 * If the Analytics-4 module is enabled, and the snippet is enabled, then the option to exclude
	 * the option to exclude certain users from tracking should be displayed.
	 *
	 * @since 1.101.0
	 *
	 * @param bool $allowed Whether to allow tracking exclusion.
	 * @return bool Filtered value.
	 */
	private function filter_analytics_allow_tracking_disabled( $allowed ) {
		if ( $allowed ) {
			return $allowed;
		}

		if ( $this->get_settings()->get()['useSnippet'] ) {
			return true;
		}

		return $allowed;
	}
}
