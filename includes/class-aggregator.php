<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FSA_Aggregator {

	public function paid_statuses() {
		$saved = get_option( 'fsa_paid_statuses', array( 'wc-completed', 'wc-processing' ) );
		if ( ! is_array( $saved ) || empty( $saved ) ) {
			$saved = array( 'wc-completed', 'wc-processing' );
		}
		// Mindkét formát tároljuk: prefix nélkül (get_status() visszaadja)
		// és prefix-szel (HPOS tábla tárolhatja így).
		$result = array();
		foreach ( $saved as $slug ) {
			$slug      = trim( $slug );
			$no_prefix = preg_replace( '/^wc-/', '', $slug );
			$result[]  = $no_prefix;
			$result[]  = 'wc-' . $no_prefix;
		}
		return array_values( array_unique( array_filter( $result ) ) );
	}

	/**
	 * Adott napra eső order ID-k lekérése direktben SQL-ből.
	 * HPOS-kompatibilis: először a wc_orders táblát próbálja, fallback a posts táblára.
	 */
	private function get_order_ids_for_date( $date ) {
		global $wpdb;

		$hpos_table  = $wpdb->prefix . 'wc_orders';
		$hpos_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$hpos_table}'" ) === $hpos_table;

		// Fizetett státuszok SQL IN listája (mindkét formában).
		$paid        = $this->paid_statuses(); // tartalmaz "completed" és "wc-completed" stb.
		$placeholders = implode( ',', array_fill( 0, count( $paid ), '%s' ) );

		if ( $hpos_exists ) {
			// GMT offset: ha 0, akkor a szerver UTC-n fut, a dátum egyezik.
			// Ha nem 0, akkor helyi időre konvertálunk.
			$offset = (int) ( get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );

			if ( $offset === 0 ) {
				$sql = $wpdb->prepare(
					"SELECT id FROM {$hpos_table}
					 WHERE type = 'shop_order'
					   AND DATE(date_created_gmt) = %s
					   AND status IN ({$placeholders})",
					array_merge( array( $date ), $paid )
				);
			} else {
				$sql = $wpdb->prepare(
					"SELECT id FROM {$hpos_table}
					 WHERE type = 'shop_order'
					   AND DATE(DATE_ADD(date_created_gmt, INTERVAL %d SECOND)) = %s
					   AND status IN ({$placeholders})",
					array_merge( array( $offset, $date ), $paid )
				);
			}

			$ids = $wpdb->get_col( $sql );
			if ( ! empty( $ids ) ) {
				return array_map( 'intval', $ids );
			}
		}

		// Fallback: wp_posts (legacy)
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'shop_order'
			   AND DATE(post_date) = %s
			   AND post_status IN ({$placeholders})",
			array_merge( array( $date ), $paid )
		);
		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	public function aggregate_day( $date ) {
		global $wpdb;

		$daily_table  = $wpdb->prefix . 'fsa_sales_daily';
		$orders_table = $wpdb->prefix . 'fsa_orders_summary';

		$wpdb->delete( $daily_table,  array( 'day' => $date ), array( '%s' ) );
		$wpdb->delete( $orders_table, array( 'day' => $date ), array( '%s' ) );

		$paid    = $this->paid_statuses();
		$ids     = $this->get_order_ids_for_date( $date );

		if ( empty( $ids ) ) {
			return 0;
		}

		$product_rows = array();
		$counted      = 0;

		foreach ( $ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order || ! ( $order instanceof WC_Order ) ) {
				continue;
			}
			// get_status() prefix nélkül adja vissza, de biztonsági okokból
			// mindkét formát elfogadjuk: "completed" és "wc-completed" is.
			$status        = $order->get_status();
			$status_wc     = 'wc-' . $status;
			$status_nowc   = preg_replace( '/^wc-/', '', $status );
			if ( ! in_array( $status, $paid, true )
				&& ! in_array( $status_wc, $paid, true )
				&& ! in_array( $status_nowc, $paid, true ) ) {
				continue;
			}

			$counted++;
			$this->insert_order_summary( $order, $date, $orders_table );

			foreach ( $order->get_items() as $item ) {
				$product_id = $item->get_product_id();
				if ( ! $product_id ) {
					continue;
				}

				$qty           = (int) $item->get_quantity();
				$line_net      = (float) $item->get_total();
				$line_tax      = (float) $item->get_total_tax();
				$line_gross    = $line_net + $line_tax;
				$line_discount = (float) $item->get_subtotal() - $line_net;

				if ( ! isset( $product_rows[ $product_id ] ) ) {
					$cat = $this->primary_category( $product_id );
					$product_rows[ $product_id ] = array(
						'qty'           => 0,
						'revenue_gross' => 0,
						'revenue_net'   => 0,
						'discount'      => 0,
						'product_name'  => $item->get_name(),
						'category_id'   => $cat['id'],
						'category_name' => $cat['name'],
					);
				}

				$product_rows[ $product_id ]['qty']           += $qty;
				$product_rows[ $product_id ]['revenue_gross'] += $line_gross;
				$product_rows[ $product_id ]['revenue_net']   += $line_net;
				$product_rows[ $product_id ]['discount']      += $line_discount;
			}
		}

		foreach ( $product_rows as $product_id => $row ) {
			$wpdb->insert(
				$daily_table,
				array(
					'day'           => $date,
					'product_id'    => $product_id,
					'category_id'   => $row['category_id'],
					'product_name'  => $row['product_name'],
					'category_name' => $row['category_name'],
					'qty'           => $row['qty'],
					'revenue_gross' => round( $row['revenue_gross'], 2 ),
					'revenue_net'   => round( $row['revenue_net'], 2 ),
					'discount'      => round( $row['discount'], 2 ),
				),
				array( '%s', '%d', '%d', '%s', '%s', '%d', '%f', '%f', '%f' )
			);
		}

		return $counted;
	}

	private function insert_order_summary( $order, $date, $orders_table ) {
		global $wpdb;

		$total    = (float) $order->get_total() - (float) $order->get_total_refunded();
		$tax      = (float) $order->get_total_tax();
		$shipping = (float) $order->get_shipping_total();
		$discount = (float) $order->get_total_discount();
		$email    = $order->get_billing_email();

		$items_count = 0;
		foreach ( $order->get_items() as $item ) {
			$items_count += (int) $item->get_quantity();
		}

		$coupons = $order->get_coupon_codes();
		$coupon  = ! empty( $coupons ) ? implode( ',', $coupons ) : '';

		$wpdb->insert(
			$orders_table,
			array(
				'order_id'      => $order->get_id(),
				'day'           => $date,
				'status'        => $order->get_status(),
				'total'         => round( $total, 2 ),
				'total_net'     => round( $total - $tax, 2 ),
				'shipping'      => round( $shipping, 2 ),
				'discount'      => round( $discount, 2 ),
				'items_count'   => $items_count,
				'coupon'        => substr( $coupon, 0, 191 ),
				'is_returning'  => $this->is_returning_customer( $email, $order->get_id() ) ? 1 : 0,
				'billing_email' => substr( $email, 0, 191 ),
			),
			array( '%d', '%s', '%s', '%f', '%f', '%f', '%f', '%d', '%s', '%d', '%s' )
		);
	}

	private function is_returning_customer( $email, $current_order_id ) {
		if ( empty( $email ) ) {
			return false;
		}
		$prev = wc_get_orders( array(
			'status'        => $this->paid_statuses(),
			'billing_email' => $email,
			'limit'         => 1,
			'return'        => 'ids',
			'exclude'       => array( $current_order_id ),
		) );
		return ! empty( $prev );
	}

	private function primary_category( $product_id ) {
		$terms = get_the_terms( $product_id, 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$first = reset( $terms );
			return array( 'id' => $first->term_id, 'name' => $first->name );
		}
		return array( 'id' => 0, 'name' => '(nincs kategória)' );
	}

	public function run_daily() {
		$yesterday = wp_date( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) );
		$this->aggregate_day( $yesterday );
		$this->aggregate_day( wp_date( 'Y-m-d', current_time( 'timestamp' ) ) );
	}

	public function on_status_changed( $order_id, $old_status, $new_status, $order ) {
		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$date = $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : wp_date( 'Y-m-d', current_time( 'timestamp' ) );
		$this->aggregate_day( $date );
	}
}
