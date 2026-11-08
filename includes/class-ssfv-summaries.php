<?php
/**
 * Sermon detection and summaries through the Anthropic (Claude) API.
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

	public static function enabled() {
		$s = self::s();
		return ! empty( $s['sum_enabled'] ) && ! empty( $s['anthropic_key'] );
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
			return new WP_Error( 'ssfv_sum_off', __( 'Summaries are turned off, or the Anthropic API key is missing.', 'sunday-sermon-facebook-video' ) );
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

		$s     = self::s();
		$model = array_key_exists( $s['sum_model'], self::models() ) ? $s['sum_model'] : 'claude-haiku-5-5';

		$res = wp_remote_post(
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
								'content' => self::prompt( $text ),
							),
						),
					)
				),
			)
		);

		$fail = static function ( $msg ) use ( $post_id ) {
			update_post_meta( $post_id, '_ssfv_sum_status', 'error' );
			update_post_meta( $post_id, '_ssfv_sum_error', sanitize_text_field( $msg ) );
			return new WP_Error( 'ssfv_sum', $msg );
		};

		if ( is_wp_error( $res ) ) {
			return $fail( $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			return $fail( 'Anthropic: ' . ( isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code ) );
		}

		$reply = '';
		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$reply .= $block['text'];
				}
			}
		}

		$json = self::extract_json( $reply );
		if ( ! $json ) {
			return $fail( __( 'Couldn\'t read the summary Claude returned. Try again.', 'sunday-sermon-facebook-video' ) );
		}

		self::store( $post_id, $json );
		update_post_meta( $post_id, '_ssfv_sum_status', 'done' );
		delete_post_meta( $post_id, '_ssfv_sum_error' );
		return true;
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
		<h2 style="margin-bottom:4px"><span class="dashicons dashicons-format-quote"></span><?php esc_html_e( 'Sermon summaries (Claude)', 'sunday-sermon-facebook-video' ); ?></h2>
		<p class="ssv-card-desc"><?php esc_html_e( 'After each transcript finishes, Claude finds where the sermon starts and ends, and writes a summary, key points and the Bible passages used.', 'sunday-sermon-facebook-video' ); ?></p>
		<?php SSFV_Sunday_Sermon_Facebook_Video::switch_field_public( 'sum_enabled', __( 'Turn on summaries', 'sunday-sermon-facebook-video' ), __( 'Needs an Anthropic API key. Anthropic bills per use; a one-hour service costs a few cents with Haiku.', 'sunday-sermon-facebook-video' ), $s ); ?>
		<div class="ssv-field">
			<label for="ssv_anthropic_key"><?php esc_html_e( 'Anthropic API key', 'sunday-sermon-facebook-video' ); ?></label>
			<div class="ssv-token-row">
				<input type="password" id="ssv_anthropic_key" name="<?php echo esc_attr( $name ); ?>[anthropic_key]" value="" autocomplete="off" placeholder="<?php echo $s['anthropic_key'] ? esc_attr__( 'Saved. Leave blank to keep it.', 'sunday-sermon-facebook-video' ) : esc_attr__( 'Paste API key', 'sunday-sermon-facebook-video' ); ?>">
				<?php if ( $s['anthropic_key'] ) : ?>
					<span class="ssv-pill ok"><span class="ssv-dot"></span><?php esc_html_e( 'Saved', 'sunday-sermon-facebook-video' ); ?></span>
				<?php endif; ?>
			</div>
			<p class="description"><?php esc_html_e( 'Create one in the Claude Console (console.anthropic.com) under API keys.', 'sunday-sermon-facebook-video' ); ?></p>
		</div>
		<?php if ( $s['anthropic_key'] ) : ?>
			<div class="ssv-field">
				<label class="ssv-switch">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_anthropic_key]" value="1">
					<span class="ssv-track"></span>
					<span><strong><?php esc_html_e( 'Remove saved Anthropic key', 'sunday-sermon-facebook-video' ); ?></strong><span class="d"><?php esc_html_e( 'Stops summarizing when you save.', 'sunday-sermon-facebook-video' ); ?></span></span>
				</label>
			</div>
		<?php endif; ?>
		<div class="ssv-field">
			<label for="ssv_sum_model"><?php esc_html_e( 'Model', 'sunday-sermon-facebook-video' ); ?></label>
			<select id="ssv_sum_model" name="<?php echo esc_attr( $name ); ?>[sum_model]">
				<?php foreach ( self::models() as $id => $label ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['sum_model'], $id ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php SSFV_Sunday_Sermon_Facebook_Video::switch_field_public( 'sum_trim', __( 'Show only the sermon in the transcript', 'sunday-sermon-facebook-video' ), __( 'Hides songs, announcements and everything outside the sermon. The full transcript stays saved.', 'sunday-sermon-facebook-video' ), $s ); ?>
		<?php
	}
}
