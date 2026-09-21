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

class RD_PPG_Stats {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rd_ppg_stats';
	}

	public static function record_visit( $merchant_id, $code ) {
		self::bump( $merchant_id, $code, 'visits' );
	}

	public static function record_signup( $merchant_id, $code ) {
		self::bump( $merchant_id, $code, 'signups' );
	}

	/**
	 * Atomic daily counter per code. One row per code per day.
	 */
	private static function bump( $merchant_id, $code, $column ) {
		global $wpdb;
		$column = 'signups' === $column ? 'signups' : 'visits';
		$values = 'signups' === $column ? '0, 1' : '1, 0';
		$table  = self::table();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (merchant_id, code, stat_date, visits, signups) VALUES (%d, %s, %s, {$values}) ON DUPLICATE KEY UPDATE {$column} = {$column} + 1",
				absint( $merchant_id ),
				strtoupper( $code ),
				current_time( 'Y-m-d' )
			)
		);
	}

	public static function totals_by_code( $merchant_id, $from = '', $to = '' ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( 'merchant_id = %d' );
		$params = array( absint( $merchant_id ) );

		if ( $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$where[]  = 'stat_date >= %s';
			$params[] = $from;
		}
		if ( $to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$where[]  = 'stat_date <= %s';
			$params[] = $to;
		}

		$sql = "SELECT code, SUM(visits) AS visits, SUM(signups) AS signups FROM {$table} WHERE " . implode( ' AND ', $where ) . ' GROUP BY code ORDER BY visits DESC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Filters out crawlers and link preview fetchers so shares on social apps
	 * do not inflate visit counts.
	 */
	public static function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' === $ua ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|curl|wget|python|httpclient|monitor|preview|facebookexternalhit|whatsapp|telegram|skype|slackbot|discordbot|embedly|quora|pinterest|vkshare|scan|validator/i', $ua );
	}
}
