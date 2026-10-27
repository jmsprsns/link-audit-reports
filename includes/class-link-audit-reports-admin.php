<?php
/**
 * Admin side: the Content Powered > Link Audit and Reports page, its AJAX
 * and form handlers, and the Plugins-screen link.
 *
 * Tabs: Domains (the report), Undo Log, Whitelist & Blacklist and Settings.
 * Everything needs manage_options, and every request that changes anything
 * carries a nonce.
 *
 * @package Link_Audit_Reports
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin page controller.
 */
final class Link_Audit_Reports_Admin {

	/**
	 * Page slug, also used as the settings group.
	 */
	const PAGE = 'link-audit-reports';

	/**
	 * Lucide icon shown beside the menu item and page title.
	 */
	const ICON = 'globe';

	/**
	 * Nonce action for the page's AJAX requests.
	 */
	const NONCE = 'link_audit_reports_admin';

	/**
	 * Capability needed for everything here.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Tabs => icon.
	 */
	const TABS = array(
		'domains'  => 'globe',
		'log'      => 'history',
		'lists'    => 'shield-check',
		'settings' => 'settings',
	);

	/**
	 * Links shown per load when a domain is opened.
	 */
	const DETAILS_PER_LOAD = 50;

	/**
	 * Log entries per page.
	 */
	const LOG_PER_PAGE = 20;

	/**
	 * Plugin instance.
	 *
	 * @var Link_Audit_Reports_Plugin
	 */
	private $plugin;

	/**
	 * Hook suffix of the page.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * The domains report (built when the page loads).
	 *
	 * @var Link_Audit_Reports_List_Table|null
	 */
	private $table = null;

	/**
	 * Constructor.
	 *
	 * @param Link_Audit_Reports_Plugin $plugin Plugin instance.
	 */
	public function __construct( Link_Audit_Reports_Plugin $plugin ) {
		$this->plugin = $plugin;

		Link_Audit_Reports_Menu::init();
		Link_Audit_Reports_Feedback::init();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'admin_notices', array( $this, 'blacklist_notice' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_option' ), 10, 3 );
		add_filter( 'set_screen_option_' . Link_Audit_Reports_List_Table::PER_PAGE_OPTION, array( $this, 'save_screen_option' ), 10, 3 );

		add_action( 'wp_ajax_link_audit_reports_scan', array( $this, 'ajax_scan' ) );
		add_action( 'wp_ajax_link_audit_reports_details', array( $this, 'ajax_details' ) );
		add_action( 'wp_ajax_link_audit_reports_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_link_audit_reports_job', array( $this, 'ajax_job' ) );

		add_action( 'admin_post_link_audit_reports_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_link_audit_reports_lists', array( $this, 'handle_lists' ) );
		add_action( 'admin_post_link_audit_reports_rescan', array( $this, 'handle_rescan' ) );
		add_action( 'admin_post_link_audit_reports_undo', array( $this, 'handle_undo' ) );

		add_filter(
			'plugin_action_links_' . plugin_basename( LINK_AUDIT_REPORTS_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	// Helpers -------------------------------------------------------------------

	/**
	 * A bundled icon.
	 *
	 * @param string $name  Icon name.
	 * @param int    $size  Size in px.
	 * @param string $class Extra classes.
	 * @return string Inline SVG.
	 */
	public static function icon( $name, $size = 18, $class = '' ) {
		return Link_Audit_Reports_Icons::get( $name, $size, $class );
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra query args.
	 * @return string
	 */
	public static function tab_url( $tab = 'domains', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::PAGE,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * URL of the report with some filters.
	 *
	 * @param array    $filters Cleaned filters.
	 * @param string[] $skip    Query args to leave out.
	 * @return string
	 */
	public static function report_url( array $filters, array $skip = array() ) {
		$args = array(
			'lar_author' => empty( $filters['author'] ) ? '' : (int) $filters['author'],
			'lar_type'   => empty( $filters['type'] ) ? '' : $filters['type'],
			'lar_rel'    => empty( $filters['rel'] ) ? '' : $filters['rel'],
			'lar_from'   => empty( $filters['from'] ) ? '' : wp_date( 'Y-m-d', $filters['from'] ),
			'lar_to'     => empty( $filters['to'] ) ? '' : wp_date( 'Y-m-d', $filters['to'] ),
			's'          => isset( $filters['search'] ) ? (string) $filters['search'] : '',
			'lar_view'   => isset( $filters['view'] ) && 'active' !== $filters['view'] ? $filters['view'] : '',
		);

		foreach ( $skip as $key ) {
			unset( $args[ $key ] );
		}

		return self::tab_url( 'domains', array_filter( $args, 'strlen' ) );
	}

	/**
	 * The report's filters from a request.
	 *
	 * @param array $source Request values (unslashed): lar_author, lar_type,
	 *                      lar_rel, lar_from, lar_to, s, lar_view.
	 * @return array Cleaned, see Link_Audit_Reports_DB::where().
	 */
	public static function filters_from( array $source ) {
		$get = function ( $key ) use ( $source ) {
			return isset( $source[ $key ] ) && is_scalar( $source[ $key ] ) ? sanitize_text_field( (string) $source[ $key ] ) : '';
		};

		$filters = Link_Audit_Reports_Jobs::clean_filters(
			array(
				'author' => $get( 'lar_author' ),
				'type'   => $get( 'lar_type' ),
				'rel'    => $get( 'lar_rel' ),
				'from'   => $get( 'lar_from' ),
				'to'     => $get( 'lar_to' ),
			)
		);

		$search = strtolower( trim( $get( 's' ) ) );
		if ( '' !== $search ) {
			$filters['search'] = substr( $search, 0, 100 );
		}

		$view            = $get( 'lar_view' );
		$filters['view'] = in_array( $view, array( 'whitelisted', 'blacklisted' ), true ) ? $view : 'active';

		return $filters;
	}

	/**
	 * Pills for rel values: Follow, Nofollow, Sponsored, UGC.
	 *
	 * @param array<string,int> $counts Value => number of links.
	 * @param bool              $counts_shown Show the numbers.
	 * @return string
	 */
	public static function rel_pills( array $counts, $counts_shown = true ) {
		$labels = array(
			'follow'    => __( 'Follow', 'link-audit-reports' ),
			'nofollow'  => __( 'Nofollow', 'link-audit-reports' ),
			'sponsored' => __( 'Sponsored', 'link-audit-reports' ),
			'ugc'       => __( 'UGC', 'link-audit-reports' ),
		);
		$out    = '';

		foreach ( $labels as $key => $label ) {
			if ( empty( $counts[ $key ] ) ) {
				continue;
			}

			$out .= sprintf(
				'<span class="lar-pill is-%1$s">%2$s%3$s</span>',
				esc_attr( $key ),
				esc_html( $label ),
				$counts_shown ? ' <b>' . esc_html( number_format_i18n( (int) $counts[ $key ] ) ) . '</b>' : ''
			);
		}

		return '<span class="lar-pills">' . $out . '</span>';
	}

	/**
	 * A user's avatar and name.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function person( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : false;

		if ( ! $user ) {
			return '<span class="lar-person lar-muted">' . self::icon( 'user', 16 ) . esc_html__( 'Unknown', 'link-audit-reports' ) . '</span>';
		}

		return '<span class="lar-person">' . get_avatar( $user->ID, 20, '', '', array( 'class' => 'lar-avatar' ) ) . esc_html( $user->display_name ) . '</span>';
	}

	/**
	 * A date, with the time on hover.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	public static function date( $time ) {
		if ( ! $time ) {
			return '<span class="lar-muted">&mdash;</span>';
		}

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) ),
			esc_html( wp_date( get_option( 'date_format' ), $time ) )
		);
	}

	/**
	 * Remember a message to show on the next page load.
	 *
	 * @param string $text Message.
	 * @param string $type success, error or info.
	 * @return void
	 */
	private static function flash( $text, $type = 'success' ) {
		set_transient(
			'link_audit_reports_notice_' . get_current_user_id(),
			array(
				'text' => (string) $text,
				'type' => $type,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Stop unless the current user may use this page.
	 *
	 * @return void
	 */
	private static function check_user() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'link-audit-reports' ), 403 );
		}
	}

	/**
	 * Stop an AJAX request unless its nonce and user are right.
	 *
	 * @return void
	 */
	private static function check_ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'link-audit-reports' ) ), 403 );
		}
	}

	// Menu and assets -----------------------------------------------------------

	/**
	 * Add a "Settings" link to the plugin's row on the Plugins screen.
	 *
	 * @param array $links Existing action links (Deactivate, etc.).
	 * @return array
	 */
	public function add_settings_link( $links ) {
		if ( ! is_array( $links ) ) {
			return $links;
		}

		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::tab_url() ), esc_html__( 'Link Report', 'link-audit-reports' ) ),
			sprintf( '<a href="%s">%s</a>', esc_url( self::tab_url( 'settings' ) ), esc_html__( 'Settings', 'link-audit-reports' ) )
		);

		return $links;
	}

	/**
	 * Add the page under the shared Content Powered menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$icon       = self::icon( self::ICON, 17, 'link-audit-reports-menu-icon' );
		$menu_title = $icon . '<span class="link-audit-reports-menu-label">' . esc_html__( 'Link Audit and Reports', 'link-audit-reports' ) . '</span>';

		$this->hook_suffix = add_submenu_page(
			Link_Audit_Reports_Menu::SLUG,
			__( 'Link Audit and Reports', 'link-audit-reports' ),
			$menu_title,
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, array( $this, 'load_page' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private static function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'domains'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return isset( self::TABS[ $tab ] ) ? $tab : 'domains';
	}

	/**
	 * Before the page is drawn: tidy the URL, run a bulk action sent without
	 * JavaScript, and set up the report.
	 *
	 * @return void
	 */
	public function load_page() {
		if ( 'domains' !== self::current_tab() ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The bulk action checks its nonce below; the rest only reads.
		$action = isset( $_GET['action'] ) && '-1' !== $_GET['action'] ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		if ( '' === $action && isset( $_GET['action2'] ) && '-1' !== $_GET['action2'] ) {
			$action = sanitize_key( wp_unslash( $_GET['action2'] ) );
		}

		$filters = self::filters_from( wp_unslash( $_GET ) );

		if ( '' !== $action && in_array( $action, Link_Audit_Reports_Jobs::actions(), true ) && ! empty( $_GET['domain'] ) ) {
			check_admin_referer( 'bulk-domains' );
			self::check_user();

			$domains = Link_Audit_Reports_Jobs::clean_domains( wp_unslash( (array) $_GET['domain'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each one is validated as a host in clean_domains().
			$result  = $this->plugin->jobs()->start( $action, $domains, array_diff_key( $filters, array_flip( array( 'search', 'view' ) ) ) );

			if ( is_wp_error( $result ) ) {
				self::flash( $result->get_error_message(), 'error' );
				wp_safe_redirect( self::report_url( $filters ) );
			} else {
				wp_safe_redirect( add_query_arg( 'lar_done', $result, self::report_url( $filters ) ) );
			}
			exit;
		}

		// The filter form also sends the bulk-action nonce: drop it from the URL.
		if ( ! empty( $_GET['_wp_http_referer'] ) || ! empty( $_GET['_wpnonce'] ) || isset( $_GET['filter_action'] ) ) {
			$sort = array();
			if ( isset( $_GET['orderby'], $_GET['order'] ) ) {
				$sort = array(
					'orderby' => sanitize_key( wp_unslash( $_GET['orderby'] ) ),
					'order'   => 'asc' === $_GET['order'] ? 'asc' : 'desc',
				);
			}

			wp_safe_redirect( add_query_arg( $sort, self::report_url( $filters ) ) );
			exit;
		}
		// phpcs:enable

		add_screen_option(
			'per_page',
			array(
				'default' => 25,
				'option'  => Link_Audit_Reports_List_Table::PER_PAGE_OPTION,
			)
		);

		$this->table = new Link_Audit_Reports_List_Table( $filters, $this->plugin );
	}

	/**
	 * Save the rows-per-page screen option.
	 *
	 * @param mixed  $status Value to save, or false.
	 * @param string $option Option name.
	 * @param mixed  $value  Submitted value.
	 * @return mixed
	 */
	public function save_screen_option( $status, $option, $value ) {
		if ( Link_Audit_Reports_List_Table::PER_PAGE_OPTION === $option ) {
			return max( 5, min( 500, absint( $value ) ) );
		}

		return $status;
	}

	/**
	 * Body class for the page, so its full-width header can line up.
	 *
	 * @param string $classes Classes.
	 * @return string
	 */
	public function body_class( $classes ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && $this->hook_suffix && $screen->id === $this->hook_suffix ) {
			$classes .= ' lar-page';
		}

		return $classes;
	}

	/**
	 * Tiny inline style so the menu icon sits neatly beside its label, even
	 * when a long plugin name wraps onto a second line.
	 *
	 * @return void
	 */
	public function print_menu_icon_style() {
		echo '<style id="link-audit-reports-menu-icon-style">'
			. '#adminmenu a:has(>.link-audit-reports-menu-icon){display:flex;align-items:flex-start;gap:7px}'
			. '#adminmenu .link-audit-reports-menu-icon{flex:none;margin-top:1px;opacity:.7}'
			. '#adminmenu li.current .link-audit-reports-menu-icon,#adminmenu a:hover .link-audit-reports-menu-icon,#adminmenu a:focus .link-audit-reports-menu-icon{opacity:1}'
			. '</style>';
	}

	/**
	 * Load the page's styles and scripts.
	 *
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'link-audit-reports-admin', LINK_AUDIT_REPORTS_URL . 'assets/admin.css', array(), LINK_AUDIT_REPORTS_VERSION );
		wp_enqueue_script( 'link-audit-reports-admin', LINK_AUDIT_REPORTS_URL . 'assets/admin.js', array(), LINK_AUDIT_REPORTS_VERSION, true );

		$filters = self::filters_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$actions = array(
			'nofollow'  => array( 'link-2-off', __( 'Add nofollow', 'link-audit-reports' ) ),
			'sponsored' => array( 'badge-dollar-sign', __( 'Add sponsored', 'link-audit-reports' ) ),
			'ugc'       => array( 'message-square-text', __( 'Add ugc', 'link-audit-reports' ) ),
			'dofollow'  => array( 'link', __( 'Make dofollow', 'link-audit-reports' ) ),
			'strip'     => array( 'unlink', __( 'Strip links, keep text', 'link-audit-reports' ) ),
			'whitelist' => array( 'shield-check', __( 'Whitelist', 'link-audit-reports' ) ),
			'blacklist' => array( 'shield-ban', __( 'Blacklist', 'link-audit-reports' ) ),
		);

		foreach ( $actions as $action => $meta ) {
			$actions[ $action ] = array(
				'icon'  => self::icon( $meta[0], 18 ),
				'label' => $meta[1],
			);
		}

		wp_localize_script(
			'link-audit-reports-admin',
			'link_audit_reports_admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'actions' => $actions,
				'filters' => array(
					'lar_author' => empty( $filters['author'] ) ? '' : (string) $filters['author'],
					'lar_type'   => empty( $filters['type'] ) ? '' : $filters['type'],
					'lar_rel'    => empty( $filters['rel'] ) ? '' : $filters['rel'],
					'lar_from'   => empty( $filters['from'] ) ? '' : wp_date( 'Y-m-d', $filters['from'] ),
					'lar_to'     => empty( $filters['to'] ) ? '' : wp_date( 'Y-m-d', $filters['to'] ),
				),
				'i18n'    => array(
					'failed'     => __( 'Something went wrong. Reload the page and try again.', 'link-audit-reports' ),
					'loading'    => __( 'Loading…', 'link-audit-reports' ),
					'cancel'     => __( 'Cancel', 'link-audit-reports' ),
					'close'      => __( 'Close', 'link-audit-reports' ),
					'working'    => __( 'Working…', 'link-audit-reports' ),
					/* translators: 1: posts done, 2: posts in total. */
					'progress'   => __( '%1$s of %2$s posts', 'link-audit-reports' ),
					'noneChosen' => __( 'Select at least one domain first.', 'link-audit-reports' ),
					'undoSure'   => __( 'Undo this change? Each link it changed is put back the way it was.', 'link-audit-reports' ),
					'showLinks'  => __( 'Show links', 'link-audit-reports' ),
					'hideLinks'  => __( 'Hide links', 'link-audit-reports' ),
				),
			)
		);
	}

	// Settings ------------------------------------------------------------------

	/**
	 * Register the settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::PAGE,
			Link_Audit_Reports_Plugin::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Link_Audit_Reports_Plugin::defaults(),
			)
		);
	}

	/**
	 * Clean the submitted settings before they are saved.
	 *
	 * @param mixed $input Raw form values.
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ) {
		$input     = is_array( $input ) ? $input : array();
		$available = array_keys( Link_Audit_Reports_Plugin::available_post_types() );
		$chosen    = isset( $input['post_types'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $input['post_types'] ), $available ) ) : array();
		$current   = $this->plugin->get_settings();

		if ( ! $chosen ) {
			add_settings_error( self::PAGE, 'post_types', __( 'Choose at least one type of content to audit.', 'link-audit-reports' ) );
			$chosen = (array) $current['post_types'];
		} elseif ( ! array_diff( $available, $chosen ) ) {
			// Everything ticked: store "all", so types added later are included too.
			$chosen = array();
		}

		return array(
			'post_types' => $chosen,
			'drafts'     => empty( $input['drafts'] ) ? 0 : 1,
		);
	}

	// Page ----------------------------------------------------------------------

	/**
	 * Tab label.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	private static function tab_label( $tab ) {
		$labels = array(
			'domains'  => __( 'Domains', 'link-audit-reports' ),
			'log'      => __( 'Undo Log', 'link-audit-reports' ),
			'lists'    => __( 'Whitelist & Blacklist', 'link-audit-reports' ),
			'settings' => __( 'Settings', 'link-audit-reports' ),
		);

		return isset( $labels[ $tab ] ) ? $labels[ $tab ] : $tab;
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tab = self::current_tab();
		?>
		<div class="lar-header">
			<div class="lar-header-title">
				<span class="lar-header-icon"><?php echo self::icon( self::ICON, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
				<span class="lar-version"><?php echo esc_html( 'v' . LINK_AUDIT_REPORTS_VERSION ); ?></span>
			</div>
			<nav class="lar-tabs" aria-label="<?php esc_attr_e( 'Sections', 'link-audit-reports' ); ?>">
				<?php foreach ( self::TABS as $id => $icon ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $id ) ); ?>" class="lar-tab<?php echo $id === $tab ? ' is-active' : ''; ?>"<?php echo $id === $tab ? ' aria-current="page"' : ''; ?>>
						<?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php echo esc_html( self::tab_label( $id ) ); ?>
					</a>
				<?php endforeach; ?>
			</nav>
		</div>

		<div class="wrap lar-wrap">
			<hr class="wp-header-end">

			<?php
			$this->render_notices();

			switch ( $tab ) {
				case 'log':
					$this->render_log_tab();
					break;
				case 'lists':
					$this->render_lists_tab();
					break;
				case 'settings':
					$this->render_settings_tab();
					break;
				default:
					$this->render_domains_tab();
			}

			$this->render_dialog();
			?>
		</div>
		<?php
	}

	/**
	 * Messages: settings saved, a flashed message, a finished change.
	 *
	 * @return void
	 */
	private function render_notices() {
		// Pages outside the Settings menu have to show "Settings saved." themselves.
		settings_errors();

		$key   = 'link_audit_reports_notice_' . get_current_user_id();
		$flash = get_transient( $key );

		if ( is_array( $flash ) && ! empty( $flash['text'] ) ) {
			delete_transient( $key );
			$type = in_array( $flash['type'], array( 'success', 'error', 'info' ), true ) ? $flash['type'] : 'info';
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $flash['text'] ) );
		}

		$done = isset( $_GET['lar_done'] ) ? absint( $_GET['lar_done'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only shows a message.
		$log  = $done ? Link_Audit_Reports_DB::get_log( $done ) : null;

		if ( ! $log ) {
			return;
		}

		$running = in_array( $log['status'], array( 'running', 'undoing' ), true );
		?>
		<div class="notice notice-<?php echo esc_attr( $running ? 'info' : 'success' ); ?> is-dismissible lar-done">
			<p>
				<?php echo self::icon( $running ? 'loader-circle' : 'circle-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<span>
					<?php
					if ( $running ) {
						esc_html_e( 'The change is running in the background. Its progress is in the Undo Log.', 'link-audit-reports' );
					} else {
						echo esc_html( Link_Audit_Reports_Jobs::describe( $log ) );
					}
					?>
				</span>
			</p>
			<?php if ( 'done' === $log['status'] && ( (int) $log['links'] || in_array( $log['action'], Link_Audit_Reports_Jobs::LIST_ACTIONS, true ) ) ) : ?>
				<?php $this->undo_button( (int) $log['id'], 'button-link' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * After a save, say which links the blacklist changed.
	 *
	 * @return void
	 */
	public function blacklist_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return;
		}

		$key   = 'link_audit_reports_blacklisted_' . get_current_user_id();
		$found = get_transient( $key );

		if ( ! is_array( $found ) || ! $found ) {
			return;
		}

		delete_transient( $key );

		$stripped  = array();
		$nofollow  = array();
		$sentences = array();

		foreach ( $found as $domain => $mode ) {
			if ( 'strip' === $mode ) {
				$stripped[] = (string) $domain;
			} else {
				$nofollow[] = (string) $domain;
			}
		}

		if ( $stripped ) {
			/* translators: %s: domains. */
			$sentences[] = sprintf( __( 'Links to %s were removed (their text stays), because those domains are blacklisted.', 'link-audit-reports' ), Link_Audit_Reports_Jobs::domain_list( $stripped ) );
		}

		if ( $nofollow ) {
			/* translators: %s: domains. */
			$sentences[] = sprintf( __( 'Links to %s got rel="nofollow", because those domains are blacklisted.', 'link-audit-reports' ), Link_Audit_Reports_Jobs::domain_list( $nofollow ) );
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Link Audit and Reports:', 'link-audit-reports' ),
			esc_html( implode( ' ', $sentences ) ),
			esc_url( self::tab_url( 'lists' ) ),
			esc_html__( 'Manage the blacklist', 'link-audit-reports' )
		);
	}

	/**
	 * An Undo button (a small form, so it works without JavaScript).
	 *
	 * @param int    $id    Log entry ID.
	 * @param string $class Button class.
	 * @return void
	 */
	private function undo_button( $id, $class = 'button' ) {
		?>
		<form class="lar-undo-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-lar-undo>
			<input type="hidden" name="action" value="link_audit_reports_undo">
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<?php wp_nonce_field( 'link_audit_reports_undo_' . $id ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?> lar-undo">
				<?php echo self::icon( 'undo-2', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
				<?php esc_html_e( 'Undo', 'link-audit-reports' ); ?>
			</button>
		</form>
		<?php
	}

	// Domains tab -----------------------------------------------------------------

	/**
	 * The report.
	 *
	 * @return void
	 */
	private function render_domains_tab() {
		if ( ! $this->table ) {
			$this->table = new Link_Audit_Reports_List_Table( self::filters_from( wp_unslash( $_GET ) ), $this->plugin ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		}

		$filters = self::filters_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only.
		$stats   = Link_Audit_Reports_DB::stats( $filters );

		$this->render_scan_box();
		$this->render_stats( $stats );

		$this->table->prepare_items();
		?>
		<div class="lar-report-head">
			<?php $this->table->views(); ?>
			<div class="lar-exports">
				<a class="button" href="<?php echo esc_url( self::export_url( 'domains', $filters ) ); ?>">
					<?php echo self::icon( 'file-spreadsheet', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Export domains', 'link-audit-reports' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( self::export_url( 'links', $filters ) ); ?>">
					<?php echo self::icon( 'file-down', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php esc_html_e( 'Export all links', 'link-audit-reports' ); ?>
				</a>
			</div>
		</div>

		<form method="get" class="lar-report" data-lar-report>
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<input type="hidden" name="tab" value="domains">
			<?php if ( 'active' !== $filters['view'] ) : ?>
				<input type="hidden" name="lar_view" value="<?php echo esc_attr( $filters['view'] ); ?>">
			<?php endif; ?>
			<?php
			$this->table->search_box( __( 'Search domains', 'link-audit-reports' ), 'lar-search' );
			$this->table->display();
			?>
		</form>
		<?php
	}

	/**
	 * Link to a CSV export with the current filters.
	 *
	 * @param string $type    domains or links.
	 * @param array  $filters Filters.
	 * @return string
	 */
	private static function export_url( $type, array $filters ) {
		$args = wp_parse_args( wp_parse_url( self::report_url( $filters ), PHP_URL_QUERY ) );

		unset( $args['page'], $args['tab'] );

		return wp_nonce_url(
			add_query_arg(
				array_merge(
					$args,
					array(
						'action' => 'link_audit_reports_export',
						'type'   => $type,
					)
				),
				admin_url( 'admin-post.php' )
			),
			'link_audit_reports_export'
		);
	}

	/**
	 * Scan progress, while a full scan runs.
	 *
	 * @return void
	 */
	private function render_scan_box() {
		$state = Link_Audit_Reports_Scanner::get_state();

		if ( 'running' !== $state['status'] ) {
			return;
		}

		$total   = max( 1, (int) $state['total'] );
		$percent = min( 100, (int) floor( (int) $state['done'] / $total * 100 ) );
		?>
		<div class="lar-scan" data-lar-scan>
			<span class="lar-scan-icon"><?php echo self::icon( 'scan-search', 22 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
			<div class="lar-scan-body">
				<strong><?php echo esc_html( $state['finished'] ? __( 'Rescanning your content…', 'link-audit-reports' ) : __( 'Scanning your content for outbound links…', 'link-audit-reports' ) ); ?></strong>
				<span data-lar-scan-status>
					<?php
					/* translators: 1: posts scanned, 2: posts in total. */
					echo esc_html( sprintf( __( '%1$s of %2$s posts', 'link-audit-reports' ), number_format_i18n( (int) $state['done'] ), number_format_i18n( (int) $state['total'] ) ) );
					?>
				</span>
				<span class="lar-progress"><span style="width: <?php echo (int) $percent; ?>%" data-lar-scan-bar></span></span>
				<span class="lar-muted"><?php esc_html_e( 'It carries on in the background if you leave this page. The report fills in as it goes.', 'link-audit-reports' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * The summary boxes above the report.
	 *
	 * @param array $stats Totals from Link_Audit_Reports_DB::stats().
	 * @return void
	 */
	private function render_stats( array $stats ) {
		$not_followed = $stats['links'] - $stats['follow'];
		$percent      = $stats['links'] ? round( $stats['follow'] / $stats['links'] * 100 ) : 0;
		$boxes        = array(
			array( 'link', number_format_i18n( $stats['links'] ), __( 'Outbound links', 'link-audit-reports' ), '' ),
			array( 'globe', number_format_i18n( $stats['domains'] ), __( 'Linked domains', 'link-audit-reports' ), '' ),
			array( 'file-text', number_format_i18n( $stats['posts'] ), __( 'Posts with outbound links', 'link-audit-reports' ), '' ),
			/* translators: %s: percentage of links. */
			array( 'arrow-up-right', number_format_i18n( $stats['follow'] ), sprintf( __( 'Followed (%s%%)', 'link-audit-reports' ), number_format_i18n( $percent ) ), 'is-follow' ),
			array( 'shield-check', number_format_i18n( $not_followed ), __( 'Nofollow, sponsored or ugc', 'link-audit-reports' ), 'is-qualified' ),
		);
		?>
		<div class="lar-stats">
			<?php foreach ( $boxes as $box ) : ?>
				<div class="lar-stat <?php echo esc_attr( $box[3] ); ?>">
					<span class="lar-stat-icon"><?php echo self::icon( $box[0], 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
					<span class="lar-stat-num"><?php echo esc_html( $box[1] ); ?></span>
					<span class="lar-stat-label"><?php echo esc_html( $box[2] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The confirmation dialog the script fills in.
	 *
	 * @return void
	 */
	private function render_dialog() {
		?>
		<dialog class="lar-dialog" data-lar-dialog aria-labelledby="lar-dialog-title">
			<form method="dialog" class="lar-dialog-inner">
				<div class="lar-dialog-head">
					<span class="lar-dialog-icon" data-lar-dialog-icon></span>
					<h2 id="lar-dialog-title" data-lar-dialog-title></h2>
					<button type="button" class="lar-dialog-close" data-lar-dialog-cancel aria-label="<?php esc_attr_e( 'Close', 'link-audit-reports' ); ?>"><?php echo self::icon( 'x', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></button>
				</div>
				<div class="lar-dialog-body" data-lar-dialog-body></div>
				<div class="lar-dialog-options" data-lar-dialog-options hidden>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'What to do with links to it', 'link-audit-reports' ); ?></legend>
						<label class="lar-choice">
							<input type="radio" name="lar_mode" value="strip" checked>
							<span><strong><?php esc_html_e( 'Strip the link, keep the text', 'link-audit-reports' ); ?></strong><span><?php esc_html_e( 'The words stay in the post as plain text.', 'link-audit-reports' ); ?></span></span>
						</label>
						<label class="lar-choice">
							<input type="radio" name="lar_mode" value="nofollow">
							<span><strong><?php esc_html_e( 'Keep the link, add nofollow', 'link-audit-reports' ); ?></strong><span><?php esc_html_e( 'Readers can still click it; search engines don\'t follow it.', 'link-audit-reports' ); ?></span></span>
						</label>
					</fieldset>
					<label class="lar-check">
						<input type="checkbox" name="lar_apply" value="1" checked>
						<span data-lar-apply-label></span>
					</label>
				</div>
				<div class="lar-dialog-progress" data-lar-dialog-progress hidden>
					<span class="lar-progress"><span data-lar-dialog-bar></span></span>
					<span class="lar-muted" data-lar-dialog-status></span>
				</div>
				<div class="lar-dialog-foot">
					<span class="lar-dialog-note"><?php echo self::icon( 'history', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Every change is logged and can be undone.', 'link-audit-reports' ); ?></span>
					<button type="button" class="button" data-lar-dialog-cancel><?php esc_html_e( 'Cancel', 'link-audit-reports' ); ?></button>
					<button type="button" class="button button-primary" data-lar-dialog-confirm></button>
				</div>
			</form>
		</dialog>
		<?php
	}

	// Log tab -----------------------------------------------------------------------

	/**
	 * The undo log.
	 *
	 * @return void
	 */
	private function render_log_tab() {
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$total = Link_Audit_Reports_DB::count_logs();
		$pages = max( 1, (int) ceil( $total / self::LOG_PER_PAGE ) );
		$page  = min( $page, $pages );
		$logs  = Link_Audit_Reports_DB::logs( self::LOG_PER_PAGE, ( $page - 1 ) * self::LOG_PER_PAGE );
		$icons = array(
			'nofollow'  => 'link-2-off',
			'sponsored' => 'badge-dollar-sign',
			'ugc'       => 'message-square-text',
			'dofollow'  => 'link',
			'strip'     => 'unlink',
			'whitelist' => 'shield-check',
			'blacklist' => 'shield-ban',
		);

		$users = array_unique( array_merge( wp_list_pluck( $logs, 'user_id' ), wp_list_pluck( $logs, 'undone_by' ) ) );
		cache_users( array_filter( array_map( 'intval', $users ) ) );
		?>
		<div class="postbox lar-box">
			<div class="postbox-header">
				<h2 class="hndle"><?php echo self::icon( 'history', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Undo Log', 'link-audit-reports' ); ?></h2>
			</div>
			<div class="inside">
				<p class="lar-intro"><?php esc_html_e( 'Every change made from the report is listed here. Undo puts back exactly the links that change touched, and leaves any edits made to the posts since then alone. Each edited post also keeps a revision.', 'link-audit-reports' ); ?></p>

				<?php if ( ! $logs ) : ?>
					<p class="lar-empty"><?php echo self::icon( 'circle-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'No changes yet. Changes you make from the Domains tab will show up here.', 'link-audit-reports' ); ?></p>
				<?php else : ?>
					<table class="widefat striped lar-log">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Change', 'link-audit-reports' ); ?></th>
								<th scope="col"><?php esc_html_e( 'By', 'link-audit-reports' ); ?></th>
								<th scope="col"><?php esc_html_e( 'When', 'link-audit-reports' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'link-audit-reports' ); ?></th>
								<th scope="col" class="lar-log-actions"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'link-audit-reports' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $log ) : ?>
								<?php $this->render_log_row( $log, isset( $icons[ $log['action'] ] ) ? $icons[ $log['action'] ] : 'history' ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( $pages > 1 ) : ?>
						<div class="lar-pager">
							<?php
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%', self::tab_url( 'log' ) ),
										'format'    => '',
										'current'   => $page,
										'total'     => $pages,
										'prev_text' => '&lsaquo;',
										'next_text' => '&rsaquo;',
									)
								)
							);
							?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * One log entry.
	 *
	 * @param array  $log  Entry.
	 * @param string $icon Icon.
	 * @return void
	 */
	private function render_log_row( array $log, $icon ) {
		$statuses = array(
			'running' => array( __( 'Running', 'link-audit-reports' ), 'is-running' ),
			'done'    => array( __( 'Done', 'link-audit-reports' ), 'is-done' ),
			'undoing' => array( __( 'Undoing', 'link-audit-reports' ), 'is-running' ),
			'undone'  => array( __( 'Undone', 'link-audit-reports' ), 'is-undone' ),
			'partial' => array( __( 'Partly undone', 'link-audit-reports' ), 'is-partial' ),
		);
		$status   = isset( $statuses[ $log['status'] ] ) ? $statuses[ $log['status'] ] : array( $log['status'], '' );
		$filters  = is_array( $log['filters'] ) ? array_filter( $log['filters'] ) : array();
		$running  = in_array( $log['status'], array( 'running', 'undoing' ), true );
		$progress = $running ? Link_Audit_Reports_Jobs::progress( Link_Audit_Reports_DB::get_log( (int) $log['id'] ) ) : null;
		?>
		<tr<?php echo $running ? ' data-lar-running="' . (int) $log['id'] . '"' : ''; ?>>
			<td class="lar-log-change">
				<span class="lar-log-icon"><?php echo self::icon( $icon, 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?></span>
				<span>
					<?php echo esc_html( Link_Audit_Reports_Jobs::describe( $log ) ); ?>
					<?php if ( $filters ) : ?>
						<span class="lar-muted lar-log-filters"><?php echo esc_html( $this->filters_text( $filters ) ); ?></span>
					<?php endif; ?>
					<?php if ( $progress ) : ?>
						<span class="lar-progress"><span style="width: <?php echo (int) ( $progress['total'] ? floor( $progress['done'] / $progress['total'] * 100 ) : 0 ); ?>%" data-lar-log-bar></span></span>
					<?php endif; ?>
					<?php if ( in_array( $log['status'], array( 'undone', 'partial' ), true ) ) : ?>
						<span class="lar-muted lar-log-undo">
							<?php
							$by = get_userdata( (int) $log['undone_by'] );
							/* translators: 1: user name, 2: date. */
							echo esc_html( sprintf( __( 'Undone by %1$s on %2$s.', 'link-audit-reports' ), $by ? $by->display_name : __( 'someone', 'link-audit-reports' ), wp_date( get_option( 'date_format' ), (int) $log['undone'] ) ) );

							if ( 'partial' === $log['status'] ) {
								$missed = max( 0, (int) $log['links'] - (int) $log['undone_links'] );
								echo ' ';
								/* translators: %s: number of links. */
								echo esc_html( sprintf( _n( '%s link couldn\'t be put back because its post was edited since. Its revisions still have it.', '%s links couldn\'t be put back because their posts were edited since. Their revisions still have them.', $missed, 'link-audit-reports' ), number_format_i18n( $missed ) ) );
							}
							?>
						</span>
					<?php endif; ?>
				</span>
			</td>
			<td><?php echo self::person( (int) $log['user_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in person(). ?></td>
			<td><?php echo self::date( (int) $log['created'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date(). ?></td>
			<td><span class="lar-status <?php echo esc_attr( $status[1] ); ?>"><?php echo esc_html( $status[0] ); ?></span></td>
			<td class="lar-log-actions">
				<?php if ( 'done' === $log['status'] && ( (int) $log['links'] || in_array( $log['action'], Link_Audit_Reports_Jobs::LIST_ACTIONS, true ) ) ) : ?>
					<?php $this->undo_button( (int) $log['id'] ); ?>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * The filters a change was limited to, in words.
	 *
	 * @param array $filters Filters.
	 * @return string
	 */
	private function filters_text( array $filters ) {
		$parts = array();

		if ( ! empty( $filters['author'] ) ) {
			$user = get_userdata( (int) $filters['author'] );
			/* translators: %s: author name. */
			$parts[] = sprintf( __( 'added by %s', 'link-audit-reports' ), $user ? $user->display_name : '#' . (int) $filters['author'] );
		}

		if ( ! empty( $filters['type'] ) ) {
			$object = get_post_type_object( $filters['type'] );
			/* translators: %s: post type. */
			$parts[] = sprintf( __( 'in %s', 'link-audit-reports' ), $object ? $object->labels->name : $filters['type'] );
		}

		if ( ! empty( $filters['rel'] ) ) {
			/* translators: %s: rel value. */
			$parts[] = 'follow' === $filters['rel'] ? __( 'followed links only', 'link-audit-reports' ) : sprintf( __( '%s links only', 'link-audit-reports' ), $filters['rel'] );
		}

		if ( ! empty( $filters['from'] ) ) {
			/* translators: %s: date. */
			$parts[] = sprintf( __( 'first seen from %s', 'link-audit-reports' ), wp_date( get_option( 'date_format' ), (int) $filters['from'] ) );
		}

		if ( ! empty( $filters['to'] ) ) {
			/* translators: %s: date. */
			$parts[] = sprintf( __( 'first seen until %s', 'link-audit-reports' ), wp_date( get_option( 'date_format' ), (int) $filters['to'] ) );
		}

		/* translators: %s: list of filters. */
		return $parts ? sprintf( __( 'Only links %s.', 'link-audit-reports' ), implode( ', ', $parts ) ) : '';
	}

	// Lists tab -----------------------------------------------------------------------

	/**
	 * The whitelist and blacklist.
	 *
	 * @return void
	 */
	private function render_lists_tab() {
		$lists = $this->plugin->lists();
		?>
		<div class="lar-lists">
			<div class="postbox lar-box">
				<div class="postbox-header">
					<h2 class="hndle"><?php echo self::icon( 'shield-check', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Whitelist', 'link-audit-reports' ); ?></h2>
				</div>
				<div class="inside">
					<p class="lar-intro"><?php esc_html_e( 'Trusted domains you don\'t need to review. They\'re hidden from the report (look under "Whitelisted" to see them), and their links are never changed. A domain covers its subdomains too.', 'link-audit-reports' ); ?></p>
					<?php $this->render_list_form( 'whitelist' ); ?>
					<?php $this->render_list( 'whitelist', $lists['whitelist'] ); ?>
				</div>
			</div>

			<div class="postbox lar-box">
				<div class="postbox-header">
					<h2 class="hndle"><?php echo self::icon( 'shield-ban', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Blacklist', 'link-audit-reports' ); ?></h2>
				</div>
				<div class="inside">
					<p class="lar-intro"><?php esc_html_e( 'Domains you never want to link to normally. Whenever a post is saved, links to them are stripped (the text stays) or get rel="nofollow", whoever wrote them. A domain covers its subdomains too.', 'link-audit-reports' ); ?></p>
					<?php $this->render_list_form( 'blacklist' ); ?>
					<?php $this->render_list( 'blacklist', $lists['blacklist'] ); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * The "add a domain" form for a list.
	 *
	 * @param string $list whitelist or blacklist.
	 * @return void
	 */
	private function render_list_form( $list ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="lar-list-form">
			<input type="hidden" name="action" value="link_audit_reports_lists">
			<input type="hidden" name="list" value="<?php echo esc_attr( $list ); ?>">
			<input type="hidden" name="do" value="add">
			<?php wp_nonce_field( 'link_audit_reports_lists' ); ?>
			<div class="lar-inline">
				<label class="screen-reader-text" for="lar-add-<?php echo esc_attr( $list ); ?>"><?php esc_html_e( 'Domain', 'link-audit-reports' ); ?></label>
				<input type="text" class="regular-text" id="lar-add-<?php echo esc_attr( $list ); ?>" name="domains" placeholder="<?php esc_attr_e( 'example.com, another.org', 'link-audit-reports' ); ?>" required>
				<?php if ( 'blacklist' === $list ) : ?>
					<label class="screen-reader-text" for="lar-add-mode"><?php esc_html_e( 'What to do with links to it', 'link-audit-reports' ); ?></label>
					<select name="mode" id="lar-add-mode">
						<option value="strip"><?php esc_html_e( 'Strip link, keep text', 'link-audit-reports' ); ?></option>
						<option value="nofollow"><?php esc_html_e( 'Add nofollow', 'link-audit-reports' ); ?></option>
					</select>
				<?php endif; ?>
				<button type="submit" class="button">
					<?php echo self::icon( 'plus', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
					<?php echo esc_html( 'blacklist' === $list ? __( 'Add to blacklist', 'link-audit-reports' ) : __( 'Add to whitelist', 'link-audit-reports' ) ); ?>
				</button>
			</div>
			<?php if ( 'blacklist' === $list ) : ?>
				<label class="lar-check lar-apply">
					<input type="checkbox" name="apply" value="1" checked>
					<span><?php esc_html_e( 'Also change the links to it that are already in posts (logged, so you can undo it)', 'link-audit-reports' ); ?></span>
				</label>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * The domains on a list.
	 *
	 * @param string $list    whitelist or blacklist.
	 * @param array  $entries Domain => entry.
	 * @return void
	 */
	private function render_list( $list, array $entries ) {
		if ( ! $entries ) {
			echo '<p class="lar-empty">' . self::icon( 'list', 18 ) . esc_html__( 'No domains yet.', 'link-audit-reports' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG.
			return;
		}
		?>
		<table class="widefat striped lar-list">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Domain', 'link-audit-reports' ); ?></th>
					<?php if ( 'blacklist' === $list ) : ?>
						<th scope="col"><?php esc_html_e( 'On save', 'link-audit-reports' ); ?></th>
					<?php endif; ?>
					<th scope="col"><?php esc_html_e( 'Added', 'link-audit-reports' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'link-audit-reports' ); ?></span></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $domain => $entry ) : ?>
					<tr>
						<td><span class="lar-domain"><?php echo self::icon( 'globe', 15, 'lar-domain-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><strong><?php echo esc_html( $domain ); ?></strong></span></td>
						<?php if ( 'blacklist' === $list ) : ?>
							<td>
								<span class="lar-pill <?php echo 'nofollow' === $entry['mode'] ? 'is-nofollow' : 'is-strip'; ?>">
									<?php echo esc_html( 'nofollow' === $entry['mode'] ? __( 'Add nofollow', 'link-audit-reports' ) : __( 'Strip link', 'link-audit-reports' ) ); ?>
								</span>
							</td>
						<?php endif; ?>
						<td><?php echo self::date( is_array( $entry ) ? (int) $entry['time'] : (int) $entry ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date(). ?></td>
						<td class="lar-list-actions">
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="link_audit_reports_lists">
								<input type="hidden" name="list" value="<?php echo esc_attr( $list ); ?>">
								<input type="hidden" name="do" value="remove">
								<input type="hidden" name="domains" value="<?php echo esc_attr( $domain ); ?>">
								<?php wp_nonce_field( 'link_audit_reports_lists' ); ?>
								<button type="submit" class="button-link lar-remove">
									<?php echo self::icon( 'trash-2', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
									<?php esc_html_e( 'Remove', 'link-audit-reports' ); ?>
									<span class="screen-reader-text"><?php echo esc_html( $domain ); ?></span>
								</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// Settings tab ---------------------------------------------------------------------

	/**
	 * Settings.
	 *
	 * @return void
	 */
	private function render_settings_tab() {
		$settings = $this->plugin->get_settings();
		$audited  = $this->plugin->post_types();
		$state    = Link_Audit_Reports_Scanner::get_state();
		?>
		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">
				<div id="post-body-content">
					<form action="options.php" method="post" id="lar-settings">
						<?php settings_fields( self::PAGE ); ?>

						<div class="postbox lar-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'files', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Content to audit', 'link-audit-reports' ); ?></h2>
							</div>
							<div class="inside">
								<p class="lar-intro"><?php esc_html_e( 'The links in these types of content are listed in the report, and the report\'s changes and the blacklist apply to them. Changing this starts a fresh scan.', 'link-audit-reports' ); ?></p>
								<table class="form-table" role="presentation">
									<tr>
										<th scope="row"><?php esc_html_e( 'Post types', 'link-audit-reports' ); ?></th>
										<td>
											<fieldset class="lar-types">
												<legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'link-audit-reports' ); ?></legend>
												<?php foreach ( Link_Audit_Reports_Plugin::available_post_types() as $type => $label ) : ?>
													<label class="lar-check">
														<input type="checkbox" name="<?php echo esc_attr( Link_Audit_Reports_Plugin::OPTION ); ?>[post_types][]" value="<?php echo esc_attr( $type ); ?>"<?php checked( in_array( $type, $audited, true ) ); ?>>
														<span><?php echo esc_html( $label ); ?> <code><?php echo esc_html( $type ); ?></code></span>
													</label>
												<?php endforeach; ?>
											</fieldset>
											<p class="description"><?php esc_html_e( 'With every type ticked, types added later by other plugins are included automatically.', 'link-audit-reports' ); ?></p>
										</td>
									</tr>
									<tr>
										<th scope="row"><?php esc_html_e( 'Statuses', 'link-audit-reports' ); ?></th>
										<td>
											<p class="lar-muted lar-statuses"><?php echo self::icon( 'circle-check', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Published, scheduled and private content is always audited.', 'link-audit-reports' ); ?></p>
											<label class="lar-check">
												<input type="checkbox" name="<?php echo esc_attr( Link_Audit_Reports_Plugin::OPTION ); ?>[drafts]" value="1"<?php checked( 1, (int) $settings['drafts'] ); ?>>
												<span><?php esc_html_e( 'Also audit drafts and posts pending review', 'link-audit-reports' ); ?></span>
											</label>
											<p class="description"><?php esc_html_e( 'Handy for catching links before they go live. The blacklist applies to drafts either way.', 'link-audit-reports' ); ?></p>
										</td>
									</tr>
								</table>
							</div>
						</div>
					</form>

					<div class="postbox lar-box">
						<div class="postbox-header">
							<h2 class="hndle"><?php echo self::icon( 'database', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Link index', 'link-audit-reports' ); ?></h2>
						</div>
						<div class="inside">
							<p class="lar-intro"><?php esc_html_e( 'The report reads from an index of every outbound link. It was built by a one-time scan when the plugin was activated, and each post is rescanned whenever it\'s saved, so it stays current on its own. A rescan is only needed if posts were changed directly in the database or by an import that skipped WordPress.', 'link-audit-reports' ); ?></p>
							<table class="lar-kv">
								<tr>
									<th scope="row"><?php esc_html_e( 'Links indexed', 'link-audit-reports' ); ?></th>
									<td><?php echo esc_html( number_format_i18n( Link_Audit_Reports_DB::count_links() ) ); ?></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Last full scan', 'link-audit-reports' ); ?></th>
									<td>
										<?php
										if ( 'running' === $state['status'] ) {
											/* translators: 1: posts scanned, 2: posts in total. */
											echo esc_html( sprintf( __( 'Running now: %1$s of %2$s posts', 'link-audit-reports' ), number_format_i18n( (int) $state['done'] ), number_format_i18n( (int) $state['total'] ) ) );
										} else {
											echo self::date( (int) $state['finished'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date().
										}
										?>
									</td>
								</tr>
							</table>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="link_audit_reports_rescan">
								<?php wp_nonce_field( 'link_audit_reports_rescan' ); ?>
								<button type="submit" class="button">
									<?php echo self::icon( 'refresh-cw', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
									<?php esc_html_e( 'Rescan all content', 'link-audit-reports' ); ?>
								</button>
							</form>
						</div>
					</div>
				</div>

				<div id="postbox-container-1" class="postbox-container">
					<div class="lar-sidebar">
						<div class="postbox lar-box lar-save-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'save', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Save', 'link-audit-reports' ); ?></h2>
							</div>
							<div class="inside">
								<p><?php esc_html_e( 'Changes to what\'s audited start a fresh scan in the background.', 'link-audit-reports' ); ?></p>
								<?php submit_button( __( 'Save Changes', 'link-audit-reports' ), 'primary', 'submit', false, array( 'form' => 'lar-settings' ) ); ?>
							</div>
						</div>

						<div class="postbox lar-box">
							<div class="postbox-header">
								<h2 class="hndle"><?php echo self::icon( 'lightbulb', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Good to know', 'link-audit-reports' ); ?></h2>
							</div>
							<div class="inside">
								<ul class="lar-checklist">
									<li><?php echo self::icon( 'link', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Outbound means a link to another site. Links to your own domain and relative links aren\'t listed.', 'link-audit-reports' ); ?></span></li>
									<li><?php echo self::icon( 'users', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( '"Added by" is whoever saved the post when a link first appeared. Links found by the first scan are credited to the post\'s author.', 'link-audit-reports' ); ?></span></li>
									<li><?php echo self::icon( 'shield-check', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Make dofollow removes nofollow, sponsored and ugc only. noopener and noreferrer stay, because they protect links that open in a new tab.', 'link-audit-reports' ); ?></span></li>
									<li><?php echo self::icon( 'calendar', 16 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><span><?php esc_html_e( 'Changes keep each post\'s "last modified" date, so sitemaps don\'t treat a rel change as fresh content.', 'link-audit-reports' ); ?></span></li>
								</ul>
							</div>
						</div>

						<?php $this->render_updates_card(); ?>
					</div>
				</div>
			</div>
			<br class="clear">
		</div>
		<?php
	}

	/**
	 * Show where updates come from, so it's easy to confirm the repo is wired up.
	 *
	 * @return void
	 */
	private function render_updates_card() {
		$repo_label = preg_replace( '#^https?://[^/]+/#', '', untrailingslashit( LINK_AUDIT_REPORTS_REPO ) );
		$check_url  = Link_Audit_Reports_Updater::check_now_url();
		?>
		<div class="postbox lar-box lar-updates-box">
			<div class="postbox-header">
				<h2 class="hndle"><?php echo self::icon( 'refresh-cw', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Updates', 'link-audit-reports' ); ?></h2>
			</div>
			<div class="inside">
				<p>
					<?php
					/* translators: %s: installed plugin version. */
					printf( esc_html__( 'Installed version: %s', 'link-audit-reports' ), '<strong>' . esc_html( LINK_AUDIT_REPORTS_VERSION ) . '</strong>' );
					?>
				</p>

				<?php if ( ! Link_Audit_Reports_Updater::has_repo() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: header name, 2: main plugin file. */
							esc_html__( 'No repo set. Add it to the %1$s header in %2$s to turn on automatic updates.', 'link-audit-reports' ),
							'<code>Update URI</code>',
							'<code>' . esc_html( plugin_basename( LINK_AUDIT_REPORTS_FILE ) ) . '</code>'
						);
						?>
					</p>
				<?php elseif ( Link_Audit_Reports_Updater::is_dev_copy() ) : ?>
					<p>
						<?php
						printf(
							/* translators: 1: repo, 2: constant name. */
							esc_html__( 'Releases come from %1$s, but update checks are off on this development copy so an update can\'t overwrite your work. Define %2$s as false in wp-config.php to test updates here.', 'link-audit-reports' ),
							'<a href="' . esc_url( LINK_AUDIT_REPORTS_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>',
							'<code>LINK_AUDIT_REPORTS_DEV_MODE</code>'
						);
						?>
					</p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: %s: repo. */
							esc_html__( 'Updates come from the latest release of %s.', 'link-audit-reports' ),
							'<a href="' . esc_url( LINK_AUDIT_REPORTS_REPO ) . '" target="_blank" rel="noopener">' . esc_html( $repo_label ) . '</a>'
						);
						?>
					</p>
					<?php if ( $check_url ) : ?>
						<p><a class="button" href="<?php echo esc_url( $check_url ); ?>"><?php echo self::icon( 'refresh-cw', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Check for updates', 'link-audit-reports' ); ?></a></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	// AJAX ------------------------------------------------------------------------------

	/**
	 * Run a scan batch while the report is open.
	 *
	 * @return void
	 */
	public function ajax_scan() {
		self::check_ajax();

		$state   = $this->plugin->scanner()->run( 5 );
		$total   = max( 1, (int) $state['total'] );
		$running = 'running' === $state['status'];

		if ( $running ) {
			Link_Audit_Reports_Scanner::schedule( 30 );
		}

		wp_send_json_success(
			array(
				'running' => $running,
				'percent' => $running ? min( 100, (int) floor( (int) $state['done'] / $total * 100 ) ) : 100,
				/* translators: 1: posts scanned, 2: posts in total. */
				'status'  => sprintf( __( '%1$s of %2$s posts', 'link-audit-reports' ), number_format_i18n( (int) $state['done'] ), number_format_i18n( (int) $state['total'] ) ),
			)
		);
	}

	/**
	 * The links to one domain, for its expanded row.
	 *
	 * @return void
	 */
	public function ajax_details() {
		self::check_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check_ajax().
		$domain  = isset( $_POST['domain'] ) ? Link_Audit_Reports_Content::host( sanitize_text_field( wp_unslash( $_POST['domain'] ) ) ) : '';
		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$filters = self::filters_from( isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized and validated in filters_from().
		// phpcs:enable

		if ( '' === $domain ) {
			wp_send_json_error( array( 'message' => __( 'Unknown domain.', 'link-audit-reports' ) ) );
		}

		$total = Link_Audit_Reports_DB::count_domain_links( $domain, $filters );
		$rows  = Link_Audit_Reports_DB::domain_links( $domain, $filters, self::DETAILS_PER_LOAD, $offset );

		wp_send_json_success(
			array(
				'html' => $this->details_html( $domain, $rows, $total, $offset, $filters ),
				'rows' => $this->details_rows( $rows ),
				'next' => $offset + count( $rows ) < $total ? $offset + count( $rows ) : 0,
			)
		);
	}

	/**
	 * The expanded row: actions for the domain and its first links.
	 *
	 * @param string  $domain  Domain.
	 * @param array[] $rows    Links.
	 * @param int     $total   Links in all.
	 * @param int     $offset  Offset of $rows.
	 * @param array   $filters Filters.
	 * @return string
	 */
	private function details_html( $domain, array $rows, $total, $offset, array $filters ) {
		$filtered = (bool) array_intersect_key( $filters, array_flip( array( 'author', 'type', 'rel', 'from', 'to' ) ) );
		$actions  = array(
			'nofollow'  => array( 'link-2-off', __( 'Add nofollow', 'link-audit-reports' ) ),
			'sponsored' => array( 'badge-dollar-sign', __( 'Add sponsored', 'link-audit-reports' ) ),
			'ugc'       => array( 'message-square-text', __( 'Add ugc', 'link-audit-reports' ) ),
			'dofollow'  => array( 'link', __( 'Make dofollow', 'link-audit-reports' ) ),
			'strip'     => array( 'unlink', __( 'Strip links', 'link-audit-reports' ) ),
		);

		ob_start();
		?>
		<div class="lar-details-inner">
			<div class="lar-details-head">
				<span class="lar-details-title">
					<?php
					/* translators: 1: number of links, 2: domain. */
					echo esc_html( sprintf( _n( '%1$s link to %2$s', '%1$s links to %2$s', $total, 'link-audit-reports' ), number_format_i18n( $total ), $domain ) );
					?>
					<?php if ( $filtered ) : ?>
						<span class="lar-muted"><?php esc_html_e( '(matching your filters; changes apply to these only)', 'link-audit-reports' ); ?></span>
					<?php endif; ?>
				</span>
				<span class="lar-domain-actions">
					<?php foreach ( $actions as $action => $button ) : ?>
						<button type="button" class="button button-small" data-lar-action="<?php echo esc_attr( $action ); ?>">
							<?php echo self::icon( $button[0], 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
							<?php echo esc_html( $button[1] ); ?>
						</button>
					<?php endforeach; ?>
				</span>
			</div>
			<table class="lar-links">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Post', 'link-audit-reports' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Author', 'link-audit-reports' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Link text', 'link-audit-reports' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Rel', 'link-audit-reports' ); ?></th>
						<th scope="col"><?php esc_html_e( 'First seen', 'link-audit-reports' ); ?></th>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'link-audit-reports' ); ?></span></th>
					</tr>
				</thead>
				<tbody data-lar-links>
					<?php echo $this->details_rows( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in details_rows(). ?>
				</tbody>
			</table>
			<?php if ( $offset + count( $rows ) < $total ) : ?>
				<p class="lar-more-wrap">
					<button type="button" class="button" data-lar-more="<?php echo (int) ( $offset + count( $rows ) ); ?>">
						<?php echo self::icon( 'chevron-down', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?>
						<?php esc_html_e( 'Show more', 'link-audit-reports' ); ?>
					</button>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Table rows for links in an expanded domain.
	 *
	 * @param array[] $rows Links.
	 * @return string
	 */
	private function details_rows( array $rows ) {
		_prime_post_caches( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'post_id' ) ) ), false, false );
		cache_users( array_unique( array_map( 'intval', array_merge( wp_list_pluck( $rows, 'post_author' ), wp_list_pluck( $rows, 'added_by' ) ) ) ) );

		$html = '';

		foreach ( $rows as $row ) {
			$post   = get_post( (int) $row['post_id'] );
			$title  = $post ? get_the_title( $post ) : '';
			$title  = '' === trim( wp_strip_all_tags( $title ) ) ? __( '(no title)', 'link-audit-reports' ) : wp_strip_all_tags( $title );
			$edit   = $post ? get_edit_post_link( $post->ID, 'raw' ) : '';
			$view   = $post ? get_permalink( $post ) : '';
			$type   = $post ? get_post_type_object( $post->post_type ) : null;
			$status = $post && 'publish' !== $post->post_status ? get_post_status_object( $post->post_status ) : null;
			$status = $status ? $status->label : '';
			$by     = (int) $row['added_by'];
			$rel    = array(
				'follow'    => ! $row['is_nofollow'] && ! $row['is_sponsored'] && ! $row['is_ugc'] ? 1 : 0,
				'nofollow'  => (int) $row['is_nofollow'],
				'sponsored' => (int) $row['is_sponsored'],
				'ugc'       => (int) $row['is_ugc'],
			);

			ob_start();
			?>
			<tr>
				<td class="lar-link-post">
					<strong><?php echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ); ?></strong>
					<span class="lar-muted">
						<?php echo esc_html( $type ? $type->labels->singular_name : $row['post_type'] ); ?>
						<?php if ( $status ) : ?>
							<span class="lar-status is-draft"><?php echo esc_html( $status ); ?></span>
						<?php endif; ?>
					</span>
					<a class="lar-url" href="<?php echo esc_url( $row['url'] ); ?>" target="_blank" rel="noopener noreferrer nofollow" title="<?php echo esc_attr( $row['url'] ); ?>"><?php echo esc_html( preg_replace( '#^https?://(www\.)?#i', '', $row['url'] ) ); ?></a>
				</td>
				<td>
					<?php echo self::person( (int) $row['post_author'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in person(). ?>
					<?php if ( $by && $by !== (int) $row['post_author'] ) : ?>
						<?php $adder = get_userdata( $by ); ?>
						<span class="lar-muted lar-added-by">
							<?php
							/* translators: %s: user name. */
							echo esc_html( sprintf( __( 'Link added by %s', 'link-audit-reports' ), $adder ? $adder->display_name : __( 'a deleted user', 'link-audit-reports' ) ) );
							?>
						</span>
					<?php endif; ?>
				</td>
				<td class="lar-anchor">
					<?php if ( '' === (string) $row['anchor'] ) : ?>
						<span class="lar-muted"><?php esc_html_e( '(no text)', 'link-audit-reports' ); ?></span>
					<?php else : ?>
						<?php echo esc_html( $row['anchor'] ); ?>
					<?php endif; ?>
				</td>
				<td><span title="<?php echo esc_attr( '' === $row['rel'] ? __( 'No rel attribute', 'link-audit-reports' ) : 'rel="' . $row['rel'] . '"' ); ?>"><?php echo self::rel_pills( $rel, false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in rel_pills(). ?></span></td>
				<td><?php echo self::date( (int) $row['first_seen'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in date(). ?></td>
				<td class="lar-link-actions">
					<?php if ( $edit ) : ?>
						<a href="<?php echo esc_url( $edit ); ?>"><?php echo self::icon( 'square-pen', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'Edit', 'link-audit-reports' ); ?></a>
					<?php endif; ?>
					<?php if ( $view ) : ?>
						<a href="<?php echo esc_url( $view ); ?>" target="_blank" rel="noopener"><?php echo self::icon( 'external-link', 15 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Bundled SVG. ?><?php esc_html_e( 'View', 'link-audit-reports' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
			<?php
			$html .= (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * What a change would do, for the confirmation dialog.
	 *
	 * @return void
	 */
	public function ajax_preview() {
		self::check_ajax();

		list( $action, $domains, $filters ) = $this->change_from_request();

		if ( ! $domains ) {
			wp_send_json_error( array( 'message' => __( 'Select at least one domain first.', 'link-audit-reports' ) ) );
		}

		$preview  = $this->plugin->jobs()->preview( $action, $domains, $filters );
		$list     = Link_Audit_Reports_Jobs::domain_list( $domains );
		$filtered = (bool) $filters;
		$lines    = array();
		$confirm  = '';
		$disabled = false;
		/* translators: %s: number of links. */
		$links = sprintf( _n( '%s link', '%s links', $preview['change'], 'link-audit-reports' ), number_format_i18n( $preview['change'] ) );
		/* translators: %s: number of posts. */
		$posts = sprintf( _n( '%s post', '%s posts', $preview['posts'], 'link-audit-reports' ), number_format_i18n( $preview['posts'] ) );

		switch ( $action ) {
			case 'nofollow':
			case 'sponsored':
			case 'ugc':
				/* translators: 1: rel value, 2: "3 links", 3: domains, 4: "2 posts". */
				$lines[] = sprintf( __( 'This adds rel="%1$s" to %2$s to %3$s, in %4$s.', 'link-audit-reports' ), $action, $links, $list, $posts );
				if ( $preview['links'] > $preview['change'] ) {
					/* translators: %s: number of links. */
					$lines[] = sprintf( _n( '%s other link already has it.', '%s other links already have it.', $preview['links'] - $preview['change'], 'link-audit-reports' ), number_format_i18n( $preview['links'] - $preview['change'] ) );
				}
				/* translators: 1: rel value, 2: number of links. */
				$confirm = sprintf( __( 'Add %1$s to %2$s', 'link-audit-reports' ), $action, $links );
				break;

			case 'dofollow':
				/* translators: 1: "3 links", 2: domains, 3: "2 posts". */
				$lines[] = sprintf( __( 'This removes nofollow, sponsored and ugc from %1$s to %2$s, in %3$s. noopener and noreferrer stay.', 'link-audit-reports' ), $links, $list, $posts );
				if ( $preview['skipped'] ) {
					/* translators: %s: domains. */
					$lines[] = sprintf( __( 'Skipped %s: it\'s blacklisted with "add nofollow", so its links would get nofollow again on the next save.', 'link-audit-reports' ), Link_Audit_Reports_Jobs::domain_list( $preview['skipped'] ) );
				}
				/* translators: %s: number of links. */
				$confirm = sprintf( __( 'Make %s dofollow', 'link-audit-reports' ), $links );
				break;

			case 'strip':
				/* translators: 1: "3 links", 2: domains, 3: "2 posts". */
				$lines[] = sprintf( __( 'This removes %1$s to %2$s from %3$s. The link text stays in the post as plain text.', 'link-audit-reports' ), $links, $list, $posts );
				/* translators: %s: number of links. */
				$confirm = sprintf( __( 'Strip %s', 'link-audit-reports' ), $links );
				break;

			case 'whitelist':
				/* translators: %s: domains. */
				$lines[] = sprintf( __( 'Whitelisting hides %s (and its subdomains) from the report. Its links aren\'t changed, and you can find it again under "Whitelisted".', 'link-audit-reports' ), $list );
				$confirm = __( 'Whitelist', 'link-audit-reports' );
				break;

			case 'blacklist':
				/* translators: %s: domains. */
				$lines[] = sprintf( __( 'From now on, whenever a post is saved, links to %s (and its subdomains) are changed automatically, whoever adds them:', 'link-audit-reports' ), $list );
				$confirm = __( 'Blacklist', 'link-audit-reports' );
				break;
		}

		if ( in_array( $action, Link_Audit_Reports_Jobs::CONTENT_ACTIONS, true ) ) {
			if ( ! $preview['change'] ) {
				$lines    = array( __( 'None of these links need changing.', 'link-audit-reports' ) );
				$disabled = true;
			} elseif ( $filtered ) {
				$lines[] = __( 'Only links that match the report\'s current filters are changed.', 'link-audit-reports' );
			}
		}

		/* translators: 1: "3 links", 2: "2 posts". */
		$apply_text = __( 'Also change the %1$s already in %2$s', 'link-audit-reports' );
		$link_label = function ( $n ) {
			/* translators: %s: number of links. */
			return sprintf( _n( '%s link', '%s links', $n, 'link-audit-reports' ), number_format_i18n( $n ) );
		};
		$post_label = function ( $n ) {
			/* translators: %s: number of posts. */
			return sprintf( _n( '%s post', '%s posts', $n, 'link-audit-reports' ), number_format_i18n( $n ) );
		};

		wp_send_json_success(
			array(
				'lines'    => $lines,
				'confirm'  => $confirm,
				'disabled' => $disabled,
				'apply'    => 'blacklist' === $action ? array(
					'strip'    => sprintf( $apply_text, $link_label( $preview['strip'] ), $post_label( $preview['posts'] ) ),
					'nofollow' => sprintf( $apply_text, $link_label( $preview['nofollow'] ), $post_label( $preview['nofollow_posts'] ) ),
					'none'     => 0 === $preview['links'],
				) : null,
			)
		);
	}

	/**
	 * Start a change, or carry on the running one.
	 *
	 * @return void
	 */
	public function ajax_job() {
		self::check_ajax();

		$step = isset( $_POST['step'] ) ? sanitize_key( wp_unslash( $_POST['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check_ajax().
		$jobs = $this->plugin->jobs();

		if ( 'start' === $step ) {
			list( $action, $domains, $filters ) = $this->change_from_request();

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked in check_ajax().
			$options = array(
				'mode'  => isset( $_POST['mode'] ) && 'nofollow' === $_POST['mode'] ? 'nofollow' : 'strip',
				'apply' => ! empty( $_POST['apply'] ),
			);
			// phpcs:enable

			$id = $jobs->start( $action, $domains, $filters, $options );

			if ( is_wp_error( $id ) ) {
				wp_send_json_error( array( 'message' => $id->get_error_message() ) );
			}
		} else {
			$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check_ajax().
		}

		$progress = $jobs->step( $id, 4 );
		$log      = Link_Audit_Reports_DB::get_log( $id );

		if ( ! $log ) {
			wp_send_json_error( array( 'message' => __( 'That change no longer exists.', 'link-audit-reports' ) ) );
		}

		wp_send_json_success(
			array_merge(
				$progress,
				array(
					'id'      => (int) $id,
					/* translators: 1: posts done, 2: posts in total. */
					'text'    => sprintf( __( '%1$s of %2$s posts', 'link-audit-reports' ), number_format_i18n( $progress['done'] ), number_format_i18n( $progress['total'] ) ),
					'percent' => $progress['total'] ? (int) floor( $progress['done'] / $progress['total'] * 100 ) : 100,
				)
			)
		);
	}

	/**
	 * The action, domains and filters of a change request.
	 *
	 * @return array{0: string, 1: string[], 2: array}
	 */
	private function change_from_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Callers check the nonce.
		$action  = isset( $_POST['change'] ) ? sanitize_key( wp_unslash( $_POST['change'] ) ) : '';
		$domains = isset( $_POST['domains'] ) ? Link_Audit_Reports_Jobs::clean_domains( wp_unslash( (array) $_POST['domains'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each one is validated as a host in clean_domains().
		$filters = self::filters_from( isset( $_POST['filters'] ) && is_array( $_POST['filters'] ) ? wp_unslash( $_POST['filters'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized and validated in filters_from().
		// phpcs:enable

		if ( ! in_array( $action, Link_Audit_Reports_Jobs::actions(), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'link-audit-reports' ) ) );
		}

		return array( $action, $domains, array_diff_key( $filters, array_flip( array( 'search', 'view' ) ) ) );
	}

	// Forms ------------------------------------------------------------------------------

	/**
	 * Send a CSV export.
	 *
	 * @return void
	 */
	public function handle_export() {
		self::check_user();
		check_admin_referer( 'link_audit_reports_export' );

		$type = isset( $_GET['type'] ) && 'links' === $_GET['type'] ? 'links' : 'domains';

		Link_Audit_Reports_Export::send( $type, self::filters_from( wp_unslash( $_GET ) ) );
	}

	/**
	 * Add domains to a list, or take one off.
	 *
	 * @return void
	 */
	public function handle_lists() {
		self::check_user();
		check_admin_referer( 'link_audit_reports_lists' );

		$list   = isset( $_POST['list'] ) && 'blacklist' === $_POST['list'] ? 'blacklist' : 'whitelist';
		$do     = isset( $_POST['do'] ) && 'remove' === $_POST['do'] ? 'remove' : 'add';
		$raw    = isset( $_POST['domains'] ) ? sanitize_text_field( wp_unslash( $_POST['domains'] ) ) : '';
		$clean  = array();
		$failed = array();

		foreach ( preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $input ) {
			$domain = Link_Audit_Reports_Plugin::clean_domain( $input );

			if ( '' === $domain ) {
				$failed[] = $input;
			} else {
				$clean[] = $domain;
			}
		}

		$clean = array_slice( array_values( array_unique( $clean ) ), 0, Link_Audit_Reports_Jobs::MAX_DOMAINS );

		if ( $failed ) {
			/* translators: %s: what was typed. */
			self::flash( sprintf( __( 'These don\'t look like domains, so they weren\'t added: %s', 'link-audit-reports' ), implode( ', ', array_slice( $failed, 0, 10 ) ) ), 'error' );
		}

		if ( ! $clean ) {
			wp_safe_redirect( self::tab_url( 'lists' ) );
			exit;
		}

		if ( 'remove' === $do ) {
			$this->plugin->set_listed( $clean, 'none' );

			/* translators: %s: domains. */
			self::flash( sprintf( __( 'Removed %s from the list.', 'link-audit-reports' ), Link_Audit_Reports_Jobs::domain_list( $clean ) ) );
			wp_safe_redirect( self::tab_url( 'lists' ) );
			exit;
		}

		$result = $this->plugin->jobs()->start(
			$list,
			$clean,
			array(),
			array(
				'mode'  => isset( $_POST['mode'] ) && 'nofollow' === $_POST['mode'] ? 'nofollow' : 'strip',
				'apply' => 'blacklist' === $list && ! empty( $_POST['apply'] ),
			)
		);

		if ( is_wp_error( $result ) ) {
			self::flash( $result->get_error_message(), 'error' );
			wp_safe_redirect( self::tab_url( 'lists' ) );
			exit;
		}

		$log = Link_Audit_Reports_DB::get_log( $result );

		// A blacklist that's changing existing links: show it running in the log.
		wp_safe_redirect( $log && 'running' === $log['status'] ? self::tab_url( 'log' ) : self::tab_url( 'lists', array( 'lar_done' => $result ) ) );
		exit;
	}

	/**
	 * Start a full rescan.
	 *
	 * @return void
	 */
	public function handle_rescan() {
		self::check_user();
		check_admin_referer( 'link_audit_reports_rescan' );

		Link_Audit_Reports_Scanner::start();

		wp_safe_redirect( self::tab_url() );
		exit;
	}

	/**
	 * Start undoing a change.
	 *
	 * @return void
	 */
	public function handle_undo() {
		self::check_user();

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'link_audit_reports_undo_' . $id );

		$result = $this->plugin->jobs()->start_undo( $id );

		if ( is_wp_error( $result ) ) {
			self::flash( $result->get_error_message(), 'error' );
		}

		wp_safe_redirect( self::tab_url( 'log' ) );
		exit;
	}
}
