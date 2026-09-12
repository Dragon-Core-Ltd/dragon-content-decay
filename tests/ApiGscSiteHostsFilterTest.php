<?php
/**
 * The dragoncontentdecay_gsc_site_hosts filter widens which hosts a sync keeps.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\API_GSC;
use DragonContentDecay\OAuth;
use PHPUnit\Framework\TestCase;

final class ApiGscSiteHostsFilterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['dragoncontentdecay_test_options'] = array( 'dragoncontentdecay_gsc_property' => 'sc-domain:example.test' );
		remove_all_filters( 'dragoncontentdecay_gsc_site_hosts' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'dragoncontentdecay_gsc_site_hosts' );
		$GLOBALS['dragoncontentdecay_test_options'] = array();
	}

	private function api(): API_GSC {
		$oauth = new class() extends OAuth {
			public function get_access_token(): ?string {
				return 'token';
			}
		};

		$transport = static function ( string $method, string $url, array $body, string $token ): array {
			unset( $method, $url, $body, $token );
			return array(
				'code' => 200,
				'body' => wp_json_encode(
					array(
						'rows' => array(
							array(
								'keys'        => array( 'https://www.example.test/a/' ),
								'clicks'      => 1,
								'impressions' => 10,
								'position'    => 1.0,
							),
							array(
								'keys'        => array( 'https://property.example/a/' ),
								'clicks'      => 2,
								'impressions' => 20,
								'position'    => 1.0,
							),
						),
					)
				),
			);
		};

		return new API_GSC( $oauth, $transport );
	}

	public function test_default_accepted_hosts_is_the_site_host(): void {
		$this->assertSame( array( 'example.test' ), API_GSC::site_hosts() );
	}

	public function test_filter_adds_a_property_host_that_differs_from_the_site(): void {
		$seen = null;
		add_filter(
			'dragoncontentdecay_gsc_site_hosts',
			static function ( array $hosts ) use ( &$seen ): array {
				$seen    = $hosts;
				$hosts[] = 'property.example';
				return $hosts;
			}
		);

		$data = $this->api()->fetch_comparison_data( 30 );

		$this->assertSame( array( 'example.test' ), $seen );
		$this->assertSame( 3, $data['current']['/a']['clicks'] );
	}

	public function test_without_the_filter_only_the_site_host_is_kept(): void {
		$data = $this->api()->fetch_comparison_data( 30 );

		$this->assertSame( 1, $data['current']['/a']['clicks'] );
	}

	public function test_the_configured_property_host_is_accepted_without_a_filter(): void {
		// A domain property for a host that is not home_url() is normal after a
		// domain move, or on a headless or proxied front end. Accepting only
		// home_url() drops every row and the Search columns silently read zero,
		// where the previous release returned data. No existing install would
		// know to add the filter.
		$GLOBALS['dragoncontentdecay_test_options']['dragoncontentdecay_gsc_property'] = 'sc-domain:old-domain.test';

		$this->assertContains( 'old-domain.test', API_GSC::site_hosts() );
	}

	public function test_a_url_prefix_property_on_another_host_is_accepted(): void {
		$GLOBALS['dragoncontentdecay_test_options']['dragoncontentdecay_gsc_property'] = 'https://www.other-host.test/shop/';

		$this->assertContains( 'other-host.test', API_GSC::site_hosts() );
	}

	public function test_the_home_host_is_still_accepted(): void {
		$GLOBALS['dragoncontentdecay_test_options']['dragoncontentdecay_gsc_property'] = 'sc-domain:old-domain.test';

		$hosts = API_GSC::site_hosts();

		$this->assertContains( 'example.test', $hosts, 'home_url() is always accepted.' );
	}
}
