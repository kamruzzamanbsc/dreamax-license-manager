<?php
/**
 * Defines the ContractData class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Contracts\Commercial\V1;

/**
 * Provides immutable, allowlisted data for non-secret contract messages.
 */
abstract class ContractData {
	/**
	 * Allowlisted contract data.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Creates an immutable contract value.
	 *
	 * @param array<string,mixed> $data Contract data.
	 * @throws ContractException When fields or values violate the contract.
	 */
	final public function __construct( array $data ) {
		$fields  = static::fields();
		$unknown = array_diff( array_keys( $data ), array_keys( $fields ) );
		if ( array() !== $unknown ) {
			throw new ContractException( 'The contract data contains an unknown field.' );
		}
		foreach ( $fields as $field => $required ) {
			if ( $required && ! array_key_exists( $field, $data ) ) {
				throw new ContractException( 'The contract data is missing a required field.' );
			}
		}
		$this->assert_values( $data, 0 );
		$this->data = $data;
	}

	/**
	 * Returns the fields accepted by the concrete value.
	 *
	 * @return array<string,bool> Field name to required state.
	 */
	abstract protected static function fields(): array;

	/**
	 * Returns one field or null when an optional field is absent.
	 *
	 * @param string $field Field name.
	 * @return mixed
	 */
	final public function get( string $field ) {
		if ( ! array_key_exists( $field, $this->data ) ) {
			return null;
		}
		return $this->data[ $field ];
	}

	/**
	 * Returns a recursively normalized, non-secret representation.
	 *
	 * @return array<string,mixed>
	 */
	final public function to_array(): array {
		return $this->normalize( $this->data );
	}

	/**
	 * Returns a safe debug representation.
	 *
	 * @return array<string,mixed>
	 */
	final public function __debugInfo(): array {
		return $this->to_array();
	}

	/**
	 * Validates nested contract values.
	 *
	 * @param array<mixed> $values Values to validate.
	 * @param int          $depth Current nesting depth.
	 * @throws ContractException When a value is secret-bearing or unsupported.
	 */
	private function assert_values( array $values, int $depth ): void {
		if ( $depth > 8 ) {
			throw new ContractException( 'The contract data exceeds the nesting limit.' );
		}
		$forbidden = array( 'license_key', 'secret', 'ciphertext', 'private_key', 'filesystem_path', 'storage_credential' );
		foreach ( $values as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), $forbidden, true ) ) {
				throw new ContractException( 'Secret-bearing fields are not allowed in this contract value.' );
			}
			if ( is_array( $value ) ) {
				$this->assert_values( $value, $depth + 1 );
				continue;
			}
			if ( ! is_scalar( $value ) && null !== $value && ! $value instanceof self ) {
				throw new ContractException( 'The contract data contains an unsupported value.' );
			}
		}
	}

	/**
	 * Normalizes nested contract values to arrays.
	 *
	 * @param array<mixed> $values Values to normalize.
	 * @return array<mixed>
	 */
	private function normalize( array $values ): array {
		foreach ( $values as $key => $value ) {
			if ( $value instanceof self ) {
				$values[ $key ] = $value->to_array();
			} elseif ( is_array( $value ) ) {
				$values[ $key ] = $this->normalize( $value );
			}
		}
		return $values;
	}
}
