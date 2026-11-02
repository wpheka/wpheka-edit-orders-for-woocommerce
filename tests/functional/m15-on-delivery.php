<?php
/**
 * Functional suite for 1.4.2: a change that costs more on an order paid on delivery
 * (findings 1 and 2 of tasks/edit-orders-1.4.1-pass/README.md).
 *
 * - The linked order for the difference sends no "Processing order" or "New order" email;
 *   the customer's "order updated" and the store's "customer changed" emails say how much
 *   more is paid on delivery.
 * - With Open for changes on, the linked order waits with the original and leaves with it.
 * - Cancelling the original cancels its linked orders still to be collected; completing it
 *   completes them, with no email.
 * - The activity log records it as "on_delivery_added", not a pay link.
 * - Orders paid by cheque, bank transfer or card keep the pay link path, unchanged.
 *
 * Run with: bash tests/functional/run.sh m15-on-delivery (or all suites without an argument).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

$eo_t = array(
	'pass'   => 0,
	'fail'   => 0,
	'orders' => array(),
	'stock'  => array(),
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

$eo_small = wc_get_product_id_by_sku( 'EO-HOODIE-S' );
$eo_large = wc_get_product_id_by_sku( 'EO-HOODIE-L' );
if ( ! $eo_small || ! $eo_large ) {
	WP_CLI::error( 'EO-HOODIE-S or EO-HOODIE-L missing. Run tests/env/seed.php first.' );
}
foreach ( array( $eo_small, $eo_large ) as $eo_id ) {
	$eo_t['stock'][ $eo_id ] = wc_get_product( $eo_id )->get_stock_quantity();
}

$eo_customer = get_user_by( 'login', 'customer' );
wp_set_current_user( $eo_customer->ID );
WC()->payment_gateways();
$eo_held_status = Edit_Orders_For_WooCommerce_Hold::STATUS;

// Bank transfer and cheque are off on the seeded store; turn them on for this suite.
$eo_gateway_settings = array();
foreach ( array( 'bacs', 'cheque' ) as $eo_gateway ) {
	$eo_gateway_settings[ $eo_gateway ] = get_option( "woocommerce_{$eo_gateway}_settings", null );
	update_option( "woocommerce_{$eo_gateway}_settings", array_merge( (array) $eo_gateway_settings[ $eo_gateway ], array( 'enabled' => 'yes' ) ) );
}
WC()->payment_gateways()->init();

/**
 * A pending Ontario order for one Hoodie S, then paid through the gateway the way its checkout pays.
 */
$eo_paid_hoodie = static function ( $gateway ) use ( &$eo_t, $eo_customer, $eo_small ) {
	$order   = wc_create_order( array( 'customer_id' => $eo_customer->ID ) );
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
	$order->add_product( wc_get_product( $eo_small ), 1 );
	$order->calculate_totals();
	$order->set_payment_method( WC()->payment_gateways()->payment_gateways()[ $gateway ] );
	$order->save();
	$eo_t['orders'][] = $order->get_id();

	if ( 'edit_orders_test' === $gateway ) {
		$order->payment_complete( 'TEST-' . $order->get_id() );
	} else {
		WC()->payment_gateways()->payment_gateways()[ $gateway ]->process_payment( $order->get_id() );
	}
	Edit_Orders_For_WooCommerce_Customer_Rules::reset_cache();

	return wc_get_order( $order->get_id() );
};

$eo_fresh = static function ( $order_id ) {
	Edit_Orders_For_WooCommerce_Customer_Rules::reset_cache();
	Edit_Orders_For_WooCommerce_Orders_List::reset();
	return wc_get_order( $order_id );
};

// The executor's result for the last change, as the emails and the activity log get it.
$eo_last = null;
add_action(
	'edit_orders_for_woocommerce_after_apply',
	static function ( $order, $result ) use ( &$eo_last ) {
		$eo_last = $result;
	},
	5,
	2
);

// The customer swaps Hoodie S for Hoodie L ($40 to $45) from My Account: it costs more.
$eo_swap_to_large = static function ( WC_Order $order ) use ( &$eo_t, &$eo_last, $eo_large, $eo_customer ) {
	$items   = $order->get_items();
	$item    = reset( $items );
	$eo_last = null;
	wp_set_current_user( $eo_customer->ID );
	$out = Edit_Orders_For_WooCommerce_Frontend::process(
		wc_get_order( $order->get_id() ),
		array(
			'edit_orders_for_woocommerce_action' => 'swap',
			'swap'                               => array( $item->get_id() => $eo_large ),
			'step'                               => 'confirm',
		),
		'customer'
	);
	if ( ! is_array( $eo_last ) ) {
		return new WP_Error( 'not_applied', is_array( $out ) && isset( $out['message'] ) ? wp_strip_all_tags( $out['message'] ) : 'not applied' );
	}
	if ( ! empty( $eo_last['balance_order_id'] ) ) {
		$eo_t['orders'][] = (int) $eo_last['balance_order_id'];
	}
	return $eo_last;
};

$eo_item_names = static function ( $order_id ) {
	return implode(
		',',
		array_map(
			static function ( $item ) {
				return $item->get_name();
			},
			array_values( wc_get_order( $order_id )->get_items() )
		)
	);
};

$eo_notes = static function ( WC_Order $order ) {
	return wp_list_pluck( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ), 'content' );
};

// Does the order have a note containing this text (tags stripped)?
$eo_has_note = static function ( WC_Order $order, $text ) use ( $eo_notes ) {
	foreach ( $eo_notes( $order ) as $note ) {
		if ( false !== strpos( wp_strip_all_tags( $note ), $text ) ) {
			return true;
		}
	}
	return false;
};

$eo_mails = static function () {
	return (array) get_option( Edit_Orders_Mail_Log::OPTION, array() );
};

// Emails about an order: its number in the subject or the body ("#37", not "#370").
$eo_mails_about = static function ( WC_Order $order ) use ( $eo_mails ) {
	return array_values(
		array_filter(
			$eo_mails(),
			static function ( $mail ) use ( $order ) {
				return (bool) preg_match( '/#' . preg_quote( (string) $order->get_order_number(), '/' ) . '(?!\d)/', $mail['subject'] . ' ' . wp_strip_all_tags( (string) $mail['message'] ) );
			}
		)
	);
};

$eo_log_rows = static function ( $order_id, $action ) use ( $wpdb ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d AND action = %s ORDER BY id', Edit_Orders_For_WooCommerce_Audit_Log::table(), $order_id, $action ), ARRAY_A );
};

$eo_started = array();
add_action(
	'edit_orders_for_woocommerce_hold_started',
	static function ( $order ) use ( &$eo_started ) {
		$eo_started[] = $order->get_id();
	}
);

// ===========================================================================
WP_CLI::log( '1. Cash on delivery, hold off: the linked order is quiet and counted as its own kind' );
$eo_links_before = Edit_Orders_For_WooCommerce_Settings_Form::stats()['links'];
$eo_o            = $eo_paid_hoodie( 'cod' );
$eo_check( 'the cash on delivery order is Processing and unpaid', $eo_o->has_status( 'processing' ) && Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $eo_o ), $eo_o->get_status() );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_r = $eo_swap_to_large( $eo_o );
$eo_check( 'the change is collected on delivery', is_array( $eo_r ) && 'collect_on_delivery' === $eo_r['status'], is_wp_error( $eo_r ) ? $eo_r->get_error_message() : wp_json_encode( is_array( $eo_r ) ? $eo_r['status'] : $eo_r ) );
$eo_linked = $eo_fresh( (int) $eo_r['balance_order_id'] );
$eo_check( 'the linked order is marked as collected on delivery', Edit_Orders_For_WooCommerce_Balance_Orders::is_on_delivery_balance( $eo_linked ) );
$eo_check( 'it is Processing, still unpaid (collected with the original)', $eo_linked->has_status( 'processing' ) && Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $eo_linked ), $eo_linked->get_status() );
$eo_check( 'the change is applied: the original now has Hoodie L', false !== strpos( $eo_item_names( $eo_o->get_id() ), 'Hoodie - L' ), $eo_item_names( $eo_o->get_id() ) );
$eo_check( 'no email at all mentions the linked order (no Processing order, no New order)', ! $eo_mails_about( $eo_linked ), wp_json_encode( wp_list_pluck( $eo_mails_about( $eo_linked ), 'subject' ) ) );
$eo_check( 'WooCommerce\'s own email filter says no for it, and yes for the original', false === apply_filters( 'woocommerce_email_enabled_new_order', true, $eo_linked, null ) && true === apply_filters( 'woocommerce_email_enabled_new_order', true, $eo_fresh( $eo_o->get_id() ), null ) );
$eo_amount  = wp_strip_all_tags( wc_price( $eo_linked->get_total(), array( 'currency' => $eo_linked->get_currency() ) ) );
$eo_updated = array_values(
	array_filter(
		$eo_mails_about( $eo_o ),
		static function ( $mail ) {
			return false !== strpos( $mail['subject'], 'was updated' );
		}
	)
);
$eo_check( 'the customer\'s "order updated" email says how much more is paid on delivery', 1 === count( $eo_updated ) && false !== strpos( html_entity_decode( wp_strip_all_tags( $eo_updated[0]['message'] ) ), 'You will pay ' . html_entity_decode( $eo_amount ) . ' more on delivery.' ), count( $eo_updated ) . ' email(s)' );
$eo_changed = array_values(
	array_filter(
		$eo_mails_about( $eo_o ),
		static function ( $mail ) {
			return false !== strpos( $mail['subject'], 'changed by the customer' );
		}
	)
);
$eo_check( 'the store\'s "customer changed" email says it too, and is the only store email', 1 === count( $eo_changed ) && false !== strpos( html_entity_decode( wp_strip_all_tags( $eo_changed[0]['message'] ) ), 'collected on delivery with the order' ), count( $eo_changed ) . ' email(s)' );
$eo_check( 'two emails in all for the change', 2 === count( $eo_mails() ), wp_json_encode( wp_list_pluck( $eo_mails(), 'subject' ) ) );
$eo_check( 'activity: logged as "on_delivery_added", not "balance_created"', 1 === count( $eo_log_rows( $eo_o->get_id(), 'on_delivery_added' ) ) && ! $eo_log_rows( $eo_o->get_id(), 'balance_created' ) );
$eo_stats = Edit_Orders_For_WooCommerce_Settings_Form::stats();
$eo_check( 'Pay links tile unchanged', $eo_links_before === $eo_stats['links'], $eo_links_before . ' -> ' . $eo_stats['links'] );
$eo_check( 'still counted as an edited order and a customer change', $eo_stats['edited'] >= 1 && $eo_stats['customer'] >= 1 );
$eo_check( 'the original\'s note names the linked order and the amount', $eo_has_note( $eo_fresh( $eo_o->get_id() ), 'more on delivery, on linked order #' . $eo_linked->get_order_number() ) );

wp_set_current_user( 1 );
$_GET['tab'] = 'activity';
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_act = ob_get_clean();
unset( $_GET['tab'] );
$eo_check( 'Activity tab shows "Extra amount added, paid on delivery"', false !== strpos( $eo_act, 'Extra amount added, paid on delivery' ) );

// An entry written before 1.4.2 still shows as it did.
Edit_Orders_For_WooCommerce_Audit_Log::add(
	array(
		'order_id'   => 999851,
		'actor_type' => 'customer',
		'action'     => 'balance_created',
		'after'      => array( 'changes' => array( 'Old entry' ) ),
	)
);
$_GET['tab']        = 'activity';
$_GET['log_action'] = 'balance_created';
ob_start();
Edit_Orders_For_WooCommerce_Activity::render();
$eo_act = ob_get_clean();
unset( $_GET['tab'], $_GET['log_action'] );
wp_set_current_user( $eo_customer->ID );
$eo_check( 'an older "Balance order created" entry still shows with its label', false !== strpos( $eo_act, '>Balance order created</span>' ) );
$eo_check( 'and still counts as a pay link, as it did', Edit_Orders_For_WooCommerce_Settings_Form::stats()['links'] === $eo_links_before + 1 );
$wpdb->delete( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'order_id' => 999851 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

WP_CLI::log( '   Cancelled from WooCommerce\'s order screen' );
$eo_fresh( $eo_o->get_id() )->update_status( 'cancelled', 'Cancelled by the store.' );
$eo_linked = $eo_fresh( $eo_linked->get_id() );
$eo_check( 'cancelling the original cancels its linked order still to be collected', $eo_linked->has_status( 'cancelled' ), $eo_linked->get_status() );

WP_CLI::log( '   Completed: delivered and the cash collected' );
$eo_o = $eo_paid_hoodie( 'cod' );
$eo_r = $eo_swap_to_large( $eo_o );
$eo_check( 'set up: the change is collected on delivery', is_array( $eo_r ) && 'collect_on_delivery' === $eo_r['status'], is_wp_error( $eo_r ) ? $eo_r->get_error_message() : '' );
$eo_linked = $eo_fresh( (int) $eo_r['balance_order_id'] );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_fresh( $eo_o->get_id() )->update_status( 'completed', 'Delivered.' );
$eo_linked = $eo_fresh( $eo_linked->get_id() );
$eo_check( 'completing the original completes its linked order', $eo_linked->has_status( 'completed' ), $eo_linked->get_status() );
$eo_check( 'the linked order\'s note names the original', $eo_has_note( $eo_linked, 'Completed with order #' . $eo_o->get_order_number() . '.' ) );
$eo_check( 'no email mentions the linked order (no Completed order)', ! $eo_mails_about( $eo_linked ), wp_json_encode( wp_list_pluck( $eo_mails_about( $eo_linked ), 'subject' ) ) );
$eo_check( 'the Completed order email filter says no for it, and yes for the original', false === apply_filters( 'woocommerce_email_enabled_customer_completed_order', true, $eo_linked, null ) && true === apply_filters( 'woocommerce_email_enabled_customer_completed_order', true, $eo_fresh( $eo_o->get_id() ), null ) );

// ===========================================================================
WP_CLI::log( '2. Cash on delivery, hold on: the linked order waits with the original' );
$eo_set(
	array(
		'hold_enabled'   => 'yes',
		'window_minutes' => 60,
	)
);
$eo_o = $eo_paid_hoodie( 'cod' );
$eo_check( 'the cash on delivery order waits as Open for changes', $eo_o->has_status( $eo_held_status ), $eo_o->get_status() );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_started = array();
$eo_r       = $eo_swap_to_large( $eo_o );
$eo_check( 'the change is collected on delivery', is_array( $eo_r ) && 'collect_on_delivery' === $eo_r['status'] );
$eo_linked = $eo_fresh( (int) $eo_r['balance_order_id'] );
$eo_check( 'the linked order waits too, not in Processing', $eo_linked->has_status( $eo_held_status ), $eo_linked->get_status() );
$eo_check( 'the change is applied all the same (Hoodie L on the original)', false !== strpos( $eo_item_names( $eo_o->get_id() ), 'Hoodie - L' ), $eo_item_names( $eo_o->get_id() ) );
$eo_own_release = as_next_scheduled_action( Edit_Orders_For_WooCommerce_Hold::ACTION, array( $eo_linked->get_id() ), Edit_Orders_For_WooCommerce_Hold::GROUP );
$eo_check( 'no hold of its own: no "Open for changes until" note, no hold_started, no release scheduled', ! in_array( $eo_linked->get_id(), $eo_started, true ) && false === $eo_own_release && ! $eo_has_note( $eo_linked, 'Open for changes until' ) );
$eo_check( 'no email mentions the linked order', ! $eo_mails_about( $eo_linked ), wp_json_encode( wp_list_pluck( $eo_mails_about( $eo_linked ), 'subject' ) ) );
$eo_stats = Edit_Orders_For_WooCommerce_Settings_Form::stats();
$eo_held  = count(
	array_filter(
		(array) wc_get_orders(
			array(
				'type'   => 'shop_order',
				'status' => array( 'wc-' . $eo_held_status ),
				'limit'  => -1,
			)
		),
		static function ( $order ) {
			return ! Edit_Orders_For_WooCommerce_Balance_Orders::is_balance_order( $order );
		}
	)
);
$eo_check( '"held" counts the original only, not its linked order', $eo_held === $eo_stats['held'], $eo_held . ' original(s), tile ' . $eo_stats['held'] );
$eo_items = array_values( $eo_fresh( $eo_o->get_id() )->get_items() );
$eo_parts = Edit_Orders_For_WooCommerce_Balance_Orders::line_parts( $eo_fresh( $eo_o->get_id() ), $eo_items[0] );
$eo_check( 'the original\'s item lines still count the linked order\'s extra (later edits refund it)', ! empty( $eo_parts ) );

// The time for changes ends: both move to Processing, the linked order without an email.
$eo_o = $eo_fresh( $eo_o->get_id() );
$eo_o->set_date_created( time() - 2 * HOUR_IN_SECONDS );
$eo_o->save();
Edit_Orders_For_WooCommerce_Hold::release_scheduled( $eo_o->get_id() );
$eo_o      = $eo_fresh( $eo_o->get_id() );
$eo_linked = $eo_fresh( $eo_linked->get_id() );
$eo_check( 'the time ends: the original moves to Processing', $eo_o->has_status( 'processing' ), $eo_o->get_status() );
$eo_check( 'and the linked order moves with it', $eo_linked->has_status( 'processing' ), $eo_linked->get_status() );
$eo_check( 'with the note "Released to Processing with order #..."', $eo_has_note( $eo_linked, 'Released to Processing with order #' . $eo_o->get_order_number() . '.' ) );
$eo_check( 'still unpaid: collected with the original', Edit_Orders_For_WooCommerce_Eligibility::is_pay_on_delivery( $eo_linked ) );
$eo_check( 'still no email about the linked order after the release', ! $eo_mails_about( $eo_linked ) );

WP_CLI::log( '   "Release now" also takes the linked order along' );
$eo_o      = $eo_paid_hoodie( 'cod' );
$eo_r      = $eo_swap_to_large( $eo_o );
$eo_linked = $eo_fresh( (int) $eo_r['balance_order_id'] );
wp_set_current_user( 1 );
Edit_Orders_For_WooCommerce_Hold::release( $eo_fresh( $eo_o->get_id() ), 'staff' );
wp_set_current_user( $eo_customer->ID );
$eo_check( 'released by the store: both in Processing', $eo_fresh( $eo_o->get_id() )->has_status( 'processing' ) && $eo_fresh( $eo_linked->get_id() )->has_status( 'processing' ), $eo_fresh( $eo_linked->get_id() )->get_status() );

WP_CLI::log( '   Cancelled while waiting' );
$eo_o      = $eo_paid_hoodie( 'cod' );
$eo_r      = $eo_swap_to_large( $eo_o );
$eo_linked = $eo_fresh( (int) $eo_r['balance_order_id'] );
$eo_check( 'the linked order waits', $eo_linked->has_status( $eo_held_status ) );
$eo_fresh( $eo_o->get_id() )->update_status( 'cancelled', 'Cancelled by the store.' );
$eo_check( 'cancelling the waiting original cancels the waiting linked order', $eo_fresh( $eo_linked->get_id() )->has_status( 'cancelled' ), $eo_fresh( $eo_linked->get_id() )->get_status() );

WP_CLI::log( '   The hold switched off releases both' );
$eo_o      = $eo_paid_hoodie( 'cod' );
$eo_r      = $eo_swap_to_large( $eo_o );
$eo_linked = $eo_fresh( (int) $eo_r['balance_order_id'] );
$eo_set( array( 'hold_enabled' => 'no' ) );
$eo_check( 'hold off: the original and its linked order are both in Processing', $eo_fresh( $eo_o->get_id() )->has_status( 'processing' ) && $eo_fresh( $eo_linked->get_id() )->has_status( 'processing' ), $eo_fresh( $eo_o->get_id() )->get_status() . ' / ' . $eo_fresh( $eo_linked->get_id() )->get_status() );

// ===========================================================================
WP_CLI::log( '3. Cheque, bank transfer and card: the pay link path, unchanged' );
foreach ( array( 'cheque', 'bacs' ) as $eo_gateway ) {
	$eo_o = $eo_paid_hoodie( $eo_gateway );
	$eo_check( "{$eo_gateway}: On hold and unpaid after checkout", $eo_o->has_status( 'on-hold' ) && ! $eo_o->get_date_paid(), $eo_o->get_status() );
	$eo_check( "{$eo_gateway}: not edited with Edit Orders until it is paid", is_wp_error( Edit_Orders_For_WooCommerce_Eligibility::check_order( $eo_o ) ) );
	wp_set_current_user( 1 );
	$eo_o->update_status( 'processing', 'Payment received.' );
	wp_set_current_user( $eo_customer->ID );
	$eo_o = $eo_fresh( $eo_o->get_id() );
	$eo_check( "{$eo_gateway}: marked paid by the store (paid date set)", (bool) $eo_o->get_date_paid() );
	$eo_links_before = Edit_Orders_For_WooCommerce_Settings_Form::stats()['links'];
	$eo_r            = $eo_swap_to_large( $eo_o );
	$eo_balance      = is_array( $eo_r ) && ! empty( $eo_r['balance_order_id'] ) ? $eo_fresh( (int) $eo_r['balance_order_id'] ) : null;
	$eo_check( "{$eo_gateway}: an increase is a pay link (balance due), as before", is_array( $eo_r ) && 'balance_due' === $eo_r['status'] && $eo_balance && $eo_balance->has_status( 'pending' ), is_array( $eo_r ) ? $eo_r['status'] : 'error' );
	$eo_check( "{$eo_gateway}: the balance order is not marked as collected on delivery", $eo_balance && ! Edit_Orders_For_WooCommerce_Balance_Orders::is_on_delivery_balance( $eo_balance ) );
	$eo_check( "{$eo_gateway}: logged as \"balance_created\" and counted as a pay link", 1 === count( $eo_log_rows( $eo_o->get_id(), 'balance_created' ) ) && Edit_Orders_For_WooCommerce_Settings_Form::stats()['links'] === $eo_links_before + 1 );
}

$eo_o       = $eo_paid_hoodie( 'edit_orders_test' );
$eo_r       = $eo_swap_to_large( $eo_o );
$eo_balance = $eo_fresh( (int) $eo_r['balance_order_id'] );
$eo_check( 'card: an increase is a pay link (balance due)', 'balance_due' === $eo_r['status'] && $eo_balance->has_status( 'pending' ) );
delete_option( Edit_Orders_Mail_Log::OPTION );
$eo_balance->payment_complete( 'TEST-B-' . $eo_balance->get_id() );
$eo_balance = $eo_fresh( $eo_balance->get_id() );
$eo_check( 'card: the paid balance order is not marked, so its emails are as they were', ! Edit_Orders_For_WooCommerce_Balance_Orders::is_on_delivery_balance( $eo_balance ) && true === apply_filters( 'woocommerce_email_enabled_new_order', true, $eo_balance, null ) && true === apply_filters( 'woocommerce_email_enabled_customer_processing_order', true, $eo_balance, null ) );
$eo_new_order = array_filter( wp_list_pluck( $eo_mails_about( $eo_balance ), 'subject' ), static fn( $subject ) => false !== stripos( $subject, 'new order' ) );
$eo_check( 'card: WooCommerce\'s "New order" email for the paid balance order is still sent', (bool) $eo_new_order, wp_json_encode( wp_list_pluck( $eo_mails(), 'subject' ) ) );

// ===========================================================================
WP_CLI::log( '4. No notices, warnings or errors from the plugin' );
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
foreach ( array_reverse( array_unique( array_filter( $eo_t['orders'] ) ) ) as $eo_order_id ) {
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
foreach ( $eo_gateway_settings as $eo_gateway => $eo_value ) {
	if ( null === $eo_value ) {
		delete_option( "woocommerce_{$eo_gateway}_settings" );
	} else {
		update_option( "woocommerce_{$eo_gateway}_settings", $eo_value );
	}
}
if ( null === $eo_settings_before ) {
	delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
} else {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $eo_settings_before );
}
delete_option( Edit_Orders_Mail_Log::OPTION );
delete_transient( Edit_Orders_For_WooCommerce_Hold::SWEEP_TRANSIENT );

WP_CLI::log( sprintf( '%d passed, %d failed', $eo_t['pass'], $eo_t['fail'] ) );
if ( $eo_t['fail'] ) {
	WP_CLI::error( 'M15 suite failed.' );
}
WP_CLI::success( 'M15 suite passed.' );
