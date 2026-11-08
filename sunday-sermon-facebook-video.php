<?php
/**
 * Plugin Name:       Sunday Sermon Facebook Video
 * Plugin URI:        https://github.com/avdude/Sunday-Sermon-Facebook-Video
 * Description:       Automatically shows your church's latest Facebook service videos (videos, Reels, live streams and video posts) on your WordPress site, with a playlist of past services.
 * Version:           2.4.2
 * Author:            avdude
 * Author URI:        https://avdude.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       sunday-sermon-facebook-video
 * Requires at least: 6.0
 * Tested up to:      7.1
 * Requires PHP:      7.4
 */

defined( 'ABSPATH' ) || exit;

final class SSFV_Sunday_Sermon_Facebook_Video {

	const OPT        = 'ssv_settings';
	const LAST_GOOD  = 'ssv_last_good';
	const LAST_ERROR = 'ssv_last_error';
	const LAST_SCAN  = 'ssv_last_scan';
	const SLUG       = 'sunday-sermon-facebook-video';
	const SLUG_SET   = 'sunday-sermon-facebook-video-settings';

	public static function init() {
		add_shortcode( 'sunday_sermon', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'sunday_sermon_date', array( __CLASS__, 'date_shortcode' ) );
		add_shortcode( 'sunday_sermon_archive', array( __CLASS__, 'archive_shortcode' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_ssv_refresh', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'update_option_' . self::OPT, array( __CLASS__, 'clear_cache' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( __CLASS__, 'action_links' ) );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'page_id'       => 'me',
			'token'         => '',
			'match'         => 'Sunday Worship Service',
			'cache_minutes' => 60,
			'manual_url'    => '',
			'aai_key'       => '',
			'tr_enabled'    => 0,
			'tr_auto'       => 1,
			'tr_limit'      => 8,
			'tr_lang'       => 'en_us',
			'tr_vocab'      => '',
			'anthropic_key' => '',
			'sum_enabled'   => 0,
			'sum_provider'  => 'assemblyai',
			'aai_llm_model' => 'claude-sonnet-4-6',
			'sum_model'     => 'claude-haiku-5-5',
			'sum_trim'      => 1,
			'display'       => 'auto',
			'sunday_blank'  => 1,
			'sunday_only'   => 0,
			'months'        => 0,
			'title_dates'   => 1,
		);
	}

	public static function settings() {
		$saved = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/* ------------------------------------------------------------------
	 * Fetching
	 * ------------------------------------------------------------------ */

	private static function cache_key( $match ) {
		$s = self::settings();
		return 'ssv_l_' . md5( $s['page_id'] . '|' . strtolower( $match ) );
	}

	/**
	 * Newest matching video, or null.
	 */
	public static function get_video( $match = null, $force = false ) {
		$list = self::get_videos( $match, $force );
		return $list ? $list[0] : null;
	}

	/**
	 * All matching videos, newest first (may be empty).
	 */
	public static function get_videos( $match = null, $force = false ) {
		$s     = self::settings();
		$match = ( null === $match ) ? $s['match'] : $match;

		// A manual link always wins (use it for special services or if Facebook access breaks).
		if ( ! empty( $s['manual_url'] ) ) {
			return array(
				array(
					'key'     => 'manual',
					'url'     => $s['manual_url'],
					'title'   => '',
					'created' => '',
					'image'   => '',
					'embed'   => true,
					'live'    => false,
				),
			);
		}

		$key = self::cache_key( $match );

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$last_good = get_option( self::LAST_GOOD, array() );
		$last_good = is_array( $last_good ) ? $last_good : array();

		$result = self::fetch_from_facebook( $match );

		if ( is_wp_error( $result ) ) {
			update_option(
				self::LAST_ERROR,
				array(
					'message' => $result->get_error_message(),
					'time'    => time(),
				),
				false
			);
			// Fall back to the last list we found; retry Facebook in 5 minutes.
			$fallback = ( isset( $last_good[ $key ] ) && is_array( $last_good[ $key ] ) ) ? $last_good[ $key ] : array();
			if ( $fallback ) {
				set_transient( $key, $fallback, 5 * MINUTE_IN_SECONDS );
			}
			return $fallback;
		}

		delete_option( self::LAST_ERROR );
		$last_good[ $key ] = $result;
		update_option( self::LAST_GOOD, $last_good, false );
		$minutes = ! empty( $result[0]['live'] ) ? 5 : max( 5, (int) $s['cache_minutes'] );
		set_transient( $key, $result, $minutes * MINUTE_IN_SECONDS );

		return $result;
	}

	private static function fetch_from_facebook( $match ) {
		$s = self::settings();

		if ( empty( $s['page_id'] ) || empty( $s['token'] ) ) {
			return new WP_Error( 'ssv_config', __( 'Facebook Page ID or Page Access Token is missing.', 'sunday-sermon-facebook-video' ) );
		}

		$base       = 'https://graph.facebook.com/' . rawurlencode( $s['page_id'] );
		$candidates = array();
		$errors     = array();

		$months = (int) $s['months'];
		$cutoff = $months > 0 ? strtotime( '-' . $months . ' months' ) : 0;

		// 1) The Page's videos (matches the video's own title / description).
		$videos = self::graph_get_with_fallback( $base . '/videos', 'id,title,description,permalink_url,created_time', ',picture,embeddable,source,thumbnails{uri,width}', $s['token'], '', $cutoff );
		if ( is_wp_error( $videos ) ) {
			$errors[] = 'videos: ' . $videos->get_error_message();
		} else {
			foreach ( $videos as $v ) {
				if ( empty( $v['id'] ) ) {
					continue;
				}
				$link = ! empty( $v['permalink_url'] ) ? $v['permalink_url'] : '/watch/?v=' . $v['id'];
				$candidates[ 'v' . $v['id'] ] = array(
					'source'  => 'video',
					'text'    => trim( ( isset( $v['title'] ) ? $v['title'] : '' ) . ' ' . ( isset( $v['description'] ) ? $v['description'] : '' ) ),
					'title'   => isset( $v['title'] ) ? $v['title'] : '',
					'url'     => $link,
					'created' => isset( $v['created_time'] ) ? $v['created_time'] : '',
					'image'   => self::best_image( $v ),
					'file'    => isset( $v['source'] ) ? $v['source'] : '',
					'embed'   => ! ( isset( $v['embeddable'] ) && false === $v['embeddable'] ),
				);
			}
		}

		// 1b) The Page's Reels.
		$reels = self::graph_get_with_fallback( $base . '/video_reels', 'id,description,permalink_url,created_time', ',picture,embeddable,source', $s['token'], '', $cutoff );
		if ( is_wp_error( $reels ) ) {
			$errors[] = 'reels: ' . $reels->get_error_message();
		} else {
			foreach ( $reels as $r ) {
				if ( empty( $r['id'] ) ) {
					continue;
				}
				$text = isset( $r['description'] ) ? $r['description'] : '';
				if ( isset( $candidates[ 'v' . $r['id'] ] ) ) {
					$candidates[ 'v' . $r['id'] ]['text'] .= ' ' . $text;
					if ( empty( $candidates[ 'v' . $r['id'] ]['file'] ) && ! empty( $r['source'] ) ) {
						$candidates[ 'v' . $r['id'] ]['file'] = $r['source'];
					}
					continue;
				}
				$candidates[ 'v' . $r['id'] ] = array(
					'source'  => 'reel',
					'text'    => $text,
					'title'   => '',
					'url'     => ! empty( $r['permalink_url'] ) ? $r['permalink_url'] : '/reel/' . $r['id'],
					'created' => isset( $r['created_time'] ) ? $r['created_time'] : '',
					'image'   => self::best_image( $r ),
					'file'    => isset( $r['source'] ) ? $r['source'] : '',
					'embed'   => ! ( isset( $r['embeddable'] ) && false === $r['embeddable'] ),
				);
			}
		}

		// 1c) The Page's live videos (live now, or recorded broadcasts).
		$lives = self::graph_get_with_fallback( $base . '/live_videos', 'id,title,description,status,creation_time,permalink_url', ',video{id,picture,source}', $s['token'], '', $cutoff );
		if ( is_wp_error( $lives ) ) {
			$errors[] = 'live: ' . $lives->get_error_message();
		} else {
			foreach ( $lives as $l ) {
				if ( empty( $l['id'] ) ) {
					continue;
				}
				$status = isset( $l['status'] ) ? strtoupper( $l['status'] ) : '';
				if ( in_array( $status, array( 'UNPUBLISHED', 'SCHEDULED_UNPUBLISHED', 'SCHEDULED_CANCELED', 'SCHEDULED_EXPIRED', 'PROCESSING' ), true ) ) {
					continue;
				}
				$is_live = ( 'LIVE' === $status );
				$text    = trim( ( isset( $l['title'] ) ? $l['title'] : '' ) . ' ' . ( isset( $l['description'] ) ? $l['description'] : '' ) );
				$key     = ! empty( $l['video']['id'] ) ? 'v' . $l['video']['id'] : 'l' . $l['id'];
				if ( isset( $candidates[ $key ] ) ) {
					$candidates[ $key ]['text'] .= ' ' . $text;
					$candidates[ $key ]['live']  = $is_live;
					if ( $is_live ) {
						$candidates[ $key ]['file'] = ''; // A live stream isn't a playable file yet.
					}
					continue;
				}
				$link = ! empty( $l['permalink_url'] ) ? $l['permalink_url'] : ( ! empty( $l['video']['id'] ) ? '/watch/?v=' . $l['video']['id'] : '' );
				if ( '' === $link ) {
					continue;
				}
				$candidates[ $key ] = array(
					'source'  => 'live',
					'text'    => $text,
					'title'   => isset( $l['title'] ) ? $l['title'] : '',
					'url'     => $link,
					'created' => isset( $l['creation_time'] ) ? $l['creation_time'] : '',
					'image'   => ! empty( $l['video']['picture'] ) ? $l['video']['picture'] : '',
					'file'    => ( ! $is_live && ! empty( $l['video']['source'] ) ) ? $l['video']['source'] : '',
					'embed'   => true,
					'live'    => $is_live,
				);
			}
		}

		// 2) The Page's posts that contain a video (matches the post text and the attachment title).
		$posts = self::graph_get_with_fallback( $base . '/posts', 'id,message,created_time,permalink_url,attachments{media_type,type,title,description,url,target', ',media}', $s['token'], '}', $cutoff );
		if ( is_wp_error( $posts ) ) {
			$errors[] = 'posts: ' . $posts->get_error_message();
		} else {
			foreach ( $posts as $p ) {
				$atts = isset( $p['attachments']['data'] ) && is_array( $p['attachments']['data'] ) ? $p['attachments']['data'] : array();
				foreach ( $atts as $a ) {
					$kind = strtolower( ( isset( $a['media_type'] ) ? $a['media_type'] : '' ) . ' ' . ( isset( $a['type'] ) ? $a['type'] : '' ) );
					if ( false === strpos( $kind, 'video' ) ) {
						continue;
					}
					$vid  = isset( $a['target']['id'] ) ? $a['target']['id'] : '';
					$link = isset( $a['url'] ) ? $a['url'] : '';
					if ( ! preg_match( '#facebook\.com/.+/videos/|facebook\.com/watch|fb\.watch|facebook\.com/reel#i', $link ) ) {
						$link = $vid ? 'https://www.facebook.com/watch/?v=' . $vid : ( isset( $p['permalink_url'] ) ? $p['permalink_url'] : '' );
					}
					if ( '' === $link ) {
						continue;
					}
					$key = $vid ? 'v' . $vid : 'p' . $p['id'];
					$text = trim( ( isset( $p['message'] ) ? $p['message'] : '' ) . ' ' . ( isset( $a['title'] ) ? $a['title'] : '' ) . ' ' . ( isset( $a['description'] ) ? $a['description'] : '' ) );
					if ( isset( $candidates[ $key ] ) ) {
						// Same video seen in both lists: combine the text so either can match.
						$candidates[ $key ]['text'] .= ' ' . $text;
						if ( '' === $candidates[ $key ]['image'] && ! empty( $a['media']['image']['src'] ) ) {
							$candidates[ $key ]['image'] = $a['media']['image']['src'];
						}
						if ( empty( $candidates[ $key ]['file'] ) && empty( $candidates[ $key ]['live'] ) && ! empty( $a['media']['source'] ) ) {
							$candidates[ $key ]['file'] = $a['media']['source'];
						}
						continue;
					}
					$candidates[ $key ] = array(
						'source'  => 'post',
						'image'   => ! empty( $a['media']['image']['src'] ) ? $a['media']['image']['src'] : '',
						'file'    => ! empty( $a['media']['source'] ) ? $a['media']['source'] : '',
						'embed'   => true,
						'text'    => $text,
						'title'   => isset( $a['title'] ) ? $a['title'] : '',
						'url'     => $link,
						'created' => isset( $p['created_time'] ) ? $p['created_time'] : '',
					);
					break;
				}
			}
		}

		foreach ( $candidates as $k => $c ) {
			$candidates[ $k ]['key'] = $k;
		}
		$candidates = array_values( $candidates );

		// Use a date written in the title/description (e.g. "January 31, 2021" or "4/6") instead of the post date.
		foreach ( $candidates as $i => $c ) {
			$candidates[ $i ]['posted'] = $c['created'];
			if ( ! empty( $s['title_dates'] ) && $c['created'] ) {
				$found = self::date_from_text( $c['title'], $c['created'] );
				if ( ! $found && $c['text'] !== $c['title'] ) {
					$found = self::date_from_text( $c['text'], $c['created'] );
				}
				if ( $found ) {
					$candidates[ $i ]['created'] = $found;
				}
			}
		}

		// Newest first, by service date.
		usort(
			$candidates,
			static function ( $a, $b ) {
				return strtotime( $b['created'] ? $b['created'] : '0' ) - strtotime( $a['created'] ? $a['created'] : '0' );
			}
		);

		// Keep only videos inside the look-back window.
		if ( $cutoff ) {
			$candidates = array_values(
				array_filter(
					$candidates,
					static function ( $c ) use ( $cutoff ) {
						$ts = $c['created'] ? strtotime( $c['created'] ) : false;
						return $ts && $ts >= $cutoff;
					}
				)
			);
		}

		// Newest first.
		usort(
			$candidates,
			static function ( $a, $b ) {
				return strtotime( $b['created'] ? $b['created'] : '0' ) - strtotime( $a['created'] ? $a['created'] : '0' );
			}
		);

		// Decide, for each video, whether it's included and why.
		foreach ( $candidates as $i => $c ) {
			$phrase = self::text_matches( $c['text'], $match );
			$blank  = self::is_blank_sunday( $c, $s );
			if ( ! $phrase && ! $blank ) {
				$why = '' === trim( (string) $c['text'] )
					? ( empty( $s['sunday_blank'] ) ? 'No: no text (untitled option is off)' : 'No: no text, not posted on a Sunday' )
					: 'No: phrase not found';
				$ok  = false;
			} elseif ( ! empty( $s['sunday_only'] ) && ! self::is_sunday( $c['created'] ) ) {
				$why = 'No: not posted on a Sunday';
				$ok  = false;
			} else {
				$why = $phrase ? 'Yes: phrase' : 'Yes: untitled Sunday video';
				$ok  = true;
			}
			$candidates[ $i ]['ok']  = $ok;
			$candidates[ $i ]['why'] = $why;
		}

		// Save the list of what Facebook returned, for the settings page.
		$scan = array();
		foreach ( array_slice( $candidates, 0, 100 ) as $c ) {
			$ts     = $c['created'] ? strtotime( $c['created'] ) : false;
			$scan[] = array(
				'source'  => ! empty( $c['live'] ) ? 'LIVE NOW' : $c['source'],
				'created' => sanitize_text_field( $c['created'] ),
				'day'     => $ts ? wp_date( 'D', $ts ) : '',
				'posted'  => ( ! empty( $c['posted'] ) && $c['posted'] !== $c['created'] ) ? sanitize_text_field( $c['posted'] ) : '',
				'text'    => sanitize_text_field( wp_html_excerpt( $c['text'], 140, '…' ) ),
				'why'     => $c['why'],
			);
		}
		update_option(
			self::LAST_SCAN,
			array(
				'time'   => time(),
				'items'  => $scan,
				'errors' => array_map( 'sanitize_text_field', $errors ),
			),
			false
		);

		if ( ! $candidates && count( $errors ) >= 4 ) {
			return new WP_Error( 'ssv_api', 'Facebook: ' . implode( ' | ', $errors ) );
		}

		$matches = array();
		foreach ( $candidates as $c ) {
			if ( empty( $c['ok'] ) ) {
				continue;
			}
			$link = $c['url'];
			if ( 0 === strpos( $link, '/' ) ) {
				$link = 'https://www.facebook.com' . $link;
			}
			$matches[] = array(
				'key'     => $c['key'],
				'url'     => esc_url_raw( $link ),
				'title'   => sanitize_text_field( '' !== $c['title'] ? $c['title'] : wp_html_excerpt( $c['text'], 80, '…' ) ),
				'created' => sanitize_text_field( $c['created'] ),
				'image'   => esc_url_raw( $c['image'] ),
				'file'    => ! empty( $c['file'] ) ? esc_url_raw( $c['file'] ) : '',
				'embed'   => (bool) $c['embed'],
				'live'    => ! empty( $c['live'] ),
			);
		}

		if ( $matches ) {
			return $matches;
		}

		/* translators: %s: match phrase */
		return new WP_Error( 'ssv_no_match', sprintf( __( 'No recent video matched "%s". See "What Facebook returned" below.', 'sunday-sermon-facebook-video' ), $match ) );
	}

	/**
	 * True when the option is on and the video has no text and was published on a Sunday (site time zone).
	 */
	private static function is_blank_sunday( $c, $s ) {
		if ( empty( $s['sunday_blank'] ) || '' !== trim( (string) $c['text'] ) ) {
			return false;
		}
		return self::is_sunday( $c['created'] );
	}

	/**
	 * Find a service date written in $text. Returns an ISO 8601 string (noon, site time zone) or ''.
	 * Dates after the post date are ignored (they're usually announcements of upcoming events).
	 */
	private static function date_from_text( $text, $posted ) {
		$text      = (string) $text;
		$posted_ts = strtotime( $posted );
		if ( '' === trim( $text ) || ! $posted_ts ) {
			return '';
		}

		$tz          = wp_timezone();
		$posted_date = ( new DateTimeImmutable( '@' . $posted_ts ) )->setTimezone( $tz );
		$post_year   = (int) $posted_date->format( 'Y' );
		$months      = array(
			'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
			'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
		);

		$found = array(); // Each: array( month, day, year or 0 ).

		if ( preg_match_all( '/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$found[] = array( (int) $x[2], (int) $x[3], (int) $x[1] );
			}
		}
		if ( preg_match_all( '/\b(jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|june?|july?|aug(?:ust)?|sep(?:t(?:ember)?)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?)\.?\s+(\d{1,2})(?:st|nd|rd|th)?\b(?:,?\s+(\d{4})\b)?/i', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$found[] = array( $months[ strtolower( substr( $x[1], 0, 3 ) ) ], (int) $x[2], isset( $x[3] ) ? (int) $x[3] : 0 );
			}
		}
		if ( preg_match_all( '#(?<![\d/.:-])(\d{1,2})[/.](\d{1,2})(?:[/.](\d{2}|\d{4}))?(?![\d/:-])(?!\.\d)#', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $x ) {
				$year = isset( $x[3] ) ? (int) $x[3] : 0;
				if ( $year && $year < 100 ) {
					$year += 2000;
				}
				$found[] = array( (int) $x[1], (int) $x[2], $year );
			}
		}

		$limit = $posted_date->modify( '+1 day' );

		foreach ( $found as $f ) {
			list( $mon, $day, $year ) = $f;
			$try = $year ? array( $year ) : array( $post_year, $post_year - 1 );
			foreach ( $try as $y ) {
				if ( ! checkdate( $mon, $day, $y ) ) {
					continue;
				}
				$d = ( new DateTimeImmutable( 'now', $tz ) )->setDate( $y, $mon, $day )->setTime( 12, 0 );
				if ( $d > $limit ) {
					continue; // In the future relative to the post: an announcement, not a service date.
				}
				if ( ! $year && $d < $posted_date->modify( '-180 days' ) ) {
					continue; // A yearless date that far back is probably not this service.
				}
				return $d->format( 'c' );
			}
		}

		return '';
	}

	/**
	 * Published on a Sunday, in the site's time zone.
	 */
	private static function is_sunday( $created ) {
		$ts = $created ? strtotime( $created ) : false;
		return $ts && '0' === wp_date( 'w', $ts );
	}

	/**
	 * True if the text contains any of the comma-separated phrases (case-insensitive).
	 * An empty match list matches everything.
	 */
	private static function text_matches( $text, $match ) {
		$phrases = array_filter( array_map( 'trim', explode( ',', (string) $match ) ), 'strlen' );
		if ( ! $phrases ) {
			return true;
		}
		foreach ( $phrases as $phrase ) {
			if ( false !== stripos( (string) $text, $phrase ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Try the request with extra (thumbnail) fields; if Facebook rejects them, retry without.
	 */
	private static function graph_get_with_fallback( $endpoint, $fields, $extra, $token, $suffix = '', $cutoff = 0 ) {
		$rich = self::graph_get( $endpoint, $fields . $extra, $token, $cutoff );
		if ( ! is_wp_error( $rich ) ) {
			return $rich;
		}
		return self::graph_get( $endpoint, $fields . $suffix, $token, $cutoff );
	}

	/**
	 * Pick the largest thumbnail Facebook gave us.
	 */
	private static function best_image( $node ) {
		$best  = isset( $node['picture'] ) ? $node['picture'] : '';
		$width = 0;
		if ( isset( $node['thumbnails']['data'] ) && is_array( $node['thumbnails']['data'] ) ) {
			foreach ( $node['thumbnails']['data'] as $t ) {
				if ( ! empty( $t['uri'] ) && ( isset( $t['width'] ) ? (int) $t['width'] : 0 ) >= $width ) {
					$width = isset( $t['width'] ) ? (int) $t['width'] : 0;
					$best  = $t['uri'];
				}
			}
		}
		return $best;
	}

	/**
	 * GET a Graph API edge and return its data array, or WP_Error.
	 * With $cutoff (a timestamp), follows Facebook's "next page" links until items are older than the cutoff.
	 */
	private static function graph_get( $endpoint, $fields, $token, $cutoff = 0 ) {
		$url = add_query_arg(
			array(
				'fields'       => rawurlencode( $fields ),
				'limit'        => $cutoff ? 50 : 25,
				'access_token' => rawurlencode( $token ),
			),
			apply_filters( 'ssv_graph_endpoint', $endpoint )
		);

		$all       = array();
		$max_pages = (int) apply_filters( 'ssv_max_pages', 20 );

		for ( $page = 0; $page < $max_pages && $url; $page++ ) {
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) ) {
				return $all ? $all : $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( 200 !== $code ) {
				if ( $all ) {
					return $all; // Keep what we already have.
				}
				$msg = isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
				return new WP_Error( 'ssv_api', $msg );
			}

			$data = ( isset( $body['data'] ) && is_array( $body['data'] ) ) ? $body['data'] : array();
			$all  = array_merge( $all, $data );

			if ( ! $cutoff || ! $data ) {
				break;
			}

			// Stop once this page reaches back past the cutoff.
			$oldest = PHP_INT_MAX;
			foreach ( $data as $item ) {
				$t = isset( $item['created_time'] ) ? $item['created_time'] : ( isset( $item['creation_time'] ) ? $item['creation_time'] : '' );
				$ts = $t ? strtotime( $t ) : false;
				if ( $ts && $ts < $oldest ) {
					$oldest = $ts;
				}
			}
			if ( $oldest < $cutoff ) {
				break;
			}

			$url = isset( $body['paging']['next'] ) ? $body['paging']['next'] : '';
		}

		return $all;
	}

	public static function clear_cache() {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_ssv_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ssv_' ) . '%'
			)
		);
		if ( wp_using_ext_object_cache() && function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'transient' );
		}
	}

	/* ------------------------------------------------------------------
	 * Shortcode
	 * ------------------------------------------------------------------ */

	public static function shortcode( $atts ) {
		$s    = self::settings();
		$atts = shortcode_atts(
			array(
				'match'      => $s['match'],
				'show_title' => 'no',
				'show_date'  => 'yes',
				'display'    => $s['display'],
				'transcript' => 'yes',
				'format'     => '',
				'fallback'   => __( 'This week\'s service video will be posted soon.', 'sunday-sermon-facebook-video' ),
			),
			$atts,
			'sunday_sermon'
		);

		$video = self::get_video( $atts['match'] );

		if ( empty( $video['url'] ) ) {
			return '<p class="ssv-fallback">' . esc_html( $atts['fallback'] ) . '</p>';
		}

		$out = '<div class="ssv-sermon">';

		if ( 'yes' === $atts['show_title'] && '' !== $video['title'] ) {
			$out .= '<h3 class="ssv-title">' . esc_html( $video['title'] ) . '</h3>';
		}

		if ( 'yes' === $atts['show_date'] && '' !== $video['created'] ) {
			$ts = strtotime( $video['created'] );
			if ( $ts ) {
				$out .= '<p class="ssv-date">' . esc_html( wp_date( '' !== $atts['format'] ? $atts['format'] : get_option( 'date_format' ), $ts ) ) . '</p>';
			}
		}

		$out .= self::render_frame( $video, $atts['display'] );
		$out .= '<p class="ssv-link"><a href="' . esc_url( $video['url'] ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Watch on Facebook', 'sunday-sermon-facebook-video' ) . '</a></p>';
		if ( 'no' !== $atts['transcript'] ) {
			$out .= SSFV_Transcripts::render( $video );
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * The 16:9 player box: Facebook player, or a thumbnail that opens Facebook.
	 * With $lazy, the iframe's src is held in data-src until the archive script shows it.
	 */
	private static function render_frame( $video, $display, $lazy = false ) {
		$display = in_array( $display, array( 'auto', 'native', 'embed', 'thumbnail' ), true ) ? $display : 'auto';
		$native  = in_array( $display, array( 'auto', 'native' ), true ) && ! empty( $video['file'] );
		$embed   = ! $native && ( ( 'embed' === $display ) || ( 'auto' === $display && ( ! isset( $video['embed'] ) || $video['embed'] ) ) );

		$src = add_query_arg(
			array(
				'href'      => rawurlencode( $video['url'] ),
				'show_text' => 'false',
			),
			'https://www.facebook.com/plugins/video.php'
		);

		$out = '<div class="ssv-frame" style="position:relative;width:100%;aspect-ratio:16/9;background:#1c1e21;overflow:hidden;">';
		if ( $native ) {
			// Plays the video file directly. If the file link has expired or fails, the thumbnail link below takes over.
			$out .= '<video class="ssv-video" controls playsinline preload="none"'
				. ( ! empty( $video['image'] ) ? ' poster="' . esc_url( $video['image'] ) . '"' : '' )
				. ' style="position:absolute;inset:0;width:100%;height:100%;background:#000;">'
				. '<source src="' . esc_url( $video['file'] ) . '" type="video/mp4"'
				. ' onerror="var v=this.parentNode,t=v.nextElementSibling;if(t){t.style.display=\'block\';}v.remove();">'
				. '</video>';
		}
		if ( $embed ) {
			$out .= '<iframe ' . ( $lazy ? 'data-src' : 'src' ) . '="' . esc_url( $src ) . '" title="' . esc_attr__( 'Sunday sermon video', 'sunday-sermon-facebook-video' ) . '"'
				. ' style="position:absolute;inset:0;width:100%;height:100%;border:0;overflow:hidden"'
				. ' scrolling="no" frameborder="0" allowfullscreen="true" loading="lazy"'
				. ' allow="autoplay; clipboard-write; encrypted-media; picture-in-picture; web-share"></iframe>';
		} else {
			$out .= '<a class="ssv-thumb" href="' . esc_url( $video['url'] ) . '" target="_blank" rel="noopener"'
				. ' style="position:absolute;inset:0;display:' . ( $native ? 'none' : 'block' ) . ';color:#fff;text-decoration:none;"'
				. ' aria-label="' . esc_attr__( 'Watch this service on Facebook', 'sunday-sermon-facebook-video' ) . '">';
			if ( ! empty( $video['image'] ) ) {
				$out .= '<img src="' . esc_url( $video['image'] ) . '" alt="" loading="lazy" onerror="this.remove()"'
					. ' style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.85;">';
			}
			$out .= '<span style="position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:84px;height:84px;border-radius:50%;background:rgba(0,0,0,.6);border:3px solid #fff;display:flex;align-items:center;justify-content:center;">'
				. '<span style="display:block;width:0;height:0;margin-left:8px;border-style:solid;border-width:16px 0 16px 26px;border-color:transparent transparent transparent #fff;"></span></span>';
			$out .= '<span style="position:absolute;left:0;right:0;bottom:0;padding:10px 14px;background:linear-gradient(transparent,rgba(0,0,0,.75));font-size:15px;">'
				. ( ! empty( $video['live'] ) ? esc_html__( '● Live now: watch on Facebook', 'sunday-sermon-facebook-video' ) : esc_html__( 'Watch this service on Facebook', 'sunday-sermon-facebook-video' ) ) . '</span>';
			$out .= '</a>';
		}
		$out .= '</div>';

		return $out;
	}

	/**
	 * [sunday_sermon_archive count="8" format="" display="" match=""]
	 * Newest service in a player, with a playlist of past services beside it.
	 */
	public static function archive_shortcode( $atts ) {
		static $instance = 0;
		$instance++;

		$s    = self::settings();
		$atts = shortcode_atts(
			array(
				'match'    => $s['match'],
				'count'      => 8,
				'format'     => '',
				'show_title' => 'no',
				'show_date'  => 'no',
				'list_title' => __( 'Past services', 'sunday-sermon-facebook-video' ),
				'display'  => $s['display'],
				'transcript' => 'yes',
				'fallback' => __( 'This week\'s service video will be posted soon.', 'sunday-sermon-facebook-video' ),
			),
			$atts,
			'sunday_sermon_archive'
		);

		$videos = array_slice( self::get_videos( $atts['match'] ), 0, max( 1, min( 100, absint( $atts['count'] ) ) ) );

		if ( ! $videos || empty( $videos[0]['url'] ) ) {
			return '<p class="ssv-fallback">' . esc_html( $atts['fallback'] ) . '</p>';
		}

		$format = '' !== $atts['format'] ? $atts['format'] : get_option( 'date_format' );
		$id     = 'ssv-archive-' . $instance;

		$out = '';
		if ( 1 === $instance ) {
			$out .= '<style>'
				. '.ssv-archive{display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start}'
				. '.ssv-archive .ssv-main{flex:1 1 480px;min-width:0}'
				. '.ssv-archive .ssv-main .ssv-frame{border-radius:10px}'
				. '.ssv-archive .ssv-side{flex:1 1 240px;min-width:0;max-width:100%}'
				. '.ssv-archive .ssv-side-title{font-size:.8em;text-transform:uppercase;letter-spacing:.06em;opacity:.7;margin:0 0 8px}'
				. '.ssv-archive .ssv-list{list-style:none;margin:0;padding:0;max-height:420px;overflow-y:auto}'
				. '.ssv-archive .ssv-item{all:unset;box-sizing:border-box;display:flex;gap:10px;align-items:center;width:100%;padding:8px;border-radius:8px;cursor:pointer;color:inherit;font:inherit;transition:background .15s}'
				. '.ssv-archive .ssv-item:hover{background:rgba(127,127,127,.12)}'
				. '.ssv-archive .ssv-item:focus-visible{outline:2px solid currentColor;outline-offset:1px}'
				. '.ssv-archive .ssv-item[aria-current="true"]{background:rgba(127,127,127,.2)}'
				. '.ssv-archive .ssv-thumbnail{position:relative;flex:none;width:88px;aspect-ratio:16/9;border-radius:6px;overflow:hidden;background:#2c2c2a center/cover no-repeat}'
				. '.ssv-archive .ssv-thumbnail::after{content:"";position:absolute;left:50%;top:50%;transform:translate(-35%,-50%);border-style:solid;border-width:7px 0 7px 11px;border-color:transparent transparent transparent rgba(255,255,255,.9)}'
				. '.ssv-archive .ssv-item[aria-current="true"] .ssv-thumbnail::after{border-width:0;width:14px;height:12px;transform:translate(-50%,-50%);background:linear-gradient(90deg,#fff 0 3px,transparent 3px 5.5px,#fff 5.5px 8.5px,transparent 8.5px 11px,#fff 11px)}'
				. '.ssv-archive .ssv-meta{min-width:0;line-height:1.3}'
				. '.ssv-archive .ssv-item-title{display:block;font-size:.92em;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
				. '.ssv-archive .ssv-item-date{display:block;font-size:.82em;opacity:.7;margin-top:2px}'
				. '.ssv-archive .ssv-live{display:inline-block;font-size:.7em;font-weight:700;letter-spacing:.05em;color:#fff;background:#d93025;border-radius:4px;padding:1px 6px;margin-left:6px;vertical-align:1px}'
				. '.ssv-archive .ssv-panel .ssv-title{margin:0 0 4px}'
				. '.ssv-archive .ssv-panel .ssv-date{margin:0 0 10px;opacity:.75}'
				. '.ssv-archive .ssv-panel .ssv-link{margin:8px 0 0;font-size:.9em}'
				. '</style>';
		}

		$out .= '<div class="ssv-archive" id="' . esc_attr( $id ) . '">';

		// Player column.
		$out .= '<div class="ssv-main">';
		foreach ( $videos as $i => $v ) {
			$out .= '<div class="ssv-panel" data-index="' . (int) $i . '"' . ( $i > 0 ? ' hidden' : '' ) . '>';
			if ( 'yes' === $atts['show_title'] && '' !== $v['title'] ) {
				$out .= '<h3 class="ssv-title">' . esc_html( $v['title'] ) . '</h3>';
			}
			$pts = ! empty( $v['created'] ) ? strtotime( $v['created'] ) : false;
			if ( 'yes' === $atts['show_date'] && $pts ) {
				$out .= '<p class="ssv-date">' . esc_html( wp_date( $format, $pts ) ) . '</p>';
			}
			$out .= self::render_frame( $v, $atts['display'], $i > 0 );
			$out .= '<p class="ssv-link"><a href="' . esc_url( $v['url'] ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Watch on Facebook', 'sunday-sermon-facebook-video' ) . '</a></p>';
			if ( 'no' !== $atts['transcript'] ) {
				$out .= SSFV_Transcripts::render( $v, $i > 0 );
			}
			$out .= '</div>';
		}
		$out .= '</div>';

		// Playlist column.
		$out .= '<div class="ssv-side">';
		if ( '' !== $atts['list_title'] ) {
			$out .= '<p class="ssv-side-title">' . esc_html( $atts['list_title'] ) . '</p>';
		}
		$out .= '<ul class="ssv-list">';
		foreach ( $videos as $i => $v ) {
			$ts    = ! empty( $v['created'] ) ? strtotime( $v['created'] ) : false;
			$title = '' !== $v['title'] ? $v['title'] : __( 'Sunday service', 'sunday-sermon-facebook-video' );
			$thumb = ! empty( $v['image'] ) ? ' style="background-image:url(\'' . esc_url( $v['image'] ) . '\')"' : '';

			$out .= '<li><button type="button" class="ssv-item" data-index="' . (int) $i . '" aria-current="' . ( 0 === $i ? 'true' : 'false' ) . '">';
			$out .= '<span class="ssv-thumbnail" aria-hidden="true"' . $thumb . '></span>';
			$out .= '<span class="ssv-meta"><span class="ssv-item-title">' . esc_html( $title ) . '</span>';
			$out .= '<span class="ssv-item-date">' . ( $ts ? esc_html( wp_date( $format, $ts ) ) : '' );
			if ( ! empty( $v['live'] ) ) {
				$out .= '<span class="ssv-live">' . esc_html__( 'LIVE', 'sunday-sermon-facebook-video' ) . '</span>';
			}
			$out .= '</span></span></button></li>';
		}
		$out .= '</ul></div>';

		$out .= '</div>';
		$out .= '<script>(function(){var r=document.getElementById(' . wp_json_encode( $id ) . ');if(!r)return;'
			. 'var p=r.querySelectorAll(".ssv-panel"),b=r.querySelectorAll(".ssv-item"),c=0;'
			. 'function show(i){if(i===c||i<0||i>=p.length)return;'
			. 'var f=p[c].querySelector("iframe");if(f&&f.src){f.setAttribute("data-src",f.src);f.removeAttribute("src");}'
			. 'var vv=p[c].querySelector("video");if(vv){vv.pause();}'
			. 'p[c].hidden=true;b[c].setAttribute("aria-current","false");c=i;p[c].hidden=false;b[c].setAttribute("aria-current","true");'
			. 'var g=p[c].querySelector("iframe[data-src]");if(g){g.src=g.getAttribute("data-src");}'
			. 'if(window.innerWidth<720){r.scrollIntoView({behavior:"smooth",block:"start"});}}'
			. 'b.forEach(function(x){x.addEventListener("click",function(){show(parseInt(x.getAttribute("data-index"),10));});});'
			. '})();</script>';

		return $out;
	}

	/**
	 * [sunday_sermon_date format="F j, Y" before="" after="" match=""]
	 * Prints just the published date of the video [sunday_sermon] is showing.
	 */
	public static function date_shortcode( $atts ) {
		$s    = self::settings();
		$atts = shortcode_atts(
			array(
				'match'  => $s['match'],
				'format' => '',
				'before' => '',
				'after'  => '',
			),
			$atts,
			'sunday_sermon_date'
		);

		$video = self::get_video( $atts['match'] );
		$ts    = ! empty( $video['created'] ) ? strtotime( $video['created'] ) : false;
		if ( ! $ts ) {
			return '';
		}

		$format = '' !== $atts['format'] ? $atts['format'] : get_option( 'date_format' );

		return '<span class="ssv-date">' . esc_html( $atts['before'] )
			. '<time datetime="' . esc_attr( gmdate( 'c', $ts ) ) . '">' . esc_html( wp_date( $format, $ts ) ) . '</time>'
			. esc_html( $atts['after'] ) . '</span>';
	}

	/* ------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	public static function action_links( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG_SET ) ) . '">' . esc_html__( 'Settings', 'sunday-sermon-facebook-video' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Dashboard', 'sunday-sermon-facebook-video' ) . '</a>'
		);
		return $links;
	}

	public static function admin_menu() {
		add_menu_page(
			__( 'Sunday Sermon Facebook Video', 'sunday-sermon-facebook-video' ),
			__( 'Sunday Sermon', 'sunday-sermon-facebook-video' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-video-alt3',
			26
		);
		add_submenu_page( self::SLUG, __( 'Sunday Sermon Facebook Video', 'sunday-sermon-facebook-video' ), __( 'Dashboard', 'sunday-sermon-facebook-video' ), 'manage_options', self::SLUG, array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( self::SLUG, __( 'Sunday Sermon Facebook Video Settings', 'sunday-sermon-facebook-video' ), __( 'Settings', 'sunday-sermon-facebook-video' ), 'manage_options', self::SLUG_SET, array( __CLASS__, 'render_settings' ) );
	}

	public static function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		wp_register_style( 'ssv-admin', false, array(), '2.4.2' );
		wp_enqueue_style( 'ssv-admin' );
		wp_add_inline_style( 'ssv-admin', self::admin_css() );

		wp_register_script( 'ssv-admin', false, array(), '2.4.2', true );
		wp_enqueue_script( 'ssv-admin' );
		wp_add_inline_script(
			'ssv-admin',
			'document.addEventListener("click",function(e){var b=e.target.closest(".ssv-copy");if(!b)return;var t=b.getAttribute("data-copy");'
			. 'var done=function(){var o=b.textContent;b.textContent=' . wp_json_encode( __( 'Copied', 'sunday-sermon-facebook-video' ) ) . ';b.classList.add("is-done");setTimeout(function(){b.textContent=o;b.classList.remove("is-done");},1500);};'
			. 'if(navigator.clipboard){navigator.clipboard.writeText(t).then(done);}else{var x=document.createElement("textarea");x.value=t;document.body.appendChild(x);x.select();document.execCommand("copy");x.remove();done();}});'
		);
	}

	private static function admin_css() {
		return '
.ssv-wrap{max-width:1180px;margin:20px 20px 40px 0}
.ssv-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:0 0 20px}
.ssv-head h1{display:flex;align-items:center;gap:10px;font-size:23px;font-weight:600;margin:0;padding:0}
.ssv-head h1 .dashicons{font-size:28px;width:28px;height:28px;color:#2271b1}
.ssv-head .ssv-sub{margin:4px 0 0;color:#646970}
.ssv-actions{display:flex;gap:8px;align-items:center}
.ssv-actions form{margin:0}
.ssv-grid{display:grid;gap:20px;margin:0 0 20px}
.ssv-grid.cols-4{grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
.ssv-grid.cols-2{grid-template-columns:repeat(auto-fit,minmax(460px,1fr))}
.ssv-card{background:#fff;border:1px solid #e0e0e3;border-radius:12px;padding:20px 24px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.ssv-card h2{display:flex;align-items:center;gap:8px;font-size:15px;font-weight:600;margin:0 0 4px}
.ssv-card h2 .dashicons{color:#2271b1;font-size:18px;width:18px;height:18px}
.ssv-card .ssv-card-desc{color:#646970;margin:0 0 16px}
.ssv-stat-label{display:flex;align-items:center;gap:6px;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.04em;margin:0 0 8px}
.ssv-stat-value{font-size:22px;font-weight:600;line-height:1.2;margin:0;color:#1d2327}
.ssv-stat-note{color:#646970;margin:6px 0 0;font-size:12px}
.ssv-pill{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:3px 10px;font-size:12px;font-weight:600}
.ssv-pill.ok{background:#e7f6ec;color:#0a6b2d}
.ssv-pill.bad{background:#fcecec;color:#a51d1d}
.ssv-pill.warn{background:#fdf3e0;color:#7a4a00}
.ssv-pill.info{background:#e8f1fb;color:#135e96}
.ssv-pill .ssv-dot{width:7px;height:7px;border-radius:50%;background:currentColor}
.ssv-alert{display:flex;gap:12px;align-items:flex-start;border-radius:12px;padding:14px 18px;margin:0 0 20px;background:#fcecec;border:1px solid #f3c9c9;color:#7a1414}
.ssv-alert .dashicons{margin-top:1px}
.ssv-alert p{margin:0}
.ssv-now{display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap}
.ssv-now-thumb{position:relative;flex:0 0 240px;aspect-ratio:16/9;border-radius:10px;background:#2c2c2a center/cover no-repeat;overflow:hidden}
.ssv-now-thumb::after{content:"";position:absolute;left:50%;top:50%;width:44px;height:44px;margin:-22px 0 0 -22px;border-radius:50%;background:rgba(0,0,0,.55) url("data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 24 24%27%3E%3Cpath fill=%27white%27 d=%27M8 5v14l11-7z%27/%3E%3C/svg%3E") center/22px no-repeat}
.ssv-now-meta{flex:1 1 200px;min-width:0}
.ssv-now-meta h3{font-size:16px;margin:0 0 4px}
.ssv-now-meta p{margin:0 0 10px;color:#646970}
.ssv-recent{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:14px}
.ssv-recent a{text-decoration:none;color:#1d2327}
.ssv-recent .ssv-rt{aspect-ratio:16/9;border-radius:8px;background:#2c2c2a center/cover no-repeat;margin:0 0 6px;position:relative}
.ssv-recent .ssv-rt .ssv-pill{position:absolute;left:6px;top:6px}
.ssv-recent .ssv-rn{font-weight:600;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ssv-recent .ssv-rd{font-size:12px;color:#646970}
.ssv-code{display:flex;align-items:center;gap:8px;background:#f6f7f7;border:1px solid #e0e0e3;border-radius:8px;padding:8px 8px 8px 12px;margin:0 0 10px}
.ssv-code code{flex:1;background:none;padding:0;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ssv-code-label{font-weight:600;margin:0 0 4px}
.ssv-code-help{color:#646970;font-size:12px;margin:-4px 0 14px}
.ssv-copy.is-done{color:#0a6b2d!important;border-color:#0a6b2d!important}
.ssv-table{width:100%;border-collapse:collapse}
.ssv-table th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970;font-weight:600;padding:8px 10px;border-bottom:1px solid #e0e0e3}
.ssv-table td{padding:10px;border-bottom:1px solid #f0f0f1;vertical-align:top}
.ssv-table tr:last-child td{border-bottom:0}
.ssv-table small{color:#646970}
.ssv-field{margin:0 0 18px}
.ssv-field:last-child{margin-bottom:0}
.ssv-field>label:not(.ssv-switch),.ssv-field .ssv-label{display:block;font-weight:600;margin:0 0 6px}
.ssv-field input[type=text],.ssv-field input[type=url],.ssv-field input[type=password],.ssv-field select{width:100%;max-width:100%}
.ssv-field input[type=number]{width:90px}
.ssv-field .description{margin:6px 0 0;color:#646970}
.ssv-inline{display:flex;align-items:center;gap:8px}
.ssv-switch{display:flex;align-items:flex-start;gap:12px;cursor:pointer}
.ssv-switch input{position:absolute;opacity:0;width:1px;height:1px}
.ssv-switch .ssv-track{flex:none;position:relative;width:38px;height:22px;border-radius:999px;background:#c3c4c7;transition:background .15s;margin-top:1px}
.ssv-switch .ssv-track::after{content:"";position:absolute;left:3px;top:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:transform .15s;box-shadow:0 1px 2px rgba(0,0,0,.2)}
.ssv-switch input:checked+.ssv-track{background:#2271b1}
.ssv-switch input:checked+.ssv-track::after{transform:translateX(16px)}
.ssv-switch input:focus-visible+.ssv-track{box-shadow:0 0 0 2px #fff,0 0 0 4px #2271b1}
.ssv-switch strong{display:block;font-weight:600}
.ssv-switch span.d{display:block;color:#646970;margin-top:2px}
.ssv-savebar{position:sticky;bottom:0;display:flex;align-items:center;justify-content:space-between;gap:12px;background:#fff;border:1px solid #e0e0e3;border-radius:12px;padding:14px 24px;box-shadow:0 -2px 10px rgba(0,0,0,.04)}
.ssv-savebar p{margin:0;color:#646970}
.ssv-token-row{display:flex;gap:8px;align-items:center}
.ssv-token-row input{flex:1}
@media (max-width:782px){.ssv-grid.cols-2{grid-template-columns:1fr}.ssv-now-thumb{flex-basis:100%}}
';
	}

	public static function register_settings() {
		register_setting(
			'ssv_group',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$old = self::settings();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$out['page_id'] = isset( $in['page_id'] ) ? preg_replace( '/[^A-Za-z0-9._-]/', '', $in['page_id'] ) : $old['page_id'];

		$token = isset( $in['token'] ) ? trim( sanitize_text_field( $in['token'] ) ) : '';
		if ( ! empty( $in['clear_token'] ) ) {
			$out['token'] = '';
		} elseif ( '' === $token ) {
			$out['token'] = $old['token']; // Blank field keeps the saved token.
		} else {
			$out['token'] = $token;
		}

		$out['match']         = isset( $in['match'] ) ? sanitize_text_field( $in['match'] ) : $old['match'];
		$out['cache_minutes'] = isset( $in['cache_minutes'] ) ? min( 1440, max( 5, absint( $in['cache_minutes'] ) ) ) : 60;

		$manual = isset( $in['manual_url'] ) ? esc_url_raw( trim( $in['manual_url'] ) ) : '';
		if ( '' !== $manual && ! preg_match( '#^https://(www\.|m\.|web\.)?(facebook\.com|fb\.watch)/#i', $manual ) ) {
			add_settings_error( self::OPT, 'ssv_manual', __( 'The manual video link must be a facebook.com or fb.watch URL.', 'sunday-sermon-facebook-video' ) );
			$manual = '';
		}
		$out['manual_url']   = $manual;
		$out['sunday_blank'] = empty( $in['sunday_blank'] ) ? 0 : 1;
		$out['sunday_only']  = empty( $in['sunday_only'] ) ? 0 : 1;
		$out['title_dates']  = empty( $in['title_dates'] ) ? 0 : 1;
		$out['months']       = isset( $in['months'] ) ? min( 60, absint( $in['months'] ) ) : 0;
		$aai = isset( $in['aai_key'] ) ? trim( sanitize_text_field( $in['aai_key'] ) ) : '';
		if ( ! empty( $in['clear_aai_key'] ) ) {
			$out['aai_key'] = '';
		} elseif ( '' === $aai ) {
			$out['aai_key'] = $old['aai_key'];
		} else {
			$out['aai_key'] = $aai;
		}
		$out['tr_enabled'] = empty( $in['tr_enabled'] ) ? 0 : 1;
		$out['tr_auto']    = empty( $in['tr_auto'] ) ? 0 : 1;
		$out['tr_limit']   = isset( $in['tr_limit'] ) ? min( 60, max( 1, absint( $in['tr_limit'] ) ) ) : 8;
		$out['tr_lang']    = ( isset( $in['tr_lang'] ) && in_array( $in['tr_lang'], array( 'en_us', 'es', 'auto' ), true ) ) ? $in['tr_lang'] : 'en_us';
		$out['tr_vocab']   = isset( $in['tr_vocab'] ) ? sanitize_text_field( $in['tr_vocab'] ) : '';
		$ant = isset( $in['anthropic_key'] ) ? trim( sanitize_text_field( $in['anthropic_key'] ) ) : '';
		if ( ! empty( $in['clear_anthropic_key'] ) ) {
			$out['anthropic_key'] = '';
		} elseif ( '' === $ant ) {
			$out['anthropic_key'] = $old['anthropic_key'];
		} else {
			$out['anthropic_key'] = $ant;
		}
		$out['sum_enabled'] = empty( $in['sum_enabled'] ) ? 0 : 1;
		$out['sum_trim']    = empty( $in['sum_trim'] ) ? 0 : 1;
		$out['sum_provider']  = ( isset( $in['sum_provider'] ) && 'claude' === $in['sum_provider'] ) ? 'claude' : 'assemblyai';
		$out['aai_llm_model'] = isset( $in['aai_llm_model'] ) ? preg_replace( '/[^A-Za-z0-9._:\/-]/', '', $in['aai_llm_model'] ) : 'claude-sonnet-4-6';
		$out['sum_model']   = ( isset( $in['sum_model'] ) && in_array( $in['sum_model'], array( 'claude-haiku-5-5', 'claude-sonnet-5-5' ), true ) ) ? $in['sum_model'] : 'claude-haiku-5-5';
		$out['display']      = ( isset( $in['display'] ) && in_array( $in['display'], array( 'auto', 'native', 'embed', 'thumbnail' ), true ) ) ? $in['display'] : 'auto';

		return $out;
	}

	public static function handle_refresh() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sunday-sermon-facebook-video' ) );
		}
		check_admin_referer( 'ssv_refresh' );

		self::clear_cache();
		self::get_video( null, true );

		wp_safe_redirect( add_query_arg( 'ssv_refreshed', '1', admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	private static function refresh_button( $primary = true ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ssv_refresh">
			<?php wp_nonce_field( 'ssv_refresh' ); ?>
			<button type="submit" class="button <?php echo $primary ? 'button-primary' : ''; ?>"><span class="dashicons dashicons-update" style="vertical-align:-4px;margin-right:4px"></span><?php esc_html_e( 'Check Facebook now', 'sunday-sermon-facebook-video' ); ?></button>
		</form>
		<?php
	}

	private static function fmt_date( $iso, $with_time = false ) {
		$ts = $iso ? strtotime( $iso ) : false;
		if ( ! $ts ) {
			return '';
		}
		$f = get_option( 'date_format' ) . ( $with_time ? ' ' . get_option( 'time_format' ) : '' );
		return wp_date( $f, $ts );
	}

	/* ------------------------------------------------------------------
	 * Dashboard
	 * ------------------------------------------------------------------ */

	public static function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s      = self::settings();
		$error  = get_option( self::LAST_ERROR );
		$videos = self::get_videos();
		$video  = $videos ? $videos[0] : null;
		$scan   = get_option( self::LAST_SCAN );
		$has_er = is_array( $error ) && ! empty( $error['message'] );
		$set    = admin_url( 'admin.php?page=' . self::SLUG_SET );

		if ( empty( $s['token'] ) ) {
			$conn = array( 'warn', __( 'Not connected', 'sunday-sermon-facebook-video' ), __( 'Add a Page access token in Settings.', 'sunday-sermon-facebook-video' ) );
		} elseif ( $has_er ) {
			$conn = array( 'bad', __( 'Needs attention', 'sunday-sermon-facebook-video' ), __( 'See the error above.', 'sunday-sermon-facebook-video' ) );
		} else {
			$conn = array( 'ok', __( 'Connected', 'sunday-sermon-facebook-video' ), __( 'Facebook is answering normally.', 'sunday-sermon-facebook-video' ) );
		}
		?>
		<div class="wrap ssv-wrap">
			<div class="ssv-head">
				<div>
					<h1><span class="dashicons dashicons-video-alt3"></span><?php esc_html_e( 'Sunday Sermon Facebook Video', 'sunday-sermon-facebook-video' ); ?></h1>
					<p class="ssv-sub"><?php esc_html_e( 'Your latest service videos from Facebook, on your website.', 'sunday-sermon-facebook-video' ); ?></p>
				</div>
				<div class="ssv-actions">
					<a class="button" href="<?php echo esc_url( $set ); ?>"><span class="dashicons dashicons-admin-generic" style="vertical-align:-4px;margin-right:4px"></span><?php esc_html_e( 'Settings', 'sunday-sermon-facebook-video' ); ?></a>
					<?php self::refresh_button(); ?>
				</div>
			</div>

			<?php if ( isset( $_GET['ssv_refreshed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Checked Facebook just now.', 'sunday-sermon-facebook-video' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ssfv_tr'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<?php if ( 'sum' === $_GET['ssfv_tr'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Summary created.', 'sunday-sermon-facebook-video' ); ?></p></div>
				<?php elseif ( 'ok' === $_GET['ssfv_tr'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
					<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sent for transcription. It usually takes a few minutes; this page updates as it finishes.', 'sunday-sermon-facebook-video' ); ?></p></div>
				<?php else : ?>
					<div class="notice notice-error is-dismissible"><p><?php echo esc_html( (string) get_transient( 'ssfv_tr_notice_' . get_current_user_id() ) ); ?></p></div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $has_er ) : ?>
				<div class="ssv-alert"><span class="dashicons dashicons-warning"></span>
					<p><strong><?php esc_html_e( 'Facebook returned an error', 'sunday-sermon-facebook-video' ); ?></strong> (<?php echo esc_html( human_time_diff( (int) $error['time'] ) ); ?> <?php esc_html_e( 'ago', 'sunday-sermon-facebook-video' ); ?>)<br><?php echo esc_html( $error['message'] ); ?><br>
					<?php esc_html_e( 'Visitors still see the last video that loaded successfully.', 'sunday-sermon-facebook-video' ); ?></p>
				</div>
			<?php endif; ?>

			<div class="ssv-grid cols-4">
				<div class="ssv-card">
					<p class="ssv-stat-label"><span class="dashicons dashicons-admin-links"></span><?php esc_html_e( 'Facebook', 'sunday-sermon-facebook-video' ); ?></p>
					<p class="ssv-stat-value"><span class="ssv-pill <?php echo esc_attr( $conn[0] ); ?>"><span class="ssv-dot"></span><?php echo esc_html( $conn[1] ); ?></span></p>
					<p class="ssv-stat-note"><?php echo esc_html( $conn[2] ); ?></p>
				</div>
				<div class="ssv-card">
					<p class="ssv-stat-label"><span class="dashicons dashicons-calendar-alt"></span><?php esc_html_e( 'Latest service', 'sunday-sermon-facebook-video' ); ?></p>
					<p class="ssv-stat-value"><?php echo $video && $video['created'] ? esc_html( self::fmt_date( $video['created'] ) ) : '—'; ?></p>
					<p class="ssv-stat-note"><?php echo ( $video && ! empty( $video['live'] ) ) ? esc_html__( 'Live right now', 'sunday-sermon-facebook-video' ) : esc_html__( 'Shown at the top of the archive', 'sunday-sermon-facebook-video' ); ?></p>
				</div>
				<div class="ssv-card">
					<p class="ssv-stat-label"><span class="dashicons dashicons-playlist-video"></span><?php esc_html_e( 'Services found', 'sunday-sermon-facebook-video' ); ?></p>
					<p class="ssv-stat-value"><?php echo esc_html( number_format_i18n( count( $videos ) ) ); ?></p>
					<p class="ssv-stat-note">
						<?php
						echo (int) $s['months'] > 0
							/* translators: %d: months */
							? esc_html( sprintf( _n( 'From the past %d month', 'From the past %d months', (int) $s['months'], 'sunday-sermon-facebook-video' ), (int) $s['months'] ) )
							: esc_html__( 'From the most recent posts', 'sunday-sermon-facebook-video' );
						?>
					</p>
				</div>
				<div class="ssv-card">
					<p class="ssv-stat-label"><span class="dashicons dashicons-clock"></span><?php esc_html_e( 'Last checked', 'sunday-sermon-facebook-video' ); ?></p>
					<p class="ssv-stat-value"><?php echo is_array( $scan ) ? esc_html( sprintf( /* translators: %s: time */ __( '%s ago', 'sunday-sermon-facebook-video' ), human_time_diff( (int) $scan['time'] ) ) ) : '—'; ?></p>
					<p class="ssv-stat-note">
						<?php
						/* translators: %d: minutes */
						echo esc_html( sprintf( __( 'Checks every %d minutes', 'sunday-sermon-facebook-video' ), (int) $s['cache_minutes'] ) );
						?>
					</p>
				</div>
			</div>

			<div class="ssv-grid cols-2">
				<div class="ssv-card">
					<h2><span class="dashicons dashicons-format-video"></span><?php esc_html_e( 'Now showing', 'sunday-sermon-facebook-video' ); ?></h2>
					<p class="ssv-card-desc"><?php esc_html_e( 'What visitors see first on your sermon page.', 'sunday-sermon-facebook-video' ); ?></p>
					<?php if ( $video && ! empty( $video['url'] ) ) : ?>
						<div class="ssv-now">
							<div class="ssv-now-thumb"<?php echo ! empty( $video['image'] ) ? ' style="background-image:url(\'' . esc_url( $video['image'] ) . '\')"' : ''; ?>></div>
							<div class="ssv-now-meta">
								<h3><?php echo esc_html( '' !== $video['title'] ? $video['title'] : __( 'Sunday service', 'sunday-sermon-facebook-video' ) ); ?></h3>
								<p><?php echo esc_html( self::fmt_date( $video['created'], true ) ); ?></p>
								<p>
									<?php if ( ! empty( $s['manual_url'] ) ) : ?>
										<span class="ssv-pill warn"><?php esc_html_e( 'Manual link override', 'sunday-sermon-facebook-video' ); ?></span>
									<?php elseif ( ! empty( $video['live'] ) ) : ?>
										<span class="ssv-pill bad"><span class="ssv-dot"></span><?php esc_html_e( 'Live now', 'sunday-sermon-facebook-video' ); ?></span>
									<?php elseif ( ! empty( $video['file'] ) ) : ?>
										<span class="ssv-pill ok"><?php esc_html_e( 'Plays on your site', 'sunday-sermon-facebook-video' ); ?></span>
									<?php else : ?>
										<span class="ssv-pill info"><?php esc_html_e( 'Opens on Facebook', 'sunday-sermon-facebook-video' ); ?></span>
									<?php endif; ?>
								</p>
								<a class="button" href="<?php echo esc_url( $video['url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View on Facebook', 'sunday-sermon-facebook-video' ); ?> <span class="dashicons dashicons-external" style="vertical-align:-4px"></span></a>
							</div>
						</div>
					<?php else : ?>
						<p><?php esc_html_e( 'No service video found yet. Check your settings, then click "Check Facebook now".', 'sunday-sermon-facebook-video' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="ssv-card">
					<h2><span class="dashicons dashicons-shortcode"></span><?php esc_html_e( 'Shortcodes', 'sunday-sermon-facebook-video' ); ?></h2>
					<p class="ssv-card-desc"><?php esc_html_e( 'Paste into a Shortcode block on any page.', 'sunday-sermon-facebook-video' ); ?></p>
					<?php
					$codes = array(
						array( __( 'Archive with playlist', 'sunday-sermon-facebook-video' ), '[sunday_sermon_archive show_title="yes" show_date="yes" fallback="Check back Sunday afternoon."]', __( 'Options: count, list_title, format, display, show_title, show_date, fallback.', 'sunday-sermon-facebook-video' ) ),
						array( __( 'Latest service only', 'sunday-sermon-facebook-video' ), '[sunday_sermon show_title="yes"]', __( 'Options: show_title, show_date, format, display, fallback.', 'sunday-sermon-facebook-video' ) ),
						array( __( 'Date of latest service', 'sunday-sermon-facebook-video' ), '[sunday_sermon_date format="F j, Y"]', __( 'Options: format, before, after.', 'sunday-sermon-facebook-video' ) ),
					);
					foreach ( $codes as $c ) :
						?>
						<p class="ssv-code-label"><?php echo esc_html( $c[0] ); ?></p>
						<div class="ssv-code"><code><?php echo esc_html( $c[1] ); ?></code><button type="button" class="button button-small ssv-copy" data-copy="<?php echo esc_attr( $c[1] ); ?>"><?php esc_html_e( 'Copy', 'sunday-sermon-facebook-video' ); ?></button></div>
						<p class="ssv-code-help"><?php echo esc_html( $c[2] ); ?></p>
					<?php endforeach; ?>
				</div>
			</div>

			<?php SSFV_Transcripts::dashboard_card( $videos ); ?>

			<?php if ( count( $videos ) > 1 ) : ?>
				<div class="ssv-card" style="margin-bottom:20px">
					<h2><span class="dashicons dashicons-images-alt2"></span><?php esc_html_e( 'Recent services', 'sunday-sermon-facebook-video' ); ?></h2>
					<p class="ssv-card-desc"><?php esc_html_e( 'These appear in the archive playlist, newest first.', 'sunday-sermon-facebook-video' ); ?></p>
					<div class="ssv-recent">
						<?php foreach ( array_slice( $videos, 0, 12 ) as $v ) : ?>
							<a href="<?php echo esc_url( $v['url'] ); ?>" target="_blank" rel="noopener">
								<div class="ssv-rt"<?php echo ! empty( $v['image'] ) ? ' style="background-image:url(\'' . esc_url( $v['image'] ) . '\')"' : ''; ?>>
									<?php if ( ! empty( $v['live'] ) ) : ?>
										<span class="ssv-pill bad"><?php esc_html_e( 'LIVE', 'sunday-sermon-facebook-video' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="ssv-rn"><?php echo esc_html( '' !== $v['title'] ? $v['title'] : __( 'Sunday service', 'sunday-sermon-facebook-video' ) ); ?></div>
								<div class="ssv-rd"><?php echo esc_html( self::fmt_date( $v['created'] ) ); ?></div>
							</a>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( is_array( $scan ) ) : ?>
				<div class="ssv-card">
					<h2><span class="dashicons dashicons-search"></span><?php esc_html_e( 'What Facebook returned', 'sunday-sermon-facebook-video' ); ?></h2>
					<p class="ssv-card-desc"><?php esc_html_e( 'Every video found on the last check, and whether it was included.', 'sunday-sermon-facebook-video' ); ?></p>
					<?php if ( ! empty( $scan['errors'] ) ) : ?>
						<p><span class="ssv-pill bad"><?php echo esc_html( implode( ' | ', $scan['errors'] ) ); ?></span></p>
					<?php endif; ?>
					<table class="ssv-table">
						<thead><tr>
							<th style="width:190px"><?php esc_html_e( 'Date', 'sunday-sermon-facebook-video' ); ?></th>
							<th style="width:230px"><?php esc_html_e( 'Included', 'sunday-sermon-facebook-video' ); ?></th>
							<th style="width:70px"><?php esc_html_e( 'From', 'sunday-sermon-facebook-video' ); ?></th>
							<th><?php esc_html_e( 'Text', 'sunday-sermon-facebook-video' ); ?></th>
						</tr></thead>
						<tbody>
						<?php if ( empty( $scan['items'] ) ) : ?>
							<tr><td colspan="4"><em><?php esc_html_e( 'No videos came back from Facebook.', 'sunday-sermon-facebook-video' ); ?></em></td></tr>
						<?php else : ?>
							<?php foreach ( $scan['items'] as $item ) : ?>
								<?php $yes = isset( $item['why'] ) && 0 === strpos( $item['why'], 'Yes' ); ?>
								<tr>
									<td>
										<?php echo esc_html( ( ! empty( $item['day'] ) ? $item['day'] . ', ' : '' ) . ( self::fmt_date( $item['created'] ) ? self::fmt_date( $item['created'] ) : '—' ) ); ?>
										<?php if ( ! empty( $item['posted'] ) ) : ?>
											<br><small><?php echo esc_html( sprintf( /* translators: %s: date */ __( 'date from text; posted %s', 'sunday-sermon-facebook-video' ), self::fmt_date( $item['posted'] ) ) ); ?></small>
										<?php endif; ?>
									</td>
									<td><span class="ssv-pill <?php echo $yes ? 'ok' : 'warn'; ?>"><?php echo esc_html( isset( $item['why'] ) ? $item['why'] : '' ); ?></span></td>
									<td><?php echo esc_html( $item['source'] ); ?></td>
									<td><?php echo esc_html( '' !== $item['text'] ? $item['text'] : __( '(no text)', 'sunday-sermon-facebook-video' ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function switch_field_public( $key, $title, $desc, $s ) {
		self::switch_field( $key, $title, $desc, $s );
	}

	private static function switch_field( $key, $title, $desc, $s ) {
		$name = self::OPT . '[' . $key . ']';
		?>
		<div class="ssv-field">
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0">
			<label class="ssv-switch">
				<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?>>
				<span class="ssv-track"></span>
				<span><strong><?php echo esc_html( $title ); ?></strong><span class="d"><?php echo esc_html( $desc ); ?></span></span>
			</label>
		</div>
		<?php
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s    = self::settings();
		$name = self::OPT;
		?>
		<div class="wrap ssv-wrap">
			<div class="ssv-head">
				<div>
					<h1><span class="dashicons dashicons-admin-generic"></span><?php esc_html_e( 'Settings', 'sunday-sermon-facebook-video' ); ?></h1>
					<p class="ssv-sub"><?php esc_html_e( 'Connect Facebook and choose which videos appear on your site.', 'sunday-sermon-facebook-video' ); ?></p>
				</div>
				<div class="ssv-actions">
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><span class="dashicons dashicons-dashboard" style="vertical-align:-4px;margin-right:4px"></span><?php esc_html_e( 'Dashboard', 'sunday-sermon-facebook-video' ); ?></a>
				</div>
			</div>

			<?php settings_errors(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'ssv_group' ); ?>

				<div class="ssv-grid cols-2">
					<div class="ssv-card">
						<h2><span class="dashicons dashicons-facebook-alt"></span><?php esc_html_e( 'Facebook connection', 'sunday-sermon-facebook-video' ); ?></h2>
						<p class="ssv-card-desc"><?php esc_html_e( 'The church Page the videos come from.', 'sunday-sermon-facebook-video' ); ?></p>
						<div class="ssv-field">
							<label for="ssv_page_id"><?php esc_html_e( 'Facebook Page ID', 'sunday-sermon-facebook-video' ); ?></label>
							<input type="text" id="ssv_page_id" name="<?php echo esc_attr( $name ); ?>[page_id]" value="<?php echo esc_attr( $s['page_id'] ); ?>">
							<p class="description"><?php esc_html_e( 'Use "me" with a Page access token, or the Page ID returned by /me/accounts.', 'sunday-sermon-facebook-video' ); ?></p>
						</div>
						<div class="ssv-field">
							<label for="ssv_token"><?php esc_html_e( 'Page access token', 'sunday-sermon-facebook-video' ); ?></label>
							<div class="ssv-token-row">
								<input type="password" id="ssv_token" name="<?php echo esc_attr( $name ); ?>[token]" value="" autocomplete="off" placeholder="<?php echo $s['token'] ? esc_attr__( 'Saved. Leave blank to keep it.', 'sunday-sermon-facebook-video' ) : esc_attr__( 'Paste token', 'sunday-sermon-facebook-video' ); ?>">
								<?php if ( $s['token'] ) : ?>
									<span class="ssv-pill ok"><span class="ssv-dot"></span><?php esc_html_e( 'Saved', 'sunday-sermon-facebook-video' ); ?></span>
								<?php endif; ?>
							</div>
							<p class="description"><?php esc_html_e( 'A Page token that never expires, with pages_read_engagement.', 'sunday-sermon-facebook-video' ); ?></p>
						</div>
						<?php if ( $s['token'] ) : ?>
							<div class="ssv-field">
								<label class="ssv-switch">
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_token]" value="1">
									<span class="ssv-track"></span>
									<span><strong><?php esc_html_e( 'Remove saved token', 'sunday-sermon-facebook-video' ); ?></strong><span class="d"><?php esc_html_e( 'Disconnects Facebook when you save.', 'sunday-sermon-facebook-video' ); ?></span></span>
								</label>
							</div>
						<?php endif; ?>
					</div>

					<div class="ssv-card">
						<h2><span class="dashicons dashicons-filter"></span><?php esc_html_e( 'Which videos count as services', 'sunday-sermon-facebook-video' ); ?></h2>
						<p class="ssv-card-desc"><?php esc_html_e( 'Rules for picking service videos out of everything on the Page.', 'sunday-sermon-facebook-video' ); ?></p>
						<div class="ssv-field">
							<label for="ssv_match"><?php esc_html_e( 'Match phrases', 'sunday-sermon-facebook-video' ); ?></label>
							<input type="text" id="ssv_match" name="<?php echo esc_attr( $name ); ?>[match]" value="<?php echo esc_attr( $s['match'] ); ?>" placeholder="Sunday Worship Service, Service">
							<p class="description"><?php esc_html_e( 'A video counts if its title or description contains any of these. Separate with commas. Leave blank to include every video.', 'sunday-sermon-facebook-video' ); ?></p>
						</div>
						<?php
						self::switch_field( 'sunday_blank', __( 'Include untitled Sunday videos', 'sunday-sermon-facebook-video' ), __( 'Videos with no text that were posted on a Sunday.', 'sunday-sermon-facebook-video' ), $s );
						self::switch_field( 'sunday_only', __( 'Sundays only', 'sunday-sermon-facebook-video' ), __( 'Leave out videos posted on other days.', 'sunday-sermon-facebook-video' ), $s );
						self::switch_field( 'title_dates', __( 'Use dates written in titles', 'sunday-sermon-facebook-video' ), __( 'If a title says "January 31, 2021" or "4/6", use that instead of the post date.', 'sunday-sermon-facebook-video' ), $s );
						?>
						<div class="ssv-field">
							<label for="ssv_months"><?php esc_html_e( 'Look back', 'sunday-sermon-facebook-video' ); ?></label>
							<div class="ssv-inline"><input type="number" min="0" max="60" id="ssv_months" name="<?php echo esc_attr( $name ); ?>[months]" value="<?php echo esc_attr( $s['months'] ); ?>"> <?php esc_html_e( 'months', 'sunday-sermon-facebook-video' ); ?></div>
							<p class="description"><?php esc_html_e( '0 = only the most recent 25 items from each place on Facebook.', 'sunday-sermon-facebook-video' ); ?></p>
						</div>
					</div>

					<div class="ssv-card">
						<h2><span class="dashicons dashicons-desktop"></span><?php esc_html_e( 'Display', 'sunday-sermon-facebook-video' ); ?></h2>
						<p class="ssv-card-desc"><?php esc_html_e( 'How videos appear on your pages.', 'sunday-sermon-facebook-video' ); ?></p>
						<div class="ssv-field">
							<label for="ssv_display"><?php esc_html_e( 'Player', 'sunday-sermon-facebook-video' ); ?></label>
							<select id="ssv_display" name="<?php echo esc_attr( $name ); ?>[display]">
								<option value="native" <?php selected( $s['display'], 'native' ); ?>><?php esc_html_e( 'Play on this site (recommended)', 'sunday-sermon-facebook-video' ); ?></option>
								<option value="auto" <?php selected( $s['display'], 'auto' ); ?>><?php esc_html_e( 'Automatic', 'sunday-sermon-facebook-video' ); ?></option>
								<option value="embed" <?php selected( $s['display'], 'embed' ); ?>><?php esc_html_e( 'Always Facebook player', 'sunday-sermon-facebook-video' ); ?></option>
								<option value="thumbnail" <?php selected( $s['display'], 'thumbnail' ); ?>><?php esc_html_e( 'Thumbnail that opens Facebook', 'sunday-sermon-facebook-video' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( '"Play on this site" works even when Facebook blocks its own player because of music. Live streams always open on Facebook.', 'sunday-sermon-facebook-video' ); ?></p>
						</div>
						<div class="ssv-field">
							<label for="ssv_cache"><?php esc_html_e( 'Check Facebook every', 'sunday-sermon-facebook-video' ); ?></label>
							<div class="ssv-inline"><input type="number" min="5" max="1440" id="ssv_cache" name="<?php echo esc_attr( $name ); ?>[cache_minutes]" value="<?php echo esc_attr( $s['cache_minutes'] ); ?>"> <?php esc_html_e( 'minutes', 'sunday-sermon-facebook-video' ); ?></div>
							<p class="description"><?php esc_html_e( 'While a service is live, it checks every 5 minutes automatically.', 'sunday-sermon-facebook-video' ); ?></p>
						</div>
					</div>

					<div class="ssv-card">
						<h2><span class="dashicons dashicons-admin-links"></span><?php esc_html_e( 'Manual override', 'sunday-sermon-facebook-video' ); ?></h2>
						<p class="ssv-card-desc"><?php esc_html_e( 'Show one specific video instead of the automatic choice.', 'sunday-sermon-facebook-video' ); ?></p>
						<div class="ssv-field">
							<label for="ssv_manual"><?php esc_html_e( 'Facebook video link', 'sunday-sermon-facebook-video' ); ?></label>
							<input type="url" id="ssv_manual" name="<?php echo esc_attr( $name ); ?>[manual_url]" value="<?php echo esc_attr( $s['manual_url'] ); ?>" placeholder="https://www.facebook.com/.../videos/...">
							<p class="description"><?php esc_html_e( 'For special services or emergencies. Clear it to go back to automatic.', 'sunday-sermon-facebook-video' ); ?></p>
						</div>
						<?php if ( $s['manual_url'] ) : ?>
							<p><span class="ssv-pill warn"><?php esc_html_e( 'Override is on', 'sunday-sermon-facebook-video' ); ?></span></p>
						<?php endif; ?>
					</div>
					<?php SSFV_Transcripts::settings_card( $s, $name ); ?>
				</div>

				<div class="ssv-savebar">
					<p><?php esc_html_e( 'Saving clears the cache, so the next page view checks Facebook again.', 'sunday-sermon-facebook-video' ); ?></p>
					<?php submit_button( __( 'Save settings', 'sunday-sermon-facebook-video' ), 'primary', 'submit', false ); ?>
				</div>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Uninstall
	 * ------------------------------------------------------------------ */

	public static function uninstall() {
		delete_option( self::OPT );
		delete_option( self::LAST_GOOD );
		delete_option( self::LAST_ERROR );
		delete_option( self::LAST_SCAN );
		wp_clear_scheduled_hook( 'ssfv_transcribe_tick' );
		self::clear_cache();
	}
}

require_once __DIR__ . '/includes/class-ssfv-transcripts.php';
require_once __DIR__ . '/includes/class-ssfv-summaries.php';

SSFV_Sunday_Sermon_Facebook_Video::init();
SSFV_Transcripts::init();
SSFV_Summaries::init();
register_deactivation_hook( __FILE__, array( 'SSFV_Transcripts', 'unschedule' ) );
register_uninstall_hook( __FILE__, array( 'SSFV_Sunday_Sermon_Facebook_Video', 'uninstall' ) );
