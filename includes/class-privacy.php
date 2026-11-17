<?php
/**
 * WordPress privacy-tool integration.
 *
 * @package YoOhwFeatureRequests
 */

namespace YoOhwFeatureRequests;

defined( 'ABSPATH' ) || exit;

final class Privacy {
	public static function boot() {
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'erasers' ) );
	}

	public static function policy() {
		wp_add_privacy_policy_content( 'YoOhw Feature Requests', wpautop( __( 'Feature requests store your WordPress user ID, title, description, request type, selected category/product, reference URL, moderation status and roadmap status in this site’s database. Requests are visible to their author and request moderators in this version. Do not submit secrets or personal information about others. Approved suggestions may be published on a community board in a future version; the site should explain its publication policy before doing so. The plugin does not publish your email, login name or a voter directory, and sends no data to external services. Submission throttling temporarily stores a user-linked flag; form error codes expire after five minutes. Requests and revisions are retained until deleted by an authorized administrator or through WordPress’s confirmed personal-data erasure process. Deactivation and uninstall retain requests, revisions and taxonomy data. WordPress’s privacy export includes your requests; confirmed erasure permanently deletes your requests and their revisions/metadata, retaining shared categories and products. This does not delete your WordPress account or copies already exported by the site administrator.', 'yoohw-feature-requests' ) ) );
	}

	public static function exporters( $exporters ) {
		$exporters['yfr-requests'] = array( 'exporter_friendly_name' => __( 'Feature Requests', 'yoohw-feature-requests' ), 'callback' => array( __CLASS__, 'export' ) );
		return $exporters;
	}

	public static function erasers( $erasers ) {
		$erasers['yfr-requests'] = array( 'eraser_friendly_name' => __( 'Feature Requests', 'yoohw-feature-requests' ), 'callback' => array( __CLASS__, 'erase' ) );
		return $erasers;
	}

	private static function query( $email, $page ) {
		$user = get_user_by( 'email', $email );
		return $user ? get_posts( array( 'post_type' => Requests::TYPE, 'post_status' => Requests::STATUSES, 'author' => $user->ID, 'numberposts' => 20, 'paged' => max( 1, (int) $page ), 'orderby' => 'ID', 'order' => 'ASC' ) ) : array();
	}

	public static function export( $email, $page = 1 ) {
		$posts = self::query( $email, $page );
		$data  = array();
		foreach ( $posts as $post ) {
			$fields = array(
				__( 'Title', 'yoohw-feature-requests' ) => $post->post_title,
				__( 'Description', 'yoohw-feature-requests' ) => $post->post_content,
				__( 'Type', 'yoohw-feature-requests' ) => get_post_meta( $post->ID, Requests::META_KIND, true ),
				__( 'Reference URL', 'yoohw-feature-requests' ) => get_post_meta( $post->ID, Requests::META_URL, true ),
				__( 'Moderation', 'yoohw-feature-requests' ) => Requests::moderation_label( $post ),
				__( 'Roadmap', 'yoohw-feature-requests' ) => Requests::state_label( $post->ID ),
			);
			foreach ( array( Requests::CATEGORY, Requests::PRODUCT ) as $taxonomy ) {
				$names = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
				$fields[ get_taxonomy( $taxonomy )->label ] = is_wp_error( $names ) ? '' : implode( ', ', $names );
			}
			$item = array( 'group_id' => 'yfr-requests', 'group_label' => __( 'Feature Requests', 'yoohw-feature-requests' ), 'item_id' => 'yfr-' . $post->ID, 'data' => array() );
			foreach ( $fields as $name => $value ) {
				$item['data'][] = array( 'name' => $name, 'value' => $value );
			}
			$data[] = $item;
		}
		return array( 'data' => $data, 'done' => count( $posts ) < 20 );
	}

	public static function erase( $email, $page = 1 ) {
		// Always consume the first remaining batch; offsets would skip deleted rows.
		$posts    = self::query( $email, 1 );
		$removed  = false;
		$retained = false;
		foreach ( $posts as $post ) {
			if ( wp_delete_post( $post->ID, true ) ) {
				$removed = true;
			} else {
				$retained = true;
			}
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			delete_transient( 'yfr_submit_' . $user->ID );
			delete_transient( 'yfr_form_error_' . $user->ID );
			delete_transient( 'yfr_admin_error_' . $user->ID );
		}
		return array(
			'items_removed' => $removed,
			'items_retained' => $retained,
			'messages' => $retained ? array( __( 'Some requests could not be deleted. An administrator must review them before retrying erasure.', 'yoohw-feature-requests' ) ) : array(),
			'done' => $retained || count( $posts ) < 20,
		);
	}
}
