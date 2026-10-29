<?php
/**
 * "Customer can still change" on the Orders screen: a badge with the time left,
 * a view that lists only those orders, and a note on the order screen (spec 1.3, section 1).
 *
 * Display only: nothing here changes an order's status, totals, stock or emails.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Orders_List Class.
 *
 * Every answer comes from Edit_Orders_For_WooCommerce_Customer_Rules::open_actions(),
 * the check the My Account panel uses, so the badge, the view and My Account agree.
 */
class Edit_Orders_For_WooCommerce_Orders_List {

	/**
	 * Query argument that selects the view.
	 */
	const VIEW_ARG = 'eofw_open';

	/**
	 * The column key.
	 */
	const COLUMN = 'edit_orders_window';

	/**
	 * Order IDs open for customer changes, worked out once per request.
	 *
	 * @var int[]|null
	 */
	private static $open_ids = null;

	/**
	 * Hook into the HPOS orders list and the legacy posts list.
	 */
	public static function init() {
		$hpos_screen = 'woocommerce_page_wc-orders';

		add_filter( 'manage_' . $hpos_screen . '_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_' . $hpos_screen . '_custom_column', array( __CLASS__, 'render_hpos_column' ), 10, 2 );
		add_filter( 'views_' . $hpos_screen, array( __CLASS__, 'add_view' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'filter_hpos_query' ) );

		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_legacy_column' ), 20, 2 );
		add_filter( 'views_edit-shop_order', array( __CLASS__, 'add_view' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_legacy_query' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'update_option_' . Edit_Orders_For_WooCommerce_Settings::OPTION, array( __CLASS__, 'reset' ) );
		add_action( 'current_screen', array( __CLASS__, 'sweep' ) );
	}

	/**
	 * Opening the Orders screen releases held orders whose time has passed, in case
	 * scheduled actions don't run on this site.
	 *
	 * @param WP_Screen $screen Screen.
	 */
	public static function sweep( $screen ) {
		if ( $screen instanceof WP_Screen && in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order' ), true ) ) {
			Edit_Orders_For_WooCommerce_Hold::sweep();
		}
	}

	/**
	 * Is customer changing turned on at all? When it is off there is nothing to show.
	 *
	 * @return bool
	 */
	private static function active() {
		return Edit_Orders_For_WooCommerce_Settings::is_on( 'customer_enabled' ) && Edit_Orders_For_WooCommerce_Customer_Rules::enabled_actions();
	}

	/**
	 * Which actor would act on this order: its account, or a guest with the order key.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	private static function actor( WC_Order $order ) {
		return $order->get_customer_id() ? 'customer' : 'guest';
	}

	/**
	 * Can the customer still change this order? Same answer as the My Account panel.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_open( WC_Order $order ) {
		if ( Edit_Orders_For_WooCommerce_Balance_Orders::is_balance_order( $order ) ) {
			return false;
		}

		return (bool) Edit_Orders_For_WooCommerce_Customer_Rules::open_actions( $order, self::actor( $order ) );
	}

	/**
	 * Orders customers can still change, newest first.
	 *
	 * Only orders paid (or, for cash on delivery, placed) within the window can be open,
	 * so the database is asked for those first, by date. WooCommerce stores the paid
	 * date for every order already, so orders from before 1.3.0 are included too. The
	 * few that come back are then checked one by one with the panel's own rules.
	 *
	 * A busy store with a long window can have thousands of those, and the view's count
	 * is shown on every Orders screen, so the answer is kept for a minute. The view
	 * itself always works it out again, so the orders it lists are exact.
	 *
	 * @return int[]
	 */
	public static function open_order_ids() {
		if ( null !== self::$open_ids ) {
			return self::$open_ids;
		}

		self::$open_ids = array();
		if ( ! self::active() ) {
			return self::$open_ids;
		}

		$cached = self::view_selected() ? false : get_transient( Edit_Orders_For_WooCommerce::OPEN_ORDERS_CACHE );
		if ( is_array( $cached ) ) {
			self::$open_ids = array_map( 'absint', $cached );
			return self::$open_ids;
		}

		$since  = time() - Edit_Orders_For_WooCommerce_Customer_Rules::window_max_seconds();
		$common = array(
			'type'    => 'shop_order',
			'status'  => self::statuses(),
			'limit'   => -1,
			'return'  => 'ids',
			'orderby' => 'date',
			'order'   => 'DESC',
		);

		$paid = wc_get_orders( array_merge( $common, array( 'date_paid' => '>' . $since ) ) );

		/** This filter is documented in includes/engine/class-edit-orders-for-woocommerce-eligibility.php */
		$on_delivery = (array) apply_filters( 'edit_orders_for_woocommerce_pay_on_delivery_gateways', array( 'cod' ) );
		$placed      = $on_delivery ? wc_get_orders(
			array_merge(
				$common,
				array(
					'date_created'   => '>' . $since,
					'payment_method' => $on_delivery,
				)
			)
		) : array();

		$ids = array_unique( array_map( 'absint', array_merge( (array) $paid, (array) $placed ) ) );
		rsort( $ids );

		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order && self::is_open( $order ) ) {
				self::$open_ids[] = $id;
			}
		}
		set_transient( Edit_Orders_For_WooCommerce::OPEN_ORDERS_CACHE, self::$open_ids, MINUTE_IN_SECONDS );

		return self::$open_ids;
	}

	/**
	 * Editable statuses as stored on orders.
	 *
	 * @return string[]
	 */
	private static function statuses() {
		return array_map(
			static function ( $status ) {
				return 'wc-' . $status;
			},
			Edit_Orders_For_WooCommerce_Eligibility::editable_statuses()
		);
	}

	/**
	 * Forget the open orders worked out so far: settings changed, or tests changed orders.
	 */
	public static function reset() {
		self::$open_ids = null;
		Edit_Orders_For_WooCommerce::forget_open_orders();
	}

	/**
	 * Is the view selected on this request?
	 *
	 * @return bool
	 */
	private static function view_selected() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list view selector, read only.
		return isset( $_GET[ self::VIEW_ARG ] ) && '1' === sanitize_key( wp_unslash( $_GET[ self::VIEW_ARG ] ) );
	}

	/**
	 * "2 h left", "35 min left" or "3 d left".
	 *
	 * @param int $seconds Seconds left.
	 * @return string
	 */
	public static function time_left( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < HOUR_IN_SECONDS ) {
			/* translators: %d: minutes. */
			return sprintf( __( '%d min left', 'wpheka-edit-orders-for-woocommerce' ), max( 1, (int) ceil( $seconds / MINUTE_IN_SECONDS ) ) );
		}
		if ( $seconds < DAY_IN_SECONDS ) {
			/* translators: %d: hours. */
			return sprintf( __( '%d h left', 'wpheka-edit-orders-for-woocommerce' ), (int) floor( $seconds / HOUR_IN_SECONDS ) );
		}

		/* translators: %d: days. */
		return sprintf( __( '%d d left', 'wpheka-edit-orders-for-woocommerce' ), (int) floor( $seconds / DAY_IN_SECONDS ) );
	}

	/**
	 * When the window closes, in words: "3:40 pm" today, otherwise with the date.
	 *
	 * @param int $timestamp Close time.
	 * @return string
	 */
	public static function close_time( $timestamp ) {
		$format = wp_date( 'Y-m-d', $timestamp ) === wp_date( 'Y-m-d' ) ? get_option( 'time_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return wp_date( $format, $timestamp );
	}

	/**
	 * The badge for an order, or an empty string when it is not open.
	 *
	 * @param WC_Order $order Order.
	 * @return string HTML.
	 */
	public static function badge( WC_Order $order ) {
		if ( ! self::active() || ! self::is_open( $order ) ) {
			return '';
		}

		$left = Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $order );
		/* translators: %s: time left, for example "2 h left". */
		$text = sprintf( __( 'Changes open, %s', 'wpheka-edit-orders-for-woocommerce' ), self::time_left( $left ) );
		/* translators: %s: time, for example 3:40 pm. */
		$title = sprintf( __( 'The customer can still change this order until %s.', 'wpheka-edit-orders-for-woocommerce' ), self::close_time( time() + $left ) );

		return '<mark class="eofw-open-badge" title="' . esc_attr( $title ) . '"><span>' . esc_html( $text ) . '</span></mark>';
	}

	/**
	 * Add the column after the status.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		if ( ! self::active() || ! is_array( $columns ) ) {
			return $columns;
		}

		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$out[ self::COLUMN ] = __( 'Changes', 'wpheka-edit-orders-for-woocommerce' );
			}
		}
		if ( ! isset( $out[ self::COLUMN ] ) ) {
			$out[ self::COLUMN ] = __( 'Changes', 'wpheka-edit-orders-for-woocommerce' );
		}

		return $out;
	}

	/**
	 * The column on the HPOS list.
	 *
	 * @param string   $column Column key.
	 * @param WC_Order $order  Order.
	 */
	public static function render_hpos_column( $column, $order ) {
		if ( self::COLUMN === $column && $order instanceof WC_Order ) {
			echo wp_kses_post( self::badge( $order ) );
		}
	}

	/**
	 * The column on the legacy posts list.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Order ID.
	 */
	public static function render_legacy_column( $column, $post_id ) {
		if ( self::COLUMN !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof WC_Order ) {
			echo wp_kses_post( self::badge( $order ) );
		}
	}

	/**
	 * The "Customer can still change" view link, after the status views.
	 *
	 * @param array $views View links.
	 * @return array
	 */
	public static function add_view( $views ) {
		if ( ! self::active() || ! is_array( $views ) ) {
			return $views;
		}

		$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		$url  = $hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
		$url  = add_query_arg( self::VIEW_ARG, '1', $url );

		// The view replaces the status views' selection while it is on.
		if ( self::view_selected() ) {
			foreach ( $views as $key => $link ) {
				$views[ $key ] = str_replace( array( ' class="current"', ' aria-current="page"' ), '', $link );
			}
		}

		$views[ self::VIEW_ARG ] = sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( $url ),
			self::view_selected() ? ' class="current" aria-current="page"' : '',
			esc_html__( 'Customer can still change', 'wpheka-edit-orders-for-woocommerce' ),
			esc_html( number_format_i18n( count( self::open_order_ids() ) ) )
		);

		return $views;
	}

	/**
	 * Limit the HPOS list to open orders while the view is selected.
	 *
	 * @param array $args Order query arguments.
	 * @return array
	 */
	public static function filter_hpos_query( $args ) {
		if ( ! self::view_selected() || ! is_array( $args ) ) {
			return $args;
		}

		$ids        = self::open_order_ids();
		$args['id'] = $ids ? $ids : array( 0 );

		return $args;
	}

	/**
	 * Limit the legacy list to open orders while the view is selected.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_legacy_query( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) || ! self::view_selected() ) {
			return;
		}

		$ids = self::open_order_ids();
		$query->set( 'post__in', $ids ? $ids : array( 0 ) );
		// The editable statuses by name: 'any' leaves out statuses hidden from search.
		$query->set( 'post_status', self::statuses() );
	}

	/**
	 * The note for the order screen's Edit order box, or an empty string.
	 *
	 * @param WC_Order $order Order.
	 * @return string HTML.
	 */
	public static function order_screen_note( WC_Order $order ) {
		if ( ! self::active() || ! self::is_open( $order ) ) {
			return '';
		}

		$close = time() + Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $order );

		/* translators: %s: time, for example 3:40 pm. */
		return '<p class="eofw-open-note">' . esc_html( sprintf( __( 'The customer can still change this order until %s.', 'wpheka-edit-orders-for-woocommerce' ), self::close_time( $close ) ) ) . '</p>';
	}

	/**
	 * The badge's style on the orders lists and the order screen.
	 */
	public static function enqueue() {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'edit-orders-for-woocommerce-orders-list', EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/css/orders-list.css', array(), EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION );
	}
}
