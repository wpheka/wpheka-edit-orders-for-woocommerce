<?php
/**
 * Plugin settings with defaults (spec section 6). The settings screen arrives in M3.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Settings Class.
 *
 * Defaults are conservative, so a fresh install cannot move money a store owner
 * didn't expect: cancellations wait for approval and the window is one hour.
 */
class Edit_Orders_For_WooCommerce_Settings {

	/**
	 * Option holding the settings.
	 */
	const OPTION = 'edit_orders_for_woocommerce_settings';

	/**
	 * Option holding the first plugin version that ran on this site.
	 */
	const INSTALLED_OPTION = 'edit_orders_for_woocommerce_installed_version';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'admin_enabled'           => 'yes',
			'editable_statuses'       => array( 'processing', 'on-hold' ),
			'customer_enabled'        => 'yes',
			'window_minutes'          => 60,
			'action_cancel'           => 'yes',
			'action_address'          => 'yes',
			'action_address_shipping' => 'yes',
			'action_address_billing'  => 'yes',
			'action_note'             => 'yes',
			'action_swap'             => 'yes',
			'cancel_mode'             => 'approval',
			'cancel_reasons'          => implode(
				"\n",
				array(
					__( 'Ordered by mistake', 'wpheka-edit-orders-for-woocommerce' ),
					__( 'Found a better price', 'wpheka-edit-orders-for-woocommerce' ),
					__( 'Delivery takes too long', 'wpheka-edit-orders-for-woocommerce' ),
					__( 'Need to change the order', 'wpheka-edit-orders-for-woocommerce' ),
				)
			),
			'cancel_reason_required'  => 'no',
			'cancel_policy'           => '',
			'note_max_length'         => 500,
			'excluded_products'       => array(),
			'excluded_categories'     => array(),
			'debug_log'               => 'no',
		);
	}

	/**
	 * One setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$settings = self::all();

		/**
		 * Filter a setting.
		 *
		 * @since 1.0.0
		 *
		 * @param mixed  $value Value.
		 * @param string $key   Setting key.
		 */
		return apply_filters( 'edit_orders_for_woocommerce_setting', isset( $settings[ $key ] ) ? $settings[ $key ] : null, $key );
	}

	/**
	 * Every setting with defaults filled in.
	 *
	 * Before 1.3.0 one setting, `action_address`, covered every address change. The two
	 * switches that replaced it start from its saved value, so a store that had turned
	 * address changes off keeps them off after the update. `action_address` itself now
	 * reads as on when either switch is on, for code that still asks for it.
	 *
	 * Customers could only change the shipping address before 1.3.0, so a store that
	 * ran an earlier version keeps billing changes off until it turns them on.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = (array) get_option( self::OPTION, array() );

		if ( ! isset( $stored['action_address_billing'] ) && version_compare( (string) get_option( self::INSTALLED_OPTION, EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION ), '1.3.0', '<' ) ) {
			$stored['action_address_billing'] = 'no';
		}

		foreach ( array( 'action_address_shipping', 'action_address_billing' ) as $key ) {
			if ( ! isset( $stored[ $key ] ) && isset( $stored['action_address'] ) ) {
				$stored[ $key ] = $stored['action_address'];
			}
		}

		$settings                   = wp_parse_args( $stored, self::defaults() );
		$settings['action_address'] = 'yes' === $settings['action_address_shipping'] || 'yes' === $settings['action_address_billing'] ? 'yes' : 'no';

		return $settings;
	}

	/**
	 * Remember the first version that ran on this site, once.
	 *
	 * Every release before 1.3.0 created the audit log table on its first run, so a site
	 * with the table's version option but without this one ran an earlier release.
	 *
	 * @return void
	 */
	public static function remember_installed_version() {
		if ( false !== get_option( self::INSTALLED_OPTION ) ) {
			return;
		}

		$earlier = false !== get_option( Edit_Orders_For_WooCommerce_Audit_Log::DB_VERSION_OPTION );
		add_option( self::INSTALLED_OPTION, $earlier ? '1.2.1' : EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION );
	}

	/**
	 * Is a yes/no setting on?
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}

	/**
	 * The edit window in seconds, clamped to 5 minutes to 7 days.
	 *
	 * @return int
	 */
	public static function window_seconds() {
		$minutes = (int) self::get( 'window_minutes' );

		return min( 7 * DAY_IN_SECONDS, max( 5 * MINUTE_IN_SECONDS, $minutes * MINUTE_IN_SECONDS ) );
	}

	/**
	 * Cancel reasons, one per line. The panel always adds "Other" after them.
	 *
	 * @return string[]
	 */
	public static function cancel_reasons() {
		$reasons = array_filter( array_map( 'trim', explode( "\n", (string) self::get( 'cancel_reasons' ) ) ) );

		return array_values( $reasons );
	}

	/**
	 * Product IDs customers can never change orders for.
	 *
	 * @return int[]
	 */
	public static function excluded_products() {
		return array_values( array_filter( array_map( 'absint', (array) self::get( 'excluded_products' ) ) ) );
	}

	/**
	 * Category IDs customers can never change orders for, with their child categories.
	 *
	 * @return int[]
	 */
	public static function excluded_categories() {
		$categories = array_values( array_filter( array_map( 'absint', (array) self::get( 'excluded_categories' ) ) ) );

		foreach ( $categories as $category ) {
			$children = get_term_children( $category, 'product_cat' );
			if ( is_array( $children ) ) {
				$categories = array_merge( $categories, array_map( 'absint', $children ) );
			}
		}

		return array_values( array_unique( $categories ) );
	}
}
