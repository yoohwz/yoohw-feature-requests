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
		$pending = new \WP_Query( array( 'post_type' => self::TYPE, 'post_status' => 'pending', 'author' => $user_id, 'fields' => 'ids', 'posts_per_page' => 5, 'no_found_rows' => true ) );
		if ( count( $pending->posts ) >= 5 ) {
			return new \WP_Error( 'pending_limit', __( 'You already have five requests awaiting moderation. Please wait for a review.', 'yoohw-feature-requests' ) );
		}
		$id = wp_insert_post(
			wp_slash( array(
				'post_type' => self::TYPE, 'post_status' => 'pending', 'post_author' => $user_id,
				'post_title' => $data['title'], 'post_content' => $data['content'],
				'comment_status' => 'closed', 'ping_status' => 'closed',
				'meta_input' => array( self::META_KIND => $data['kind'], self::META_STATE => 'under_review', self::META_URL => $data['url'] ),
			) ),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! $id || get_post_meta( $id, self::META_KIND, true ) !== $data['kind'] || get_post_meta( $id, self::META_STATE, true ) !== 'under_review' || get_post_meta( $id, self::META_URL, true ) !== $data['url'] ) {
			if ( $id ) { wp_delete_post( $id, true ); }
			return new \WP_Error( 'save_failed', __( 'The request could not be saved. Please try again.', 'yoohw-feature-requests' ) );
		}
		foreach ( $data['terms'] as $taxonomy => $term_id ) {
			if ( $term_id ) {
				$result = wp_set_object_terms( $id, array( $term_id ), $taxonomy );
				if ( is_wp_error( $result ) ) {
					wp_delete_post( $id, true );
					return new \WP_Error( 'save_failed', __( 'The request could not be saved. Please try again.', 'yoohw-feature-requests' ) );
				}
			}
		}
		do_action( 'yoohw_feature_requests_submitted', $id );
		set_transient( 'yfr_submit_' . $user_id, 1, MINUTE_IN_SECONDS );
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
