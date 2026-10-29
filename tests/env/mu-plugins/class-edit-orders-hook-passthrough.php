<?php
/**
 * Plugin Name: Edit Orders Hook Pass-through
 * Description: Development-only. With the option `edit_orders_test_passthrough` set to `yes`, registers every hook added in 1.4.0 as a pass-through (filters return what they get, actions do nothing), so the whole functional suite can prove the hooks change nothing by being there. Never shipped.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function () {
		if ( 'yes' !== get_option( 'edit_orders_test_passthrough' ) ) {
			return;
		}

		$filters = array(
			'window_end',
			'window_max_seconds',
			'should_hold',
			'customer_changes',
			'customer_action',
			'panel_args',
			'customer_preview',
			'can_change_item',
			'normalise_change',
			'refund_handler',
			'cancel_refund',
			'execute_cancel',
			'cancel_request_details',
			'request_summary',
			'cancel_intercept',
			'email_data',
			'balance_redirect',
			'admin_apply_args',
			'customer_change_intercept',
			'activity_requests',
			'activity_requests_heading',
			'activity_decide',
			'page_tabs',
			'settings_sections',
			'page_links',
		);
		foreach ( $filters as $hook ) {
			add_filter(
				'edit_orders_for_woocommerce_' . $hook,
				static function ( $value ) {
					return $value;
				},
				10,
				1
			);
		}

		$actions = array( 'hold_started', 'hold_released', 'panel_actions', 'panel_state', 'preview_after', 'plan_change', 'plan_run', 'cancel_form_fields', 'admin_preview_after', 'email_after_summary', 'admin_address_tab', 'settings_after_sections', 'settings_save' );
		foreach ( $actions as $hook ) {
			add_action( 'edit_orders_for_woocommerce_' . $hook, '__return_null', 10, 0 );
		}

		update_option( 'edit_orders_test_passthrough_loaded', count( $filters ) + count( $actions ), false );
	},
	1
);
