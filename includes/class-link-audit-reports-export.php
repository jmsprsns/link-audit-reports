<?php
/**
 * CSV exports of the report: one row per domain, or one row per link.
 *
 * Both follow the report's current filters and are streamed a chunk at a
 * time, so big sites don't run out of memory. Text that a spreadsheet would
 * read as a formula (it starts with =, +, -, @ or a tab) is prefixed with an
 * apostrophe, because link text and URLs come from post content.
 *
 * @package Link_Audit_Reports
 */

defined( 'ABSPATH' ) || exit;

/**
 * CSV export.
 */
final class Link_Audit_Reports_Export {

	/**
	 * Rows read per query.
	 */
	const CHUNK = 500;

	/**
	 * Display names already looked up.
	 *
	 * @var array<int,string>
	 */
	private static $names = array();

	/**
	 * Send a CSV file and stop.
	 *
	 * @param string $type    domains or links.
	 * @param array  $filters Report filters (cleaned), including search and view.
	 * @return void
	 */
	public static function send( $type, array $filters ) {
		$type = 'links' === $type ? 'links' : 'domains';
		$name = sprintf( 'link-audit-%s-%s.csv', $type, wp_date( 'Y-m-d' ) );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Not allowed on every host.
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to the browser.

		// Byte order mark, so Excel reads the file as UTF-8.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		if ( 'links' === $type ) {
			self::links( $out, $filters );
		} else {
			self::domains( $out, $filters );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * One row per domain.
	 *
	 * @param resource $out     Output stream.
	 * @param array    $filters Filters.
	 * @return void
	 */
	private static function domains( $out, array $filters ) {
		$plugin = link_audit_reports_plugin();

		self::row(
			$out,
			array(
				__( 'Domain', 'link-audit-reports' ),
				__( 'Links', 'link-audit-reports' ),
				__( 'Posts', 'link-audit-reports' ),
				__( 'Follow', 'link-audit-reports' ),
				__( 'Nofollow', 'link-audit-reports' ),
				__( 'Sponsored', 'link-audit-reports' ),
				__( 'UGC', 'link-audit-reports' ),
				__( 'Added by', 'link-audit-reports' ),
				__( 'First seen', 'link-audit-reports' ),
				__( 'Last seen', 'link-audit-reports' ),
				__( 'List', 'link-audit-reports' ),
			)
		);

		for ( $offset = 0; ; $offset += self::CHUNK ) {
			$rows = Link_Audit_Reports_DB::domains( $filters, 'links', 'desc', self::CHUNK, $offset );

			foreach ( $rows as $row ) {
				$entry   = $plugin->listed( $row['domain'] );
				$authors = array_map( array( __CLASS__, 'name' ), array_filter( array_map( 'intval', explode( ',', (string) $row['authors'] ) ) ) );

				self::row(
					$out,
					array(
						$row['domain'],
						(int) $row['links'],
						(int) $row['posts'],
						(int) $row['follow'],
						(int) $row['nofollow'],
						(int) $row['sponsored'],
						(int) $row['ugc'],
						implode( '; ', $authors ),
						self::date( $row['first_seen'] ),
						self::date( $row['last_seen'] ),
						$entry ? $entry['list'] : '',
					)
				);
			}

			if ( count( $rows ) < self::CHUNK ) {
				return;
			}
		}
	}

	/**
	 * One row per link.
	 *
	 * @param resource $out     Output stream.
	 * @param array    $filters Filters.
	 * @return void
	 */
	private static function links( $out, array $filters ) {
		self::row(
			$out,
			array(
				__( 'Domain', 'link-audit-reports' ),
				__( 'URL', 'link-audit-reports' ),
				__( 'Link text', 'link-audit-reports' ),
				__( 'Rel', 'link-audit-reports' ),
				__( 'Post ID', 'link-audit-reports' ),
				__( 'Post title', 'link-audit-reports' ),
				__( 'Post type', 'link-audit-reports' ),
				__( 'Post status', 'link-audit-reports' ),
				__( 'Post URL', 'link-audit-reports' ),
				__( 'Edit URL', 'link-audit-reports' ),
				__( 'Author', 'link-audit-reports' ),
				__( 'Added by', 'link-audit-reports' ),
				__( 'First seen', 'link-audit-reports' ),
				__( 'Last seen', 'link-audit-reports' ),
			)
		);

		$after = 0;

		do {
			$rows  = Link_Audit_Reports_DB::links_after( $filters, $after, self::CHUNK );
			$count = count( $rows );

			_prime_post_caches( array_unique( array_map( 'intval', wp_list_pluck( $rows, 'post_id' ) ) ), false, false );

			foreach ( $rows as $row ) {
				$after = (int) $row['id'];
				$post  = get_post( (int) $row['post_id'] );

				self::row(
					$out,
					array(
						$row['domain'],
						$row['url'],
						$row['anchor'],
						$row['rel'],
						(int) $row['post_id'],
						$post ? wp_strip_all_tags( get_the_title( $post ) ) : '',
						$row['post_type'],
						$post ? $post->post_status : '',
						$post ? (string) get_permalink( $post ) : '',
						$post ? admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' ) : '',
						self::name( (int) $row['post_author'] ),
						self::name( (int) $row['added_by'] ),
						self::date( $row['first_seen'] ),
						self::date( $row['last_seen'] ),
					)
				);
			}

			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( self::CHUNK === $count );
	}

	/**
	 * Write one row, with text cells made safe for spreadsheets.
	 *
	 * @param resource $out    Output stream.
	 * @param array    $fields Cells.
	 * @return void
	 */
	private static function row( $out, array $fields ) {
		foreach ( $fields as $i => $field ) {
			if ( is_string( $field ) ) {
				$fields[ $i ] = self::cell( $field );
			}
		}

		fputcsv( $out, $fields, ',', '"', '\\' );
	}

	/**
	 * A text cell a spreadsheet won't run as a formula.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function cell( $text ) {
		$text = (string) $text;

		return '' !== $text && false !== strpos( "=+-@\t\r", $text[0] ) ? "'" . $text : $text;
	}

	/**
	 * A user's display name.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function name( $user_id ) {
		if ( ! isset( self::$names[ $user_id ] ) ) {
			$user                    = $user_id ? get_userdata( $user_id ) : false;
			self::$names[ $user_id ] = $user ? $user->display_name : '';
		}

		return self::$names[ $user_id ];
	}

	/**
	 * A Unix time as a date in the site's time zone.
	 *
	 * @param mixed $time Unix time.
	 * @return string
	 */
	private static function date( $time ) {
		return (int) $time ? (string) wp_date( 'Y-m-d', (int) $time ) : '';
	}
}
