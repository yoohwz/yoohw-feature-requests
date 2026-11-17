<?php
/** Completed-save email notifications. @package YoOhwFeatureRequests */
namespace YoOhwFeatureRequests;
defined( 'ABSPATH' ) || exit;

final class Notifications {
	const OPTION = 'yfr_notifications';
	const RETRY = 'yoohw_feature_requests_notification_retry';
	private static $before = array();
	private static $deferred = array();
	private static $cancelled = array();

	public static function boot() {
		add_action( 'pre_post_update', array( __CLASS__, 'capture' ) );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'after_insert' ), 100, 3 );
		add_action( 'yoohw_feature_requests_submitted', array( __CLASS__, 'submitted' ) );
		add_action( 'yoohw_feature_requests_before_update', array( __CLASS__, 'begin' ) );
		add_action( 'yoohw_feature_requests_updated', array( __CLASS__, 'complete' ) );
		add_action( self::RETRY, array( __CLASS__, 'deliver' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'settings_changed' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'settings_changed' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
	}

	public static function config() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), array( 'new' => 1, 'moderation' => 1, 'roadmap' => 1, 'admin_email' => '', 'page_id' => 0 ) );
	}

	public static function snapshot( $id ) {
		return array( 'status' => get_post_status( $id ), 'state' => get_post_meta( $id, Requests::META_STATE, true ) ?: 'under_review' );
	}

	public static function capture( $id ) {
		if ( Requests::TYPE === get_post_type( $id ) && ! isset( self::$before[ $id ] ) && ! wp_is_post_revision( $id ) && ! wp_is_post_autosave( $id ) ) {
			self::$before[ $id ] = self::snapshot( $id );
			unset( self::$cancelled[ $id ] );
		}
	}

	public static function begin( $id ) {
		self::capture( $id );
		self::$deferred[ $id ] = true;
	}

	public static function cancel( $id ) {
		self::$cancelled[ $id ] = true;
	}

	public static function after_insert( $id, $post, $update ) {
		if ( $update && ! isset( self::$deferred[ $id ] ) ) {
			self::complete( $id );
		}
	}

	public static function complete( $id ) {
		$before = self::$before[ $id ] ?? null;
		$cancelled = isset( self::$cancelled[ $id ] );
		unset( self::$before[ $id ], self::$deferred[ $id ], self::$cancelled[ $id ] );
		if ( ! $before || $cancelled || Requests::TYPE !== get_post_type( $id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) { return; }
		$after = self::snapshot( $id );
		if ( $before === $after || in_array( $before['status'], array( 'trash', 'auto-draft' ), true ) || ! in_array( $after['status'], array( 'pending', 'publish', 'draft' ), true ) ) { return; }
		$config = self::config();
		$moderation = $before['status'] !== $after['status'] && in_array( $after['status'], array( 'publish', 'draft' ), true ) && $config['moderation'];
		$roadmap = 'publish' === $after['status'] && $before['state'] !== $after['state'] && in_array( $after['state'], array( 'planned', 'in_progress', 'released', 'declined' ), true ) && $config['roadmap'];
		if ( $moderation || $roadmap ) {
			self::queue( $id, 'update', array( 'moderation' => (bool) $moderation, 'roadmap' => (bool) $roadmap ), $after );
		}
	}

	public static function submitted( $id ) {
		if ( Requests::TYPE !== get_post_type( $id ) || 'pending' !== get_post_status( $id ) || ! self::config()['new'] || get_post_meta( $id, '_yfr_notice_new', true ) ) { return; }
		self::queue( $id, 'new', array( 'new' => true ), self::snapshot( $id ) );
	}

	private static function queue( $id, $type, $events, $after ) {
		$old = get_post_meta( $id, '_yfr_notice_' . $type, true );
		if ( is_array( $old ) && isset( $old['token'] ) ) { wp_clear_scheduled_hook( self::RETRY, array( $id, $type, $old['token'] ) ); }
		$job = array( 'token' => wp_generate_uuid4(), 'author' => (int) get_post_field( 'post_author', $id ), 'title' => wp_strip_all_tags( get_the_title( $id ) ), 'after' => $after, 'events' => $events, 'attempts' => 0, 'result' => 'pending', 'revision' => get_option( 'yfr_notification_revision', '' ) );
		update_post_meta( $id, '_yfr_notice_' . $type, $job );
		wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::RETRY, array( $id, $type, $job['token'] ) );
		self::deliver( $id, $type, $job['token'] );
	}

	/** One immutable event per type; a newer event supersedes a failed older update. */
	public static function deliver( $id, $type, $token ) {
		if ( ! in_array( $type, array( 'new', 'update' ), true ) ) { return; }
		global $wpdb;
		$lock_name = 'yfr_mail_lock_' . absint( $id ) . '_' . $type;
		$lock = ( time() + 5 * MINUTE_IN_SECONDS ) . ':' . wp_generate_uuid4();
		// Insert-only option lock: add_option can upsert a concurrent winner.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock ownership cannot use an options-cache snapshot.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, ':', 1) AS UNSIGNED) < %d", $lock_name, time() ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique option_name provides the delivery lock.
		$acquired = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lock_name, $lock ) );
		if ( 1 !== $acquired ) {
			if ( ! wp_next_scheduled( self::RETRY, array( $id, $type, $token ) ) ) { wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::RETRY, array( $id, $type, $token ) ); }
			return;
		}
		try { self::send( $id, $type, $token ); }
		finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only the current owner's lock.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock_name, $lock ) );
		}
	}

	private static function send( $id, $type, $token ) {
		if ( ! in_array( $type, array( 'new', 'update' ), true ) || Requests::TYPE !== get_post_type( $id ) ) { return; }
		$key = '_yfr_notice_' . $type;
		$job = get_post_meta( $id, $key, true );
		if ( ! is_array( $job ) || $job['token'] !== $token || 'pending' !== $job['result'] || $job['attempts'] >= 3 ) { return; }
		$config = self::config();
		$post = get_post( $id );
		$author = get_userdata( $job['author'] );
		$enabled = 'new' === $type ? $config['new'] : ( ( empty( $job['events']['moderation'] ) || $config['moderation'] ) && ( empty( $job['events']['roadmap'] ) || $config['roadmap'] ) );
		$recipient = 'new' === $type ? ( $config['admin_email'] ?: get_option( 'admin_email' ) ) : ( $author ? $author->user_email : '' );
		if ( ( $job['revision'] ?? '' ) !== get_option( 'yfr_notification_revision', '' ) || ! $enabled || ! $author || (int) $post->post_author !== $job['author'] || ! is_string( $recipient ) || ! is_email( $recipient ) || preg_match( '/[\r\n]/', $recipient ) || self::snapshot( $id ) !== $job['after'] ) {
			$previous = $job; $job['result'] = 'cancelled'; update_post_meta( $id, $key, $job, $previous ); wp_clear_scheduled_hook( self::RETRY, array( $id, $type, $token ) ); return;
		}
		$status_label = Requests::state_label( $id );
		if ( 'new' === $type ) {
			/* translators: %d: Request ID. */
			$subject = sprintf( __( 'New request #%d needs review', 'yoohw-feature-requests' ), $id );
			$intro = __( 'A new suggestion has been submitted and is waiting for your review.', 'yoohw-feature-requests' );
		} elseif ( ! empty( $job['events']['moderation'] ) ) {
			if ( 'publish' === $job['after']['status'] ) {
				/* translators: %d: Request ID. */
				$subject = sprintf( __( 'Your request #%d has been approved for consideration', 'yoohw-feature-requests' ), $id );
				$intro = __( 'Thank you for sharing your idea. Your request has passed moderation and is now approved for consideration.', 'yoohw-feature-requests' );
			} else {
				/* translators: %d: Request ID. */
				$subject = sprintf( __( 'Your request #%d was not approved', 'yoohw-feature-requests' ), $id );
				$intro = __( 'Thank you for taking the time to share your idea. Your request has not been approved for consideration at this time.', 'yoohw-feature-requests' );
			}
		} else {
			/* translators: 1: Request ID, 2: Current roadmap status. */
			$subject = sprintf( __( 'Request #%1$d: %2$s', 'yoohw-feature-requests' ), $id, $status_label );
			$intro = __( 'There is a new roadmap update for your request.', 'yoohw-feature-requests' );
		}
		$details = array();
		if ( 'new' === $type ) {
			$details[] = __( 'Review the details and choose whether to approve the suggestion for consideration or mark it as not approved.', 'yoohw-feature-requests' );
		} else {
			if ( 'publish' === $job['after']['status'] ) {
				$context = array(
					'under_review' => __( 'The suggestion is being reviewed. Approval does not promise implementation, a delivery date or paid work.', 'yoohw-feature-requests' ),
					'planned' => __( 'The suggestion is included in the current plan. Scope and timing may change. Approval does not promise implementation, a delivery date or paid work.', 'yoohw-feature-requests' ),
					'in_progress' => __( 'The roadmap now shows work in progress for this suggestion. Follow the request for the latest status; timing may change.', 'yoohw-feature-requests' ),
					'released' => __( 'This suggestion is now marked as released. Refer to the related product\'s release notes for availability and update instructions.', 'yoohw-feature-requests' ),
					'declined' => __( 'This suggestion is not planned for implementation at this time. Thank you for helping us understand what you need.', 'yoohw-feature-requests' ),
				);
				if ( isset( $context[ $job['after']['state'] ] ) ) { $details[] = $context[ $job['after']['state'] ]; }
			}
		}
		$url = 'new' === $type ? admin_url( 'post.php?post=' . absint( $id ) . '&action=edit' ) : self::request_url( $id );
		$message = self::email_content( $id, $job, $type, $intro, $details, $url );
		$previous = $job;
		++$job['attempts'];
		// Persist attempt before transport; no exactly-once delivery guarantee is possible.
		if ( ! update_post_meta( $id, $key, $job, $previous ) ) { return; }
		$claimed = $job;
		$accepted = wp_mail( $recipient, $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
		$current = get_post_meta( $id, $key, true );
		if ( ! is_array( $current ) || $current['token'] !== $token ) { return; }
		$job['result'] = $accepted ? 'accepted' : ( $job['attempts'] >= 3 ? 'failed' : 'pending' );
		update_post_meta( $id, $key, $job, $claimed );
		if ( 'pending' !== $job['result'] ) { wp_clear_scheduled_hook( self::RETRY, array( $id, $type, $token ) ); }
		if ( 'pending' === $job['result'] && ! wp_next_scheduled( self::RETRY, array( $id, $type, $token ) ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::RETRY, array( $id, $type, $token ) );
		}
	}

	/** An HTML content fragment only; the host's shared email shell is untouched. */
	private static function email_content( $id, $job, $type, $intro, $details, $url ) {
		$colors = array(
			'pending' => array( '#fef3c7', '#78350f' ),
			'publish' => array( '#dcfce7', '#14532d' ),
			'draft' => array( '#f1f5f9', '#334155' ),
			'planned' => array( '#dbeafe', '#1e3a8a' ),
			'in_progress' => array( '#fef3c7', '#78350f' ),
			'released' => array( '#dcfce7', '#14532d' ),
			'declined' => array( '#fee2e2', '#7f1d1d' ),
		);
		$statuses = array();
		if ( 'new' === $type || ! empty( $job['events']['moderation'] ) ) {
			$statuses[] = array( __( 'Moderation:', 'yoohw-feature-requests' ), Requests::moderation_label( (object) array( 'post_status' => $job['after']['status'] ) ), $job['after']['status'] );
		}
		if ( 'publish' === $job['after']['status'] ) {
			$statuses[] = array( __( 'Roadmap:', 'yoohw-feature-requests' ), Requests::states()[ $job['after']['state'] ] ?? Requests::states()['under_review'], $job['after']['state'] );
		}
		$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.65;color:#1f2937;">';
		$html .= '<p style="margin:0 0 20px;">' . esc_html( $intro ) . '</p>';
		$html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;border:1px solid #dbe2ea;border-radius:10px;background-color:#f8fafc;"><tr><td style="padding:20px;">';
		/* translators: %d: Request ID. */
		$html .= '<p style="margin:0 0 6px;font-size:12px;font-weight:700;color:#64748b;">' . esc_html( sprintf( __( 'REQUEST #%d', 'yoohw-feature-requests' ), $id ) ) . '</p>';
		$html .= '<h2 style="margin:0 0 16px;font-size:19px;line-height:1.4;font-weight:700;color:#182231;overflow-wrap:anywhere;">' . esc_html( $job['title'] ) . '</h2>';
		foreach ( $statuses as $row ) {
			$palette = $colors[ $row[2] ] ?? array( '#f1f5f9', '#334155' );
			$html .= '<p style="margin:10px 0 0;font-size:13px;"><span style="color:#475569;">' . esc_html( $row[0] ) . '</span> <span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;background-color:' . esc_attr( $palette[0] ) . ';color:' . esc_attr( $palette[1] ) . ';">' . esc_html( $row[1] ) . '</span></p>';
		}
		$html .= '</td></tr></table>';
		foreach ( $details as $detail ) { $html .= '<p style="margin:0 0 20px;color:#475569;">' . esc_html( $detail ) . '</p>'; }
		if ( $url ) {
			$label = 'new' === $type ? __( 'Review request', 'yoohw-feature-requests' ) : __( 'View your request', 'yoohw-feature-requests' );
			$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 8px;"><tr><td style="border-radius:8px;background-color:#1f2937;"><a href="' . esc_url( $url ) . '" style="display:inline-block;padding:13px 20px;border:1px solid #1f2937;border-radius:8px;background-color:#1f2937;color:#ffffff;font-size:14px;font-weight:700;line-height:1.4;text-decoration:none;">' . esc_html( $label ) . '</a></td></tr></table>';
		}
		return $html . '</div>';
	}

	public static function request_url( $id ) {
		$page = get_post( absint( self::config()['page_id'] ) );
		$url = $page && 'page' === $page->post_type && 'publish' === $page->post_status && ! $page->post_password && has_shortcode( $page->post_content, 'yfr_requests' ) ? add_query_arg( 'yfr_request', $id, get_permalink( $page ) ) : '';
		$url = apply_filters( 'yoohw_feature_requests_notification_url', $url, $id );
		$parts = is_string( $url ) ? wp_parse_url( $url ) : false;
		$home = wp_parse_url( home_url() );
		return is_array( $parts ) && ! empty( $parts['host'] ) && strtolower( $parts['host'] ) === strtolower( $home['host'] ) && in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) ? esc_url_raw( $url ) : '';
	}

	public static function settings_changed() {
		// A settings change invalidates queued messages; re-enabling never replays a backlog.
		update_option( 'yfr_notification_revision', wp_generate_uuid4(), false );
	}

	public static function menu() {
		add_submenu_page( 'edit.php?post_type=' . Requests::TYPE, __( 'Email notifications', 'yoohw-feature-requests' ), __( 'Email notifications', 'yoohw-feature-requests' ), 'manage_options', 'yfr-notifications', array( __CLASS__, 'screen' ) );
	}

	public static function settings() {
		register_setting( 'yfr_notifications', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
	}

	public static function sanitize( $input ) {
		if ( ! is_array( $input ) ) { return self::config(); }
		$result = array();
		foreach ( array( 'new', 'moderation', 'roadmap' ) as $key ) { $result[ $key ] = isset( $input[ $key ] ) && '1' === $input[ $key ] ? 1 : 0; }
		$email = $input['admin_email'] ?? '';
		if ( ! is_string( $email ) || ( '' !== $email && ( ! is_email( $email ) || preg_match( '/[\r\n]/', $email ) ) ) ) {
			add_settings_error( self::OPTION, 'invalid_email', __( 'Enter one valid administrator email address.', 'yoohw-feature-requests' ) );
			return self::config();
		}
		$result['admin_email'] = $email;
		$result['page_id'] = isset( $input['page_id'] ) && is_scalar( $input['page_id'] ) ? absint( $input['page_id'] ) : 0;
		$page = get_post( $result['page_id'] );
		if ( $result['page_id'] && ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status || $page->post_password || ! has_shortcode( $page->post_content, 'yfr_requests' ) ) ) {
			add_settings_error( self::OPTION, 'invalid_page', __( 'Choose a published page containing [yfr_requests].', 'yoohw-feature-requests' ) ); return self::config();
		}
		return $result;
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$config = self::config();
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Email notifications', 'yoohw-feature-requests' ); ?></h1><form action="options.php" method="post">
		<?php settings_fields( 'yfr_notifications' ); ?>
		<?php foreach ( array( 'new' => __( 'New requests to administrator', 'yoohw-feature-requests' ), 'moderation' => __( 'Moderation decisions to author', 'yoohw-feature-requests' ), 'roadmap' => __( 'Roadmap changes to author', 'yoohw-feature-requests' ) ) as $key => $label ) : ?>
		<p><label><input type="checkbox" name="yfr_notifications[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $config[ $key ], 1 ); ?>> <?php echo esc_html( $label ); ?></label></p>
		<?php endforeach; ?>
		<p><label for="yfr-email"><?php esc_html_e( 'Administrator recipient (blank uses site administrator email)', 'yoohw-feature-requests' ); ?></label><br><input id="yfr-email" type="email" class="regular-text" name="yfr_notifications[admin_email]" value="<?php echo esc_attr( $config['admin_email'] ); ?>"></p>
		<p><label for="yfr-email-page"><?php esc_html_e( 'Request page for author email links', 'yoohw-feature-requests' ); ?></label><br>
		<?php // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Core wp_dropdown_pages escapes the generated select and its options.
		wp_dropdown_pages( array( 'name' => 'yfr_notifications[page_id]', 'id' => 'yfr-email-page', 'selected' => $config['page_id'], 'show_option_none' => __( 'No author link', 'yoohw-feature-requests' ), 'option_none_value' => '0' ) ); ?></p>
		<p><?php esc_html_e( 'Select a published page containing [yfr_requests]. Messages contain title/status only. Existing requests are not emailed retroactively. Delivery uses your WordPress mail configuration; accepted mail is not confirmation of inbox delivery.', 'yoohw-feature-requests' ); ?></p>
		<?php submit_button(); ?></form></div>
		<?php
	}
}
