<?php
/**
 * Main plugin orchestrator: owns the settings and the domain lists, and boots
 * the scanner, the change runner and the admin side.
 *
 * @package Link_Audit_Reports
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin singleton.
 */
final class Link_Audit_Reports_Plugin {

	/**
	 * Option key holding the plugin's settings array.
	 */
	const OPTION = 'link_audit_reports_settings';

	/**
	 * Option key holding the whitelist and blacklist.
	 */
	const LISTS_OPTION = 'link_audit_reports_lists';

	/**
	 * What the blacklist can do to a link.
	 */
	const BLACKLIST_MODES = array( 'strip', 'nofollow' );

	/**
	 * Single shared instance.
	 *
	 * @var Link_Audit_Reports_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Scanner.
	 *
	 * @var Link_Audit_Reports_Scanner
	 */
	private $scanner;

	/**
	 * Change runner.
	 *
	 * @var Link_Audit_Reports_Jobs
	 */
	private $jobs;

	/**
	 * Get (and lazily create) the shared instance.
	 *
	 * @return Link_Audit_Reports_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire everything up.
	 */
	private function __construct() {
		$this->scanner = new Link_Audit_Reports_Scanner( $this );
		$this->jobs    = new Link_Audit_Reports_Jobs( $this, $this->scanner );

		if ( is_admin() ) {
			new Link_Audit_Reports_Admin( $this );
		}

		add_action( 'init', array( $this, 'maybe_install' ), 20 );
		add_action( 'add_option_' . self::OPTION, array( $this, 'settings_added' ), 10, 2 );
		add_action( 'update_option_' . self::OPTION, array( $this, 'settings_changed' ), 10, 2 );
	}

	/**
	 * Create the tables on a site that doesn't have them yet (a network
	 * activation, or an update that changed them), and scan it the first time.
	 * Runs after init, so every post type is registered before the scan counts.
	 *
	 * @return void
	 */
	public function maybe_install() {
		if ( Link_Audit_Reports_DB::maybe_install() ) {
			Link_Audit_Reports_Scanner::start();
		}
	}

	/**
	 * The scanner.
	 *
	 * @return Link_Audit_Reports_Scanner
	 */
	public function scanner() {
		return $this->scanner;
	}

	/**
	 * The change runner.
	 *
	 * @return Link_Audit_Reports_Jobs
	 */
	public function jobs() {
		return $this->jobs;
	}

	// Settings ----------------------------------------------------------------

	/**
	 * Default value for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'post_types' => array(), // Empty = every public type, including ones added later.
			'drafts'     => 0,
		);
	}

	/**
	 * All settings, with defaults filled in.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$saved = get_option( self::OPTION, array() );

		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key from {@see defaults()}.
	 * @return mixed Null for an unknown key.
	 */
	public function get_setting( $key ) {
		$settings = $this->get_settings();

		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Post types that can be audited: every public type except attachments.
	 *
	 * @return array<string,string> Name => label.
	 */
	public static function available_post_types() {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $object ) {
			if ( 'attachment' !== $name ) {
				$types[ $name ] = $object->labels->name;
			}
		}

		return $types;
	}

	/**
	 * Post types being audited.
	 *
	 * @return string[]
	 */
	public function post_types() {
		$available = array_keys( self::available_post_types() );
		$chosen    = (array) $this->get_setting( 'post_types' );
		$types     = $chosen ? array_values( array_intersect( $chosen, $available ) ) : $available;

		/**
		 * Filters the post types whose links are audited.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_filter( (array) apply_filters( 'link_audit_reports_post_types', $types ), 'is_string' ) );
	}

	/**
	 * Post statuses being audited.
	 *
	 * @return string[]
	 */
	public function statuses() {
		$statuses = array( 'publish', 'future', 'private' );

		if ( $this->get_setting( 'drafts' ) ) {
			$statuses[] = 'draft';
			$statuses[] = 'pending';
		}

		return $statuses;
	}

	/**
	 * Whether a post's links are audited.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public function audits( WP_Post $post ) {
		if ( ! in_array( $post->post_type, $this->post_types(), true ) || ! in_array( $post->post_status, $this->statuses(), true ) ) {
			return false;
		}

		/**
		 * Filters whether the plugin leaves a post out of the audit. Its links
		 * aren't listed, no change from the report touches it, and the
		 * blacklist leaves it alone when it's saved.
		 *
		 * @param bool    $skip Whether to skip the post. Default false.
		 * @param WP_Post $post The post.
		 */
		return ! apply_filters( 'link_audit_reports_skip_post', false, $post );
	}

	/**
	 * This site's own hosts, whose links aren't outbound.
	 *
	 * @return string[]
	 */
	public function internal_hosts() {
		$hosts = array(
			Link_Audit_Reports_Content::host( home_url() ),
			Link_Audit_Reports_Content::host( site_url() ),
		);

		/**
		 * Filters the hosts treated as this site (their links aren't outbound).
		 * Hosts are lowercase, without "www.".
		 *
		 * @param string[] $hosts Hosts.
		 */
		$hosts = (array) apply_filters( 'link_audit_reports_internal_hosts', $hosts );

		return array_values( array_unique( array_filter( array_map( array( 'Link_Audit_Reports_Content', 'host' ), $hosts ) ) ) );
	}

	/**
	 * The first save of the settings creates the option instead of updating it.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  Saved value.
	 * @return void
	 */
	public function settings_added( $option, $value ) {
		$this->settings_changed( self::defaults(), $value );
	}

	/**
	 * When the audited types or statuses change, scan everything again.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 * @return void
	 */
	public function settings_changed( $old, $new ) {
		$old = wp_parse_args( is_array( $old ) ? $old : array(), self::defaults() );
		$new = wp_parse_args( is_array( $new ) ? $new : array(), self::defaults() );

		if ( $old['post_types'] !== $new['post_types'] || (int) $old['drafts'] !== (int) $new['drafts'] ) {
			Link_Audit_Reports_Scanner::start();
		}
	}

	// Lists -------------------------------------------------------------------

	/**
	 * The whitelist and blacklist.
	 *
	 * @return array{whitelist: array<string,int>, blacklist: array<string,array{mode: string, time: int}>}
	 */
	public function lists() {
		$lists = get_option( self::LISTS_OPTION, array() );
		$lists = is_array( $lists ) ? $lists : array();
		$clean = array(
			'whitelist' => array(),
			'blacklist' => array(),
		);

		if ( isset( $lists['whitelist'] ) && is_array( $lists['whitelist'] ) ) {
			foreach ( $lists['whitelist'] as $domain => $time ) {
				$clean['whitelist'][ (string) $domain ] = (int) $time;
			}
		}

		if ( isset( $lists['blacklist'] ) && is_array( $lists['blacklist'] ) ) {
			foreach ( $lists['blacklist'] as $domain => $entry ) {
				$mode = is_array( $entry ) && isset( $entry['mode'] ) && in_array( $entry['mode'], self::BLACKLIST_MODES, true ) ? $entry['mode'] : 'strip';

				$clean['blacklist'][ (string) $domain ] = array(
					'mode' => $mode,
					'time' => is_array( $entry ) && isset( $entry['time'] ) ? (int) $entry['time'] : 0,
				);
			}
		}

		return $clean;
	}

	/**
	 * Whitelisted domains.
	 *
	 * @return array<string,int> Domain => when it was added.
	 */
	public function whitelist() {
		return $this->lists()['whitelist'];
	}

	/**
	 * Blacklisted domains.
	 *
	 * @return array<string,array{mode: string, time: int}>
	 */
	public function blacklist() {
		return $this->lists()['blacklist'];
	}

	/**
	 * Which list a domain is on, counting its parent domains: a list entry
	 * for example.com covers blog.example.com too.
	 *
	 * @param string $domain Domain.
	 * @return array{list: string, domain: string, mode: string}|null The entry, or null.
	 */
	public function listed( $domain ) {
		$lists = $this->lists();
		$parts = explode( '.', (string) $domain );

		while ( $parts ) {
			$candidate = implode( '.', $parts );

			if ( isset( $lists['blacklist'][ $candidate ] ) ) {
				return array(
					'list'   => 'blacklist',
					'domain' => $candidate,
					'mode'   => $lists['blacklist'][ $candidate ]['mode'],
				);
			}

			if ( isset( $lists['whitelist'][ $candidate ] ) ) {
				return array(
					'list'   => 'whitelist',
					'domain' => $candidate,
					'mode'   => '',
				);
			}

			array_shift( $parts );
		}

		return null;
	}

	/**
	 * Put domains on a list, or take them off both.
	 *
	 * A domain is only ever on one list: adding it to one removes it from the other.
	 *
	 * @param string[] $domains Domains (already validated).
	 * @param string   $list    whitelist, blacklist or none.
	 * @param string   $mode    Blacklist mode.
	 * @return array<string,array|null> What each domain was before: null, or
	 *                                  array( list, mode ), for an undo.
	 */
	public function set_listed( array $domains, $list, $mode = 'strip' ) {
		$lists  = $this->lists();
		$before = array();
		$mode   = in_array( $mode, self::BLACKLIST_MODES, true ) ? $mode : 'strip';

		foreach ( $domains as $domain ) {
			if ( isset( $lists['whitelist'][ $domain ] ) ) {
				$before[ $domain ] = array( 'whitelist', '' );
			} elseif ( isset( $lists['blacklist'][ $domain ] ) ) {
				$before[ $domain ] = array( 'blacklist', $lists['blacklist'][ $domain ]['mode'] );
			} else {
				$before[ $domain ] = null;
			}

			unset( $lists['whitelist'][ $domain ], $lists['blacklist'][ $domain ] );

			if ( 'whitelist' === $list ) {
				$lists['whitelist'][ $domain ] = time();
			} elseif ( 'blacklist' === $list ) {
				$lists['blacklist'][ $domain ] = array(
					'mode' => $mode,
					'time' => time(),
				);
			}
		}

		ksort( $lists['whitelist'] );
		ksort( $lists['blacklist'] );
		update_option( self::LISTS_OPTION, $lists, true );

		return $before;
	}

	/**
	 * Put domains back on the lists they were on (undo of set_listed()).
	 *
	 * @param array $before set_listed()'s return value.
	 * @return void
	 */
	public function restore_listed( array $before ) {
		foreach ( $before as $domain => $was ) {
			$domain = Link_Audit_Reports_Content::host( (string) $domain );

			if ( '' === $domain ) {
				continue;
			}

			if ( is_array( $was ) && isset( $was[0] ) && in_array( $was[0], array( 'whitelist', 'blacklist' ), true ) ) {
				$this->set_listed( array( $domain ), $was[0], isset( $was[1] ) ? (string) $was[1] : 'strip' );
			} else {
				$this->set_listed( array( $domain ), 'none' );
			}
		}
	}

	/**
	 * Check a domain someone typed in: a host with at least one dot (or an
	 * IP address), as the report groups it.
	 *
	 * @param string $input Domain or URL.
	 * @return string The domain, or '' if it isn't valid.
	 */
	public static function clean_domain( $input ) {
		$domain = Link_Audit_Reports_Content::host( sanitize_text_field( (string) $input ) );

		if ( '' === $domain || ( false === strpos( $domain, '.' ) && '[' !== $domain[0] ) ) {
			return '';
		}

		return $domain;
	}
}
