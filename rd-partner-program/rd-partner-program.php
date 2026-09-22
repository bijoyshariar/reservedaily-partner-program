<?php
/**
 * Plugin Name: ReserveDaily Partner Program
 * Plugin URI: https://shariarbijoy.dev
 * Description: Affiliate partner referral programs for WooCommerce. Partner tagging, referral traffic and signup tracking, automatic category and product discounts, myCRED points multipliers, and commission tracking with manual refund settlement. Developed by Shariar Bijoy.
 * Version: 2.3.1
 * Author: Shariar Bijoy
 * Author URI: https://shariarbijoy.dev
 * Text Domain: rd-partner-program
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @author Shariar Bijoy
 * @link https://shariarbijoy.dev
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RD_PPG_VERSION', '2.3.1' );
define( 'RD_PPG_FILE', __FILE__ );
define( 'RD_PPG_DIR', plugin_dir_path( __FILE__ ) );

require_once RD_PPG_DIR . 'includes/class-rd-ppg-install.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-settings.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-partner.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-partner-cpt.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-stats.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-referral.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-eligibility.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-discounts.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-points.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-commission.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-reports.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-merchant-dashboard.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-redemption.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-ledger.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-expiry.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-users-column.php';
require_once RD_PPG_DIR . 'includes/class-rd-ppg-user-profile.php';

register_activation_hook( __FILE__, array( 'RD_PPG_Install', 'activate' ) );

add_action( 'plugins_loaded', 'rd_ppg_init' );

function rd_ppg_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'rd_ppg_wc_missing_notice' );
		return;
	}
	RD_PPG_Install::maybe_upgrade();
	RD_PPG_Partner_CPT::init();
	RD_PPG_Settings::init();
	RD_PPG_Referral::init();
	RD_PPG_Discounts::init();
	RD_PPG_Points::init();
	RD_PPG_Commission::init();
	RD_PPG_Reports::init();
	RD_PPG_Merchant_Dashboard::init();
	RD_PPG_Redemption::init();
	RD_PPG_Expiry::init();
	RD_PPG_Users_Column::init();
	RD_PPG_User_Profile::init();
}

function rd_ppg_wc_missing_notice() {
	echo '<div class="notice notice-error"><p>' . esc_html__( 'ReserveDaily Partner Program requires WooCommerce to be installed and active.', 'rd-partner-program' ) . '</p></div>';
}

add_filter( 'plugin_row_meta', 'rd_ppg_plugin_row_meta', 10, 2 );

function rd_ppg_plugin_row_meta( $links, $file ) {
	if ( plugin_basename( RD_PPG_FILE ) === $file ) {
		$links[] = '<a href="https://shariarbijoy.dev" target="_blank" rel="noopener">' . esc_html__( 'Developed by Shariar Bijoy', 'rd-partner-program' ) . '</a>';
	}
	return $links;
}

add_filter( 'admin_footer_text', 'rd_ppg_admin_footer_brand', 20 );

function rd_ppg_admin_footer_brand( $text ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) {
		return $text;
	}
	$is_plugin_screen = 'rd_partner' === $screen->post_type || false !== strpos( (string) $screen->id, 'rd-ppg' );
	if ( $is_plugin_screen ) {
		return wp_kses_post( __( 'ReserveDaily Partner Program, developed by <a href="https://shariarbijoy.dev" target="_blank" rel="noopener">Shariar Bijoy</a>.', 'rd-partner-program' ) );
	}
	return $text;
}
