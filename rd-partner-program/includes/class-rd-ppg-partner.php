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

class RD_PPG_Partner {

	const CPT = 'rd_partner';

	/**
	 * Returns the full merchant configuration for a partner post id, or null when missing.
	 */
	public static function get( $partner_id ) {
		$partner_id = absint( $partner_id );
		$post       = get_post( $partner_id );
		if ( ! $post || self::CPT !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}

		return array(
			'id'                       => $partner_id,
			'name'                     => $post->post_title,
			'active'                   => 'no' !== get_post_meta( $partner_id, 'rd_ppg_active', true ),
			'parent_code'              => strtoupper( (string) get_post_meta( $partner_id, 'rd_ppg_parent_code', true ) ),
			'coach_codes'              => self::meta_rows( $partner_id, 'rd_ppg_coach_codes' ),
			'commission_rate'          => (float) get_post_meta( $partner_id, 'rd_ppg_commission_rate', true ),
			'points_multiplier'        => self::meta_number( $partner_id, 'rd_ppg_points_multiplier', 1.5 ),
			'signup_bonus'             => absint( get_post_meta( $partner_id, 'rd_ppg_signup_bonus', true ) ),
			'signup_bonus_expiry_days' => (int) self::meta_number( $partner_id, 'rd_ppg_signup_bonus_expiry_days', 0 ),
			'eligible_categories'      => self::meta_rows( $partner_id, 'rd_ppg_eligible_categories' ),
			'eligible_products'        => self::meta_rows( $partner_id, 'rd_ppg_eligible_products' ),
			'excluded_products'        => array_map( 'absint', self::meta_rows( $partner_id, 'rd_ppg_excluded_products' ) ),
			'window_months'            => max( 1, self::meta_number( $partner_id, 'rd_ppg_window_months', 12 ) ),
			'deal_end'                 => (string) get_post_meta( $partner_id, 'rd_ppg_deal_end', true ),
			'commission_order_cap'     => max( 0, (int) self::meta_number( $partner_id, 'rd_ppg_commission_order_cap', 0 ) ),
			'landing_url'              => esc_url_raw( (string) get_post_meta( $partner_id, 'rd_ppg_landing_url', true ) ),
			'redemption_products'      => array_map( 'absint', self::meta_rows( $partner_id, 'rd_ppg_redemption_products' ) ),
			'redemption_categories'    => array_map( 'absint', self::meta_rows( $partner_id, 'rd_ppg_redemption_categories' ) ),
			'redemption_mode'          => self::redemption_mode( $partner_id ),
			'premium_redemption_cap'   => (float) self::meta_number( $partner_id, 'rd_ppg_premium_redemption_cap', 20 ),
			'allow_existing_customers' => 'yes' === get_post_meta( $partner_id, 'rd_ppg_allow_existing_customers', true ),
			'bonus_existing_customers' => 'yes' === get_post_meta( $partner_id, 'rd_ppg_bonus_existing_customers', true ),
			'commission_paused'        => 'yes' === get_post_meta( $partner_id, 'rd_ppg_commission_paused', true ),
			'auto_backfill'            => 'yes' === get_post_meta( $partner_id, 'rd_ppg_auto_backfill', true ),
			'dashboard_users'          => array_map( 'absint', self::meta_rows( $partner_id, 'rd_ppg_dashboard_users' ) ),
		);
	}

	/**
	 * Redemption perk mode: off | items | all.
	 * Falls back to the pre-mode meta (all-items checkbox) for rows saved
	 * before the mode field existed.
	 */
	private static function redemption_mode( $partner_id ) {
		$mode = (string) get_post_meta( $partner_id, 'rd_ppg_redemption_mode', true );
		if ( in_array( $mode, array( 'off', 'items', 'all' ), true ) ) {
			return $mode;
		}
		if ( 'yes' === get_post_meta( $partner_id, 'rd_ppg_redemption_all_items', true ) ) {
			return 'all';
		}
		$prods = get_post_meta( $partner_id, 'rd_ppg_redemption_products', true );
		$cats  = get_post_meta( $partner_id, 'rd_ppg_redemption_categories', true );
		if ( ( is_array( $prods ) && $prods ) || ( is_array( $cats ) && $cats ) ) {
			return 'items';
		}
		return 'off';
	}

	private static function meta_rows( $partner_id, $key ) {
		$value = get_post_meta( $partner_id, $key, true );
		return is_array( $value ) ? $value : array();
	}

	private static function meta_number( $partner_id, $key, $default ) {
		$value = get_post_meta( $partner_id, $key, true );
		if ( '' === $value || null === $value || false === $value ) {
			return $default;
		}
		return is_numeric( $value ) ? $value + 0 : $default;
	}

	public static function all_active() {
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		$partners = array();
		foreach ( $ids as $id ) {
			$partner = self::get( $id );
			if ( $partner && $partner['active'] ) {
				$partners[] = $partner;
			}
		}
		return $partners;
	}

	/**
	 * Resolves a raw code to an active merchant. Matches parent codes and coach codes.
	 * Returns array with merchant_id, parent_code, code, or null when no active partner matches.
	 */
	public static function find_by_code( $code ) {
		$code = strtoupper( trim( (string) $code ) );
		if ( '' === $code ) {
			return null;
		}
		foreach ( self::all_active() as $partner ) {
			$deal_end = self::deal_end_ts( $partner );
			if ( $deal_end && time() > $deal_end ) {
				continue;
			}
			if ( $partner['parent_code'] === $code ) {
				return array(
					'merchant_id' => $partner['id'],
					'parent_code' => $partner['parent_code'],
					'code'        => $code,
				);
			}
			foreach ( $partner['coach_codes'] as $row ) {
				if ( isset( $row['code'] ) && strtoupper( $row['code'] ) === $code ) {
					return array(
						'merchant_id' => $partner['id'],
						'parent_code' => $partner['parent_code'],
						'code'        => $code,
					);
				}
			}
		}
		return null;
	}

	/**
	 * Returns the tagged merchant for a user when the tag exists and the merchant is active.
	 */
	public static function for_user( $user_id ) {
		$merchant_id = absint( get_user_meta( $user_id, 'rd_ppg_merchant_id', true ) );
		if ( ! $merchant_id ) {
			return null;
		}
		$partner = self::get( $merchant_id );
		if ( ! $partner || ! $partner['active'] ) {
			return null;
		}
		return $partner;
	}

	/**
	 * Returns the partner whose dashboard users include this user, regardless of
	 * active state so a paused merchant can still see their dashboard.
	 */
	public static function for_dashboard_user( $user_id ) {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);
		foreach ( $ids as $id ) {
			$users = get_post_meta( $id, 'rd_ppg_dashboard_users', true );
			if ( is_array( $users ) && in_array( $user_id, array_map( 'absint', $users ), true ) ) {
				return self::get( $id );
			}
		}
		return null;
	}

	public static function user_signup_ts( $user_id ) {
		$ts = absint( get_user_meta( $user_id, 'rd_ppg_signup_date', true ) );
		if ( $ts ) {
			return $ts;
		}
		$user = get_userdata( $user_id );
		return $user ? strtotime( $user->user_registered ) : 0;
	}

	public static function window_end_ts( $user_id, $window_months ) {
		$signup = self::user_signup_ts( $user_id );
		if ( ! $signup ) {
			return 0;
		}
		return strtotime( '+' . absint( $window_months ) . ' months', $signup );
	}

	/**
	 * Deal end as a unix timestamp, end of that day site time. 0 when open ended.
	 */
	public static function deal_end_ts( $partner ) {
		if ( empty( $partner['deal_end'] ) ) {
			return 0;
		}
		return (int) get_gmt_from_date( $partner['deal_end'] . ' 23:59:59', 'U' );
	}

	/**
	 * The moment a customer's special treatment ends, whichever comes first of
	 * their personal window and the partner deal end date. Governs commission,
	 * discounts, the points multiplier, and the redemption override.
	 */
	public static function effective_window_end( $user_id, $partner ) {
		$end  = self::window_end_ts( $user_id, $partner['window_months'] );
		$deal = self::deal_end_ts( $partner );
		if ( $deal && ( ! $end || $deal < $end ) ) {
			$end = $deal;
		}
		return $end;
	}

	public static function user_in_window( $user_id, $partner ) {
		$end = self::effective_window_end( $user_id, $partner );
		return $end ? time() <= $end : false;
	}

	/**
	 * True when a category or product row carries its own commission rate.
	 * Blank rows fall back to the partner default and do not count.
	 */
	public static function row_has_commission( $row ) {
		return isset( $row['commission'] ) && null !== $row['commission'] && '' !== $row['commission'];
	}

	/**
	 * True when any eligible category or product row has its own commission
	 * rate. That switches commission from a single rate on the whole order
	 * to a per line calculation.
	 */
	public static function has_item_commission_rates( $partner ) {
		foreach ( array( 'eligible_categories', 'eligible_products' ) as $key ) {
			foreach ( $partner[ $key ] as $row ) {
				if ( self::row_has_commission( $row ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Commission rate for one product under a partner. A product row with its
	 * own rate wins, then a category row with its own rate, then the partner
	 * default. Returns array with rate (float) and source (product, category,
	 * or default).
	 */
	public static function commission_rate_for_product( $product_id, $partner ) {
		$default = array(
			'rate'   => (float) $partner['commission_rate'],
			'source' => 'default',
		);

		$product_id = absint( $product_id );
		$product    = wc_get_product( $product_id );
		if ( ! $product ) {
			return $default;
		}

		$parent_id = $product->get_parent_id();
		$check_ids = array_filter( array_unique( array( $product_id, $parent_id ) ) );

		foreach ( $partner['eligible_products'] as $row ) {
			$row_id = isset( $row['product_id'] ) ? absint( $row['product_id'] ) : 0;
			if ( $row_id && in_array( $row_id, $check_ids, true ) && self::row_has_commission( $row ) ) {
				return array(
					'rate'   => (float) $row['commission'],
					'source' => 'product',
				);
			}
		}

		$category_target = $parent_id ? $parent_id : $product_id;
		foreach ( $partner['eligible_categories'] as $row ) {
			$term_id = isset( $row['term_id'] ) ? absint( $row['term_id'] ) : 0;
			if ( $term_id && self::row_has_commission( $row ) && has_term( $term_id, 'product_cat', $category_target ) ) {
				return array(
					'rate'   => (float) $row['commission'],
					'source' => 'category',
				);
			}
		}

		return $default;
	}
}
