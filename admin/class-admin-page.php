<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menüpont és a React dashboard betöltése.
 */
class FSA_Admin_Page {

	const SLUG = 'forme-sales-analytics';

	public function register_menu() {
		add_menu_page(
			'Forme Analytics',
			'Forme Analytics',
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-chart-area',
			56
		);
	}

	public function render() {
		echo '<div class="wrap"><div id="fsa-root"></div></div>';
	}

	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$asset_file = FSA_PATH . 'admin/dist/app.js';
		$version    = file_exists( $asset_file ) ? filemtime( $asset_file ) : FSA_VERSION;

		wp_enqueue_script(
			'fsa-app',
			FSA_URL . 'admin/dist/app.js',
			array(),
			$version,
			true
		);

		wp_enqueue_style(
			'fsa-app',
			FSA_URL . 'admin/dist/app.css',
			array(),
			$version
		);

		wp_localize_script( 'fsa-app', 'FSA_DATA', array(
			'restUrl' => esc_url_raw( rest_url( 'forme-analytics/v1/' ) ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		) );
	}
}
