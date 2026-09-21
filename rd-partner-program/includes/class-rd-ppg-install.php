<?php
/**
 * ReserveDaily Partner Program by Shariar Bijoy.
 *
 * @author Shariar Bijoy
 * @link https://shariarbijoy.dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RD_PPG_Install {

	const DB_VERSION = '1.3.0';

	public static function activate() {
		self::create_tables();
		self::seed_settings();
		update_option( 'rd_ppg_db_version', self::DB_VERSION );
	}

	/**
	 * Runs dbDelta again when the plugin updates without a fresh activation.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'rd_ppg_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'rd_ppg_db_version', self::DB_VERSION );
			self::maybe_backfill_ledger();
		}
	}

	private static function maybe_backfill_ledger() {
		if ( 'yes' === get_option( 'rd_ppg_ledger_backfill_done' ) ) {
			return;
		}
		RD_PPG_Ledger::backfill_from_mycred_log();
	}

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'rd_ppg_commission';
	}

	private static function create_tables() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			merchant_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(64) NOT NULL DEFAULT '',
			paid_value DECIMAL(10,2) NOT NULL DEFAULT 0,
			rate DECIMAL(5,2) NOT NULL DEFAULT 0,
			commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			slot_number SMALLINT UNSIGNED NULL,
			counted TINYINT(1) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			settled_by BIGINT UNSIGNED NULL,
			note TEXT NULL,
			breakdown LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY merchant_id (merchant_id),
			KEY user_id (user_id),
			UNIQUE KEY order_id (order_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		$stats_table = $wpdb->prefix . 'rd_ppg_stats';

		$stats_sql = "CREATE TABLE {$stats_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			merchant_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(64) NOT NULL DEFAULT '',
			stat_date DATE NOT NULL,
			visits INT UNSIGNED NOT NULL DEFAULT 0,
			signups INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY code_date (code,stat_date),
			KEY merchant_id (merchant_id)
		) {$charset_collate};";

		dbDelta( $stats_sql );

		RD_PPG_Ledger::create_table();
	}

	private static function seed_settings() {
		if ( false === get_option( 'rd_ppg_settings', false ) ) {
			add_option( 'rd_ppg_settings', RD_PPG_Settings::defaults() );
		}
	}
}
