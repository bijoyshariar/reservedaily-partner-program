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

class RD_PPG_Points {

	public static function init() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'award_order_points' ), 10 );
		add_action( 'init', array( __CLASS__, 'neutralize_legacy_snippet' ), 0 );
	}

	/**
	 * Safety net against double awards while the old standalone snippet is still active.
	 * The snippet should still be deleted, see the readme.
	 */
	public static function neutralize_legacy_snippet() {
		if ( function_exists( 'uk_award_rank_points_on_completed_order' ) ) {
			remove_action( 'woocommerce_order_status_completed', 'uk_award_rank_points_on_completed_order' );
		}
	}

	/**
	 * Awards rank based myCRED points per completed order with the merchant multiplier.
	 * Discounted eligible lines earn zero, discount instead of points.
	 */
	public static function award_order_points( $order_id ) {
		if ( ! function_exists( 'mycred_add' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		if ( 'yes' === $order->get_meta( '_rd_ppg_points_awarded' ) ) {
			return;
		}
		if ( 'yes' === $order->get_meta( '_uk_points_awarded' ) ) {
			return;
		}
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$settings = RD_PPG_Settings::get();
		$ranks    = $settings['rank_percentages'];

		$rank = get_user_meta( $user_id, 'uk_wellness_rank', true );
		if ( ! $rank || ! isset( $ranks[ $rank ] ) ) {
			$rank = 'vital_core';
		}
		$percent = isset( $ranks[ $rank ] ) ? (float) $ranks[ $rank ] : 1.5;

		$partner    = RD_PPG_Partner::for_user( $user_id );
		$in_window  = $partner && RD_PPG_Partner::user_in_window( $user_id, $partner );
		$multiplier = $in_window ? (float) $partner['points_multiplier'] : 1.0;
		if ( $multiplier <= 0 ) {
			$multiplier = 1.0;
		}

		$points_per_rm = absint( $settings['points_per_rm'] );
		$total_points  = 0.0;

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$line_paid = (float) $item->get_total();
			if ( $line_paid <= 0 ) {
				continue;
			}
			if ( 'yes' === $item->get_meta( '_rd_ppg_discounted' ) && $partner ) {
				$item_product_id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
				if ( RD_PPG_Eligibility::is_eligible( $item_product_id, $partner ) ) {
					continue;
				}
			}
			$total_points += $line_paid * ( $percent / 100 ) * $multiplier * $points_per_rm;
		}

		$points = (int) round( $total_points );
		if ( $points <= 0 ) {
			$order->update_meta_data( '_rd_ppg_points_awarded', 'yes' );
			$order->save();
			return;
		}

		$added = mycred_add(
			'rd_ppg_order_points',
			$user_id,
			$points,
			sprintf( 'Earned %d points for order #%d as %s', $points, $order_id, ucwords( str_replace( '_', ' ', $rank ) ) ),
			$order_id,
			array( 'ref_type' => 'post' ),
			$settings['point_type']
		);

		if ( $added ) {
			RD_PPG_Ledger::insert( $user_id, 'order_points', $points, $order_id );
		}

		$order->update_meta_data( '_rd_ppg_points_awarded', 'yes' );
		$order->save();
	}
}
