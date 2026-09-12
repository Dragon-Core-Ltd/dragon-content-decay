<?php
/**
 * Search Console row parsing: host filtering and per-path aggregation.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\API_GSC;
use PHPUnit\Framework\TestCase;

final class ApiGscParseRowsTest extends TestCase {

	private function row( string $page, int $clicks, int $impressions, float $position ): array {
		return array(
			'keys'        => array( $page ),
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => $impressions > 0 ? $clicks / $impressions : 0,
			'position'    => $position,
		);
	}

	public function test_www_and_bare_host_rows_are_summed_into_one_path(): void {
		$out = API_GSC::parse_rows(
			array(
				'rows' => array(
					$this->row( 'https://example.com/a/', 10, 100, 4.0 ),
					$this->row( 'https://www.example.com/a/', 5, 50, 10.0 ),
				),
			),
			array( 'example.com' )
		);

		$this->assertSame( array( '/a' ), array_keys( $out ) );
		$this->assertSame( 15, $out['/a']['clicks'] );
		$this->assertSame( 150, $out['/a']['impressions'] );
		// Impression-weighted: (4*100 + 10*50) / 150 = 6.0.
		$this->assertSame( 6.0, $out['/a']['position'] );
	}

	public function test_host_match_is_case_insensitive_and_www_agnostic(): void {
		$out = API_GSC::parse_rows(
			array(
				'rows' => array(
					$this->row( 'HTTPS://WWW.Example.COM/a/', 3, 30, 2.0 ),
					$this->row( 'http://example.com/a/', 4, 40, 2.0 ),
				),
			),
			array( 'www.example.com' )
		);

		$this->assertSame( 7, $out['/a']['clicks'] );
		$this->assertSame( 70, $out['/a']['impressions'] );
	}

	public function test_other_hosts_are_dropped(): void {
		$out = API_GSC::parse_rows(
			array(
				'rows' => array(
					$this->row( 'https://example.com/a/', 10, 100, 4.0 ),
					$this->row( 'https://shop.example.com/a/', 99, 999, 1.0 ),
					$this->row( 'https://other.test/a/', 99, 999, 1.0 ),
				),
			),
			array( 'example.com' )
		);

		$this->assertSame( 10, $out['/a']['clicks'] );
		$this->assertSame( 100, $out['/a']['impressions'] );
	}

	public function test_keys_are_normalised_paths(): void {
		$out = API_GSC::parse_rows(
			array(
				'rows' => array(
					$this->row( 'https://example.com/caf%C3%A9/?utm_source=x#top', 1, 10, 3.0 ),
					$this->row( 'https://example.com/café', 2, 20, 3.0 ),
					$this->row( 'https://example.com/', 5, 50, 1.0 ),
					$this->row( 'https://example.com', 1, 10, 1.0 ),
				),
			),
			array( 'example.com' )
		);

		$this->assertSame( 3, $out['/caf%C3%A9']['clicks'] );
		$this->assertSame( 30, $out['/caf%C3%A9']['impressions'] );
		$this->assertSame( 6, $out['/']['clicks'] );
		$this->assertSame( 60, $out['/']['impressions'] );
	}

	public function test_position_falls_back_to_plain_mean_without_impressions(): void {
		$out = API_GSC::parse_rows(
			array(
				'rows' => array(
					$this->row( 'https://example.com/a/', 0, 0, 4.0 ),
					$this->row( 'https://www.example.com/a', 0, 0, 8.0 ),
				),
			),
			array( 'example.com' )
		);

		$this->assertSame( 6.0, $out['/a']['position'] );
	}

	public function test_multiple_accepted_hosts_are_all_kept(): void {
		$out = API_GSC::parse_rows(
			array(
				'rows' => array(
					$this->row( 'https://example.com/a/', 1, 10, 1.0 ),
					$this->row( 'https://legacy.test/a/', 2, 20, 1.0 ),
					$this->row( 'https://other.test/a/', 4, 40, 1.0 ),
				),
			),
			array( 'example.com', 'Legacy.test' )
		);

		$this->assertSame( 3, $out['/a']['clicks'] );
	}

	public function test_empty_or_malformed_response_yields_nothing(): void {
		$this->assertSame( array(), API_GSC::parse_rows( array(), array( 'example.com' ) ) );
		$this->assertSame( array(), API_GSC::parse_rows( array( 'rows' => 'nope' ), array( 'example.com' ) ) );
		$this->assertSame( array(), API_GSC::parse_rows( array( 'rows' => array( array( 'clicks' => 1 ) ) ), array( 'example.com' ) ) );
	}
}
