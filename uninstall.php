<?php
/**
 * Uninstall Edit Orders for WooCommerce.
 *
 * Data is kept unless "Delete data on uninstall" is checked (option
 * `edit_orders_for_woocommerce_delete_data`). Balance orders are real orders
 * and are never deleted.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Always, before anything else: release orders still held as Open for changes, so none
 * is left in a status WooCommerce no longer knows (a network, or a plugin deleted without
 * being deactivated). The plugin isn't loaded here, so the status is registered first:
 * order queries ignore statuses WordPress doesn't know.
 */
if ( function_exists( 'wc_get_orders' ) ) {
	if ( ! get_post_status_object( 'wc-open-for-changes' ) ) {
		register_post_status(
			'wc-open-for-changes',
			array(
				'label'                     => 'Open for changes',
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
			)
		);
	}
	add_filter(
		'wc_order_statuses',
		static function ( $statuses ) {
			$statuses['wc-open-for-changes'] = 'Open for changes';
			return $statuses;
		}
	);

	// The gateways' filters keep a cash on delivery order without a paid date at Processing.
	WC()->payment_gateways();

	for ( $edit_orders_for_woocommerce_round = 0; $edit_orders_for_woocommerce_round < 200; $edit_orders_for_woocommerce_round++ ) {
		$edit_orders_for_woocommerce_held = wc_get_orders(
			array(
				'type'   => 'shop_order',
				'status' => array( 'wc-open-for-changes' ),
				'limit'  => 50,
			)
		);
		if ( ! $edit_orders_for_woocommerce_held ) {
			break;
		}
		foreach ( $edit_orders_for_woocommerce_held as $edit_orders_for_woocommerce_order ) {
			$edit_orders_for_woocommerce_order->update_status( 'processing', __( 'Released to Processing: Edit Orders for WooCommerce was deleted.', 'wpheka-edit-orders-for-woocommerce' ) );
		}
	}

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'edit_orders_for_woocommerce_release_hold' );
	}
}

if ( 'yes' !== get_option( 'edit_orders_for_woocommerce_delete_data' ) ) {
	return;
}

global $wpdb;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall removes our own table and meta.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}edit_orders_for_woocommerce_log" );

// Order meta, in both order storages.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_edit_orders_for_woocommerce_' ) . '%' ) );
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wc_orders_meta' ) ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key LIKE %s", $wpdb->esc_like( '_edit_orders_for_woocommerce_' ) . '%' ) );
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'edit_orders_for_woocommerce_' ) . '%' ) );

// Transients: one-time messages, rate limit counters, admin notices and the pending requests count.
foreach ( array( 'eofw_', 'edit_orders_for_woocommerce_' ) as $edit_orders_for_woocommerce_prefix ) {
	foreach ( array( '_transient_', '_transient_timeout_' ) as $edit_orders_for_woocommerce_kind ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $edit_orders_for_woocommerce_kind . $edit_orders_for_woocommerce_prefix ) . '%' ) );
	}
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
