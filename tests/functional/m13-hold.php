<?php
/**
 * Functional suite for 1.4.0, section 1 (tasks/edit-orders-1.4-spec.md, section 5.1):
 * the "Open for changes" hold. Off by default; with it on, paid orders wait in their own
 * status until the time for changes ends, then move to Processing once.
 *
 * Run with: bash tests/functional/run.sh m13-hold (or all suites without an argument).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$eo_t = array(
	'pass'     => 0,
	'fail'     => 0,
	'orders'   => array(),
	'stock'    => array(),
	'product'  => array(),
	'products' => array(),
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

// A virtual, downloadable product, for an order that needs no processing (WooCommerce
// completes those at payment; a virtual-only product still goes to Processing).
$eo_virtual = new WC_Product_Simple();
$eo_virtual->set_name( 'EO Virtual Gift Card' );
$eo_virtual->set_regular_price( '25' );
$eo_virtual->set_virtual( true );
$eo_virtual->set_downloadable( true );
$eo_virtual->save();
$eo_t['product']['EO-VIRTUAL'] = $eo_virtual->get_id();
$eo_t['products'][]            = $eo_virtual->get_id();

$eo_customer = get_user_by( 'login', 'customer' );
wp_set_current_user( $eo_customer->ID );
WC()->payment_gateways();

$eo_held_status = Edit_Orders_For_WooCommerce_Hold::STATUS;

/**
 * A pending Ontario order, not yet paid.
 */
$eo_new_order = static function ( array $lines, $gateway = 'edit_orders_test', $customer_id = null ) use ( &$eo_t, $eo_customer ) {
	$order   = wc_create_order( array( 'customer_id' => null === $customer_id ? $eo_customer->ID : $customer_id ) );
	$address = array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '1 Yonge St',
		'city'       => 'Toronto',
		'state'      => 'ON',
		'postcode'   => 'M5E 1E5',
		'country'    => 'CA',
	);
	$order->set_address( array_merge( $address, array( 'email' => 'customer@example.com' ) ), 'billing' );
	$order->set_address( $address, 'shipping' );
	foreach ( $lines as $line ) {
		$order->add_product( wc_get_product( $eo_t['product'][ $line[0] ] ), $line[1] );
	}
	$order->calculate_totals();
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()[ $gateway ] );
	$order->save();
	$eo_t['orders'][] = $order->get_id();

	return $order;
};

/**
 * Paid through the gateway: payment_complete() for the test gateway, the checkout for cash on delivery.
 */
$eo_pay = static function ( WC_Order $order ) {
	if ( 'cod' === $order->get_payment_method() || 'bacs' === $order->get_payment_method() ) {
		WC()->payment_gateways()->payment_gateways()[ $order->get_payment_method() ]->process_payment( $order->get_id() );
	} else {
		$order->payment_complete( 'TEST-' . $order->get_id() );
	}
	Edit_Orders_For_WooCommerce_Customer_Rules::reset_cache();

	return wc_get_order( $order->get_id() );
};

$eo_paid_order = static function ( array $lines, $gateway = 'edit_orders_test' ) use ( $eo_new_order, $eo_pay ) {
	return $eo_pay( $eo_new_order( $lines, $gateway ) );
};

$eo_fresh = static function ( $order_id ) {
	Edit_Orders_For_WooCommerce_Customer_Rules::reset_cache();
	Edit_Orders_For_WooCommerce_Orders_List::reset();
	return wc_get_order( $order_id );
};

$eo_notes = static function ( WC_Order $order ) {
	return wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
};

$eo_has_note = static function ( WC_Order $order, $start ) use ( $eo_notes ) {
	foreach ( $eo_notes( $order ) as $note ) {
		if ( 0 === strpos( $note, $start ) ) {
			return true;
		}
	}
	return false;
};

$eo_log_rows = static function ( $order_id, $action ) use ( $wpdb ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d AND action = %s ORDER BY id', Edit_Orders_For_WooCommerce_Audit_Log::table(), $order_id, $action ), ARRAY_A );
};

$eo_scheduled = static function ( $order_id ) {
	return as_next_scheduled_action( Edit_Orders_For_WooCommerce_Hold::ACTION, array( (int) $order_id ), Edit_Orders_For_WooCommerce_Hold::GROUP );
};

// Move an order's window into the past: paid (or placed) two hours ago; the window is the default hour.
$eo_expire = static function ( WC_Order $order ) use ( $eo_fresh ) {
	$order = $eo_fresh( $order->get_id() );
	if ( $order->get_date_paid() ) {
		$order->set_date_paid( time() - 2 * HOUR_IN_SECONDS );
	}
	$order->set_date_created( time() - 2 * HOUR_IN_SECONDS );
	$order->save();
	return $eo_fresh( $order->get_id() );
};

// Counters: the hold's own hooks, and WooCommerce's processing hook per order.
$eo_events = array(
	'started'    => array(),
	'released'   => array(),
	'processing' => array(),
);
add_action(
	'edit_orders_for_woocommerce_hold_started',
	static function ( $order, $release_at ) use ( &$eo_events ) {
		$eo_events['started'][ $order->get_id() ] = $release_at;
	},
	10,
	2
);
add_action(
	'edit_orders_for_woocommerce_hold_released',
	static function ( $order, $reason ) use ( &$eo_events ) {
		$eo_events['released'][ $order->get_id() ] = $reason;
	},
	10,
	2
);
add_action(
	'woocommerce_order_status_processing',
	static function ( $order_id ) use ( &$eo_events ) {
		$eo_events['processing'][ $order_id ] = ( isset( $eo_events['processing'][ $order_id ] ) ? $eo_events['processing'][ $order_id ] : 0 ) + 1;
	}
);

// ===========================================================================
WP_CLI::log( '1. The status is registered; the hold is off by default' );
$eo_check( 'wc_get_order_statuses() lists "Open for changes"', isset( wc_get_order_statuses()[ 'wc-' . $eo_held_status ] ) && 'Open for changes' === wc_get_order_statuses()[ 'wc-' . $eo_held_status ] );
$eo_check( 'registered as a post status (legacy storage)', in_array( 'wc-' . $eo_held_status, get_post_stati(), true ) );
$eo_check( 'the setting defaults to off', 'no' === Edit_Orders_For_WooCommerce_Settings::get( 'hold_enabled' ) && ! Edit_Orders_For_WooCommerce_Hold::is_on() );
$eo_check( 'not offered in the editable statuses list', ! isset( Edit_Orders_For_WooCommerce_Settings_Form::status_options()[ $eo_held_status ] ) );
$eo_check( 'not editable while the hold is off', ! in_array( $eo_held_status, Edit_Orders_For_WooCommerce_Eligibility::editable_statuses(), true ) );

$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_check( 'hold off: a paid order goes to Processing as in 1.3', $eo_o->has_status( 'processing' ), $eo_o->get_status() );
$eo_check( 'hold off: no release scheduled', false === $eo_scheduled( $eo_o->get_id() ) );
$eo_check( 'hold off: nothing counted as held', 0 === (int) wc_orders_count( $eo_held_status ) );
$eo_off_mails = count( (array) get_option( Edit_Orders_Mail_Log::OPTION, array() ) );

// ===========================================================================
WP_CLI::log( '2. Entering the hold' );
$eo_set( array( 'hold_enabled' => 'yes' ) );
$eo_check( 'the hold is on', Edit_Orders_For_WooCommerce_Hold::is_on() );
$eo_check( 'editable statuses include Open for changes while it is on', in_array( $eo_held_status, Edit_Orders_For_WooCommerce_Eligibility::editable_statuses(), true ) );

delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_stock_before = wc_get_product( $eo_t['product']['EO-TSHIRT'] )->get_stock_quantity();
$eo_card         = $eo_paid_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_check( 'card payment: Open for changes', $eo_card->has_status( $eo_held_status ), $eo_card->get_status() );
$eo_check( 'card payment: the paid date is set', (bool) $eo_card->get_date_paid() );
$eo_check( 'card payment: note "Open for changes until ..."', $eo_has_note( $eo_card, 'Open for changes until ' ) );
$eo_check( 'card payment: a hold_started log row', 1 === count( $eo_log_rows( $eo_card->get_id(), 'hold_started' ) ) );
$eo_check( 'card payment: release scheduled at the window end', abs( (int) $eo_scheduled( $eo_card->get_id() ) - ( time() + Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $eo_card ) ) ) <= 5, (string) $eo_scheduled( $eo_card->get_id() ) );
$eo_check( 'edit_orders_for_woocommerce_hold_started fired with the release time', isset( $eo_events['started'][ $eo_card->get_id() ] ) && abs( $eo_events['started'][ $eo_card->get_id() ] - (int) $eo_scheduled( $eo_card->get_id() ) ) <= 5 );
$eo_check( 'stock reduced once, at payment', wc_get_product( $eo_t['product']['EO-TSHIRT'] )->get_stock_quantity() === $eo_stock_before - 2, $eo_stock_before . ' -> ' . wc_get_product( $eo_t['product']['EO-TSHIRT'] )->get_stock_quantity() );
$eo_check( 'stock marked as reduced on the order', (bool) $eo_card->get_data_store()->get_stock_reduced( $eo_card->get_id() ) );
$eo_mails = (array) get_option( Edit_Orders_Mail_Log::OPTION, array() );
$eo_check( 'the same emails at payment as without the hold (customer and store)', count( $eo_mails ) === $eo_off_mails && 2 === count( $eo_mails ), count( $eo_mails ) . ' vs ' . $eo_off_mails . ': ' . wp_json_encode( wp_list_pluck( $eo_mails, 'subject' ) ) );
$eo_check( 'WooCommerce\'s processing hook has not run yet', empty( $eo_events['processing'][ $eo_card->get_id() ] ) );

$eo_cod_stock = wc_get_product( $eo_t['product']['EO-MUG'] )->get_stock_quantity();
$eo_cod       = $eo_paid_order( array( array( 'EO-MUG', 1 ) ), 'cod' );
$eo_check( 'cash on delivery checkout: Open for changes', $eo_cod->has_status( $eo_held_status ), $eo_cod->get_status() );
$eo_check( 'cash on delivery: still no paid date (paid at the door)', ! $eo_cod->get_date_paid() );
$eo_check( 'cash on delivery: stock reduced at checkout', wc_get_product( $eo_t['product']['EO-MUG'] )->get_stock_quantity() === $eo_cod_stock - 1 );
$eo_check( 'cash on delivery: release scheduled', (bool) $eo_scheduled( $eo_cod->get_id() ) );
Edit_Orders_For_WooCommerce_Hold::release( $eo_cod, 'window_end' );
$eo_cod = $eo_fresh( $eo_cod->get_id() );
$eo_check( 'cash on delivery released: Processing, still unpaid, still collected at the door', $eo_cod->has_status( 'processing' ) && ! $eo_cod->get_date_paid() && Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $eo_cod ), $eo_cod->get_status() );

// Never held.
$eo_o = $eo_new_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_o->update_status( 'processing', 'Moved by hand.' );
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_check( 'moved to Processing by hand: not held', $eo_o->has_status( 'processing' ) );
$eo_check( 'moved to Processing by hand: the paid date is still set (WooCommerce\'s own rule)', (bool) $eo_o->get_date_paid() );

$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ), 'bacs' );
$eo_check( 'bank transfer: On hold, not held', $eo_o->has_status( 'on-hold' ), $eo_o->get_status() );
$eo_o->payment_complete( 'BACS-' . $eo_o->get_id() );
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_check( 'an On hold order paid later (authorize-only style): Processing, not held', $eo_o->has_status( 'processing' ), $eo_o->get_status() );

$eo_o = $eo_paid_order( array( array( 'EO-VIRTUAL', 1 ) ) );
$eo_check( 'virtual and downloadable order: Completed, never held', $eo_o->has_status( 'completed' ), $eo_o->get_status() );

$eo_o = $eo_new_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_o->set_created_via( 'admin' );
$eo_o->save();
$eo_o = $eo_pay( $eo_o );
$eo_check( 'order created by staff in the admin: not held', $eo_o->has_status( 'processing' ), $eo_o->get_status() );

$eo_parent  = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_balance = $eo_new_order( array( array( 'EO-MUG', 1 ) ) );
$eo_balance->set_parent_id( $eo_parent->get_id() );
$eo_balance->set_created_via( Edit_Orders_For_WooCommerce_Balance_Orders::CREATED_VIA );
$eo_balance->save();
$eo_balance = $eo_pay( $eo_balance );
$eo_check( 'balance order: not held', ! $eo_balance->has_status( $eo_held_status ), $eo_balance->get_status() );

$eo_set( array( 'excluded_products' => array( $eo_t['product']['EO-MUG'] ) ) );
$eo_o = $eo_paid_order( array( array( 'EO-MUG', 1 ) ) );
$eo_check( 'order with an excluded product: not held', $eo_o->has_status( 'processing' ), $eo_o->get_status() );
$eo_set( array( 'excluded_products' => array() ) );

if ( function_exists( 'wcs_order_contains_renewal' ) ) {
	$eo_check( 'subscription renewals: checked through WooCommerce Subscriptions', true );
} else {
	WP_CLI::log( '  SKIP  renewal order: WooCommerce Subscriptions is not on this store (the check is skipped without it)' );
}

add_filter( 'edit_orders_for_woocommerce_should_hold', '__return_false' );
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_check( 'edit_orders_for_woocommerce_should_hold can stop a hold', $eo_o->has_status( 'processing' ), $eo_o->get_status() );
remove_filter( 'edit_orders_for_woocommerce_should_hold', '__return_false' );

// ===========================================================================
WP_CLI::log( '3. A held order is paid, downloadable and editable' );
$eo_card = $eo_fresh( $eo_card->get_id() );
$eo_check( 'is_paid()', $eo_card->is_paid() );
$eo_check( 'downloads as for Processing', $eo_card->is_download_permitted() === ( 'yes' === get_option( 'woocommerce_downloads_grant_access_after_payment' ) ) );
$eo_check( 'in WooCommerce\'s paid statuses', in_array( $eo_held_status, wc_get_is_paid_statuses(), true ) );
$eo_check( 'the legacy reports count it', in_array( $eo_held_status, (array) apply_filters( 'woocommerce_reports_order_statuses', array( 'completed', 'processing', 'on-hold' ) ), true ) );
if ( is_callable( array( '\Automattic\WooCommerce\Internal\Admin\Settings', 'get_default_excluded_order_statuses' ) ) ) {
	$eo_check( 'Analytics doesn\'t exclude it', ! in_array( $eo_held_status, (array) \Automattic\WooCommerce\Internal\Admin\Settings::get_default_excluded_order_statuses(), true ) );
}
$eo_check( 'the customer can change it', true === Edit_Orders_For_WooCommerce_Customer_Rules::can( $eo_card, 'address', 'customer' ) );
$eo_args = Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_card, '' );
$eo_check( 'the panel shows, marked as held', is_array( $eo_args ) && ! empty( $eo_args['actions'] ) && ! empty( $eo_args['held'] ) );
$eo_check( 'the Orders screen badge shows', '' !== Edit_Orders_For_WooCommerce_Orders_List::badge( $eo_card ) );
$eo_check( 'and it is in "Customer can still change"', in_array( $eo_card->get_id(), Edit_Orders_For_WooCommerce_Orders_List::open_order_ids(), true ) );
ob_start();
wc_get_template( 'myaccount/edit-order.php', $eo_args, 'edit-orders-for-woocommerce/', EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/' );
$eo_html = (string) ob_get_clean();
$eo_check( 'the panel says "You can still change this order until ..."', false !== strpos( $eo_html, 'You can still change this order until ' ), substr( wp_strip_all_tags( $eo_html ), 0, 200 ) );
wp_set_current_user( 1 );
$eo_check( 'staff can edit it', true === Edit_Orders_For_WooCommerce_Eligibility::check_order( $eo_card ) );
ob_start();
Edit_Orders_For_WooCommerce_Admin::render_meta_box( $eo_card );
$eo_box = (string) ob_get_clean();
$eo_check( 'the Edit order box offers "Release now"', false !== strpos( $eo_box, 'Release now' ) && false !== strpos( $eo_box, Edit_Orders_For_WooCommerce_Hold::RELEASE_NOW ) );
wp_set_current_user( $eo_customer->ID );

// A staff edit keeps the order held.
wp_set_current_user( 1 );
$eo_edit = $eo_paid_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_item = current( $eo_edit->get_items() );
$eo_r    = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_edit,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item->get_id(),
			'quantity' => 1,
		),
	),
	'staff'
);
$eo_edit = $eo_fresh( $eo_edit->get_id() );
$eo_check( 'a staff edit (refund) on a held order: applied, still held', ! is_wp_error( $eo_r ) && $eo_edit->has_status( $eo_held_status ), is_wp_error( $eo_r ) ? $eo_r->get_error_message() : $eo_edit->get_status() );
wp_set_current_user( $eo_customer->ID );

// ===========================================================================
WP_CLI::log( '4. Each way out' );

// The window ends.
$eo_o           = $eo_expire( $eo_card );
$eo_stock_ahead = wc_get_product( $eo_t['product']['EO-TSHIRT'] )->get_stock_quantity();
Edit_Orders_For_WooCommerce_Hold::release_scheduled( $eo_o->get_id() );
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_check( 'window end: Processing', $eo_o->has_status( 'processing' ), $eo_o->get_status() );
$eo_check( 'window end: note', $eo_has_note( $eo_o, 'Released to Processing: the time for changes ended.' ) );
$eo_rows = $eo_log_rows( $eo_o->get_id(), 'hold_released' );
$eo_check( 'window end: a hold_released log row with the reason', 1 === count( $eo_rows ) && 'window_end' === json_decode( $eo_rows[0]['after_data'], true )['reason'] );
$eo_check( 'window end: hold_released fired with "window_end"', isset( $eo_events['released'][ $eo_o->get_id() ] ) && 'window_end' === $eo_events['released'][ $eo_o->get_id() ] );
$eo_check( 'WooCommerce\'s processing hook ran once, at the release', 1 === (int) ( isset( $eo_events['processing'][ $eo_o->get_id() ] ) ? $eo_events['processing'][ $eo_o->get_id() ] : 0 ) );
$eo_check(
	'no email at the release',
	2 === count(
		array_filter(
			(array) get_option( Edit_Orders_Mail_Log::OPTION, array() ),
			static function ( $mail ) use ( $eo_o ) {
				return false !== strpos( $mail['subject'] . $mail['message'], '#' . $eo_o->get_order_number() );
			}
		)
	),
	wp_json_encode( wp_list_pluck( (array) get_option( Edit_Orders_Mail_Log::OPTION, array() ), 'subject' ) )
); // phpcs:ignore Generic.Formatting.MultipleStatementAlignment
$eo_check( 'stock not reduced again at the release', wc_get_product( $eo_t['product']['EO-TSHIRT'] )->get_stock_quantity() === $eo_stock_ahead );
$eo_check( 'no release left scheduled', false === $eo_scheduled( $eo_o->get_id() ) );

// The time was moved later (N1): the scheduled run waits instead of releasing.
$eo_o     = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_later = static function ( $end ) {
	return $end + DAY_IN_SECONDS;
};
add_filter( 'edit_orders_for_woocommerce_window_end', $eo_later );
Edit_Orders_For_WooCommerce_Hold::release_scheduled( $eo_o->get_id() );
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_check( 'deadline moved later: still held', $eo_o->has_status( $eo_held_status ), $eo_o->get_status() );
$eo_check( 'deadline moved later: rescheduled for the new time', (int) $eo_scheduled( $eo_o->get_id() ) > time() + DAY_IN_SECONDS - HOUR_IN_SECONDS );
remove_filter( 'edit_orders_for_woocommerce_window_end', $eo_later );

// The customer says all good.
$eo_r = Edit_Orders_For_WooCommerce_Frontend::process( $eo_o, array( 'edit_orders_for_woocommerce_action' => 'close' ), 'customer' );
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_check( '"All good": Processing', 'success' === $eo_r['type'] && $eo_o->has_status( 'processing' ), $eo_o->get_status() );
$eo_check( '"All good": reason customer_closed', isset( $eo_events['released'][ $eo_o->get_id() ] ) && 'customer_closed' === $eo_events['released'][ $eo_o->get_id() ] );
$eo_check( '"All good": note', $eo_has_note( $eo_o, 'Released to Processing: the customer confirmed the order as it is.' ) );

// A tracking number.
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_o->update_meta_data( '_tracking_number', 'TRACK123' );
$eo_o->save();
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_check( 'tracking number added: Processing', $eo_o->has_status( 'processing' ), $eo_o->get_status() );
$eo_check( 'tracking number added: reason shipped', isset( $eo_events['released'][ $eo_o->get_id() ] ) && 'shipped' === $eo_events['released'][ $eo_o->get_id() ] );

// WooCommerce Fulfillments.
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_o->update_meta_data( '_fulfillment_status', 'fulfilled' );
$eo_o->save();
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_check( 'fulfilled (WooCommerce Fulfillments): Processing, reason shipped', $eo_o->has_status( 'processing' ) && isset( $eo_events['released'][ $eo_o->get_id() ] ) && 'shipped' === $eo_events['released'][ $eo_o->get_id() ] );

// Staff "Release now".
wp_set_current_user( 1 );
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_check( '"Release now" link carries a nonce for the order', false !== strpos( Edit_Orders_For_WooCommerce_Hold::release_now_url( $eo_o ), '_wpnonce=' ) && false !== strpos( Edit_Orders_For_WooCommerce_Hold::release_now_url( $eo_o ), 'order_id=' . $eo_o->get_id() ) );
Edit_Orders_For_WooCommerce_Hold::release( $eo_o, 'staff' );
$eo_o    = $eo_fresh( $eo_o->get_id() );
$eo_rows = $eo_log_rows( $eo_o->get_id(), 'hold_released' );
$eo_check( '"Release now": Processing, by the store', $eo_o->has_status( 'processing' ) && 'staff' === $eo_rows[0]['actor_type'] && 1 === (int) $eo_rows[0]['actor_id'] );
$eo_check( '"Release now": note names the user', $eo_has_note( $eo_o, 'Released to Processing by ' ) );
wp_set_current_user( $eo_customer->ID );

// The sweep, with the scheduled action gone (cron off).
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
Edit_Orders_For_WooCommerce_Hold::unschedule( $eo_o->get_id() );
$eo_o = $eo_expire( $eo_o );
Edit_Orders_For_WooCommerce_Hold::sweep( true );
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_check( 'sweep: an expired held order is released', $eo_o->has_status( 'processing' ) && isset( $eo_events['released'][ $eo_o->get_id() ] ) && 'sweep' === $eo_events['released'][ $eo_o->get_id() ] );
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
Edit_Orders_For_WooCommerce_Hold::sweep( true );
$eo_check( 'sweep: one still in its window stays held', $eo_fresh( $eo_o->get_id() )->has_status( $eo_held_status ) );
delete_transient( Edit_Orders_For_WooCommerce_Hold::SWEEP_TRANSIENT );
Edit_Orders_For_WooCommerce_Hold::sweep();
$eo_check( 'sweep: at most once a minute unless forced', 0 === Edit_Orders_For_WooCommerce_Hold::sweep() && false !== get_transient( Edit_Orders_For_WooCommerce_Hold::SWEEP_TRANSIENT ) );
delete_transient( Edit_Orders_For_WooCommerce_Hold::SWEEP_TRANSIENT );

// Cancelled while held, instantly: cancelled and refunded; the scheduled run does nothing later.
$eo_set( array( 'cancel_mode' => 'instant' ) );
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_r = Edit_Orders_For_WooCommerce_Frontend::process(
	$eo_o,
	array(
		'edit_orders_for_woocommerce_action' => 'cancel',
		'reason'                             => '',
	),
	'customer'
);
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_check( 'cancelled while held: Cancelled and refunded', $eo_o->has_status( 'cancelled' ) && (float) $eo_o->get_total_refunded() === (float) $eo_o->get_total(), $eo_o->get_status() . ' ' . $eo_o->get_total_refunded() );
Edit_Orders_For_WooCommerce_Hold::release_scheduled( $eo_o->get_id() );
$eo_check( 'cancelled while held: the scheduled release does nothing', $eo_fresh( $eo_o->get_id() )->has_status( 'cancelled' ) && empty( $eo_events['released'][ $eo_o->get_id() ] ) );

// A cancellation request on a held order can be approved.
$eo_set( array( 'cancel_mode' => 'approval' ) );
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
Edit_Orders_For_WooCommerce_Frontend::process(
	$eo_o,
	array(
		'edit_orders_for_woocommerce_action' => 'cancel',
		'reason'                             => '',
	),
	'customer'
);
wp_set_current_user( 1 );
$eo_r = Edit_Orders_For_WooCommerce_Cancellation::approve( $eo_fresh( $eo_o->get_id() ) );
wp_set_current_user( $eo_customer->ID );
$eo_check( 'a cancel request on a held order: approving cancels it', ! is_wp_error( $eo_r ) && $eo_fresh( $eo_o->get_id() )->has_status( 'cancelled' ), is_wp_error( $eo_r ) ? $eo_r->get_error_message() : '' );

// ===========================================================================
WP_CLI::log( '5. Turning the hold off releases everything (more than one batch)' );
$eo_many = array();
for ( $eo_i = 0; $eo_i < 60; $eo_i++ ) {
	$eo_many[] = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) )->get_id();
}
$eo_check( '60 orders held', 60 <= (int) wc_orders_count( $eo_held_status ) );
$eo_set( array( 'hold_enabled' => 'no' ) );
$eo_left = array_filter(
	$eo_many,
	static function ( $id ) use ( $eo_fresh, $eo_held_status ) {
		return $eo_fresh( $id )->has_status( $eo_held_status );
	}
);
$eo_check( 'turned off: every held order is released', ! $eo_left && 0 === (int) wc_orders_count( $eo_held_status ), count( $eo_left ) . ' left' );
$eo_check( 'turned off: note "the hold was turned off"', $eo_has_note( $eo_fresh( end( $eo_many ) ), 'Released to Processing: the hold was turned off.' ) );
$eo_check( 'turned off: new paid orders go to Processing', $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) )->has_status( 'processing' ) );

// Turning customer changes off stops the hold too.
$eo_set( array( 'hold_enabled' => 'yes' ) );
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_set( array( 'customer_enabled' => 'no' ) );
$eo_check( 'customer changes turned off: held orders released', $eo_fresh( $eo_o->get_id() )->has_status( 'processing' ) );
$eo_check( 'customer changes turned off: no new holds', $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) )->has_status( 'processing' ) );
$eo_set( array( 'customer_enabled' => 'yes' ) );

// ===========================================================================
WP_CLI::log( '6. Deactivation, reactivation and uninstall' );
$eo_many = array();
for ( $eo_i = 0; $eo_i < 120; $eo_i++ ) {
	$eo_many[] = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) )->get_id();
}
$eo_check( '120 orders held', 120 <= (int) wc_orders_count( $eo_held_status ) );
$eo_started = microtime( true );
edit_orders_for_woocommerce_deactivate();
$eo_check( 'deactivation releases every held order', 0 === (int) wc_orders_count( $eo_held_status ), (int) wc_orders_count( $eo_held_status ) . ' left' );
$eo_check( 'deactivation removes the scheduled releases', false === as_next_scheduled_action( Edit_Orders_For_WooCommerce_Hold::ACTION ) );
$eo_check( 'deactivation note', $eo_has_note( $eo_fresh( end( $eo_many ) ), 'Released to Processing: Edit Orders for WooCommerce was deactivated.' ) );
WP_CLI::log( sprintf( '  (120 orders released in %.1f s)', microtime( true ) - $eo_started ) );

// Left over from a plugin folder removed without deactivating: released once after reactivation.
$eo_o = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) );
update_option( 'edit_orders_for_woocommerce_release_left_over', 'yes', false );
edit_orders_for_woocommerce_release_left_over();
$eo_check( 'after reactivation: a held order left over is released', $eo_fresh( $eo_o->get_id() )->has_status( 'processing' ) );
$eo_check( 'after reactivation: only once', false === get_option( 'edit_orders_for_woocommerce_release_left_over' ) );

// Uninstall (data kept): held orders are released first.
$eo_many = array();
for ( $eo_i = 0; $eo_i < 3; $eo_i++ ) {
	$eo_many[] = $eo_paid_order( array( array( 'EO-TSHIRT', 1 ) ) )->get_id();
}
$eo_delete_before = get_option( 'edit_orders_for_woocommerce_delete_data', null );
delete_option( 'edit_orders_for_woocommerce_delete_data' );
WP_CLI::runcommand( 'eval-file ' . EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'tests/functional/helpers/run-uninstall.php', array( 'launch' => true ) );
// The other process changed the orders: read them again from the database.
wp_cache_flush();
$eo_left = array_filter(
	$eo_many,
	static function ( $id ) use ( $eo_fresh, $eo_held_status ) {
		return $eo_fresh( $id )->has_status( $eo_held_status );
	}
);
$eo_check( 'uninstall releases held orders even when data is kept', ! $eo_left, count( $eo_left ) . ' left' );
$eo_check( 'uninstall note', $eo_has_note( $eo_fresh( end( $eo_many ) ), 'Released to Processing: Edit Orders for WooCommerce was deleted.' ) );
$eo_check( 'uninstall with data kept: settings still there', false !== get_option( Edit_Orders_For_WooCommerce_Settings::OPTION ) );
if ( null !== $eo_delete_before ) {
	update_option( 'edit_orders_for_woocommerce_delete_data', $eo_delete_before );
}

// ===========================================================================
WP_CLI::log( '7. No notices, warnings or errors from the plugin' );
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
$eo_set( array( 'hold_enabled' => 'no' ) );
foreach ( array_unique( array_filter( $eo_t['orders'] ) ) as $eo_order_id ) {
	$eo_order = wc_get_order( $eo_order_id );
	if ( ! $eo_order ) {
		continue;
	}
	Edit_Orders_For_WooCommerce_Hold::unschedule( $eo_order_id );
	foreach ( $eo_order->get_refunds() as $eo_refund ) {
		$eo_refund->delete( true );
	}
	$eo_order->delete( true );
	$wpdb->delete( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'order_id' => $eo_order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}
foreach ( $eo_t['stock'] as $eo_product_id => $eo_quantity ) {
	wc_update_product_stock( $eo_product_id, $eo_quantity, 'set' );
}
foreach ( $eo_t['products'] as $eo_product_id ) {
	wp_delete_post( $eo_product_id, true );
}if ( null === $eo_settings_before ) {
	delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
} else {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $eo_settings_before );
}
delete_option( Edit_Orders_Mail_Log::OPTION );
delete_transient( Edit_Orders_For_WooCommerce_Hold::SWEEP_TRANSIENT );

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'M13 suite failed.' );
}
WP_CLI::success( 'M13 suite passed.' );
