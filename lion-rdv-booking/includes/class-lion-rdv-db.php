<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Journal local des réservations (indépendant d'InterFast).
 * Sert de filet de sécurité si l'appel API échoue ou si InterFast est
 * temporairement injoignable, et permet de consulter l'historique dans WP.
 */
class Lion_RDV_DB {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lion_rdv_bookings';
	}

	public static function activate() {
		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			service_type VARCHAR(30) NOT NULL,
			slot_start DATETIME NOT NULL,
			slot_end DATETIME NOT NULL,
			first_name VARCHAR(100) NOT NULL,
			last_name VARCHAR(100) NOT NULL,
			phone VARCHAR(30) NOT NULL,
			email VARCHAR(150) NOT NULL,
			address VARCHAR(255) NOT NULL,
			postal_code VARCHAR(10) NOT NULL,
			city VARCHAR(100) NOT NULL,
			message TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			interfast_event_id VARCHAR(100) NULL,
			interfast_error TEXT NULL,
			client_email_sent TINYINT(1) NOT NULL DEFAULT 0,
			client_email_error TEXT NULL,
			ip_address VARCHAR(45) NULL,
			PRIMARY KEY  (id),
			KEY slot_start (slot_start),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'lion_rdv_db_version', LION_RDV_DB_VERSION );
	}

	public static function insert_booking( array $data ) {
		global $wpdb;

		$wpdb->insert( self::table_name(), $data );

		return $wpdb->insert_id;
	}

	public static function update_booking( $id, array $data ) {
		global $wpdb;

		$wpdb->update( self::table_name(), $data, array( 'id' => $id ) );
	}

	public static function get_recent_bookings( $limit = 50 ) {
		global $wpdb;

		$table_name = self::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		);
	}
}
