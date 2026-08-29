<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Tests\Unit;

use Dreamax\LicenseManager\Events\AuditEventCatalog;
use Dreamax\LicenseManager\Support\PublicId;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AuditEventCatalogTest extends TestCase {
	public function test_event_ids_are_opaque_stable_shape_and_unique(): void {
		$first  = PublicId::generate( 'evt' );
		$second = PublicId::generate( 'evt' );
		self::assertMatchesRegularExpression( '/^evt_[A-Za-z0-9_-]{22}$/D', $first );
		self::assertNotSame( $first, $second );
	}

	public function test_every_catalog_contract_accepts_a_complete_version_one_payload(): void {
		$contracts = AuditEventCatalog::contracts();
		self::assertCount( 48, $contracts );
		foreach ( $contracts as $event_type => $versions ) {
			self::assertSame( array( AuditEventCatalog::SCHEMA_V1 ), array_keys( $versions ), $event_type );
			$contract = $versions[ AuditEventCatalog::SCHEMA_V1 ];
			if ( false === $contract['persistable'] ) {
				self::assertIsString( $contract['legacy_reason'] );
				self::assertNotSame( '', $contract['legacy_reason'] );
				continue;
			}
			$metadata = $this->metadata( $contract );
			self::assertSame(
				$metadata,
				AuditEventCatalog::validate(
					$event_type,
					AuditEventCatalog::SCHEMA_V1,
					'required' === $contract['license_reference'] ? 5 : null,
					$contract['actors'][0],
					'required' === $contract['actor_reference'] ? 7 : null,
					'required' === $contract['request_reference'] ? 'req_AAAAAAAAAAAAAAAAAAAAAA' : null,
					$metadata
				),
				$event_type
			);
		}
	}

	public function test_every_production_emitter_uses_the_catalog_and_explicit_schema_version(): void {
		$root  = dirname( __DIR__, 2 );
		$calls = array();
		foreach ( $this->production_php( $root . '/src' ) as $file ) {
			$calls = array_merge( $calls, $this->append_calls( (string) file_get_contents( $file ) ) );
		}
		self::assertCount( 44, $calls );
		foreach ( $calls as $call ) {
			self::assertStringContainsString( 'AuditEventCatalog::', $call );
			self::assertStringContainsString( 'AuditEventCatalog::SCHEMA_V1', $call );
		}
	}

	public function test_every_catalog_entry_has_a_real_emitter_and_no_undocumented_orphan(): void {
		$root      = dirname( __DIR__, 2 );
		$constants = ( new ReflectionClass( AuditEventCatalog::class ) )->getConstants();
		foreach ( AuditEventCatalog::contracts() as $event_type => $versions ) {
			$contract = $versions[ AuditEventCatalog::SCHEMA_V1 ];
			if ( false === $contract['persistable'] ) {
				self::assertSame( array(), $contract['emitters'], $event_type );
				self::assertIsString( $contract['legacy_reason'], $event_type );
				continue;
			}
			self::assertNotEmpty( $contract['emitters'], $event_type );
			$constant = array_search( $event_type, $constants, true );
			self::assertIsString( $constant, $event_type );
			$source = '';
			foreach ( $contract['emitters'] as $relative ) {
				self::assertFileExists( $root . '/' . $relative );
				$source .= (string) file_get_contents( $root . '/' . $relative );
			}
			$guest_selector = in_array( $event_type, array( AuditEventCatalog::GUEST_CLAIM_REPLAY_FAILED, AuditEventCatalog::GUEST_CLAIM_EXPIRED_FAILED, AuditEventCatalog::GUEST_CLAIM_CONFLICT_FAILED ), true )
				&& str_contains( $source, 'AuditEventCatalog::guest_claim_failure' );
			self::assertTrue( $guest_selector || str_contains( $source, 'AuditEventCatalog::' . $constant ), $event_type );
		}
	}

	public function test_f25_and_f31_published_event_sets_are_complete(): void {
		$types = array_keys( AuditEventCatalog::contracts() );
		$f25   = array(
			AuditEventCatalog::GUEST_CLAIM_ISSUED,
			AuditEventCatalog::GUEST_CLAIM_SUCCEEDED,
			AuditEventCatalog::GUEST_CLAIM_REPLAY_FAILED,
			AuditEventCatalog::GUEST_CLAIM_EXPIRED_FAILED,
			AuditEventCatalog::GUEST_CLAIM_CONFLICT_FAILED,
			AuditEventCatalog::GUEST_CLAIM_RELEASED,
			AuditEventCatalog::GUEST_CLAIM_ADMINISTRATOR_OVERRIDE,
		);
		$f31   = array(
			AuditEventCatalog::CREDENTIAL_CREATED,
			AuditEventCatalog::CREDENTIAL_AUTHENTICATION_USED,
			AuditEventCatalog::CREDENTIAL_ROTATION_SUCCEEDED,
			AuditEventCatalog::CREDENTIAL_ROTATION_FAILED,
			AuditEventCatalog::CREDENTIAL_REVOKED,
			AuditEventCatalog::CREDENTIAL_EXPIRED_AUTHENTICATION_FAILED,
			AuditEventCatalog::CREDENTIAL_INSUFFICIENT_SCOPE,
		);
		self::assertSame( array(), array_diff( array_merge( $f25, $f31 ), $types ) );
	}

	public function test_existing_consumer_fixtures_remain_valid_and_select_type_plus_version(): void {
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/audit-events-v1.json' ), true );
		self::assertIsArray( $fixture );
		foreach ( $fixture as $row ) {
			self::assertSame(
				$row['metadata'],
				AuditEventCatalog::validate( $row['event_type'], $row['schema_version'], $row['license_id'], $row['actor_type'], $row['actor_id'], $row['request_id'], $row['metadata'] )
			);
			$row['public_id']  = 'evt_AAAAAAAAAAAAAAAAAAAAAA';
			$row['occurred_at'] = '2030-01-02 03:04:05';
			$row['metadata']    = json_encode( $row['metadata'] );
			self::assertSame( 'supported', AuditEventCatalog::compatibility_status( $row ) );
		}
	}

	/**
	 * @dataProvider invalidVersionProvider
	 */
	public function test_missing_zero_negative_unknown_and_unsupported_versions_are_safe( string $type, int $version ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'The audit event does not satisfy its published contract.' );
		AuditEventCatalog::validate( $type, $version, 5, 'system', null, null, array() );
	}

	/** @return iterable<string,array{string,int}> */
	public static function invalidVersionProvider(): iterable {
		yield 'missing represented as zero' => array( AuditEventCatalog::LICENSE_CREATED, 0 );
		yield 'negative' => array( AuditEventCatalog::LICENSE_CREATED, -1 );
		yield 'unsupported future' => array( AuditEventCatalog::LICENSE_CREATED, 2 );
		yield 'unknown type' => array( 'unknown_event', 1 );
	}

	public function test_required_metadata_actor_and_references_are_enforced(): void {
		foreach (
			array(
				array( array(), 'administrator', 1, null ),
				array( array( 'full_keys' => false, 'row_count' => 0 ), 'unknown_actor', 1, null ),
				array( array( 'full_keys' => false, 'row_count' => 0 ), 'administrator', null, null ),
			) as $case
		) {
			try {
				AuditEventCatalog::validate( AuditEventCatalog::LICENSE_EXPORTED, 1, null, $case[1], $case[2], $case[3], $case[0] );
				self::fail( 'Invalid contract input was accepted.' );
			} catch ( InvalidArgumentException $error ) {
				self::assertSame( 'The audit event does not satisfy its published contract.', $error->getMessage() );
			}
		}
	}

	public function test_documented_additive_optional_metadata_is_compatible_but_unknown_or_incompatible_fields_fail(): void {
		$base = array( 'source' => 'generated', 'public_id' => 'lic_AAAAAAAAAAAAAAAAAAAAAA' );
		self::assertArrayHasKey(
			'normalization_profile',
			AuditEventCatalog::validate( AuditEventCatalog::LICENSE_CREATED, 1, 5, 'system', null, null, $base + array( 'normalization_profile' => 'generated-ascii-v1' ) )
		);
		foreach ( array( $base + array( 'undocumented' => true ), array( 'source' => 'generated', 'public_id' => 5 ) ) as $invalid ) {
			try {
				AuditEventCatalog::validate( AuditEventCatalog::LICENSE_CREATED, 1, 5, 'system', null, null, $invalid );
				self::fail( 'An incompatible v1 payload was accepted.' );
			} catch ( InvalidArgumentException $error ) {
				self::assertSame( 'The audit event does not satisfy its published contract.', $error->getMessage() );
			}
		}
	}

	public function test_legacy_catalog_names_remain_readable_but_cannot_accept_new_writes(): void {
		$this->expectException( InvalidArgumentException::class );
		AuditEventCatalog::validate( AuditEventCatalog::LEGACY_LICENSE_IMPORTED, 1, 5, 'administrator', 1, null, array() );
	}

	public function test_legacy_unknown_and_unsupported_rows_remain_classifiable_without_rewrite(): void {
		$base = array(
			'public_id'   => 'evt_AAAAAAAAAAAAAAAAAAAAAA',
			'event_type'  => AuditEventCatalog::LICENSE_CREATED,
			'actor_type'  => 'system',
			'actor_id'    => null,
			'license_id'  => 5,
			'request_id'  => null,
			'occurred_at' => '2030-01-02 03:04:05',
			'metadata'    => '{}',
		);
		self::assertSame( 'legacy_unversioned', AuditEventCatalog::compatibility_status( $base ) );
		self::assertSame( 'legacy_unversioned', AuditEventCatalog::compatibility_status( $base + array( 'schema_version' => 0 ) ) );
		self::assertSame( 'unsupported_version', AuditEventCatalog::compatibility_status( array_replace( $base, array( 'schema_version' => 2 ) ) ) );
		self::assertSame( 'unknown_type', AuditEventCatalog::compatibility_status( array_replace( $base, array( 'event_type' => 'legacy_private_event', 'schema_version' => 1 ) ) ) );
		self::assertSame( 'legacy_catalog_entry', AuditEventCatalog::compatibility_status( array_replace( $base, array( 'event_type' => AuditEventCatalog::LEGACY_LICENSE_IMPORTED, 'schema_version' => 1 ) ) ) );
		self::assertSame( 'legacy_payload', AuditEventCatalog::compatibility_status( array_replace( $base, array( 'schema_version' => 1 ) ) ) );
	}

	public function test_repository_enforces_opaque_ids_utc_versioned_reads_and_site_local_tables(): void {
		$root       = dirname( __DIR__, 2 );
		$repository = (string) file_get_contents( $root . '/src/Events/class-eventrepository.php' );
		$schema     = (string) file_get_contents( $root . '/src/Database/class-schema.php' );
		$privacy    = (string) file_get_contents( $root . '/src/Privacy/class-privacy.php' );
		$uninstall  = (string) file_get_contents( $root . '/uninstall.php' );
		self::assertStringContainsString( "PublicId::generate( 'evt' )", $repository );
		self::assertStringContainsString( "gmdate( 'Y-m-d H:i:s' )", $repository );
		self::assertStringContainsString( 'AuditEventCatalog::validate', $repository );
		self::assertStringContainsString( 'schema_version', $repository );
		self::assertStringContainsString( 'contract_status', $repository );
		self::assertStringContainsString( '$wpdb->prefix . \'dreamax_lm_events\'', $repository );
		self::assertStringContainsString( 'schema_version smallint(5) unsigned NOT NULL DEFAULT 1', $schema );
		self::assertStringContainsString( 'PRIVACY_DATA_ANONYMIZED', $privacy );
		self::assertStringContainsString( "'events'", $uninstall );
	}

	/**
	 * Builds a valid payload from the public contract description.
	 *
	 * @param array $contract Contract.
	 * @phpstan-param array<string,mixed> $contract
	 * @return array<string,mixed>
	 */
	private function metadata( array $contract ): array {
		$metadata = array();
		foreach ( $contract['required'] as $field => $type ) {
			$metadata[ $field ] = $this->sample( $type );
		}
		foreach ( $contract['rules'] as $rule ) {
			$field = $rule['one_of'][0];
			if ( ! array_key_exists( $field, $metadata ) ) {
				$metadata[ $field ] = $this->sample( $contract['optional'][ $field ] );
			}
			foreach ( $rule['requires'][ $field ] ?? array() as $required_field ) {
				$metadata[ $required_field ] = $this->sample( $contract['optional'][ $required_field ] );
			}
		}
		return $metadata;
	}

	/** @param mixed $type @return mixed */
	private function sample( $type ) {
		if ( is_array( $type ) ) {
			return $type['enum'][0];
		}
		return array(
			'bool'                  => true,
			'positive-int'          => 1,
			'nullable-positive-int' => null,
			'non-negative-int'      => 0,
			'string-list'           => array( 'value' ),
			'license-public-id'     => 'lic_AAAAAAAAAAAAAAAAAAAAAA',
			'activation-public-id'  => 'act_AAAAAAAAAAAAAAAAAAAAAA',
			'credential-public-id'  => 'AAAAAAAAAAAAAAAAAAAAAA',
			'utc-datetime'          => '2030-01-02 03:04:05',
			'nullable-utc-datetime' => null,
			'reason'                => 'Confirmed action',
			'non-empty-string'      => 'value',
			'license-snapshot'      => array(
				'customer_id'       => null,
				'order_id'          => null,
				'product_public_id' => 'prd_AAAAAAAAAAAAAAAAAAAAAA',
				'lifecycle_status'  => 'assigned',
			),
		)[ $type ];
	}

	/** @return list<string> */
	private function production_php( string $directory ): array {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ) );
		$files    = array();
		foreach ( $iterator as $item ) {
			if ( $item instanceof \SplFileInfo && 'php' === strtolower( $item->getExtension() ) ) {
				$files[] = $item->getPathname();
			}
		}
		return $files;
	}

	/** @return list<string> */
	private function append_calls( string $source ): array {
		$calls  = array();
		$offset = 0;
		$needle = '->append(';
		while ( false !== ( $start = strpos( $source, $needle, $offset ) ) ) {
			$index = $start + strlen( $needle );
			$depth = 1;
			$quote = '';
			for ( $length = strlen( $source ); $index < $length; ++$index ) {
				$character = $source[ $index ];
				if ( '' !== $quote ) {
					if ( '\\' === $character ) {
						++$index;
					} elseif ( $quote === $character ) {
						$quote = '';
					}
					continue;
				}
				if ( "'" === $character || '"' === $character ) {
					$quote = $character;
				} elseif ( '(' === $character ) {
					++$depth;
				} elseif ( ')' === $character && 0 === --$depth ) {
					$calls[] = substr( $source, $start, $index - $start + 1 );
					$offset  = $index + 1;
					break;
				}
			}
		}
		return $calls;
	}
}
