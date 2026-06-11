<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API a dashboardhoz. Minden adat az aggregált táblákból jön.
 * Jogosultság: manage_woocommerce.
 */
class FSA_Rest_API {

	const NS = 'forme-analytics/v1';

	public function register_routes() {
		$perm = array( $this, 'permission' );

		register_rest_route( self::NS, '/summary', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_summary' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/timeseries', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_timeseries' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/top-products', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_top_products' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/categories', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_categories' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/aov-breakdown', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_aov_breakdown' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/coupons', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_coupons' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/install', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'install' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/debug', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'debug' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/backfill', array(
			'methods'             => 'GET, POST',
			'callback'            => array( $this, 'run_backfill' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => $perm,
		) );
		// Státusz-konfiguráció: GET = lista checkboxokhoz, POST = mentés.
		register_rest_route( self::NS, '/settings/statuses', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_statuses' ),
			'permission_callback' => $perm,
		) );
		register_rest_route( self::NS, '/settings/statuses/save', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'save_statuses' ),
			'permission_callback' => $perm,
		) );
	}

	public function permission() {
		return current_user_can( 'manage_woocommerce' );
	}

	/* ---------- segédek ---------- */

	private function range( $request ) {
		$to   = $request->get_param( 'to' ) ?: wp_date( 'Y-m-d', current_time( 'timestamp' ) );
		$from = $request->get_param( 'from' ) ?: wp_date( 'Y-m-d', strtotime( '-29 days', current_time( 'timestamp' ) ) );
		// sanitizálás
		$from = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ? $from : wp_date( 'Y-m-d', strtotime( '-29 days' ) );
		$to   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ? $to : wp_date( 'Y-m-d' );
		return array( $from, $to );
	}

	private function previous_range( $from, $to ) {
		$len  = ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS + 1;
		$pto  = wp_date( 'Y-m-d', strtotime( '-1 day', strtotime( $from ) ) );
		$pfrom = wp_date( 'Y-m-d', strtotime( "-" . ( $len ) . " day", strtotime( $from ) ) );
		return array( $pfrom, $pto );
	}

	/* ---------- végpontok ---------- */

	public function get_summary( $request ) {
		global $wpdb;
		list( $from, $to ) = $this->range( $request );
		$ot = $wpdb->prefix . 'fsa_orders_summary';

		$cur  = $this->summary_block( $from, $to, $ot );
		list( $pf, $ptv ) = $this->previous_range( $from, $to );
		$prev = $this->summary_block( $pf, $ptv, $ot );

		return rest_ensure_response( array(
			'range'   => array( 'from' => $from, 'to' => $to ),
			'current' => $cur,
			'previous' => $prev,
		) );
	}

	private function summary_block( $from, $to, $ot ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT
				COUNT(*) AS orders,
				COALESCE(SUM(total),0) AS revenue_gross,
				COALESCE(SUM(total_net),0) AS revenue_net,
				COALESCE(SUM(discount),0) AS discount,
				COALESCE(SUM(items_count),0) AS items,
				COALESCE(SUM(is_returning),0) AS ret_count
			FROM {$ot} WHERE day BETWEEN %s AND %s",
			$from, $to
		), ARRAY_A );

		$orders = $row ? (int) $row['orders'] : 0;
		return array(
			'orders'         => $orders,
			'revenue_gross'  => $row ? (float) $row['revenue_gross'] : 0,
			'revenue_net'    => $row ? (float) $row['revenue_net'] : 0,
			'discount'       => $row ? (float) $row['discount'] : 0,
			'items'          => $row ? (int) $row['items'] : 0,
			'aov'            => $orders ? round( $row['revenue_gross'] / $orders, 2 ) : 0,
			'items_per_order' => $orders ? round( $row['items'] / $orders, 2 ) : 0,
			'returning_rate' => $orders ? round( $row['ret_count'] / $orders * 100, 1 ) : 0,
		);
	}

	public function get_timeseries( $request ) {
		global $wpdb;
		list( $from, $to ) = $this->range( $request );
		$gran = $request->get_param( 'granularity' ) ?: 'day';
		$ot = $wpdb->prefix . 'fsa_orders_summary';

		if ( 'month' === $gran ) {
			$bucket = "DATE_FORMAT(day, '%%Y-%%m-01')";
		} elseif ( 'week' === $gran ) {
			$bucket = "DATE_SUB(day, INTERVAL WEEKDAY(day) DAY)";
		} else {
			$bucket = "day";
		}

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT {$bucket} AS bucket,
				COALESCE(SUM(total),0) AS revenue,
				COUNT(*) AS orders,
				COALESCE(SUM(items_count),0) AS items
			FROM {$ot} WHERE day BETWEEN %s AND %s
			GROUP BY bucket ORDER BY bucket ASC",
			$from, $to
		), ARRAY_A );

		$out = array_map( function ( $r ) {
			return array(
				'date'    => $r['bucket'],
				'revenue' => (float) $r['revenue'],
				'orders'  => (int) $r['orders'],
				'aov'     => (int) $r['orders'] ? round( $r['revenue'] / $r['orders'] ) : 0,
			);
		}, $rows );

		return rest_ensure_response( $out );
	}

	public function get_top_products( $request ) {
		global $wpdb;
		list( $from, $to ) = $this->range( $request );
		$limit = min( 100, max( 1, (int) ( $request->get_param( 'limit' ) ?: 20 ) ) );
		$dt = $wpdb->prefix . 'fsa_sales_daily';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT product_id, MAX(product_name) AS name,
				SUM(qty) AS qty,
				SUM(revenue_gross) AS revenue
			FROM {$dt} WHERE day BETWEEN %s AND %s AND product_id > 0
			GROUP BY product_id ORDER BY revenue DESC LIMIT %d",
			$from, $to, $limit
		), ARRAY_A );

		// Pareto kumulatív %.
		$total = 0;
		foreach ( $rows as $r ) { $total += (float) $r['revenue']; }
		$cum = 0;
		$out = array();
		foreach ( $rows as $r ) {
			$rev = (float) $r['revenue'];
			$cum += $rev;
			$out[] = array(
				'product_id' => (int) $r['product_id'],
				'name'       => $r['name'],
				'qty'        => (int) $r['qty'],
				'revenue'    => $rev,
				'cum_pct'    => $total ? round( $cum / $total * 100, 1 ) : 0,
			);
		}
		return rest_ensure_response( $out );
	}

	public function get_categories( $request ) {
		global $wpdb;
		list( $from, $to ) = $this->range( $request );
		$dt = $wpdb->prefix . 'fsa_sales_daily';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT category_id, MAX(category_name) AS name,
				SUM(qty) AS qty, SUM(revenue_gross) AS revenue
			FROM {$dt} WHERE day BETWEEN %s AND %s
			GROUP BY category_id ORDER BY revenue DESC",
			$from, $to
		), ARRAY_A );

		$out = array_map( function ( $r ) {
			return array(
				'category_id' => (int) $r['category_id'],
				'name'        => $r['name'] ?: '(nincs kategória)',
				'qty'         => (int) $r['qty'],
				'revenue'     => (float) $r['revenue'],
			);
		}, $rows );
		return rest_ensure_response( $out );
	}

	public function get_aov_breakdown( $request ) {
		global $wpdb;
		list( $from, $to ) = $this->range( $request );
		$ot = $wpdb->prefix . 'fsa_orders_summary';

		// Darabszám-eloszlás: 1, 2, 3+ pólós rendelések.
		$dist = $wpdb->get_results( $wpdb->prepare(
			"SELECT
				CASE WHEN items_count <= 1 THEN '1'
				     WHEN items_count = 2 THEN '2'
				     ELSE '3+' END AS bucket,
				COUNT(*) AS orders,
				COALESCE(SUM(total),0) AS revenue,
				COALESCE(AVG(total),0) AS avg_value
			FROM {$ot} WHERE day BETWEEN %s AND %s
			GROUP BY bucket ORDER BY bucket ASC",
			$from, $to
		), ARRAY_A );

		// Új vs visszatérő.
		$seg = $wpdb->get_results( $wpdb->prepare(
			"SELECT is_returning, COUNT(*) AS orders,
				COALESCE(SUM(total),0) AS revenue,
				COALESCE(AVG(total),0) AS avg_value
			FROM {$ot} WHERE day BETWEEN %s AND %s
			GROUP BY is_returning",
			$from, $to
		), ARRAY_A );

		return rest_ensure_response( array(
			'distribution' => array_map( function ( $r ) {
				return array(
					'bucket'    => $r['bucket'],
					'orders'    => (int) $r['orders'],
					'revenue'   => (float) $r['revenue'],
					'avg_value' => round( (float) $r['avg_value'] ),
				);
			}, $dist ),
			'segments' => array_map( function ( $r ) {
				return array(
					'type'      => $r['is_returning'] ? 'returning' : 'new',
					'orders'    => (int) $r['orders'],
					'revenue'   => (float) $r['revenue'],
					'avg_value' => round( (float) $r['avg_value'] ),
				);
			}, $seg ),
		) );
	}

	public function get_coupons( $request ) {
		global $wpdb;
		list( $from, $to ) = $this->range( $request );
		$ot = $wpdb->prefix . 'fsa_orders_summary';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT coupon, COUNT(*) AS orders,
				COALESCE(SUM(total),0) AS revenue,
				COALESCE(SUM(discount),0) AS discount
			FROM {$ot} WHERE day BETWEEN %s AND %s AND coupon <> ''
			GROUP BY coupon ORDER BY revenue DESC LIMIT 50",
			$from, $to
		), ARRAY_A );

		$out = array_map( function ( $r ) {
			return array(
				'coupon'   => $r['coupon'],
				'orders'   => (int) $r['orders'],
				'revenue'  => (float) $r['revenue'],
				'discount' => (float) $r['discount'],
			);
		}, $rows );
		return rest_ensure_response( $out );
	}

	public function debug( $request ) {
		global $wpdb;

		$hpos    = $wpdb->prefix . 'wc_orders';
		$offset  = (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		$date    = $request->get_param( 'date' ) ?: wp_date( 'Y-m-d', current_time( 'timestamp' ) );
		$agg     = new FSA_Aggregator();
		$paid    = $agg->paid_statuses();

		// 1) Hány paid ID van az adott napra SQL-ből?
		$paid_ph = implode( ',', array_fill( 0, count( $paid ), '%s' ) );
		$ids_raw = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, status FROM {$hpos}
			 WHERE type='shop_order'
			   AND DATE(date_created_gmt) = %s
			   AND status IN ({$paid_ph})
			 LIMIT 20",
			array_merge( array( $date ), $paid )
		), ARRAY_A );

		// 1b) Összes rendelés az adott napra (státusztól függetlenül)
		$all_that_day = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$hpos} WHERE type='shop_order' AND DATE(date_created_gmt) = %s",
			$date
		) );

		// 2) Az első 3 order get_status() értéke
		$sample = array();
		foreach ( array_slice( $ids_raw, 0, 3 ) as $row ) {
			$o = wc_get_order( (int) $row['id'] );
			if ( $o ) {
				$gs = $o->get_status();
				$sample[] = array(
					'id'          => $row['id'],
					'db_status'   => $row['status'],
					'get_status'  => $gs,
					'in_paid'     => in_array( $gs, $paid, true ),
					'in_paid_wc'  => in_array( 'wc-' . $gs, $paid, true ),
				);
			}
		}

		// 3) Legkorábbi rendelés dátuma
		$earliest = $wpdb->get_var(
			"SELECT DATE(date_created_gmt) FROM {$hpos}
			 WHERE type='shop_order' ORDER BY date_created_gmt ASC LIMIT 1"
		);

		// 4) A tábla sorai
		$agg_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}fsa_orders_summary" );

		return rest_ensure_response( array(
			'date'            => $date,
			'gmt_offset'      => $offset,
			'paid_statuses'   => $paid,
			'all_orders_day'  => $all_that_day,
			'paid_ids_found'  => count( $ids_raw ),
			'sample_orders'   => $sample,
			'earliest_date'   => $earliest,
			'cursor'          => get_option( 'fsa_backfill_cursor', '' ),
			'agg_total'       => $agg_total,
		) );
	}

	public function install( $request ) {
		FSA_Installer::create_tables();
		global $wpdb;
		$t1 = $wpdb->prefix . 'fsa_sales_daily';
		$t2 = $wpdb->prefix . 'fsa_orders_summary';
		return rest_ensure_response( array(
			'done'           => true,
			'fsa_daily'      => $wpdb->get_var( "SHOW TABLES LIKE '{$t1}'" ) === $t1 ? 'exists' : 'MISSING',
			'fsa_orders'     => $wpdb->get_var( "SHOW TABLES LIKE '{$t2}'" ) === $t2 ? 'exists' : 'MISSING',
		) );
	}

	public function run_backfill( $request ) {
		// Táblák létrehozása ha hiányoznak (pl. aktiválás nem futott le).
		FSA_Installer::create_tables();
		// reset param elfogadása URL-ből és JSON body-ból egyaránt.
		$body        = $request->get_json_params();
		$should_reset = $request->get_param( 'reset' ) || ( is_array( $body ) && ! empty( $body['reset'] ) );
		if ( $should_reset ) {
			( new FSA_Backfill() )->reset();
		}
		$result = ( new FSA_Backfill() )->process_batch();
		return rest_ensure_response( $result );
	}

	/**
	 * Az összes WooCommerce státusz listája a mentett bejelöléssel együtt.
	 * A frontend ebből építi a checkbox-listát.
	 */
	public function get_statuses( $request ) {
		$all_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		$saved        = get_option( 'fsa_paid_statuses', array( 'wc-completed', 'wc-processing' ) );
		if ( ! is_array( $saved ) ) {
			$saved = array( 'wc-completed', 'wc-processing' );
		}

		$out = array();
		foreach ( $all_statuses as $slug => $label ) {
			$out[] = array(
				'slug'    => $slug,            // pl. "wc-completed"
				'label'   => $label,
				'checked' => in_array( $slug, $saved, true ),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Menti a bejelölt státuszokat, és automatikusan újraindítja a backfill-t.
	 */
	public function save_statuses( $request ) {
		// Elfogadja GET query paraméterként (vesszővel elválasztva) és JSON body-ból is.
		$body  = $request->get_json_params();
		$raw   = $request->get_param( 'statuses' );

		if ( is_string( $raw ) && strpos( $raw, ',' ) !== false ) {
			$slugs = array_map( 'trim', explode( ',', $raw ) );
		} elseif ( is_array( $raw ) ) {
			$slugs = $raw;
		} elseif ( isset( $body['statuses'] ) ) {
			$slugs = $body['statuses'];
		} else {
			$slugs = null;
		}
		if ( ! is_array( $slugs ) ) {
			return new WP_Error( 'invalid', 'A statuses paraméter tömb kell legyen.', array( 'status' => 400 ) );
		}

		// Csak érvényes, létező WooCommerce státuszokat mentünk.
		$all_valid = array_keys( function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array() );
		$clean     = array_values( array_filter( $slugs, function ( $s ) use ( $all_valid ) {
			return in_array( $s, $all_valid, true );
		} ) );

		// Legalább egy státusz kell.
		if ( empty( $clean ) ) {
			return new WP_Error( 'empty', 'Legalább egy státuszt be kell jelölni.', array( 'status' => 400 ) );
		}

		update_option( 'fsa_paid_statuses', $clean );

		// Csak a "done" flag törlése — a cursor megmarad, a backfill onnan folytatja.
		// Így státuszváltásnál nem kell az egész előzményt újrafeldolgozni.
		update_option( 'fsa_backfill_done', 0 );

		return rest_ensure_response( array( 'saved' => $clean, 'backfill_reset' => true ) );
	}

	public function get_status( $request ) {
		global $wpdb;
		$paid = ( new FSA_Aggregator() )->paid_statuses();

		$ot        = $wpdb->prefix . 'fsa_orders_summary';
		$agg_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ot}" );

		// Diagnosztika: hány rendelés van SQL-ből az utóbbi 30 napban
		$hpos = $wpdb->prefix . 'wc_orders';
		$hpos_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$hpos}'" ) === $hpos;
		if ( $hpos_exists ) {
			$sql_orders = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$hpos} WHERE type='shop_order' AND date_created_gmt >= %s",
				gmdate( 'Y-m-d', strtotime( '-30 days' ) )
			) );
			$db_mode = 'hpos';
		} else {
			$sql_orders = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='shop_order' AND post_date >= %s",
				gmdate( 'Y-m-d', strtotime( '-30 days' ) )
			) );
			$db_mode = 'legacy';
		}

		return rest_ensure_response( array(
			'backfill_done'   => (int) get_option( 'fsa_backfill_done', 0 ),
			'backfill_cursor' => get_option( 'fsa_backfill_cursor', '' ),
			'currency'        => get_woocommerce_currency(),
			'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol() ),
			'paid_statuses'   => $paid,
			'aggregated_rows' => $agg_count,
			'sql_orders_30d'  => $sql_orders,
			'db_mode'         => $db_mode,
			'all_statuses'    => function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array(),
		) );
	}
}
