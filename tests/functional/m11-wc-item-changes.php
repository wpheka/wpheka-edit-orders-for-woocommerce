<?php
/**
 * WooCommerce 11.0 and 11.1 order item changes (laptop queue 2026-10-23, item 1).
 *
 * WooCommerce 11.0 defers the database delete of remove_order_items() to the next save(),
 * and 11.1 makes WC_Order_Item_Product::set_product() reset variation_id for a product
 * that is not a variation. Edit Orders calls neither remove_order_items() nor
 * remove_item(): a removed line is a refund, and a swap always passes a variation. These
 * cases check that at the database, not only in memory:
 * - a size swap by staff and by the customer leaves one line with the new variation and
 *   its size, and no stale size meta;
 * - a dearer swap's balance order holds the difference as one fee and one tax row per rate;
 * - removing a line and a quantity change to 0 keep every line row and make a refund;
 * - tax rows are never duplicated or lost after the edit and after another save().
 *
 * Run with: bash tests/functional/run.sh m11-wc-item-changes (or all suites without an argument).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$eo_t = array(
	'pass'    => 0,
	'fail'    => 0,
	'orders'  => array(),
	'stock'   => array(),
	'product' => array(),
);

$eo_check = static function ( $label, $condition, $detail = '' ) use ( &$eo_t ) {
	if ( $condition ) {
		++$eo_t['pass'];
		WP_CLI::log( "  PASS  {$label}" );
	} else {
		++$eo_t['fail'];
		WP_CLI::log( "  FAIL  {$label}" . ( '' !== $detail ? " ({$detail})" : '' ) );
	}
};

$eo_money = static function ( $amount ) {
	return wc_format_decimal( $amount, 2 );
};

$eo_debug_log  = WP_CONTENT_DIR . '/debug.log';
$eo_debug_from = file_exists( $eo_debug_log ) ? filesize( $eo_debug_log ) : 0;

Edit_Orders_For_WooCommerce_Audit_Log::maybe_install();
$eo_settings_before = get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );

foreach ( array( 'EO-TSHIRT', 'EO-MUG', 'EO-HOODIE-S', 'EO-HOODIE-M', 'EO-HOODIE-L' ) as $eo_sku ) {
	$eo_t['product'][ $eo_sku ]                   = wc_get_product_id_by_sku( $eo_sku );
	$eo_t['stock'][ $eo_t['product'][ $eo_sku ] ] = wc_get_product( $eo_t['product'][ $eo_sku ] )->get_stock_quantity();
}
$eo_hoodie   = wc_get_product_id_by_sku( 'EO-HOODIE' );
$eo_customer = get_user_by( 'login', 'customer' );

$eo_place = array(
	'first_name' => 'Casey',
	'last_name'  => 'Customer',
	'address_1'  => '1 Yonge St',
	'city'       => 'Toronto',
	'state'      => 'ON',
	'postcode'   => 'M5E 1E5',
	'country'    => 'CA',
);

/**
 * A paid Ontario order for the seeded customer, with taxes and flat rate shipping.
 */
$eo_make_order = static function ( array $lines ) use ( &$eo_t, $eo_customer, $eo_place ) {
	$order = wc_create_order( array( 'customer_id' => $eo_customer->ID ) );
	$order->set_address( array_merge( $eo_place, array( 'email' => 'customer@example.com' ) ), 'billing' );
	$order->set_address( $eo_place, 'shipping' );
	foreach ( $lines as $line ) {
		$order->add_product( wc_get_product( $eo_t['product'][ $line[0] ] ), $line[1] );
	}
	$zone     = WC_Shipping_Zones::get_zone_matching_package(
		array(
			'destination' => array(
				'country'  => 'CA',
				'state'    => 'ON',
				'postcode' => 'M5E 1E5',
			),
		)
	);
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Flat rate' );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_instance_id( current( $zone->get_shipping_methods( true ) )->get_instance_id() );
	$shipping->set_total( 10 );
	$order->add_item( $shipping );
	$order->calculate_totals();
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$order->save();
	$order->payment_complete( 'TEST-' . $order->get_id() );
	$eo_t['orders'][] = $order->get_id();
	return wc_get_order( $order->get_id() );
};

$eo_item_id = static function ( WC_Order $order, $sku ) use ( &$eo_t ) {
	foreach ( $order->get_items() as $item_id => $item ) {
		$id = $item->get_variation_id() ? $item->get_variation_id() : $item->get_product_id();
		if ( (int) $id === (int) $eo_t['product'][ $sku ] ) {
			return $item_id;
		}
	}
	return 0;
};

$eo_pay = static function ( $balance_id ) {
	$balance = wc_get_order( $balance_id );
	$balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$balance->save();
	$balance->payment_complete( 'TEST-BAL-' . $balance_id );
};

// What the database holds, read past every cache.
$eo_db_items = static function ( $order_id, $type ) use ( $wpdb ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items WHERE order_id = %d AND order_item_type = %s ORDER BY order_item_id", $order_id, $type ) ) );
};
$eo_db_meta  = static function ( $item_id, $key ) use ( $wpdb ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta WHERE order_item_id = %d AND meta_key = %s", $item_id, $key ) );
};
// Tax rows by rate ID: one row per rate, never two, never none.
$eo_db_tax_rates = static function ( $order_id ) use ( $eo_db_items, $eo_db_meta ) {
	$rates = array();
	foreach ( $eo_db_items( $order_id, 'tax' ) as $tax_item_id ) {
		$rate    = $eo_db_meta( $tax_item_id, 'rate_id' );
		$rates[] = (int) ( $rate ? $rate[0] : 0 );
	}
	sort( $rates );
	return $rates;
};
$eo_fresh        = static function ( $order_id ) {
	wp_cache_flush();
	return wc_get_order( $order_id );
};

/**
 * The line is the expected variation in the database: product, variation, one size row.
 */
$eo_line_is = static function ( $label, $item_id, $sku, $size ) use ( $eo_check, $eo_db_meta, &$eo_t, $eo_hoodie ) {
	$variation = $eo_db_meta( $item_id, '_variation_id' );
	$product   = $eo_db_meta( $item_id, '_product_id' );
	$sizes     = $eo_db_meta( $item_id, 'size' );
	$eo_check( "{$label}: variation in the database is {$sku}", array( (string) $eo_t['product'][ $sku ] ) === $variation, wp_json_encode( $variation ) );
	$eo_check( "{$label}: product is still the Hoodie", array( (string) $eo_hoodie ) === $product, wp_json_encode( $product ) );
	$eo_check( "{$label}: one size row, {$size}", array( $size ) === $sizes, wp_json_encode( $sizes ) );
};

wp_set_current_user( 1 );
WP_CLI::log( 'HPOS ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) . ', WooCommerce ' . WC()->version );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 1: this store has the 11.0 deferred delete (so the cases below test against it)' );
$eo_order    = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_taxes    = $eo_db_items( $eo_order->get_id(), 'tax' );
$eo_deferred = version_compare( WC()->version, '11.0.0', '>=' );
if ( $eo_deferred ) {
	$eo_order->remove_order_items( 'tax' );
	$eo_check( 'remove_order_items(): tax rows still in the database before save()', $eo_taxes && $eo_db_items( $eo_order->get_id(), 'tax' ) === $eo_taxes );
	$eo_order->save();
	$eo_check( 'remove_order_items(): tax rows gone after save()', array() === $eo_db_items( $eo_order->get_id(), 'tax' ) );
} else {
	WP_CLI::log( '  SKIP  WooCommerce ' . WC()->version . ' deletes at once; the cases below still apply' );
}

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 2: staff swap a size at the same price (S to M)' );
$eo_order   = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_line    = $eo_item_id( $eo_order, 'EO-HOODIE-S' );
$eo_rates   = $eo_db_tax_rates( $eo_order->get_id() );
$eo_changes = array(
	array(
		'type'         => 'swap',
		'item_id'      => $eo_line,
		'variation_id' => $eo_t['product']['EO-HOODIE-M'],
	),
);
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
$eo_result  = is_wp_error( $eo_plan ) ? $eo_plan : Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes, 'staff', array( 'expect' => $eo_plan->fingerprint() ) );
$eo_check( 'applied', ! is_wp_error( $eo_result ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_check( 'still one line row, the same one', array( $eo_line ) === $eo_db_items( $eo_order->get_id(), 'line_item' ) );
$eo_line_is( 'S to M', $eo_line, 'EO-HOODIE-M', 'M' );
$eo_check( 'tax rows unchanged', $eo_rates === $eo_db_tax_rates( $eo_order->get_id() ), wp_json_encode( $eo_db_tax_rates( $eo_order->get_id() ) ) );
$eo_fresh( $eo_order->get_id() )->save();
$eo_check( 'after another save(): line and tax rows unchanged', array( $eo_line ) === $eo_db_items( $eo_order->get_id(), 'line_item' ) && $eo_rates === $eo_db_tax_rates( $eo_order->get_id() ) );
$eo_line_is( 'after another save()', $eo_line, 'EO-HOODIE-M', 'M' );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 3: staff swap to a dearer size (S to L) through a balance order' );
$eo_order   = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_line    = $eo_item_id( $eo_order, 'EO-HOODIE-S' );
$eo_rates   = $eo_db_tax_rates( $eo_order->get_id() );
$eo_changes = array(
	array(
		'type'         => 'swap',
		'item_id'      => $eo_line,
		'variation_id' => $eo_t['product']['EO-HOODIE-L'],
	),
);
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
$eo_result  = is_wp_error( $eo_plan ) ? $eo_plan : Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes, 'staff', array( 'expect' => $eo_plan->fingerprint() ) );
$eo_balance = Edit_Orders_For_WooCommerce_Balance_Orders::get_open_balance_order( $eo_fresh( $eo_order->get_id() ) );
$eo_check( 'a balance order is waiting', ! is_wp_error( $eo_result ) && $eo_balance, is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
if ( $eo_balance ) {
	$eo_t['orders'][] = $eo_balance->get_id();
	// A swap's price difference is a fee on the balance order, not a second product line.
	$eo_check( 'balance: no product line, one fee row for the difference', array() === $eo_db_items( $eo_balance->get_id(), 'line_item' ) && 1 === count( $eo_db_items( $eo_balance->get_id(), 'fee' ) ), wp_json_encode( $eo_db_items( $eo_balance->get_id(), 'fee' ) ) );
	$eo_balance_rates = $eo_db_tax_rates( $eo_balance->get_id() );
	$eo_check( 'balance: one tax row per rate, none lost', $eo_balance_rates && count( $eo_balance_rates ) === count( array_unique( $eo_balance_rates ) ) && array_values( array_unique( $eo_balance_rates ) ) === array_values( array_unique( array_intersect( $eo_rates, $eo_balance_rates ) ) ), wp_json_encode( $eo_balance_rates ) );
	$eo_check( 'balance total matches the preview', $eo_money( $eo_plan->get_balance_estimate( $eo_order ) ) === $eo_money( $eo_fresh( $eo_balance->get_id() )->get_total() ), $eo_money( $eo_fresh( $eo_balance->get_id() )->get_total() ) );
	$eo_check( 'the original line is still S until paid', array( (string) $eo_t['product']['EO-HOODIE-S'] ) === $eo_db_meta( $eo_line, '_variation_id' ) );
	$eo_pay( $eo_balance->get_id() );
	$eo_line_is( 'after payment', $eo_line, 'EO-HOODIE-L', 'L' );
	$eo_check( 'after payment: original tax rows unchanged', $eo_rates === $eo_db_tax_rates( $eo_order->get_id() ), wp_json_encode( $eo_db_tax_rates( $eo_order->get_id() ) ) );
}

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 4: staff remove a line, then the order is saved again' );
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 2 ), array( 'EO-MUG', 1 ) ) );
$eo_lines   = $eo_db_items( $eo_order->get_id(), 'line_item' );
$eo_rates   = $eo_db_tax_rates( $eo_order->get_id() );
$eo_mug     = $eo_item_id( $eo_order, 'EO-MUG' );
$eo_changes = array(
	array(
		'type'    => 'remove',
		'item_id' => $eo_mug,
	),
);
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
$eo_result  = is_wp_error( $eo_plan ) ? $eo_plan : Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes, 'staff', array( 'expect' => $eo_plan->fingerprint() ) );
$eo_after   = $eo_fresh( $eo_order->get_id() );
$eo_check( 'applied', ! is_wp_error( $eo_result ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_check( 'every line row kept (a removal is a refund)', $eo_lines === $eo_db_items( $eo_order->get_id(), 'line_item' ) );
$eo_check( 'the mug is fully refunded', -1 === (int) $eo_after->get_qty_refunded_for_item( $eo_mug ) );
$eo_check( 'refund matches the preview', $eo_money( $eo_plan->get_refund_amount() ) === $eo_money( $eo_after->get_total_refunded() ), $eo_money( $eo_after->get_total_refunded() ) );
$eo_check( 'tax rows unchanged', $eo_rates === $eo_db_tax_rates( $eo_order->get_id() ), wp_json_encode( $eo_db_tax_rates( $eo_order->get_id() ) ) );
$eo_after->save();
$eo_check( 'after another save(): lines, tax rows and refund unchanged', $eo_lines === $eo_db_items( $eo_order->get_id(), 'line_item' ) && $eo_rates === $eo_db_tax_rates( $eo_order->get_id() ) && -1 === (int) $eo_fresh( $eo_order->get_id() )->get_qty_refunded_for_item( $eo_mug ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 5: staff change a quantity to 0' );
$eo_order   = $eo_make_order( array( array( 'EO-TSHIRT', 2 ), array( 'EO-MUG', 3 ) ) );
$eo_lines   = $eo_db_items( $eo_order->get_id(), 'line_item' );
$eo_rates   = $eo_db_tax_rates( $eo_order->get_id() );
$eo_mug     = $eo_item_id( $eo_order, 'EO-MUG' );
$eo_changes = array(
	array(
		'type'     => 'quantity',
		'item_id'  => $eo_mug,
		'quantity' => 0,
	),
);
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_order, $eo_changes );
$eo_result  = is_wp_error( $eo_plan ) ? $eo_plan : Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_order, $eo_changes, 'staff', array( 'expect' => $eo_plan->fingerprint() ) );
$eo_after   = $eo_fresh( $eo_order->get_id() );
$eo_check( 'applied', ! is_wp_error( $eo_result ), is_wp_error( $eo_result ) ? $eo_result->get_error_message() : '' );
$eo_check( 'every line row kept', $eo_lines === $eo_db_items( $eo_order->get_id(), 'line_item' ) );
$eo_check( 'all 3 mugs refunded', -3 === (int) $eo_after->get_qty_refunded_for_item( $eo_mug ) );
$eo_check( 'tax rows unchanged', $eo_rates === $eo_db_tax_rates( $eo_order->get_id() ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 6: the customer swaps a size (S to M) from My Account' );
wp_set_current_user( $eo_customer->ID );
$eo_order  = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_line   = $eo_item_id( $eo_order, 'EO-HOODIE-S' );
$eo_rates  = $eo_db_tax_rates( $eo_order->get_id() );
$eo_result = Edit_Orders_For_WooCommerce_Frontend::process(
	wc_get_order( $eo_order->get_id() ),
	array(
		'edit_orders_for_woocommerce_action' => 'swap',
		'swap'                               => array( $eo_line => $eo_t['product']['EO-HOODIE-M'] ),
		'step'                               => 'confirm',
	),
	'customer'
);
$eo_check( 'applied', is_array( $eo_result ) && 'success' === $eo_result['type'], is_array( $eo_result ) && isset( $eo_result['message'] ) ? wp_strip_all_tags( $eo_result['message'] ) : '' );
$eo_check( 'still one line row, the same one', array( $eo_line ) === $eo_db_items( $eo_order->get_id(), 'line_item' ) );
$eo_line_is( 'customer S to M', $eo_line, 'EO-HOODIE-M', 'M' );
$eo_check( 'tax rows unchanged', $eo_rates === $eo_db_tax_rates( $eo_order->get_id() ) );
wp_set_current_user( 1 );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Case 7: no notices, warnings or errors from the plugin' );
$eo_ours = array();
if ( file_exists( $eo_debug_log ) ) {
	clearstatcache();
	$eo_new = (string) file_get_contents( $eo_debug_log, false, null, $eo_debug_from ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	foreach ( explode( "\n", $eo_new ) as $eo_log_line ) {
		if ( false !== strpos( $eo_log_line, 'edit-orders-for-woocommerce' ) ) {
			$eo_ours[] = $eo_log_line;
		}
	}
}
$eo_check( 'debug.log has nothing from the plugin', empty( $eo_ours ), implode( ' | ', array_slice( $eo_ours, 0, 3 ) ) );

// ---------------------------------------------------------------------------
WP_CLI::log( 'Cleanup' );
foreach ( array_unique( array_filter( $eo_t['orders'] ) ) as $eo_order_id ) {
	$eo_order = wc_get_order( $eo_order_id );
	if ( ! $eo_order ) {
		continue;
	}
	foreach ( $eo_order->get_refunds() as $eo_refund ) {
		$eo_refund->delete( true );
	}
	$eo_order->delete( true );
	$wpdb->delete( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'order_id' => $eo_order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
foreach ( $eo_t['stock'] as $eo_product_id => $eo_quantity ) {
	wc_update_product_stock( $eo_product_id, $eo_quantity, 'set' );
}
if ( null === $eo_settings_before ) {
	delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
} else {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $eo_settings_before );
}

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'M11 suite failed.' );
}
WP_CLI::success( 'M11 suite passed.' );
