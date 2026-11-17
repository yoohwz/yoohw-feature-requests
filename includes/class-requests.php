<?php
/**
 * Request storage, validation and object access.
 *
 * @package YoOhwFeatureRequests
 */

namespace YoOhwFeatureRequests;

defined( 'ABSPATH' ) || exit;

final class Requests {
	const TYPE       = 'yfr_request';
	const MANAGE     = 'yfr_manage_requests';
	const CATEGORY   = 'yfr_category';
	const PRODUCT    = 'yfr_product';
	const STATUSES   = array( 'pending', 'publish', 'draft', 'trash', 'private', 'future' );
	const META_KIND  = '_yfr_kind';
	const META_STATE = '_yfr_state';
	const META_URL   = '_yfr_reference_url';
	const META_CREATED_BY = '_yfr_created_by';
	const META_CREATION_KEY = '_yfr_creation_key';

	public static function kinds() {
		return array(
			'feature'     => __( 'Feature', 'yoohw-feature-requests' ),
			'plugin'      => __( 'Plugin', 'yoohw-feature-requests' ),
			'integration' => __( 'Integration', 'yoohw-feature-requests' ),
		);
	}

	public static function states() {
		return array(
			'under_review' => __( 'Under Review', 'yoohw-feature-requests' ),
			'planned'      => __( 'Planned', 'yoohw-feature-requests' ),
			'in_progress'  => __( 'In Progress', 'yoohw-feature-requests' ),
			'released'     => __( 'Released', 'yoohw-feature-requests' ),
			'declined'     => __( 'Declined', 'yoohw-feature-requests' ),
		);
	}

	public static function activate( $network_wide = false ) {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate YoOhw Feature Requests on an individual site, not network-wide.', 'yoohw-feature-requests' ) );
		}
		self::register();
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( array_merge( array( self::MANAGE ), array_values( (array) get_post_type_object( self::TYPE )->cap ) ) as $cap ) {
				if ( ! in_array( $cap, array( 'read', 'read_yfr_request', 'edit_yfr_request', 'delete_yfr_request' ), true ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Feature Requests', 'yoohw-feature-requests' ),
					'singular_name' => __( 'Request', 'yoohw-feature-requests' ),
					'add_new_item'  => __( 'Add Request', 'yoohw-feature-requests' ),
					'edit_item'     => __( 'Review Request', 'yoohw-feature-requests' ),
					'search_items'  => __( 'Search Requests', 'yoohw-feature-requests' ),
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'menu_icon'           => 'dashicons-lightbulb',
				'capability_type'     => array( 'yfr_request', 'yfr_requests' ),
				'map_meta_cap'        => true,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'taxonomies'          => array( self::CATEGORY, self::PRODUCT ),
			)
		);
		foreach ( array( self::CATEGORY => __( 'Request Categories', 'yoohw-feature-requests' ), self::PRODUCT => __( 'Related Products', 'yoohw-feature-requests' ) ) as $taxonomy => $label ) {
			register_taxonomy(
				$taxonomy,
				self::TYPE,
				array(
					'label'             => $label,
					'public'            => false,
					'publicly_queryable'=> false,
					'show_ui'           => true,
					'show_in_rest'      => false,
					'show_admin_column' => true,
					'hierarchical'      => true,
					'rewrite'           => false,
					'query_var'         => false,
					'capabilities'      => array_fill_keys( array( 'manage_terms', 'edit_terms', 'delete_terms', 'assign_terms' ), self::MANAGE ),
				)
			);
		}
	}

	public static function can_view( $post ) {
		$post = get_post( $post );
		return is_user_logged_in() && $post && self::TYPE === $post->post_type
			&& ( current_user_can( self::MANAGE ) || get_current_user_id() === (int) $post->post_author );
	}

	public static function map_read_capability( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'read_post', 'read_yfr_request' ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( $args[0] );
		if ( ! $post || self::TYPE !== $post->post_type ) {
			return $caps;
		}
		$user = get_userdata( $user_id );
		return $user && ( ! empty( $user->allcaps[ self::MANAGE ] ) || (int) $user_id === (int) $post->post_author ) ? array( 'read' ) : array( 'do_not_allow' );
	}

	public static function length( $value ) {
		$count = preg_match_all( '/./us', $value );
		return false === $count ? strlen( $value ) : $count;
	}

	public static function allowed_html() {
		return array(
			'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(),
			'ul' => array(), 'ol' => array(), 'li' => array(), 'blockquote' => array(),
			'code' => array(), 'pre' => array(),
			'a' => array( 'href' => true, 'title' => true, 'rel' => true ),
		);
	}

	public static function text( $value ) {
		return is_string( $value ) ? trim( $value ) : '';
	}

	public static function validate( array $input ) {
		foreach ( array( 'title', 'description', 'kind', 'reference_url' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) ) {
				$code = 'kind' === $key ? 'invalid_kind' : ( 'reference_url' === $key ? 'invalid_url' : 'invalid_content' );
				return new \WP_Error( $code, __( 'Use text values for the request fields.', 'yoohw-feature-requests' ) );
			}
		}
		$title = sanitize_text_field( self::text( $input['title'] ?? '' ) );
		$raw   = self::text( $input['description'] ?? '' );
		$kind  = self::text( $input['kind'] ?? '' );
		$url   = self::text( $input['reference_url'] ?? '' );
		if ( self::length( $title ) < 3 || self::length( $title ) > 180 || self::length( $raw ) > 10000 ) {
			return new \WP_Error( 'invalid_content', __( 'Use a title of 3–180 characters and a description of at most 10,000 characters.', 'yoohw-feature-requests' ) );
		}
		$content = wp_kses( $raw, self::allowed_html() );
		if ( self::length( trim( wp_strip_all_tags( $content ) ) ) < 10 ) {
			return new \WP_Error( 'invalid_content', __( 'Add a description with at least 10 characters of text.', 'yoohw-feature-requests' ) );
		}
		if ( ! isset( self::kinds()[ $kind ] ) ) {
			return new \WP_Error( 'invalid_kind', __( 'Select a valid request type.', 'yoohw-feature-requests' ) );
		}
		if ( $url ) {
			$parts = wp_parse_url( $url );
			if ( strlen( $url ) > 2048 || ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['scheme'] ?? '' ), array( 'http', 'https' ), true ) || ! empty( $parts['user'] ) || ! empty( $parts['pass'] ) ) {
				return new \WP_Error( 'invalid_url', __( 'Use a complete http or https reference URL without credentials.', 'yoohw-feature-requests' ) );
			}
			$url = esc_url_raw( $url, array( 'http', 'https' ) );
		}
		$terms = array();
		foreach ( array( 'category' => self::CATEGORY, 'product' => self::PRODUCT ) as $key => $taxonomy ) {
			$value = $input[ $key ] ?? '';
			if ( ! is_scalar( $value ) || ( '' !== (string) $value && ! ctype_digit( (string) $value ) ) ) {
				return new \WP_Error( 'invalid_term', __( 'Select an existing category or product.', 'yoohw-feature-requests' ) );
			}
			$id = absint( $value );
			if ( $id && ! term_exists( $id, $taxonomy ) ) {
				return new \WP_Error( 'invalid_term', __( 'Select an existing category or product.', 'yoohw-feature-requests' ) );
			}
			$terms[ $taxonomy ] = $id;
		}
		return array( 'title' => $title, 'content' => $content, 'kind' => $kind, 'url' => $url, 'terms' => $terms );
	}

	public static function submit( array $input ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'login_required', __( 'Log in to submit a request.', 'yoohw-feature-requests' ) );
		}
		$data = self::validate( $input );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$user_id = get_current_user_id();
		if ( get_transient( 'yfr_submit_' . $user_id ) ) {
			return new \WP_Error( 'rate_limit', __( 'Please wait one minute before submitting another request.', 'yoohw-feature-requests' ) );
		}
		$pending = new \WP_Query( array( 'post_type' => self::TYPE, 'post_status' => 'pending', 'author' => $user_id, 'fields' => 'ids', 'posts_per_page' => 5, 'no_found_rows' => true, 'meta_query' => array( 'relation' => 'OR', array( 'key' => self::META_CREATED_BY, 'compare' => 'NOT EXISTS' ), array( 'key' => self::META_CREATED_BY, 'value' => $user_id, 'type' => 'NUMERIC' ) ) ) );
		if ( count( $pending->posts ) >= 5 ) {
			return new \WP_Error( 'pending_limit', __( 'You already have five requests awaiting moderation. Please wait for a review.', 'yoohw-feature-requests' ) );
		}
		$id = self::persist( $data, $user_id, $user_id, '', true );
		if ( ! is_wp_error( $id ) ) {
			set_transient( 'yfr_submit_' . $user_id, 1, MINUTE_IN_SECONDS );
		}
		return $id;
	}

	/** Create for an existing owner without changing the current actor/session. */
	public static function create_for_user( array $input, $owner_id, array $context = array() ) {
		$type = get_post_type_object( self::TYPE );
		if ( ! is_user_logged_in() || ! $type || ! current_user_can( self::MANAGE ) || ! current_user_can( $type->cap->create_posts ) ) {
			return new \WP_Error( 'forbidden', __( 'You cannot create requests for another user.', 'yoohw-feature-requests' ) );
		}
		if ( ! is_int( $owner_id ) || $owner_id < 1 || ! get_userdata( $owner_id ) ) {
			return new \WP_Error( 'invalid_owner', __( 'Select an existing request owner.', 'yoohw-feature-requests' ) );
		}
		$notify = array_key_exists( 'notify', $context ) ? $context['notify'] : true;
		$key = array_key_exists( 'creation_key', $context ) ? $context['creation_key'] : '';
		if ( ! is_bool( $notify ) || ! is_string( $key ) || ( '' !== $key && ! preg_match( '/^[a-f0-9]{64}$/D', $key ) ) ) {
			return new \WP_Error( 'invalid_context', __( 'Use a valid creation context.', 'yoohw-feature-requests' ) );
		}
		$data = self::validate( $input );
		if ( is_wp_error( $data ) ) { return $data; }
		$actor = get_current_user_id();
		if ( '' === $key ) {
			$id = self::persist( $data, $owner_id, $actor, '', $notify );
			return is_wp_error( $id ) ? $id : array( 'id' => $id, 'created' => true );
		}
		// Scope a confirmation to its actor, then verify the owner on every replay.
		$key = hash( 'sha256', $actor . '|' . $key );
		$lock = 'yfr_create_' . $key;
		$token = wp_generate_uuid4();
		global $wpdb;
		// Insert-only: add_option can update an existing row during a concurrent insert.
		$acquired = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $lock, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic confirmation lock, not a cached option read.
		if ( 1 !== $acquired ) {
			return new \WP_Error( 'creation_busy', __( 'This request is being saved. Please try again.', 'yoohw-feature-requests' ) );
		}
		try {
			$prior = get_posts( array( 'post_type' => self::TYPE, 'post_status' => self::STATUSES, 'meta_key' => self::META_CREATION_KEY, 'meta_value' => $key, 'posts_per_page' => 1, 'fields' => 'ids' ) );
			if ( $prior ) {
				$post = get_post( $prior[0] );
				if ( (int) $post->post_author !== $owner_id || (int) get_post_meta( $post->ID, self::META_CREATED_BY, true ) !== $actor ) {
					return new \WP_Error( 'creation_conflict', __( 'This confirmation belongs to a different request owner.', 'yoohw-feature-requests' ) );
				}
				return array( 'id' => (int) $post->ID, 'created' => false );
			}
			$id = self::persist( $data, $owner_id, $actor, $key, $notify );
			return is_wp_error( $id ) ? $id : array( 'id' => $id, 'created' => true );
		} finally {
			// Never release a lock owned by another operation.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $lock, $token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Ownership-checked release of the atomic lock.
			wp_cache_delete( $lock, 'options' );
		}
	}

	private static function persist( $data, $owner_id, $actor, $creation_key, $notify ) {
		$meta = array( self::META_KIND => $data['kind'], self::META_STATE => 'under_review', self::META_URL => $data['url'], self::META_CREATED_BY => $actor );
		if ( $creation_key ) { $meta[ self::META_CREATION_KEY ] = $creation_key; }
		$id = wp_insert_post(
			wp_slash( array(
				'post_type' => self::TYPE, 'post_status' => 'pending', 'post_author' => $owner_id,
				'post_title' => $data['title'], 'post_content' => $data['content'],
				'comment_status' => 'closed', 'ping_status' => 'closed',
				'meta_input' => $meta,
			) ),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! $id || self::TYPE !== get_post_type( $id ) || 'pending' !== get_post_status( $id ) || (int) get_post_field( 'post_author', $id ) !== $owner_id || (int) get_post_meta( $id, self::META_CREATED_BY, true ) !== $actor || ( $creation_key && get_post_meta( $id, self::META_CREATION_KEY, true ) !== $creation_key ) || get_post_meta( $id, self::META_KIND, true ) !== $data['kind'] || get_post_meta( $id, self::META_STATE, true ) !== 'under_review' || get_post_meta( $id, self::META_URL, true ) !== $data['url'] ) {
			if ( $id ) { wp_delete_post( $id, true ); }
			return new \WP_Error( 'save_failed', __( 'The request could not be saved. Please try again.', 'yoohw-feature-requests' ) );
		}
		foreach ( $data['terms'] as $taxonomy => $term_id ) {
			if ( $term_id ) {
				$result = wp_set_object_terms( $id, array( $term_id ), $taxonomy );
				$saved_terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $result ) || is_wp_error( $saved_terms ) || ! in_array( $term_id, array_map( 'intval', $saved_terms ), true ) ) {
					wp_delete_post( $id, true );
					return new \WP_Error( 'save_failed', __( 'The request could not be saved. Please try again.', 'yoohw-feature-requests' ) );
				}
			}
		}
		if ( $notify ) { do_action( 'yoohw_feature_requests_submitted', $id ); }
		return $id;
	}

	public static function moderation_label( $post ) {
		$labels = array(
			'pending' => __( 'Pending moderation', 'yoohw-feature-requests' ),
			'publish' => __( 'Published', 'yoohw-feature-requests' ),
			'draft'   => __( 'Not approved', 'yoohw-feature-requests' ),
			'trash'   => __( 'Removed', 'yoohw-feature-requests' ),
			'private' => __( 'Private', 'yoohw-feature-requests' ),
			'future'  => __( 'Scheduled', 'yoohw-feature-requests' ),
		);
		return $labels[ $post->post_status ] ?? __( 'Not available', 'yoohw-feature-requests' );
	}

	public static function state_label( $id ) {
		return self::states()[ get_post_meta( $id, self::META_STATE, true ) ] ?? self::states()['under_review'];
	}
}
