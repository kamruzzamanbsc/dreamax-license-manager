<?php
/**
 * Defines the Schema class.
 *
 * @package DreamaxLicenseManager
 */

declare(strict_types=1);

namespace Dreamax\LicenseManager\Database;

/**
 * Handles Schema operations.
 */
final class Schema {
	public const VERSION = '1';

	/**
	 * Handles the install operation.
	 */
	public function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$prefix  = $wpdb->prefix . 'dreamax_lm_';

		$sql = array(
			"CREATE TABLE {$prefix}licenses (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(64) NOT NULL,
				key_ciphertext longtext NOT NULL,
				key_fingerprint binary(32) NOT NULL,
				normalization_profile varchar(32) NOT NULL,
				normalization_separator char(1) NULL,
				encryption_key_version smallint(5) unsigned NOT NULL DEFAULT 1,
				lifecycle_status varchar(16) NOT NULL DEFAULT 'available',
				product_public_id varchar(64) NULL,
				product_id bigint(20) unsigned NULL,
				variation_id bigint(20) unsigned NULL,
				order_id bigint(20) unsigned NULL,
				order_item_id bigint(20) unsigned NULL,
				quantity_slot int(10) unsigned NULL,
				customer_id bigint(20) unsigned NULL,
				customer_email_enc longtext NULL,
				generator_id bigint(20) unsigned NULL,
				activation_limit int(10) unsigned NULL,
				valid_for_seconds bigint(20) unsigned NULL,
				expires_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				metadata longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY key_fingerprint (key_fingerprint),
				UNIQUE KEY order_slot (order_item_id,quantity_slot),
				KEY customer_status (customer_id,lifecycle_status),
				KEY product_status (product_public_id,lifecycle_status),
				KEY expires_at (expires_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}activations (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(64) NOT NULL,
				license_id bigint(20) unsigned NOT NULL,
				instance_fingerprint binary(32) NOT NULL,
				instance_label varchar(255) NULL,
				status varchar(8) NOT NULL,
				first_activated_at datetime NOT NULL,
				activated_at datetime NOT NULL,
				deactivated_at datetime NULL,
				last_seen_at datetime NULL,
				updated_at datetime NOT NULL,
				ip_fingerprint binary(32) NULL,
				metadata longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				UNIQUE KEY license_instance (license_id,instance_fingerprint),
				KEY license_status (license_id,status),
				KEY last_seen_at (last_seen_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}events (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(64) NOT NULL,
				license_id bigint(20) unsigned NULL,
				event_type varchar(64) NOT NULL,
				schema_version smallint(5) unsigned NOT NULL DEFAULT 1,
				actor_type varchar(32) NOT NULL,
				actor_id bigint(20) unsigned NULL,
				request_id varchar(64) NULL,
				occurred_at datetime NOT NULL,
				metadata longtext NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY license_time (license_id,occurred_at),
				KEY event_time (event_type,occurred_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}generators (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(64) NOT NULL,
				name varchar(191) NOT NULL,
				configuration longtext NOT NULL,
				valid_for_seconds bigint(20) unsigned NULL,
				default_activation_limit int(10) unsigned NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}api_credentials (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				public_id varchar(64) NOT NULL,
				name varchar(191) NOT NULL,
				visible_prefix varchar(24) NOT NULL,
				secret_hash varchar(255) NOT NULL,
				scopes longtext NOT NULL,
				status varchar(16) NOT NULL,
				expires_at datetime NULL,
				last_used_at datetime NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY public_id (public_id),
				KEY status_expiry (status,expires_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}idempotency (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scope_hash binary(32) NOT NULL,
				payload_digest binary(32) NOT NULL,
				api_version varchar(16) NOT NULL,
				operation varchar(16) NOT NULL,
				state varchar(16) NOT NULL,
				credential_id bigint(20) unsigned NULL,
				http_status smallint(5) unsigned NULL,
				result_code varchar(64) NULL,
				result_public_id varchar(64) NULL,
				result_metadata longtext NULL,
				created_at datetime NOT NULL,
				completed_at datetime NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY scope_hash (scope_hash),
				KEY expires_at (expires_at)
			) ENGINE=InnoDB {$charset};",
			"CREATE TABLE {$prefix}rate_limits (
				bucket_hash binary(32) NOT NULL,
				tokens decimal(12,6) NOT NULL,
				updated_microtime decimal(20,6) NOT NULL,
				expires_at datetime NOT NULL,
				PRIMARY KEY  (bucket_hash),
				KEY expires_at (expires_at)
			) ENGINE=InnoDB {$charset};",
		);

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
