<?php
/**
 * Plugin Name:       Forme Sales Analytics
 * Description:        Árbevétel- és eladás-analitika a forme.hu WooCommerce áruházhoz. Aggregált napi adatok, interaktív React dashboard, AOV/bundle insightok.
 * Version:           1.0.0
 * Author:            Szoki
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 * Text Domain:       forme-sales-analytics
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FSA_VERSION', '1.0.0' );
define( 'FSA_FILE', __FILE__ );
define( 'FSA_PATH', plugin_dir_path( __FILE__ ) );
define( 'FSA_URL', plugin_dir_url( __FILE__ ) );

require_once FSA_PATH . 'includes/class-installer.php';
require_once FSA_PATH . 'includes/class-aggregator.php';
require_once FSA_PATH . 'includes/class-backfill.php';
require_once FSA_PATH . 'includes/class-rest-api.php';
require_once FSA_PATH . 'admin/class-admin-page.php';

/**
 * Aktiváláskor: táblák létrehozása + cron időzítés.
 */
function fsa_activate() {
	FSA_Installer::create_tables();
	if ( ! wp_next_scheduled( 'fsa_daily_aggregate' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', 'fsa_daily_aggregate' );
	}
	if ( false === get_option( 'fsa_backfill_done' ) ) {
		add_option( 'fsa_backfill_done', 0 );
	}
}
register_activation_hook( __FILE__, 'fsa_activate' );

/**
 * Deaktiváláskor: cron leállítása (az adatok megmaradnak).
 */
function fsa_deactivate() {
	$timestamp = wp_next_scheduled( 'fsa_daily_aggregate' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'fsa_daily_aggregate' );
	}
}
register_deactivation_hook( __FILE__, 'fsa_deactivate' );

/**
 * Indítás: csak ha a WooCommerce aktív.
 */
function fsa_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Forme Sales Analytics</strong>: a WooCommerce nincs aktiválva, a plugin nem fut.</p></div>';
		} );
		return;
	}

	$aggregator = new FSA_Aggregator();
	add_action( 'fsa_daily_aggregate', array( $aggregator, 'run_daily' ) );
	add_action( 'woocommerce_order_status_changed', array( $aggregator, 'on_status_changed' ), 10, 4 );

	$rest = new FSA_Rest_API();
	add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

	if ( is_admin() ) {
		$admin = new FSA_Admin_Page();
		add_action( 'admin_menu', array( $admin, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_assets' ) );
	}
}
add_action( 'plugins_loaded', 'fsa_init' );
