<?php
/**
 * Shared fakes for Analyzer tests: an OAuth that never talks to Google, a GA4
 * API fed canned rows, and a $wpdb double that records replace() calls.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Analyzer;
use DragonContentDecay\API_GA4;
use DragonContentDecay\API_GSC;
use DragonContentDecay\OAuth;

final class AnalyzerTestSupport {

	public static function oauth(): OAuth {
		return new class() extends OAuth {
			public function get_access_token(): ?string {
				return null;
			}
		};
	}

	/**
	 * @param array             $comparison Canned ['current' => ..., 'previous' => ...] rows.
	 * @param array<string,int> $resolve    Path key => post ID answers for path_to_post_id().
	 */
	public static function ga4( array $comparison = array(), array $resolve = array() ): API_GA4 {
		return new class( self::oauth(), $comparison, $resolve ) extends API_GA4 {
			private array $canned;
			private array $resolve;
			public array $resolved = array();

			public function __construct( OAuth $oauth, array $canned, array $resolve ) {
				parent::__construct( $oauth );
				$this->canned  = $canned;
				$this->resolve = $resolve;
			}

			public function fetch_comparison_data( int $period_days = 30 ): array {
				unset( $period_days );
				return $this->canned + array(
					'current'  => array(),
					'previous' => array(),
				);
			}

			public function path_to_post_id( string $path ): ?int {
				$this->resolved[] = $path;
				return $this->resolve[ Analyzer::path_key( $path ) ] ?? null;
			}
		};
	}

	public static function analyzer( array $comparison = array(), array $resolve = array() ): Analyzer {
		return new Analyzer( self::ga4( $comparison, $resolve ), new API_GSC( self::oauth() ) );
	}

	/**
	 * @param array<int,bool> $replace_results post_id => whether replace() succeeds.
	 * @param array<string,int|array> $slugs post_name => ID rows returned by get_results();
	 *                                       an array value may set 'ID', 'type' and 'parent'
	 *                                       (a post without a parent by default).
	 */
	public static function wpdb( array $replace_results, array $slugs = array() ): object {
		return new class( $replace_results, $slugs ) {
			public string $prefix = 'wp_';
			public string $posts  = 'wp_posts';
			public string $options = 'wp_options';
			public array $replaced = array();
			public array $rows     = array();
			public array $queries  = array();
			/** @var int|false */
			public $query_result = 1;
			/** @var int|false|null Result of a DELETE ... post_id IN query; null for the number of rows removed. */
			public $delete_result = null;
			/** @var int[] Post IDs whose score rows a DELETE ... post_id IN query removed. */
			public array $deleted = array();
			private array $last_args = array();
			private array $results;
			private array $slugs;

			public function __construct( array $results, array $slugs ) {
				$this->results = $results;
				$this->slugs   = $slugs;
			}

			public function prepare( string $query, ...$args ): string {
				$this->last_args = 1 === count( $args ) && is_array( $args[0] ) ? $args[0] : $args;
				return $query;
			}

			public function get_results( string $query ) {
				unset( $query );
				$rows = array();
				foreach ( $this->slugs as $slug => $post ) {
					$post   = is_array( $post ) ? $post : array( 'ID' => $post );
					$rows[] = (object) array(
						'ID'          => $post['ID'],
						'post_name'   => $slug,
						'post_type'   => $post['type'] ?? 'post',
						'post_parent' => $post['parent'] ?? 0,
					);
				}
				return $rows;
			}

			// The post IDs of the stored score rows. A read, so not recorded in $queries.
			public function get_col( string $query ) {
				unset( $query );
				return array_map( 'strval', array_keys( $this->rows ) );
			}

			public function query( string $query ) {
				$this->queries[] = $query;
				if ( str_contains( $query, "DELETE FROM {$this->prefix}dcd_scores WHERE post_id IN" ) ) {
					if ( false === $this->delete_result ) {
						return false;
					}
					foreach ( $this->last_args as $id ) {
						unset( $this->rows[ (int) $id ] );
						$this->deleted[] = (int) $id;
					}
					return count( $this->last_args );
				}
				return $this->query_result;
			}

			public function replace( string $table, array $data, $format = null ) {
				unset( $table, $format );
				$this->replaced[]            = $data['post_id'];
				$this->rows[ $data['post_id'] ] = $data;
				return $this->results[ $data['post_id'] ] ?? 1;
			}
		};
	}
}
