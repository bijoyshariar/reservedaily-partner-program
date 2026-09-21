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

class RD_PPG_Redemption {

	private static $user_cached  = false;
	private static $user_partner = null;
	private static $resolving    = false;

	public static function init() {
		// Legacy myCRED Toolkit Pro reads the max from the mycred_pref_woo option.
		add_filter( 'option_mycred_pref_woo', array( __CLASS__, 'filter_woo_pref' ), 20 );
		add_filter( 'mycred_get_woocommerce_settings', array( __CLASS__, 'filter_woo_settings' ), 20, 3 );

		// Revamped myCRED Toolkit Pro (WooCommerce Plus revamped) reads the max from
		// the point type settings object: mycred()->core['partial_payment_settings']['mwp_max'].
		// Both the slider data and the apply-discount enforcement run through these two
		// AJAX actions. We hook them at priority 5 (module handler runs at 10) and modify
		// the cached settings object in place before myCRED reads it.
		add_action( 'wp_ajax_mycred_get_partial_data', array( __CLASS__, 'inject_cap_into_mycred' ), 5 );
		add_action( 'wp_ajax_mycred_new_partial_payment', array( __CLASS__, 'inject_cap_into_mycred' ), 5 );

		// Hide the point type picker at cart/checkout and auto-select the right
		// type: Welcome Credit while the customer has any, Vital Points after.
		add_action( 'wp_footer', array( __CLASS__, 'point_type_autoselect_script' ), 99 );
	}

	/**
	 * Resolve the effective redemption cap for the current visitor, main point
	 * type only. Welcome Credit lives in its own myCRED point type with its own
	 * native max (100%) and is never touched here.
	 *
	 * Priority:
	 * 1. Tagged in-window customer, partner redemption perk matches the cart -> perk cap
	 * 2. Fallback -> standard cap (default 5%)
	 *
	 * Returns null when there is nothing to override (admin screens, guests).
	 */
	private static function override_cap() {
		// Re-entry guard: partner_cart_cap reads the mycred_pref_woo option,
		// which runs back through our own legacy filter. The nested call
		// leaves the option untouched instead of recursing.
		if ( self::$resolving ) {
			return null;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return null;
		}
		if ( ! is_user_logged_in() ) {
			return null;
		}

		if ( ! self::$user_cached ) {
			self::$user_cached = true;
			$user_id = get_current_user_id();

			$partner = RD_PPG_Partner::for_user( $user_id );
			if ( $partner && RD_PPG_Partner::user_in_window( $user_id, $partner ) ) {
				self::$user_partner = $partner;
			}
		}

		if ( self::$user_partner ) {
			self::$resolving = true;
			$cap = self::partner_cart_cap( self::$user_partner );
			self::$resolving = false;
			return $cap;
		}

		return (float) RD_PPG_Settings::get( 'standard_redemption_cap' );
	}

	/**
	 * Resolve the redemption perk cap for a tagged customer's current cart.
	 * Mode off = standard cap. Mode all = perk cap on any cart. Mode items =
	 * BLENDED cap: the perk percent applies only to the perk items' share of
	 * the cart, everything else contributes at the standard percent. One
	 * effective percentage comes out, so the toolkit needs no changes.
	 * Never cached: the filter can fire before WC()->cart is available.
	 */
	private static function partner_cart_cap( $partner ) {
		$standard_cap = (float) RD_PPG_Settings::get( 'standard_redemption_cap' );
		$perk_cap     = (float) $partner['premium_redemption_cap'];
		$mode         = $partner['redemption_mode'];

		if ( 'all' === $mode ) {
			return $perk_cap;
		}
		if ( 'items' !== $mode ) {
			return $standard_cap;
		}

		$perk_prods = $partner['redemption_products'];
		$perk_cats  = $partner['redemption_categories'];

		if ( empty( $perk_prods ) && empty( $perk_cats ) ) {
			return $standard_cap;
		}

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $standard_cap;
		}

		$eligible_subtotal = 0.0;
		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id   = (int) $item['product_id'];
			$variation_id = isset( $item['variation_id'] ) ? (int) $item['variation_id'] : 0;
			$line         = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0;

			$is_perk = false;
			if ( ! empty( $perk_prods ) && ( in_array( $product_id, $perk_prods, true ) || ( $variation_id && in_array( $variation_id, $perk_prods, true ) ) ) ) {
				$is_perk = true;
			} elseif ( ! empty( $perk_cats ) ) {
				foreach ( $perk_cats as $term_id ) {
					if ( has_term( (int) $term_id, 'product_cat', $product_id ) ) {
						$is_perk = true;
						break;
					}
				}
			}

			if ( $is_perk ) {
				$eligible_subtotal += $line;
			}
		}

		if ( $eligible_subtotal <= 0 ) {
			return $standard_cap;
		}

		// Mirror the base the toolkit multiplies the percent against:
		// cart subtotal, plus shipping unless its free_shipping option is off.
		$base      = (float) WC()->cart->get_subtotal();
		$woo_prefs = get_option( 'mycred_pref_woo' );
		$shipping_included = ! ( is_array( $woo_prefs )
			&& isset( $woo_prefs['mwp_partial_payments']['free_shipping'] )
			&& 'no' === $woo_prefs['mwp_partial_payments']['free_shipping'] );
		if ( $shipping_included ) {
			$base += (float) WC()->cart->get_shipping_total();
		}

		if ( $base <= 0 ) {
			return $standard_cap;
		}
		if ( $eligible_subtotal > $base ) {
			$eligible_subtotal = $base;
		}

		$allowed_value = ( $eligible_subtotal * $perk_cap / 100 ) + ( ( $base - $eligible_subtotal ) * $standard_cap / 100 );
		$effective     = $allowed_value / $base * 100;

		return min( 100, $effective );
	}

	/**
	 * Revamped Toolkit Pro support. Runs at priority 5 on the partial payment
	 * AJAX actions, before the myCRED module handler at priority 10. Swaps
	 * mwp_max on the cached myCRED settings object so both the slider data
	 * response and the apply-discount validation use our cap.
	 *
	 * Applies to the main point type only. Welcome Credit requests pass
	 * through untouched so its native 100% max stays in force.
	 */
	public static function inject_cap_into_mycred() {
		if ( ! function_exists( 'mycred' ) ) {
			return;
		}

		$point_type = '';
		if ( isset( $_POST['selected_pointtype'] ) ) {
			$point_type = sanitize_key( wp_unslash( $_POST['selected_pointtype'] ) );
		} elseif ( isset( $_POST['type'] ) ) {
			$point_type = sanitize_key( wp_unslash( $_POST['type'] ) );
		}
		if ( '' === $point_type ) {
			$point_type = RD_PPG_Settings::get( 'point_type' );
		}

		if ( $point_type !== RD_PPG_Settings::get( 'point_type' ) ) {
			return;
		}

		$cap = self::override_cap();
		if ( null === $cap ) {
			return;
		}

		$mycred = mycred( $point_type );
		if ( ! is_object( $mycred ) ) {
			return;
		}

		if ( isset( $mycred->core['partial_payment_settings'] ) && is_array( $mycred->core['partial_payment_settings'] ) ) {
			$mycred->core['partial_payment_settings']['mwp_max'] = (float) $cap;
		}
		if ( isset( $mycred->partial_payment_settings ) && is_array( $mycred->partial_payment_settings ) ) {
			$mycred->partial_payment_settings['mwp_max'] = (float) $cap;
		}
	}

	/**
	 * Hides the partial payment point type picker and auto-selects the right
	 * type: Welcome Credit while the customer has a balance, the main type
	 * otherwise. Uses the toolkit's own change handler to load the slider.
	 * Without the Welcome Credit point type there is nothing to pick, the
	 * toolkit handles the single type itself.
	 */
	public static function point_type_autoselect_script() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$is_cart     = function_exists( 'is_cart' ) && is_cart();
		$is_checkout = function_exists( 'is_checkout' ) && is_checkout();
		if ( ! $is_cart && ! $is_checkout ) {
			return;
		}

		$welcome_type = RD_PPG_Settings::welcome_type();
		if ( '' === $welcome_type || ! function_exists( 'mycred_get_users_balance' ) ) {
			return;
		}

		$default_type    = RD_PPG_Settings::get( 'point_type' );
		$welcome_balance = (float) mycred_get_users_balance( get_current_user_id(), $welcome_type );
		$preferred       = $welcome_balance > 0 ? $welcome_type : $default_type;
		?>
		<script>
		(function(){
			if ( typeof jQuery === 'undefined' ) { return; }
			var rdPreferredType = <?php echo wp_json_encode( $preferred ); ?>;

			function rdPickPointType() {
				var $select = jQuery( '#point-type-select' );
				if ( ! $select.length ) { return; }

				jQuery( 'label[for="point-type-select"]' ).hide();
				$select.hide();

				var want = rdPreferredType;
				if ( ! $select.find( 'option[value="' + want + '"]' ).length ) {
					want = $select.find( 'option' ).not( '[value="0"]' ).first().val();
				}
				if ( ! want ) { return; }

				if ( $select.val() !== want ) {
					$select.val( want ).trigger( 'change' );
				}
			}

			jQuery( rdPickPointType );

			jQuery( document ).ajaxComplete( function( event, xhr, settings ) {
				if ( settings && settings.url && ( settings.url.indexOf( 'wc-ajax=get_refreshed_fragments' ) !== -1 || settings.url.indexOf( 'wc-ajax=update_order_review' ) !== -1 ) ) {
					setTimeout( rdPickPointType, 150 );
				}
			} );

			jQuery( document.body ).on( 'updated_checkout wc_fragments_refreshed updated_wc_div', function() {
				setTimeout( rdPickPointType, 150 );
			} );
		})();
		</script>
		<?php
	}

	/**
	 * Legacy Toolkit Pro support: filter the stored option.
	 */
	public static function filter_woo_pref( $value ) {
		if ( ! is_array( $value ) || empty( $value['mwp_partial_payments'] ) || ! is_array( $value['mwp_partial_payments'] ) ) {
			return $value;
		}
		$cap = self::override_cap();
		if ( null === $cap ) {
			return $value;
		}
		$value['mwp_partial_payments']['max'] = (int) round( $cap );
		return $value;
	}

	/**
	 * Legacy Toolkit Pro support: filter the settings getter.
	 */
	public static function filter_woo_settings( $settings, $module_name, $pref_woo ) {
		$cap = self::override_cap();
		if ( null === $cap ) {
			return $settings;
		}
		if ( 'mwp_partial_payments' === $module_name && is_array( $settings ) && isset( $settings['max'] ) ) {
			$settings['max'] = (int) round( $cap );
		} elseif ( '' === $module_name && is_array( $settings ) && ! empty( $settings['mwp_partial_payments']['max'] ) ) {
			$settings['mwp_partial_payments']['max'] = (int) round( $cap );
		}
		return $settings;
	}
}
