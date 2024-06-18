<?php
/**
 * SubscribewithGoogle Test.
 *
 * @package   Google\Site_Kit\Tests\Modules
 * @copyright 2024 Google LLC
 * @license   https://www.apache.org/licenses/LICENSE-2.0 Apache License 2.0
 * @link      https://sitekit.withgoogle.com
 */

namespace Google\Site_Kit\Tests\Modules;

use Google\Site_Kit\Tests\TestCase;

/**
 * @group Modules
 */
class Subscribe_With_Google extends TestCase {
	public function test_service_classes_exist() {
		$this->assertTrue(
			class_exists( 'Google\Service\SubscribewithGoogle' )
		);
	}
}
