<?php
/**
 * ReserveDaily Partner Program by Shariar Bijoy.
 *
 * "Affiliate Partner" box on the wp-admin user edit screen. Shows the
 * customer's tag, lets a manager tag an untagged customer to a partner by
 * hand (management approved cases only), and lets a manager pay the partner
 * welcome bonus to a tagged customer who has no live bonus (never received
 * it, or it expired). A manual tag behaves exactly like a link signup:
 * permanent, one partner per customer, perks window starts at the moment of
 * tagging, welcome bonus through the normal award path.
 *
 * @author Shariar Bijoy
 * @link https://shariarbijoy.dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RD_PPG_User_Profile {

	const NONCE  = 'rd_ppg_tag_user';
	const CAP    = 'manage_woocommerce';

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'show_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'handle' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'handle' ) );
	}

	public static function render( $user ) {
		if ( ! current_user_can( self::CAP ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Affiliate Partner', 'rd-partner-program' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$merchant_id = absint( get_user_meta( $user->ID, 'rd_ppg_merchant_id', true ) );
		if ( $merchant_id ) {
			self::render_tagged( $user->ID, $merchant_id );
		} else {
			self::render_untagged( $user->ID );
		}

		echo '</tbody></table>';
	}

	private static function render_tagged( $user_id, $merchant_id ) {
		$post    = get_post( $merchant_id );
		$partner = RD_PPG_Partner::get( $merchant_id );
		$name    = $post ? $post->post_title : sprintf( __( 'Partner #%d', 'rd-partner-program' ), $merchant_id );
		if ( $post && current_user_can( 'edit_post', $merchant_id ) ) {
			$name_html = '<a href="' . esc_url( get_edit_post_link( $merchant_id ) ) . '">' . esc_html( $name ) . '</a>';
		} else {
			$name_html = esc_html( $name );
		}
		if ( ! $post ) {
			$name_html .= ' <span class="description">' . esc_html__( '(partner deleted)', 'rd-partner-program' ) . '</span>';
		} elseif ( 'publish' !== $post->post_status ) {
			$name_html .= ' <span class="description">' . esc_html__( '(not published)', 'rd-partner-program' ) . '</span>';
		}

		$code   = (string) get_user_meta( $user_id, 'rd_ppg_code', true );
		$signup = (int) get_user_meta( $user_id, 'rd_ppg_signup_date', true );
		$source = 'manual' === get_user_meta( $user_id, 'rd_ppg_tag_source', true )
			? __( 'Tagged by hand in wp-admin', 'rd-partner-program' )
			: __( 'Referral link or code', 'rd-partner-program' );

		self::row( __( 'Tagged to', 'rd-partner-program' ), $name_html );
		self::row( __( 'Code', 'rd-partner-program' ), '' !== $code ? '<code>' . esc_html( $code ) . '</code>' : esc_html__( 'None', 'rd-partner-program' ) );
		self::row( __( 'Signup date', 'rd-partner-program' ), $signup ? esc_html( wp_date( get_option( 'date_format' ), $signup ) ) : esc_html__( 'Unknown', 'rd-partner-program' ) );
		self::row( __( 'How', 'rd-partner-program' ), esc_html( $source ) );

		if ( $partner ) {
			$end = RD_PPG_Partner::effective_window_end( $user_id, $partner );
			if ( $end ) {
				$state = time() <= $end ? __( 'perks active', 'rd-partner-program' ) : __( 'perks ended', 'rd-partner-program' );
				self::row( __( 'Perks window ends', 'rd-partner-program' ), esc_html( wp_date( get_option( 'date_format' ), $end ) . ', ' . $state ) );
			}
		}

		self::row( __( 'Welcome bonus', 'rd-partner-program' ), esc_html( self::bonus_status_text( $user_id ) ) );

		if ( $partner && $partner['signup_bonus'] > 0 && ! self::has_live_bonus( $user_id ) ) {
			wp_nonce_field( self::NONCE . '_' . $user_id, self::NONCE . '_nonce' );
			$again = '<label><input type="checkbox" name="rd_ppg_reaward_bonus" value="1"> '
				. sprintf( esc_html__( 'Pay the partner welcome bonus of %s points now', 'rd-partner-program' ), esc_html( number_format_i18n( $partner['signup_bonus'] ) ) ) . '</label>'
				. '<p class="description">' . esc_html__( 'This customer has no live welcome bonus (never received it, or it expired). Ticking this pays the partner welcome bonus with a fresh expiry when you press Update User. Check the myCRED log first so nobody is paid twice.', 'rd-partner-program' ) . '</p>';
			self::row( __( 'Pay welcome bonus', 'rd-partner-program' ), $again );
		}

		echo '<tr><th></th><td><p class="description">' . esc_html__( 'A customer belongs to one affiliate partner for good. The tag cannot be changed or removed from here.', 'rd-partner-program' ) . '</p></td></tr>';
	}

	private static function render_untagged( $user_id ) {
		$partners = self::taggable_partners();

		if ( ! $partners ) {
			self::row( __( 'Tagged to', 'rd-partner-program' ), esc_html__( 'None. No affiliate partner is currently open for tagging.', 'rd-partner-program' ) );
			return;
		}

		wp_nonce_field( self::NONCE . '_' . $user_id, self::NONCE . '_nonce' );

		$options = '<option value="">' . esc_html__( 'Do not tag', 'rd-partner-program' ) . '</option>';
		foreach ( $partners as $partner ) {
			$label = $partner['name'];
			if ( $partner['signup_bonus'] > 0 ) {
				$label .= sprintf( __( ' (welcome bonus %s points)', 'rd-partner-program' ), number_format_i18n( $partner['signup_bonus'] ) );
			}
			$options .= '<option value="' . esc_attr( $partner['id'] ) . '">' . esc_html( $label ) . '</option>';
		}

		$select = '<select name="rd_ppg_tag_partner" id="rd_ppg_tag_partner">' . $options . '</select>'
			. '<p class="description">' . esc_html__( 'Only for customers management has approved. Tagging is permanent, one partner per customer, and the perks window starts today. Past orders are not commissioned.', 'rd-partner-program' ) . '</p>';
		self::row( __( 'Tag to affiliate partner', 'rd-partner-program' ), $select, 'rd_ppg_tag_partner' );

		$bonus = '<label><input type="checkbox" name="rd_ppg_tag_bonus" value="1" checked> '
			. esc_html__( 'Award the partner welcome bonus now', 'rd-partner-program' ) . '</label>'
			. '<p class="description">' . esc_html__( 'Uses the partner welcome bonus amount and expiry, paid into Welcome Credits when that point type exists. Untick if the customer already received it by hand.', 'rd-partner-program' ) . '</p>';
		self::row( __( 'Welcome bonus', 'rd-partner-program' ), $bonus );
	}

	/**
	 * Published, active partners whose deal has not ended. Same gate as
	 * find_by_code(), so a manual tag can never reach a partner a link cannot.
	 */
	private static function taggable_partners() {
		$out = array();
		foreach ( RD_PPG_Partner::all_active() as $partner ) {
			$deal_end = RD_PPG_Partner::deal_end_ts( $partner );
			if ( $deal_end && time() > $deal_end ) {
				continue;
			}
			if ( '' === (string) $partner['parent_code'] ) {
				continue;
			}
			$out[] = $partner;
		}
		return $out;
	}

	/**
	 * A live bonus means: with the Welcome Credits type, an expiry stamp still
	 * in the future; without it (legacy single type), the awarded flag.
	 */
	private static function has_live_bonus( $user_id ) {
		if ( '' !== RD_PPG_Settings::welcome_type() ) {
			return (int) get_user_meta( $user_id, 'rd_ppg_welcome_expires_at', true ) > time();
		}
		return 'yes' === get_user_meta( $user_id, 'rd_ppg_signup_bonus_awarded', true );
	}

	private static function bonus_status_text( $user_id ) {
		$awarded = 'yes' === get_user_meta( $user_id, 'rd_ppg_signup_bonus_awarded', true );
		if ( ! $awarded ) {
			return __( 'Not awarded', 'rd-partner-program' );
		}
		if ( '' === RD_PPG_Settings::welcome_type() ) {
			return __( 'Awarded (into Vital Points, Welcome Credits type not set up)', 'rd-partner-program' );
		}
		$expires = (int) get_user_meta( $user_id, 'rd_ppg_welcome_expires_at', true );
		if ( $expires > time() ) {
			return sprintf( __( 'Awarded, expires %s', 'rd-partner-program' ), wp_date( get_option( 'date_format' ), $expires ) );
		}
		return __( 'Awarded earlier, now expired', 'rd-partner-program' );
	}

	/**
	 * Runs on profile save. Does nothing unless the tag dropdown or the pay
	 * bonus checkbox was used.
	 */
	public static function handle( $user_id ) {
		$wants_tag    = ! empty( $_POST['rd_ppg_tag_partner'] );
		$wants_reward = ! empty( $_POST['rd_ppg_reaward_bonus'] );
		if ( ! $wants_tag && ! $wants_reward ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		$nonce = isset( $_POST[ self::NONCE . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE . '_' . $user_id ) ) {
			return;
		}

		$existing = absint( get_user_meta( $user_id, 'rd_ppg_merchant_id', true ) );

		if ( $existing ) {
			if ( $wants_reward ) {
				self::pay_bonus_again( $user_id, $existing );
			} elseif ( $wants_tag ) {
				self::notice( __( 'Tagging skipped: this customer is already tagged to an affiliate partner.', 'rd-partner-program' ), 'error' );
			}
			return;
		}

		if ( ! $wants_tag ) {
			return;
		}

		$partner_id = absint( $_POST['rd_ppg_tag_partner'] );
		$partner    = null;
		foreach ( self::taggable_partners() as $candidate ) {
			if ( (int) $candidate['id'] === $partner_id ) {
				$partner = $candidate;
				break;
			}
		}
		if ( ! $partner ) {
			self::notice( __( 'Tagging skipped: that affiliate partner is not open for tagging.', 'rd-partner-program' ), 'error' );
			return;
		}

		$match = array(
			'merchant_id' => $partner['id'],
			'parent_code' => $partner['parent_code'],
			'code'        => $partner['parent_code'],
		);
		if ( ! RD_PPG_Referral::tag_user( $user_id, $match ) ) {
			self::notice( __( 'Tagging failed. Reload the page and check the tag.', 'rd-partner-program' ), 'error' );
			return;
		}
		update_user_meta( $user_id, 'rd_ppg_tag_source', 'manual' );
		update_user_meta( $user_id, 'rd_ppg_tagged_by', get_current_user_id() );
		RD_PPG_Stats::record_signup( $partner['id'], $partner['parent_code'] );

		$message = sprintf( __( 'Customer tagged to %s. Perks window starts today.', 'rd-partner-program' ), $partner['name'] );

		if ( ! empty( $_POST['rd_ppg_tag_bonus'] ) && $partner['signup_bonus'] > 0 ) {
			// The manager ticked the box, so a stale "awarded" flag from an
			// earlier removed tag must not block the payment.
			delete_user_meta( $user_id, 'rd_ppg_signup_bonus_awarded' );
			$message .= ' ' . self::award_message( RD_PPG_Referral::award_signup_bonus( $user_id, $partner['id'] ), $partner['signup_bonus'] );
		}

		self::notice( $message, 'success' );
	}

	private static function pay_bonus_again( $user_id, $merchant_id ) {
		$partner = RD_PPG_Partner::get( $merchant_id );
		if ( ! $partner || $partner['signup_bonus'] <= 0 ) {
			self::notice( __( 'Welcome bonus skipped: the partner is not published or has no welcome bonus.', 'rd-partner-program' ), 'error' );
			return;
		}
		if ( self::has_live_bonus( $user_id ) ) {
			self::notice( __( 'Welcome bonus skipped: this customer already has a live welcome bonus.', 'rd-partner-program' ), 'error' );
			return;
		}
		delete_user_meta( $user_id, 'rd_ppg_signup_bonus_awarded' );
		$paid = RD_PPG_Referral::award_signup_bonus( $user_id, $merchant_id );
		self::notice( self::award_message( $paid, $partner['signup_bonus'] ), $paid ? 'success' : 'error' );
	}

	private static function award_message( $paid, $points ) {
		if ( $paid ) {
			return sprintf( __( 'Welcome bonus of %s points awarded.', 'rd-partner-program' ), number_format_i18n( $points ) );
		}
		return __( 'Welcome bonus was not awarded (myCRED missing, or the user is excluded in myCRED).', 'rd-partner-program' );
	}

	private static function row( $label, $html, $for = '' ) {
		$label_html = '' !== $for ? '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label );
		echo '<tr><th scope="row">' . $label_html . '</th><td>' . $html . '</td></tr>';
	}

	/**
	 * Same transient the partner screens use, so RD_PPG_Partner_CPT::code_notices()
	 * prints it after the profile save redirect.
	 */
	private static function notice( $message, $type ) {
		set_transient( 'rd_ppg_admin_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
	}
}
