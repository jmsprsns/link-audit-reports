<?php
/**
 * Keeps the link index current.
 *
 * - A full scan reads every audited post, in short batches: on WP-Cron in the
 *   background, and faster while the report is open in a browser. It runs
 *   once on activation, after the settings change, and on "Rescan".
 * - Every save rescans that post straight away, so the report is always up
 *   to date without another full scan.
 * - Every save also applies the blacklist: links to blacklisted domains are
 *   stripped (their text stays) or get rel="nofollow" before the post is
 *   stored.
 *
 * Scanning only reads posts. The blacklist is the only part that changes
 * content on its own, and only for domains someone has blacklisted.
 *
 * @package Link_Audit_Reports
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scanner.
 */
final class Link_Audit_Reports_Scanner {

	/**
	 * Option holding the full scan's progress.
	 */
	const STATE_OPTION = 'link_audit_reports_scan';

	/**
	 * Option used as the scan lock.
	 */
	const LOCK_OPTION = 'link_audit_reports_scan_lock';

	/**
	 * Cron hook for background batches.
	 */
	const BATCH_HOOK = 'link_audit_reports_scan_batch';

	/**
	 * Seconds a background batch may run.
	 */
	const CRON_BUDGET = 20;

	/**
	 * Seconds before a lock is considered abandoned (a batch that crashed).
	 */
	const LOCK_TTL = 120;

	/**
	 * Posts read per query.
	 */
	const CHUNK = 50;

	/**
	 * Plugin instance.
	 *
	 * @var Link_Audit_Reports_Plugin
	 */
	private $plugin;

	/**
	 * True while this plugin saves a post itself, so the save isn't treated
	 * as an edit (no blacklist pass, no rescan as the user's own edit).
	 *
	 * @var bool
	 */
	private $saving = false;

	/**
	 * Constructor.
	 *
	 * @param Link_Audit_Reports_Plugin $plugin Plugin instance.
	 */
	public function __construct( Link_Audit_Reports_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( self::BATCH_HOOK, array( $this, 'cron_batch' ) );
		add_action( 'save_post', array( $this, 'post_saved' ), 20, 2 );
		add_action( 'deleted_post', array( 'Link_Audit_Reports_DB', 'delete_post' ) );
		add_action( 'trashed_post', array( 'Link_Audit_Reports_DB', 'delete_post' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'apply_blacklist' ), 99, 2 );
	}

	// Full scan -----------------------------------------------------------------

	/**
	 * Default scan state.
	 *
	 * @return array<string,int|string>
	 */
	private static function default_state() {
		return array(
			'status'   => 'idle', // idle or running.
			'last_id'  => 0,      // Highest post ID scanned so far.
			'total'    => 0,      // Posts to scan.
			'done'     => 0,      // Posts scanned.
			'started'  => 0,      // Unix time the scan started.
			'finished' => 0,      // Unix time the last scan finished.
		);
	}

	/**
	 * The full scan's progress, read fresh from the database.
	 *
	 * @return array<string,int|string>
	 */
	public static function get_state() {
		wp_cache_delete( self::STATE_OPTION, 'options' );

		$state = get_option( self::STATE_OPTION, array() );

		return wp_parse_args( is_array( $state ) ? $state : array(), self::default_state() );
	}

	/**
	 * Start a full scan of every audited post (or restart the one running).
	 *
	 * @return array New state.
	 */
	public static function start() {
		$state = array_merge(
			self::get_state(),
			array(
				'status'  => 'running',
				'last_id' => 0,
				'total'   => self::count_posts(),
				'done'    => 0,
				'started' => time(),
			)
		);

		update_option( self::STATE_OPTION, $state, false );
		self::schedule( 0 );

		return $state;
	}

	/**
	 * Schedule the next background batch, unless one is already due sooner.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	public static function schedule( $delay ) {
		$when = time() + max( 0, (int) $delay );
		$next = wp_next_scheduled( self::BATCH_HOOK );

		if ( $next && $next <= $when ) {
			return;
		}

		if ( $next ) {
			wp_unschedule_event( $next, self::BATCH_HOOK );
		}

		wp_schedule_single_event( $when, self::BATCH_HOOK );
	}

	/**
	 * Clear the scheduled batch (deactivation).
	 *
	 * @return void
	 */
	public static function unschedule() {
		wp_clear_scheduled_hook( self::BATCH_HOOK );
	}

	/**
	 * Cron callback: run one background batch.
	 *
	 * @return void
	 */
	public function cron_batch() {
		$state = $this->run( self::CRON_BUDGET );

		if ( 'running' === $state['status'] ) {
			self::schedule( 0 );
		}
	}

	/**
	 * Scan posts until the time is up or the scan is done.
	 *
	 * @param int $budget Seconds.
	 * @return array Scan state afterwards.
	 */
	public function run( $budget ) {
		$state = self::get_state();

		if ( 'running' !== $state['status'] || ! $this->lock() ) {
			return $state;
		}

		$deadline = microtime( true ) + max( 1, (int) $budget );
		$started  = (int) $state['started'];

		while ( microtime( true ) < $deadline ) {
			$ids = self::next_posts( (int) $state['last_id'], self::CHUNK );

			if ( ! $ids ) {
				$state['status']   = 'idle';
				$state['finished'] = time();
				$state['done']     = max( (int) $state['done'], (int) $state['total'] );
				Link_Audit_Reports_DB::delete_unscanned( $started );
				break;
			}

			_prime_post_caches( $ids, false, false );

			foreach ( $ids as $post_id ) {
				$this->scan_post( $post_id, 'full' );

				$state['last_id'] = $post_id;
				$state['done']    = (int) $state['done'] + 1;

				if ( microtime( true ) >= $deadline ) {
					break 2;
				}
			}

			// Keep memory flat on big sites.
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		}

		// "Rescan" may have started a new scan while this batch ran: the new one wins.
		if ( (int) self::get_state()['started'] === $started ) {
			update_option( self::STATE_OPTION, $state, false );
		} else {
			$state = self::get_state();
		}

		$this->unlock();

		return $state;
	}

	/**
	 * Take the scan lock. An atomic insert, like core's upgrader lock, so two
	 * batches can't both get it.
	 *
	 * @return bool
	 */
	private function lock() {
		return self::take_lock( self::LOCK_OPTION, self::LOCK_TTL );
	}

	/**
	 * Release the scan lock.
	 *
	 * @return void
	 */
	private function unlock() {
		self::release_lock( self::LOCK_OPTION );
	}

	/**
	 * Take a lock stored as an option.
	 *
	 * @param string $option Option name.
	 * @param int    $ttl    Seconds before an abandoned lock can be taken over.
	 * @return bool Whether this request holds it.
	 */
	public static function take_lock( $option, $ttl ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, (string) time() ) );

		if ( ! $taken ) {
			$held = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) );

			if ( $held > time() - (int) $ttl ) {
				return false;
			}

			// Abandoned by a request that died: take it over, unless another just did.
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), $option, (string) $held ) );
		}
		// phpcs:enable

		wp_cache_delete( $option, 'options' );

		return (bool) $taken;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function release_lock( $option ) {
		global $wpdb;

		$wpdb->delete( $wpdb->options, array( 'option_name' => $option ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $option, 'options' );
	}

	/**
	 * SQL for the posts that get scanned.
	 *
	 * @return string Prepared WHERE conditions.
	 */
	private static function posts_where() {
		global $wpdb;

		$plugin   = link_audit_reports_plugin();
		$types    = $plugin->post_types();
		$types    = $types ? $types : array( '' );
		$statuses = $plugin->statuses();

		return $wpdb->prepare(
			'post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')', // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built to match.
			array_merge( $types, $statuses )
		);
	}

	/**
	 * Number of posts a full scan covers.
	 *
	 * @return int
	 */
	private static function count_posts() {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE " . self::posts_where() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in posts_where().
	}

	/**
	 * The next posts to scan, by ID.
	 *
	 * @param int $after_id Last post ID scanned.
	 * @param int $limit    How many.
	 * @return int[]
	 */
	private static function next_posts( $after_id, $limit ) {
		global $wpdb;

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND " . self::posts_where() . ' ORDER BY ID ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in posts_where().
				(int) $after_id,
				(int) $limit
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	// One post ------------------------------------------------------------------

	/**
	 * Read a post's links into the index (or drop them if it isn't audited).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $mode    save, full or job (see Link_Audit_Reports_DB::sync_post()).
	 * @return int Outbound links found.
	 */
	public function scan_post( $post_id, $mode = 'save' ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->plugin->audits( $post ) ) {
			Link_Audit_Reports_DB::delete_post( $post_id );
			return 0;
		}

		$links = Link_Audit_Reports_Content::outbound( $post->post_content, $this->plugin->internal_hosts() );

		Link_Audit_Reports_DB::sync_post( $post, $links, $mode, get_current_user_id() );

		return count( $links );
	}

	/**
	 * Rescan a post when it's saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 * @return void
	 */
	public function post_saved( $post_id, $post ) {
		if ( $this->saving || ! $post instanceof WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// Menus, orders and other types that aren't audited never have rows.
		if ( ! in_array( $post->post_type, $this->plugin->post_types(), true ) ) {
			return;
		}

		$this->scan_post( $post_id, 'save' );
	}

	/**
	 * Run a callback while this plugin saves posts itself.
	 *
	 * @param callable $callback Callback.
	 * @return mixed Its return value.
	 */
	public function while_saving( $callback ) {
		$this->saving = true;

		try {
			return call_user_func( $callback );
		} finally {
			$this->saving = false;
		}
	}

	// Blacklist -----------------------------------------------------------------

	/**
	 * Strip or nofollow links to blacklisted domains before a post is stored.
	 *
	 * Runs after kses, and only removes links or adds rel="nofollow", so it
	 * can never add anything unsafe to a post.
	 *
	 * @param array $data    Slashed post fields about to be saved.
	 * @param array $postarr Raw post data.
	 * @return array
	 */
	public function apply_blacklist( $data, $postarr ) {
		if ( $this->saving || ! is_array( $data ) || empty( $data['post_content'] ) || empty( $data['post_type'] ) ) {
			return $data;
		}

		if ( ! in_array( $data['post_type'], $this->plugin->post_types(), true ) || in_array( isset( $data['post_status'] ) ? $data['post_status'] : '', array( 'auto-draft', 'inherit', 'trash' ), true ) ) {
			return $data;
		}

		if ( ! $this->plugin->blacklist() ) {
			return $data;
		}

		// A post the site has left out of the audit (link_audit_reports_skip_post).
		$existing = empty( $postarr['ID'] ) ? null : get_post( (int) $postarr['ID'] );
		if ( $existing instanceof WP_Post && apply_filters( 'link_audit_reports_skip_post', false, $existing ) ) {
			return $data;
		}

		$content = wp_unslash( $data['post_content'] );
		$hosts   = $this->plugin->internal_hosts();
		$plugin  = $this->plugin;
		$found   = array();

		foreach ( Link_Audit_Reports_Plugin::BLACKLIST_MODES as $mode ) {
			$content = Link_Audit_Reports_Content::transform(
				$content,
				$mode,
				function ( $link ) use ( $plugin, $mode, &$found ) {
					$entry = $plugin->listed( $link['domain'] );

					if ( ! $entry || 'blacklist' !== $entry['list'] || $mode !== $entry['mode'] ) {
						return false;
					}

					// Already nofollowed: nothing to do.
					if ( 'nofollow' === $mode && $link['nofollow'] ) {
						return false;
					}

					$found[ $entry['domain'] ] = $mode;

					return true;
				},
				$hosts,
				$changes
			);
		}

		if ( ! $found ) {
			return $data;
		}

		$data['post_content'] = wp_slash( $content );

		$user = get_current_user_id();
		if ( $user ) {
			set_transient( 'link_audit_reports_blacklisted_' . $user, $found, 10 * MINUTE_IN_SECONDS );
		}

		return $data;
	}
}
