<?php
/**
 * WPHEKA > Edit Orders: the plugin's own page, with Settings, Activity and Help tabs.
 *
 * @package Edit_Orders_For_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

use Edit_Orders_For_WooCommerce\AdminUI\Menu;
use Edit_Orders_For_WooCommerce\AdminUI\Page;
use Edit_Orders_For_WooCommerce\AdminUI\Icons;

/**
 * Edit_Orders_For_WooCommerce_Admin_Page Class.
 *
 * Opening the page needs edit_shop_orders, as the Activity page always did. The
 * Settings tab and its save need manage_woocommerce, as WooCommerce's settings do,
 * so a shop manager without it sees Activity and Help only.
 */
class Edit_Orders_For_WooCommerce_Admin_Page {

	/**
	 * Page slug (the Activity page's slug before 1.2.0, so old links still work).
	 */
	const PAGE = 'edit-orders-for-woocommerce';

	/**
	 * The admin-post action that hides the getting-started card.
	 */
	const DISMISS_ACTION = 'edit_orders_for_woocommerce_dismiss_start';

	/**
	 * User meta that records the getting-started card was hidden.
	 */
	const DISMISS_META = 'edit_orders_for_woocommerce_start_dismissed';

	/**
	 * The wordpress.org slug.
	 */
	const SLUG = 'wpheka-edit-orders-for-woocommerce';

	/**
	 * The page hook WordPress returned, for loading assets on this page only.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  settings, activity or help.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function url( $tab = 'settings', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- short query args.
	}

	/**
	 * Register the page under the shared WPHEKA menu, with the waiting-requests count.
	 * Staff who can edit orders but cannot open the WPHEKA parent still see the menu:
	 * WordPress points the parent at the first page they may open.
	 */
	public static function register_page() {
		$pending = Edit_Orders_For_WooCommerce_Cancellation::pending_count();
		$label   = __( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' );
		if ( $pending ) {
			$label .= ' <span class="awaiting-mod"><span class="pending-count">' . absint( $pending ) . '</span></span>';
		}

		$hook = Menu::add_page( __( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' ), $label, Edit_Orders_For_WooCommerce_Admin::CAPABILITY, self::PAGE, array( __CLASS__, 'render' ), Icons::menu_icon() );

		self::$hook = is_string( $hook ) ? $hook : '';
		if ( '' !== self::$hook ) {
			// Notices from WordPress and other plugins would print above the header and then jump into place.
			add_action( 'load-' . self::$hook, array( Page::class, 'quiet_notices' ) );
		}
	}

	/**
	 * Load the kit on this page only.
	 *
	 * @param string $hook Page hook.
	 */
	public static function enqueue( $hook ) {
		if ( '' === self::$hook || self::$hook !== $hook ) {
			return;
		}
		Page::enqueue( 'edit-orders-for-woocommerce', EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/wpheka-ui/' );
	}

	/**
	 * The page's tabs, in order.
	 *
	 * @return array[] Each { key, label, capability, count }.
	 */
	public static function tabs() {
		$tabs = array(
			array(
				'key'        => 'settings',
				'label'      => __( 'Settings', 'wpheka-edit-orders-for-woocommerce' ),
				'capability' => Edit_Orders_For_WooCommerce_Settings_Form::CAPABILITY,
				'count'      => 0,
			),
			array(
				'key'        => 'activity',
				'label'      => __( 'Activity', 'wpheka-edit-orders-for-woocommerce' ),
				'capability' => Edit_Orders_For_WooCommerce_Admin::CAPABILITY,
				'count'      => Edit_Orders_For_WooCommerce_Cancellation::pending_count(),
			),
			array(
				'key'        => 'help',
				'label'      => __( 'Help', 'wpheka-edit-orders-for-woocommerce' ),
				'capability' => Edit_Orders_For_WooCommerce_Admin::CAPABILITY,
				'count'      => 0,
			),
		);

		/**
		 * Tabs on WPHEKA > Edit Orders. A tab added here is printed by the action
		 * edit_orders_for_woocommerce_page_tab_{key}, and shown only to users with its capability.
		 *
		 * @since 1.4.0
		 *
		 * @param array[] $tabs Each { key, label, capability, count }.
		 */
		$tabs = (array) apply_filters( 'edit_orders_for_woocommerce_page_tabs', $tabs );

		$out = array();
		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) || empty( $tab['key'] ) ) {
				continue;
			}
			$key         = sanitize_key( $tab['key'] );
			$out[ $key ] = array(
				'key'        => $key,
				'label'      => isset( $tab['label'] ) ? (string) $tab['label'] : $key,
				'capability' => ! empty( $tab['capability'] ) ? (string) $tab['capability'] : Edit_Orders_For_WooCommerce_Admin::CAPABILITY,
				'count'      => isset( $tab['count'] ) ? absint( $tab['count'] ) : 0,
			);
		}

		return array_values( $out );
	}

	/**
	 * The tab being shown. An unknown tab opens Settings; a tab the user may not open
	 * (Settings needs manage_woocommerce) opens Activity.
	 *
	 * @return string
	 */
	public static function current_tab() {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab to show.
		$tabs = array_column( self::tabs(), 'capability', 'key' );
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}
		if ( ! isset( $tabs[ $tab ] ) || ! current_user_can( $tabs[ $tab ] ) ) {
			$tab = 'activity';
		}

		return $tab;
	}

	/**
	 * Save the settings form (admin-post).
	 */
	public static function handle_save() {
		check_admin_referer( Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION );
		if ( ! current_user_can( Edit_Orders_For_WooCommerce_Settings_Form::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'wpheka-edit-orders-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		Edit_Orders_For_WooCommerce_Settings_Form::save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitised in save().

		wp_safe_redirect( self::url( 'settings', array( 'w-saved' => 1 ) ) );
		exit;
	}

	/**
	 * Hide the getting-started card for this user (admin-post, also called by fetch()).
	 */
	public static function handle_dismiss() {
		check_admin_referer( self::DISMISS_ACTION );
		if ( current_user_can( Edit_Orders_For_WooCommerce_Admin::CAPABILITY ) ) {
			update_user_meta( get_current_user_id(), self::DISMISS_META, 1 );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * Links for the header and the help card.
	 *
	 * @return array
	 */
	public static function links() {
		$links = array(
			'docs'    => 'https://wordpress.org/plugins/' . self::SLUG . '/#faq',
			'support' => 'https://wordpress.org/support/plugin/' . self::SLUG . '/',
			'changes' => 'https://wordpress.org/plugins/' . self::SLUG . '/#developers',
			'review'  => 'https://wordpress.org/support/plugin/' . self::SLUG . '/reviews/#new-post',
		);

		/**
		 * Links in the page header, the help card and the Help tab: docs, support,
		 * changes and review. Keys added here are passed on to added tabs.
		 *
		 * @since 1.4.0
		 *
		 * @param string[] $links Key => URL.
		 */
		$filtered = (array) apply_filters( 'edit_orders_for_woocommerce_page_links', $links );

		// The four the page prints are always there.
		return array_merge( $links, array_filter( array_map( 'strval', $filtered ) ) );
	}

	/**
	 * The page.
	 */
	public static function render() {
		if ( ! current_user_can( Edit_Orders_For_WooCommerce_Admin::CAPABILITY ) ) {
			return;
		}

		$tab   = self::current_tab();
		$links = self::links();
		$tabs  = array();
		foreach ( self::tabs() as $item ) {
			if ( ! current_user_can( $item['capability'] ) ) {
				continue;
			}
			$tabs[] = 'activity' === $item['key'] || $item['count']
				? array( $item['label'], self::url( $item['key'] ), $tab === $item['key'], $item['count'], true )
				: array( $item['label'], self::url( $item['key'] ), $tab === $item['key'] );
		}

		Page::open(
			array(
				'icon'     => EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/wpheka-ui/icons/' . self::SLUG . '.png',
				'name'     => __( 'Edit Orders for WooCommerce', 'wpheka-edit-orders-for-woocommerce' ),
				'version'  => EDIT_ORDERS_FOR_WOOCOMMERCE_VERSION,
				'badges'   => array( array( __( 'Free', 'wpheka-edit-orders-for-woocommerce' ), 'mint' ) ),
				'subtitle' => __( 'Edit paid orders and settle the difference automatically.', 'wpheka-edit-orders-for-woocommerce' ),
				'crumb'    => __( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' ),
				'links'    => array(
					array( __( 'Docs', 'wpheka-edit-orders-for-woocommerce' ), $links['docs'], 'book' ),
					array( __( 'Support', 'wpheka-edit-orders-for-woocommerce' ), $links['support'], 'chat' ),
					array( __( "What's new", 'wpheka-edit-orders-for-woocommerce' ), $links['changes'], 'sparkle' ),
				),
				'cta'      => array( __( 'Rate on WordPress.org', 'wpheka-edit-orders-for-woocommerce' ), $links['review'], 'star', 'ghost' ),
				'tabs'     => $tabs,
			)
		);

		if ( in_array( $tab, array( 'settings', 'activity' ), true ) ) {
			self::render_stats();
		}

		if ( 'settings' === $tab ) {
			self::render_settings( $links );
		} elseif ( 'activity' === $tab ) {
			Edit_Orders_For_WooCommerce_Activity::render();
		} elseif ( 'help' === $tab ) {
			self::render_help( $links );
		} else {
			/**
			 * Print a tab added through edit_orders_for_woocommerce_page_tabs. Only runs for
			 * users with the tab's capability.
			 *
			 * @since 1.4.0
			 *
			 * @param array $links The page's links (see edit_orders_for_woocommerce_page_links).
			 */
			do_action( 'edit_orders_for_woocommerce_page_tab_' . $tab, $links );
		}

		Page::close(
			array(
				'slug'       => self::SLUG,
				'icons_url'  => EDIT_ORDERS_FOR_WOOCOMMERCE_URL . 'assets/wpheka-ui/icons/',
				'short_name' => __( 'Edit Orders', 'wpheka-edit-orders-for-woocommerce' ),
				'review_url' => $links['review'],
				'form'       => 'settings' === $tab ? 'edit-orders-settings' : '',
				'saved'      => 'settings' === $tab && isset( $_GET['w-saved'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display flag after our own redirect.
			)
		);
	}

	/**
	 * The stats strip (last 30 days).
	 */
	private static function render_stats() {
		$n = Edit_Orders_For_WooCommerce_Settings_Form::stats();

		Page::stats(
			array(
				array(
					'label' => __( 'Orders edited', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'pencil',
					'value' => number_format_i18n( $n['edited'] ),
					'meta'  => __( 'Last 30 days', 'wpheka-edit-orders-for-woocommerce' ),
				),
				array(
					'label' => __( 'Refunded', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'refund',
					'mint'  => true,
					'value' => wp_strip_all_tags( wc_price( $n['refunded'] ) ),
					/* translators: %s: number of refunds. */
					'meta'  => sprintf( _n( '%s refund, made automatically', '%s refunds, made automatically', $n['refunds'], 'wpheka-edit-orders-for-woocommerce' ), number_format_i18n( $n['refunds'] ) ),
				),
				array(
					'label' => __( 'Pay links', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'link',
					/* translators: %s: number of paid pay links. */
					'value' => number_format_i18n( $n['links'] ) . ' <small>' . esc_html( sprintf( __( '/ %s paid', 'wpheka-edit-orders-for-woocommerce' ), number_format_i18n( $n['links_paid'] ) ) ) . '</small>',
					/* translators: %s: amount collected. */
					'meta'  => sprintf( __( '%s collected', 'wpheka-edit-orders-for-woocommerce' ), wp_strip_all_tags( wc_price( $n['collected'] ) ) ),
					'up'    => $n['collected'] > 0,
				),
				array(
					'label' => __( 'Customer changes', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'user',
					'mint'  => true,
					'value' => number_format_i18n( $n['customer'] ),
					'meta'  => $n['closed_early']
						/* translators: %s: number of customers who confirmed their order as it was. */
						? sprintf( _n( 'Made by customers themselves. %s said all good.', 'Made by customers themselves. %s said all good.', $n['closed_early'], 'wpheka-edit-orders-for-woocommerce' ), number_format_i18n( $n['closed_early'] ) )
						: __( 'Made by customers themselves', 'wpheka-edit-orders-for-woocommerce' ),
				),
				array(
					'label' => __( 'Cancellations', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'xcircle',
					'value' => number_format_i18n( $n['cancelled'] ),
					'meta'  => sprintf(
						/* translators: 1: cancellations by customers, 2: cancellation requests declined. */
						__( '%1$s by customers, %2$s declined', 'wpheka-edit-orders-for-woocommerce' ),
						number_format_i18n( $n['cancelled_customer'] ),
						number_format_i18n( $n['cancel_declined'] )
					),
				),
				array(
					'label' => __( 'Open for changes now', 'wpheka-edit-orders-for-woocommerce' ),
					'icon'  => 'gauge',
					'mint'  => true,
					'value' => number_format_i18n( $n['open_now'] ),
					'meta'  => Edit_Orders_For_WooCommerce_Hold::is_on() || $n['held']
						/* translators: %s: number of orders held as Open for changes. */
						? sprintf( _n( '%s held', '%s held', $n['held'], 'wpheka-edit-orders-for-woocommerce' ), number_format_i18n( $n['held'] ) )
						: __( 'Customers can still change these', 'wpheka-edit-orders-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * The getting-started card, until every step is done or it is hidden.
	 */
	private static function render_start() {
		if ( get_user_meta( get_current_user_id(), self::DISMISS_META, true ) ) {
			return;
		}
		$steps = Edit_Orders_For_WooCommerce_Settings_Form::steps();
		$done  = count( wp_list_filter( $steps, array( 'done' => true ) ) );
		if ( count( $steps ) === $done ) {
			return;
		}
		$dash = round( 94.25 * $done / count( $steps ), 1 );

		echo '<div class="w-card w-start"><svg class="w-ring" viewBox="0 0 36 36" aria-hidden="true"><circle cx="18" cy="18" r="15" fill="none" stroke="#ede9fe" stroke-width="4"/>';
		echo '<circle cx="18" cy="18" r="15" fill="none" stroke="#7c3aed" stroke-width="4" stroke-dasharray="' . esc_attr( $dash . ' 94.25' ) . '" stroke-linecap="round" transform="rotate(-90 18 18)"/>';
		echo '<text x="18" y="21.5" text-anchor="middle" font-size="9" font-weight="700" fill="#0f172a">' . esc_html( $done . '/' . count( $steps ) ) . '</text></svg>';
		echo '<div><h3>' . esc_html__( 'Getting started', 'wpheka-edit-orders-for-woocommerce' ) . '</h3><ol class="w-steps">';
		foreach ( $steps as $step ) {
			echo '<li' . ( $step['done'] ? ' class="is-done"' : '' ) . '>' . esc_html( $step['label'] ) . '<span class="w-sr"> ' . esc_html( $step['done'] ? __( '(done)', 'wpheka-edit-orders-for-woocommerce' ) : __( '(to do)', 'wpheka-edit-orders-for-woocommerce' ) ) . '</span></li>';
		}
		echo '</ol></div><div class="w-start-actions">';
		$orders = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'admin.php?page=wc-orders&status=wc-processing' : 'edit.php?post_type=shop_order&post_status=wc-processing';
		echo '<a class="w-btn w-btn--secondary w-btn--sm" href="' . esc_url( admin_url( $orders ) ) . '">' . esc_html__( 'Open an order', 'wpheka-edit-orders-for-woocommerce' ) . '</a>';
		echo '<a class="w-close" data-w-dismiss=".w-start" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION ) ) . '" aria-label="' . esc_attr__( 'Hide getting started', 'wpheka-edit-orders-for-woocommerce' ) . '">' . Icons::get( 'xcircle' ) . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		echo '</div></div>';
	}

	/**
	 * The Settings tab.
	 *
	 * @param array $links Help links.
	 */
	private static function render_settings( array $links ) {
		echo '<div class="w-stack">';
		self::render_start();
		echo '<div class="w-layout"><div>';
		Page::nav( self::settings_nav() );
		echo '</div><form id="edit-orders-settings" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-w-form>';
		echo '<input type="hidden" name="action" value="' . esc_attr( Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION ) . '">';
		wp_nonce_field( Edit_Orders_For_WooCommerce_Settings_Form::SAVE_ACTION );
		Edit_Orders_For_WooCommerce_Settings_Form::render();
		// Without JavaScript the save bar never appears, so the form keeps a plain button.
		echo '<noscript><p><button type="submit" class="w-btn w-btn--primary">' . esc_html__( 'Save changes', 'wpheka-edit-orders-for-woocommerce' ) . '</button></p></noscript>';
		echo '</form><aside class="w-rail" aria-label="' . esc_attr__( 'Review and help', 'wpheka-edit-orders-for-woocommerce' ) . '">';
		Page::review_card( __( 'Enjoying Edit Orders?', 'wpheka-edit-orders-for-woocommerce' ), __( 'A quick review helps other store owners find it and keeps it improving.', 'wpheka-edit-orders-for-woocommerce' ), $links['review'] );
		Page::help_card(
			array(
				array( __( 'Read the documentation', 'wpheka-edit-orders-for-woocommerce' ), $links['docs'], 'book' ),
				array( __( 'Ask in the support forum', 'wpheka-edit-orders-for-woocommerce' ), $links['support'], 'chat' ),
			)
		);
		echo '</aside></div></div>';
	}

	/**
	 * The Settings tab's section links.
	 *
	 * @return array[] Each [ section id, icon, label ], as the kit's nav takes them.
	 */
	public static function settings_nav() {
		$nav = array(
			array(
				'id'    => 'editing',
				'icon'  => 'pencil',
				'label' => __( 'Order editing', 'wpheka-edit-orders-for-woocommerce' ),
			),
			array(
				'id'    => 'customers',
				'icon'  => 'users',
				'label' => __( 'Customer changes', 'wpheka-edit-orders-for-woocommerce' ),
			),
			array(
				'id'    => 'cancellations',
				'icon'  => 'xcircle',
				'label' => __( 'Cancellations', 'wpheka-edit-orders-for-woocommerce' ),
			),
			array(
				'id'    => 'emails',
				'icon'  => 'mail',
				'label' => __( 'Emails', 'wpheka-edit-orders-for-woocommerce' ),
			),
			array(
				'id'    => 'advanced',
				'icon'  => 'sliders',
				'label' => __( 'Advanced', 'wpheka-edit-orders-for-woocommerce' ),
			),
		);

		/**
		 * Section links on the Settings tab. Add one for each section printed on
		 * edit_orders_for_woocommerce_settings_after_sections.
		 *
		 * @since 1.4.0
		 *
		 * @param array[] $nav Each { id, icon, label }; [ id, icon, label ] works too.
		 */
		$nav = (array) apply_filters( 'edit_orders_for_woocommerce_settings_sections', $nav );

		$out = array();
		foreach ( $nav as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$id    = isset( $item['id'] ) ? $item['id'] : ( isset( $item[0] ) ? $item[0] : '' );
			$icon  = isset( $item['icon'] ) ? $item['icon'] : ( isset( $item[1] ) ? $item[1] : 'sliders' );
			$label = isset( $item['label'] ) ? $item['label'] : ( isset( $item[2] ) ? $item[2] : '' );
			if ( '' !== (string) $id && '' !== (string) $label ) {
				$out[] = array( sanitize_html_class( (string) $id ), sanitize_key( (string) $icon ), (string) $label );
			}
		}

		return $out;
	}

	/**
	 * The Help tab.
	 *
	 * @param array $links Help links.
	 */
	private static function render_help( array $links ) {
		$cards = array(
			array( 'book', __( 'Documentation', 'wpheka-edit-orders-for-woocommerce' ), __( 'How each setting works, with answers to common questions.', 'wpheka-edit-orders-for-woocommerce' ), $links['docs'] ),
			array( 'chat', __( 'Support forum', 'wpheka-edit-orders-for-woocommerce' ), __( 'Ask a question. The developers who built the plugin answer.', 'wpheka-edit-orders-for-woocommerce' ), $links['support'] ),
			array( 'sparkle', __( "What's new", 'wpheka-edit-orders-for-woocommerce' ), __( 'Every change in each version, in plain words.', 'wpheka-edit-orders-for-woocommerce' ), $links['changes'] ),
		);
		echo '<div class="w-flow">';
		foreach ( $cards as $card ) {
			echo '<a class="w-card w-flow-step" href="' . esc_url( $card[3] ) . '" target="_blank" rel="noopener"><span class="w-ico w-ico--lg">' . Icons::get( $card[0] ) . '</span><h3>' . esc_html( $card[1] ) . '</h3><p>' . esc_html( $card[2] ) . '<span class="w-sr"> ' . esc_html__( '(opens in a new tab)', 'wpheka-edit-orders-for-woocommerce' ) . '</span></p></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		}
		echo '</div>';
	}
}
