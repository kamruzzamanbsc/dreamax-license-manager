<?php

declare(strict_types=1);

namespace Dreamax\LicenseManager\Api;

final class SourceAddress {
	public function resolve(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return '0.0.0.0';
		}

		$trusted = get_option( 'dreamax_lm_trusted_proxies', array() );
		if ( ! is_array( $trusted ) || ! in_array( $remote, $trusted, true ) ) {
			return $remote;
		}

		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) : array();
		$chain     = array_map( 'trim', $forwarded );
		$chain[]   = $remote;
		for ( $i = count( $chain ) - 1; $i >= 0; --$i ) {
			$address = $chain[ $i ];
			if ( ! filter_var( $address, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( ! in_array( $address, $trusted, true ) ) {
				return $address;
			}
		}
		return $remote;
	}

	public function network(): string {
		$address = $this->resolve();
		if ( false !== filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $address );
			return $parts[0] . '.' . $parts[1] . '.' . $parts[2] . '.0/24';
		}
		$packed = inet_pton( $address );
		if ( false === $packed ) {
			return 'unknown';
		}
		return bin2hex( substr( $packed, 0, 7 ) ) . '00/56';
	}

	public function remote_is_trusted_proxy(): bool {
		$remote  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$trusted = get_option( 'dreamax_lm_trusted_proxies', array() );
		return is_array( $trusted ) && in_array( $remote, $trusted, true );
	}
}
