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

class RD_PPG_Referral {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture_ref' ), 1 );
		add_action( 'user_register', array( __CLASS__, 'tag_on_register' ), 20 );
		// The typed code fields are off by default: the referral link is the
		// only way in. The POST handlers below stay registered because they
		// only act when a code is actually posted (for example routed from
		// the server-side friend referral field).
		if ( ! empty( RD_PPG_Settings::get( 'typed_code_fields' ) ) ) {
			add_action( 'woocommerce_register_form', array( __CLASS__, 'render_registration_code_field' ), 99 );
			add_action( 'register_form', array( __CLASS__, 'render_registration_code_field' ), 99 );
			add_action( 'woocommerce_after_order_notes', array( __CLASS__, 'render_checkout_code_field' ) );
		}
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout_code' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'tag_from_checkout' ), 20, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'print_tracker' ), 99 );
		add_action( 'wp_ajax_rd_ppg_track', array( __CLASS__, 'ajax_track' ) );
		add_action( 'wp_ajax_nopriv_rd_ppg_track', array( __CLASS__, 'ajax_track' ) );
	}

	public static function sanitize_code( $raw ) {
		$code = strtoupper( preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $raw ) );
		return substr( $code, 0, 64 );
	}

	public static function cookie_name() {
		return RD_PPG_Settings::get( 'cookie_name' );
	}

	public static function capture_ref() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! isset( $_GET['ref'] ) ) {
			return;
		}

		$code = self::sanitize_code( wp_unslash( $_GET['ref'] ) );
		if ( '' === $code ) {
			return;
		}

		$match = RD_PPG_Partner::find_by_code( $code );
		if ( ! $match ) {
			return;
		}

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			if ( get_user_meta( $user_id, 'rd_ppg_merchant_id', true ) ) {
				return;
			}
			$partner = RD_PPG_Partner::get( $match['merchant_id'] );
			if ( $partner && $partner['allow_existing_customers'] && self::tag_user( $user_id, $match ) ) {
				RD_PPG_Stats::record_signup( $match['merchant_id'], $match['code'] );
				if ( $partner['bonus_existing_customers'] ) {
					self::award_signup_bonus( $user_id, $match['merchant_id'] );
				}
			}
			return;
		}

		$cookie = self::cookie_name();
		if ( isset( $_COOKIE[ $cookie ] ) && '' !== $_COOKIE[ $cookie ] ) {
			return;
		}
		if ( headers_sent() ) {
			return;
		}

		$lifetime = absint( RD_PPG_Settings::get( 'cookie_lifetime_days' ) ) * DAY_IN_SECONDS;
		setcookie( $cookie, $code, time() + $lifetime, '/', '', is_ssl(), true );
		$_COOKIE[ $cookie ] = $code;
	}

	/**
	 * A code typed into the registration or checkout form wins over the cookie,
	 * explicit intent beats a stale first touch. Admin side account creation is
	 * skipped entirely so an admin's own browser cookie never tags accounts
	 * created from wp-admin.
	 */
	public static function tag_on_register( $user_id ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}

		$match = null;

		if ( ! empty( $_POST['rd_ppg_partner_code'] ) ) {
			$typed = self::sanitize_code( wp_unslash( $_POST['rd_ppg_partner_code'] ) );
			if ( '' !== $typed ) {
				$match = RD_PPG_Partner::find_by_code( $typed );
			}
		}

		if ( ! $match ) {
			$cookie = self::cookie_name();
			if ( empty( $_COOKIE[ $cookie ] ) ) {
				return;
			}
			$code  = self::sanitize_code( wp_unslash( $_COOKIE[ $cookie ] ) );
			$match = RD_PPG_Partner::find_by_code( $code );
			if ( ! $match ) {
				return;
			}
		}

		if ( get_user_meta( $user_id, 'rd_ppg_merchant_id', true ) ) {
			return;
		}

		if ( self::tag_user( $user_id, $match ) ) {
			RD_PPG_Stats::record_signup( $match['merchant_id'], $match['code'] );
			self::award_signup_bonus( $user_id, $match['merchant_id'] );
		}
		self::clear_cookie();
	}

	/**
	 * Writes the permanent first touch tag. Never overwrites an existing tag.
	 */
	public static function tag_user( $user_id, $match ) {
		if ( get_user_meta( $user_id, 'rd_ppg_merchant_id', true ) ) {
			return false;
		}
		update_user_meta( $user_id, 'rd_ppg_merchant_id', absint( $match['merchant_id'] ) );
		update_user_meta( $user_id, 'rd_ppg_parent_code', $match['parent_code'] );
		update_user_meta( $user_id, 'rd_ppg_code', $match['code'] );
		update_user_meta( $user_id, 'rd_ppg_signup_date', time() );
		return true;
	}

	/**
	 * Awards the partner welcome bonus once per user. Public since 2.3.0 so the
	 * manual tagging box on the user edit screen uses the same path.
	 */
	public static function award_signup_bonus( $user_id, $merchant_id ) {
		if ( ! function_exists( 'mycred_add' ) ) {
			return false;
		}
		if ( 'yes' === get_user_meta( $user_id, 'rd_ppg_signup_bonus_awarded', true ) ) {
			return false;
		}
		$partner = RD_PPG_Partner::get( $merchant_id );
		if ( ! $partner || $partner['signup_bonus'] <= 0 ) {
			return false;
		}

		$welcome_type = RD_PPG_Settings::welcome_type();
		$award_type   = '' !== $welcome_type ? $welcome_type : RD_PPG_Settings::get( 'point_type' );

		$added = mycred_add(
			'rd_ppg_signup_bonus',
			$user_id,
			$partner['signup_bonus'],
			sprintf( 'Partner welcome bonus, %s', $partner['name'] ),
			$merchant_id,
			array( 'ref_type' => 'post' ),
			$award_type
		);
		update_user_meta( $user_id, 'rd_ppg_signup_bonus_awarded', 'yes' );

		if ( $added ) {
			$bonus_expiry_days = (int) $partner['signup_bonus_expiry_days'];
			if ( $bonus_expiry_days <= 0 ) {
				$bonus_expiry_days = (int) RD_PPG_Settings::get( 'signup_bonus_expiry_days' );
			}
			if ( $bonus_expiry_days <= 0 ) {
				// Never stamp an expiry at or before "now" (2.3.1 guard).
				$bonus_expiry_days = 30;
			}

			if ( '' !== $welcome_type ) {
				// Welcome Credit point type: expiry is a per-user timestamp, the
				// daily sweep zeroes whatever is left after it passes.
				update_user_meta( $user_id, 'rd_ppg_welcome_expires_at', time() + ( $bonus_expiry_days * DAY_IN_SECONDS ) );
			} else {
				// Legacy single-type fallback: track the batch in the ledger.
				RD_PPG_Ledger::insert( $user_id, 'signup_bonus', $partner['signup_bonus'], $merchant_id, null, $bonus_expiry_days );
			}
		}
		return (bool) $added;
	}

	private static function clear_cookie() {
		$cookie = self::cookie_name();
		if ( ! headers_sent() ) {
			setcookie( $cookie, '', time() - HOUR_IN_SECONDS, '/', '', is_ssl(), true );
		}
		unset( $_COOKIE[ $cookie ] );
	}

	/**
	 * Cache proof capture. This inline script ships inside the cached HTML and runs
	 * in the browser, so referral visits are captured even when the page cache
	 * suppresses PHP. The ajax endpoint does the counting and sets the cookie.
	 */
	public static function print_tracker() {
		?>
		<script>
		(function() {
			try {
				var params = new URLSearchParams( window.location.search );
				var code = params.get( 'ref' );
				if ( ! code ) { return; }
				code = code.toUpperCase().replace( /[^A-Z0-9\-_]/g, '' ).slice( 0, 64 );
				if ( ! code ) { return; }
				var seenKey = 'rd_ppg_t_' + code;
				if ( window.sessionStorage && sessionStorage.getItem( seenKey ) ) { return; }
				if ( window.sessionStorage ) { sessionStorage.setItem( seenKey, '1' ); }
				var body = new FormData();
				body.append( 'action', 'rd_ppg_track' );
				body.append( 'code', code );
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, {
					method: 'POST',
					credentials: 'same-origin',
					body: body
				} );
			} catch ( e ) {}
		})();
		</script>
		<?php
	}

	public static function ajax_track() {
		$code = isset( $_POST['code'] ) ? self::sanitize_code( wp_unslash( $_POST['code'] ) ) : '';
		if ( '' === $code ) {
			wp_send_json_error( null, 400 );
		}
		$match = RD_PPG_Partner::find_by_code( $code );
		if ( ! $match ) {
			wp_send_json_error( null, 404 );
		}

		if ( ! RD_PPG_Stats::is_bot() && ! self::visit_throttled( $match['code'] ) ) {
			RD_PPG_Stats::record_visit( $match['merchant_id'], $match['code'] );
		}

		if ( is_user_logged_in() ) {
			$user_id = get_current_user_id();
			if ( ! get_user_meta( $user_id, 'rd_ppg_merchant_id', true ) ) {
				$partner = RD_PPG_Partner::get( $match['merchant_id'] );
				if ( $partner && $partner['allow_existing_customers'] && self::tag_user( $user_id, $match ) ) {
					RD_PPG_Stats::record_signup( $match['merchant_id'], $match['code'] );
					if ( $partner['bonus_existing_customers'] ) {
						self::award_signup_bonus( $user_id, $match['merchant_id'] );
					}
				}
			}
		} else {
			$cookie = self::cookie_name();
			if ( empty( $_COOKIE[ $cookie ] ) ) {
				$lifetime = absint( RD_PPG_Settings::get( 'cookie_lifetime_days' ) ) * DAY_IN_SECONDS;
				setcookie( $cookie, $match['code'], time() + $lifetime, '/', '', is_ssl(), true );
			}
		}

		wp_send_json_success();
	}

	/**
	 * Blunts naive endpoint spam. One counted visit per ip and code per short window.
	 */
	private static function visit_throttled( $code ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
		}
		$key = 'rd_ppg_v_' . md5( $ip . '|' . $code );
		if ( get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, 15 );
		return false;
	}

	private static function cookie_code() {
		$cookie = self::cookie_name();
		if ( empty( $_COOKIE[ $cookie ] ) ) {
			return '';
		}
		return self::sanitize_code( wp_unslash( $_COOKIE[ $cookie ] ) );
	}

	public static function render_registration_code_field() {
		?>
		<p class="form-row form-row-wide rd-ppg-code-row">
			<label for="rd_ppg_partner_code"><?php esc_html_e( 'Partner code (optional)', 'rd-partner-program' ); ?></label>
			<input type="text" class="input-text" name="rd_ppg_partner_code" id="rd_ppg_partner_code" value="<?php echo esc_attr( self::cookie_code() ); ?>" placeholder="<?php esc_attr_e( 'Enter your code if you have one', 'rd-partner-program' ); ?>" style="text-transform: uppercase;" />
		</p>
		<script>
		(function() {
			// The registration form template prints its remaining fields after the
			// hook that outputs this field, so PHP hook priority cannot place it at
			// the end of the form. Move it there in the browser instead: directly
			// above the form's submit button.
			function rdPpgMoveCodeRow() {
				var row = document.querySelector( '.rd-ppg-code-row' );
				if ( ! row || ! row.parentNode ) { return; }
				var form = row.closest( 'form' );
				if ( ! form ) { return; }
				var submit = form.querySelector( 'button[type="submit"], input[type="submit"]' );
				if ( ! submit ) { return; }
				var anchor = submit.closest( 'p, div' ) || submit;
				if ( anchor.parentNode && anchor.previousElementSibling !== row ) {
					anchor.parentNode.insertBefore( row, anchor );
				}
			}
			if ( document.readyState === 'loading' ) {
				document.addEventListener( 'DOMContentLoaded', rdPpgMoveCodeRow );
			} else {
				rdPpgMoveCodeRow();
			}
		})();
		</script>
		<?php
	}

	public static function render_checkout_code_field( $checkout ) {
		if ( is_user_logged_in() && get_user_meta( get_current_user_id(), 'rd_ppg_merchant_id', true ) ) {
			return;
		}
		$value = $checkout->get_value( 'rd_ppg_partner_code' );
		if ( ! $value ) {
			$value = self::cookie_code();
		}
		woocommerce_form_field(
			'rd_ppg_partner_code',
			array(
				'type'        => 'text',
				'class'       => array( 'form-row-wide', 'rd-ppg-code-row' ),
				'label'       => __( 'Partner code (optional)', 'rd-partner-program' ),
				'placeholder' => __( 'Enter your code if you have one', 'rd-partner-program' ),
				'required'    => false,
			),
			$value
		);
	}

	public static function validate_checkout_code() {
		if ( empty( $_POST['rd_ppg_partner_code'] ) ) {
			return;
		}
		$code = self::sanitize_code( wp_unslash( $_POST['rd_ppg_partner_code'] ) );
		if ( '' !== $code && ! RD_PPG_Partner::find_by_code( $code ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: typed partner code */
					esc_html__( 'The partner code %s was not recognized. Check the spelling or remove it to continue.', 'rd-partner-program' ),
					esc_html( $code )
				),
				'error'
			);
		}
	}

	/**
	 * Covers the checkout paths tag_on_register cannot reach. A brand new account
	 * created at checkout is already tagged by user_register reading the same POST.
	 * This handles the pre existing logged in untagged customer, gated by the
	 * merchant toggle, and drops a cookie for guests so a later registration
	 * still attributes.
	 */
	public static function tag_from_checkout( $order_id, $posted_data, $order ) {
		if ( empty( $_POST['rd_ppg_partner_code'] ) ) {
			return;
		}
		$code  = self::sanitize_code( wp_unslash( $_POST['rd_ppg_partner_code'] ) );
		$match = RD_PPG_Partner::find_by_code( $code );
		if ( ! $match ) {
			return;
		}

		$user_id = $order ? $order->get_user_id() : 0;

		if ( ! $user_id ) {
			if ( empty( $_COOKIE[ self::cookie_name() ] ) && ! headers_sent() ) {
				$lifetime = absint( RD_PPG_Settings::get( 'cookie_lifetime_days' ) ) * DAY_IN_SECONDS;
				setcookie( self::cookie_name(), $match['code'], time() + $lifetime, '/', '', is_ssl(), true );
			}
			return;
		}

		if ( get_user_meta( $user_id, 'rd_ppg_merchant_id', true ) ) {
			return;
		}

		$partner = RD_PPG_Partner::get( $match['merchant_id'] );
		if ( $partner && $partner['allow_existing_customers'] && self::tag_user( $user_id, $match ) ) {
			RD_PPG_Stats::record_signup( $match['merchant_id'], $match['code'] );
			if ( $partner['bonus_existing_customers'] ) {
				self::award_signup_bonus( $user_id, $match['merchant_id'] );
			}
		}
	}

}
