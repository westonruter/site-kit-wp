<?php
/**
 * First_Party_Mode_SettingsTest
 *
 * @package   Google\Site_Kit\Tests\Core\Tags\First_Party_Mode
 * @copyright 2024 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Tests\Core\Tags\First_Party_Mode;

use Google\Site_Kit\Context;
use Google\Site_Kit\Core\Storage\Options;
use Google\Site_Kit\Core\Tags\First_Party_Mode\First_Party_Mode_Settings;
use Google\Site_Kit\Tests\Modules\SettingsTestCase;

class First_Party_Mode_SettingsTest extends SettingsTestCase {

	/**
	 * First Party Mode Settings instance.
	 *
	 * @var First_Party_Mode_Settings
	 */
	private $settings;

	/**
	 * Context instance.
	 *
	 * @var Context
	 */
	private $context;

	public function set_up() {
		parent::set_up();

		$this->context  = new Context( GOOGLESITEKIT_PLUGIN_MAIN_FILE );
		$options        = new Options( $this->context );
		$this->settings = new First_Party_Mode_Settings( $options );
		$this->settings->register();
	}

	protected function get_option_name() {
		return First_Party_Mode_Settings::OPTION;
	}

	public function test_get_default() {
		$default_settings = get_option( $this->get_option_name() );
		$this->assertEqualSetsWithIndex(
			array(
				'isEnabled'             => null,
				'isFPMHealthy'          => null,
				'isScriptAccessEnabled' => null,
			),
			$default_settings
		);
	}

	public function data_first_party_mode_settings() {
		return array(
			'all properties false'  => array(
				array(
					'isEnabled'             => false,
					'isFPMHealthy'          => false,
					'isScriptAccessEnabled' => false,
				),
				array(
					'isEnabled'             => false,
					'isFPMHealthy'          => false,
					'isScriptAccessEnabled' => false,
				),
			),
			'empty settings'        => array(
				array(),
				array(
					'isEnabled'             => null,
					'isFPMHealthy'          => null,
					'isScriptAccessEnabled' => null,
				),
			),
			'all properties true'   => array(
				array(
					'isEnabled'             => true,
					'isFPMHealthy'          => true,
					'isScriptAccessEnabled' => true,
				),
				array(
					'isEnabled'             => true,
					'isFPMHealthy'          => true,
					'isScriptAccessEnabled' => true,
				),
			),
			'only isEnabled false'  => array(
				array(
					'isEnabled' => false,
				),
				array(
					'isEnabled'             => false,
					'isFPMHealthy'          => null,
					'isScriptAccessEnabled' => null,
				),
			),
			'only isEnabled true'   => array(
				array(
					'isEnabled' => true,
				),
				array(
					'isEnabled'             => true,
					'isFPMHealthy'          => null,
					'isScriptAccessEnabled' => null,
				),
			),
			'isEnabled non-boolean' => array(
				array(
					'isEnabled' => 123,
				),
				array(
					'isEnabled'             => true,
					'isFPMHealthy'          => null,
					'isScriptAccessEnabled' => null,
				),
			),
		);
	}

	/**
	 * @dataProvider data_first_party_mode_settings
	 *
	 * @param mixed $input    Values to pass to the `set()` method.
	 * @param array $expected The expected sanitized array.
	 */
	public function test_get_sanitize_callback( $input, $expected ) {
		$this->settings->set( $input );
		$this->assertEqualSetsWithIndex( $expected, $this->settings->get() );
	}
}
