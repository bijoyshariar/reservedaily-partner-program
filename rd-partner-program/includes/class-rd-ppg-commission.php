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

class RD_PPG_Commission {

	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'handle_completed_order' ), 20 );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rd_ppg_commission';
	}

	public static function handle_completed_order( $order_id ) {
		global $wpdb;

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}
		$partner = RD_PPG_Partner::for_user( $user_id );
		if ( ! $partner ) {
			return;
		}

		if ( $partner['commission_paused'] ) {
			return;
		}

		$existing = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE order_id = %d', $order_id ) );
		if ( $existing ) {
			return;
		}

		$signup_ts = RD_PPG_Partner::user_signup_ts( $user_id );
		if ( ! $signup_ts ) {
			return;
		}
		$window_end   = RD_PPG_Partner::effective_window_end( $user_id, $partner );
		$completed    = $order->get_date_completed();
		$completed_ts = $completed ? $completed->getTimestamp() : time();

		$breakdown = null;
		if ( RD_PPG_Partner::has_item_commission_rates( $partner ) ) {
			// Per item rates: commission line by line on product value only.
			// The stored rate is the blended result so Adjust keeps working
			// proportionally on the whole entry.
			$lines      = self::line_commission( $order, $partner );
			$paid_value = $lines['value'];
			$amount     = $lines['amount'];
			$rate       = $paid_value > 0 ? round( $amount / $paid_value * 100, 2 ) : 0;
			$breakdown  = wp_json_encode( $lines['lines'] );
		} else {
			// Single partner rate on the whole order net of refunds.
			$paid_value = round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 );
			$rate       = round( (float) $partner['commission_rate'], 2 );
			$amount     = round( $paid_value * $rate / 100, 2 );
		}
		$code = strtoupper( (string) get_user_meta( $user_id, 'rd_ppg_code', true ) );
		$now  = current_time( 'mysql' );

		$row = array(
			'merchant_id'       => $partner['id'],
			'user_id'           => $user_id,
			'order_id'          => $order_id,
			'code'              => $code,
			'paid_value'        => $paid_value,
			'rate'              => $rate,
			'commission_amount' => $amount,
			'slot_number'       => null,
			'counted'           => 0,
			'status'            => 'active',
			'settled_by'        => null,
			'note'              => null,
			'breakdown'         => $breakdown,
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		if ( $completed_ts > $window_end ) {
			$row['note'] = 'Outside commission window';
			self::insert_row( $row );
			return;
		}

		$used = self::used_slots( $partner['id'], $user_id );
		$cap  = (int) $partner['commission_order_cap'];
		if ( 0 === $cap || count( $used ) < $cap ) {
			$row['counted']     = 1;
			$row['slot_number'] = self::next_free_slot( $used, $cap );
		}

		self::insert_row( $row );
	}

	private static function insert_row( $row ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			$row,
			array( '%d', '%d', '%d', '%s', '%f', '%f', '%f', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Per line commission for partners with item level rates. Each product
	 * line pays at its own resolved rate on the line value after discounts and
	 * net of refunds on that line. Shipping and fees are not commissioned in
	 * this mode. Returns lines (for the stored breakdown), value (sum of line
	 * values) and amount (sum of line commissions).
	 */
	public static function line_commission( $order, $partner ) {
		$lines  = array();
		$value  = 0.0;
		$amount = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			$net = round( (float) $item->get_total() - (float) $order->get_total_refunded_for_item( $item_id ), 2 );
			if ( $net <= 0 ) {
				continue;
			}
			$product_id  = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
			$resolved    = RD_PPG_Partner::commission_rate_for_product( $product_id, $partner );
			$line_amount = round( $net * $resolved['rate'] / 100, 2 );

			$lines[] = array(
				'name'   => $item->get_name(),
				'qty'    => (int) $item->get_quantity(),
				'value'  => $net,
				'rate'   => round( $resolved['rate'], 2 ),
				'amount' => $line_amount,
				'source' => $resolved['source'],
			);
			$value  += $net;
			$amount += $line_amount;
		}

		return array(
			'lines'  => $lines,
			'value'  => round( $value, 2 ),
			'amount' => round( $amount, 2 ),
		);
	}

	/**
	 * Decoded per line breakdown for a commission entry, empty array when the
	 * entry was recorded with a single whole order rate.
	 */
	public static function breakdown_lines( $entry ) {
		if ( empty( $entry->breakdown ) ) {
			return array();
		}
		$lines = json_decode( $entry->breakdown, true );
		return is_array( $lines ) ? $lines : array();
	}

	/**
	 * Returns the slot numbers currently consumed by active counted entries for a user under a merchant.
	 */
	public static function used_slots( $merchant_id, $user_id ) {
		global $wpdb;
		$slots = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT slot_number FROM ' . self::table() . " WHERE merchant_id = %d AND user_id = %d AND counted = 1 AND status = 'active'",
				$merchant_id,
				$user_id
			)
		);
		return array_map( 'intval', $slots );
	}

	/**
	 * Lowest unused slot number. A cap of 0 means unlimited, the search space
	 * is then one larger than the used count so a free slot always exists.
	 */
	private static function next_free_slot( $used, $cap ) {
		$limit = $cap > 0 ? $cap : count( $used ) + 1;
		for ( $slot = 1; $slot <= $limit; $slot++ ) {
			if ( ! in_array( $slot, $used, true ) ) {
				return $slot;
			}
		}
		return null;
	}

	public static function get_entry( $entry_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $entry_id ) );
	}

	public static function void_entry( $entry_id, $admin_id ) {
		global $wpdb;
		$entry = self::get_entry( $entry_id );
		if ( ! $entry || 'voided' === $entry->status ) {
			return false;
		}

		$wpdb->update(
			self::table(),
			array(
				'status'      => 'voided',
				'counted'     => 0,
				'slot_number' => null,
				'settled_by'  => $admin_id,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $entry_id ),
			array( '%s', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);

		$partner = RD_PPG_Partner::get( $entry->merchant_id );
		if ( $partner && $partner['auto_backfill'] ) {
			self::auto_backfill( $partner, $entry->user_id, $admin_id );
		}
		return true;
	}

	public static function adjust_entry( $entry_id, $new_paid_value, $admin_id ) {
		global $wpdb;
		$entry = self::get_entry( $entry_id );
		if ( ! $entry || 'active' !== $entry->status ) {
			return false;
		}
		$paid   = round( max( 0, (float) $new_paid_value ), 2 );
		$amount = round( $paid * (float) $entry->rate / 100, 2 );

		$wpdb->update(
			self::table(),
			array(
				'paid_value'        => $paid,
				'commission_amount' => $amount,
				'settled_by'        => $admin_id,
				'updated_at'        => current_time( 'mysql' ),
			),
			array( 'id' => $entry_id ),
			array( '%f', '%f', '%d', '%s' ),
			array( '%d' )
		);
		return true;
	}

	public static function promote_entry( $entry_id, $admin_id ) {
		global $wpdb;
		$entry = self::get_entry( $entry_id );
		if ( ! $entry || 'active' !== $entry->status || 1 === (int) $entry->counted ) {
			return false;
		}
		$partner = RD_PPG_Partner::get( $entry->merchant_id );
		if ( ! $partner ) {
			return false;
		}

		$window_end = RD_PPG_Partner::effective_window_end( $entry->user_id, $partner );
		if ( (int) get_gmt_from_date( $entry->created_at, 'U' ) > $window_end ) {
			return false;
		}

		$used = self::used_slots( $entry->merchant_id, $entry->user_id );
		$cap  = (int) $partner['commission_order_cap'];
		if ( $cap > 0 && count( $used ) >= $cap ) {
			return false;
		}
		$slot = self::next_free_slot( $used, $cap );

		$wpdb->update(
			self::table(),
			array(
				'counted'     => 1,
				'slot_number' => $slot,
				'settled_by'  => $admin_id,
				'updated_at'  => current_time( 'mysql' ),
			),
			array( 'id' => $entry_id ),
			array( '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
		return true;
	}

	public static function note_entry( $entry_id, $note, $admin_id ) {
		global $wpdb;
		$entry = self::get_entry( $entry_id );
		if ( ! $entry ) {
			return false;
		}
		$wpdb->update(
			self::table(),
			array(
				'note'       => sanitize_textarea_field( $note ),
				'settled_by' => $admin_id,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $entry_id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		return true;
	}

	private static function auto_backfill( $partner, $user_id, $admin_id ) {
		global $wpdb;
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE merchant_id = %d AND user_id = %d AND counted = 0 AND status = 'active' AND ( note IS NULL OR note <> 'Outside commission window' ) ORDER BY created_at ASC",
				$partner['id'],
				$user_id
			)
		);
		$window_end = RD_PPG_Partner::effective_window_end( $user_id, $partner );
		foreach ( $candidates as $candidate ) {
			if ( (int) get_gmt_from_date( $candidate->created_at, 'U' ) <= $window_end ) {
				self::promote_entry( $candidate->id, $admin_id );
				return;
			}
		}
	}

	/**
	 * Completed purchase counts per code for the merchant dashboard.
	 * Includes counted and overflow entries, excludes voided.
	 */
	public static function purchases_by_code( $merchant_id, $from = '' ) {
		global $wpdb;
		$where  = "merchant_id = %d AND status = 'active'";
		$params = array( absint( $merchant_id ) );
		if ( $from && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT code, COUNT(*) AS purchases FROM ' . self::table() . " WHERE {$where} GROUP BY code",
				$params
			)
		);
	}

	public static function merchant_totals( $merchant_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(DISTINCT user_id) AS customers, COALESCE(SUM(commission_amount), 0) AS owed FROM ' . self::table() . " WHERE merchant_id = %d AND counted = 1 AND status = 'active'",
				$merchant_id
			)
		);
		return array(
			'customers' => $row ? (int) $row->customers : 0,
			'owed'      => $row ? (float) $row->owed : 0.0,
		);
	}
}
