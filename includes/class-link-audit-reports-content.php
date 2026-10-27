<?php
/**
 * Finds the outbound links in post content and changes them.
 *
 * A link is the <a> tag around it, read with bounded patterns (no DOM
 * parsing, so it's fast and works on any WordPress version). Changes are
 * surgical: adding or removing rel values touches only the rel attribute of
 * the opening tag, and stripping a link removes the <a> and </a> tags and
 * keeps everything between them. The rest of the post stays byte for byte
 * the same.
 *
 * Each change reports where it was made and what was there before, so an
 * undo can put back exactly those spots.
 *
 * @package Link_Audit_Reports
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content helper.
 */
final class Link_Audit_Reports_Content {

	/**
	 * An <a> opening tag. Quoted values may contain ">", so they're skipped
	 * whole. Possessive quantifiers keep it linear on any input.
	 */
	const TAG = '/<a(?=[\s>\/])(?:[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>/i';

	/**
	 * One attribute in an opening tag: 1 name, then 2, 3 or 4 the value
	 * (double-quoted, single-quoted or bare).
	 */
	const ATTR = '/([^\s"\'>\/=]++)(?:\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s"\'=<>`]++)))?/';

	/**
	 * The rel values this plugin reads and changes.
	 */
	const REL = array( 'nofollow', 'sponsored', 'ugc' );

	/**
	 * Most characters of link text kept.
	 */
	const ANCHOR_CHARS = 200;

	/**
	 * Every <a> tag in some HTML, in order.
	 *
	 * @param string $html HTML.
	 * @return array[] Each: start, open_end (offset after the opening tag),
	 *                 end (offset after </a>, or 0 if the tag never closes
	 *                 before the next link), tag, attrs (from attributes()).
	 */
	public static function tags( $html ) {
		$html = (string) $html;

		if ( false === stripos( $html, '<a' ) || ! preg_match_all( self::TAG, $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$tags  = array();
		$count = count( $matches[0] );

		foreach ( $matches[0] as $i => $match ) {
			$start    = (int) $match[1];
			$open_end = $start + strlen( $match[0] );
			$next     = $i + 1 < $count ? (int) $matches[0][ $i + 1 ][1] : strlen( $html );
			$end      = 0;

			// The link ends at its </a>, as long as that comes before the next
			// link opens (links can't nest, so anything else is broken markup).
			// Only that stretch is searched, so the whole pass stays linear.
			if ( preg_match( '#</a\s*>#i', substr( $html, $open_end, $next - $open_end ), $close, PREG_OFFSET_CAPTURE ) ) {
				$end = $open_end + (int) $close[0][1] + strlen( $close[0][0] );
			}

			$tags[] = array(
				'start'    => $start,
				'open_end' => $open_end,
				'end'      => $end,
				'tag'      => $match[0],
				'attrs'    => self::attributes( $match[0] ),
			);
		}

		return $tags;
	}

	/**
	 * The attributes of an opening tag. When one is repeated, the first wins,
	 * as it does in browsers.
	 *
	 * @param string $tag Opening tag.
	 * @return array<string,array{value: string, offset: int, length: int}> Lowercase name => value
	 *         (entities decoded) and the position of the whole attribute in the tag.
	 */
	public static function attributes( $tag ) {
		$attrs = array();
		$inner = (string) substr( $tag, 2, -1 );

		if ( ! preg_match_all( self::ATTR, $inner, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			return $attrs;
		}

		foreach ( $matches as $m ) {
			$name = strtolower( $m[1][0] );

			if ( isset( $attrs[ $name ] ) ) {
				continue;
			}

			$value = '';
			foreach ( array( 2, 3, 4 ) as $group ) {
				if ( isset( $m[ $group ] ) && -1 !== $m[ $group ][1] ) {
					$value = $m[ $group ][0];
					break;
				}
			}

			$attrs[ $name ] = array(
				'value'  => self::decode( $value ),
				'offset' => 2 + (int) $m[0][1],
				'length' => strlen( $m[0][0] ),
			);
		}

		return $attrs;
	}

	/**
	 * Every outbound link in some HTML.
	 *
	 * Outbound means an absolute http(s) or protocol-relative link to a host
	 * other than this site's. Relative links, anchors, mailto:, tel: and the
	 * like are left out.
	 *
	 * @param string   $html           Post content.
	 * @param string[] $internal_hosts This site's hosts, as host() returns them.
	 * @return array[] Each: index (of the tag in tags()), url, domain, nth (the
	 *                 how-manyth link to that same URL in the post, from 0),
	 *                 anchor, rel (the rel values as written), nofollow,
	 *                 sponsored, ugc (bools).
	 */
	public static function outbound( $html, array $internal_hosts ) {
		$links = array();
		$seen  = array();

		foreach ( self::tags( $html ) as $index => $tag ) {
			$link = self::describe( $html, $tag, $internal_hosts );

			if ( ! $link ) {
				continue;
			}

			$seen[ $link['url'] ] = isset( $seen[ $link['url'] ] ) ? $seen[ $link['url'] ] + 1 : 0;

			$link['index'] = $index;
			$link['nth']   = $seen[ $link['url'] ];
			$links[]       = $link;
		}

		return $links;
	}

	/**
	 * Details of one tag, if it's an outbound link.
	 *
	 * @param string   $html           Post content.
	 * @param array    $tag            Tag from tags().
	 * @param string[] $internal_hosts This site's hosts.
	 * @return array|null
	 */
	private static function describe( $html, array $tag, array $internal_hosts ) {
		if ( ! isset( $tag['attrs']['href'] ) ) {
			return null;
		}

		$url    = self::url( $tag['attrs']['href']['value'] );
		$domain = '' === $url ? '' : self::host( $url );

		if ( '' === $domain || in_array( $domain, $internal_hosts, true ) ) {
			return null;
		}

		$rel    = isset( $tag['attrs']['rel'] ) ? self::tokens( $tag['attrs']['rel']['value'] ) : array();
		$lower  = array_map( 'strtolower', $rel );
		$anchor = '';

		if ( $tag['end'] ) {
			$inner  = substr( $html, $tag['open_end'], $tag['end'] - $tag['open_end'] );
			$inner  = (string) preg_replace( '#</a\s*>$#i', '', $inner );
			$anchor = self::text( $inner );

			// An image link: use its alt text, so the report says what it is.
			if ( '' === $anchor && strlen( $inner ) < 5000 && preg_match( '/<img\s[^>]*?\balt\s*=\s*(["\'])(.*?)\1/is', $inner, $alt ) ) {
				$anchor = trim( self::decode( $alt[2] ) );
				$anchor = '' === $anchor ? '' : '[' . $anchor . ']';
			}
		}

		return array(
			'url'       => $url,
			'domain'    => $domain,
			'anchor'    => self::cut( $anchor, self::ANCHOR_CHARS ),
			'rel'       => implode( ' ', $rel ),
			'nofollow'  => in_array( 'nofollow', $lower, true ),
			'sponsored' => in_array( 'sponsored', $lower, true ),
			'ugc'       => in_array( 'ugc', $lower, true ),
		);
	}

	/**
	 * A link as the absolute http(s) URL it points at, or '' if it isn't one
	 * (relative, an anchor, mailto:, a shortcode or template placeholder).
	 *
	 * It's read the way browsers read it (the WHATWG URL rules for http and
	 * https), so a link can't hide from the audit or the blacklist behind
	 * something a browser ignores: tabs and newlines are dropped, backslashes
	 * count as slashes, and "\\host" or "https:\\host" is a link to host.
	 *
	 * @param string $href Decoded href.
	 * @return string
	 */
	public static function url( $href ) {
		$href = str_replace( array( "\t", "\n", "\r" ), '', trim( (string) $href ) );

		if ( ! preg_match( '#^(?:(https?):)?[/\\\\]{2,}#i', $href, $m ) ) {
			return '';
		}

		$href = strtolower( isset( $m[1] ) && '' !== $m[1] ? $m[1] : 'https' ) . '://' . str_replace( '\\', '/', substr( $href, strlen( $m[0] ) ) );

		if ( strlen( $href ) > 2000 || '' === self::host( $href ) ) {
			return '';
		}

		return $href;
	}

	/**
	 * The host a URL points at, as it's grouped in the report: lowercase,
	 * without "www." or a trailing dot. '' when there isn't a valid one.
	 *
	 * The host is read as browsers read it: the authority ends at the first
	 * "/", "?" or "#", and everything up to its last "@" (the user name) and
	 * a ":port" are dropped. PHP's parse_url() disagrees with browsers on
	 * some URLs, so it isn't used.
	 *
	 * @param string $url Absolute URL, or a bare host (example.com/path).
	 * @return string
	 */
	public static function host( $url ) {
		$url = trim( (string) $url );

		if ( ! preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $url ) ) {
			$url = 'http://' . ltrim( $url, '/' );
		}

		$authority = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://([^/?\#\\\\]*).*$#is', '$1', $url );
		$at        = strrpos( $authority, '@' );
		$host      = false === $at ? $authority : substr( $authority, $at + 1 );
		$host      = '[' === substr( $host, 0, 1 ) ? (string) strstr( $host . ']', ']', true ) . ']' : (string) preg_replace( '/:\d*$/', '', $host );

		// Browsers decode %-escapes in a host and treat ideographic full stops as dots.
		$host = str_replace( array( '。', '．', '｡' ), '.', rawurldecode( $host ) );
		$host = function_exists( 'mb_strtolower' ) ? mb_strtolower( $host, 'UTF-8' ) : strtolower( $host );
		$host = rtrim( $host, '.' );

		if ( 0 === strpos( $host, 'www.' ) ) {
			$host = substr( $host, 4 );
		}

		// Labels of letters (any script), digits and dashes, or an IPv6 address.
		if ( '' === $host || strlen( $host ) > 191 || ! preg_match( '/^[\p{L}\p{N}_\-]++(?:\.[\p{L}\p{N}_\-]++)*+$|^\[[0-9a-f:.]++\]$/u', $host ) ) {
			return '';
		}

		return $host;
	}

	/**
	 * Change some of the links in some HTML.
	 *
	 * @param string   $html    Post content.
	 * @param string   $action  nofollow, sponsored, ugc (add that value),
	 *                          dofollow (remove all three) or strip (remove the
	 *                          link, keep its text).
	 * @param callable $pick    Gets each outbound link (as outbound() returns
	 *                          it), returns whether to change it.
	 * @param string[] $internal_hosts This site's hosts.
	 * @param array    $changes Set to the changes made, in order: each
	 *                          array( offset in the new HTML, before, after ).
	 * @return string The new HTML (the same string if nothing changed).
	 */
	public static function transform( $html, $action, $pick, array $internal_hosts, &$changes = array() ) {
		$html    = (string) $html;
		$changes = array();
		$out     = '';
		$cursor  = 0;
		$tags    = self::tags( $html );

		foreach ( self::outbound( $html, $internal_hosts ) as $link ) {
			if ( ! call_user_func( $pick, $link ) ) {
				continue;
			}

			$tag    = $tags[ $link['index'] ];
			$start  = $tag['start'];
			$length = $tag['open_end'] - $tag['start'];
			$after  = null;

			if ( 'strip' === $action ) {
				if ( self::is_button( $tag ) ) {
					// A Buttons block needs its <a> to stay a button: drop the
					// link from it instead, as the editor does with no URL.
					$after = self::without( $tag['tag'], $tag['attrs'], array( 'href', 'target', 'rel' ) );
				} elseif ( $tag['end'] ) {
					$length = $tag['end'] - $tag['start'];
					$after  = (string) preg_replace( '#</a\s*>$#i', '', substr( $html, $tag['open_end'], $tag['end'] - $tag['open_end'] ) );
				}
			} elseif ( 'dofollow' === $action ) {
				$after = self::with_rel( $tag['tag'], $tag['attrs'], array(), self::REL );
			} elseif ( in_array( $action, self::REL, true ) ) {
				$after = self::with_rel( $tag['tag'], $tag['attrs'], array( $action ), array() );
			}

			$before = substr( $html, $start, $length );

			if ( null === $after || $after === $before ) {
				continue;
			}

			$out      .= substr( $html, $cursor, $start - $cursor );
			$changes[] = array( strlen( $out ), $before, $after );
			$out      .= $after;
			$cursor    = $start + $length;
		}

		if ( ! $changes ) {
			return $html;
		}

		return $out . substr( $html, $cursor );
	}

	/**
	 * Put changes back.
	 *
	 * When the content is exactly as the change left it, every spot is put
	 * back by its position. Otherwise (the post was edited since) a change is
	 * put back only where what it wrote still appears exactly as many times as
	 * it was written, so nothing else in the post can be touched by mistake.
	 *
	 * @param string $html    Post content now.
	 * @param array  $changes Changes from transform().
	 * @param bool   $exact   Whether the content is exactly as transform() returned it.
	 * @param int    $undone  Set to how many changes were put back.
	 * @return string
	 */
	public static function revert( $html, array $changes, $exact, &$undone = 0 ) {
		$html   = (string) $html;
		$undone = 0;
		$valid  = array();

		foreach ( $changes as $change ) {
			if ( is_array( $change ) && 3 === count( $change ) && is_int( $change[0] ) && is_string( $change[1] ) && is_string( $change[2] ) ) {
				$valid[] = $change;
			}
		}

		if ( $exact ) {
			// Last first, so earlier positions stay right.
			usort(
				$valid,
				function ( $a, $b ) {
					return $b[0] - $a[0];
				}
			);

			foreach ( $valid as $change ) {
				list( $offset, $before, $after ) = $change;

				if ( substr( $html, $offset, strlen( $after ) ) === $after ) {
					$html = substr_replace( $html, $before, $offset, strlen( $after ) );
					++$undone;
				}
			}

			return $html;
		}

		$groups = array();
		foreach ( $valid as $change ) {
			$key = md5( $change[1] ) . md5( $change[2] );

			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'before' => $change[1],
					'after'  => $change[2],
					'count'  => 0,
				);
			}

			++$groups[ $key ]['count'];
		}

		foreach ( $groups as $group ) {
			if ( '' === $group['after'] || substr_count( $html, $group['after'] ) !== $group['count'] ) {
				continue;
			}

			$html    = str_replace( $group['after'], $group['before'], $html );
			$undone += $group['count'];
		}

		return $html;
	}

	/**
	 * An opening tag with rel values added and removed. Other rel values
	 * (noopener, noreferrer, external) stay as they are.
	 *
	 * @param string   $tag    Opening tag.
	 * @param array    $attrs  Its attributes().
	 * @param string[] $add    Values to add.
	 * @param string[] $remove Values to remove.
	 * @return string
	 */
	private static function with_rel( $tag, array $attrs, array $add, array $remove ) {
		$current = isset( $attrs['rel'] ) ? self::tokens( $attrs['rel']['value'] ) : array();
		$tokens  = array();

		foreach ( $current as $token ) {
			if ( ! in_array( strtolower( $token ), $remove, true ) ) {
				$tokens[] = $token;
			}
		}

		$lower = array_map( 'strtolower', $tokens );
		foreach ( $add as $token ) {
			if ( ! in_array( $token, $lower, true ) ) {
				$tokens[] = $token;
			}
		}

		if ( $tokens === $current ) {
			return $tag;
		}

		if ( ! $tokens ) {
			return self::without( $tag, $attrs, array( 'rel' ) );
		}

		$rel = 'rel="' . esc_attr( implode( ' ', $tokens ) ) . '"';

		if ( isset( $attrs['rel'] ) ) {
			return substr_replace( $tag, $rel, $attrs['rel']['offset'], $attrs['rel']['length'] );
		}

		// New attribute: just before the closing ">", or before "/>" when the
		// slash isn't the end of a bare value like href=https://example.com/.
		$end = strlen( $tag ) - 1;
		if ( preg_match( '#[\s"\']/>$#', $tag ) ) {
			--$end;
		}

		return rtrim( substr( $tag, 0, $end ) ) . ' ' . $rel . substr( $tag, $end );
	}

	/**
	 * An opening tag without some attributes (and the space before each).
	 *
	 * @param string   $tag   Opening tag.
	 * @param array    $attrs Its attributes().
	 * @param string[] $names Attributes to remove.
	 * @return string
	 */
	private static function without( $tag, array $attrs, array $names ) {
		$spots = array();

		foreach ( $names as $name ) {
			if ( isset( $attrs[ $name ] ) ) {
				$spots[] = $attrs[ $name ];
			}
		}

		// Last first, so earlier offsets stay right.
		usort(
			$spots,
			function ( $a, $b ) {
				return $b['offset'] - $a['offset'];
			}
		);

		foreach ( $spots as $spot ) {
			$before = rtrim( substr( $tag, 0, $spot['offset'] ) );
			$after  = substr( $tag, $spot['offset'] + $spot['length'] );
			$tag    = $before . ( '' !== $after && ! in_array( $after[0], array( ' ', '>', '/' ), true ) ? ' ' : '' ) . $after;
		}

		return $tag;
	}

	/**
	 * Whether a tag is the link of a Buttons block.
	 *
	 * @param array $tag Tag from tags().
	 * @return bool
	 */
	private static function is_button( array $tag ) {
		return isset( $tag['attrs']['class'] ) && false !== strpos( ' ' . $tag['attrs']['class']['value'] . ' ', 'wp-block-button__link' );
	}

	/**
	 * Space-separated values, as written.
	 *
	 * @param string $value Attribute value.
	 * @return string[]
	 */
	private static function tokens( $value ) {
		return preg_split( '/\s+/', trim( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Plain text from HTML: no tags or shortcodes, entities decoded,
	 * whitespace collapsed. Only bounded patterns, so it's fast on any input.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function text( $html ) {
		$text = strip_tags( (string) $html ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Linear time on any input; wp_strip_all_tags() isn't.
		$text = (string) preg_replace( '/\[\/?[a-z][a-z0-9_-]{0,50}(?:\s[^\]\[]{0,300})?\]/i', ' ', $text );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = (string) preg_replace( '/\s+/u', ' ', $text );

		return trim( $text );
	}

	/**
	 * Shorten text to a length, at a word boundary, with an ellipsis.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Most characters.
	 * @return string
	 */
	public static function cut( $text, $chars ) {
		$text = trim( (string) $text );

		if ( ! function_exists( 'mb_strlen' ) ) {
			return strlen( $text ) > $chars ? substr( $text, 0, $chars ) . '…' : $text;
		}

		if ( mb_strlen( $text ) <= $chars ) {
			return $text;
		}

		$text  = mb_substr( $text, 0, $chars );
		$space = mb_strrpos( $text, ' ' );

		return ( false !== $space && $space > $chars / 2 ? mb_substr( $text, 0, $space ) : $text ) . '…';
	}

	/**
	 * An attribute value as the browser reads it.
	 *
	 * @param string $raw Value from the HTML.
	 * @return string
	 */
	public static function decode( $raw ) {
		return html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
