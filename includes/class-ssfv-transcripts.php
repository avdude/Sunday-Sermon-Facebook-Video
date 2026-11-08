<?php
/**
 * Sermon transcripts through AssemblyAI.
 *
 * Each transcript is stored as a private "ssfv_transcript" post. The post content holds one
 * paragraph per line in the form "[m:ss] text", so a person can correct it in the editor and
 * the timestamps still work.
 *
 * @package Sunday_Sermon_Facebook_Video
 */

defined( 'ABSPATH' ) || exit;

final class SSFV_Transcripts {

	const CPT  = 'ssfv_transcript';
	const CRON = 'ssfv_transcribe_tick';
	const API  = 'https://api.assemblyai.com/v2/transcript';

	/** Newest services that are transcribed automatically; older ones only by hand. */
	const AUTO_NEWEST = 3;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ), 20 );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
		add_action( 'admin_post_ssfv_transcribe', array( __CLASS__, 'handle_transcribe' ) );
	}

	private static function s() {
		return SSFV_Sunday_Sermon_Facebook_Video::settings();
	}

	public static function enabled() {
		$s = self::s();
		return ! empty( $s['tr_enabled'] ) && ! empty( $s['aai_key'] );
	}

	/* ------------------------------------------------------------------
	 * Setup
	 * ------------------------------------------------------------------ */

	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Transcripts', 'sunday-sermon-facebook-video' ),
					'singular_name' => __( 'Transcript', 'sunday-sermon-facebook-video' ),
					'edit_item'     => __( 'Edit transcript', 'sunday-sermon-facebook-video' ),
					'search_items'  => __( 'Search transcripts', 'sunday-sermon-facebook-video' ),
					'not_found'     => __( 'No transcripts yet.', 'sunday-sermon-facebook-video' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => SSFV_Sunday_Sermon_Facebook_Video::SLUG,
				'show_in_rest' => false, // Classic editor: plain text keeps the [m:ss] lines intact.
				'supports'     => array( 'title', 'editor' ),
				'map_meta_cap' => true,
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
			)
		);
	}

	public static function cron_schedules( $schedules ) {
		$schedules['ssfv_five_min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'sunday-sermon-facebook-video' ),
		);
		return $schedules;
	}

	public static function ensure_cron() {
		$next = wp_next_scheduled( self::CRON );
		if ( self::enabled() && ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'ssfv_five_min', self::CRON );
		} elseif ( ! self::enabled() && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON );
	}

	/* ------------------------------------------------------------------
	 * Lookups
	 * ------------------------------------------------------------------ */

	public static function find_post( $key ) {
		if ( '' === (string) $key ) {
			return 0;
		}
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'meta_key'       => '_ssfv_key', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => (string) $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	public static function status( $key ) {
		$id = self::find_post( $key );
		return $id ? (string) get_post_meta( $id, '_ssfv_status', true ) : '';
	}

	/** Transcripts started this calendar month (site time zone), and their audio minutes. */
	public static function month_usage() {
		$now = current_datetime();
		$q   = new WP_Query(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 200,
				'date_query'     => array(
					array(
						'year'  => (int) $now->format( 'Y' ),
						'month' => (int) $now->format( 'n' ),
					),
				),
			)
		);
		$seconds = 0;
		foreach ( $q->posts as $id ) {
			$seconds += (int) get_post_meta( $id, '_ssfv_seconds', true );
		}
		return array(
			'count'   => (int) $q->found_posts,
			'minutes' => (int) round( $seconds / 60 ),
		);
	}

	/* ------------------------------------------------------------------
	 * AssemblyAI
	 * ------------------------------------------------------------------ */

	private static function request( $method, $url, $body = null ) {
		$s    = self::s();
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'authorization' => $s['aai_key'],
				'content-type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = isset( $data['error'] ) ? $data['error'] : 'HTTP ' . $code;
			return new WP_Error( 'ssfv_aai_' . $code, 'AssemblyAI: ' . $msg );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Send a sermon video to AssemblyAI. Returns the transcript post ID or WP_Error.
	 */
	public static function submit( $video, $force = false ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'ssfv_off', __( 'Transcripts are turned off, or the AssemblyAI API key is missing.', 'sunday-sermon-facebook-video' ) );
		}
		$key = isset( $video['key'] ) ? (string) $video['key'] : '';
		if ( '' === $key || 'manual' === $key ) {
			return new WP_Error( 'ssfv_nokey', __( 'This video can\'t be transcribed automatically.', 'sunday-sermon-facebook-video' ) );
		}
		if ( ! empty( $video['live'] ) ) {
			return new WP_Error( 'ssfv_live', __( 'This service is still live. Try again after the stream ends.', 'sunday-sermon-facebook-video' ) );
		}
		if ( empty( $video['file'] ) ) {
			return new WP_Error( 'ssfv_nofile', __( 'Facebook didn\'t provide a video file for this service, so it can\'t be sent for transcription.', 'sunday-sermon-facebook-video' ) );
		}

		$existing = self::find_post( $key );
		if ( $existing && ! $force ) {
			$st = get_post_meta( $existing, '_ssfv_status', true );
			if ( 'error' !== $st ) {
				return $existing;
			}
		}

		$s    = self::s();
		$body = array(
			'audio_url'   => $video['file'],
			'punctuate'   => true,
			'format_text' => true,
		);
		if ( 'auto' === $s['tr_lang'] ) {
			$body['language_detection'] = true;
		} else {
			$body['language_code'] = $s['tr_lang'];
		}

		$vocab = array_slice( array_filter( array_map( 'trim', explode( ',', (string) $s['tr_vocab'] ) ), 'strlen' ), 0, 100 );
		if ( $vocab ) {
			$body['word_boost']  = array_values( $vocab );
			$body['boost_param'] = 'high';
		}

		$res = self::request( 'POST', self::API, $body );

		// Some AssemblyAI speech models don't accept word_boost: retry once without it.
		if ( is_wp_error( $res ) && $vocab && 0 === strpos( $res->get_error_code(), 'ssfv_aai_4' ) ) {
			unset( $body['word_boost'], $body['boost_param'] );
			$res = self::request( 'POST', self::API, $body );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['id'] ) ) {
			return new WP_Error( 'ssfv_aai', __( 'AssemblyAI didn\'t return a job ID.', 'sunday-sermon-facebook-video' ) );
		}

		$ts    = ! empty( $video['created'] ) ? strtotime( $video['created'] ) : false;
		$title = ( '' !== (string) $video['title'] ? $video['title'] : __( 'Sunday service', 'sunday-sermon-facebook-video' ) )
			. ( $ts ? ' — ' . wp_date( get_option( 'date_format' ), $ts ) : '' );

		$postarr = array(
			'post_type'    => self::CPT,
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => '',
		);
		if ( $existing ) {
			$postarr['ID'] = $existing;
			$id            = wp_update_post( $postarr, true );
		} else {
			$id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, '_ssfv_key', $key );
		update_post_meta( $id, '_ssfv_job', sanitize_text_field( $res['id'] ) );
		update_post_meta( $id, '_ssfv_status', 'queued' );
		update_post_meta( $id, '_ssfv_url', esc_url_raw( $video['url'] ) );
		update_post_meta( $id, '_ssfv_date', sanitize_text_field( (string) $video['created'] ) );
		delete_post_meta( $id, '_ssfv_error' );

		return $id;
	}

	/** Check jobs that are still running. */
	public static function poll() {
		$ids = get_posts(
			array(
				'post_type'      => self::CPT,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 10,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_ssfv_status',
						'value'   => array( 'queued', 'processing' ),
						'compare' => 'IN',
					),
				),
			)
		);

		foreach ( $ids as $id ) {
			$job = get_post_meta( $id, '_ssfv_job', true );
			if ( ! $job ) {
				continue;
			}
			$res = self::request( 'GET', self::API . '/' . rawurlencode( $job ) );
			if ( is_wp_error( $res ) ) {
				continue; // Try again on the next tick.
			}

			$status = isset( $res['status'] ) ? $res['status'] : '';

			if ( 'completed' === $status ) {
				$paras = self::request( 'GET', self::API . '/' . rawurlencode( $job ) . '/paragraphs' );
				$lines = array();
				if ( ! is_wp_error( $paras ) && ! empty( $paras['paragraphs'] ) ) {
					foreach ( $paras['paragraphs'] as $p ) {
						$text = isset( $p['text'] ) ? trim( $p['text'] ) : '';
						if ( '' !== $text ) {
							$lines[] = '[' . self::format_time( isset( $p['start'] ) ? (int) $p['start'] : 0 ) . '] ' . $text;
						}
					}
				}
				if ( ! $lines && ! empty( $res['text'] ) ) {
					$lines[] = trim( $res['text'] );
				}
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => implode( "\n\n", $lines ),
					)
				);
				update_post_meta( $id, '_ssfv_status', 'completed' );
				update_post_meta( $id, '_ssfv_seconds', isset( $res['audio_duration'] ) ? (int) $res['audio_duration'] : 0 );
				delete_post_meta( $id, '_ssfv_sum_status' );
				if ( SSFV_Summaries::enabled() ) {
					SSFV_Summaries::summarize( $id );
				}
			} elseif ( 'error' === $status ) {
				update_post_meta( $id, '_ssfv_status', 'error' );
				update_post_meta( $id, '_ssfv_error', sanitize_text_field( isset( $res['error'] ) ? $res['error'] : 'Unknown error' ) );
			} elseif ( $status ) {
				update_post_meta( $id, '_ssfv_status', sanitize_key( $status ) );
			}
		}
	}

	/** Runs every 5 minutes: check running jobs, then send the newest sermons. */
	public static function tick() {
		if ( ! self::enabled() ) {
			return;
		}
		self::poll();
		SSFV_Summaries::backfill();

		$s = self::s();
		if ( empty( $s['tr_auto'] ) ) {
			return;
		}

		$usage = self::month_usage();
		$limit = max( 1, (int) $s['tr_limit'] );

		foreach ( array_slice( SSFV_Sunday_Sermon_Facebook_Video::get_videos(), 0, self::AUTO_NEWEST ) as $video ) {
			if ( $usage['count'] >= $limit ) {
				break;
			}
			if ( empty( $video['key'] ) || ! empty( $video['live'] ) || empty( $video['file'] ) || self::find_post( $video['key'] ) ) {
				continue;
			}
			if ( ! is_wp_error( self::submit( $video ) ) ) {
				$usage['count']++;
			}
		}
	}

	public static function handle_transcribe() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sunday-sermon-facebook-video' ) );
		}
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		check_admin_referer( 'ssfv_transcribe_' . $key );

		$result = new WP_Error( 'ssfv_missing', __( 'That service is no longer in the list. Click "Check Facebook now" and try again.', 'sunday-sermon-facebook-video' ) );
		foreach ( SSFV_Sunday_Sermon_Facebook_Video::get_videos() as $video ) {
			if ( isset( $video['key'] ) && $video['key'] === $key ) {
				$result = self::submit( $video, true );
				break;
			}
		}

		if ( is_wp_error( $result ) ) {
			set_transient( 'ssfv_tr_notice_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
			$flag = 'err';
		} else {
			$flag = 'ok';
		}
		wp_safe_redirect( add_query_arg( 'ssfv_tr', $flag, admin_url( 'admin.php?page=' . SSFV_Sunday_Sermon_Facebook_Video::SLUG ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Text helpers
	 * ------------------------------------------------------------------ */

	public static function format_time( $ms ) {
		$sec = (int) floor( $ms / 1000 );
		$h   = intdiv( $sec, 3600 );
		$m   = intdiv( $sec % 3600, 60 );
		$s   = $sec % 60;
		return $h ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
	}

	/** Turn stored content into array( array( seconds|null, text ) ). */
	public static function parse( $content ) {
		$content = wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br />' ), "\n", (string) $content ) );
		$out     = array();
		foreach ( preg_split( '/\R+/', $content ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^\[(\d{1,2}(?::\d{2}){1,2})\]\s*(.*)$/', $line, $m ) ) {
				$parts = array_map( 'intval', explode( ':', $m[1] ) );
				$sec   = 0;
				foreach ( $parts as $p ) {
					$sec = $sec * 60 + $p;
				}
				$out[] = array( $sec, $m[2] );
			} elseif ( $out ) {
				$out[ count( $out ) - 1 ][1] .= ' ' . $line;
			} else {
				$out[] = array( null, $line );
			}
		}
		return $out;
	}

	private static function body_html( $post_id ) {
		$html = '';
		$s    = self::s();
		list( $from, $to ) = ! empty( $s['sum_trim'] ) ? SSFV_Summaries::range( $post_id ) : array( null, null );
		foreach ( self::parse( get_post_field( 'post_content', $post_id, 'raw' ) ) as $p ) {
			if ( null !== $p[0] && ( ( null !== $from && $p[0] < $from ) || ( null !== $to && $p[0] > $to ) ) ) {
				continue;
			}
			$html .= '<p>';
			if ( null !== $p[0] ) {
				$label = self::format_time( $p[0] * 1000 );
				$html .= '<button type="button" class="ssv-ts" data-t="' . (int) $p[0] . '" aria-label="' . esc_attr( sprintf( /* translators: %s: time */ __( 'Play from %s', 'sunday-sermon-facebook-video' ), $label ) ) . '">' . esc_html( $label ) . '</button> ';
			}
			$html .= esc_html( $p[1] ) . '</p>';
		}
		return $html;
	}

	/* ------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	/**
	 * The collapsible transcript under a player. $lazy loads it when opened (archive panels).
	 */
	/**
	 * $part: 'all' (summary + transcript), 'summary' or 'transcript'. $open starts the transcript expanded.
	 */
	public static function render( $video, $lazy = false, $part = 'all', $open = false ) {
		$key = isset( $video['key'] ) ? (string) $video['key'] : '';
		$id  = self::find_post( $key );
		if ( ! $id || 'completed' !== get_post_meta( $id, '_ssfv_status', true ) ) {
			return '';
		}

		$out = self::assets();
		if ( 'transcript' !== $part ) {
			$out .= SSFV_Summaries::render( $id );
		}
		if ( 'summary' === $part ) {
			return $out;
		}
		$out .= '<details class="ssv-transcript"' . ( $lazy ? ' data-key="' . esc_attr( $key ) . '"' : '' ) . ( $open ? ' open' : '' ) . '>';
		$out .= '<summary>' . esc_html__( 'Transcript', 'sunday-sermon-facebook-video' ) . '</summary>';
		$out .= '<div class="ssv-tr-body">' . ( $lazy ? '<p class="ssv-tr-loading">' . esc_html__( 'Loading…', 'sunday-sermon-facebook-video' ) . '</p>' : self::body_html( $id ) ) . '</div>';
		$out .= '</details>';
		return $out;
	}

	private static function assets() {
		static $done = false;
		if ( $done ) {
			return '';
		}
		$done = true;

		return '<style>'
			. '.ssv-transcript{margin:12px 0 0;border:1px solid rgba(127,127,127,.3);border-radius:10px;padding:0 16px}'
			. '.ssv-transcript summary{cursor:pointer;padding:10px 0;font-weight:600}'
			. '.ssv-tr-body{max-height:420px;overflow-y:auto;padding:0 0 12px;line-height:1.6}'
			. '.ssv-tr-body p{margin:0 0 .8em}'
			. '.ssv-archive .ssv-side .ssv-transcript{margin-top:18px}'
			. '.ssv-archive .ssv-side .ssv-tr-body{max-height:520px}'
			. '.ssv-ts{all:unset;cursor:pointer;font-size:.8em;opacity:.65;margin-right:6px;font-variant-numeric:tabular-nums}'
			. '.ssv-ts:hover,.ssv-ts:focus-visible{opacity:1;text-decoration:underline}'
			. SSFV_Summaries::css()
			. '</style>'
			. '<script>(function(){if(window.ssfvTr)return;window.ssfvTr=1;'
			. 'var api=' . wp_json_encode( esc_url_raw( rest_url( 'ssfv/v1/transcript/' ) ) ) . ';'
			. 'document.addEventListener("click",function(e){var b=e.target.closest(".ssv-ts");if(!b)return;'
			. 'var a=b.closest(".ssv-archive");var w=a?a.querySelector(".ssv-panel:not([hidden])"):(b.closest(".ssv-panel")||b.closest(".ssv-sermon"));'
			. 'var v=w&&w.querySelector("video");if(!v)return;'
			. 'v.currentTime=parseInt(b.getAttribute("data-t"),10)||0;v.play();if(window.innerWidth<720){v.scrollIntoView({behavior:"smooth",block:"center"});}});'
			. 'window.ssfvTrLoad=function(d){if(!d||!d.open||!d.getAttribute("data-key")||d.getAttribute("data-loaded"))return;'
			. 'd.setAttribute("data-loaded","1");var body=d.querySelector(".ssv-tr-body");'
			. 'fetch(api+encodeURIComponent(d.getAttribute("data-key"))).then(function(r){return r.json();}).then(function(j){body.innerHTML=j&&j.html?j.html:"";}).catch(function(){body.textContent="";});};'
			. 'document.addEventListener("toggle",function(e){var d=e.target;if(d.matches&&d.matches("details.ssv-transcript"))window.ssfvTrLoad(d);},true);'
			. '})();</script>';
	}

	public static function rest_routes() {
		register_rest_route(
			'ssfv/v1',
			'/transcript/(?P<key>[A-Za-z0-9_-]+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true', // Transcripts of public sermons.
				'callback'            => static function ( $req ) {
					$id = self::find_post( sanitize_text_field( $req['key'] ) );
					if ( ! $id || 'completed' !== get_post_meta( $id, '_ssfv_status', true ) ) {
						return new WP_Error( 'ssfv_not_found', 'Not found', array( 'status' => 404 ) );
					}
					return array( 'html' => self::body_html( $id ) );
				},
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Admin pieces (called from the main class)
	 * ------------------------------------------------------------------ */

	public static function settings_card( $s, $name ) {
		?>
		<div class="ssv-card">
			<h2><span class="dashicons dashicons-text-page"></span><?php esc_html_e( 'Transcripts (AssemblyAI)', 'sunday-sermon-facebook-video' ); ?></h2>
			<p class="ssv-card-desc"><?php esc_html_e( 'Automatically transcribe each new service and show the transcript under the video. Visitors can click a line to jump to that moment.', 'sunday-sermon-facebook-video' ); ?></p>
			<?php
			SSFV_Sunday_Sermon_Facebook_Video::switch_field_public( 'tr_enabled', __( 'Turn on transcripts', 'sunday-sermon-facebook-video' ), __( 'Needs an AssemblyAI account. AssemblyAI bills you per hour of audio.', 'sunday-sermon-facebook-video' ), $s );
			?>
			<div class="ssv-field">
				<label for="ssv_aai_key"><?php esc_html_e( 'AssemblyAI API key', 'sunday-sermon-facebook-video' ); ?></label>
				<div class="ssv-token-row">
					<input type="password" id="ssv_aai_key" name="<?php echo esc_attr( $name ); ?>[aai_key]" value="" autocomplete="off" placeholder="<?php echo $s['aai_key'] ? esc_attr__( 'Saved. Leave blank to keep it.', 'sunday-sermon-facebook-video' ) : esc_attr__( 'Paste API key', 'sunday-sermon-facebook-video' ); ?>">
					<?php if ( $s['aai_key'] ) : ?>
						<span class="ssv-pill ok"><span class="ssv-dot"></span><?php esc_html_e( 'Saved', 'sunday-sermon-facebook-video' ); ?></span>
					<?php endif; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Find it on your AssemblyAI dashboard under API Keys.', 'sunday-sermon-facebook-video' ); ?></p>
			</div>
			<?php if ( $s['aai_key'] ) : ?>
				<div class="ssv-field">
					<label class="ssv-switch">
						<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_aai_key]" value="1">
						<span class="ssv-track"></span>
						<span><strong><?php esc_html_e( 'Remove saved API key', 'sunday-sermon-facebook-video' ); ?></strong><span class="d"><?php esc_html_e( 'Stops transcribing when you save.', 'sunday-sermon-facebook-video' ); ?></span></span>
					</label>
				</div>
			<?php endif; ?>
			<?php
			SSFV_Sunday_Sermon_Facebook_Video::switch_field_public( 'tr_auto', __( 'Transcribe new services automatically', 'sunday-sermon-facebook-video' ), __( 'The 3 newest services are sent once each. Older ones can be sent from the Dashboard.', 'sunday-sermon-facebook-video' ), $s );
			?>
			<div class="ssv-field">
				<label for="ssv_tr_limit"><?php esc_html_e( 'Monthly limit', 'sunday-sermon-facebook-video' ); ?></label>
				<div class="ssv-inline"><input type="number" min="1" max="60" id="ssv_tr_limit" name="<?php echo esc_attr( $name ); ?>[tr_limit]" value="<?php echo esc_attr( $s['tr_limit'] ); ?>"> <?php esc_html_e( 'transcripts per month', 'sunday-sermon-facebook-video' ); ?></div>
				<p class="description"><?php esc_html_e( 'Caps automatic transcripts to control cost. Sending one by hand from the Dashboard ignores the limit.', 'sunday-sermon-facebook-video' ); ?></p>
			</div>
			<div class="ssv-field">
				<label for="ssv_tr_lang"><?php esc_html_e( 'Language', 'sunday-sermon-facebook-video' ); ?></label>
				<select id="ssv_tr_lang" name="<?php echo esc_attr( $name ); ?>[tr_lang]">
					<option value="en_us" <?php selected( $s['tr_lang'], 'en_us' ); ?>><?php esc_html_e( 'English (US)', 'sunday-sermon-facebook-video' ); ?></option>
					<option value="es" <?php selected( $s['tr_lang'], 'es' ); ?>><?php esc_html_e( 'Spanish', 'sunday-sermon-facebook-video' ); ?></option>
					<option value="auto" <?php selected( $s['tr_lang'], 'auto' ); ?>><?php esc_html_e( 'Detect automatically', 'sunday-sermon-facebook-video' ); ?></option>
				</select>
			</div>
			<div class="ssv-field">
				<label for="ssv_tr_vocab"><?php esc_html_e( 'Names and words to recognize', 'sunday-sermon-facebook-video' ); ?></label>
				<input type="text" id="ssv_tr_vocab" name="<?php echo esc_attr( $name ); ?>[tr_vocab]" value="<?php echo esc_attr( $s['tr_vocab'] ); ?>" placeholder="Pastor Smith, Kilgore, Habakkuk, Ecclesiastes">
				<p class="description"><?php esc_html_e( 'Comma-separated. Helps with pastors\' names, local places and Bible books.', 'sunday-sermon-facebook-video' ); ?></p>
			</div>
			<?php SSFV_Summaries::settings_fields( $s, $name ); ?>
		</div>
		<?php
	}

	public static function dashboard_card( $videos ) {
		if ( ! self::enabled() ) {
			return;
		}
		$usage = self::month_usage();
		$s     = self::s();
		?>
		<div class="ssv-card" style="margin-bottom:20px">
			<h2><span class="dashicons dashicons-text-page"></span><?php esc_html_e( 'Transcripts', 'sunday-sermon-facebook-video' ); ?></h2>
			<p class="ssv-card-desc">
				<?php
				printf(
					/* translators: 1: transcripts this month, 2: monthly limit, 3: audio minutes */
					esc_html__( 'This month: %1$d of %2$d automatic transcripts, %3$d minutes of audio.', 'sunday-sermon-facebook-video' ),
					(int) $usage['count'],
					(int) $s['tr_limit'],
					(int) $usage['minutes']
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::CPT ) ); ?>"><?php esc_html_e( 'All transcripts', 'sunday-sermon-facebook-video' ); ?></a>
			</p>
			<table class="ssv-table">
				<thead><tr>
					<th><?php esc_html_e( 'Service', 'sunday-sermon-facebook-video' ); ?></th>
					<th style="width:140px"><?php esc_html_e( 'Transcript', 'sunday-sermon-facebook-video' ); ?></th>
					<?php if ( SSFV_Summaries::enabled() ) : ?>
						<th style="width:140px"><?php esc_html_e( 'Summary', 'sunday-sermon-facebook-video' ); ?></th>
					<?php endif; ?>
					<th style="width:200px"></th>
				</tr></thead>
				<tbody>
				<?php foreach ( array_slice( $videos, 0, 8 ) as $v ) : ?>
					<?php
					$key = isset( $v['key'] ) ? (string) $v['key'] : '';
					$id  = self::find_post( $key );
					$st  = $id ? get_post_meta( $id, '_ssfv_status', true ) : '';
					$ts  = ! empty( $v['created'] ) ? strtotime( $v['created'] ) : false;
					?>
					<tr>
						<td><strong><?php echo esc_html( '' !== $v['title'] ? $v['title'] : __( 'Sunday service', 'sunday-sermon-facebook-video' ) ); ?></strong><br><small><?php echo $ts ? esc_html( wp_date( get_option( 'date_format' ), $ts ) ) : ''; ?></small></td>
						<td>
							<?php if ( 'completed' === $st ) : ?>
								<span class="ssv-pill ok"><?php esc_html_e( 'Done', 'sunday-sermon-facebook-video' ); ?></span>
							<?php elseif ( 'error' === $st ) : ?>
								<span class="ssv-pill bad" title="<?php echo esc_attr( get_post_meta( $id, '_ssfv_error', true ) ); ?>"><?php esc_html_e( 'Failed', 'sunday-sermon-facebook-video' ); ?></span>
							<?php elseif ( $st ) : ?>
								<span class="ssv-pill info"><?php esc_html_e( 'In progress', 'sunday-sermon-facebook-video' ); ?></span>
							<?php elseif ( ! empty( $v['live'] ) ) : ?>
								<span class="ssv-pill warn"><?php esc_html_e( 'After live ends', 'sunday-sermon-facebook-video' ); ?></span>
							<?php elseif ( empty( $v['file'] ) ) : ?>
								<span class="ssv-pill warn"><?php esc_html_e( 'No video file', 'sunday-sermon-facebook-video' ); ?></span>
							<?php else : ?>
								<span class="ssv-pill"><?php esc_html_e( 'Not started', 'sunday-sermon-facebook-video' ); ?></span>
							<?php endif; ?>
						</td>
						<?php if ( SSFV_Summaries::enabled() ) : ?>
							<?php $sum = 'completed' === $st ? get_post_meta( $id, '_ssfv_sum_status', true ) : ''; ?>
							<td>
								<?php if ( 'done' === $sum ) : ?>
									<span class="ssv-pill ok"><?php esc_html_e( 'Done', 'sunday-sermon-facebook-video' ); ?></span>
								<?php elseif ( 'error' === $sum ) : ?>
									<span class="ssv-pill bad" title="<?php echo esc_attr( get_post_meta( $id, '_ssfv_sum_error', true ) ); ?>"><?php esc_html_e( 'Failed', 'sunday-sermon-facebook-video' ); ?></span>
								<?php elseif ( 'completed' === $st ) : ?>
									<span class="ssv-pill info"><?php esc_html_e( 'Waiting', 'sunday-sermon-facebook-video' ); ?></span>
								<?php else : ?>
									<span class="ssv-pill">—</span>
								<?php endif; ?>
							</td>
						<?php endif; ?>
						<td style="text-align:right;white-space:nowrap">
							<?php if ( 'completed' === $st ) : ?>
								<?php if ( SSFV_Summaries::enabled() && 'done' !== get_post_meta( $id, '_ssfv_sum_status', true ) ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;margin:0">
										<input type="hidden" name="action" value="ssfv_summarize">
										<input type="hidden" name="post_id" value="<?php echo (int) $id; ?>">
										<?php wp_nonce_field( 'ssfv_summarize_' . $id ); ?>
										<button type="submit" class="button button-small"><?php esc_html_e( 'Summarize', 'sunday-sermon-facebook-video' ); ?></button>
									</form>
								<?php endif; ?>
								<a class="button button-small" href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'View / edit', 'sunday-sermon-facebook-video' ); ?></a>
							<?php elseif ( $key && 'manual' !== $key && ! in_array( $st, array( 'queued', 'processing' ), true ) && empty( $v['live'] ) && ! empty( $v['file'] ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0">
									<input type="hidden" name="action" value="ssfv_transcribe">
									<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
									<?php wp_nonce_field( 'ssfv_transcribe_' . $key ); ?>
									<button type="submit" class="button button-small"><?php echo 'error' === $st ? esc_html__( 'Retry', 'sunday-sermon-facebook-video' ) : esc_html__( 'Transcribe', 'sunday-sermon-facebook-video' ); ?></button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
