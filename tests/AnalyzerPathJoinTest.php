<?php
/**
 * GA4-to-GSC join: both maps are keyed by the same normalised path.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\API_GSC;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnalyzerPathJoinTest extends TestCase {

	public function test_path_key_normalises_slashes_query_fragment_and_encoding(): void {
		$this->assertSame( '/about', Analyzer::path_key( '/about' ) );
		$this->assertSame( '/about', Analyzer::path_key( '/about/' ) );
		$this->assertSame( '/about', Analyzer::path_key( 'about/' ) );
		$this->assertSame( '/about', Analyzer::path_key( '/about/?utm=1#x' ) );
		$this->assertSame( '/caf%C3%A9', Analyzer::path_key( '/caf%C3%A9/' ) );
		$this->assertSame( '/caf%C3%A9', Analyzer::path_key( '/café/' ) );
		$this->assertSame( '/caf%C3%A9', Analyzer::path_key( '/caf%c3%a9' ) );
		$this->assertSame( '/', Analyzer::path_key( '/' ) );
		$this->assertSame( '/', Analyzer::path_key( '' ) );
		$this->assertSame( '/a/b', Analyzer::path_key( '/a/b//' ) );
	}

	#[DataProvider( 'encoded_paths' )]
	public function test_path_key_is_idempotent( string $input ): void {
		$once = Analyzer::path_key( $input );
		$this->assertSame( $once, Analyzer::path_key( $once ), $input );
	}

	public static function encoded_paths(): array {
		return array(
			array( '/offer%3Fvip/' ),
			array( '/x%23y/' ),
			array( '/p%25q' ),
			array( '/a%2Fb' ),
			array( '/café' ),
			array( '/caf%C3%A9/' ),
			array( '/a b/' ),
			array( '/a+b/' ),
			array( '/about/?utm=1#x' ),
			array( '/' ),
			array( '' ),
		);
	}

	public function test_path_key_keeps_reserved_escapes_and_encoded_slash(): void {
		$this->assertSame( '/offer%3Fvip', Analyzer::path_key( '/offer%3Fvip/' ) );
		$this->assertSame( '/x%23y', Analyzer::path_key( '/x%23y/' ) );
		$this->assertSame( '/p%25q', Analyzer::path_key( '/p%25q' ) );
		$this->assertSame( '/a%2Fb', Analyzer::path_key( '/a%2Fb' ) );
		$this->assertNotSame( Analyzer::path_key( '/a/b' ), Analyzer::path_key( '/a%2Fb' ) );
	}

	public function test_build_search_metrics_joins_an_encoded_question_mark_path(): void {
		$gsc = array(
			'current'  => API_GSC::parse_rows(
				array(
					'rows' => array(
						array(
							'keys'        => array( 'https://example.com/offer%3Fvip/' ),
							'clicks'      => 4,
							'impressions' => 40,
							'position'    => 2.0,
						),
					),
				),
				array( 'example.com' )
			),
			'previous' => array(),
		);
		$ga4 = Analyzer::normalize_ga4_map( array( '/offer%3Fvip/' => array( 'pageviews' => 1, 'sessions' => 1, 'avg_time_on_page' => 1.0 ) ) );

		$analyzer = AnalyzerTestSupport::analyzer();
		$method   = new ReflectionMethod( $analyzer, 'build_search_metrics' );
		$metrics  = $method->invoke( $analyzer, array( array_keys( $ga4 )[0] ), $gsc );

		$this->assertIsArray( $metrics );
		$this->assertSame( 4, $metrics['clicks_current'] );
	}

	public function test_path_key_keeps_case(): void {
		$this->assertSame( '/About', Analyzer::path_key( '/About/' ) );
	}

	public function test_ga4_rows_collapsing_onto_one_key_are_summed(): void {
		$map = Analyzer::normalize_ga4_map(
			array(
				'/about'  => array(
					'pageviews'        => 10,
					'sessions'         => 8,
					'avg_time_on_page' => 30.0,
				),
				'/about/' => array(
					'pageviews'        => 5,
					'sessions'         => 2,
					'avg_time_on_page' => 60.0,
				),
				'/other'  => array(
					'pageviews'        => 1,
					'sessions'         => 1,
					'avg_time_on_page' => 5.0,
				),
			)
		);

		$this->assertSame( array( '/about', '/other' ), array_keys( $map ) );
		$this->assertSame( 15, $map['/about']['pageviews'] );
		$this->assertSame( 10, $map['/about']['sessions'] );
		// Session-weighted: (30*8 + 60*2) / 10 = 36.0.
		$this->assertSame( 36.0, $map['/about']['avg_time_on_page'] );
		$this->assertSame( 1, $map['/other']['pageviews'] );
	}

	public function test_ga4_path_without_trailing_slash_joins_gsc_path_with_one(): void {
		$gsc = array(
			'current'  => API_GSC::parse_rows(
				array(
					'rows' => array(
						array(
							'keys'        => array( 'https://example.com/about/' ),
							'clicks'      => 7,
							'impressions' => 70,
							'position'    => 3.2,
						),
					),
				),
				array( 'example.com' )
			),
			'previous' => array(),
		);
		$ga4 = Analyzer::normalize_ga4_map( array( '/about' => array( 'pageviews' => 3, 'sessions' => 3, 'avg_time_on_page' => 1.0 ) ) );

		$path = array_keys( $ga4 )[0];
		$this->assertArrayHasKey( $path, $gsc['current'] );
		$this->assertSame( 7, $gsc['current'][ $path ]['clicks'] );
	}
}
