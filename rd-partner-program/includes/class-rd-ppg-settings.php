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

class RD_PPG_Settings {

	const OPTION = 'rd_ppg_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 70 );
		add_action( 'admin_post_rd_ppg_save_settings', array( __CLASS__, 'save' ) );
	}

	public static function defaults() {
		return array(
			'point_type'               => 'mycred_default',
			'welcome_point_type'       => 'mycred_welcome',
			'points_per_rm'            => 100,
			'rank_percentages'         => array(
				'vital_core'  => 1.5,
				'vital_plus'  => 2.0,
				'vital_elite' => 2.5,
			),
			'cookie_lifetime_days'     => 90,
			'cookie_name'              => 'rd_ppg_ref',
			'typed_code_fields'        => false,
			'points_expiry_months'     => 3,
			'standard_redemption_cap'  => 5,
			'signup_bonus_expiry_days' => 30,
			'rm50_stack_partner'       => false,
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * The Welcome Credit point type key, or empty string when the type does not
	 * exist in myCRED yet. Callers fall back to legacy single-type behavior on
	 * empty, so the plugin stays safe on sites without the type (production
	 * before go-live setup).
	 */
	public static function welcome_type() {
		$key = (string) self::get( 'welcome_point_type' );
		if ( '' === $key || ! function_exists( 'mycred_get_types' ) ) {
			return '';
		}
		$types = (array) mycred_get_types( true );
		return array_key_exists( $key, $types ) ? $key : '';
	}

	/**
	 * Returns the full settings array, or one key when $key is given.
	 */
	public static function get( $key = null ) {
		$defaults = self::defaults();
		$saved    = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$settings = wp_parse_args( $saved, $defaults );

		$ranks = isset( $saved['rank_percentages'] ) && is_array( $saved['rank_percentages'] ) ? $saved['rank_percentages'] : array();
		$settings['rank_percentages'] = wp_parse_args( $ranks, $defaults['rank_percentages'] );

		// The welcome bonus expiry fallback was a visible setting in 1.x and can
		// still be stored as blank or 0 from that time. Zero days would stamp the
		// welcome credit as expired at the moment it is awarded (2.3.1 fix).
		if ( (int) $settings['signup_bonus_expiry_days'] <= 0 ) {
			$settings['signup_bonus_expiry_days'] = $defaults['signup_bonus_expiry_days'];
		}

		if ( null === $key ) {
			return $settings;
		}
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	public static function register_menu() {
		add_submenu_page(
			'edit.php?post_type=rd_partner',
			__( 'Partner Program Settings', 'rd-partner-program' ),
			__( 'Partner Settings', 'rd-partner-program' ),
			'manage_woocommerce',
			'rd-ppg-settings',
			array( __CLASS__, 'render' )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'rd-partner-program' ) );
		}
		$s = self::get();
		?>
		<div class="wrap rd-ppg-admin">
			<style>
				.rd-ppg-admin h1 { border-left: 4px solid #085E83; padding-left: 10px; }
				.rd-ppg-admin .button-primary { background: #085E83; border-color: #085E83; }
				.rd-ppg-admin h2.section-heading { margin: 28px 0 10px; padding: 8px 0; border-bottom: 1px solid #ddd; font-size: 16px; }
			</style>
			<h1><?php esc_html_e( 'Partner Program Settings', 'rd-partner-program' ); ?></h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'rd-partner-program' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rd_ppg_save_settings" />
				<?php wp_nonce_field( 'rd_ppg_save_settings' ); ?>

				<h2 class="section-heading"><?php esc_html_e( 'Points', 'rd-partner-program' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rd_ppg_points_per_rm"><?php esc_html_e( 'Points per RM1', 'rd-partner-program' ); ?></label></th>
						<td>
							<input name="points_per_rm" id="rd_ppg_points_per_rm" type="number" step="1" min="1" value="<?php echo esc_attr( $s['points_per_rm'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Default 100, meaning 100 points equal RM1.', 'rd-partner-program' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Rank earn percentages', 'rd-partner-program' ); ?></th>
						<td>
							<?php foreach ( $s['rank_percentages'] as $rank_key => $percent ) : ?>
								<p>
									<label>
										<code><?php echo esc_html( $rank_key ); ?></code>
										<input name="rank_percentages[<?php echo esc_attr( $rank_key ); ?>]" type="number" step="0.1" min="0" value="<?php echo esc_attr( $percent ); ?>" /> %
									</label>
								</p>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Rank is read from user meta uk_wellness_rank. Unknown ranks fall back to vital_core.', 'rd-partner-program' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="rd_ppg_expiry_months"><?php esc_html_e( 'Points expiry months', 'rd-partner-program' ); ?></label></th>
						<td>
							<input name="points_expiry_months" id="rd_ppg_expiry_months" type="number" step="1" min="1" max="24" value="<?php echo esc_attr( $s['points_expiry_months'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Order points expire at the end of the Nth month after they are earned. Default 3 (e.g. earned in July, expires September 30).', 'rd-partner-program' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="section-heading"><?php esc_html_e( 'Redemption and Welcome Offer', 'rd-partner-program' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rd_ppg_standard_cap"><?php esc_html_e( 'Standard redemption cap, percent', 'rd-partner-program' ); ?></label></th>
						<td>
							<input name="standard_redemption_cap" id="rd_ppg_standard_cap" type="number" step="1" min="0" max="100" value="<?php echo esc_attr( $s['standard_redemption_cap'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Max percent of cart payable with points for everyone. This is the only place to manage it: the plugin overrides the myCRED partial payment max at checkout, so the percent stored in myCRED settings can be ignored. Partner perks are set on each partner edit screen and only lift this for tagged customers.', 'rd-partner-program' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'RM50 welcome code stacking', 'rd-partner-program' ); ?></th>
						<td>
							<label>
								<input name="rm50_stack_partner" type="checkbox" value="1" <?php checked( ! empty( $s['rm50_stack_partner'] ) ); ?> />
								<?php esc_html_e( 'Allow the RM50 welcome offer to combine with partner discounts on the same order. Off means the partner discount wins and RM50 steps aside.', 'rd-partner-program' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Read by the welcome offer snippet on the server. Flip it any time, no code changes needed.', 'rd-partner-program' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="section-heading"><?php esc_html_e( 'Referral', 'rd-partner-program' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="rd_ppg_cookie_lifetime"><?php esc_html_e( 'Referral cookie lifetime, days', 'rd-partner-program' ); ?></label></th>
						<td><input name="cookie_lifetime_days" id="rd_ppg_cookie_lifetime" type="number" step="1" min="1" value="<?php echo esc_attr( $s['cookie_lifetime_days'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Typed partner code fields', 'rd-partner-program' ); ?></th>
						<td>
							<label>
								<input name="typed_code_fields" type="checkbox" value="1" <?php checked( ! empty( $s['typed_code_fields'] ) ); ?> />
								<?php esc_html_e( 'Show a "Partner code (optional)" field on the registration form and at checkout so customers can type a code by hand.', 'rd-partner-program' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off means the referral link is the only way to join a partner program. Tagging through links, QR codes, and the referral cookie is unaffected.', 'rd-partner-program' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="section-heading"><?php esc_html_e( 'Other', 'rd-partner-program' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall', 'rd-partner-program' ); ?></th>
						<td>
							<label>
								<input name="delete_data_on_uninstall" type="checkbox" value="1" <?php checked( ! empty( $s['delete_data_on_uninstall'] ) ); ?> />
								<?php esc_html_e( 'Delete all plugin data when the plugin is uninstalled. Off by default.', 'rd-partner-program' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Settings', 'rd-partner-program' ) ); ?>
			</form>
			<p class="description" style="margin-top: 24px; border-top: 1px solid #ddd; padding-top: 12px;">
				<?php echo wp_kses_post( __( 'ReserveDaily Partner Program, developed by <a href="https://shariarbijoy.dev" target="_blank" rel="noopener">Shariar Bijoy</a>.', 'rd-partner-program' ) ); ?>
			</p>
		</div>
		<?php
	}

	public static function save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'rd-partner-program' ) );
		}
		check_admin_referer( 'rd_ppg_save_settings' );

		$defaults = self::defaults();
		$settings = self::get();

		$settings['points_per_rm']        = isset( $_POST['points_per_rm'] ) ? max( 1, absint( $_POST['points_per_rm'] ) ) : $defaults['points_per_rm'];
		$settings['cookie_lifetime_days'] = isset( $_POST['cookie_lifetime_days'] ) ? max( 1, absint( $_POST['cookie_lifetime_days'] ) ) : $defaults['cookie_lifetime_days'];

		$settings['points_expiry_months']    = isset( $_POST['points_expiry_months'] ) ? max( 1, min( 24, absint( $_POST['points_expiry_months'] ) ) ) : $defaults['points_expiry_months'];
		$settings['standard_redemption_cap'] = isset( $_POST['standard_redemption_cap'] ) ? max( 0, min( 100, absint( $_POST['standard_redemption_cap'] ) ) ) : $defaults['standard_redemption_cap'];
		$settings['rm50_stack_partner']      = ! empty( $_POST['rm50_stack_partner'] );
		$settings['typed_code_fields']       = ! empty( $_POST['typed_code_fields'] );

		if ( empty( $settings['point_type'] ) ) {
			$settings['point_type'] = $defaults['point_type'];
		}
		if ( empty( $settings['cookie_name'] ) ) {
			$settings['cookie_name'] = $defaults['cookie_name'];
		}

		$ranks = array();
		if ( isset( $_POST['rank_percentages'] ) && is_array( $_POST['rank_percentages'] ) ) {
			foreach ( wp_unslash( $_POST['rank_percentages'] ) as $rank_key => $percent ) {
				$ranks[ sanitize_key( $rank_key ) ] = round( max( 0, floatval( $percent ) ), 2 );
			}
		}
		$settings['rank_percentages'] = wp_parse_args( $ranks, $defaults['rank_percentages'] );

		$settings['delete_data_on_uninstall'] = ! empty( $_POST['delete_data_on_uninstall'] );

		update_option( self::OPTION, $settings );

		wp_safe_redirect( add_query_arg( array( 'post_type' => 'rd_partner', 'page' => 'rd-ppg-settings', 'updated' => 1 ), admin_url( 'edit.php' ) ) );
		exit;
	}
}
