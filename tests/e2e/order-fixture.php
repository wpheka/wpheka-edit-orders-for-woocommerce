<?php
/**
 * Browser-test fixtures: create a paid order, or delete orders and reset stock.
 *
 * Create: wp eval-file .../order-fixture.php create QTY [guest] [SKU]
 *   prints ORDER_ID=<id>, RECEIVED=<thank-you URL with key>, VIEW=<My Account URL>.
 *   "guest" makes it a guest order; SKU defaults to EO-TSHIRT.
 * Clean:  wp eval-file .../order-fixture.php clean <id> [<id> ...]
 * Free shipping: wp eval-file .../order-fixture.php free-shipping on|off
 *   "on" leaves Alberta's zone with only free shipping; "off" restores its flat rate.
 * Close:  wp eval-file .../order-fixture.php close <id> (closes the customer's window).
 * Settings: wp eval-file .../order-fixture.php settings-backup | settings-restore
 * Billing:  wp eval-file .../order-fixture.php billing-on (customers may change the
 *   billing address; it starts off on a store updated from 1.2.x).
 * HPOS:   wp eval-file .../order-fixture.php hpos (prints HPOS=on or HPOS=off).
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'pre_wp_mail', '__return_true' );

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- WP-CLI passes positional arguments in $args.
$eo_command = isset( $args[0] ) ? $args[0] : '';

if ( 'create' === $eo_command ) {
	$eo_qty     = isset( $args[1] ) ? max( 1, (int) $args[1] ) : 1;
	$eo_address = array(
		'first_name' => 'Casey',
		'last_name'  => 'Customer',
		'address_1'  => '1 Yonge St',
		'city'       => 'Toronto',
		'state'      => 'ON',
		'postcode'   => 'M5E 1E5',
		'country'    => 'CA',
	);
	$eo_guest   = isset( $args[2] ) && 'guest' === $args[2];
	$eo_sku     = isset( $args[3] ) ? $args[3] : 'EO-TSHIRT';
	$eo_order   = wc_create_order( array( 'customer_id' => $eo_guest ? 0 : (int) get_user_by( 'login', 'customer' )->ID ) );
	$eo_order->set_address( array_merge( $eo_address, array( 'email' => 'customer@example.com' ) ), 'billing' );
	$eo_order->set_address( $eo_address, 'shipping' );
	$eo_order->add_product( wc_get_product( wc_get_product_id_by_sku( $eo_sku ) ), $eo_qty );
	$eo_zone     = WC_Shipping_Zones::get_zone_matching_package(
		array(
			'destination' => array(
				'country'  => 'CA',
				'state'    => 'ON',
				'postcode' => 'M5E 1E5',
			),
		)
	);
	$eo_method   = current( $eo_zone->get_shipping_methods( true ) );
	$eo_shipping = new WC_Order_Item_Shipping();
	$eo_shipping->set_method_title( 'Flat rate' );
	$eo_shipping->set_method_id( 'flat_rate' );
	$eo_shipping->set_instance_id( $eo_method->get_instance_id() );
	$eo_shipping->set_total( 10 );
	$eo_order->add_item( $eo_shipping );
	$eo_order->calculate_totals();
	$eo_order->set_payment_method( WC()->payment_gateways()->payment_gateways()['edit_orders_test'] );
	$eo_order->save();
	$eo_order->payment_complete( 'E2E-' . $eo_order->get_id() );
	WP_CLI::log( 'ORDER_ID=' . $eo_order->get_id() );
	WP_CLI::log( 'RECEIVED=' . $eo_order->get_checkout_order_received_url() );
	WP_CLI::log( 'VIEW=' . $eo_order->get_view_order_url() );
} elseif ( 'clean' === $eo_command ) {
	foreach ( array_slice( $args, 1 ) as $eo_id ) {
		$eo_order = wc_get_order( (int) $eo_id );
		if ( ! $eo_order ) {
			continue;
		}
		$eo_balances = wc_get_orders(
			array(
				'parent' => $eo_order->get_id(),
				'type'   => 'shop_order',
				'limit'  => -1,
				'status' => array_keys( wc_get_order_statuses() ),
			)
		);
		foreach ( array_merge( $eo_balances, array( $eo_order ) ) as $eo_delete ) {
			foreach ( $eo_delete->get_refunds() as $eo_refund ) {
				$eo_refund->delete( true );
			}
			$GLOBALS['wpdb']->delete( Edit_Orders_For_WooCommerce_Audit_Log::table(), array( 'order_id' => $eo_delete->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$eo_delete->delete( true );
		}
	}
	foreach ( array(
		'EO-TSHIRT'   => 50,
		'EO-MUG'      => 50,
		'EO-HOODIE-S' => 20,
		'EO-HOODIE-M' => 20,
		'EO-HOODIE-L' => 20,
	) as $eo_reset_sku => $eo_reset_qty ) {
		wc_update_product_stock( wc_get_product_id_by_sku( $eo_reset_sku ), $eo_reset_qty, 'set' );
	}
	WP_CLI::log( 'CLEANED' );
} elseif ( 'free-shipping' === $eo_command ) {
	// "on": Alberta's zone offers only free shipping, so a flat-rate order moved there must choose. "off": undo.
	global $wpdb;
	$eo_zone = WC_Shipping_Zones::get_zone_matching_package(
		array(
			'destination' => array(
				'country'  => 'CA',
				'state'    => 'AB',
				'postcode' => 'T2P 1B3',
			),
		)
	);
	$eo_on   = isset( $args[1] ) && 'on' === $args[1];
	foreach ( $eo_zone->get_shipping_methods() as $eo_method ) {
		if ( 'flat_rate' === $eo_method->id ) {
			$wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => $eo_on ? 0 : 1 ), array( 'instance_id' => $eo_method->get_instance_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		} elseif ( 'free_shipping' === $eo_method->id ) {
			$eo_zone->delete_shipping_method( $eo_method->get_instance_id() );
		}
	}
	if ( $eo_on ) {
		$eo_zone->add_shipping_method( 'free_shipping' );
	}
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	WP_CLI::log( 'FREE_SHIPPING=' . ( $eo_on ? 'on' : 'off' ) );
} elseif ( 'close' === $eo_command ) {
	// Close the customer's window, as "All good, no changes needed" does.
	$eo_order = wc_get_order( isset( $args[1] ) ? (int) $args[1] : 0 );
	if ( $eo_order ) {
		$eo_order->update_meta_data( Edit_Orders_For_WooCommerce_Customer_Rules::WINDOW_CLOSED_META, time() );
		$eo_order->save();
		WP_CLI::log( 'CLOSED=' . $eo_order->get_id() );
	}
} elseif ( 'settings-backup' === $eo_command ) {
	// Keep the settings as they are, to put back after a browser test changes them.
	update_option( 'edit_orders_e2e_settings_backup', array( 'value' => get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, null ) ), false );
	WP_CLI::log( 'SETTINGS=saved' );
} elseif ( 'settings-restore' === $eo_command ) {
	$eo_backup = get_option( 'edit_orders_e2e_settings_backup', null );
	if ( is_array( $eo_backup ) ) {
		if ( null === $eo_backup['value'] ) {
			delete_option( Edit_Orders_For_WooCommerce_Settings::OPTION );
		} else {
			update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, $eo_backup['value'] );
		}
		delete_option( 'edit_orders_e2e_settings_backup' );
	}
	WP_CLI::log( 'SETTINGS=restored' );
} elseif ( 'billing-on' === $eo_command ) {
	update_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array_merge( (array) get_option( Edit_Orders_For_WooCommerce_Settings::OPTION, array() ), array( 'action_address_billing' => 'yes' ) ) );
	WP_CLI::log( 'BILLING=on' );
} elseif ( 'hpos' === $eo_command ) {
	// Prints whether HPOS is on, for a test that switches it.
	WP_CLI::log( 'HPOS=' . ( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'on' : 'off' ) );
}
