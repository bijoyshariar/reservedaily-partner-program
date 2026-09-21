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

class RD_PPG_Discounts {

	public static function init() {
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'apply_discounts' ), 20 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'cart_item_label' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'persist_line_flags' ), 10, 4 );
	}

	public static function apply_discounts( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		$partner = is_user_logged_in() ? RD_PPG_Partner::for_user( get_current_user_id() ) : null;
		if ( $partner && ! RD_PPG_Partner::user_in_window( get_current_user_id(), $partner ) ) {
			$partner = null;
		}
		$any = false;

		// The undiscounted price is read fresh on the first calculation pass of
		// each request and only remembered for the rest of that request, never
		// stored in the cart session. On the first pass the line price is the
		// true current price with add-on surcharges baked in; on later passes in
		// the same request it may already carry our discount. Persisting it
		// across requests is what caused stale and inflated originals before:
		// the line is repriced from scratch on every request, so a per-request
		// snapshot is always correct even after the customer edits options.
		static $originals = array();

		foreach ( $cart->cart_contents as $key => $item ) {
			$discounted = false;

			if ( $partner ) {
				$product_id = ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'];
				$resolved   = RD_PPG_Eligibility::resolve( $product_id, $partner );

				if ( $resolved['eligible'] && $resolved['rate'] > 0 ) {
					if ( ! isset( $originals[ $key ] ) ) {
						$originals[ $key ] = (float) $item['data']->get_price();
					}
					$original = $originals[ $key ];
					unset( $cart->cart_contents[ $key ]['rd_ppg_original_price'] );

					$item['data']->set_price( round( $original * ( 1 - $resolved['rate'] / 100 ), wc_get_price_decimals() ) );
					$cart->cart_contents[ $key ]['rd_ppg_discounted']    = true;
					$cart->cart_contents[ $key ]['rd_ppg_discount_rate'] = $resolved['rate'];
					$discounted = true;
					$any        = true;
				}
			}

			if ( ! $discounted ) {
				unset( $cart->cart_contents[ $key ]['rd_ppg_discounted'], $cart->cart_contents[ $key ]['rd_ppg_discount_rate'] );
			}
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'rd_ppg_discount_active', $any ? 'yes' : '' );
		}
	}

	public static function cart_item_label( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['rd_ppg_discounted'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Partner discount', 'rd-partner-program' ),
				'value' => wc_clean( $cart_item['rd_ppg_discount_rate'] . '%' ),
			);
		}
		return $item_data;
	}

	public static function persist_line_flags( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['rd_ppg_discounted'] ) ) {
			$item->add_meta_data( '_rd_ppg_discounted', 'yes', true );
			$item->add_meta_data( '_rd_ppg_discount_rate', (float) $values['rd_ppg_discount_rate'], true );
		}
	}
}
