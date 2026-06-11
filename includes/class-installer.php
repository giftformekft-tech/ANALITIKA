<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adatbázis táblák létrehozása.
 *
 * Két aggregált tábla:
 *  - fsa_sales_daily:   napi + termékszintű bontás (árbevétel, db, kategória)
 *  - fsa_orders_summary: rendelésenként 1 sor (AOV, darabszám-eloszlás, kupon)
 */
class FSA_Installer {

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$daily = $wpdb->prefix . 'fsa_sales_daily';
		$sql_daily = "CREATE TABLE {$daily} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			day DATE NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_name VARCHAR(255) NOT NULL DEFAULT '',
			category_name VARCHAR(255) NOT NULL DEFAULT '',
			qty INT NOT NULL DEFAULT 0,
			revenue_gross DECIMAL(14,2) NOT NULL DEFAULT 0,
			revenue_net DECIMAL(14,2) NOT NULL DEFAULT 0,
			discount DECIMAL(14,2) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			UNIQUE KEY day_product (day, product_id),
			KEY day_idx (day),
			KEY category_idx (category_id)
		) {$charset_collate};";

		$orders = $wpdb->prefix . 'fsa_orders_summary';
		$sql_orders = "CREATE TABLE {$orders} (
			order_id BIGINT UNSIGNED NOT NULL,
			day DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT '',
			total DECIMAL(14,2) NOT NULL DEFAULT 0,
			total_net DECIMAL(14,2) NOT NULL DEFAULT 0,
			shipping DECIMAL(14,2) NOT NULL DEFAULT 0,
			discount DECIMAL(14,2) NOT NULL DEFAULT 0,
			items_count INT NOT NULL DEFAULT 0,
			coupon VARCHAR(191) NOT NULL DEFAULT '',
			is_returning TINYINT(1) NOT NULL DEFAULT 0,
			billing_email VARCHAR(191) NOT NULL DEFAULT '',
			PRIMARY KEY (order_id),
			KEY day_idx (day),
			KEY status_idx (status),
			KEY coupon_idx (coupon)
		) {$charset_collate};";

		dbDelta( $sql_daily );
		dbDelta( $sql_orders );

		update_option( 'fsa_db_version', FSA_VERSION );
	}
}
