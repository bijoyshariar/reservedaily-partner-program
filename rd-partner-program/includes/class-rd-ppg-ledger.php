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

class RD_PPG_Ledger {

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'rd_ppg_points_ledger';
	}

	public static function create_table() {
		global $wpdb;
		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			source VARCHAR(50) NOT NULL DEFAULT 'order_points',
			points_earned INT NOT NULL DEFAULT 0,
			points_remaining INT NOT NULL DEFAULT 0,
			earned_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			ref_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY user_status_expires (user_id,status,expires_at),
			KEY expires_status (expires_at,status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Shopee-style expiry: points earned in month M expire at end of month M+N-1.
	 * With default N=3: earned July -> expires September 30.
	 */
	public static function calculate_expiry( $earned_timestamp = null, $months = null ) {
		if ( null === $earned_timestamp ) {
			$earned_timestamp = time();
		}
		if ( null === $months ) {
			$months = (int) RD_PPG_Settings::get( 'points_expiry_months' );
		}
		if ( $months < 1 ) {
			$months = 3;
		}
		$earned_month = (int) gmdate( 'n', $earned_timestamp );
		$earned_year  = (int) gmdate( 'Y', $earned_timestamp );

		$target_month = $earned_month + $months - 1;
		$target_year  = $earned_year;
		while ( $target_month > 12 ) {
			$target_month -= 12;
			$target_year++;
		}

		$last_day = (int) gmdate( 't', gmmktime( 0, 0, 0, $target_month, 1, $target_year ) );
		return gmdate( 'Y-m-d', gmmktime( 0, 0, 0, $target_month, $last_day, $target_year ) ) . ' 23:59:59';
	}

	/**
	 * @param int      $user_id
	 * @param string   $source       welcome|signup_bonus|order_points
	 * @param int      $points
	 * @param int      $ref_id
	 * @param string   $earned_at    MySQL datetime or null for now.
	 * @param int|null $expiry_days  When set, expires earned_at + N days instead of Shopee month rule.
	 */
	public static function insert( $user_id, $source, $points, $ref_id = 0, $earned_at = null, $expiry_days = null ) {
		global $wpdb;

		if ( $points <= 0 ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		if ( null === $earned_at ) {
			$earned_at = $now;
		}

		$earned_ts = strtotime( $earned_at );

		if ( null !== $expiry_days && (int) $expiry_days > 0 ) {
			$expires_at = gmdate( 'Y-m-d 23:59:59', $earned_ts + ( (int) $expiry_days * DAY_IN_SECONDS ) );
		} else {
			$expires_at = self::calculate_expiry( $earned_ts );
		}

		return $wpdb->insert(
			self::table_name(),
			array(
				'user_id'          => absint( $user_id ),
				'source'           => sanitize_key( $source ),
				'points_earned'    => (int) $points,
				'points_remaining' => (int) $points,
				'earned_at'        => $earned_at,
				'expires_at'       => $expires_at,
				'status'           => 'active',
				'ref_id'           => absint( $ref_id ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * Consume points FIFO: oldest active batches first.
	 * Called when points are deducted (redemption, admin removal).
	 */
	public static function consume( $user_id, $amount ) {
		global $wpdb;

		$amount    = abs( (int) $amount );
		$remaining = $amount;
		$table     = self::table_name();

		$batches = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, points_remaining FROM {$table}
			 WHERE user_id = %d AND status = 'active' AND points_remaining > 0
			 ORDER BY expires_at ASC, id ASC",
			$user_id
		) );

		foreach ( $batches as $batch ) {
			if ( $remaining <= 0 ) {
				break;
			}
			$deduct = min( $remaining, (int) $batch->points_remaining );
			$new_remaining = (int) $batch->points_remaining - $deduct;
			$new_status    = $new_remaining <= 0 ? 'consumed' : 'active';

			$wpdb->update(
				$table,
				array(
					'points_remaining' => $new_remaining,
					'status'           => $new_status,
				),
				array( 'id' => $batch->id ),
				array( '%d', '%s' ),
				array( '%d' )
			);

			$remaining -= $deduct;
		}
	}

	/**
	 * Get expired batches that need sweeping.
	 */
	public static function get_expired_batches( $limit = 100 ) {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_id, points_remaining, source, ref_id FROM {$table}
			 WHERE status = 'active' AND points_remaining > 0 AND expires_at <= %s
			 ORDER BY expires_at ASC
			 LIMIT %d",
			$now,
			$limit
		) );
	}

	/**
	 * Mark a batch as expired.
	 */
	public static function mark_expired( $batch_id ) {
		global $wpdb;
		$wpdb->update(
			self::table_name(),
			array( 'status' => 'expired', 'points_remaining' => 0 ),
			array( 'id' => $batch_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Backfill ledger from myCRED log for existing transactions.
	 * Called once on upgrade to v1.8.0.
	 */
	public static function backfill_from_mycred_log( $batch_size = 500 ) {
		global $wpdb;

		$mycred_table = $wpdb->prefix . 'myCRED_log';
		$ledger_table = self::table_name();
		$point_type   = RD_PPG_Settings::get( 'point_type' );

		if ( ! $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $mycred_table ) ) ) {
			return;
		}

		$offset = (int) get_option( 'rd_ppg_ledger_backfill_offset', 0 );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, user_id, creds, ref, ref_id, time FROM {$mycred_table}
			 WHERE ctype = %s AND creds > 0
			 ORDER BY id ASC
			 LIMIT %d OFFSET %d",
			$point_type,
			$batch_size,
			$offset
		) );

		if ( empty( $rows ) ) {
			delete_option( 'rd_ppg_ledger_backfill_offset' );
			update_option( 'rd_ppg_ledger_backfill_done', 'yes' );
			return;
		}

		$now_ts             = time();
		$bonus_expiry_days  = (int) RD_PPG_Settings::get( 'signup_bonus_expiry_days' );

		foreach ( $rows as $row ) {
			$source = self::ref_to_source( $row->ref );

			$already = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$ledger_table} WHERE user_id = %d AND ref_id = %d AND source = %s AND points_earned = %d LIMIT 1",
				$row->user_id,
				$row->ref_id,
				$source,
				(int) $row->creds
			) );
			if ( $already ) {
				continue;
			}
			$earned_at  = gmdate( 'Y-m-d H:i:s', (int) $row->time );
			$earned_ts  = (int) $row->time;

			if ( 'signup_bonus' === $source && $bonus_expiry_days > 0 ) {
				$expires_at = gmdate( 'Y-m-d 23:59:59', $earned_ts + ( $bonus_expiry_days * DAY_IN_SECONDS ) );
			} else {
				$expires_at = self::calculate_expiry( $earned_ts );
			}
			$expires_ts = strtotime( $expires_at );

			$status = 'active';
			$points_remaining = (int) $row->creds;
			if ( $expires_ts <= $now_ts ) {
				$status = 'expired';
				$points_remaining = 0;
			}

			$wpdb->insert(
				$ledger_table,
				array(
					'user_id'          => (int) $row->user_id,
					'source'           => $source,
					'points_earned'    => (int) $row->creds,
					'points_remaining' => $points_remaining,
					'earned_at'        => $earned_at,
					'expires_at'       => $expires_at,
					'status'           => $status,
					'ref_id'           => (int) $row->ref_id,
				),
				array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d' )
			);
		}

		update_option( 'rd_ppg_ledger_backfill_offset', $offset + $batch_size );

		if ( count( $rows ) >= $batch_size ) {
			wp_schedule_single_event( time() + 5, 'rd_ppg_ledger_backfill_continue' );
		} else {
			delete_option( 'rd_ppg_ledger_backfill_offset' );
			update_option( 'rd_ppg_ledger_backfill_done', 'yes' );
		}
	}

	private static function ref_to_source( $ref ) {
		$map = array(
			'rd_ppg_order_points'          => 'order_points',
			'rd_ppg_signup_bonus'          => 'signup_bonus',
			'rd_ppg_welcome_points'        => 'welcome',
			'order_completed_rank_points'  => 'order_points',
		);
		return isset( $map[ $ref ] ) ? $map[ $ref ] : 'other';
	}
}
