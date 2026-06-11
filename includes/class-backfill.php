<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Visszamenőleges feltöltés a meglévő rendelésekből.
 *
 * Naponként, kötegekben dolgozik, hogy ne fusson timeoutba nagy adatmennyiségnél.
 * A haladást a 'fsa_backfill_cursor' opció tárolja (a következő feldolgozandó nap).
 */
class FSA_Backfill {

	const DAYS_PER_BATCH = 7;

	/**
	 * A legkorábbi rendelés dátuma (a tartomány kezdete).
	 */
	public function earliest_order_date() {
		global $wpdb;

		$hpos = $wpdb->prefix . 'wc_orders';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$hpos}'" ) === $hpos ) {
			$d = $wpdb->get_var(
				"SELECT DATE(date_created_gmt) FROM {$hpos}
				 WHERE type='shop_order' AND date_created_gmt IS NOT NULL
				 ORDER BY date_created_gmt ASC LIMIT 1"
			);
			if ( $d ) {
				return $d;
			}
		}

		$d = $wpdb->get_var(
			"SELECT DATE(post_date) FROM {$wpdb->posts}
			 WHERE post_type='shop_order'
			 ORDER BY post_date ASC LIMIT 1"
		);
		return $d ?: null;
	}

	/**
	 * Egy köteg feldolgozása. Visszaadja a haladást.
	 */
	public function process_batch() {
		$start = get_option( 'fsa_backfill_cursor', '' );
		$today = wp_date( 'Y-m-d', current_time( 'timestamp' ) );

		if ( empty( $start ) ) {
			$start = $this->earliest_order_date();
			if ( ! $start ) {
				update_option( 'fsa_backfill_done', 1 );
				return array( 'done' => true, 'processed_until' => $today, 'progress' => 100, 'message' => 'Nincs feldolgozható rendelés.' );
			}
		}

		$aggregator = new FSA_Aggregator();
		$cursor     = $start;
		$processed  = 0;

		for ( $i = 0; $i < self::DAYS_PER_BATCH; $i++ ) {
			if ( strtotime( $cursor ) > strtotime( $today ) ) {
				break;
			}
			$aggregator->aggregate_day( $cursor );
			$processed++;
			$cursor = wp_date( 'Y-m-d', strtotime( '+1 day', strtotime( $cursor ) ) );
		}

		$done = ( strtotime( $cursor ) > strtotime( $today ) );

		if ( $done ) {
			update_option( 'fsa_backfill_done', 1 );
			update_option( 'fsa_backfill_cursor', $today );
		} else {
			update_option( 'fsa_backfill_cursor', $cursor );
		}

		// Haladás becslése.
		$earliest = $this->earliest_order_date();
		$progress = 100;
		if ( $earliest ) {
			$total_days = max( 1, ( strtotime( $today ) - strtotime( $earliest ) ) / DAY_IN_SECONDS );
			$done_days  = ( strtotime( $cursor ) - strtotime( $earliest ) ) / DAY_IN_SECONDS;
			$progress   = min( 100, max( 0, round( $done_days / $total_days * 100 ) ) );
		}

		return array(
			'done'            => $done,
			'processed_until' => $cursor,
			'processed_days'  => $processed,
			'progress'        => $progress,
		);
	}

	/**
	 * Backfill újraindítása nulláról.
	 */
	public function reset() {
		delete_option( 'fsa_backfill_cursor' );
		update_option( 'fsa_backfill_done', 0 );
	}
}
