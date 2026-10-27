<?php
/**
 * Runs the changes made from the report, and undoes them.
 *
 * A change (add nofollow, sponsored or ugc, make dofollow, strip links,
 * whitelist, blacklist) is logged before it starts and then works through
 * the posts it affects in short batches: while the report is open, and on
 * WP-Cron if the browser goes away. One change runs at a time.
 *
 * Every post it edits is saved through wp_update_post(), so caches are
 * cleared and a revision is kept, and the log records exactly which spots
 * changed and what was there. Undo puts back those spots only, so edits made
 * to the post since are kept.
 *
 * @package Link_Audit_Reports
 */

defined( 'ABSPATH' ) || exit;

/**
 * Change runner.
 */
final class Link_Audit_Reports_Jobs {

	/**
	 * Actions that change links in posts.
	 */
	const CONTENT_ACTIONS = array( 'nofollow', 'sponsored', 'ugc', 'dofollow', 'strip' );

	/**
	 * Actions that change the lists.
	 */
	const LIST_ACTIONS = array( 'whitelist', 'blacklist' );

	/**
	 * Most domains one change can cover.
	 */
	const MAX_DOMAINS = 500;

	/**
	 * Option used as the lock.
	 */
	const LOCK_OPTION = 'link_audit_reports_job_lock';

	/**
	 * Cron hook that carries on a change in the background.
	 */
	const BATCH_HOOK = 'link_audit_reports_job_batch';

	/**
	 * Seconds a background batch may run, and before a lock is abandoned.
	 */
	const CRON_BUDGET = 20;
	const LOCK_TTL    = 120;

	/**
	 * Plugin instance.
	 *
	 * @var Link_Audit_Reports_Plugin
	 */
	private $plugin;

	/**
	 * Scanner.
	 *
	 * @var Link_Audit_Reports_Scanner
	 */
	private $scanner;

	/**
	 * Constructor.
	 *
	 * @param Link_Audit_Reports_Plugin  $plugin  Plugin instance.
	 * @param Link_Audit_Reports_Scanner $scanner Scanner.
	 */
	public function __construct( Link_Audit_Reports_Plugin $plugin, Link_Audit_Reports_Scanner $scanner ) {
		$this->plugin  = $plugin;
		$this->scanner = $scanner;

		add_action( self::BATCH_HOOK, array( $this, 'cron_batch' ) );
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
	 * Every action.
	 *
	 * @return string[]
	 */
	public static function actions() {
		return array_merge( self::CONTENT_ACTIONS, self::LIST_ACTIONS );
	}

	// Input -------------------------------------------------------------------

	/**
	 * Clean a list of domains from the report.
	 *
	 * @param mixed $domains Domains.
	 * @return string[]
	 */
	public static function clean_domains( $domains ) {
		$clean = array();

		foreach ( array_slice( (array) $domains, 0, self::MAX_DOMAINS ) as $domain ) {
			$domain = is_string( $domain ) ? Link_Audit_Reports_Content::host( $domain ) : '';

			if ( '' !== $domain ) {
				$clean[ $domain ] = true;
			}
		}

		return array_keys( $clean );
	}

	/**
	 * Clean the report's filters.
	 *
	 * @param mixed $filters Raw filters: author, type, rel, from, to (Y-m-d).
	 * @return array See Link_Audit_Reports_DB::where().
	 */
	public static function clean_filters( $filters ) {
		$filters = is_array( $filters ) ? $filters : array();
		$clean   = array();

		if ( ! empty( $filters['author'] ) ) {
			$clean['author'] = absint( $filters['author'] );
		}

		if ( ! empty( $filters['type'] ) && is_string( $filters['type'] ) && post_type_exists( $filters['type'] ) ) {
			$clean['type'] = $filters['type'];
		}

		if ( ! empty( $filters['rel'] ) && in_array( $filters['rel'], array( 'follow', 'nofollow', 'sponsored', 'ugc' ), true ) ) {
			$clean['rel'] = $filters['rel'];
		}

		foreach ( array( 'from', 'to' ) as $key ) {
			$time = isset( $filters[ $key ] ) ? self::day( $filters[ $key ], 'to' === $key ) : 0;
			if ( $time ) {
				$clean[ $key ] = $time;
			}
		}

		return $clean;
	}

	/**
	 * A Y-m-d date (site time zone) as a Unix time.
	 *
	 * @param mixed $date Date.
	 * @param bool  $end  The end of that day instead of its start.
	 * @return int 0 if it isn't a valid date.
	 */
	public static function day( $date, $end = false ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return 0;
		}

		try {
			$time = new DateTime( $date . ( $end ? ' 23:59:59' : ' 00:00:00' ), wp_timezone() );
		} catch ( Exception $e ) {
			return 0;
		}

		return $time->getTimestamp();
	}

	// Preview -----------------------------------------------------------------

	/**
	 * What a change would do, for the confirmation dialog.
	 *
	 * @param string   $action  Action.
	 * @param string[] $domains Domains (cleaned).
	 * @param array    $filters Filters (cleaned).
	 * @return array{links: int, posts: int, change: int, strip: int, nofollow: int, nofollow_posts: int, skipped: string[]}
	 */
	public function preview( $action, array $domains, array $filters ) {
		$skipped = 'dofollow' === $action ? $this->nofollow_blacklisted( $domains ) : array();
		$domains = array_values( array_diff( $domains, $skipped ) );
		$result  = array(
			'links'          => 0,
			'posts'          => 0,
			'change'         => 0,
			'strip'          => 0,
			'nofollow'       => 0,
			'nofollow_posts' => 0,
			'skipped'        => $skipped,
		);

		if ( in_array( $action, self::CONTENT_ACTIONS, true ) ) {
			$result = array_merge( $result, Link_Audit_Reports_DB::target_counts( $action, $domains, $filters, false ) );
		} elseif ( 'blacklist' === $action ) {
			$strip    = Link_Audit_Reports_DB::target_counts( 'strip', $domains, array(), true );
			$nofollow = Link_Audit_Reports_DB::target_counts( 'nofollow', $domains, array(), true );

			$result['links']          = $strip['links'];
			$result['posts']          = $strip['posts'];
			$result['strip']          = $strip['change'];
			$result['nofollow']       = $nofollow['change'];
			$result['nofollow_posts'] = $nofollow['posts'];
		}

		return $result;
	}

	/**
	 * The domains that are blacklisted with "add nofollow": making their
	 * links dofollow would be reversed on the next save.
	 *
	 * @param string[] $domains Domains.
	 * @return string[]
	 */
	private function nofollow_blacklisted( array $domains ) {
		$listed = array();

		foreach ( $domains as $domain ) {
			$entry = $this->plugin->listed( $domain );
			if ( $entry && 'blacklist' === $entry['list'] && 'nofollow' === $entry['mode'] ) {
				$listed[] = $domain;
			}
		}

		return $listed;
	}

	// Start -------------------------------------------------------------------

	/**
	 * Log a change and start it.
	 *
	 * @param string   $action  Action.
	 * @param string[] $domains Domains (cleaned).
	 * @param array    $filters Filters (cleaned).
	 * @param array    $options mode (blacklist: strip or nofollow) and apply
	 *                          (blacklist: also change existing links now).
	 * @return int|WP_Error Log entry ID.
	 */
	public function start( $action, array $domains, array $filters, array $options = array() ) {
		if ( ! in_array( $action, self::actions(), true ) ) {
			return new WP_Error( 'link_audit_reports_action', __( 'Unknown action.', 'link-audit-reports' ) );
		}

		if ( 'dofollow' === $action ) {
			$skipped = $this->nofollow_blacklisted( $domains );
			$domains = array_values( array_diff( $domains, $skipped ) );
		}

		if ( ! $domains ) {
			if ( ! empty( $skipped ) ) {
				return new WP_Error( 'link_audit_reports_blacklisted', __( 'Those domains are blacklisted with "add nofollow", so their links would get nofollow again on the next save. Take them off the blacklist first.', 'link-audit-reports' ) );
			}

			return new WP_Error( 'link_audit_reports_domains', __( 'Choose at least one domain first.', 'link-audit-reports' ) );
		}

		if ( Link_Audit_Reports_DB::running_log() ) {
			return new WP_Error( 'link_audit_reports_busy', __( 'Another change is still running. Wait for it to finish, then try again.', 'link-audit-reports' ) );
		}

		$data = array(
			'content'    => '',
			'subdomains' => false,
			'pending'    => array(),
			'total'      => 0,
			'posts'      => array(),
			'skipped'    => isset( $skipped ) ? $skipped : array(),
		);

		if ( in_array( $action, self::CONTENT_ACTIONS, true ) ) {
			$data['content'] = $action;
		} else {
			$mode          = isset( $options['mode'] ) && 'nofollow' === $options['mode'] ? 'nofollow' : 'strip';
			$data['mode']  = 'blacklist' === $action ? $mode : '';
			$data['lists'] = $this->plugin->set_listed( $domains, $action, $mode );
			$filters       = array();

			if ( 'blacklist' === $action && ! empty( $options['apply'] ) ) {
				$data['content']    = $mode;
				$data['subdomains'] = true;
			}
		}

		if ( '' !== $data['content'] ) {
			// Only posts the person starting the change could edit by hand.
			$posts = array();
			foreach ( Link_Audit_Reports_DB::target_posts( $domains, $filters, $data['subdomains'] ) as $post_id ) {
				if ( current_user_can( 'edit_post', $post_id ) ) {
					$posts[] = $post_id;
				}
			}

			$data['pending'] = $posts;
			$data['total']   = count( $posts );
		}

		$id = Link_Audit_Reports_DB::add_log(
			array(
				'created' => time(),
				'user_id' => get_current_user_id(),
				'action'  => $action,
				'status'  => $data['pending'] ? 'running' : 'done',
				'domains' => $domains,
				'filters' => $filters,
				'data'    => $data,
			)
		);

		if ( ! $id ) {
			if ( isset( $data['lists'] ) ) {
				$this->plugin->restore_listed( $data['lists'] );
			}

			return new WP_Error( 'link_audit_reports_log', __( 'The change couldn\'t be logged, so it wasn\'t made.', 'link-audit-reports' ) );
		}

		if ( $data['pending'] ) {
			wp_schedule_single_event( time() + 30, self::BATCH_HOOK );
		}

		return $id;
	}

	/**
	 * Start undoing a finished change.
	 *
	 * @param int $id Log entry ID.
	 * @return true|WP_Error
	 */
	public function start_undo( $id ) {
		$log = Link_Audit_Reports_DB::get_log( $id );

		if ( ! $log || 'done' !== $log['status'] ) {
			return new WP_Error( 'link_audit_reports_undo', __( 'That change can\'t be undone.', 'link-audit-reports' ) );
		}

		if ( Link_Audit_Reports_DB::running_log() ) {
			return new WP_Error( 'link_audit_reports_busy', __( 'Another change is still running. Wait for it to finish, then try again.', 'link-audit-reports' ) );
		}

		$data = $log['data'];

		if ( ! empty( $data['lists'] ) && is_array( $data['lists'] ) ) {
			$this->plugin->restore_listed( $data['lists'] );
		}

		$posts                = isset( $data['posts'] ) && is_array( $data['posts'] ) ? array_map( 'intval', array_keys( $data['posts'] ) ) : array();
		$data['undo_pending'] = $posts;
		$data['undo_failed']  = 0;
		$data['undo_started'] = time();

		Link_Audit_Reports_DB::update_log(
			$id,
			array(
				'status'    => $posts ? 'undoing' : 'undone',
				'undone'    => time(),
				'undone_by' => get_current_user_id(),
				'data'      => $data,
			)
		);

		if ( $posts ) {
			wp_schedule_single_event( time() + 30, self::BATCH_HOOK );
		}

		return true;
	}

	// Batches -----------------------------------------------------------------

	/**
	 * Cron callback: carry on the running change.
	 *
	 * @return void
	 */
	public function cron_batch() {
		$id = Link_Audit_Reports_DB::running_log();

		if ( ! $id ) {
			return;
		}

		$progress = $this->step( $id, self::CRON_BUDGET );

		if ( $progress['running'] ) {
			wp_schedule_single_event( time() + 5, self::BATCH_HOOK );
		}
	}

	/**
	 * Work on a change (or its undo) until the time is up.
	 *
	 * @param int $id     Log entry ID.
	 * @param int $budget Seconds.
	 * @return array{running: bool, done: int, total: int, status: string}
	 */
	public function step( $id, $budget ) {
		$log = Link_Audit_Reports_DB::get_log( $id );

		if ( ! $log ) {
			return array(
				'running' => false,
				'done'    => 0,
				'total'   => 0,
				'status'  => '',
			);
		}

		if ( in_array( $log['status'], array( 'running', 'undoing' ), true ) && Link_Audit_Reports_Scanner::take_lock( self::LOCK_OPTION, self::LOCK_TTL ) ) {
			// Read again under the lock: another batch may have just saved it.
			$log      = Link_Audit_Reports_DB::get_log( $id );
			$deadline = microtime( true ) + max( 1, (int) $budget );

			if ( 'running' === $log['status'] ) {
				$log = $this->run_change( $log, $deadline );
			} elseif ( 'undoing' === $log['status'] ) {
				$log = $this->run_undo( $log, $deadline );
			}

			Link_Audit_Reports_Scanner::release_lock( self::LOCK_OPTION );
		}

		return self::progress( $log );
	}

	/**
	 * How far a change has got.
	 *
	 * @param array $log Log entry.
	 * @return array{running: bool, done: int, total: int, status: string}
	 */
	public static function progress( array $log ) {
		$data    = $log['data'];
		$undoing = 'undoing' === $log['status'];
		$total   = $undoing ? count( isset( $data['posts'] ) ? (array) $data['posts'] : array() ) : ( isset( $data['total'] ) ? (int) $data['total'] : 0 );
		$left    = count( (array) ( $undoing ? ( isset( $data['undo_pending'] ) ? $data['undo_pending'] : array() ) : ( isset( $data['pending'] ) ? $data['pending'] : array() ) ) );

		return array(
			'running' => in_array( $log['status'], array( 'running', 'undoing' ), true ),
			'done'    => max( 0, $total - $left ),
			'total'   => $total,
			'status'  => (string) $log['status'],
		);
	}

	/**
	 * Apply a change to posts until the deadline.
	 *
	 * @param array $log      Log entry.
	 * @param float $deadline microtime() to stop at.
	 * @return array The entry, saved.
	 */
	private function run_change( array $log, $deadline ) {
		$data    = $log['data'];
		$pending = array_map( 'intval', isset( $data['pending'] ) ? (array) $data['pending'] : array() );
		$posts   = isset( $data['posts'] ) && is_array( $data['posts'] ) ? $data['posts'] : array();
		$filters = is_array( $log['filters'] ) ? $log['filters'] : array();

		while ( $pending && microtime( true ) < $deadline ) {
			$post_id = array_shift( $pending );
			$result  = $this->apply_post( $post_id, (string) $data['content'], (array) $log['domains'], $filters, ! empty( $data['subdomains'] ) );

			if ( $result ) {
				$posts[ $post_id ] = $result;
			}
		}

		$data['pending'] = $pending;
		$data['posts']   = $posts;
		$links           = 0;

		foreach ( $posts as $entry ) {
			$links += count( $entry['changes'] );
		}

		$log['data']   = $data;
		$log['posts']  = count( $posts );
		$log['links']  = $links;
		$log['status'] = $pending ? 'running' : 'done';

		Link_Audit_Reports_DB::update_log(
			$log['id'],
			array(
				'status' => $log['status'],
				'posts'  => $log['posts'],
				'links'  => $log['links'],
				'data'   => $data,
			)
		);

		return $log;
	}

	/**
	 * Undo a change in posts until the deadline.
	 *
	 * @param array $log      Log entry.
	 * @param float $deadline microtime() to stop at.
	 * @return array The entry, saved.
	 */
	private function run_undo( array $log, $deadline ) {
		$data    = $log['data'];
		$pending = array_map( 'intval', isset( $data['undo_pending'] ) ? (array) $data['undo_pending'] : array() );
		$failed  = isset( $data['undo_failed'] ) ? (int) $data['undo_failed'] : 0;
		$undone  = (int) $log['undone_links'];
		$since   = isset( $data['undo_started'] ) ? (int) $data['undo_started'] : time();

		while ( $pending && microtime( true ) < $deadline ) {
			$post_id = array_shift( $pending );
			$entry   = isset( $data['posts'][ $post_id ] ) && is_array( $data['posts'][ $post_id ] ) ? $data['posts'][ $post_id ] : array();
			$changes = isset( $entry['changes'] ) && is_array( $entry['changes'] ) ? $entry['changes'] : array();
			$count   = $this->revert_post( $post_id, $entry, $since );

			$undone += $count;
			$failed += count( $changes ) - $count;
		}

		$data['undo_pending'] = $pending;
		$data['undo_failed']  = $failed;

		if ( $pending ) {
			$status = 'undoing';
		} else {
			$status = $failed ? 'partial' : 'undone';
		}

		$log['data']         = $data;
		$log['status']       = $status;
		$log['undone_links'] = $undone;

		Link_Audit_Reports_DB::update_log(
			$log['id'],
			array(
				'status'       => $status,
				'undone_links' => $undone,
				'data'         => $data,
			)
		);

		return $log;
	}

	// One post ------------------------------------------------------------------

	/**
	 * Apply a change to the matching links in one post.
	 *
	 * @param int      $post_id    Post ID.
	 * @param string   $action     Content action.
	 * @param string[] $domains    Domains.
	 * @param array    $filters    Filters.
	 * @param bool     $subdomains Also their subdomains.
	 * @return array|null What changed (md5 of the new content, changes, and the
	 *                    stripped links' authors and dates), or null for nothing.
	 */
	private function apply_post( $post_id, $action, array $domains, array $filters, $subdomains ) {
		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! $this->plugin->audits( $post ) ) {
			return null;
		}

		// Catch up first, in case the post was changed in a way the index missed.
		$this->scanner->scan_post( $post_id, 'full' );

		$targets = array();
		foreach ( Link_Audit_Reports_DB::target_links( $post_id, $domains, $filters, $subdomains ) as $row ) {
			$targets[ $row['url_hash'] . '|' . $row['nth'] ] = $row;
		}

		if ( ! $targets ) {
			return null;
		}

		$origins = array();
		$content = Link_Audit_Reports_Content::transform(
			$post->post_content,
			$action,
			function ( $link ) use ( $targets, &$origins ) {
				$key = md5( $link['url'] ) . '|' . $link['nth'];

				if ( ! isset( $targets[ $key ] ) ) {
					return false;
				}

				$origins[] = array( $link['url'], (int) $targets[ $key ]['added_by'], (int) $targets[ $key ]['first_seen'] );

				return true;
			},
			$this->plugin->internal_hosts(),
			$changes
		);

		if ( ! $changes || ! $this->save_content( $post, $content ) ) {
			return null;
		}

		$this->scanner->scan_post( $post_id, 'job' );

		return array(
			'md5'     => md5( $content ),
			'changes' => $changes,
			'origins' => 'strip' === $action ? $origins : array(),
		);
	}

	/**
	 * Undo a change in one post.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $entry   What changed in it (from apply_post()).
	 * @param int   $since   When the undo started.
	 * @return int Changes put back.
	 */
	private function revert_post( $post_id, array $entry, $since ) {
		$post    = get_post( $post_id );
		$changes = isset( $entry['changes'] ) && is_array( $entry['changes'] ) ? $entry['changes'] : array();

		if ( ! $post instanceof WP_Post || ! $changes || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return 0;
		}

		$exact   = isset( $entry['md5'] ) && md5( $post->post_content ) === $entry['md5'];
		$content = Link_Audit_Reports_Content::revert( $post->post_content, $changes, $exact, $undone );

		if ( ! $undone || $content === $post->post_content || ! $this->save_content( $post, $content ) ) {
			return 0;
		}

		$this->scanner->scan_post( $post_id, 'job' );

		if ( ! empty( $entry['origins'] ) && is_array( $entry['origins'] ) ) {
			Link_Audit_Reports_DB::restore_origins( $post_id, $entry['origins'], $since );
		}

		return $undone;
	}

	/**
	 * Save new content for a post.
	 *
	 * Goes through wp_update_post(), so caches are cleared and a revision is
	 * saved, but:
	 * - the content isn't run through kses again. Only link tags changed (rel
	 *   values from a fixed list added or removed, or a link taken off its
	 *   text), and in the background there's no logged-in user, so kses would
	 *   strip embeds and other markup an administrator was allowed to add.
	 * - the "last modified" date stays the same: a rel change isn't a content
	 *   update, and shouldn't touch every sitemap date.
	 * - a post with no revisions gets one of its old content first, so the
	 *   change can always be rolled back from the Revisions screen too.
	 *
	 * @param WP_Post $post    Post as it is now.
	 * @param string  $content New content.
	 * @return bool
	 */
	private function save_content( WP_Post $post, $content ) {
		if ( function_exists( 'wp_revisions_enabled' ) && wp_revisions_enabled( $post ) && function_exists( '_wp_put_post_revision' ) ) {
			$revisions = wp_get_post_revisions(
				$post->ID,
				array(
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);
			if ( ! $revisions ) {
				_wp_put_post_revision( $post );
			}
		}

		$keep_dates = function ( $data, $postarr ) use ( $post ) {
			if ( isset( $postarr['ID'] ) && (int) $postarr['ID'] === (int) $post->ID ) {
				$data['post_modified']     = $post->post_modified;
				$data['post_modified_gmt'] = $post->post_modified_gmt;
			}
			return $data;
		};

		$had_kses = false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		kses_remove_filters();
		add_filter( 'wp_insert_post_data', $keep_dates, 99, 2 );

		$result = $this->scanner->while_saving(
			function () use ( $post, $content ) {
				return wp_update_post(
					wp_slash(
						array(
							'ID'           => $post->ID,
							'post_content' => $content,
						)
					),
					true
				);
			}
		);

		remove_filter( 'wp_insert_post_data', $keep_dates, 99 );
		if ( $had_kses ) {
			kses_init_filters();
		}

		clean_post_cache( $post->ID );

		return $result && ! is_wp_error( $result );
	}

	// Words -------------------------------------------------------------------

	/**
	 * A sentence saying what a logged change did.
	 *
	 * @param array $log Log entry.
	 * @return string Plain text.
	 */
	public static function describe( array $log ) {
		// Log lists leave the data out; a blacklist entry needs it for its mode.
		if ( ! isset( $log['data'] ) && 'blacklist' === $log['action'] ) {
			$full = Link_Audit_Reports_DB::get_log( (int) $log['id'] );
			$log  = $full ? $full : $log;
		}

		$domains = self::domain_list( (array) $log['domains'] );
		$data    = isset( $log['data'] ) && is_array( $log['data'] ) ? $log['data'] : array();
		/* translators: %s: number of links. */
		$links = sprintf( _n( '%s link', '%s links', (int) $log['links'], 'link-audit-reports' ), number_format_i18n( (int) $log['links'] ) );
		/* translators: %s: number of posts. */
		$posts = sprintf( _n( '%s post', '%s posts', (int) $log['posts'], 'link-audit-reports' ), number_format_i18n( (int) $log['posts'] ) );

		if ( in_array( $log['action'], self::CONTENT_ACTIONS, true ) && ! (int) $log['links'] && 'running' !== $log['status'] ) {
			/* translators: %s: domains. */
			return sprintf( __( 'No links to %s needed changing.', 'link-audit-reports' ), $domains );
		}

		switch ( $log['action'] ) {
			case 'nofollow':
			case 'sponsored':
			case 'ugc':
				/* translators: 1: rel value, 2: "3 links", 3: domains, 4: "2 posts". */
				return sprintf( __( 'Added %1$s to %2$s to %3$s in %4$s.', 'link-audit-reports' ), $log['action'], $links, $domains, $posts );

			case 'dofollow':
				/* translators: 1: "3 links", 2: domains, 3: "2 posts". */
				return sprintf( __( 'Made %1$s to %2$s dofollow in %3$s.', 'link-audit-reports' ), $links, $domains, $posts );

			case 'strip':
				/* translators: 1: "3 links", 2: domains, 3: "2 posts". */
				return sprintf( __( 'Removed %1$s to %2$s from %3$s, keeping the link text.', 'link-audit-reports' ), $links, $domains, $posts );

			case 'whitelist':
				/* translators: %s: domains. */
				return sprintf( __( 'Whitelisted %s.', 'link-audit-reports' ), $domains );

			case 'blacklist':
				$nofollow = isset( $data['mode'] ) && 'nofollow' === $data['mode'];
				/* translators: %s: domains. */
				$text = sprintf( $nofollow ? __( 'Blacklisted %s: new links get nofollow on save.', 'link-audit-reports' ) : __( 'Blacklisted %s: new links are removed on save.', 'link-audit-reports' ), $domains );

				if ( ! empty( $data['content'] ) && (int) $log['links'] ) {
					/* translators: 1: "3 links", 2: "2 posts". */
					$more  = $nofollow ? __( 'Added nofollow to %1$s already in %2$s.', 'link-audit-reports' ) : __( 'Removed %1$s already in %2$s.', 'link-audit-reports' );
					$text .= ' ' . sprintf( $more, $links, $posts );
				}

				return $text;
		}

		return '';
	}

	/**
	 * Domains as a short list: "a.com, b.com and 3 more".
	 *
	 * @param string[] $domains Domains.
	 * @return string
	 */
	public static function domain_list( array $domains ) {
		$domains = array_values( array_filter( array_map( 'strval', $domains ) ) );
		$count   = count( $domains );

		if ( $count <= 3 ) {
			return wp_sprintf( '%l', $domains );
		}

		/* translators: 1: first domains, 2: number of other domains. */
		return sprintf( _n( '%1$s and %2$s more', '%1$s and %2$s more', $count - 2, 'link-audit-reports' ), implode( ', ', array_slice( $domains, 0, 2 ) ), number_format_i18n( $count - 2 ) );
	}
}
