<?php
/**
 * The "Open for changes" hold (spec 1.4, section 1): while a customer can still change
 * a paid order, it waits in its own status instead of Processing, then moves to
 * Processing once when the time for changes ends.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Edit_Orders_For_WooCommerce_Hold Class.
 *
 * Off by default. An order enters the hold only at payment (inside payment_complete()
 * or the cash on delivery checkout), never when a store owner moves it to Processing.
 * Every way out goes through release(), which moves the order to Processing, notes why,
 * logs it and fires edit_orders_for_woocommerce_hold_released.
 */
class Edit_Orders_For_WooCommerce_Hold {

	/**
	 * The status, without the wc- prefix (19 characters; WooCommerce allows 20).
	 */
	const STATUS = 'open-for-changes';

	/**
	 * Action Scheduler hook that releases an order when its time for changes ends.
	 */
	const ACTION = 'edit_orders_for_woocommerce_release_hold';

	/**
	 * Action Scheduler group.
	 */
	const GROUP = 'edit-orders-for-woocommerce';

	/**
	 * The admin-post action behind "Release now".
	 */
	const RELEASE_NOW = 'edit_orders_for_woocommerce_release_now';

	/**
	 * Capability for "Release now": the one for editing orders (as Edit_Orders_For_WooCommerce_Admin::CAPABILITY).
	 */
	const CAPABILITY = 'edit_shop_orders';

	/**
	 * Orders released per round when everything held is released at once.
	 */
	const BATCH = 50;

	/**
	 * Most rounds of BATCH a release of everything runs, so it can never loop.
	 */
	const MAX_ROUNDS = 200;

	/**
	 * Most orders the safety sweep releases per request.
	 */
	const SWEEP_LIMIT = 20;

	/**
	 * Transient that spaces safety sweeps a minute apart.
	 */
	const SWEEP_TRANSIENT = 'edit_orders_for_woocommerce_hold_sweep';

	/**
	 * Orders inside payment_complete() right now, by ID, that may enter the hold.
	 *
	 * @var bool[]
	 */
	private static $paying = array();

	/**
	 * Orders being released on this request, by ID, so a save inside the release
	 * doesn't start a second one.
	 *
	 * @var bool[]
	 */
	private static $releasing = array();

	/**
	 * Hook in. The status is always registered, so an order left in it stays visible
	 * and editable even with the hold off.
	 */
	public static function init() {
		add_filter( 'woocommerce_register_shop_order_post_statuses', array( __CLASS__, 'register_post_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'order_statuses' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'add_status' ) );
		add_filter( 'woocommerce_reports_order_statuses', array( __CLASS__, 'reports_statuses' ) );
		add_filter( 'woocommerce_order_is_download_permitted', array( __CLASS__, 'download_permitted' ), 10, 2 );

		// Entry: only at payment.
		add_action( 'woocommerce_pre_payment_complete', array( __CLASS__, 'start_payment' ) );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'end_payment' ) );
		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'payment_complete_status' ), 20, 3 );
		add_filter( 'woocommerce_cod_process_payment_order_status', array( __CLASS__, 'cod_status' ), 20, 2 );
		add_action( 'woocommerce_order_status_' . self::STATUS, array( __CLASS__, 'entered' ), 10, 2 );

		// What WooCommerce does at Processing for its own records happens at payment for a
		// held order too (each is done once per order). Integrations wait for the release.
		foreach ( array( 'wc_maybe_reduce_stock_levels', 'wc_downloadable_product_permissions', 'wc_update_total_sales_counts', 'wc_update_coupon_usage_counts' ) as $callback ) {
			add_action( 'woocommerce_order_status_' . self::STATUS, $callback );
		}
		add_action( 'woocommerce_order_status_' . self::STATUS, 'wc_release_stock_for_order', 11 );

		// The customer and store emails sent at payment, as for Processing.
		add_filter( 'woocommerce_email_actions', array( __CLASS__, 'email_actions' ) );
		add_action( 'woocommerce_email', array( __CLASS__, 'hook_emails' ) );

		// Ways out.
		add_action( self::ACTION, array( __CLASS__, 'release_scheduled' ) );
		add_action( 'edit_orders_for_woocommerce_window_closed', array( __CLASS__, 'release_closed' ) );
		add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'release_shipped' ) );
		add_action( 'admin_post_' . self::RELEASE_NOW, array( __CLASS__, 'handle_release_now' ) );
		add_action( 'update_option_' . Edit_Orders_For_WooCommerce_Settings::OPTION, array( __CLASS__, 'settings_saved' ), 10, 2 );
	}

	/**
	 * Is the hold on? It needs customer changes on too.
	 *
	 * @return bool
	 */
	public static function is_on() {
		return Edit_Orders_For_WooCommerce_Settings::is_on( 'hold_enabled' ) && Edit_Orders_For_WooCommerce_Settings::is_on( 'customer_enabled' );
	}

	/**
	 * Is the order held?
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_held( WC_Order $order ) {
		return $order->has_status( self::STATUS );
	}

	/**
	 * The status name.
	 *
	 * @return string
	 */
	public static function label() {
		return _x( 'Open for changes', 'Order status', 'wpheka-edit-orders-for-woocommerce' );
	}

	/**
	 * Register the status for the posts storage.
	 *
	 * @param array $statuses Status => register_post_status() arguments.
	 * @return array
	 */
	public static function register_post_status( $statuses ) {
		$statuses = is_array( $statuses ) ? $statuses : array();

		$statuses[ 'wc-' . self::STATUS ] = array(
			'label'                     => self::label(),
			'public'                    => false,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			/* translators: %s: number of orders. */
			'label_count'               => _n_noop( 'Open for changes <span class="count">(%s)</span>', 'Open for changes <span class="count">(%s)</span>', 'wpheka-edit-orders-for-woocommerce' ),
		);

		return $statuses;
	}

	/**
	 * Add the status to WooCommerce's list (both storages), after Processing.
	 *
	 * @param array $statuses wc-status => label.
	 * @return array
	 */
	public static function order_statuses( $statuses ) {
		if ( ! is_array( $statuses ) ) {
			return $statuses;
		}

		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				$out[ 'wc-' . self::STATUS ] = self::label();
			}
		}
		if ( ! isset( $out[ 'wc-' . self::STATUS ] ) ) {
			$out[ 'wc-' . self::STATUS ] = self::label();
		}

		return $out;
	}

	/**
	 * Count the status as paid (is_paid(), Analytics).
	 *
	 * @param string[] $statuses Statuses without the prefix.
	 * @return string[]
	 */
	public static function add_status( $statuses ) {
		$statuses   = is_array( $statuses ) ? $statuses : array();
		$statuses[] = self::STATUS;

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Count held orders in WooCommerce's legacy reports.
	 *
	 * @param string[]|false $statuses Statuses.
	 * @return string[]|false
	 */
	public static function reports_statuses( $statuses ) {
		return is_array( $statuses ) ? self::add_status( $statuses ) : $statuses;
	}

	/**
	 * Downloads: what WooCommerce allows for a Processing order.
	 *
	 * @param bool     $permitted Permitted.
	 * @param WC_Order $order     Order.
	 * @return bool
	 */
	public static function download_permitted( $permitted, $order ) {
		if ( $order instanceof WC_Order && self::is_held( $order ) ) {
			return 'yes' === get_option( 'woocommerce_downloads_grant_access_after_payment' );
		}

		return $permitted;
	}

	/**
	 * Mark an order as being paid through payment_complete(). Orders already On hold never enter the hold.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function start_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && $order->has_status( array( 'pending', 'failed', 'cancelled' ) ) ) {
			self::$paying[ (int) $order_id ] = true;
		}
	}

	/**
	 * The payment through payment_complete() has finished.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function end_payment( $order_id ) {
		unset( self::$paying[ (int) $order_id ] );
	}

	/**
	 * The status after payment: Open for changes instead of Processing, for orders that
	 * qualify. Only inside payment_complete(): WooCommerce asks the same filter when any
	 * order reaches Processing (to set the paid date), and those must stay as they are.
	 *
	 * @param string        $status   Status WooCommerce would set.
	 * @param int           $order_id Order ID.
	 * @param WC_Order|bool $order    Order.
	 * @return string
	 */
	public static function payment_complete_status( $status, $order_id = 0, $order = false ) {
		if ( 'processing' !== $status || empty( self::$paying[ (int) $order_id ] ) || ! $order instanceof WC_Order ) {
			return $status;
		}

		return self::qualifies( $order ) ? self::STATUS : $status;
	}

	/**
	 * Cash on delivery: the status set at checkout.
	 *
	 * @param string   $status Status.
	 * @param WC_Order $order  Order.
	 * @return string
	 */
	public static function cod_status( $status, $order = null ) {
		if ( 'processing' !== $status || ! $order instanceof WC_Order ) {
			return $status;
		}

		return self::qualifies( $order ) ? self::STATUS : $status;
	}

	/**
	 * Does a paid order enter the hold?
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function qualifies( WC_Order $order ) {
		$hold = self::is_on()
			&& ! Edit_Orders_For_WooCommerce_Balance_Orders::is_balance_order( $order )
			&& 'admin' !== $order->get_created_via()
			&& ! self::is_subscription_order( $order )
			&& ! Edit_Orders_For_WooCommerce_Customer_Rules::is_excluded( $order );

		/**
		 * Whether a paid order waits as Open for changes instead of going to Processing.
		 *
		 * Only asked at payment, for orders that would go to Processing.
		 *
		 * @since 1.4.0
		 *
		 * @param bool     $hold  Hold the order.
		 * @param WC_Order $order Order.
		 */
		return (bool) apply_filters( 'edit_orders_for_woocommerce_should_hold', $hold, $order );
	}

	/**
	 * Is it a WooCommerce Subscriptions renewal, switch or resubscribe order?
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function is_subscription_order( WC_Order $order ) {
		foreach ( array( 'wcs_order_contains_renewal', 'wcs_order_contains_switch', 'wcs_order_contains_resubscribe' ) as $check ) {
			if ( function_exists( $check ) && call_user_func( $check, $order ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An order entered the status: note, log, schedule the release.
	 *
	 * @param int           $order_id Order ID.
	 * @param WC_Order|null $order    Order.
	 */
	public static function entered( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$release_at = Edit_Orders_For_WooCommerce_Customer_Rules::window_end( $order );
		$release_at = $release_at ? $release_at : time();

		/* translators: %s: time, for example 3:40 pm. */
		$order->add_order_note( sprintf( __( 'Open for changes until %s. The order moves to Processing after that.', 'wpheka-edit-orders-for-woocommerce' ), self::close_time( $release_at ) ) );

		Edit_Orders_For_WooCommerce_Audit_Log::add(
			array(
				'order_id'   => $order->get_id(),
				'actor_type' => 'system',
				'actor_id'   => 0,
				'action'     => 'hold_started',
				'after'      => array( 'release_at' => $release_at ),
			)
		);

		self::schedule( $order->get_id(), $release_at );
		Edit_Orders_For_WooCommerce::forget_open_orders();

		/**
		 * Fired when a paid order starts waiting as Open for changes.
		 *
		 * @since 1.4.0
		 *
		 * @param WC_Order $order      Order.
		 * @param int      $release_at When it moves to Processing, unless released earlier.
		 */
		do_action( 'edit_orders_for_woocommerce_hold_started', $order, $release_at );
	}

	/**
	 * Schedule the release, replacing any earlier one for the order.
	 *
	 * @param int $order_id Order ID.
	 * @param int $time     Timestamp.
	 */
	public static function schedule( $order_id, $time ) {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		self::unschedule( $order_id );
		as_schedule_single_action( max( time(), (int) $time ), self::ACTION, array( (int) $order_id ), self::GROUP );
	}

	/**
	 * Remove an order's scheduled release.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function unschedule( $order_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION, array( (int) $order_id ), self::GROUP );
		}
	}

	/**
	 * The scheduled release. If the time for changes was moved later (filter
	 * edit_orders_for_woocommerce_window_end), it waits for the new time instead.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function release_scheduled( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! self::is_held( $order ) ) {
			return;
		}

		$left = Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $order );
		if ( $left > 0 && ! $order->get_meta( Edit_Orders_For_WooCommerce_Customer_Rules::WINDOW_CLOSED_META ) ) {
			// The time was moved later: wait for it. Any other pending release for the order goes
			// (the one running now isn't pending, so it isn't touched).
			self::schedule( (int) $order_id, time() + $left );
			return;
		}

		self::release( $order, 'window_end' );
	}

	/**
	 * The customer said "All good, no changes needed".
	 *
	 * @param WC_Order $order Order.
	 */
	public static function release_closed( $order ) {
		if ( $order instanceof WC_Order && self::is_held( $order ) ) {
			self::release( $order, 'customer_closed' );
		}
	}

	/**
	 * A tracking number or a fulfilment was saved on a held order.
	 *
	 * @param WC_Order $order Order just saved.
	 */
	public static function release_shipped( $order ) {
		if ( ! $order instanceof WC_Order || ! $order->get_id() || ! empty( self::$releasing[ $order->get_id() ] ) || ! self::is_held( $order ) ) {
			return;
		}

		if ( Edit_Orders_For_WooCommerce_Customer_Rules::is_shipped( $order ) ) {
			self::release( $order, 'shipped' );
		}
	}

	/**
	 * "Release now" in the Edit order box.
	 */
	public static function handle_release_now() {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		check_admin_referer( self::RELEASE_NOW . '_' . $order_id );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change this order.', 'wpheka-edit-orders-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order && self::is_held( $order ) ) {
			self::release( $order, 'staff' );
		}

		wp_safe_redirect( $order instanceof WC_Order ? $order->get_edit_order_url() : admin_url() );
		exit;
	}

	/**
	 * The "Release now" link for an order.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function release_now_url( WC_Order $order ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::RELEASE_NOW,
					'order_id' => $order->get_id(),
				),
				admin_url( 'admin-post.php' )
			),
			self::RELEASE_NOW . '_' . $order->get_id()
		);
	}

	/**
	 * Move a held order to Processing.
	 *
	 * @param WC_Order $order  Order.
	 * @param string   $reason window_end, customer_closed, shipped, staff, setting_off, deactivated or sweep.
	 * @return bool Released.
	 */
	public static function release( WC_Order $order, $reason ) {
		$order_id = $order->get_id();
		if ( ! $order_id || ! empty( self::$releasing[ $order_id ] ) || ! self::is_held( $order ) ) {
			return false;
		}

		self::$releasing[ $order_id ] = true;
		// The gateways' filters keep a cash on delivery order without a paid date when it
		// goes to Processing. Releases run from scheduled actions, admin-post and
		// deactivation, where they may not be loaded yet.
		WC()->payment_gateways();
		$order->update_status( 'processing', self::release_note( $reason ) );
		unset( self::$releasing[ $order_id ] );

		if ( ! $order->has_status( 'processing' ) ) {
			return false;
		}

		$actor = 'system';
		if ( 'staff' === $reason ) {
			$actor = 'staff';
		} elseif ( 'customer_closed' === $reason ) {
			$actor = $order->get_customer_id() ? 'customer' : 'guest';
		}

		Edit_Orders_For_WooCommerce_Audit_Log::add(
			array(
				'order_id'   => $order_id,
				'actor_type' => $actor,
				'actor_id'   => 'system' === $actor ? 0 : ( 'staff' === $actor ? get_current_user_id() : $order->get_customer_id() ),
				'action'     => 'hold_released',
				'after'      => array( 'reason' => $reason ),
			)
		);

		self::unschedule( $order_id );
		Edit_Orders_For_WooCommerce::forget_open_orders();

		/**
		 * Fired when a held order moves to Processing.
		 *
		 * @since 1.4.0
		 *
		 * @param WC_Order $order  Order.
		 * @param string   $reason window_end, customer_closed, shipped, staff, setting_off, deactivated or sweep.
		 */
		do_action( 'edit_orders_for_woocommerce_hold_released', $order, $reason );

		return true;
	}

	/**
	 * The order note for a release.
	 *
	 * @param string $reason Reason.
	 * @return string
	 */
	private static function release_note( $reason ) {
		switch ( $reason ) {
			case 'customer_closed':
				return __( 'Released to Processing: the customer confirmed the order as it is.', 'wpheka-edit-orders-for-woocommerce' );
			case 'shipped':
				return __( 'Released to Processing: a tracking number or fulfilment was added.', 'wpheka-edit-orders-for-woocommerce' );
			case 'staff':
				$user = wp_get_current_user();
				/* translators: %s: name of the user. */
				return $user && $user->exists() ? sprintf( __( 'Released to Processing by %s.', 'wpheka-edit-orders-for-woocommerce' ), $user->display_name ) : __( 'Released to Processing by the store.', 'wpheka-edit-orders-for-woocommerce' );
			case 'setting_off':
				return __( 'Released to Processing: the hold was turned off.', 'wpheka-edit-orders-for-woocommerce' );
			case 'deactivated':
				return __( 'Released to Processing: Edit Orders for WooCommerce was deactivated.', 'wpheka-edit-orders-for-woocommerce' );
		}

		return __( 'Released to Processing: the time for changes ended.', 'wpheka-edit-orders-for-woocommerce' );
	}

	/**
	 * Release held orders whose time has passed, for sites where scheduled actions
	 * don't run (cron off on a staging copy). At most SWEEP_LIMIT, oldest first, and
	 * no more than once a minute unless forced.
	 *
	 * @param bool $force Skip the once-a-minute limit.
	 * @return int Orders released.
	 */
	public static function sweep( $force = false ) {
		if ( ! $force ) {
			if ( get_transient( self::SWEEP_TRANSIENT ) ) {
				return 0;
			}
			set_transient( self::SWEEP_TRANSIENT, 1, MINUTE_IN_SECONDS );
		}

		$ids = wc_get_orders(
			array(
				'type'    => 'shop_order',
				'status'  => array( 'wc-' . self::STATUS ),
				'limit'   => self::SWEEP_LIMIT,
				'orderby' => 'date',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		$released = 0;
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof WC_Order && 0 === Edit_Orders_For_WooCommerce_Customer_Rules::seconds_left( $order ) && self::release( $order, 'sweep' ) ) {
				++$released;
			}
		}

		return $released;
	}

	/**
	 * Release every held order, BATCH at a time, for at most MAX_ROUNDS rounds.
	 *
	 * @param string $reason setting_off or deactivated.
	 * @return int Orders released.
	 */
	public static function release_all( $reason ) {
		$released = 0;

		for ( $round = 0; $round < self::MAX_ROUNDS; $round++ ) {
			$ids = wc_get_orders(
				array(
					'type'   => 'shop_order',
					'status' => array( 'wc-' . self::STATUS ),
					'limit'  => self::BATCH,
					'return' => 'ids',
				)
			);
			if ( ! $ids ) {
				break;
			}

			$this_round = 0;
			foreach ( $ids as $id ) {
				$order = wc_get_order( $id );
				if ( $order instanceof WC_Order && self::release( $order, $reason ) ) {
					++$this_round;
				}
			}
			$released += $this_round;

			// Nothing moved: the same orders would come back every round.
			if ( ! $this_round ) {
				break;
			}
		}

		return $released;
	}

	/**
	 * The hold was turned off (or customer changes were): release every held order now.
	 *
	 * @param mixed $before Settings before.
	 * @param mixed $after  Settings after.
	 */
	public static function settings_saved( $before, $after ) {
		$on = static function ( $settings ) {
			$settings = wp_parse_args( is_array( $settings ) ? $settings : array(), Edit_Orders_For_WooCommerce_Settings::defaults() );

			return 'yes' === $settings['hold_enabled'] && 'yes' === $settings['customer_enabled'];
		};

		if ( $on( $before ) && ! $on( $after ) ) {
			self::release_all( 'setting_off' );
		}
	}

	/**
	 * Deactivation: release every held order and remove the scheduled releases. Data is kept.
	 */
	public static function deactivate() {
		self::release_all( 'deactivated' );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION );
		}
	}

	/**
	 * Add the status transitions at payment to WooCommerce's email actions.
	 *
	 * @param string[] $actions Actions.
	 * @return string[]
	 */
	public static function email_actions( $actions ) {
		$actions = is_array( $actions ) ? $actions : array();
		foreach ( self::entry_transitions() as $transition ) {
			$actions[] = $transition;
		}

		return array_values( array_unique( $actions ) );
	}

	/**
	 * Send WooCommerce's "Processing order" and "New order" emails when an order enters
	 * the hold at payment, as they are sent when it enters Processing. Nothing is sent at
	 * the release: Open for changes to Processing is not one of their transitions.
	 *
	 * @param WC_Emails $mailer WooCommerce's mailer.
	 */
	public static function hook_emails( $mailer ) {
		if ( ! is_object( $mailer ) || empty( $mailer->emails ) ) {
			return;
		}

		foreach ( array( 'WC_Email_Customer_Processing_Order', 'WC_Email_New_Order' ) as $class ) {
			if ( empty( $mailer->emails[ $class ] ) ) {
				continue;
			}
			foreach ( self::entry_transitions() as $transition ) {
				add_action( $transition . '_notification', array( $mailer->emails[ $class ], 'trigger' ), 10, 2 );
			}
		}
	}

	/**
	 * The transitions an order makes when it enters the hold at payment.
	 *
	 * @return string[]
	 */
	private static function entry_transitions() {
		$transitions = array();
		foreach ( array( 'pending', 'failed', 'cancelled' ) as $from ) {
			$transitions[] = 'woocommerce_order_status_' . $from . '_to_' . self::STATUS;
		}

		return $transitions;
	}

	/**
	 * A close time in words: "3:40 pm" today, otherwise with the date.
	 *
	 * @param int $timestamp Timestamp.
	 * @return string
	 */
	public static function close_time( $timestamp ) {
		$format = wp_date( 'Y-m-d', $timestamp ) === wp_date( 'Y-m-d' ) ? get_option( 'time_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return wp_date( $format, $timestamp );
	}
}
