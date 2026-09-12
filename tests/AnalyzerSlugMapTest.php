<?php
/**
 * The batched slug map and the path key must be spelled the same way.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/AnalyzerTestSupport.php';

final class AnalyzerSlugMapTest extends TestCase {

	/**
	 * Call the private slug-map lookup.
	 *
	 * @param string $path     Path as GA4 reported it, already path_key()ed.
	 * @param array  $slug_map Map as built from post_name values.
	 * @return int|null
	 */
	private function resolve( string $path, array $slug_map ): ?int {
		$m = new ReflectionMethod( Analyzer::class, 'resolve_path' );
		$m->setAccessible( true );

		return $m->invoke( AnalyzerTestSupport::analyzer(), $path, $slug_map );
	}

	/**
	 * Build the map exactly as build_slug_map() does, from raw post_name values.
	 *
	 * @param array<string,array<int,int>> $rows post_name => ids.
	 * @return array<string,array<int,int>>
	 */
	private function map( array $rows ): array {
		$map = array();
		foreach ( $rows as $post_name => $ids ) {
			$map[ ltrim( Analyzer::path_key( (string) $post_name ), '/' ) ] = $ids;
		}

		return $map;
	}

	public function test_a_non_latin_slug_still_matches_the_batched_map(): void {
		// path_key() percent-encodes with UPPERCASE hex, while WordPress builds
		// post_name with lowercase hex (utf8_uri_encode uses dechex). Keying the
		// map on the raw post_name therefore missed every non-Latin slug and sent
		// each one to the slow resolver, which is what the map exists to avoid.
		$key = Analyzer::path_key( '/caf%c3%a9/' );

		$this->assertSame( $key, Analyzer::path_key( $key ), 'path_key is idempotent.' );
		$this->assertSame( 41, $this->resolve( $key, $this->map( array( 'caf%c3%a9' => array( 41 ) ) ) ) );
	}

	public function test_an_ascii_slug_is_unaffected(): void {
		$this->assertSame(
			7,
			$this->resolve( Analyzer::path_key( '/hello-world/' ), $this->map( array( 'hello-world' => array( 7 ) ) ) )
		);
	}

	public function test_an_ambiguous_slug_still_falls_through(): void {
		$this->assertNull(
			$this->resolve( Analyzer::path_key( '/dup/' ), $this->map( array( 'dup' => array( 1, 2 ) ) ) ),
			'Two posts share the slug, so the fast path must not guess.'
		);
	}
}
