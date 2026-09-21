<?php
/**
 * ReserveDaily Partner Program by Shariar Bijoy.
 *
 * "Affiliate Partner" column and filter on the wp-admin Users list. Read only:
 * shows which partner a customer is tagged to, the code they came in with, and
 * the signup date. Nothing here writes data.
 *
 * @author Shariar Bijoy
 * @link https://shariarbijoy.dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RD_PPG_Users_Column {

	const COLUMN = 'rd_ppg_partner';
	const FILTER = 'rd_ppg_partner';

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column_content' ), 10, 3 );
		add_action( 'restrict_manage_users', array( __CLASS__, 'filter_dropdown' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'apply_filter' ) );
		add_action( 'admin_head-users.php', array( __CLASS__, 'column_styles' ) );
	}

	public static function add_column( $columns ) {
		$columns[ self::COLUMN ] = __( 'Affiliate Partner', 'rd-partner-program' );
		return $columns;
	}

	public static function column_content( $output, $column_name, $user_id ) {
		if ( self::COLUMN !== $column_name ) {
			return $output;
		}

		$merchant_id = absint( get_user_meta( $user_id, 'rd_ppg_merchant_id', true ) );
		if ( ! $merchant_id ) {
			return '<span class="rd-ppg-muted">' . esc_html__( 'None', 'rd-partner-program' ) . '</span>';
		}

		$post = get_post( $merchant_id );
		if ( ! $post || RD_PPG_Partner::CPT !== $post->post_type ) {
			return '<span class="rd-ppg-muted">' . sprintf( esc_html__( 'Partner #%d (deleted)', 'rd-partner-program' ), $merchant_id ) . '</span>';
		}

		$name = $post->post_title ? $post->post_title : sprintf( __( 'Partner #%d', 'rd-partner-program' ), $merchant_id );
		if ( current_user_can( 'edit_post', $merchant_id ) ) {
			$html = '<a href="' . esc_url( get_edit_post_link( $merchant_id ) ) . '"><strong>' . esc_html( $name ) . '</strong></a>';
		} else {
			$html = '<strong>' . esc_html( $name ) . '</strong>';
		}
		if ( 'publish' !== $post->post_status ) {
			$html .= ' <span class="rd-ppg-muted">(' . esc_html__( 'not published', 'rd-partner-program' ) . ')</span>';
		}

		$details = array();
		$code    = (string) get_user_meta( $user_id, 'rd_ppg_code', true );
		if ( '' !== $code ) {
			$details[] = sprintf( esc_html__( 'Code %s', 'rd-partner-program' ), '<code>' . esc_html( $code ) . '</code>' );
		}
		$signup = (int) get_user_meta( $user_id, 'rd_ppg_signup_date', true );
		if ( $signup > 0 ) {
			$details[] = esc_html( wp_date( get_option( 'date_format' ), $signup ) );
		}
		if ( 'publish' === $post->post_status && class_exists( 'RD_PPG_Partner' ) ) {
			$partner = RD_PPG_Partner::get( $merchant_id );
			if ( $partner ) {
				$details[] = RD_PPG_Partner::user_in_window( $user_id, $partner )
					? esc_html__( 'perks active', 'rd-partner-program' )
					: esc_html__( 'perks ended', 'rd-partner-program' );
			}
		}
		if ( $details ) {
			$html .= '<br><span class="rd-ppg-muted">' . implode( ', ', $details ) . '</span>';
		}

		return $html;
	}

	/**
	 * Dropdown next to the role selector: every partner (any status) plus
	 * "any" and "none" choices. WordPress renders this twice (top and bottom of
	 * the list) inside one form, so each copy gets its own field name and
	 * button name; requested_filter() reads the copy whose button was pressed.
	 */
	public static function filter_dropdown( $which = 'top' ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}
		$partners = get_posts(
			array(
				'post_type'      => RD_PPG_Partner::CPT,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);
		if ( ! $partners ) {
			return;
		}
		$current = self::requested_filter();
		$pos     = 'bottom' === $which ? 'bottom' : 'top';
		$name    = self::field_name( $pos );
		$id      = 'rd_ppg_partner_filter_' . $pos;
		echo '<label class="screen-reader-text" for="' . esc_attr( $id ) . '">' . esc_html__( 'Filter by affiliate partner', 'rd-partner-program' ) . '</label>';
		echo '<select name="' . esc_attr( $name ) . '" id="' . esc_attr( $id ) . '" style="margin-right:6px">';
		echo '<option value="">' . esc_html__( 'Any affiliate partner status', 'rd-partner-program' ) . '</option>';
		echo '<option value="any"' . selected( 'any', $current, false ) . '>' . esc_html__( 'Tagged to any partner', 'rd-partner-program' ) . '</option>';
		echo '<option value="none"' . selected( 'none', $current, false ) . '>' . esc_html__( 'Not tagged', 'rd-partner-program' ) . '</option>';
		foreach ( $partners as $partner_id ) {
			$title = get_the_title( $partner_id );
			if ( 'publish' !== get_post_status( $partner_id ) ) {
				$title .= ' (' . __( 'not published', 'rd-partner-program' ) . ')';
			}
			echo '<option value="' . esc_attr( $partner_id ) . '"' . selected( (string) $partner_id, $current, false ) . '>' . esc_html( $title ) . '</option>';
		}
		echo '</select>';
		submit_button( __( 'Filter', 'rd-partner-program' ), 'secondary', 'rd_ppg_filter_' . $pos, false );
	}

	private static function field_name( $pos ) {
		return 'bottom' === $pos ? self::FILTER . '_bottom' : self::FILTER;
	}

	/**
	 * Narrows the Users list query when the partner filter is set. Touches the
	 * query only on the admin users screen and only when the parameter is
	 * present, so every other user query on the site is untouched.
	 */
	public static function apply_filter( $query ) {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'users' !== $screen->id ) {
			return;
		}
		$current = self::requested_filter();
		if ( '' === $current ) {
			return;
		}

		if ( 'any' === $current ) {
			$clause = array(
				'key'     => 'rd_ppg_merchant_id',
				'value'   => '',
				'compare' => '!=',
			);
		} elseif ( 'none' === $current ) {
			$clause = array(
				'relation' => 'OR',
				array(
					'key'     => 'rd_ppg_merchant_id',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'rd_ppg_merchant_id',
					'value'   => '',
					'compare' => '=',
				),
			);
		} else {
			$clause = array(
				'key'   => 'rd_ppg_merchant_id',
				'value' => absint( $current ),
			);
		}

		$existing = $query->get( 'meta_query' );
		if ( is_array( $existing ) && array_filter( $existing ) ) {
			$query->set( 'meta_query', array( 'relation' => 'AND', $existing, $clause ) );
		} else {
			$query->set( 'meta_query', array( $clause ) );
		}
	}

	/**
	 * The bottom copy wins only when its own Filter button was pressed; the
	 * top copy (plain rd_ppg_partner) is what pagination and search links carry.
	 */
	private static function requested_filter() {
		$field = isset( $_GET['rd_ppg_filter_bottom'] ) ? self::field_name( 'bottom' ) : self::field_name( 'top' );
		if ( ! isset( $_GET[ $field ] ) ) {
			return '';
		}
		$raw = sanitize_text_field( wp_unslash( $_GET[ $field ] ) );
		if ( 'any' === $raw || 'none' === $raw ) {
			return $raw;
		}
		$id = absint( $raw );
		return $id > 0 ? (string) $id : '';
	}

	public static function column_styles() {
		echo '<style>.column-rd_ppg_partner{width:16%}.rd-ppg-muted{color:#646970;font-size:12px}</style>';
	}
}
