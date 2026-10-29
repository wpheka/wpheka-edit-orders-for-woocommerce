<?php
/**
 * Plugin Name: Edit Orders Fake Pro
 * Description: Development-only. With the option `edit_orders_test_fake_pro` set to `yes`, uses the page extension points added in 1.4.0 the way Pro would: a tab, a settings section saved with the form, and changed Support and docs links. For the browser tests. Never shipped.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function () {
		if ( 'yes' !== get_option( 'edit_orders_test_fake_pro' ) ) {
			return;
		}

		add_filter(
			'edit_orders_for_woocommerce_page_tabs',
			static function ( $tabs ) {
				$tabs[] = array(
					'key'        => 'fake-pro',
					'label'      => 'Pro analytics',
					'capability' => 'manage_woocommerce',
					'count'      => 0,
				);
				return $tabs;
			}
		);

		add_action(
			'edit_orders_for_woocommerce_page_tab_fake-pro',
			static function ( $links ) {
				echo '<div class="w-card" id="eofw-fake-pro-tab"><p>Fake Pro tab. Docs: <a href="' . esc_url( $links['pro_docs'] ) . '">Pro docs</a></p></div>';
			}
		);

		add_filter(
			'edit_orders_for_woocommerce_settings_sections',
			static function ( $nav ) {
				$nav[] = array(
					'id'    => 'fake-pro-rules',
					'icon'  => 'shield',
					'label' => 'Pro window rules',
				);
				return $nav;
			}
		);

		add_action(
			'edit_orders_for_woocommerce_settings_after_sections',
			static function () {
				\Edit_Orders_For_WooCommerce\AdminUI\Fields::section_start( 'fake-pro-rules', 'Pro window rules', 'A section added by an extension.' );
				\Edit_Orders_For_WooCommerce\AdminUI\Fields::number_row(
					array(
						'name'  => 'edit_orders_test_fake_pro_value',
						'value' => (int) get_option( 'edit_orders_test_fake_pro_value', 15 ),
						'label' => 'Minutes for local pickup',
						'min'   => 0,
						'max'   => 600,
					)
				);
				\Edit_Orders_For_WooCommerce\AdminUI\Fields::section_end();
			}
		);

		add_action(
			'edit_orders_for_woocommerce_settings_save',
			static function ( $post ) {
				if ( isset( $post['edit_orders_test_fake_pro_value'] ) ) {
					update_option( 'edit_orders_test_fake_pro_value', absint( $post['edit_orders_test_fake_pro_value'] ), false );
				}
			}
		);

		add_filter(
			'edit_orders_for_woocommerce_page_links',
			static function ( $links ) {
				$links['support']  = 'https://example.com/helpdesk';
				$links['pro_docs'] = 'https://example.com/pro-docs';
				return $links;
			}
		);
	}
);
