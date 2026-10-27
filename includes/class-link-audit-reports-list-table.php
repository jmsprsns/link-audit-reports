<?php
/**
 * The domains report: one row per linked domain.
 *
 * Column and row action keys are prefixed: WordPress styles some generic
 * ones on every admin page (a ".column-posts" cell is centered, an
 * ".approve" link is hidden).
 *
 * @package Link_Audit_Reports
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Domains list.
 */
final class Link_Audit_Reports_List_Table extends WP_List_Table {

	/**
	 * Screen option holding rows per page.
	 */
	const PER_PAGE_OPTION = 'link_audit_reports_per_page';

	/**
	 * Views => label.
	 *
	 * @var array<string,string>
	 */
	private $views = array();

	/**
	 * Report filters (cleaned), including search and view.
	 *
	 * @var array
	 */
	private $filters;

	/**
	 * Plugin instance.
	 *
	 * @var Link_Audit_Reports_Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param array                     $filters Report filters.
	 * @param Link_Audit_Reports_Plugin $plugin  Plugin instance.
	 */
	public function __construct( array $filters, Link_Audit_Reports_Plugin $plugin ) {
		$this->filters = $filters;
		$this->plugin  = $plugin;
		$this->views   = array(
			'active'      => __( 'Active', 'link-audit-reports' ),
			'whitelisted' => __( 'Whitelisted', 'link-audit-reports' ),
			'blacklisted' => __( 'Blacklisted', 'link-audit-reports' ),
		);

		parent::__construct(
			array(
				'singular' => 'domain',
				'plural'   => 'domains',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'cb'          => '<input type="checkbox">',
			'lar_domain'  => __( 'Domain', 'link-audit-reports' ),
			'lar_links'   => __( 'Links', 'link-audit-reports' ),
			'lar_posts'   => __( 'Posts', 'link-audit-reports' ),
			'lar_rel'     => __( 'Rel attributes', 'link-audit-reports' ),
			'lar_authors' => __( 'Added by', 'link-audit-reports' ),
			'lar_first'   => __( 'First seen', 'link-audit-reports' ),
			'lar_last'    => __( 'Last seen', 'link-audit-reports' ),
		);
	}

	/**
	 * Sortable columns => array( orderby, starts descending ).
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'lar_domain' => array( 'domain', false ),
			'lar_links'  => array( 'links', true ),
			'lar_posts'  => array( 'posts', true ),
			'lar_rel'    => array( 'rel', true ),
			'lar_first'  => array( 'first', true ),
			'lar_last'   => array( 'last', true ),
		);
	}

	/**
	 * The column holding the row actions.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'lar_domain';
	}

	/**
	 * Bulk actions.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array(
			'nofollow'  => __( 'Add nofollow', 'link-audit-reports' ),
			'sponsored' => __( 'Add sponsored', 'link-audit-reports' ),
			'ugc'       => __( 'Add ugc', 'link-audit-reports' ),
			'dofollow'  => __( 'Make dofollow', 'link-audit-reports' ),
			'strip'     => __( 'Strip links, keep text', 'link-audit-reports' ),
			'whitelist' => __( 'Whitelist', 'link-audit-reports' ),
			'blacklist' => __( 'Blacklist', 'link-audit-reports' ),
		);
	}

	/**
	 * Views above the table.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$current = isset( $this->filters['view'] ) ? $this->filters['view'] : 'active';
		$links   = array();

		foreach ( $this->views as $view => $label ) {
			$count = Link_Audit_Reports_DB::count_domains( array_merge( $this->filters, array( 'view' => $view ) ) );

			if ( 'active' !== $view && ! $count && $current !== $view ) {
				continue;
			}

			$links[ $view ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( add_query_arg( 'lar_view', $view, Link_Audit_Reports_Admin::report_url( $this->filters, array( 'lar_view' ) ) ) ),
				$current === $view ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $links;
	}

	/**
	 * Load the rows for this page.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( self::PER_PAGE_OPTION, 25 );
		$orderby  = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'links'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sorting.
		$order    = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'asc' : 'desc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sorting.
		$total    = Link_Audit_Reports_DB::count_domains( $this->filters );
		$page     = min( $this->get_pagenum(), max( 1, (int) ceil( $total / $per_page ) ) );

		$this->items = Link_Audit_Reports_DB::domains( $this->filters, $orderby, $order, $per_page, ( $page - 1 ) * $per_page );

		// Look up every author on the page at once.
		$users = array();
		foreach ( $this->items as $item ) {
			$users = array_merge( $users, array_slice( array_filter( array_map( 'intval', explode( ',', (string) $item['authors'] ) ) ), 0, 3 ) );
		}
		if ( $users ) {
			cache_users( array_unique( $users ) );
		}

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_default_primary_column_name() );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Message when there are no rows.
	 *
	 * @return void
	 */
	public function no_items() {
		$state    = Link_Audit_Reports_Scanner::get_state();
		$filtered = array_diff_key( $this->filters, array( 'view' => true ) );

		if ( 'running' === $state['status'] && ! $state['finished'] ) {
			esc_html_e( 'Your content is being scanned. Domains appear here as their links are found.', 'link-audit-reports' );
		} elseif ( $filtered || 'active' !== $this->filters['view'] ) {
			esc_html_e( 'No domains match these filters.', 'link-audit-reports' );
		} else {
			esc_html_e( 'No outbound links found yet.', 'link-audit-reports' );
		}
	}

	/**
	 * Filters next to the bulk actions.
	 *
	 * @param string $which top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}

		$f       = $this->filters;
		$authors = Link_Audit_Reports_DB::authors();
		cache_users( $authors );
		?>
		<div class="alignleft actions lar-filters">
			<label class="screen-reader-text" for="lar-filter-author"><?php esc_html_e( 'Filter by author', 'link-audit-reports' ); ?></label>
			<select name="lar_author" id="lar-filter-author">
				<option value=""><?php esc_html_e( 'All authors', 'link-audit-reports' ); ?></option>
				<?php
				$names = array();
				foreach ( $authors as $user_id ) {
					$user              = get_userdata( $user_id );
					$names[ $user_id ] = $user ? $user->display_name : '#' . $user_id;
				}
				natcasesort( $names );
				foreach ( $names as $user_id => $name ) {
					printf( '<option value="%1$d"%2$s>%3$s</option>', (int) $user_id, selected( isset( $f['author'] ) ? (int) $f['author'] : 0, (int) $user_id, false ), esc_html( $name ) );
				}
				?>
			</select>

			<label class="screen-reader-text" for="lar-filter-type"><?php esc_html_e( 'Filter by post type', 'link-audit-reports' ); ?></label>
			<select name="lar_type" id="lar-filter-type">
				<option value=""><?php esc_html_e( 'All post types', 'link-audit-reports' ); ?></option>
				<?php foreach ( Link_Audit_Reports_Plugin::available_post_types() as $type => $label ) : ?>
					<?php if ( in_array( $type, $this->plugin->post_types(), true ) ) : ?>
						<option value="<?php echo esc_attr( $type ); ?>"<?php selected( isset( $f['type'] ) ? $f['type'] : '', $type ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endif; ?>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="lar-filter-rel"><?php esc_html_e( 'Filter by rel attribute', 'link-audit-reports' ); ?></label>
			<select name="lar_rel" id="lar-filter-rel">
				<option value=""><?php esc_html_e( 'Any rel', 'link-audit-reports' ); ?></option>
				<?php
				$rels = array(
					'follow'    => __( 'Follow only', 'link-audit-reports' ),
					'nofollow'  => __( 'Nofollow', 'link-audit-reports' ),
					'sponsored' => __( 'Sponsored', 'link-audit-reports' ),
					'ugc'       => __( 'UGC', 'link-audit-reports' ),
				);
				foreach ( $rels as $rel => $label ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $rel ), selected( isset( $f['rel'] ) ? $f['rel'] : '', $rel, false ), esc_html( $label ) );
				}
				?>
			</select>

			<span class="lar-dates">
				<label for="lar-filter-from"><?php esc_html_e( 'First seen', 'link-audit-reports' ); ?></label>
				<input type="date" name="lar_from" id="lar-filter-from" value="<?php echo esc_attr( empty( $f['from'] ) ? '' : wp_date( 'Y-m-d', $f['from'] ) ); ?>">
				<label for="lar-filter-to"><?php esc_html_e( 'to', 'link-audit-reports' ); ?></label>
				<input type="date" name="lar_to" id="lar-filter-to" value="<?php echo esc_attr( empty( $f['to'] ) ? '' : wp_date( 'Y-m-d', $f['to'] ) ); ?>">
			</span>

			<?php submit_button( __( 'Filter', 'link-audit-reports' ), '', 'filter_action', false, array( 'id' => 'lar-filter-submit' ) ); ?>

			<?php if ( array_intersect_key( $f, array_flip( array( 'author', 'type', 'rel', 'from', 'to' ) ) ) ) : ?>
				<a class="lar-reset" href="<?php echo esc_url( Link_Audit_Reports_Admin::report_url( array( 'view' => $f['view'] ) ) ); ?>"><?php esc_html_e( 'Reset', 'link-audit-reports' ); ?></a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * One row, tagged with its domain for the script.
	 *
	 * @param array $item Row.
	 * @return void
	 */
	public function single_row( $item ) {
		printf( '<tr class="lar-row" data-lar-domain="%s">', esc_attr( $item['domain'] ) );
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf(
			'<label class="screen-reader-text" for="lar-cb-%1$s">%2$s</label><input type="checkbox" name="domain[]" id="lar-cb-%1$s" value="%3$s">',
			esc_attr( md5( $item['domain'] ) ),
			/* translators: %s: domain. */
			esc_html( sprintf( __( 'Select %s', 'link-audit-reports' ), $item['domain'] ) ),
			esc_attr( $item['domain'] )
		);
	}

	/**
	 * Domain column: the expand toggle, list badges and row actions.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_lar_domain( $item ) {
		$domain = $item['domain'];
		$entry  = $this->plugin->listed( $domain );
		$badge  = '';

		if ( $entry && 'whitelist' === $entry['list'] ) {
			$badge = '<span class="lar-badge is-white">' . Link_Audit_Reports_Admin::icon( 'shield-check', 13 ) . esc_html__( 'Whitelisted', 'link-audit-reports' ) . '</span>';
		} elseif ( $entry ) {
			$badge = '<span class="lar-badge is-black">' . Link_Audit_Reports_Admin::icon( 'shield-ban', 13 ) . esc_html( 'nofollow' === $entry['mode'] ? __( 'Blacklisted: nofollow', 'link-audit-reports' ) : __( 'Blacklisted: strip', 'link-audit-reports' ) ) . '</span>';
		}

		$toggle = sprintf(
			'<button type="button" class="lar-toggle" aria-expanded="false" data-lar-toggle>%1$s<span class="lar-domain-name">%2$s</span></button>',
			Link_Audit_Reports_Admin::icon( 'chevron-right', 16, 'lar-chevron' ),
			esc_html( $domain )
		);

		$actions = array(
			'lar_show'      => sprintf( '<a href="#" data-lar-toggle>%s</a>', esc_html__( 'Show links', 'link-audit-reports' ) ),
			'lar_visit'     => sprintf( '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span></a>', esc_url( 'https://' . $domain . '/' ), esc_html__( 'Visit', 'link-audit-reports' ), esc_html__( '(opens in a new tab)', 'link-audit-reports' ) ),
			'lar_whitelist' => sprintf( '<a href="#" data-lar-action="whitelist">%s</a>', esc_html__( 'Whitelist', 'link-audit-reports' ) ),
			'lar_blacklist' => sprintf( '<a href="#" data-lar-action="blacklist">%s</a>', esc_html__( 'Blacklist', 'link-audit-reports' ) ),
		);

		if ( $entry && 'whitelist' === $entry['list'] ) {
			unset( $actions['lar_whitelist'] );
		} elseif ( $entry ) {
			unset( $actions['lar_blacklist'] );
		}

		return '<span class="lar-domain">' . Link_Audit_Reports_Admin::icon( 'globe', 16, 'lar-domain-icon' ) . $toggle . $badge . '</span>' . $this->row_actions( $actions );
	}

	/**
	 * Links column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_lar_links( $item ) {
		return '<strong>' . esc_html( number_format_i18n( (int) $item['links'] ) ) . '</strong>';
	}

	/**
	 * Posts column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_lar_posts( $item ) {
		return esc_html( number_format_i18n( (int) $item['posts'] ) );
	}

	/**
	 * Rel column: how many links have each value.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_lar_rel( $item ) {
		return Link_Audit_Reports_Admin::rel_pills(
			array(
				'follow'    => (int) $item['follow'],
				'nofollow'  => (int) $item['nofollow'],
				'sponsored' => (int) $item['sponsored'],
				'ugc'       => (int) $item['ugc'],
			),
			(int) $item['links'] > 1
		);
	}

	/**
	 * Authors column: who added the links.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_lar_authors( $item ) {
		$ids   = array_slice( array_filter( array_map( 'intval', explode( ',', (string) $item['authors'] ) ) ), 0, 3 );
		$total = (int) $item['author_count'];
		$out   = '';

		foreach ( $ids as $id ) {
			$out .= Link_Audit_Reports_Admin::person( $id );
		}

		if ( $total > count( $ids ) ) {
			/* translators: %s: number of other authors. */
			$out .= '<span class="lar-more">' . esc_html( sprintf( __( '+%s more', 'link-audit-reports' ), number_format_i18n( $total - count( $ids ) ) ) ) . '</span>';
		}

		return '' === $out ? '<span class="lar-muted">' . esc_html__( 'Unknown', 'link-audit-reports' ) . '</span>' : '<span class="lar-people">' . $out . '</span>';
	}

	/**
	 * First seen column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_lar_first( $item ) {
		return Link_Audit_Reports_Admin::date( (int) $item['first_seen'] );
	}

	/**
	 * Last seen column.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function column_lar_last( $item ) {
		return Link_Audit_Reports_Admin::date( (int) $item['last_seen'] );
	}
}
