<?php
/**
 * Teljes eltávolítás: táblák és opciók törlése.
 * Csak akkor fut, ha a felhasználó kifejezetten törli a plugint.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fsa_sales_daily" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}fsa_orders_summary" );

delete_option( 'fsa_db_version' );
delete_option( 'fsa_backfill_done' );
delete_option( 'fsa_backfill_cursor' );
