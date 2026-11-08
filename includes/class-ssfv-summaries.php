<?php
/**
 * Sermon detection and summaries through the Anthropic (Claude) API or AssemblyAI's LLM Gateway.
 *
 * Works on finished transcripts: finds where the sermon starts and ends inside the full
 * service, and writes a short summary, key points and the scripture passages. Results are
 * stored as post meta on the transcript and can be edited on its edit screen.
 *
 * @package Sunday_Sermon_Facebook_Video
 */

defined( 'ABSPATH' ) || exit;

final class SSFV_Summaries {

	const API     = 'https://api.anthropic.com/v1/messages';
	const AAI_LLM = 'https://llm-gateway.assemblyai.com/v1/chat/completions';
	const AAI_DEFAULT_MODEL = 'claude-sonnet-4-6';
	const VERSION = '2023-06-01';

	/** Longest transcript text sent, in characters (about 3 hours of speech). */
	const MAX_CHARS = 180000;

	public static function init() {
		add_action( 'add_meta_boxes_' . SSFV_Transcripts::CPT, array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . SSFV_Transcripts::CPT, array( __CLASS__, 'save_meta_box' ), 10, 2 );
		add_action( 'admin_post_ssfv_summarize', array( __CLASS__, 'handle_summarize' ) );
	}

	private static function s() {
		return SSFV_Sunday_Sermon_Facebook_Video::settings();
	}

	public static function provider() {
		$s = self::s();
		return 'claude' === $s['sum_provider'] ? 'claude' : 'assemblyai';
	}

	public static function enabled() {
		$s = self::s();
		if ( empty( $s['sum_enabled'] ) ) {
			return false;
		}
		return 'claude' === self::provider() ? ! empty( $s['anthropic_key'] ) : ! empty( $s['aai_key'] );
	}

	public static function models() {
		return array(
			'claude-haiku-5-5'  => __( 'Claude Haiku 5.5 (fast, lowest cost)', 'sunday-sermon-facebook-video' ),
			'claude-sonnet-5-5' => __( 'Claude Sonnet 5.5 (more careful)', 'sunday-sermon-facebook-video' ),
		);
	}

	/* ------------------------------------------------------------------
	 * Summarizing
	 * ------------------------------------------------------------------ */

	private static function prompt( $lines ) {
		return "Below is a timestamped transcript of a full church service recording. It may include worship songs, announcements, prayers, Scripture readings, a sermon, and an invitation or closing.\n\n"
			. "Tasks:\n"
			. "1. Find the sermon: the main preaching or teaching message. It starts where the preacher begins the message (often with a greeting, a Scripture reading they then preach from, or an opening illustration) and ends where the message concludes, before any closing song, invitation hymn, or announcements. Give the [m:ss] or [h:mm:ss] timestamps of the first and last transcript lines of the sermon, copied exactly from the transcript.\n"
			. "2. Summarize the sermon for church members who missed it: 2 or 3 short paragraphs in plain, warm language, written about the message (\"The sermon...\"), not as the preacher.\n"
			. "3. List 3 to 5 key points, one sentence each.\n"
			. "4. List the main Bible passages the sermon used, as standard references (for example \"John 3:16-21\").\n"
			. "5. Suggest a short sermon title if the preacher did not give one.\n\n"
			. "Do not quote song lyrics. Keep any direct quotes from the preacher under 15 words. If there is no sermon in the transcript, set sermon_start and sermon_end to empty strings and explain in the summary.\n\n"
			. "Reply with only a JSON object, no other text, in this form:\n"
			. "{\"sermon_start\":\"12:34\",\"sermon_end\":\"48:10\",\"title\":\"...\",\"summary\":\"...\",\"key_points\":[\"...\"],\"scripture\":[\"...\"]}\n\n"
			. "Transcript:\n" . $lines;
	}

	/**
	 * Summarize one finished transcript. Returns true or WP_Error.
	 */
	public static function summarize( $post_id ) {
		if ( ! self::enabled() ) {
			return new WP_Error( 'ssfv_sum_off', __( 'Summaries are turned off, or the API key for the chosen provider is missing.', 'sunday-sermon-facebook-video' ) );
		}
		if ( 'completed' !== get_post_meta( $post_id, '_ssfv_status', true ) ) {
			return new WP_Error( 'ssfv_sum_wait', __( 'The transcript isn\'t finished yet.', 'sunday-sermon-facebook-video' ) );
		}

		$text = (string) get_post_field( 'post_content', $post_id, 'raw' );
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'ssfv_sum_empty', __( 'The transcript is empty.', 'sunday-sermon-facebook-video' ) );
		}
		if ( strlen( $text ) > self::MAX_CHARS ) {
			$text = substr( $text, 0, self::MAX_CHARS );
		}

		$fail = static function ( $msg ) use ( $post_id ) {
			update_post_meta( $post_id, '_ssfv_sum_status', 'error' );
			update_post_meta( $post_id, '_ssfv_sum_error', sanitize_text_field( $msg ) );
			return new WP_Error( 'ssfv_sum', $msg );
		};

		$reply = self::ask( self::prompt( $text ) );
		if ( is_wp_error( $reply ) ) {
			return $fail( $reply->get_error_message() );
		}

		$json = self::extract_json( $reply );
		if ( ! $json ) {
			return $fail( __( 'Couldn\'t read the summary the AI model returned. Try again.', 'sunday-sermon-facebook-video' ) );
		}

		self::store( $post_id, $json );
		update_post_meta( $post_id, '_ssfv_sum_status', 'done' );
		delete_post_meta( $post_id, '_ssfv_sum_error' );
		return true;
	}

	/**
	 * Send one prompt to the chosen provider. Returns the reply text or WP_Error.
	 */
	private static function ask( $prompt ) {
		$s = self::s();

		if ( 'claude' === self::provider() ) {
			$model = array_key_exists( $s['sum_model'], self::models() ) ? $s['sum_model'] : 'claude-haiku-5-5';
			$res   = wp_remote_post(
				self::API,
				array(
					'timeout' => 90,
					'headers' => array(
						'x-api-key'         => $s['anthropic_key'],
						'anthropic-version' => self::VERSION,
						'content-type'      => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'model'      => $model,
							'max_tokens' => 2000,
							'messages'   => array(
								array(
									'role'    => 'user',
									'content' => $prompt,
								),
							),
						)
					),
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			$data = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( 200 !== $code ) {
				return new WP_Error( 'ssfv_sum', 'Anthropic: ' . ( isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code ) );
			}
			$reply = '';
			if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
				foreach ( $data['content'] as $block ) {
					if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
						$reply .= $block['text'];
					}
				}
			}
			return $reply;
		}

		// AssemblyAI LLM Gateway (OpenAI-style chat completions).
		$model = '' !== trim( (string) $s['aai_llm_model'] ) ? trim( $s['aai_llm_model'] ) : self::AAI_DEFAULT_MODEL;
		$res   = wp_remote_post(
			self::AAI_LLM,
			array(
				'timeout' => 90,
				'headers' => array(
					'authorization' => $s['aai_key'],
					'content-type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'max_tokens' => 2000,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return new WP_Error( 'ssfv_sum', 'AssemblyAI: ' . self::gateway_error( $code, $data, wp_remote_retrieve_body( $res ) ) );
		}
		$content = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		if ( is_array( $content ) ) { // Some gateways return content blocks.
			$text = '';
			foreach ( $content as $block ) {
				$text .= isset( $block['text'] ) ? $block['text'] : '';
			}
			$content = $text;
		}
		return (string) $content;
	}

	/** Readable message from an LLM Gateway error response. */
	public static function gateway_error( $code, $data, $raw ) {
		$parts = array();
		if ( is_array( $data ) ) {
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$parts[] = $data['message'];
			}
			if ( isset( $data['error']['message'] ) ) {
				$parts[] = $data['error']['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$parts[] = $data['error'];
			}
			if ( isset( $data['detail'] ) ) {
				$parts[] = is_string( $data['detail'] ) ? $data['detail'] : wp_json_encode( $data['detail'] );
			}
			if ( ! empty( $data['metadata']['errors'] ) && is_array( $data['metadata']['errors'] ) ) {
				$parts[] = implode( '; ', array_map( 'strval', $data['metadata']['errors'] ) );
			}
		}
		if ( ! $parts && '' !== trim( (string) $raw ) ) {
			$parts[] = wp_html_excerpt( wp_strip_all_tags( (string) $raw ), 200, '…' );
		}
		$msg = $parts ? implode( ' — ', array_unique( $parts ) ) : '';
		$msg = 'HTTP ' . (int) $code . ( '' !== $msg ? ': ' . $msg : '' );
		if ( is_array( $data ) && ! empty( $data['request_id'] ) ) {
			$msg .= ' (request ' . $data['request_id'] . ')';
		}
		return $msg;
	}

	/**
	 * Model IDs available on AssemblyAI's LLM Gateway (public list, cached for a day).
	 * Returns array( id => name ), or an empty array if the list can't be loaded.
	 */
	public static function gateway_models() {
		$cached = get_transient( 'ssfv_aai_models' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res = wp_remote_get( 'https://llm-gateway.assemblyai.com/v1/models', array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		$data   = json_decode( wp_remote_retrieve_body( $res ), true );
		$models = array();
		if ( ! empty( $data['data'] ) && is_array( $data['data'] ) ) {
			foreach ( $data['data'] as $m ) {
				if ( empty( $m['id'] ) ) {
					continue;
				}
				if ( ! empty( $m['retirement_date'] ) && (int) $m['retirement_date'] > 0 && (int) $m['retirement_date'] < time() ) {
					continue;
				}
				$models[ sanitize_text_field( $m['id'] ) ] = sanitize_text_field( ! empty( $m['name'] ) ? $m['name'] : $m['id'] );
			}
		}
		if ( $models ) {
			set_transient( 'ssfv_aai_models', $models, DAY_IN_SECONDS );
		}
		return $models;
	}

	public static function extract_json( $reply ) {
		$start = strpos( $reply, '{' );
		$end   = strrpos( $reply, '}' );
		if ( false === $start || false === $end || $end <= $start ) {
			return null;
		}
		$json = json_decode( substr( $reply, $start, $end - $start + 1 ), true );
		return is_array( $json ) ? $json : null;
	}

	/** Seconds from "m:ss" / "h:mm:ss" (brackets allowed), or null. */
	public static function to_seconds( $ts ) {
		$ts = trim( (string) $ts, " []\t" );
		if ( ! preg_match( '/^\d{1,2}(?::\d{2}){1,2}$/', $ts ) ) {
			return null;
		}
		$sec = 0;
		foreach ( explode( ':', $ts ) as $p ) {
			$sec = $sec * 60 + (int) $p;
		}
		return $sec;
	}

	private static function clean_list( $list, $max ) {
		$out = array();
		foreach ( (array) $list as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$out[] = $item;
			}
		}
		return array_slice( $out, 0, $max );
	}

	private static function store( $post_id, $json ) {
		$start = self::to_seconds( isset( $json['sermon_start'] ) ? $json['sermon_start'] : '' );
		$end   = self::to_seconds( isset( $json['sermon_end'] ) ? $json['sermon_end'] : '' );
		if ( null !== $start && null !== $end && $end < $start ) {
			$end = null;
		}

		update_post_meta( $post_id, '_ssfv_sermon_start', null === $start ? '' : $start );
		update_post_meta( $post_id, '_ssfv_sermon_end', null === $end ? '' : $end );
		update_post_meta( $post_id, '_ssfv_sermon_title', sanitize_text_field( isset( $json['title'] ) ? $json['title'] : '' ) );
		update_post_meta( $post_id, '_ssfv_summary', sanitize_textarea_field( isset( $json['summary'] ) ? $json['summary'] : '' ) );
		update_post_meta( $post_id, '_ssfv_points', self::clean_list( isset( $json['key_points'] ) ? $json['key_points'] : array(), 8 ) );
		update_post_meta( $post_id, '_ssfv_scripture', self::clean_list( isset( $json['scripture'] ) ? $json['scripture'] : array(), 10 ) );
	}

	/** Summarize one finished transcript that has no summary yet (called from the 5-minute cron). */
	public static function backfill() {
		if ( ! self::enabled() ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => SSFV_Transcripts::CPT,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'   => '_ssfv_status',
						'value' => 'completed',
					),
					array(
						'key'     => '_ssfv_sum_status',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		foreach ( $ids as $id ) {
			self::summarize( $id );
		}
	}

	public static function handle_summarize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'sunday-sermon-facebook-video' ) );
		}
		$id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_admin_referer( 'ssfv_summarize_' . $id );

		$result = self::summarize( $id );
		if ( is_wp_error( $result ) ) {
			set_transient( 'ssfv_tr_notice_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
			$flag = 'err';
		} else {
			$flag = 'sum';
		}
		wp_safe_redirect( add_query_arg( 'ssfv_tr', $flag, admin_url( 'admin.php?page=' . SSFV_Sunday_Sermon_Facebook_Video::SLUG ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Front end
	 * ------------------------------------------------------------------ */

	/** Sermon start/end in seconds, or array( null, null ). */
	public static function range( $post_id ) {
		$a = get_post_meta( $post_id, '_ssfv_sermon_start', true );
		$b = get_post_meta( $post_id, '_ssfv_sermon_end', true );
		return array( '' === $a ? null : (int) $a, '' === $b ? null : (int) $b );
	}

	public static function render( $post_id ) {
		$summary = (string) get_post_meta( $post_id, '_ssfv_summary', true );
		list( $start ) = self::range( $post_id );
		if ( '' === $summary && null === $start ) {
			return '';
		}

		$title  = (string) get_post_meta( $post_id, '_ssfv_sermon_title', true );
		$points = (array) get_post_meta( $post_id, '_ssfv_points', true );
		$refs   = (array) get_post_meta( $post_id, '_ssfv_scripture', true );

		$out = '<div class="ssv-summary">';
		if ( null !== $start ) {
			$label = SSFV_Transcripts::format_time( $start * 1000 );
			$out  .= '<button type="button" class="ssv-ts ssv-start-sermon" data-t="' . (int) $start . '">'
				. esc_html( sprintf( /* translators: %s: time */ __( '▶ Start at the sermon (%s)', 'sunday-sermon-facebook-video' ), $label ) ) . '</button>';
		}
		if ( '' !== $summary ) {
			$out .= '<h4 class="ssv-summary-title">' . esc_html( '' !== $title ? $title : __( 'Sermon summary', 'sunday-sermon-facebook-video' ) ) . '</h4>';
			if ( $refs = array_filter( $refs ) ) {
				$out .= '<p class="ssv-scripture">' . esc_html( implode( ' · ', $refs ) ) . '</p>';
			}
			foreach ( preg_split( '/\R{2,}|\R/', $summary ) as $para ) {
				if ( '' !== trim( $para ) ) {
					$out .= '<p>' . esc_html( trim( $para ) ) . '</p>';
				}
			}
			if ( $points = array_filter( $points ) ) {
				$out .= '<ul class="ssv-points">';
				foreach ( $points as $pt ) {
					$out .= '<li>' . esc_html( $pt ) . '</li>';
				}
				$out .= '</ul>';
			}
		}
		$out .= '</div>';
		return $out;
	}

	public static function css() {
		return '.ssv-summary{margin:14px 0 0;padding:14px 18px;border-radius:10px;background:rgba(127,127,127,.08)}'
			. '.ssv-summary-title{margin:6px 0 2px;font-size:1.05em}'
			. '.ssv-scripture{margin:0 0 8px;font-size:.88em;opacity:.75}'
			. '.ssv-summary p{margin:0 0 .7em;line-height:1.6}'
			. '.ssv-points{margin:0 0 .3em 1.2em;padding:0}'
			. '.ssv-points li{margin:0 0 .35em}'
			. '.ssv-start-sermon{opacity:1!important;font-size:.9em!important;font-weight:600;border:1px solid currentColor!important;border-radius:999px!important;padding:4px 12px!important;text-decoration:none!important}';
	}

	/* ------------------------------------------------------------------
	 * Admin
	 * ------------------------------------------------------------------ */

	public static function meta_box() {
		add_meta_box( 'ssfv_summary', __( 'Sermon summary', 'sunday-sermon-facebook-video' ), array( __CLASS__, 'render_meta_box' ), SSFV_Transcripts::CPT, 'normal', 'high' );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( 'ssfv_summary_box', 'ssfv_summary_nonce' );
		list( $start, $end ) = self::range( $post->ID );
		$fmt = static function ( $sec ) {
			return null === $sec ? '' : SSFV_Transcripts::format_time( $sec * 1000 );
		};
		$err = get_post_meta( $post->ID, '_ssfv_sum_error', true );
		?>
		<?php if ( $err ) : ?>
			<p style="color:#a51d1d"><?php echo esc_html( $err ); ?></p>
		<?php endif; ?>
		<p>
			<label><?php esc_html_e( 'Sermon starts at', 'sunday-sermon-facebook-video' ); ?> <input type="text" name="ssfv_sermon_start" value="<?php echo esc_attr( $fmt( $start ) ); ?>" placeholder="12:34" style="width:90px"></label>
			&nbsp;
			<label><?php esc_html_e( 'ends at', 'sunday-sermon-facebook-video' ); ?> <input type="text" name="ssfv_sermon_end" value="<?php echo esc_attr( $fmt( $end ) ); ?>" placeholder="48:10" style="width:90px"></label>
		</p>
		<p><label><strong><?php esc_html_e( 'Sermon title', 'sunday-sermon-facebook-video' ); ?></strong><br><input type="text" class="widefat" name="ssfv_sermon_title" value="<?php echo esc_attr( get_post_meta( $post->ID, '_ssfv_sermon_title', true ) ); ?>"></label></p>
		<p><label><strong><?php esc_html_e( 'Summary', 'sunday-sermon-facebook-video' ); ?></strong><br><textarea class="widefat" rows="6" name="ssfv_summary"><?php echo esc_textarea( get_post_meta( $post->ID, '_ssfv_summary', true ) ); ?></textarea></label></p>
		<p><label><strong><?php esc_html_e( 'Key points (one per line)', 'sunday-sermon-facebook-video' ); ?></strong><br><textarea class="widefat" rows="4" name="ssfv_points"><?php echo esc_textarea( implode( "\n", (array) get_post_meta( $post->ID, '_ssfv_points', true ) ) ); ?></textarea></label></p>
		<p><label><strong><?php esc_html_e( 'Scripture (one per line)', 'sunday-sermon-facebook-video' ); ?></strong><br><textarea class="widefat" rows="3" name="ssfv_scripture"><?php echo esc_textarea( implode( "\n", (array) get_post_meta( $post->ID, '_ssfv_scripture', true ) ) ); ?></textarea></label></p>
		<?php
	}

	public static function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['ssfv_summary_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ssfv_summary_nonce'] ) ), 'ssfv_summary_box' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$start = self::to_seconds( isset( $_POST['ssfv_sermon_start'] ) ? sanitize_text_field( wp_unslash( $_POST['ssfv_sermon_start'] ) ) : '' );
		$end   = self::to_seconds( isset( $_POST['ssfv_sermon_end'] ) ? sanitize_text_field( wp_unslash( $_POST['ssfv_sermon_end'] ) ) : '' );
		update_post_meta( $post_id, '_ssfv_sermon_start', null === $start ? '' : $start );
		update_post_meta( $post_id, '_ssfv_sermon_end', null === $end ? '' : $end );
		update_post_meta( $post_id, '_ssfv_sermon_title', isset( $_POST['ssfv_sermon_title'] ) ? sanitize_text_field( wp_unslash( $_POST['ssfv_sermon_title'] ) ) : '' );
		update_post_meta( $post_id, '_ssfv_summary', isset( $_POST['ssfv_summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ssfv_summary'] ) ) : '' );
		update_post_meta( $post_id, '_ssfv_points', self::clean_list( isset( $_POST['ssfv_points'] ) ? preg_split( '/\R/', wp_unslash( $_POST['ssfv_points'] ) ) : array(), 8 ) );
		update_post_meta( $post_id, '_ssfv_scripture', self::clean_list( isset( $_POST['ssfv_scripture'] ) ? preg_split( '/\R/', wp_unslash( $_POST['ssfv_scripture'] ) ) : array(), 10 ) );
		// phpcs:enable
		if ( ! get_post_meta( $post_id, '_ssfv_sum_status', true ) ) {
			update_post_meta( $post_id, '_ssfv_sum_status', 'done' );
		}
	}

	public static function settings_fields( $s, $name ) {
		?>
		<hr style="border:0;border-top:1px solid #e0e0e3;margin:20px 0">
		<h2 style="margin-bottom:4px"><span class="dashicons dashicons-format-quote"></span><?php esc_html_e( 'Sermon summaries', 'sunday-sermon-facebook-video' ); ?></h2>
		<p class="ssv-card-desc"><?php esc_html_e( 'After each transcript finishes, an AI model finds where the sermon starts and ends, and writes a summary, key points and the Bible passages used.', 'sunday-sermon-facebook-video' ); ?></p>
		<?php SSFV_Sunday_Sermon_Facebook_Video::switch_field_public( 'sum_enabled', __( 'Turn on summaries', 'sunday-sermon-facebook-video' ), __( 'Billed per use by the provider you choose; a few cents per service.', 'sunday-sermon-facebook-video' ), $s ); ?>
		<div class="ssv-field">
			<label for="ssv_sum_provider"><?php esc_html_e( 'Provider', 'sunday-sermon-facebook-video' ); ?></label>
			<select id="ssv_sum_provider" name="<?php echo esc_attr( $name ); ?>[sum_provider]">
				<option value="assemblyai" <?php selected( $s['sum_provider'], 'assemblyai' ); ?>><?php esc_html_e( 'AssemblyAI LLM Gateway (uses your AssemblyAI key above)', 'sunday-sermon-facebook-video' ); ?></option>
				<option value="claude" <?php selected( $s['sum_provider'], 'claude' ); ?>><?php esc_html_e( 'Claude, directly from Anthropic (needs an Anthropic key)', 'sunday-sermon-facebook-video' ); ?></option>
			</select>
		</div>

		<div class="ssv-field">
			<label for="ssv_aai_llm_model"><?php esc_html_e( 'AssemblyAI model', 'sunday-sermon-facebook-video' ); ?></label>
			<?php $gw = self::gateway_models(); ?>
			<?php if ( $gw ) : ?>
				<select id="ssv_aai_llm_model" name="<?php echo esc_attr( $name ); ?>[aai_llm_model]">
					<?php if ( ! isset( $gw[ $s['aai_llm_model'] ] ) ) : ?>
						<option value="<?php echo esc_attr( $s['aai_llm_model'] ); ?>" selected><?php echo esc_html( $s['aai_llm_model'] . ' ' . __( '(not in AssemblyAI\'s current list)', 'sunday-sermon-facebook-video' ) ); ?></option>
					<?php endif; ?>
					<?php foreach ( $gw as $mid => $mname ) : ?>
						<option value="<?php echo esc_attr( $mid ); ?>" <?php selected( $s['aai_llm_model'], $mid ); ?>><?php echo esc_html( $mname . ' (' . $mid . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Used only with the AssemblyAI provider. The list comes from AssemblyAI and refreshes daily.', 'sunday-sermon-facebook-video' ); ?></p>
			<?php else : ?>
				<input type="text" id="ssv_aai_llm_model" name="<?php echo esc_attr( $name ); ?>[aai_llm_model]" value="<?php echo esc_attr( $s['aai_llm_model'] ); ?>" placeholder="<?php echo esc_attr( self::AAI_DEFAULT_MODEL ); ?>">
				<p class="description"><?php esc_html_e( 'Used only with the AssemblyAI provider. Couldn\'t load AssemblyAI\'s model list right now, so enter a model ID by hand.', 'sunday-sermon-facebook-video' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="ssv-field">
			<label for="ssv_anthropic_key"><?php esc_html_e( 'Anthropic API key', 'sunday-sermon-facebook-video' ); ?></label>
			<div class="ssv-token-row">
				<input type="password" id="ssv_anthropic_key" name="<?php echo esc_attr( $name ); ?>[anthropic_key]" value="" autocomplete="off" placeholder="<?php echo $s['anthropic_key'] ? esc_attr__( 'Saved. Leave blank to keep it.', 'sunday-sermon-facebook-video' ) : esc_attr__( 'Paste API key', 'sunday-sermon-facebook-video' ); ?>">
				<?php if ( $s['anthropic_key'] ) : ?>
					<span class="ssv-pill ok"><span class="ssv-dot"></span><?php esc_html_e( 'Saved', 'sunday-sermon-facebook-video' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Used only with the Claude provider. Create one in the Claude Console (console.anthropic.com).', 'sunday-sermon-facebook-video' ); ?></p>
		</div>
		<?php if ( $s['anthropic_key'] ) : ?>
			<div class="ssv-field">
				<label class="ssv-switch">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_anthropic_key]" value="1">
					<span class="ssv-track"></span>
					<span><strong><?php esc_html_e( 'Remove saved Anthropic key', 'sunday-sermon-facebook-video' ); ?></strong><span class="d"><?php esc_html_e( 'Removes it when you save.', 'sunday-sermon-facebook-video' ); ?></span></span>
				</label>
			</div>
		<?php endif; ?>
		<div class="ssv-field">
			<label for="ssv_sum_model"><?php esc_html_e( 'Claude model', 'sunday-sermon-facebook-video' ); ?></label>
			<select id="ssv_sum_model" name="<?php echo esc_attr( $name ); ?>[sum_model]">
				<?php foreach ( self::models() as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['sum_model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<p class="description"><?php esc_html_e( 'Used only with the Claude provider.', 'sunday-sermon-facebook-video' ); ?></p>
		</div>
		<?php SSFV_Sunday_Sermon_Facebook_Video::switch_field_public( 'sum_trim', __( 'Show only the sermon in the transcript', 'sunday-sermon-facebook-video' ), __( 'Hides songs, announcements and everything outside the sermon. The full transcript stays saved.', 'sunday-sermon-facebook-video' ), $s ); ?>
		<?php
	}
}
