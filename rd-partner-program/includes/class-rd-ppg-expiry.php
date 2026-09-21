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

class RD_PPG_Expiry {

	const CRON_HOOK     = 'rd_ppg_points_expiry_sweep';
	const BACKFILL_HOOK = 'rd_ppg_ledger_backfill_continue';

	private static $sweeping = false;

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'sweep' ) );
		add_action( self::BACKFILL_HOOK, array( 'RD_PPG_Ledger', 'backfill_from_mycred_log' ) );
		add_filter( 'mycred_add_finished', array( __CLASS__, 'on_transaction_complete' ), 10, 3 );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Daily cron: expire batches whose expires_at has passed.
	 * Deducts the remaining points from myCRED balance.
	 * Also zeroes expired Welcome Credit balances (separate point type).
	 */
	public static function sweep() {
		if ( ! function_exists( 'mycred_add' ) ) {
			return;
		}

		self::$sweeping = true;
		self::sweep_welcome_credits();
		$batches    = RD_PPG_Ledger::get_expired_batches( 100 );
		$point_type = RD_PPG_Settings::get( 'point_type' );

		foreach ( $batches as $batch ) {
			if ( (int) $batch->points_remaining <= 0 ) {
				RD_PPG_Ledger::mark_expired( $batch->id );
				continue;
			}

			mycred_add(
				'rd_ppg_points_expired',
				$batch->user_id,
				-1 * abs( (int) $batch->points_remaining ),
				sprintf( 'Points expired (%d pts)', $batch->points_remaining ),
				$batch->ref_id,
				array( 'ref_type' => 'post' ),
				$point_type
			);

			RD_PPG_Ledger::mark_expired( $batch->id );
		}

		self::$sweeping = false;

		if ( count( $batches ) >= 100 ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK );
		}
	}

	/**
	 * Welcome Credit expiry. The bonus is a one-time gift into its own point
	 * type, so no batch tracking is needed: when a user's stamped expiry
	 * timestamp passes, whatever balance remains in that type is zeroed.
	 */
	private static function sweep_welcome_credits() {
		$welcome_type = RD_PPG_Settings::welcome_type();
		if ( '' === $welcome_type || ! function_exists( 'mycred_get_users_balance' ) ) {
			return;
		}

		$user_ids = get_users(
			array(
				'fields'     => 'ids',
				'number'     => 200,
				'meta_query' => array(
					array(
						'key'     => 'rd_ppg_welcome_expires_at',
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $user_ids as $uid ) {
			$balance = (float) mycred_get_users_balance( $uid, $welcome_type );
			if ( $balance > 0 ) {
				mycred_add(
					'rd_ppg_welcome_expired',
					$uid,
					-1 * $balance,
					sprintf( 'Welcome credit expired (%s)', $balance ),
					0,
					'',
					$welcome_type
				);
			}
			delete_user_meta( $uid, 'rd_ppg_welcome_expires_at' );
		}
	}

	/**
	 * After any point transaction completes, if it was a deduction,
	 * consume from ledger FIFO (oldest batches first).
	 * Skips our own expiry deductions to avoid double-consuming.
	 */
	public static function on_transaction_complete( $result, $request, $mycred ) {
		if ( self::$sweeping ) {
			return $result;
		}
		if ( ! is_array( $request ) ) {
			return $result;
		}

		$amount = isset( $request['amount'] ) ? (float) $request['amount'] : 0;
		if ( $amount >= 0 ) {
			return $result;
		}

		$ref = isset( $request['ref'] ) ? $request['ref'] : '';
		if ( 'rd_ppg_points_expired' === $ref ) {
			return $result;
		}

		// The ledger tracks the main point type only. Welcome Credit and any
		// other type must never consume vital batches.
		$type = isset( $request['type'] ) ? $request['type'] : '';
		if ( '' !== $type && $type !== RD_PPG_Settings::get( 'point_type' ) ) {
			return $result;
		}

		$user_id = isset( $request['user_id'] ) ? (int) $request['user_id'] : 0;
		if ( ! $user_id ) {
			return $result;
		}

		RD_PPG_Ledger::consume( $user_id, abs( $amount ) );
		return $result;
	}

	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}
}
