<?php
/**
 * The schema version is stamped only once the scores table and its newest
 * column exist, so a failed CREATE or ALTER is retried on the next load.
 *
 * @package DragonContentDecay
 */

use DragonContentDecay\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/wporg-scan-stubs.php';
require_once __DIR__ . '/../includes/class-plugin.php';

final class SchemaVersionTest extends TestCase {

	protected function setUp(): void {
		dragoncontentdecay_test_reset();
		$GLOBALS['dragoncontentdecay_test_dbdelta'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	/**
	 * A $wpdb double whose SHOW TABLES / SHOW COLUMNS answers are given.
	 *
	 * @param string|null $table  Table name SHOW TABLES returns, or null.
	 * @param string|null $column Column name SHOW COLUMNS returns, or null.
	 */
	private function wpdb( ?string $table, ?string $column ): object {
		return new class( $table, $column ) {
			public string $prefix = 'wp_';
			public array $queries = array();
			private ?string $table;
			private ?string $column;

			public function __construct( ?string $table, ?string $column ) {
				$this->table  = $table;
				$this->column = $column;
			}

			public function get_charset_collate(): string {
				return 'DEFAULT CHARACTER SET utf8mb4';
			}

			public function esc_like( string $text ): string {
				return addcslashes( $text, '_%\\' );
			}

			public function prepare( string $query, ...$args ): string {
				foreach ( $args as $arg ) {
					$query = preg_replace( '/%s/', "'" . $arg . "'", $query, 1 );
				}
				return $query;
			}

			public function get_var( string $query ) {
				$this->queries[] = $query;
				if ( str_starts_with( $query, 'SHOW TABLES' ) ) {
					return $this->table;
				}
				if ( str_starts_with( $query, 'SHOW COLUMNS' ) ) {
					return $this->column;
				}
				return null;
			}
		};
	}

	private function create_tables(): void {
		$method = new ReflectionMethod( Plugin::class, 'create_tables' );
		$method->invoke( null );
	}

	public function test_a_table_with_its_newest_column_is_stamped(): void {
		$GLOBALS['wpdb'] = $this->wpdb( 'wp_dcd_scores', 'search_position' );

		$this->create_tables();

		$this->assertCount( 1, $GLOBALS['dragoncontentdecay_test_dbdelta'] );
		$this->assertSame( DRAGONCONTENTDECAY_VERSION, get_option( 'dragoncontentdecay_db_version' ) );
		$this->assertStringContainsString( "SHOW TABLES LIKE 'wp\\_dcd\\_scores'", $GLOBALS['wpdb']->queries[0] );
	}

	public function test_a_table_name_reported_in_lower_case_still_counts(): void {
		$GLOBALS['wpdb']         = $this->wpdb( 'wp_dcd_scores', 'search_position' );
		$GLOBALS['wpdb']->prefix = 'WP_';

		$this->create_tables();

		$this->assertSame( DRAGONCONTENTDECAY_VERSION, get_option( 'dragoncontentdecay_db_version' ) );
	}

	public function test_a_missing_table_is_not_stamped(): void {
		$GLOBALS['wpdb'] = $this->wpdb( null, null );
		update_option( 'dragoncontentdecay_db_version', '1.0.9' );

		$this->create_tables();

		$this->assertSame( '1.0.9', get_option( 'dragoncontentdecay_db_version' ) );
	}

	public function test_a_table_without_the_newest_column_is_not_stamped(): void {
		$GLOBALS['wpdb'] = $this->wpdb( 'wp_dcd_scores', null );
		update_option( 'dragoncontentdecay_db_version', '1.0.9' );

		$this->create_tables();

		$this->assertSame( '1.0.9', get_option( 'dragoncontentdecay_db_version' ) );
	}
}
