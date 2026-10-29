<?php
/**
 * Functional suite for 1.4.0, sections 2 to 4 and 6 (tasks/edit-orders-1.4-spec.md):
 * - the hooks N1 to N26 fire where the spec says, with their arguments, and work;
 * - the section 6 hooks (approval queue, staff shipping method);
 * - the page extension points P1 to P4;
 * - the counters;
 * - the hold switch on the settings page, and the Orders screen view with a longer window.
 *
 * Run with: bash tests/functional/run.sh m14-hooks (or all suites without an argument).
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

foreach ( array( 'admin/class-edit-orders-for-woocommerce-admin.php', 'admin/class-edit-orders-for-woocommerce-activity.php', 'admin/class-edit-orders-for-woocommerce-orders-list.php', 'wpheka-ui/load.php', 'admin/class-edit-orders-for-woocommerce-settings-form.php', 'admin/class-edit-orders-for-woocommerce-admin-page.php' ) as $eo_file ) {
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
wp_set_current_user( $eo_customer->ID );
WC()->payment_gateways();

$eo_places = array(
	'ON' => array(
		'address_1' => '1 Yonge St',
		'city'      => 'Toronto',
		'state'     => 'ON',
		'postcode'  => 'M5E 1E5',
		'country'   => 'CA',
	),
	'AB' => array(
		'address_1' => '100 8 Ave SW',
		'city'      => 'Calgary',
		'state'     => 'AB',
		'postcode'  => 'T2P 1B3',
		'country'   => 'CA',
	),
);

$eo_on_zone = WC_Shipping_Zones::get_zone_matching_package(
	array(
		'destination' => array(
			'country'  => 'CA',
			'state'    => 'ON',
			'postcode' => 'M5E 1E5',
		),
	)
);

$eo_make_order = static function ( array $lines, $gateway = 'edit_orders_test' ) use ( &$eo_t, $eo_customer, $eo_places, $eo_on_zone ) {
	$order = wc_create_order( array( 'customer_id' => $eo_customer->ID ) );
	$name  = array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
	);
	$order->set_address( array_merge( $eo_places['ON'], $name, array( 'email' => 'customer@example.com' ) ), 'billing' );
	$order->set_address( array_merge( $eo_places['ON'], $name ), 'shipping' );
	foreach ( $lines as $line ) {
		$order->add_product( wc_get_product( $eo_t['product'][ $line[0] ] ), $line[1] );
	}
	$flat = null;
	foreach ( $eo_on_zone->get_shipping_methods( true ) as $method ) {
		if ( 'flat_rate' === $method->id ) {
			$flat = $method;
		}
	}
	$shipping = new WC_Order_Item_Shipping();
	$shipping->set_method_title( 'Flat rate' );
	$shipping->set_method_id( 'flat_rate' );
	$shipping->set_instance_id( $flat ? $flat->get_instance_id() : 0 );
	$shipping->set_total( 10 );
	$order->add_item( $shipping );
	$order->calculate_totals();
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()[ $gateway ] );
	$order->save();
	$order->payment_complete( 'TEST-' . $order->get_id() );
	$eo_t['orders'][] = $order->get_id();
	return wc_get_order( $order->get_id() );
};

$eo_fresh = static function ( $order_id ) {
	Edit_Orders_For_WooCommerce_Customer_Rules::reset_cache();
	Edit_Orders_For_WooCommerce_Orders_List::reset();
	return wc_get_order( $order_id );
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

$eo_process = static function ( WC_Order $order, array $data, $actor = 'customer' ) use ( $eo_fresh ) {
	return Edit_Orders_For_WooCommerce_Frontend::process( $eo_fresh( $order->get_id() ), $data, $actor );
};

// A preview, then the confirm with what the preview returned, as the panel does.
$eo_preview_confirm = static function ( WC_Order $order, array $data ) use ( $eo_process ) {
	$preview = $eo_process( $order, $data );
	if ( 'preview' !== $preview['type'] ) {
		return array( $preview, null );
	}
	$confirm = $eo_process( $order, array_merge( $data, $preview['preview']['fields'], array( 'step' => 'confirm' ) ) );
	return array( $preview, $confirm );
};

$eo_address_form = static function ( $type, array $place ) {
	return array(
		'edit_orders_for_woocommerce_action' => 'address',
		$type                                => array_merge(
			array(
				'first_name' => 'Casey',
				'last_name'  => 'Customer',
				'company'    => '',
				'address_2'  => '',
			),
			$place
		),
	);
};

// Record every call of a hook: its arguments.
$eo_calls  = array();
$eo_record = static function ( $hook, $filter = true, $args = 6 ) use ( &$eo_calls ) {
	$callback = static function () use ( &$eo_calls, $hook, $filter ) {
		$eo_calls[ $hook ][] = func_get_args();
		return $filter ? func_get_arg( 0 ) : null;
	};
	if ( $filter ) {
		add_filter( $hook, $callback, 10, $args );
	} else {
		add_action( $hook, $callback, 10, $args );
	}
	return $callback;
};
$eo_last   = static function ( $hook ) use ( &$eo_calls ) {
	return empty( $eo_calls[ $hook ] ) ? null : end( $eo_calls[ $hook ] );
};

$eo_render_panel = static function ( WC_Order $order, $state = null ) {
	$args = Edit_Orders_For_WooCommerce_Frontend::panel_args( $order, '' );
	if ( ! $args ) {
		return '';
	}
	if ( null !== $state ) {
		$args['state'] = $state;
	}
	ob_start();
	wc_get_template( 'myaccount/edit-order.php', $args, 'edit-orders-for-woocommerce/', EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/' );
	return (string) ob_get_clean();
};

// ===========================================================================
WP_CLI::log( '1. N1 and the longer window on the Orders screen' );
foreach ( array( 'edit_orders_for_woocommerce_window_end', 'edit_orders_for_woocommerce_window_max_seconds' ) as $eo_hook ) {
	$eo_record( $eo_hook );
}
$eo_o    = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_left = Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $eo_o );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_window_end' );
$eo_check( 'N1 window_end: ( int $end, WC_Order $order, int $start )', $eo_args && is_int( $eo_args[0] ) && $eo_args[1] instanceof WC_Order && Edit_Orders_For_WooCommerce_Customer_Rules::window_start( $eo_o ) === $eo_args[2] && $eo_args[2] + HOUR_IN_SECONDS === $eo_args[0] );
$eo_check( 'N1 default leaves the window as set (about an hour)', $eo_left > 3500 && $eo_left <= 3600, (string) $eo_left );

// Paid three hours ago, with a 60-minute window: closed, unless a rule makes it longer.
$eo_old = $eo_fresh( $eo_o->get_id() );
$eo_old->set_date_paid( time() - 3 * HOUR_IN_SECONDS );
$eo_old->save();
$eo_check( 'paid 3 hours ago: not in "Customer can still change"', ! in_array( $eo_old->get_id(), Edit_Orders_For_WooCommerce_Orders_List::open_order_ids(), true ) );
$eo_longer = static function ( $end ) {
	return $end + DAY_IN_SECONDS;
};
add_filter( 'edit_orders_for_woocommerce_window_end', $eo_longer );
Edit_Orders_For_WooCommerce_Orders_List::reset();
$eo_check( 'a longer window through N1 alone: the badge shows', '' !== Edit_Orders_For_WooCommerce_Orders_List::badge( $eo_fresh( $eo_old->get_id() ) ) );
$eo_check( 'but the view leaves it out without window_max_seconds', ! in_array( $eo_old->get_id(), Edit_Orders_For_WooCommerce_Orders_List::open_order_ids(), true ) );
$eo_max = static function () {
	return 2 * DAY_IN_SECONDS;
};
add_filter( 'edit_orders_for_woocommerce_window_max_seconds', $eo_max );
Edit_Orders_For_WooCommerce_Orders_List::reset();
$eo_check( 'with window_max_seconds the view keeps it', in_array( $eo_old->get_id(), Edit_Orders_For_WooCommerce_Orders_List::open_order_ids(), true ) );
remove_filter( 'edit_orders_for_woocommerce_window_end', $eo_longer );
remove_filter( 'edit_orders_for_woocommerce_window_max_seconds', $eo_max );
Edit_Orders_For_WooCommerce_Orders_List::reset();

// ===========================================================================
WP_CLI::log( '2. Customer actions: N2, N3, N4, N7, N24 and the panel hooks N5, N6' );
$eo_add_action = static function ( $actions ) {
	$actions[] = 'fewer';
	$actions[] = 'accept_offer';
	return $actions;
};
add_filter( 'edit_orders_for_woocommerce_customer_actions', $eo_add_action );
add_filter(
	'edit_orders_for_woocommerce_customer_changes',
	static function ( $changes, $action, $order ) {
		if ( 'fewer' !== $action ) {
			return $changes;
		}
		foreach ( $order->get_items() as $item_id => $item ) {
			return array(
				array(
					'type'     => 'quantity',
					'item_id'  => $item_id,
					'quantity' => max( 1, $item->get_quantity() - 1 ),
				),
			);
		}
		return $changes;
	},
	10,
	3
);
add_filter(
	'edit_orders_for_woocommerce_customer_action',
	static function ( $result, $action ) {
		return 'accept_offer' === $action ? array(
			'type'    => 'success',
			'message' => 'Offer accepted.',
		) : $result;
	},
	10,
	2
);
foreach ( array( 'edit_orders_for_woocommerce_customer_changes', 'edit_orders_for_woocommerce_customer_action', 'edit_orders_for_woocommerce_customer_preview', 'edit_orders_for_woocommerce_panel_args', 'edit_orders_for_woocommerce_balance_redirect' ) as $eo_hook ) {
	$eo_record( $eo_hook );
}

$eo_o                = $eo_make_order( array( array( 'EO-TSHIRT', 3 ) ) );
list( $eo_p, $eo_c ) = $eo_preview_confirm( $eo_o, array( 'edit_orders_for_woocommerce_action' => 'fewer' ) );
$eo_check(
	// The recorder runs after the test's own filter, so it sees the changes that filter returned.
	'N2 customer_changes: ( $changes, $action, WC_Order, $data, $actor )',
	( static function ( $a ) {
		return $a && is_array( $a[0] ) && 'fewer' === $a[1] && $a[2] instanceof WC_Order && is_array( $a[3] ) && 'customer' === $a[4];
	} )( $eo_calls['edit_orders_for_woocommerce_customer_changes'][0] )
);
$eo_check( 'N2: a Pro action gets free\'s preview with a fingerprint', 'preview' === $eo_p['type'] && ! empty( $eo_p['preview']['fields']['expect'] ) );
$eo_fewer = $eo_fresh( $eo_o->get_id() );
$eo_check( 'N2: and is applied on confirm (one unit refunded)', $eo_c && 'success' === $eo_c['type'] && -1 === (int) $eo_fewer->get_qty_refunded_for_item( $eo_item_id( $eo_fewer, 'EO-TSHIRT' ) ) && (float) $eo_fewer->get_total_refunded() > 0, $eo_c ? $eo_c['type'] . ' ' . $eo_c['message'] : '' );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_customer_preview' );
$eo_check( 'N7 customer_preview: ( array $preview, Settlement_Plan, WC_Order, $action )', $eo_args && isset( $eo_args[0]['fields'] ) && $eo_args[1] instanceof Edit_Orders_For_WooCommerce_Settlement_Plan && $eo_args[2] instanceof WC_Order && 'fewer' === $eo_args[3] );

$eo_r = $eo_process( $eo_o, array( 'edit_orders_for_woocommerce_action' => 'accept_offer' ) );
$eo_check( 'N3 customer_action: a non-change action returns its own result', 'success' === $eo_r['type'] && 'Offer accepted.' === $eo_r['message'] );
$eo_r = $eo_process( $eo_o, array( 'edit_orders_for_woocommerce_action' => 'nothing_known' ) );
$eo_check( 'an action nobody handles is still refused', 'error' === $eo_r['type'] );
remove_filter( 'edit_orders_for_woocommerce_customer_actions', $eo_add_action );

$eo_o = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
add_filter(
	'edit_orders_for_woocommerce_customer_preview',
	static function ( $preview ) {
		$preview['changes'][] = 'Pro line in the preview.';
		return $preview;
	}
);
$eo_p = $eo_process( $eo_o, $eo_address_form( 'shipping', $eo_places['AB'] ) );
$eo_check( 'N7: the preview shows what the filter added', 'preview' === $eo_p['type'] && in_array( 'Pro line in the preview.', $eo_p['preview']['changes'], true ) );

// N24: the redirect to pay for a change.
add_filter(
	'edit_orders_for_woocommerce_balance_redirect',
	static function ( $url ) {
		return add_query_arg( 'one_click', '1', $url );
	}
);
$eo_c    = $eo_process( $eo_o, array_merge( $eo_address_form( 'shipping', $eo_places['AB'] ), $eo_p['preview']['fields'], array( 'step' => 'confirm' ) ) );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_balance_redirect' );
$eo_check( 'N24 balance_redirect: ( $url, WC_Order $balance, WC_Order $order, $actor, $data )', $eo_args && is_string( $eo_args[0] ) && $eo_args[1] instanceof WC_Order && $eo_args[2] instanceof WC_Order && 'customer' === $eo_args[3] && is_array( $eo_args[4] ) );
$eo_check( 'N24: the customer goes where the filter says', ! empty( $eo_c['redirect'] ) && false !== strpos( $eo_c['redirect'], 'one_click=1' ) );
$eo_balance = Edit_Orders_For_WooCommerce_Balance_Orders::get_open_balance_order( $eo_fresh( $eo_o->get_id() ) );
if ( $eo_balance ) {
	$eo_t['orders'][] = $eo_balance->get_id();
}

// N4: panel args, also when the plugin itself has nothing to show.
$eo_o         = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_args      = Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_o, '' );
$eo_last_args = $eo_last( 'edit_orders_for_woocommerce_panel_args' );
$eo_check( 'N4 panel_args: ( array $args, WC_Order, $key )', $eo_last_args && is_array( $eo_last_args[0] ) && $eo_last_args[1] instanceof WC_Order && '' === $eo_last_args[2] );
$eo_done = $eo_fresh( $eo_o->get_id() );
$eo_done->update_status( 'completed' );
$eo_check( 'N4 with nothing to show: asked with null', null === Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_fresh( $eo_o->get_id() ), '' ) && null === $eo_last( 'edit_orders_for_woocommerce_panel_args' )[0] );
$eo_show = static function ( $args, $order ) {
	return null === $args ? array(
		'order'   => $order,
		'actions' => array(),
		'state'   => null,
		'message' => 'Pro panel',
	) : $args;
};
add_filter( 'edit_orders_for_woocommerce_panel_args', $eo_show, 20, 2 );
$eo_check( 'N4: an extension can show the panel anyway', is_array( Edit_Orders_For_WooCommerce_Frontend::panel_args( $eo_fresh( $eo_o->get_id() ), '' ) ) );
remove_filter( 'edit_orders_for_woocommerce_panel_args', $eo_show, 20 );

// N5, N6, N7 action, N17 in the template.
foreach ( array( 'edit_orders_for_woocommerce_panel_actions', 'edit_orders_for_woocommerce_panel_state', 'edit_orders_for_woocommerce_preview_after', 'edit_orders_for_woocommerce_cancel_form_fields' ) as $eo_hook ) {
	$eo_record( $eo_hook, false );
}
$eo_o    = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_html = $eo_render_panel( $eo_o );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_panel_actions' );
$eo_check( 'N5 panel_actions: ( WC_Order, $actions, $order_key, $args ), before Cancel', $eo_args && $eo_args[0] instanceof WC_Order && in_array( 'cancel', $eo_args[1], true ) && '' === $eo_args[2] && isset( $eo_args[3]['actions'] ) );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_cancel_form_fields' );
$eo_check( 'N17 cancel_form_fields: ( WC_Order, $order_key, $args )', $eo_args && $eo_args[0] instanceof WC_Order && '' === $eo_args[1] && is_array( $eo_args[2] ) );
$eo_html = $eo_render_panel(
	$eo_o,
	array(
		'type'    => 'offer',
		'message' => '',
		'action'  => 'cancel',
	)
);
$eo_args = $eo_last( 'edit_orders_for_woocommerce_panel_state' );
$eo_check( 'N6 panel_state: ( $state, WC_Order, $order_key ) for a state the plugin doesn\'t know', $eo_args && 'offer' === $eo_args[0]['type'] && $eo_args[1] instanceof WC_Order );
$eo_p    = $eo_process( $eo_o, $eo_address_form( 'shipping', $eo_places['AB'] ) );
$eo_html = $eo_render_panel( $eo_o, $eo_p );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_preview_after' );
$eo_check( 'N7 preview_after: ( $preview, WC_Order, $state )', $eo_args && isset( $eo_args[0]['fields'] ) && $eo_args[1] instanceof WC_Order && 'preview' === $eo_args[2]['type'] );

// ===========================================================================
WP_CLI::log( '3. Cancellation hooks: N13 (contexts), N14, N15, N16, N19' );
$eo_set( array( 'cancel_mode' => 'instant' ) );
foreach ( array( 'edit_orders_for_woocommerce_refund_handler', 'edit_orders_for_woocommerce_cancel_refund', 'edit_orders_for_woocommerce_execute_cancel', 'edit_orders_for_woocommerce_cancel_request_details', 'edit_orders_for_woocommerce_cancel_intercept' ) as $eo_hook ) {
	$eo_record( $eo_hook, true, 7 );
}

// N19: an offer instead of cancelling.
$eo_offer = static function () {
	return array(
		'type'    => 'offer',
		'message' => 'Stay for 10% off?',
	);
};
add_filter( 'edit_orders_for_woocommerce_cancel_intercept', $eo_offer );
$eo_o    = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_r    = $eo_process(
	$eo_o,
	array(
		'edit_orders_for_woocommerce_action' => 'cancel',
		'reason'                             => '',
	)
);
$eo_args = $eo_last( 'edit_orders_for_woocommerce_cancel_intercept' );
$eo_check( 'N19 cancel_intercept: ( null, WC_Order, $data, $actor, $reason )', $eo_args && null === $eo_args[0] && $eo_args[1] instanceof WC_Order && is_array( $eo_args[2] ) && 'customer' === $eo_args[3] && '' === $eo_args[4] );
$eo_check( 'N19: the offer is shown and the order is not cancelled', 'offer' === $eo_r['type'] && ! $eo_fresh( $eo_o->get_id() )->has_status( 'cancelled' ) );
remove_filter( 'edit_orders_for_woocommerce_cancel_intercept', $eo_offer );

// N16: details kept with the request.
$eo_set( array( 'cancel_mode' => 'approval' ) );
$eo_details = static function ( $details ) {
	$details['lines'] = array( 1, 2 );
	return $details;
};
add_filter( 'edit_orders_for_woocommerce_cancel_request_details', $eo_details );
$eo_o = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_process(
	$eo_o,
	array(
		'edit_orders_for_woocommerce_action' => 'cancel',
		'reason'                             => '',
	)
);
$eo_request = (array) $eo_fresh( $eo_o->get_id() )->get_meta( Edit_Orders_For_WooCommerce_Cancellation::REQUEST_META );
$eo_args    = $eo_last( 'edit_orders_for_woocommerce_cancel_request_details' );
$eo_check( 'N16 cancel_request_details: ( array, WC_Order, $data, $actor )', $eo_args && array() === $eo_args[0] && $eo_args[1] instanceof WC_Order && is_array( $eo_args[2] ) && 'customer' === $eo_args[3] );
$eo_check( 'N16: the details are stored with the request, which keeps its own keys', array( 1, 2 ) === $eo_request['lines'] && isset( $eo_request['reason'], $eo_request['time'] ) );
remove_filter( 'edit_orders_for_woocommerce_cancel_request_details', $eo_details );

// N18: the request's summary in the queue.
$eo_record( 'edit_orders_for_woocommerce_request_summary' );
add_filter(
	'edit_orders_for_woocommerce_request_summary',
	static function ( $summary, $order, $request ) {
		return isset( $request['lines'] ) ? 'Two lines' : $summary;
	},
	20,
	3
);
wp_set_current_user( 1 );
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_html = (string) ob_get_clean();
$eo_args = $eo_last( 'edit_orders_for_woocommerce_request_summary' );
$eo_check( 'N18 request_summary: ( $summary, WC_Order, $request with type )', $eo_args && is_string( $eo_args[0] ) && $eo_args[1] instanceof WC_Order && 'cancel' === $eo_args[2]['type'] );
$eo_check( 'N18: the queue shows the filtered summary', false !== strpos( $eo_html, 'Two lines' ) );

// N15: a cancellation carried out by an extension.
$eo_partial = static function ( $result, $order ) {
	return array(
		'status' => 'partial',
		'order'  => $order->get_id(),
	);
};
add_filter( 'edit_orders_for_woocommerce_execute_cancel', $eo_partial, 20, 2 );
$eo_r    = Edit_Orders_For_WooCommerce_Cancellation::approve( $eo_fresh( $eo_o->get_id() ) );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_execute_cancel' );
$eo_check( 'N15 execute_cancel: ( null, WC_Order, $actor, $reason, $request )', $eo_args && null === $eo_args[0] && $eo_args[1] instanceof WC_Order && 'staff' === $eo_args[2] && is_array( $eo_args[4] ) && isset( $eo_args[4]['lines'] ) );
$eo_check( 'N15: its result is returned and the order isn\'t cancelled', is_array( $eo_r ) && 'partial' === $eo_r['status'] && ! $eo_fresh( $eo_o->get_id() )->has_status( 'cancelled' ) );
remove_filter( 'edit_orders_for_woocommerce_execute_cancel', $eo_partial, 20 );
$eo_check( 'N15: the cancel guard is released afterwards', Edit_Orders_For_WooCommerce_Lock::claim( 'cancel_' . $eo_o->get_id() ) );
Edit_Orders_For_WooCommerce_Lock::unclaim( 'cancel_' . $eo_o->get_id() );

// N14: a cancellation fee.
$eo_fee = static function ( $refund ) {
	$refund['amount'] -= 5;
	$refund['fee']     = 5;
	foreach ( $refund['lines'] as $item_id => $line ) {
		if ( $line['refund_total'] >= 5 ) {
			$refund['lines'][ $item_id ]['refund_total'] -= 5;
			break;
		}
	}
	return $refund;
};
add_filter( 'edit_orders_for_woocommerce_cancel_refund', $eo_fee, 20 );
$eo_r    = Edit_Orders_For_WooCommerce_Cancellation::approve( $eo_fresh( $eo_o->get_id() ) );
$eo_o    = $eo_fresh( $eo_o->get_id() );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_cancel_refund' );
$eo_check( 'N14 cancel_refund: ( { lines, amount, fee }, WC_Order, $actor, $reason )', $eo_args && isset( $eo_args[0]['lines'], $eo_args[0]['amount'], $eo_args[0]['fee'] ) && $eo_args[1] instanceof WC_Order && 'staff' === $eo_args[2] );
$eo_check( 'N14: the fee is kept back from the refund', ! is_wp_error( $eo_r ) && $eo_o->has_status( 'cancelled' ) && abs( (float) $eo_o->get_total_refunded() - ( (float) $eo_o->get_total() - 5 ) ) < 0.01, $eo_o->get_total_refunded() . ' of ' . $eo_o->get_total() );
$eo_check( 'N14: the result and the log carry the reduced amount and the fee', ! is_wp_error( $eo_r ) && abs( $eo_r['amount'] - ( (float) $eo_o->get_total() - 5 ) ) < 0.01 && 5.0 === (float) json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT after_data FROM %i WHERE order_id = %d AND action = %s', Edit_Orders_For_WooCommerce_Audit_Log::table(), $eo_o->get_id(), 'cancelled' ) ), true )['fee'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
remove_filter( 'edit_orders_for_woocommerce_cancel_refund', $eo_fee, 20 );
$eo_contexts = array_map(
	static function ( $call ) {
		return $call[5];
	},
	isset( $eo_calls['edit_orders_for_woocommerce_refund_handler'] ) ? $eo_calls['edit_orders_for_woocommerce_refund_handler'] : array()
);
$eo_check( 'N13 refund_handler: context "cancel" for a cancellation', in_array( 'cancel', $eo_contexts, true ) );

// N13: cancel_balance, for a paid balance order cancelled with its original.
$eo_set( array( 'cancel_mode' => 'instant' ) );
$eo_o                = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
list( $eo_p, $eo_c ) = $eo_preview_confirm( $eo_o, $eo_address_form( 'shipping', $eo_places['AB'] ) );
$eo_balance          = Edit_Orders_For_WooCommerce_Balance_Orders::get_open_balance_order( $eo_fresh( $eo_o->get_id() ) );
if ( $eo_balance ) {
	$eo_t['orders'][] = $eo_balance->get_id();
	$eo_balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$eo_balance->save();
	$eo_balance->payment_complete( 'TEST-BAL-' . $eo_balance->get_id() );
}
$eo_process(
	$eo_o,
	array(
		'edit_orders_for_woocommerce_action' => 'cancel',
		'reason'                             => '',
	)
);
$eo_contexts = array_map(
	static function ( $call ) {
		return $call[5];
	},
	$eo_calls['edit_orders_for_woocommerce_refund_handler']
);
$eo_check( 'N13 refund_handler: context "cancel_balance" for a paid balance order', $eo_balance && in_array( 'cancel_balance', $eo_contexts, true ) );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_refund_handler' );
$eo_check( 'N13 arguments: ( null, WC_Order, $lines, float $amount, $reason, $context, bool $customer_email )', $eo_args && null === $eo_args[0] && $eo_args[1] instanceof WC_Order && is_array( $eo_args[2] ) && is_float( $eo_args[3] ) && is_string( $eo_args[4] ) && is_bool( $eo_args[6] ) );

// N13 short-circuit: store credit instead of a gateway refund.
$eo_credit = static function () {
	return array(
		'refund_id'   => 0,
		'destination' => 'store_credit',
	);
};
add_filter( 'edit_orders_for_woocommerce_refund_handler', $eo_credit, 20 );
wp_set_current_user( 1 );
$eo_o = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_r = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_o,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item_id( $eo_o, 'EO-TSHIRT' ),
			'quantity' => 1,
		),
	),
	'staff'
);
remove_filter( 'edit_orders_for_woocommerce_refund_handler', $eo_credit, 20 );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_refund_handler' );
$eo_check( 'N13 refund_handler: context "edit" for an edit', $eo_args && 'edit' === $eo_args[5] );
$eo_check( 'N13 short-circuit: the result has free\'s shape plus the destination', ! is_wp_error( $eo_r ) && is_array( $eo_r['refund'] ) && array_key_exists( 'manual', $eo_r['refund'] ) && array_key_exists( 'gateway_error', $eo_r['refund'] ) && array_key_exists( 'on_delivery', $eo_r['refund'] ) && 'store_credit' === $eo_r['refund']['destination'] );
$eo_check( 'N13 short-circuit: no WooCommerce refund was made', ! is_wp_error( $eo_r ) && 0.0 === (float) $eo_fresh( $eo_o->get_id() )->get_total_refunded() );

// ===========================================================================
WP_CLI::log( '4. The plan: N8, N9, N10, N11, N12, N26' );
foreach ( array( 'edit_orders_for_woocommerce_can_change_item', 'edit_orders_for_woocommerce_normalise_change', 'edit_orders_for_woocommerce_plan_change', 'edit_orders_for_woocommerce_plan_run', 'edit_orders_for_woocommerce_before_apply' ) as $eo_hook ) {
	$eo_record( $eo_hook, 'edit_orders_for_woocommerce_plan_change' !== $eo_hook && 'edit_orders_for_woocommerce_plan_run' !== $eo_hook && 'edit_orders_for_woocommerce_before_apply' !== $eo_hook );
}

$eo_o    = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_item = $eo_item_id( $eo_o, 'EO-TSHIRT' );
$eo_plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_o,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item,
			'quantity' => 1,
		),
	),
	'customer'
);
$eo_args = $eo_last( 'edit_orders_for_woocommerce_can_change_item' );
$eo_check( 'N8 can_change_item: ( true, WC_Order, WC_Order_Item_Product, $change, $actor )', $eo_args && true === $eo_args[0] && $eo_args[2] instanceof WC_Order_Item_Product && 'quantity' === $eo_args[3]['type'] && 'customer' === $eo_args[4] );
$eo_check( 'N8: the plan knows its actor from build()', ! is_wp_error( $eo_plan ) && 'customer' === $eo_plan->get_actor() );
$eo_no = static function () {
	return new WP_Error( 'eo_limit', 'Only one change per line.' );
};
add_filter( 'edit_orders_for_woocommerce_can_change_item', $eo_no, 20 );
$eo_plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_o,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item,
			'quantity' => 1,
		),
	)
);
$eo_check( 'N8: a WP_Error refuses the change', is_wp_error( $eo_plan ) && false !== strpos( $eo_plan->get_error_message(), 'Only one change per line.' ) );
remove_filter( 'edit_orders_for_woocommerce_can_change_item', $eo_no, 20 );

// N9 and N10: a new change type.
$eo_coupon_ok = static function ( $result, $change ) {
	return isset( $change['type'] ) && 'coupon' === $change['type'] ? array(
		'type' => 'coupon',
		'code' => sanitize_text_field( $change['code'] ),
	) : $result;
};
add_filter( 'edit_orders_for_woocommerce_normalise_change', $eo_coupon_ok, 20, 2 );
$eo_coupon_plan = static function ( $plan, $order, $change ) use ( $eo_item ) {
	if ( 'coupon' !== $change['type'] ) {
		return;
	}
	$plan->add_description( 'Coupon ' . $change['code'] . ' applied.' );
	$plan->add_refund_line(
		$eo_item,
		array(
			'qty'          => 0,
			'refund_total' => 2.0,
			'refund_tax'   => array(),
		)
	);
	$plan->set_extra( 'coupon', $change['code'] );
};
add_action( 'edit_orders_for_woocommerce_plan_change', $eo_coupon_plan, 20, 3 );
$eo_plan = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_o,
	array(
		array(
			'type' => 'coupon',
			'code' => 'SAVE2',
		),
	)
);
$eo_args = $eo_last( 'edit_orders_for_woocommerce_normalise_change' );
$eo_check( 'N9 normalise_change: ( "Unknown change.", $change )', $eo_args && 'Unknown change.' === $eo_args[0] && 'coupon' === $eo_args[1]['type'] );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_plan_change' );
$eo_check( 'N10 plan_change: ( Settlement_Plan, WC_Order, $change )', $eo_args && $eo_args[0] instanceof Edit_Orders_For_WooCommerce_Settlement_Plan && $eo_args[1] instanceof WC_Order && 'coupon' === $eo_args[2]['type'] );
$eo_check( 'N9/N10: the new change is planned (description, refund, extra)', ! is_wp_error( $eo_plan ) && in_array( 'Coupon SAVE2 applied.', $eo_plan->get_descriptions(), true ) && 2.0 === (float) $eo_plan->get_parent_refund_amount() && 'SAVE2' === $eo_plan->get_extra( 'coupon' ) );
$eo_bad = Edit_Orders_For_WooCommerce_Settlement_Executor::preview(
	$eo_o,
	array(
		array(
			'type' => 'teleport',
		),
	)
);
$eo_check( 'N9: an unknown type nobody accepts is still refused', is_wp_error( $eo_bad ) && false !== strpos( $eo_bad->get_error_message(), 'Unknown change.' ) );

// N11: extra data is in the fingerprint and survives storage.
$eo_without = clone $eo_plan;
$eo_without->set_extra( 'coupon', null );
$eo_check( 'N11: extra changes the fingerprint', ! is_wp_error( $eo_plan ) && $eo_plan->fingerprint() !== $eo_without->fingerprint() );
$eo_round = Edit_Orders_For_WooCommerce_Settlement_Plan::from_array( $eo_plan->to_array() );
$eo_check( 'N11: to_array() and from_array() keep extra and the fingerprint', 'SAVE2' === $eo_round->get_extra( 'coupon' ) && $eo_round->fingerprint() === $eo_plan->fingerprint() );
$eo_check( 'N11: a plan without extra keeps its 1.3 shape (no extra key)', ! array_key_exists( 'extra', $eo_without->to_array() ) );
$eo_m = new Edit_Orders_For_WooCommerce_Settlement_Plan();
$eo_m->add_balance_item(
	array(
		'type'  => 'fee',
		'name'  => 'Fee',
		'total' => 3,
	)
);
$eo_m->set_balance_item(
	0,
	array(
		'type'  => 'fee',
		'name'  => 'Fee',
		'total' => 4,
	)
);
$eo_m->add_error( 'Stop.' );
$eo_m->add_refund_line( 7, array( 'qty' => 1 ) );
$eo_m->remove_refund_line( 7 );
$eo_check( 'N11 mutators: balance items, errors and refund lines', 4 === $eo_m->get_balance_items()[0]['total'] && array( 'Stop.' ) === $eo_m->get_errors() && array() === $eo_m->get_refund_lines() );

// N11 through a balance order: the extra is stored with the plan and restored when paid.
$eo_keep = static function ( $plan ) {
	$plan->set_extra( 'destination', 'card' );
	return $plan;
};
add_filter( 'edit_orders_for_woocommerce_settlement_plan', $eo_keep );
$eo_b                = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
list( $eo_p, $eo_c ) = $eo_preview_confirm( $eo_b, $eo_address_form( 'shipping', $eo_places['AB'] ) );
remove_filter( 'edit_orders_for_woocommerce_settlement_plan', $eo_keep );
$eo_balance = Edit_Orders_For_WooCommerce_Balance_Orders::get_open_balance_order( $eo_fresh( $eo_b->get_id() ) );
$eo_stored  = $eo_balance ? (array) $eo_balance->get_meta( Edit_Orders_For_WooCommerce_Balance_Orders::PLAN_META ) : array();
$eo_check( 'N11: the extra is stored with a balance order\'s plan', $eo_balance && isset( $eo_stored['extra']['destination'] ) && 'card' === $eo_stored['extra']['destination'] );
if ( $eo_balance ) {
	$eo_t['orders'][] = $eo_balance->get_id();
	$eo_balance->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$eo_balance->save();
	$eo_balance->payment_complete( 'TEST-BAL-' . $eo_balance->get_id() );
}
$eo_args = $eo_last( 'edit_orders_for_woocommerce_plan_run' );
$eo_check( 'N12 plan_run after a paid balance: ( WC_Order, Settlement_Plan with extra, WC_Order $balance, $result )', $eo_args && $eo_args[0] instanceof WC_Order && $eo_args[1] instanceof Edit_Orders_For_WooCommerce_Settlement_Plan && 'card' === $eo_args[1]->get_extra( 'destination' ) && $eo_args[2] instanceof WC_Order && 'applied' === $eo_args[3]['status'] );

// N12 and N26 on a plain staff edit; N25's fourth argument on before_apply.
$eo_r    = Edit_Orders_For_WooCommerce_Settlement_Executor::apply(
	$eo_o,
	array(
		array(
			'type'     => 'quantity',
			'item_id'  => $eo_item,
			'quantity' => 1,
		),
	),
	'staff',
	array( 'custom' => 'yes' )
);
$eo_args = $eo_last( 'edit_orders_for_woocommerce_plan_run' );
$eo_check( 'N12 plan_run on an edit: no balance order', $eo_args && null === $eo_args[2] && $eo_args[0]->get_id() === $eo_o->get_id() );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_before_apply' );
$eo_check( 'N25: before_apply gets the caller\'s options as a 4th argument', $eo_args && isset( $eo_args[3]['custom'] ) && 'yes' === $eo_args[3]['custom'] );
$eo_after = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT after_data FROM %i WHERE order_id = %d AND action = %s ORDER BY id DESC LIMIT 1', Edit_Orders_For_WooCommerce_Audit_Log::table(), $eo_o->get_id(), 'edit' ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$eo_check( 'N26: the log row lists the change types, untranslated', isset( $eo_after['types'] ) && array( 'quantity' ) === $eo_after['types'], wp_json_encode( $eo_after ) );
$eo_paid_row = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT after_data FROM %i WHERE order_id = %d AND action = %s ORDER BY id DESC LIMIT 1', Edit_Orders_For_WooCommerce_Audit_Log::table(), $eo_b->get_id(), 'balance_paid' ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$eo_check( 'N26: also when a balance order is paid', isset( $eo_paid_row['types'] ) && array( 'address' ) === $eo_paid_row['types'], wp_json_encode( $eo_paid_row ) );
remove_filter( 'edit_orders_for_woocommerce_normalise_change', $eo_coupon_ok, 20 );
remove_action( 'edit_orders_for_woocommerce_plan_change', $eo_coupon_plan, 20 );

// ===========================================================================
WP_CLI::log( '5. N20: a shipping method change on its own' );
$eo_pickup = $eo_on_zone->add_shipping_method( 'local_pickup' );
$eo_o      = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_before = (float) $eo_o->get_shipping_total() + (float) $eo_o->get_shipping_tax();
$eo_change = array(
	array(
		'type'            => 'address',
		'shipping_method' => 'local_pickup:' . $eo_pickup,
	),
);
$eo_plan   = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_o, $eo_change );
$eo_check( 'N20: a method on its own is a valid change', ! is_wp_error( $eo_plan ), is_wp_error( $eo_plan ) ? $eo_plan->get_error_message() : '' );
$eo_check( 'N20: it re-rates: the paid shipping and its tax are refunded', ! is_wp_error( $eo_plan ) && abs( $eo_plan->get_refund_amount() - $eo_before ) < 0.01, is_wp_error( $eo_plan ) ? '' : $eo_plan->get_refund_amount() . ' vs ' . $eo_before );
$eo_r    = Edit_Orders_For_WooCommerce_Settlement_Executor::apply( $eo_o, $eo_change, 'staff' );
$eo_o    = $eo_fresh( $eo_o->get_id() );
$eo_line = current( $eo_o->get_shipping_methods() );
$eo_check( 'N20: applied, the shipping line now names the new method', ! is_wp_error( $eo_r ) && 'local_pickup' === $eo_line->get_method_id() && abs( (float) $eo_o->get_total_refunded() - $eo_before ) < 0.01 );
$eo_same = Edit_Orders_For_WooCommerce_Settlement_Executor::preview( $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) ), array( array( 'type' => 'address', 'shipping_method' => 'flat_rate:' . current( $eo_make_order( array( array( 'EO-MUG', 1 ) ) )->get_shipping_methods() )->get_instance_id() ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
$eo_check( 'N20: the same method again changes nothing', is_wp_error( $eo_same ) && false !== strpos( $eo_same->get_error_message(), 'Nothing to change.' ), is_wp_error( $eo_same ) ? $eo_same->get_error_message() : 'a plan' );
$eo_cust = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_r    = $eo_process(
	$eo_cust,
	array(
		'edit_orders_for_woocommerce_action' => 'address',
		'shipping_method'                    => 'local_pickup:' . $eo_pickup,
	)
);
$eo_check( 'customers can\'t change the method on its own (that picker is Pro)', 'error' === $eo_r['type'] && 'Nothing was changed.' === $eo_r['message'] );
$eo_on_zone->delete_shipping_method( $eo_pickup );

// Section 6: the staff method change goes through the editor's request.
wp_set_current_user( 1 );
$eo_record( 'edit_orders_for_woocommerce_admin_address_tab', false );
$eo_tab = new ReflectionMethod( 'Edit_Orders_For_WooCommerce_Admin', 'render_address_tab' );
$eo_tab->setAccessible( true );
ob_start();
$eo_tab->invoke( null, $eo_cust );
ob_end_clean();
$eo_args = $eo_last( 'edit_orders_for_woocommerce_admin_address_tab' );
$eo_check( 'section 6 admin_address_tab: ( WC_Order ) in the Address tab', $eo_args && $eo_args[0] instanceof WC_Order );
$eo_changes = Edit_Orders_For_WooCommerce_Admin::changes_from_request(
	$eo_cust,
	array(
		'mode'            => 'address',
		'shipping_method' => 'local_pickup:9',
	)
);
$eo_check( 'section 6: changes_from_request() passes a method posted without an address', array( array( 'type' => 'address', 'shipping_method' => 'local_pickup:9' ) ) === $eo_changes ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

// ===========================================================================
WP_CLI::log( '6. The admin editor: N21, N25' );
$eo_record( 'edit_orders_for_woocommerce_admin_preview_after', false, 2 );
$eo_record( 'edit_orders_for_woocommerce_admin_apply_args', true, 3 );
add_action(
	'edit_orders_for_woocommerce_admin_preview_after',
	static function () {
		echo '<label><input type="checkbox" name="charge_card" value="1" /> Charge the saved card</label>';
	}
);
$eo_o    = $eo_make_order( array( array( 'EO-TSHIRT', 2 ) ) );
$eo_form = array(
	'mode'  => 'items',
	'items' => array( $eo_item_id( $eo_o, 'EO-TSHIRT' ) => array( 'qty' => 1 ) ),
);
$eo_prev = Edit_Orders_For_WooCommerce_Admin::preview( $eo_o, Edit_Orders_For_WooCommerce_Admin::changes_from_request( $eo_o, $eo_form ) );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_admin_preview_after' );
$eo_check( 'N21 admin_preview_after: ( WC_Order, Settlement_Plan )', $eo_args && $eo_args[0] instanceof WC_Order && $eo_args[1] instanceof Edit_Orders_For_WooCommerce_Settlement_Plan );
$eo_check( 'N21: printed before the Apply button', ! empty( $eo_prev['html'] ) && false !== strpos( $eo_prev['html'], 'charge_card' ) && strpos( $eo_prev['html'], 'charge_card' ) < strpos( $eo_prev['html'], 'edit-orders-apply' ) );
add_filter(
	'edit_orders_for_woocommerce_admin_apply_args',
	static function ( $args, $order, $posted ) {
		$args['charge_card'] = ! empty( $posted['charge_card'] );
		return $args;
	},
	20,
	3
);
$eo_r    = Edit_Orders_For_WooCommerce_Admin::apply( $eo_o, array_merge( $eo_form, array( 'charge_card' => '1' ) ), array( 'notify_customer' => false ) );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_admin_apply_args' );
$eo_check( 'N25 admin_apply_args: ( $args, WC_Order, $posted )', $eo_args && isset( $eo_args[0]['send_pay_link'] ) && $eo_args[1] instanceof WC_Order && '1' === $eo_args[2]['charge_card'] );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_before_apply' );
$eo_check( 'N25: the choice reaches before_apply', ! is_wp_error( $eo_r ) && $eo_args && ! empty( $eo_args[3]['charge_card'] ) );

// ===========================================================================
WP_CLI::log( '7. Emails: N22, N23' );
$eo_record( 'edit_orders_for_woocommerce_email_data', true, 3 );
$eo_record( 'edit_orders_for_woocommerce_email_after_summary', false, 4 );
add_filter(
	'edit_orders_for_woocommerce_email_data',
	static function ( $data ) {
		$data['pro_line'] = 'Refunded as store credit.';
		return $data;
	},
	20
);
add_action(
	'edit_orders_for_woocommerce_email_after_summary',
	static function ( $order, $data, $plain_text ) {
		if ( ! empty( $data['pro_line'] ) ) {
			echo $plain_text ? esc_html( $data['pro_line'] ) . "\n" : '<p>' . esc_html( $data['pro_line'] ) . '</p>';
		}
	},
	20,
	3
);
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_mailer = WC()->mailer();
foreach ( $eo_mailer->get_emails() as $eo_email ) {
	if ( 'edit_orders_for_woocommerce_order_updated' === $eo_email->id ) {
		$eo_email->trigger( $eo_o->get_id(), $eo_fresh( $eo_o->get_id() ), array( 'changes' => array( 'A change.' ) ) );
	}
}
$eo_args = $eo_last( 'edit_orders_for_woocommerce_email_data' );
$eo_check( 'N22 email_data: ( array $data, $email_id, WC_Order )', $eo_args && is_array( $eo_args[0] ) && 'edit_orders_for_woocommerce_order_updated' === $eo_args[1] && $eo_args[2] instanceof WC_Order );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_email_after_summary' );
$eo_check( 'N23 email_after_summary: ( WC_Order, $data, bool $plain_text, WC_Email )', $eo_args && $eo_args[0] instanceof WC_Order && isset( $eo_args[1]['pro_line'] ) && is_bool( $eo_args[2] ) && $eo_args[3] instanceof WC_Email );
$eo_mails = (array) get_option( Edit_Orders_Mail_Log::OPTION, array() );
$eo_check( 'N22 and N23: the email carries the extension\'s line', $eo_mails && false !== strpos( end( $eo_mails )['message'], 'Refunded as store credit.' ) );
$eo_count = 0;
// Two globs: GLOB_BRACE isn't there on every system (Alpine's musl, as in wp-env).
foreach ( array_merge( (array) glob( EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/emails/*.php' ), (array) glob( EDIT_ORDERS_FOR_WOOCOMMERCE_PATH . 'templates/emails/plain/*.php' ) ) as $eo_template ) {
	if ( false !== strpos( (string) file_get_contents( $eo_template ), "do_action( 'edit_orders_for_woocommerce_email_after_summary'" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		++$eo_count;
	}
}
$eo_check( 'N23 is in all 14 email templates (HTML and plain)', 14 === $eo_count, (string) $eo_count );

// ===========================================================================
WP_CLI::log( '8. Section 6: the approval queue for customer edits' );
$eo_record( 'edit_orders_for_woocommerce_customer_change_intercept', true, 5 );
$eo_queue = static function ( $result, $order, $changes, $plan ) {
	$order->update_meta_data( '_eo_test_waiting', $plan->fingerprint() );
	$order->save();
	return array(
		'type'    => 'success',
		'message' => 'Your change is waiting for the store.',
	);
};
add_filter( 'edit_orders_for_woocommerce_customer_change_intercept', $eo_queue, 20, 4 );
wp_set_current_user( $eo_customer->ID );
$eo_o                = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
list( $eo_p, $eo_c ) = $eo_preview_confirm(
	$eo_o,
	array(
		'edit_orders_for_woocommerce_action' => 'swap',
		'swap'                               => array( $eo_item_id( $eo_o, 'EO-HOODIE-S' ) => $eo_t['product']['EO-HOODIE-M'] ),
	)
);
$eo_args             = $eo_last( 'edit_orders_for_woocommerce_customer_change_intercept' );
$eo_check( 'customer_change_intercept: ( null, WC_Order, $changes, Settlement_Plan, $actor )', $eo_args && null === $eo_args[0] && $eo_args[1] instanceof WC_Order && 'swap' === $eo_args[2][0]['type'] && $eo_args[3] instanceof Edit_Orders_For_WooCommerce_Settlement_Plan && 'customer' === $eo_args[4] );
$eo_check( 'the confirmed change waits: not applied', $eo_c && 'Your change is waiting for the store.' === $eo_c['message'] && (int) current( $eo_fresh( $eo_o->get_id() )->get_items() )->get_variation_id() === (int) $eo_t['product']['EO-HOODIE-S'] );
$eo_check( 'the plan it saw is the previewed one', $eo_fresh( $eo_o->get_id() )->get_meta( '_eo_test_waiting' ) === $eo_p['preview']['fields']['expect'] );
// A confirm whose plan isn't the previewed one (prices changed, or edited fields) never reaches the intercept.
$eo_o     = $eo_make_order( array( array( 'EO-HOODIE-S', 1 ) ) );
$eo_swap  = array(
	'edit_orders_for_woocommerce_action' => 'swap',
	'swap'                               => array( $eo_item_id( $eo_o, 'EO-HOODIE-S' ) => $eo_t['product']['EO-HOODIE-M'] ),
);
$eo_p     = $eo_process( $eo_o, $eo_swap );
$eo_count = count( $eo_calls['edit_orders_for_woocommerce_customer_change_intercept'] ?? array() );
$eo_c     = $eo_process(
	$eo_o,
	array_merge(
		$eo_swap,
		$eo_p['preview']['fields'],
		array(
			'expect' => 'notthepreview',
			'step'   => 'confirm',
		)
	)
);
$eo_check( 'customer_change_intercept: a plan unlike the preview is not passed on', count( $eo_calls['edit_orders_for_woocommerce_customer_change_intercept'] ?? array() ) === $eo_count && ! $eo_fresh( $eo_o->get_id() )->get_meta( '_eo_test_waiting' ) );
$eo_check( 'customer_change_intercept: and it is refused as on the apply path', 'error' === $eo_c['type'] && false !== strpos( $eo_c['message'], 'changed since the preview' ) && (int) current( $eo_fresh( $eo_o->get_id() )->get_items() )->get_variation_id() === (int) $eo_t['product']['EO-HOODIE-S'] );
remove_filter( 'edit_orders_for_woocommerce_customer_change_intercept', $eo_queue, 20 );

$eo_rows = static function ( $rows ) use ( $eo_o ) {
	$rows[] = array(
		'type'    => 'edit',
		'order'   => wc_get_order( $eo_o->get_id() ),
		'request' => array(
			'reason' => 'Swap to M',
			'time'   => time(),
		),
	);
	return $rows;
};
add_filter( 'edit_orders_for_woocommerce_activity_requests', $eo_rows );
$eo_types = wp_list_pluck( Edit_Orders_For_WooCommerce_Activity::requests(), 'type' );
$eo_check( 'activity_requests: an extension\'s request type joins the queue', in_array( 'edit', $eo_types, true ) );
wp_set_current_user( 1 );
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_html = (string) ob_get_clean();
$eo_check( 'the queue row posts its type', false !== strpos( $eo_html, 'name="type" value="edit"' ) );
$eo_check( 'activity_requests_heading: free keeps "Cancellation requests" by default', false !== strpos( $eo_html, '>Cancellation requests</h2>' ) );
$eo_heading = static function ( $heading ) {
	$heading['title'] = 'Requests <b>';
	return $heading;
};
add_filter( 'edit_orders_for_woocommerce_activity_requests_heading', $eo_heading );
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_html = (string) ob_get_clean();
remove_filter( 'edit_orders_for_woocommerce_activity_requests_heading', $eo_heading );
$eo_check( 'activity_requests_heading: an extension renames the queue, escaped', false !== strpos( $eo_html, '>Requests &lt;b&gt;</h2>' ) && false !== strpos( $eo_html, 'Customers asking to cancel, waiting for your decision.' ) );
$eo_record( 'edit_orders_for_woocommerce_activity_decide', true, 5 );
$eo_decide = static function ( $result, $order, $type, $decision ) {
	return 'edit' === $type && 'approve' === $decision ? true : $result;
};
add_filter( 'edit_orders_for_woocommerce_activity_decide', $eo_decide, 20, 4 );
$eo_r    = Edit_Orders_For_WooCommerce_Activity::decide( $eo_fresh( $eo_o->get_id() ), 'approve', '', 'edit' );
$eo_args = $eo_last( 'edit_orders_for_woocommerce_activity_decide' );
$eo_check( 'activity_decide: ( null, WC_Order, $type, $decision, $message )', $eo_args && null === $eo_args[0] && 'edit' === $eo_args[2] && 'approve' === $eo_args[3] );
$eo_check( 'activity_decide: the extension answers its own type', true === $eo_r );
remove_filter( 'edit_orders_for_woocommerce_activity_decide', $eo_decide, 20 );
$eo_r = Edit_Orders_For_WooCommerce_Activity::decide( $eo_fresh( $eo_o->get_id() ), 'approve', '', 'edit' );
$eo_check( 'a type nobody answers is refused, not treated as a cancellation', is_wp_error( $eo_r ) && ! $eo_fresh( $eo_o->get_id() )->has_status( 'cancelled' ) );
remove_filter( 'edit_orders_for_woocommerce_activity_requests', $eo_rows );

// ===========================================================================
WP_CLI::log( '9. The page extension points P1 to P4' );
add_filter(
	'edit_orders_for_woocommerce_page_tabs',
	static function ( $tabs ) {
		$tabs[] = array(
			'key'        => 'pro',
			'label'      => 'Pro tab',
			'capability' => 'manage_woocommerce',
			'count'      => 2,
		);
		$tabs[] = array(
			'key'        => 'secret',
			'label'      => 'Secret tab',
			'capability' => 'do_not_allow',
		);
		return $tabs;
	}
);
$eo_record( 'edit_orders_for_woocommerce_page_tab_pro', false, 1 );
add_action(
	'edit_orders_for_woocommerce_page_tab_pro',
	static function () {
		echo '<div id="eo-pro-tab">Pro content</div>';
	}
);
add_filter(
	'edit_orders_for_woocommerce_settings_sections',
	static function ( $nav ) {
		$nav[] = array(
			'id'    => 'pro-rules',
			'icon'  => 'shield',
			'label' => 'Window rules',
		);
		$nav[] = array( 'pro-other', 'sliders', 'Other' );
		return $nav;
	}
);
add_action(
	'edit_orders_for_woocommerce_settings_after_sections',
	static function () {
		echo '<section id="pro-rules"><input name="pro_rule" value="5" /></section>';
	}
);
$eo_saved = array();
add_action(
	'edit_orders_for_woocommerce_settings_save',
	static function ( $post ) use ( &$eo_saved ) {
		$eo_saved = $post;
	}
);
add_filter(
	'edit_orders_for_woocommerce_page_links',
	static function ( $links ) {
		$links['support'] = 'https://example.com/helpdesk';
		$links['pro']     = 'https://example.com/pro-docs';
		return $links;
	}
);

$eo_keys = wp_list_pluck( Edit_Orders_For_WooCommerce_Admin_Page::tabs(), 'key' );
$eo_check( 'P1: the added tabs are listed after the free ones', array( 'settings', 'activity', 'help', 'pro', 'secret' ) === $eo_keys, implode( ',', $eo_keys ) );
$_GET['tab'] = 'pro';
$eo_check( 'P1: an added tab opens', 'pro' === Edit_Orders_For_WooCommerce_Admin_Page::current_tab() );
$_GET['tab'] = 'secret';
$eo_check( 'P1: a tab the user may not open falls back to Activity', 'activity' === Edit_Orders_For_WooCommerce_Admin_Page::current_tab() );
$_GET['tab'] = 'nonsense';
$eo_check( 'P1: an unknown tab still opens Settings', 'settings' === Edit_Orders_For_WooCommerce_Admin_Page::current_tab() );
$_GET['tab'] = 'pro';
ob_start();
Edit_Orders_For_WooCommerce_Admin_Page::render();
$eo_html = (string) ob_get_clean();
$eo_args = $eo_last( 'edit_orders_for_woocommerce_page_tab_pro' );
$eo_check( 'P1: the tab is printed by its action, with the links', false !== strpos( $eo_html, 'eo-pro-tab' ) && $eo_args && isset( $eo_args[0]['pro'] ) );
$eo_check( 'P1: its label and count show; the secret tab doesn\'t', false !== strpos( $eo_html, 'Pro tab' ) && false === strpos( $eo_html, 'Secret tab' ) );
$eo_check( 'P4: the header uses the filtered Support link', false !== strpos( $eo_html, 'https://example.com/helpdesk' ) );
$eo_links = Edit_Orders_For_WooCommerce_Admin_Page::links();
$eo_check( 'P4: keys added are kept and the four always there', 'https://example.com/pro-docs' === $eo_links['pro'] && isset( $eo_links['docs'], $eo_links['changes'], $eo_links['review'] ) );
$_GET['tab'] = 'help';
ob_start();
Edit_Orders_For_WooCommerce_Admin_Page::render();
$eo_html = (string) ob_get_clean();
$eo_check( 'P4: the Help tab uses the filtered links', false !== strpos( $eo_html, 'https://example.com/helpdesk' ) );
$_GET['tab'] = 'settings';
ob_start();
Edit_Orders_For_WooCommerce_Admin_Page::render();
$eo_html = (string) ob_get_clean();
$eo_nav  = wp_list_pluck( Edit_Orders_For_WooCommerce_Admin_Page::settings_nav(), 0 );
$eo_check( 'P2: section links, keyed and positional', in_array( 'pro-rules', $eo_nav, true ) && in_array( 'pro-other', $eo_nav, true ) && false !== strpos( $eo_html, 'Window rules' ) );
$eo_form_open  = strpos( $eo_html, 'id="edit-orders-settings"' );
$eo_form_close = strpos( $eo_html, '</form>', (int) $eo_form_open );
$eo_section    = strpos( $eo_html, 'id="pro-rules"' );
$eo_check( 'P2: the section is printed inside the settings form', false !== $eo_section && $eo_section > $eo_form_open && $eo_section < $eo_form_close );
unset( $_GET['tab'] );
Edit_Orders_For_WooCommerce_Settings_Form::save(
	array(
		Edit_Orders_For_WooCommerce_Settings::OPTION => array( 'customer_enabled' => 'yes' ),
		'pro_rule'                                   => '5',
	)
);
$eo_check( 'P3: settings_save gets the posted form', isset( $eo_saved['pro_rule'] ) && '5' === $eo_saved['pro_rule'] );

// ===========================================================================
WP_CLI::log( '10. The hold switch on the settings page' );
$eo_check( 'default: off', 'no' === Edit_Orders_For_WooCommerce_Settings::get( 'hold_enabled' ) );
Edit_Orders_For_WooCommerce_Settings_Form::save(
	array(
		Edit_Orders_For_WooCommerce_Settings::OPTION => array(
			'customer_enabled' => 'yes',
			'hold_enabled'     => 'yes',
		),
	)
);
$eo_check( 'saved on', 'yes' === Edit_Orders_For_WooCommerce_Settings::get( 'hold_enabled' ) );
ob_start();
Edit_Orders_For_WooCommerce_Settings_Form::render();
$eo_html = (string) ob_get_clean();
$eo_check( 'the switch is on the page, with its help text', false !== strpos( $eo_html, 'Hold new orders while customers can change them' ) && false !== strpos( $eo_html, 'Paid orders wait as Open for changes until the time for changes ends' ) );
Edit_Orders_For_WooCommerce_Settings_Form::save( array( Edit_Orders_For_WooCommerce_Settings::OPTION => array( 'customer_enabled' => 'yes' ) ) );
$eo_check( 'saved off', 'no' === Edit_Orders_For_WooCommerce_Settings::get( 'hold_enabled' ) );

// ===========================================================================
WP_CLI::log( '11. The counters' );
$eo_counter_order = $eo_make_order( array( array( 'EO-TSHIRT', 1 ) ) );
$eo_cid           = $eo_counter_order->get_id();
$wpdb->delete( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'order_id' => $eo_cid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$eo_base = Edit_Orders_For_WooCommerce_Settings_Form::stats();
$eo_rows = array(
	array( 'cancelled', 'customer', 10 ),
	array( 'cancelled', 'guest', 10 ),
	array( 'cancelled', 'staff', 10 ),
	array( 'cancel_declined', 'staff', 0 ),
	array( 'window_closed', 'customer', 0 ),
	array( 'window_closed', 'guest', 0 ),
	array( 'hold_started', 'system', 0 ),
	array( 'hold_released', 'system', 0 ),
);
foreach ( $eo_rows as $eo_row ) {
	Edit_Orders_For_WooCommerce_Audit_Log::add(
		array(
			'order_id'   => $eo_cid,
			'action'     => $eo_row[0],
			'actor_type' => $eo_row[1],
			'amount'     => $eo_row[2],
		)
	);
}
// One more of each, 31 days ago: outside the 30 days.
foreach ( array( 'cancelled', 'cancel_declined', 'window_closed' ) as $eo_action ) {
	$eo_id = Edit_Orders_For_WooCommerce_Audit_Log::add(
		array(
			'order_id'   => $eo_cid,
			'action'     => $eo_action,
			'actor_type' => 'customer',
		)
	);
	$wpdb->update( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 31 * DAY_IN_SECONDS ) ), array( 'id' => $eo_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$eo_n = Edit_Orders_For_WooCommerce_Settings_Form::stats();
$eo_check( 'Cancellations: 3 in 30 days', 3 === $eo_n['cancelled'] - $eo_base['cancelled'], (string) ( $eo_n['cancelled'] - $eo_base['cancelled'] ) );
$eo_check( '... 2 by customers (customer and guest)', 2 === $eo_n['cancelled_customer'] - $eo_base['cancelled_customer'] );
$eo_check( '... 1 request declined', 1 === $eo_n['cancel_declined'] - $eo_base['cancel_declined'] );
$eo_check( 'Closed early ("said all good"): 2', 2 === $eo_n['closed_early'] - $eo_base['closed_early'] );
$eo_check( 'hold rows are logged but not counted', 2 === $eo_n['customer'] - $eo_base['customer'], (string) ( $eo_n['customer'] - $eo_base['customer'] ) );
$eo_check( '"Open for changes now" = the Orders screen view', count( Edit_Orders_For_WooCommerce_Orders_List::open_order_ids() ) === $eo_n['open_now'] && $eo_n['open_now'] > 0 );
$eo_check( '... with the held count', (int) wc_orders_count( Edit_Orders_For_WooCommerce_Hold::STATUS ) === $eo_n['held'] );
$eo_queries = $wpdb->num_queries;
Edit_Orders_For_WooCommerce_Settings_Form::stats();
WP_CLI::log( sprintf( '  (stats(): %d queries)', $wpdb->num_queries - $eo_queries ) );
$_GET['tab'] = 'activity';
ob_start();
Edit_Orders_For_WooCommerce_Admin_Page::render();
$eo_html = (string) ob_get_clean();
unset( $_GET['tab'] );
$eo_check( 'six tiles: Cancellations and "Open for changes now" show', false !== strpos( $eo_html, 'Cancellations' ) && false !== strpos( $eo_html, 'Open for changes now' ) && false !== strpos( $eo_html, 'by customers' ) && false !== strpos( $eo_html, 'said all good' ) );

// ===========================================================================
WP_CLI::log( '12. No notices, warnings or errors from the plugin' );
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
	foreach ( wc_get_orders( array( 'parent' => $eo_order_id, 'type' => 'shop_order', 'limit' => -1 ) ) as $eo_child ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$eo_child->delete( true );
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
delete_option( Edit_Orders_Mail_Log::OPTION );

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'M14 suite failed.' );
}
WP_CLI::success( 'M14 suite passed.' );
