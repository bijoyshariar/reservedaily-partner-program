<?php
/**
 * ReserveDaily Partner Program by Shariar Bijoy.
 *
 * @author Shariar Bijoy
 * @link https://shariarbijoy.dev
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$rd_ppg_settings = get_option( 'rd_ppg_settings', array() );
if ( empty( $rd_ppg_settings['delete_data_on_uninstall'] ) ) {
	return;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rd_ppg_commission" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rd_ppg_stats" );

$rd_ppg_partner_ids = get_posts(
	array(
		'post_type'      => 'rd_partner',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $rd_ppg_partner_ids as $rd_ppg_partner_id ) {
	wp_delete_post( $rd_ppg_partner_id, true );
}

delete_metadata( 'user', 0, 'rd_ppg_merchant_id', '', true );
delete_metadata( 'user', 0, 'rd_ppg_parent_code', '', true );
delete_metadata( 'user', 0, 'rd_ppg_code', '', true );
delete_metadata( 'user', 0, 'rd_ppg_signup_date', '', true );
delete_metadata( 'user', 0, 'rd_ppg_signup_bonus_awarded', '', true );

delete_option( 'rd_ppg_settings' );
delete_option( 'rd_ppg_db_version' );
