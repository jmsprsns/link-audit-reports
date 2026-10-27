<?php
/**
 * The link index and the undo log: two tables and every query on them.
 *
 * The index has one row per outbound link: the post it's in, the URL and
 * domain, its text and rel values, who added it, and when it was first and
 * last seen. A link is identified by its post, its URL and which copy of that
 * URL in the post it is (the first, second...), so edits elsewhere in a post
 * never change which row a link belongs to.
 *
 * @package Link_Audit_Reports
 */

defined( 'ABSPATH' ) || exit;

/**
 * Database access.
 */
final class Link_Audit_Reports_DB {

	/**
	 * Schema version. Bump it when the tables change.
	 */
	const VERSION = '1';

	/**
	 * Option holding the installed schema version.
	 */
	const VERSION_OPTION = 'link_audit_reports_db_version';

	/**
	 * Log entries kept (older ones are deleted).
	 */
	const LOG_KEEP = 200;

	/**
	 * Sort keys the report accepts => SQL. Nothing else reaches ORDER BY.
	 */
	const ORDER = array(
		'domain' => 'domain',
		'links'  => 'links',
		'posts'  => 'posts',
		'rel'    => 'follow',
		'first'  => 'first_seen',
		'last'   => 'last_seen',
	);

	/**
	 * Index table name.
	 *
	 * @return string
	 */
	public static function links_table() {
		global $wpdb;

		return $wpdb->prefix . 'lar_links';
	}

	/**
	 * Log table name.
	 *
	 * @return string
	 */
	public static function log_table() {
		global $wpdb;

		return $wpdb->prefix . 'lar_log';
	}

	// Install -----------------------------------------------------------------

	/**
	 * Create or update the tables.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$links   = self::links_table();
		$log     = self::log_table();

		dbDelta(
			"CREATE TABLE {$links} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL DEFAULT 0,
			post_type varchar(20) NOT NULL DEFAULT '',
			post_author bigint(20) unsigned NOT NULL DEFAULT 0,
			added_by bigint(20) unsigned NOT NULL DEFAULT 0,
			url text NOT NULL,
			url_hash char(32) NOT NULL DEFAULT '',
			nth smallint(5) unsigned NOT NULL DEFAULT 0,
			domain varchar(191) NOT NULL DEFAULT '',
			anchor varchar(255) NOT NULL DEFAULT '',
			rel varchar(191) NOT NULL DEFAULT '',
			is_nofollow tinyint(1) unsigned NOT NULL DEFAULT 0,
			is_sponsored tinyint(1) unsigned NOT NULL DEFAULT 0,
			is_ugc tinyint(1) unsigned NOT NULL DEFAULT 0,
			first_seen int(10) unsigned NOT NULL DEFAULT 0,
			last_seen int(10) unsigned NOT NULL DEFAULT 0,
			scanned int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY link (post_id,url_hash,nth),
			KEY domain (domain),
			KEY added_by (added_by),
			KEY first_seen (first_seen)
			) {$charset};
			CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created int(10) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(20) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT '',
			domains text NOT NULL,
			filters text NOT NULL,
			posts int(10) unsigned NOT NULL DEFAULT 0,
			links int(10) unsigned NOT NULL DEFAULT 0,
			undone int(10) unsigned NOT NULL DEFAULT 0,
			undone_by bigint(20) unsigned NOT NULL DEFAULT 0,
			undone_links int(10) unsigned NOT NULL DEFAULT 0,
			data longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status)
			) {$charset};"
		);

		update_option( self::VERSION_OPTION, self::VERSION );
	}

	/**
	 * Create the tables if this site doesn't have them yet (network
	 * activation, or an update that changed them). Returns whether it did.
	 *
	 * @return bool
	 */
	public static function maybe_install() {
		if ( self::VERSION === get_option( self::VERSION_OPTION ) ) {
			return false;
		}

		$first = false === get_option( self::VERSION_OPTION );

		self::install();

		return $first;
	}

	/**
	 * Drop both tables (uninstall).
	 *
	 * @return void
	 */
	public static function drop() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::links_table() . ', ' . self::log_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table names only.
		delete_option( self::VERSION_OPTION );
	}

	// Index -------------------------------------------------------------------

	/**
	 * Bring a post's rows in line with the links it has now.
	 *
	 * @param WP_Post $post  Post.
	 * @param array[] $links Its links, from Link_Audit_Reports_Content::outbound().
	 * @param string  $mode  save (an edit: new links are credited to the user
	 *                       saving it, seen now), full (a background scan:
	 *                       new links are credited to the post's author, seen
	 *                       when the post was published or last edited) or
	 *                       job (this plugin's own change: dates stay put).
	 * @param int     $user  User saving the post (save and job modes).
	 * @return void
	 */
	public static function sync_post( WP_Post $post, array $links, $mode, $user = 0 ) {
		global $wpdb;

		$table    = self::links_table();
		$now      = time();
		$author   = (int) $post->post_author;
		$existing = $wpdb->get_results( $wpdb->prepare( "SELECT id, url_hash, nth, domain, anchor, rel, is_nofollow, is_sponsored, is_ugc FROM {$table} WHERE post_id = %d", $post->ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
		$by_key   = array();

		foreach ( (array) $existing as $row ) {
			$by_key[ $row['url_hash'] . '|' . $row['nth'] ] = $row;
		}

		$published = self::timestamp( $post->post_date_gmt, $now );
		$modified  = self::timestamp( $post->post_modified_gmt, $now );

		if ( 'full' === $mode ) {
			// A post scanned for the first time: its links have been there since
			// it was published. Links that turn up later were added in an edit
			// this plugin didn't see, so the last edit is the best guess.
			$first_new = min( $existing ? $modified : $published, $now );
			$added_new = $author;
		} else {
			$first_new = $now;
			$added_new = $user ? (int) $user : $author;
		}

		$suppress = $wpdb->suppress_errors( true );

		foreach ( $links as $link ) {
			$hash   = md5( $link['url'] );
			$key    = $hash . '|' . (int) $link['nth'];
			$fields = array(
				'domain'       => $link['domain'],
				'anchor'       => (string) $link['anchor'],
				'rel'          => substr( (string) $link['rel'], 0, 191 ),
				'is_nofollow'  => $link['nofollow'] ? 1 : 0,
				'is_sponsored' => $link['sponsored'] ? 1 : 0,
				'is_ugc'       => $link['ugc'] ? 1 : 0,
			);

			if ( isset( $by_key[ $key ] ) ) {
				$row = $by_key[ $key ];
				unset( $by_key[ $key ] );

				$changed = array();
				foreach ( $fields as $name => $value ) {
					if ( (string) $row[ $name ] !== (string) $value ) {
						$changed[ $name ] = $value;
					}
				}

				if ( $changed ) {
					$wpdb->update( $table, $changed, array( 'id' => (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				}
				continue;
			}

			// A duplicate (another request scanning the same post at the same
			// moment) fails quietly on the unique key.
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$table,
				array_merge(
					$fields,
					array(
						'post_id'     => (int) $post->ID,
						'post_type'   => $post->post_type,
						'post_author' => $author,
						'added_by'    => $added_new,
						'url'         => $link['url'],
						'url_hash'    => $hash,
						'nth'         => min( 65535, (int) $link['nth'] ),
						'first_seen'  => $first_new,
						'last_seen'   => 'job' === $mode ? $first_new : ( 'full' === $mode ? $modified : $now ),
						'scanned'     => $now,
					)
				)
			);
		}

		$wpdb->suppress_errors( $suppress );

		// Links that are gone from the post.
		if ( $by_key ) {
			$ids = array_map( 'intval', wp_list_pluck( $by_key, 'id' ) );
			$wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- Integers only.
		}

		// What every row of the post shares, and when its links were last seen.
		if ( 'save' === $mode ) {
			$last = $wpdb->prepare( 'last_seen = %d,', $now );
		} elseif ( 'full' === $mode ) {
			$last = $wpdb->prepare( 'last_seen = GREATEST(last_seen, %d),', $modified );
		} else {
			$last = '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $last is prepared above.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET {$last} post_type = %s, post_author = %d, scanned = %d WHERE post_id = %d", $post->post_type, $author, $now, $post->ID ) );
	}

	/**
	 * A GMT date from the posts table as a Unix time.
	 *
	 * @param string $date     Y-m-d H:i:s (GMT), or the zero date.
	 * @param int    $fallback Used for an empty or zero date.
	 * @return int
	 */
	private static function timestamp( $date, $fallback ) {
		$time = ( '' === (string) $date || 0 === strpos( (string) $date, '0000' ) ) ? false : strtotime( $date . ' UTC' );

		return $time ? (int) $time : (int) $fallback;
	}

	/**
	 * Forget a post's links.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function delete_post( $post_id ) {
		global $wpdb;

		$wpdb->delete( self::links_table(), array( 'post_id' => (int) $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Forget the links of every post a full scan didn't reach (deleted, or no
	 * longer audited).
	 *
	 * @param int $started When the scan started.
	 * @return void
	 */
	public static function delete_unscanned( $started ) {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::links_table() . ' WHERE scanned < %d', (int) $started ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only.
	}

	/**
	 * Give links that an undo has just put back the author and first-seen
	 * date they had before they were stripped.
	 *
	 * @param int     $post_id Post ID.
	 * @param array[] $origins Each array( url, added_by, first_seen ), in post order.
	 * @param int     $since   Only rows created since this time.
	 * @return void
	 */
	public static function restore_origins( $post_id, array $origins, $since ) {
		global $wpdb;

		$table = self::links_table();

		foreach ( $origins as $origin ) {
			if ( ! is_array( $origin ) || 3 !== count( $origin ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name only.
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE post_id = %d AND url_hash = %s AND first_seen >= %d ORDER BY nth ASC LIMIT 1", $post_id, md5( (string) $origin[0] ), (int) $since ) );

			if ( $id ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'added_by'   => absint( $origin[1] ),
						'first_seen' => absint( $origin[2] ),
					),
					array( 'id' => (int) $id )
				);
			}
		}
	}

	// Report ------------------------------------------------------------------

	/**
	 * WHERE conditions for a set of filters.
	 *
	 * @param array $filters author (user ID), type (post type), rel (follow,
	 *                       nofollow, sponsored or ugc), from and to (Unix
	 *                       times, first seen), search (part of a domain),
	 *                       view (active, whitelisted, blacklisted or all).
	 * @return string Prepared SQL, starting with "1=1".
	 */
	public static function where( array $filters ) {
		global $wpdb;

		$sql = '1=1';

		if ( ! empty( $filters['author'] ) ) {
			$sql .= $wpdb->prepare( ' AND added_by = %d', (int) $filters['author'] );
		}

		if ( ! empty( $filters['type'] ) ) {
			$sql .= $wpdb->prepare( ' AND post_type = %s', (string) $filters['type'] );
		}

		if ( ! empty( $filters['rel'] ) ) {
			$rel  = array(
				'follow'    => ' AND is_nofollow = 0 AND is_sponsored = 0 AND is_ugc = 0',
				'nofollow'  => ' AND is_nofollow = 1',
				'sponsored' => ' AND is_sponsored = 1',
				'ugc'       => ' AND is_ugc = 1',
			);
			$sql .= isset( $rel[ $filters['rel'] ] ) ? $rel[ $filters['rel'] ] : '';
		}

		if ( ! empty( $filters['from'] ) ) {
			$sql .= $wpdb->prepare( ' AND first_seen >= %d', (int) $filters['from'] );
		}

		if ( ! empty( $filters['to'] ) ) {
			$sql .= $wpdb->prepare( ' AND first_seen <= %d', (int) $filters['to'] );
		}

		if ( isset( $filters['search'] ) && '' !== (string) $filters['search'] ) {
			$sql .= $wpdb->prepare( ' AND domain LIKE %s', '%' . $wpdb->esc_like( (string) $filters['search'] ) . '%' );
		}

		$view   = isset( $filters['view'] ) ? $filters['view'] : 'active';
		$plugin = link_audit_reports_plugin();

		if ( 'active' === $view ) {
			$sql .= ' AND NOT ' . self::match_sql( array_keys( $plugin->whitelist() ), true );
		} elseif ( 'whitelisted' === $view ) {
			$sql .= ' AND ' . self::match_sql( array_keys( $plugin->whitelist() ), true );
		} elseif ( 'blacklisted' === $view ) {
			$sql .= ' AND ' . self::match_sql( array_keys( $plugin->blacklist() ), true );
		}

		return $sql;
	}

	/**
	 * SQL that matches rows whose domain is one of some domains.
	 *
	 * @param string[] $domains    Domains, as Link_Audit_Reports_Content::host() returns them.
	 * @param bool     $subdomains Also match their subdomains.
	 * @return string Prepared SQL in brackets ("(0=1)" for no domains).
	 */
	public static function match_sql( array $domains, $subdomains ) {
		global $wpdb;

		$domains = array_values( array_unique( array_filter( array_map( 'strval', $domains ) ) ) );

		if ( ! $domains ) {
			return '(0=1)';
		}

		$sql = $wpdb->prepare( 'domain IN (' . implode( ',', array_fill( 0, count( $domains ), '%s' ) ) . ')', $domains ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built to match.

		if ( $subdomains ) {
			foreach ( $domains as $domain ) {
				$sql .= $wpdb->prepare( ' OR domain LIKE %s', '%.' . $wpdb->esc_like( $domain ) );
			}
		}

		return '(' . $sql . ')';
	}

	/**
	 * One page of the domains report.
	 *
	 * @param array  $filters See where().
	 * @param string $orderby Key of ORDER.
	 * @param string $order   asc or desc.
	 * @param int    $limit   Rows.
	 * @param int    $offset  Rows to skip.
	 * @return array[]
	 */
	public static function domains( array $filters, $orderby, $order, $limit, $offset ) {
		global $wpdb;

		$table = self::links_table();
		$where = self::where( $filters );
		$sort  = isset( self::ORDER[ $orderby ] ) ? self::ORDER[ $orderby ] : 'links';
		$dir   = 'asc' === strtolower( (string) $order ) ? 'ASC' : 'DESC';
		$then  = 'domain' === $sort ? '' : ', domain ASC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared; $sort and $dir come from fixed lists.
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT domain, COUNT(*) AS links, COUNT(DISTINCT post_id) AS posts, SUM(is_nofollow = 0 AND is_sponsored = 0 AND is_ugc = 0) AS follow, SUM(is_nofollow) AS nofollow, SUM(is_sponsored) AS sponsored, SUM(is_ugc) AS ugc, COUNT(DISTINCT added_by) AS author_count, GROUP_CONCAT(DISTINCT added_by ORDER BY added_by) AS authors, MIN(first_seen) AS first_seen, MAX(last_seen) AS last_seen FROM {$table} WHERE {$where} GROUP BY domain ORDER BY {$sort} {$dir}{$then} LIMIT %d OFFSET %d", max( 1, (int) $limit ), max( 0, (int) $offset ) ), ARRAY_A );
	}

	/**
	 * Number of domains in the report.
	 *
	 * @param array $filters See where().
	 * @return int
	 */
	public static function count_domains( array $filters ) {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT domain) FROM ' . self::links_table() . ' WHERE ' . self::where( $filters ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in where().
	}

	/**
	 * Totals for the report's summary boxes.
	 *
	 * @param array $filters See where().
	 * @return array<string,int> links, domains, posts, follow, nofollow, sponsored, ugc.
	 */
	public static function stats( array $filters ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in where().
		$row = $wpdb->get_row( 'SELECT COUNT(*) AS links, COUNT(DISTINCT domain) AS domains, COUNT(DISTINCT post_id) AS posts, SUM(is_nofollow = 0 AND is_sponsored = 0 AND is_ugc = 0) AS follow, SUM(is_nofollow) AS nofollow, SUM(is_sponsored) AS sponsored, SUM(is_ugc) AS ugc FROM ' . self::links_table() . ' WHERE ' . self::where( $filters ), ARRAY_A );

		return array_map( 'intval', is_array( $row ) ? $row : array() ) + array_fill_keys( array( 'links', 'domains', 'posts', 'follow', 'nofollow', 'sponsored', 'ugc' ), 0 );
	}

	/**
	 * The links to one domain.
	 *
	 * @param string $domain  Domain.
	 * @param array  $filters See where() (the search and view are ignored).
	 * @param int    $limit   Rows.
	 * @param int    $offset  Rows to skip.
	 * @return array[]
	 */
	public static function domain_links( $domain, array $filters, $limit, $offset ) {
		global $wpdb;

		$filters = array_merge( $filters, array( 'search' => '', 'view' => 'all' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$where   = self::where( $filters ) . $wpdb->prepare( ' AND domain = %s', (string) $domain );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::links_table() . " WHERE {$where} ORDER BY post_id DESC, id ASC LIMIT %d OFFSET %d", max( 1, (int) $limit ), max( 0, (int) $offset ) ), ARRAY_A );
	}

	/**
	 * Number of links to one domain.
	 *
	 * @param string $domain  Domain.
	 * @param array  $filters See where().
	 * @return int
	 */
	public static function count_domain_links( $domain, array $filters ) {
		global $wpdb;

		$filters = array_merge( $filters, array( 'search' => '', 'view' => 'all' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$where   = self::where( $filters ) . $wpdb->prepare( ' AND domain = %s', (string) $domain );

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::links_table() . " WHERE {$where}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared.
	}

	/**
	 * Every link matching some filters, a chunk at a time (CSV export).
	 *
	 * @param array $filters See where().
	 * @param int   $after   Last row ID already read.
	 * @param int   $limit   Rows.
	 * @return array[]
	 */
	public static function links_after( array $filters, $after, $limit ) {
		global $wpdb;

		$where = self::where( $filters );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared.
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::links_table() . " WHERE {$where} AND id > %d ORDER BY id ASC LIMIT %d", (int) $after, (int) $limit ), ARRAY_A );
	}

	/**
	 * Users who have added links (for the author filter).
	 *
	 * @return int[]
	 */
	public static function authors() {
		global $wpdb;

		return array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT added_by FROM ' . self::links_table() . ' WHERE added_by > 0 LIMIT 500' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only.
	}

	/**
	 * Total rows in the index.
	 *
	 * @return int
	 */
	public static function count_links() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::links_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only.
	}

	// Targets of a change ---------------------------------------------------------

	/**
	 * SQL for the links a change applies to.
	 *
	 * @param string[] $domains    Domains.
	 * @param array    $filters    See where() (the search and view are ignored).
	 * @param bool     $subdomains Also their subdomains.
	 * @return string Prepared WHERE conditions.
	 */
	private static function target_where( array $domains, array $filters, $subdomains ) {
		$filters = array_merge( $filters, array( 'search' => '', 'view' => 'all' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		return self::where( $filters ) . ' AND ' . self::match_sql( $domains, $subdomains );
	}

	/**
	 * Posts with links a change applies to.
	 *
	 * @param string[] $domains    Domains.
	 * @param array    $filters    Filters.
	 * @param bool     $subdomains Also their subdomains.
	 * @return int[]
	 */
	public static function target_posts( array $domains, array $filters, $subdomains ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Prepared in target_where().
		return array_map( 'intval', (array) $wpdb->get_col( 'SELECT DISTINCT post_id FROM ' . self::links_table() . ' WHERE ' . self::target_where( $domains, $filters, $subdomains ) . ' ORDER BY post_id ASC' ) );
	}

	/**
	 * The links in one post a change applies to.
	 *
	 * @param int      $post_id    Post ID.
	 * @param string[] $domains    Domains.
	 * @param array    $filters    Filters.
	 * @param bool     $subdomains Also their subdomains.
	 * @return array[] Rows: url, url_hash, nth, added_by, first_seen.
	 */
	public static function target_links( $post_id, array $domains, array $filters, $subdomains ) {
		global $wpdb;

		$where = self::target_where( $domains, $filters, $subdomains ) . $wpdb->prepare( ' AND post_id = %d', (int) $post_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $where is prepared.
		return (array) $wpdb->get_results( 'SELECT url, url_hash, nth, added_by, first_seen FROM ' . self::links_table() . " WHERE {$where} ORDER BY id ASC", ARRAY_A );
	}

	/**
	 * How many links a change would apply to, and how many of them it would
	 * actually change.
	 *
	 * @param string   $action     Action.
	 * @param string[] $domains    Domains.
	 * @param array    $filters    Filters.
	 * @param bool     $subdomains Also their subdomains.
	 * @return array{links: int, posts: int, change: int} Links it applies to, and
	 *         the links and posts it would change.
	 */
	public static function target_counts( $action, array $domains, array $filters, $subdomains ) {
		global $wpdb;

		// Which of the links the action would actually change.
		$changes = array(
			'nofollow'  => 'is_nofollow = 0',
			'sponsored' => 'is_sponsored = 0',
			'ugc'       => 'is_ugc = 0',
			'dofollow'  => '(is_nofollow = 1 OR is_sponsored = 1 OR is_ugc = 1)',
		);
		$change  = isset( $changes[ $action ] ) ? $changes[ $action ] : '1=1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- $change comes from a fixed list; the WHERE is prepared.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS links, SUM({$change}) AS change_links, COUNT(DISTINCT CASE WHEN {$change} THEN post_id END) AS change_posts FROM " . self::links_table() . ' WHERE ' . self::target_where( $domains, $filters, $subdomains ), ARRAY_A );

		return array(
			'links'  => isset( $row['links'] ) ? (int) $row['links'] : 0,
			'posts'  => isset( $row['change_posts'] ) ? (int) $row['change_posts'] : 0,
			'change' => isset( $row['change_links'] ) ? (int) $row['change_links'] : 0,
		);
	}

	// Log ---------------------------------------------------------------------

	/**
	 * Add a log entry.
	 *
	 * @param array $fields Columns (data, domains and filters as arrays).
	 * @return int Entry ID, 0 on failure.
	 */
	public static function add_log( array $fields ) {
		global $wpdb;

		$ok = $wpdb->insert( self::log_table(), self::encode_log( $fields ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		if ( ! $ok ) {
			return 0;
		}

		$id = (int) $wpdb->insert_id;

		// Keep the newest entries only.
		$cutoff = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::log_table() . ' ORDER BY id DESC LIMIT 1 OFFSET %d', self::LOG_KEEP ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only.
		if ( $cutoff ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::log_table() . " WHERE id <= %d AND status NOT IN ('running', 'undoing')", $cutoff ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only.
		}

		return $id;
	}

	/**
	 * Update a log entry.
	 *
	 * @param int   $id     Entry ID.
	 * @param array $fields Columns.
	 * @return void
	 */
	public static function update_log( $id, array $fields ) {
		global $wpdb;

		$wpdb->update( self::log_table(), self::encode_log( $fields ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * A log entry, with data, domains and filters decoded.
	 *
	 * @param int $id Entry ID.
	 * @return array|null
	 */
	public static function get_log( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::log_table() . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only.

		return $row ? self::decode_log( $row ) : null;
	}

	/**
	 * One page of log entries, newest first (without their data).
	 *
	 * @param int $limit  Rows.
	 * @param int $offset Rows to skip.
	 * @return array[]
	 */
	public static function logs( $limit, $offset ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, created, user_id, action, status, domains, filters, posts, links, undone, undone_by, undone_links FROM ' . self::log_table() . ' ORDER BY id DESC LIMIT %d OFFSET %d', (int) $limit, (int) $offset ), ARRAY_A );

		return array_map( array( __CLASS__, 'decode_log' ), $rows );
	}

	/**
	 * Number of log entries.
	 *
	 * @return int
	 */
	public static function count_logs() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::log_table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only.
	}

	/**
	 * The change that's running (or being undone), if any.
	 *
	 * @return int Entry ID, 0 for none.
	 */
	public static function running_log() {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT id FROM ' . self::log_table() . " WHERE status IN ('running', 'undoing') ORDER BY id ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Table name only.
	}

	/**
	 * Log columns ready for the database.
	 *
	 * @param array $fields Columns.
	 * @return array
	 */
	private static function encode_log( array $fields ) {
		foreach ( array( 'data', 'domains', 'filters' ) as $key ) {
			if ( isset( $fields[ $key ] ) && ! is_string( $fields[ $key ] ) ) {
				$fields[ $key ] = (string) wp_json_encode( $fields[ $key ] );
			}
		}

		return $fields;
	}

	/**
	 * A log row with its JSON columns decoded (never unserialized).
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function decode_log( array $row ) {
		foreach ( array( 'data', 'domains', 'filters' ) as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$decoded     = json_decode( (string) $row[ $key ], true );
				$row[ $key ] = is_array( $decoded ) ? $decoded : array();
			}
		}

		return $row;
	}
}
