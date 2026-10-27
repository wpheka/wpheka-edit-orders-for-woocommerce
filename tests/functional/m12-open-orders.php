<?php
/**
 * Functional suite for 1.3.0 (tasks/edit-orders-1.3-spec.md):
 * 1. "Customer can still change" on the Orders screen: the badge, the view and the
 *    order screen note agree with My Account; they go when the window closes or the
 *    order ships; the view lists the right orders.
 * 2. Excluded products and categories: no customer actions, a plain line instead,
 *    staff edits still work.
 * 3. The shipping and billing address switches, each on its own, and the upgrade from
 *    the single 1.2.x switch.
 * 4. The "from -> to" order note for customer address changes.
 * Nothing in these features may change an order's total, status or emails.
 *
 * Run with: bash tests/functional/run.sh m12-open-orders (or all suites without an argument).
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
	'terms'   => array(),
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

foreach ( array( 'admin/class-edit-orders-for-woocommerce-admin.php', 'admin/class-edit-orders-for-woocommerce-activity.php', 'admin/class-edit-orders-for-woocommerce-orders-list.php', 'wpheka-ui/load.php', 'admin/class-edit-orders-for-woocommerce-settings-form.php' ) as $eo_file ) {
	require_once EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'includes/' . $eo_file;
}

$eo_debug_log  = WP_CONTENT_DIR . '/debug.log';
$eo_debug_from = file_exists( $eo_debug_log ) ? filesize( $eo_debug_log ) : 0;

Edit_Orders_For_WooCommerce_Audit_Log::maybe_install();
delete_option( Edit_Orders_Mail_Log::OPTION );

$eo_settings_before = get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null );
$eo_set             = static function ( array $settings ) {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array_merge( (array) get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array() ), $settings ) );
	Edit_Orders_For_WooCommerce_Customer_Rules::reset_cache();
	Edit_Orders_For_WooCommerce_Orders_List::reset();
};
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );

foreach ( array( 'EO-TSHIRT', 'EO-MUG', 'EO-HOODIE-S', 'EO-HOODIE-M' ) as $eo_sku ) {
	$eo_t['product'][ $eo_sku ]                   = wc_get_product_id_by_sku( $eo_sku );
	$eo_t['stock'][ $eo_t['product'][ $eo_sku ] ] = wc_get_product( $eo_t['product'][ $eo_sku ] )->get_stock_quantity();
}

$eo_customer = get_user_by( 'login', 'customer' );

$eo_places = array(
	'ON'  => array(
		'address_1' => '1 Yonge St',
		'city'      => 'Toronto',
		'state'     => 'ON',
		'postcode'  => 'M5E 1E5',
		'country'   => 'CA',
	),
	'ON2' => array(
		'address_1' => '50 King St',
		'city'      => 'Ottawa',
		'state'     => 'ON',
		'postcode'  => 'K1P 5N2',
		'country'   => 'CA',
	),
	'AB'  => array(
		'address_1' => '100 8 Ave SW',
		'city'      => 'Calgary',
		'state'     => 'AB',
		'postcode'  => 'T2P 1B3',
		'country'   => 'CA',
	),
);

/**
 * A paid Ontario order, for the logged-in customer (default) or a guest (customer 0).
 */
$eo_make_order = static function ( array $lines, $customer_id = null, $gateway = 'edit_orders_test' ) use ( &$eo_t, $eo_customer, $eo_places ) {
	$order = wc_create_order( array( 'customer_id' => null === $customer_id ? $eo_customer->ID : $customer_id ) );
	$name  = array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
	);
	$order->set_address( array_merge( $eo_places['ON'], $name, array( 'email' => 'customer@example.com' ) ), 'billing' );
	$order->set_address( array_merge( $eo_places['ON'], $name ), 'shipping' );
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
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()[ $gateway ] );
	$order->save();
	if ( 'cod' === $gateway ) {
		$order->update_status( 'processing', 'Payment to be made upon delivery.' );
	} else {
		$order->payment_complete( 'TEST-' . $order->get_id() );
	}
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

$eo_process = static function ( WC_Order $order, array $data, $actor = 'customer' ) {
	return Edit_Orders_For_WooCommerce_Frontend::process( wc_get_order( $order->get_id() ), $data, $actor );
};

$eo_fresh = static function ( $order_id ) {
	Edit_Orders_For_WooCommerce_Customer_Rules::reset_cache();
	Edit_Orders_For_WooCommerce_Orders_List::reset();
	return wc_get_order( $order_id );
};

// What My Account shows for the order: its actions (empty when nothing is offered).
$eo_account_actions = static function ( WC_Order $order ) {
	$args = Edit_Orders_For_WooCommerce_Frontend::panel_args( $order, $order->get_customer_id() ? '' : $order->get_order_key() );
	return $args ? $args['actions'] : array();
};

// The badge, the view and My Account must agree.
$eo_agree = static function ( $label, WC_Order $order, $open ) use ( $eo_check, $eo_account_actions, $eo_fresh ) {
	$order   = $eo_fresh( $order->get_id() );
	$account = (bool) $eo_account_actions( $order );
	$badge   = '' !== Edit_Orders_For_WooCommerce_Orders_List::badge( $order );
	$view    = in_array( $order->get_id(), Edit_Orders_For_WooCommerce_Orders_List::open_order_ids(), true );
	$note    = '' !== Edit_Orders_For_WooCommerce_Orders_List::order_screen_note( $order );
	$eo_check(
		"{$label}: " . ( $open ? 'open' : 'not open' ) . ' in My Account, the badge, the view and the order screen',
		$open === $account && $open === $badge && $open === $view && $open === $note,
		wp_json_encode( compact( 'account', 'badge', 'view', 'note' ) )
	);
};

$eo_notes = static function ( WC_Order $order ) {
	return wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
};

wp_set_current_user( $eo_customer->ID );
WP_CLI::log( 'HPOS ' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) );

// ===========================================================================
WP_CLI::log( '1. The badge, the view and My Account agree' );
$eo_open = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_agree( 'a paid order', $eo_open, true );

$eo_badge = Edit_Orders_For_WooCommerce_Orders_List::badge( $eo_fresh( $eo_open->get_id() ) );
// Exactly 3,600 seconds left (the check ran in the second the order was paid) reads "1 h left".
$eo_check( 'badge text: "Changes open, 59 min left" (one-hour window)', false !== strpos( $eo_badge, 'Changes open, ' ) && preg_match( '/(59|60) min left|1 h left/', $eo_badge ), wp_strip_all_tags( $eo_badge ) );
$eo_screen_note = Edit_Orders_For_WooCommerce_Orders_List::order_screen_note( $eo_fresh( $eo_open->get_id() ) );
$eo_check( 'order screen: "The customer can still change this order until <time>."', false !== strpos( $eo_screen_note, 'The customer can still change this order until ' . wp_date( get_option( 'time_format' ), time() + Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $eo_open ) ) ), wp_strip_all_tags( $eo_screen_note ) );
$eo_check( 'time left wording', '45 min left' === Edit_Orders_For_WooCommerce_Orders_List::time_left( 45 * MINUTE_IN_SECONDS ) && '2 h left' === Edit_Orders_For_WooCommerce_Orders_List::time_left( 2 * HOUR_IN_SECONDS + 1200 ) && '3 d left' === Edit_Orders_For_WooCommerce_Orders_List::time_left( 3 * DAY_IN_SECONDS + 60 ) && '1 min left' === Edit_Orders_For_WooCommerce_Orders_List::time_left( 20 ) );

$eo_guest = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ), 0 );
$eo_agree( 'a guest order', $eo_guest, true );

$eo_cod = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ), null, 'cod' );
$eo_agree( 'an unpaid cash on delivery order (window from the order date)', $eo_cod, true );

$eo_total_before = $eo_open->get_total();
$eo_status       = $eo_open->get_status();
ob_start();
Edit_Orders_For_WooCommerce_Admin::render_meta_box( $eo_fresh( $eo_open->get_id() ) );
$eo_box   = ob_get_clean();
$eo_after = $eo_fresh( $eo_open->get_id() );
$eo_check( 'the Edit order box shows the note', false !== strpos( $eo_box, 'eofw-open-note' ) && false !== strpos( $eo_box, 'The customer can still change this order until' ) );
$eo_check( 'display only: total and status untouched', $eo_total_before === $eo_after->get_total() && $eo_status === $eo_after->get_status() );

// ===========================================================================
WP_CLI::log( '2. The badge goes when the window closes or the order ships' );
$eo_closed = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_closed->update_meta_data( Edit_Orders_For_WooCommerce_Customer_Rules::WINDOW_CLOSED_META, time() );
$eo_closed->save();
$eo_agree( 'closed early by the customer ("All good")', $eo_closed, false );

$eo_late = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_late->set_date_paid( time() - 2 * HOUR_IN_SECONDS );
$eo_late->save();
$eo_agree( 'paid two hours ago (one-hour window)', $eo_late, false );

$eo_tracked = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_agree( 'before a tracking number', $eo_tracked, true );
$eo_tracked = $eo_fresh( $eo_tracked->get_id() );
$eo_tracked->update_meta_data( '_tracking_number', 'TRK123' );
$eo_tracked->save();
$eo_agree( 'after a tracking number is added', $eo_tracked, false );

$eo_done = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_done->update_status( 'completed' );
$eo_agree( 'a completed order', $eo_done, false );

$eo_check( 'the badge is empty for a closed order', '' === Edit_Orders_For_WooCommerce_Orders_List::badge( $eo_fresh( $eo_late->get_id() ) ) );

// ===========================================================================
WP_CLI::log( '3. The view lists the right orders' );
$eo_ids      = Edit_Orders_For_WooCommerce_Orders_List::open_order_ids();
$eo_expected = array( $eo_open->get_id(), $eo_guest->get_id(), $eo_cod->get_id() );
$eo_shut     = array( $eo_closed->get_id(), $eo_late->get_id(), $eo_tracked->get_id(), $eo_done->get_id() );
$eo_check( 'lists every open order of this suite', array() === array_diff( $eo_expected, $eo_ids ), wp_json_encode( $eo_ids ) );
$eo_check( 'lists none of the closed ones', array() === array_intersect( $eo_shut, $eo_ids ) );
$eo_check( 'newest first', array_values( array_intersect( $eo_ids, $eo_expected ) ) === array( $eo_cod->get_id(), $eo_guest->get_id(), $eo_open->get_id() ) );

// Kept for a minute between Orders screens, and dropped as soon as an order changes.
$eo_check( 'the list is kept for the next Orders screen', is_array( get_transient( Edit_Orders_For_WooCommerce::OPEN_ORDERS_CACHE ) ) );
$eo_new = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_check( 'a newly paid order drops it', false === get_transient( Edit_Orders_For_WooCommerce::OPEN_ORDERS_CACHE ) );
Edit_Orders_For_WooCommerce_Orders_List::reset();
$eo_check( 'and the next Orders screen lists that order', in_array( $eo_new->get_id(), Edit_Orders_For_WooCommerce_Orders_List::open_order_ids(), true ) );
$eo_new->update_status( 'completed' );
$eo_check( 'a status change drops it too', false === get_transient( Edit_Orders_For_WooCommerce::OPEN_ORDERS_CACHE ) );
Edit_Orders_For_WooCommerce_Orders_List::reset();
$eo_ids = Edit_Orders_For_WooCommerce_Orders_List::open_order_ids();

$_GET[ Edit_Orders_For_WooCommerce_Orders_List::VIEW_ARG ] = '1';
if ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
	// The HPOS list table passes its order query through this filter.
	$eo_args = Edit_Orders_For_WooCommerce_Orders_List::filter_hpos_query(
		array(
			'type'  => 'shop_order',
			'limit' => 50,
		)
	);
	$eo_list = wc_get_orders( array_merge( $eo_args, array( 'return' => 'ids' ) ) );
} else {
	// The legacy list is the main posts query on edit.php?post_type=shop_order.
	$eo_main_before = isset( $GLOBALS['wp_the_query'] ) ? $GLOBALS['wp_the_query'] : null;
	set_current_screen( 'edit-shop_order' );
	$eo_query                = new WP_Query();
	$GLOBALS['wp_the_query'] = $eo_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- makes this the main query, as on the list screen; restored below.
	$eo_query->parse_query(
		array(
			'post_type'      => 'shop_order',
			'post_status'    => 'all',
			'posts_per_page' => 50,
			'fields'         => 'ids',
		)
	);
	Edit_Orders_For_WooCommerce_Orders_List::filter_legacy_query( $eo_query );
	$eo_list                 = $eo_query->get_posts();
	$GLOBALS['wp_the_query'] = $eo_main_before; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restoring the main query.
	unset( $GLOBALS['current_screen'] );
}
$eo_check( 'the list query with the view on returns only open orders', array() === array_diff( array_map( 'absint', $eo_list ), $eo_ids ) && array() === array_diff( $eo_expected, array_map( 'absint', $eo_list ) ), wp_json_encode( $eo_list ) );
$eo_views = Edit_Orders_For_WooCommerce_Orders_List::add_view( array( 'all' => '<a href="#" class="current" aria-current="page">All</a>' ) );
$eo_check( 'the view link is current and counts the open orders', false !== strpos( $eo_views['eofw_open'], 'class="current"' ) && false !== strpos( $eo_views['eofw_open'], '(' . number_format_i18n( count( $eo_ids ) ) . ')' ) && false === strpos( $eo_views['all'], 'current' ) );
unset( $_GET[ Edit_Orders_For_WooCommerce_Orders_List::VIEW_ARG ] );
$eo_none = Edit_Orders_For_WooCommerce_Orders_List::filter_hpos_query( array( 'limit' => 5 ) );
$eo_check( 'without the view the list query is untouched', array( 'limit' => 5 ) === $eo_none );

$eo_columns = Edit_Orders_For_WooCommerce_Orders_List::add_column(
	array(
		'order_number' => 'Order',
		'order_status' => 'Status',
		'order_total'  => 'Total',
	)
);
$eo_check( 'the Changes column sits after Status', array( 'order_number', 'order_status', 'edit_orders_window', 'order_total' ) === array_keys( $eo_columns ) );

$eo_set( array( 'customer_enabled' => 'no' ) );
$eo_check( 'customer changes off: no column, no view, no badge', array( 'a' => 'A' ) === Edit_Orders_For_WooCommerce_Orders_List::add_column( array( 'a' => 'A' ) ) && array() === Edit_Orders_For_WooCommerce_Orders_List::add_view( array() ) && '' === Edit_Orders_For_WooCommerce_Orders_List::badge( $eo_fresh( $eo_open->get_id() ) ) && array() === Edit_Orders_For_WooCommerce_Orders_List::open_order_ids() );
$eo_set( array( 'customer_enabled' => 'yes' ) );

// ===========================================================================
WP_CLI::log( '4. Excluded products and categories' );
$eo_set( array( 'excluded_products' => array( $eo_t['product']['EO-MUG'] ) ) );
$eo_excl = $eo_make_order( array( array( 'EO-TSHIRT', 1 ), array( 'EO-MUG', 1 ) ) );
$eo_args = Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_fresh( $eo_excl->get_id() ), '' );
$eo_check( 'excluded product: no actions, the plain line instead', is_array( $eo_args ) && array() === $eo_args['actions'] && true === $eo_args['excluded'] );
ob_start();
wc_get_template( 'myaccount/edit-order.php', $eo_args, 'edit-orders-for-woocommerce/', EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/' );
$eo_panel = ob_get_clean();
$eo_check( 'My Account shows "This order can\'t be changed online. Please contact us."', false !== strpos( $eo_panel, 'This order can&#039;t be changed online. Please contact us.' ) && false === strpos( $eo_panel, 'eofw-close' ) && false === strpos( $eo_panel, 'name="edit_orders_for_woocommerce_action"' ), wp_strip_all_tags( $eo_panel ) );
$eo_agree( 'an order with an excluded product', $eo_excl, false );
$eo_check( 'no "Change or cancel" in the My Account orders list', ! isset( Edit_Orders_For_WooCommerce_Frontend::order_action( array(), $eo_fresh( $eo_excl->get_id() ) )['edit_order'] ) );
$eo_result = $eo_process(
	$eo_excl,
	array(
		'edit_orders_for_woocommerce_action' => 'note',
		'customer_note'                      => 'Please knock.',
	)
);
$eo_check( 'a posted customer change is refused', 'error' === $eo_result['type'] && '' === $eo_fresh( $eo_excl->get_id() )->get_customer_note(), wp_json_encode( $eo_result ) );
$eo_plan  = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_fresh( $eo_excl->get_id() ),
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_item_id( $eo_excl, 'EO-MUG' ),
		),
	)
);
$eo_staff = is_wp_error( $eo_plan ) ? $eo_plan : Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_fresh( $eo_excl->get_id() ),
	array(
		array(
			'type'    => 'remove',
			'item_id' => $eo_item_id( $eo_excl, 'EO-MUG' ),
		),
	),
	'staff',
	array( 'expect' => $eo_plan->fingerprint() )
);
$eo_check( 'staff can still edit it (remove the mug, refunded)', ! is_wp_error( $eo_staff ) && -1 === (int) $eo_fresh( $eo_excl->get_id() )->get_qty_refunded_for_item( $eo_item_id( $eo_excl, 'EO-MUG' ) ), is_wp_error( $eo_staff ) ? $eo_staff->get_error_message() : '' );

// A variation counts through its product.
$eo_set( array( 'excluded_products' => array( wc_get_product( $eo_t['product']['EO-HOODIE-S'] )->get_parent_id() ) ) );
$eo_hood = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_agree( 'a variation of an excluded product', $eo_hood, false );
$eo_set( array( 'excluded_products' => array() ) );
$eo_agree( 'the same order once the product is no longer excluded', $eo_hood, true );

// Categories, with a subcategory.
$eo_parent     = wp_insert_term( 'EO Final sale ' . wp_rand( 1000, 9999 ), 'product_cat' );
$eo_child      = wp_insert_term( 'EO Custom ' . wp_rand( 1000, 9999 ), 'product_cat', array( 'parent' => $eo_parent['term_id'] ) );
$eo_t['terms'] = array( $eo_child['term_id'], $eo_parent['term_id'] );
$eo_mug_cats   = wc_get_product_term_ids( $eo_t['product']['EO-MUG'], 'product_cat' );
wp_set_object_terms( $eo_t['product']['EO-MUG'], array_merge( $eo_mug_cats, array( (int) $eo_child['term_id'] ) ), 'product_cat' );
$eo_set( array( 'excluded_categories' => array( (int) $eo_parent['term_id'] ) ) );
$eo_cat = $eo_make_order( array( array( 'EO-MUG', 1 ) ) );
$eo_agree( 'a product in a subcategory of an excluded category', $eo_cat, false );
$eo_tee = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_agree( 'an order without excluded products, same settings', $eo_tee, true );
$eo_set( array( 'excluded_categories' => array() ) );
wp_set_object_terms( $eo_t['product']['EO-MUG'], $eo_mug_cats, 'product_cat' );

// The settings form saves IDs of things that exist.
Edit_Orders_For_WooCommerce_Settings_Form::save(
	array(
		Edit_Orders_For_WooCommerce_Settings::OPTION => array(
			'customer_enabled'        => 'yes',
			'action_cancel'           => 'yes',
			'action_address_shipping' => 'yes',
			'action_address_billing'  => 'yes',
			'action_swap'             => 'yes',
			'action_note'             => 'yes',
			'excluded_products'       => array( '', (string) $eo_t['product']['EO-MUG'], '999999', 'abc' ),
			'excluded_categories'     => array( '', (string) $eo_parent['term_id'], '888888' ),
			'cancel_reasons'          => '',
			'cancel_policy'           => '',
		),
	)
);
$eo_check(
	'settings save only existing products and categories',
	array( $eo_t['product']['EO-MUG'] ) === Edit_Orders_For_WooCommerce_Settings::excluded_products() && array( (int) $eo_parent['term_id'] ) === array_map( 'absint', (array) Edit_Orders_For_WooCommerce_Settings::get( 'excluded_categories' ) ),
	wp_json_encode( get_option( Edit_Orders_For_WooCommerce_Settings::OPTION ) )
);
ob_start();
Edit_Orders_For_WooCommerce_Settings_Form::render();
$eo_form = ob_get_clean();
$eo_check( 'the form shows both exclusion lists as searchable chips, with the saved ones ticked', false !== strpos( $eo_form, 'Never offer changes for these products' ) && false !== strpos( $eo_form, 'Never offer changes for these categories' ) && false !== strpos( $eo_form, 'value="' . $eo_t['product']['EO-MUG'] . '" checked' ) && 2 <= substr_count( $eo_form, 'data-w-chipfind' ) );
$eo_set(
	array(
		'excluded_products'   => array(),
		'excluded_categories' => array(),
	)
);

// ===========================================================================
WP_CLI::log( '5. Shipping and billing address switches' );
$eo_address_form = static function ( $type, array $place ) use ( $eo_places ) {
	return array(
		'edit_orders_for_woocommerce_action' => 'address',
		$type                                => array_merge(
			array(
				'first_name' => 'Casey',
				'last_name'  => 'Customer',
			),
			$place
		),
		'step'                               => 'confirm',
	);
};

$eo_set(
	array(
		'action_address_shipping' => 'yes',
		'action_address_billing'  => 'no',
	)
);
$eo_o = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_a = Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_fresh( $eo_o->get_id() ), '' );
$eo_check( 'shipping only: address offered, shipping form only', in_array( 'address', $eo_a['actions'], true ) && array( 'shipping' ) === $eo_a['address_types'] );
$eo_eo = $eo_process( $eo_o, $eo_address_form( 'billing', $eo_places['ON2'] ) );
$eo_check( 'shipping only: a posted billing change is ignored', 'Toronto' === $eo_fresh( $eo_o->get_id() )->get_billing_city(), wp_json_encode( $eo_eo ) );
$eo_eo = $eo_process( $eo_o, $eo_address_form( 'shipping', $eo_places['ON2'] ) );
$eo_check( 'shipping only: the shipping change is made', 'Ottawa' === $eo_fresh( $eo_o->get_id() )->get_shipping_city(), wp_json_encode( $eo_eo ) );

$eo_set(
	array(
		'action_address_shipping' => 'no',
		'action_address_billing'  => 'yes',
	)
);
$eo_o = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_a = Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_fresh( $eo_o->get_id() ), '' );
ob_start();
wc_get_template( 'myaccount/edit-order.php', $eo_a, 'edit-orders-for-woocommerce/', EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/' );
$eo_panel = ob_get_clean();
$eo_check( 'billing only: billing form only, with phone and without email', array( 'billing' ) === $eo_a['address_types'] && false !== strpos( $eo_panel, 'name="billing[address_1]"' ) && false !== strpos( $eo_panel, 'name="billing[phone]"' ) && false === strpos( $eo_panel, 'name="shipping[address_1]"' ) && false === strpos( $eo_panel, 'billing[email]' ) );
$eo_process( $eo_o, $eo_address_form( 'shipping', $eo_places['ON2'] ) );
$eo_check( 'billing only: a posted shipping change is ignored', 'Toronto' === $eo_fresh( $eo_o->get_id() )->get_shipping_city() );
$eo_eo = $eo_process( $eo_o, $eo_address_form( 'billing', $eo_places['ON2'] ) );
$eo_check( 'billing only: the billing change is made', 'Ottawa' === $eo_fresh( $eo_o->get_id() )->get_billing_city() && 'customer@example.com' === $eo_fresh( $eo_o->get_id() )->get_billing_email(), wp_json_encode( $eo_eo ) );

$eo_set(
	array(
		'action_address_shipping' => 'no',
		'action_address_billing'  => 'no',
	)
);
$eo_o = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_check( 'both off: no address action', ! in_array( 'address', Edit_Orders_For_WooCommerce_Customer_Rules::open_actions( $eo_fresh( $eo_o->get_id() ) ), true ) && ! Edit_Orders_For_WooCommerce_Settings::is_on( 'action_address' ) );
$eo_set(
	array(
		'action_address_shipping' => 'yes',
		'action_address_billing'  => 'yes',
	)
);
$eo_a = Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_fresh( $eo_o->get_id() ), '' );
$eo_check( 'both on: both forms', array( 'shipping', 'billing' ) === $eo_a['address_types'] );

// ===========================================================================
WP_CLI::log( '6. Upgrade from the single 1.2.x address setting' );
$eo_installed_before = get_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION, null );
$eo_db_version       = get_option( Edit_Orders_For_WooCommerce_Audit_Log::DB_VERSION_OPTION, null );
$eo_billing_on       = static function () {
	return Edit_Orders_For_WooCommerce_Settings::is_on( 'action_address_billing' );
};

// The first version that ran: an earlier release left the audit log's version option.
delete_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION );
update_option( Edit_Orders_For_WooCommerce_Audit_Log::DB_VERSION_OPTION, Edit_Orders_For_WooCommerce_Audit_Log::DB_VERSION );
Edit_Orders_For_WooCommerce_Settings::remember_installed_version();
$eo_check( 'a site that ran an earlier release is recorded as 1.2.1', '1.2.1' === get_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION ) );
delete_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION );
delete_option( Edit_Orders_For_WooCommerce_Audit_Log::DB_VERSION_OPTION );
Edit_Orders_For_WooCommerce_Settings::remember_installed_version();
$eo_check( 'a new site is recorded as this version', EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION === get_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION ) );
update_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION, '1.2.1' );
Edit_Orders_For_WooCommerce_Settings::remember_installed_version();
$eo_check( 'the recorded version never changes afterwards', '1.2.1' === get_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION ) );
null === $eo_db_version ? delete_option( Edit_Orders_For_WooCommerce_Audit_Log::DB_VERSION_OPTION ) : update_option( Edit_Orders_For_WooCommerce_Audit_Log::DB_VERSION_OPTION, $eo_db_version );

// An updated store (1.2.1 or earlier ran first).
update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array( 'action_address' => 'no' ) );
$eo_check( '1.2.x "address changes" off: both switches start off', ! Edit_Orders_For_WooCommerce_Settings::is_on( 'action_address_shipping' ) && ! $eo_billing_on() && ! in_array( 'address', Edit_Orders_For_WooCommerce_Customer_Rules::enabled_actions(), true ) );
$eo_cur = Edit_Orders_For_WooCommerce_Settings_Form::current();
$eo_check( 'the settings page shows both off', 'no' === $eo_cur['action_address_shipping'] && 'no' === $eo_cur['action_address_billing'] );
update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array( 'action_address' => 'yes' ) );
$eo_check( '1.2.x on: shipping starts on, billing (new in 1.3.0) starts off', Edit_Orders_For_WooCommerce_Settings::is_on( 'action_address_shipping' ) && ! $eo_billing_on() );
$eo_cur = Edit_Orders_For_WooCommerce_Settings_Form::current();
$eo_check( 'the settings page shows shipping on, billing off', 'yes' === $eo_cur['action_address_shipping'] && 'no' === $eo_cur['action_address_billing'] );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
$eo_check( 'an updated store that never saved settings: shipping on, billing off', Edit_Orders_For_WooCommerce_Settings::is_on( 'action_address_shipping' ) && ! $eo_billing_on() );
update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array( 'action_address_billing' => 'yes' ) );
$eo_check( 'an updated store that turns billing on keeps it on', $eo_billing_on() );

// A store that started on 1.3.0 or later.
update_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION, '1.3.0' );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
$eo_check( 'a new store: both on', Edit_Orders_For_WooCommerce_Settings::is_on( 'action_address_shipping' ) && $eo_billing_on() );
update_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION, '1.2.1' );
update_option(
	Edit_Orders_For_WooCommerce_Settings::OPTION,
	array(
		'action_address'          => 'no',
		'action_address_shipping' => 'yes',
	)
);
$eo_check( 'once saved, the new switches win over the old one', Edit_Orders_For_WooCommerce_Settings::is_on( 'action_address_shipping' ) && ! Edit_Orders_For_WooCommerce_Settings::is_on( 'action_address_billing' ) );
update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array( 'action_address' => 'no' ) );
Edit_Orders_For_WooCommerce_Settings_Form::save(
	array(
		Edit_Orders_For_WooCommerce_Settings::OPTION => array(
			'customer_enabled'        => 'yes',
			'action_address_shipping' => 'yes',
			'action_address_billing'  => 'no',
			'cancel_reasons'          => '',
			'cancel_policy'           => '',
		),
	)
);
$eo_stored = (array) get_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
$eo_check( 'saving the form drops the old key and keeps the two new ones', ! isset( $eo_stored['action_address'] ) && 'yes' === $eo_stored['action_address_shipping'] && 'no' === $eo_stored['action_address_billing'] );
delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
null === $eo_installed_before ? delete_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION ) : update_option( Edit_Orders_For_WooCommerce_Settings::INSTALLED_OPTION, $eo_installed_before );
$eo_set( array() );

// ===========================================================================
WP_CLI::log( '7. The order note for customer address changes' );
$eo_o   = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_r   = $eo_process( $eo_o, $eo_address_form( 'shipping', $eo_places['ON2'] ) );
$eo_n   = $eo_notes( $eo_fresh( $eo_o->get_id() ) );
$eo_hit = null;
foreach ( $eo_n as $eo_note ) {
	if ( 0 === strpos( $eo_note->content, 'Shipping address changed by the customer: ' ) ) {
		$eo_hit = $eo_note;
	}
}
$eo_check( 'note: "Shipping address changed by the customer: 1 Yonge St, Toronto M5E 1E5 -> 50 King St, Ottawa K1P 5N2"', $eo_hit && 'Shipping address changed by the customer: 1 Yonge St, Toronto M5E 1E5 -> 50 King St, Ottawa K1P 5N2' === $eo_hit->content, $eo_hit ? $eo_hit->content : wp_json_encode( wp_list_pluck( $eo_n, 'content' ) ) );
$eo_check( 'the note is private', $eo_hit && ! $eo_hit->customer_note );
$eo_check( 'only the fields that changed (the province and country did not)', $eo_hit && false === strpos( $eo_hit->content, ' ON' ) && false === strpos( $eo_hit->content, 'CA' ) );
$eo_repeats = array_filter(
	wp_list_pluck( $eo_n, 'content' ),
	static function ( $content ) {
		return false !== strpos( $content, 'address changed to' );
	}
);
$eo_check( 'no second note repeats the new address', ! $eo_repeats );

$eo_set( array( 'action_address_billing' => 'yes' ) );
$eo_o                            = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_form                         = $eo_address_form( 'billing', $eo_places['ON'] );
$eo_form['billing']['address_2'] = 'Unit 4';
$eo_form['billing']['phone']     = '416 555 0100';
$eo_process( $eo_o, $eo_form );
$eo_contents = wp_list_pluck( $eo_notes( $eo_fresh( $eo_o->get_id() ) ), 'content' );
$eo_check( 'billing note with a field that was empty: "none -> Unit 4, none -> 416 555 0100"', in_array( 'Billing address changed by the customer: none, none -> Unit 4, 416 555 0100', $eo_contents, true ), wp_json_encode( $eo_contents ) );

// Through a balance order: the note is written when the balance is paid, still by the customer.
$eo_o = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_process( $eo_o, $eo_address_form( 'shipping', $eo_places['AB'] ) );
$eo_balance = Edit_Orders_For_WooCommerce_Balance_Orders::get_open_balance_order( $eo_fresh( $eo_o->get_id() ) );
if ( $eo_balance ) {
	$eo_t['orders'][] = $eo_balance->get_id();
	$eo_balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$eo_balance->save();
	$eo_balance->payment_complete( 'TEST-BAL-' . $eo_balance->get_id() );
}
$eo_contents = wp_list_pluck( $eo_notes( $eo_fresh( $eo_o->get_id() ) ), 'content' );
$eo_check( 'after a balance order is paid: the from -> to note, by the customer', $eo_balance && in_array( 'Shipping address changed by the customer: 1 Yonge St, Toronto ON M5E 1E5 -> 100 8 Ave SW, Calgary AB T2P 1B3', $eo_contents, true ), wp_json_encode( $eo_contents ) );
$eo_check(
	'and the change note says it was the customer',
	(bool) array_filter(
		$eo_contents,
		static function ( $content ) {
			return 0 === strpos( $content, 'Order changed by the customer (paid through balance order #' );
		}
	),
	wp_json_encode( $eo_contents )
);

// A customer swap: the change note names the customer.
$eo_o = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_process(
	$eo_o,
	array(
		'edit_orders_for_woocommerce_action' => 'swap',
		'swap'                               => array( $eo_item_id( $eo_o, 'EO-HOODIE-S' ) => $eo_t['product']['EO-HOODIE-M'] ),
		'step'                               => 'confirm',
	)
);
$eo_contents = wp_list_pluck( $eo_notes( $eo_fresh( $eo_o->get_id() ) ), 'content' );
$eo_check(
	'a customer swap note: "Order changed by the customer: Swapped ..."',
	(bool) array_filter(
		$eo_contents,
		static function ( $content ) {
			return 0 === strpos( $content, 'Order changed by the customer: Swapped "Hoodie - S" for "Hoodie - M".' );
		}
	),
	wp_json_encode( $eo_contents )
);

// Staff address changes keep their note.
wp_set_current_user( 1 );
$eo_o       = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_changes = array(
	array(
		'type'     => 'address',
		'shipping' => array_merge(
			array(
				'first_name' => 'Casey',
				'last_name'  => 'Customer',
			),
			$eo_places['ON2']
		),
	),
);
$eo_plan    = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_fresh( $eo_o->get_id() ), $eo_changes );
Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_fresh( $eo_o->get_id() ), $eo_changes, 'staff', array( 'expect' => $eo_plan->fingerprint() ) );
$eo_contents = wp_list_pluck( $eo_notes( $eo_fresh( $eo_o->get_id() ) ), 'content' );
$eo_check(
	'staff note unchanged: "Order edited: Shipping address changed to ..."',
	(bool) array_filter(
		$eo_contents,
		static function ( $content ) {
			return 0 === strpos( $content, 'Order edited: Shipping address changed to 50 King St, Ottawa, ON, K1P 5N2, CA.' );
		}
	),
	wp_json_encode( $eo_contents )
);
wp_set_current_user( $eo_customer->ID );

// ===========================================================================
WP_CLI::log( '8. No notices, warnings or errors from the plugin' );
$eo_ours = array();
if ( file_exists( $eo_debug_log ) ) {
	clearstatcache();
	$eo_new = (string) file_get_contents( $eo_debug_log, false, null, $eo_debug_from ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	foreach ( explode( "\n", $eo_new ) as $eo_line ) {
		if ( false !== strpos( $eo_line, 'edit-orders-for-woocommerce' ) ) {
			$eo_ours[] = $eo_line;
		}
	}
}
$eo_check( 'debug.log has nothing from the plugin', empty( $eo_ours ), implode( ' | ', array_slice( $eo_ours, 0, 3 ) ) );

// ===========================================================================
WP_CLI::log( 'Cleanup' );
wp_set_current_user( 1 );
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
foreach ( $eo_t['terms'] as $eo_term ) {
	wp_delete_term( $eo_term, 'product_cat' );
}
if ( null === $eo_settings_before ) {
	delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
} else {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $eo_settings_before );
}
delete_option( Edit_Orders_Mail_Log::OPTION );

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'M12 suite failed.' );
}
WP_CLI::success( 'M12 suite passed.' );
