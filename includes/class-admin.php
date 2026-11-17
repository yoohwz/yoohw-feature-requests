<?php
/**
 * WordPress moderation UI.
 *
 * @package YoOhwFeatureRequests
 */

namespace YoOhwFeatureRequests;

defined( 'ABSPATH' ) || exit;

final class Admin {
	public static function boot() {
		add_action( 'add_meta_boxes_' . Requests::TYPE, array( __CLASS__, 'boxes' ) );
		add_action( 'save_post_' . Requests::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . Requests::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Requests::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function boxes() {
		add_meta_box( 'yfr-details', __( 'Request Details', 'yoohw-feature-requests' ), array( __CLASS__, 'details' ), Requests::TYPE, 'normal', 'high' );
	}

	public static function details( $post ) {
		wp_nonce_field( 'yfr_details_' . $post->ID, 'yfr_details_nonce' );
		self::select( 'yfr_kind', __( 'Request type', 'yoohw-feature-requests' ), Requests::kinds(), get_post_meta( $post->ID, Requests::META_KIND, true ) ?: 'feature' );
		self::select( 'yfr_state', __( 'Roadmap status', 'yoohw-feature-requests' ), Requests::states(), get_post_meta( $post->ID, Requests::META_STATE, true ) ?: 'under_review' );
		?>
		<p><label for="yfr-reference-url"><?php esc_html_e( 'Reference URL (optional)', 'yoohw-feature-requests' ); ?></label><br>
		<input class="widefat" type="url" id="yfr-reference-url" name="yfr_reference_url" maxlength="2048" value="<?php echo esc_attr( get_post_meta( $post->ID, Requests::META_URL, true ) ); ?>"></p>
		<p><?php esc_html_e( 'Publish to approve a submission. Save as Draft to reject it without publishing, or move spam to Trash. Declined is a roadmap decision for a reviewed suggestion.', 'yoohw-feature-requests' ); ?></p>
		<p><?php esc_html_e( 'Publication and roadmap status do not promise implementation, a delivery date, or paid work. In this version, frontend requests remain visible only to their author and moderators.', 'yoohw-feature-requests' ); ?></p>
		<?php
	}

	public static function select( $name, $label, array $options, $current ) {
		?>
		<p><label for="<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label><br>
		<select name="<?php echo esc_attr( $name ); ?>" id="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( $options as $value => $text ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $text ); ?></option>
			<?php endforeach; ?>
		</select></p>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( Requests::TYPE !== $post->post_type || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( Requests::MANAGE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['yfr_details_nonce'] ) || ! is_string( $_POST['yfr_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['yfr_details_nonce'] ) ), 'yfr_details_' . $post_id ) ) {
			if ( isset( $_POST['yfr_details_nonce'] ) ) { Notifications::cancel( $post_id ); }
			return;
		}
		$kind  = sanitize_text_field( isset( $_POST['yfr_kind'] ) ? wp_unslash( $_POST['yfr_kind'] ) : '' );
		$state = sanitize_text_field( isset( $_POST['yfr_state'] ) ? wp_unslash( $_POST['yfr_state'] ) : '' );
		$url   = isset( $_POST['yfr_reference_url'] ) ? wp_unslash( $_POST['yfr_reference_url'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Requests::validate rejects unsafe schemes and sanitizes accepted URLs before persistence.
		$data  = Requests::validate( array( 'title' => 'Request details', 'description' => 'Request details validation.', 'kind' => $kind, 'reference_url' => $url ) );
		if ( is_wp_error( $data ) || ! isset( Requests::states()[ $state ] ) ) {
			Notifications::cancel( $post_id );
			set_transient( 'yfr_admin_error_' . get_current_user_id(), 1, MINUTE_IN_SECONDS );
			return;
		}
		update_post_meta( $post_id, Requests::META_KIND, $kind );
		update_post_meta( $post_id, Requests::META_STATE, $state );
		update_post_meta( $post_id, Requests::META_URL, $data['url'] );
		if ( get_post_meta( $post_id, Requests::META_KIND, true ) !== $kind || get_post_meta( $post_id, Requests::META_STATE, true ) !== $state || get_post_meta( $post_id, Requests::META_URL, true ) !== $data['url'] ) { Notifications::cancel( $post_id ); set_transient( 'yfr_admin_error_' . get_current_user_id(), 1, MINUTE_IN_SECONDS ); }
	}

	public static function notice() {
		if ( current_user_can( Requests::MANAGE ) && get_transient( 'yfr_admin_error_' . get_current_user_id() ) ) {
			delete_transient( 'yfr_admin_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Request details were not updated. Select a valid type and status, and use an http or https reference URL.', 'yoohw-feature-requests' ) . '</p></div>';
		}
	}

	public static function columns( $columns ) {
		$columns['yfr_kind']  = __( 'Type', 'yoohw-feature-requests' );
		$columns['yfr_state'] = __( 'Roadmap', 'yoohw-feature-requests' );
		return $columns;
	}

	public static function column( $column, $id ) {
		if ( 'yfr_kind' === $column ) {
			echo esc_html( Requests::kinds()[ get_post_meta( $id, Requests::META_KIND, true ) ] ?? '' );
		} elseif ( 'yfr_state' === $column ) {
			echo esc_html( Requests::state_label( $id ) );
		}
	}

	public static function filters( $post_type ) {
		if ( Requests::TYPE !== $post_type || ! current_user_can( Requests::MANAGE ) ) {
			return;
		}
		// These GET values only select an allowlisted admin list filter.
		$state = isset( $_GET['yfr_state_filter'] ) && is_string( $_GET['yfr_state_filter'] ) ? sanitize_key( wp_unslash( $_GET['yfr_state_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<label class="screen-reader-text" for="yfr-state-filter">' . esc_html__( 'Filter roadmap status', 'yoohw-feature-requests' ) . '</label><select id="yfr-state-filter" name="yfr_state_filter"><option value="">' . esc_html__( 'All roadmap statuses', 'yoohw-feature-requests' ) . '</option>';
		foreach ( Requests::states() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $state, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		foreach ( array( Requests::CATEGORY, Requests::PRODUCT ) as $taxonomy ) {
			wp_dropdown_categories( array( 'taxonomy' => $taxonomy, 'name' => $taxonomy, 'show_option_all' => get_taxonomy( $taxonomy )->label, 'hide_empty' => false, 'value_field' => 'slug', 'selected' => isset( $_GET[ $taxonomy ] ) && is_string( $_GET[ $taxonomy ] ) ? sanitize_title( wp_unslash( $_GET[ $taxonomy ] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	public static function filter_query( $query ) {
		if ( is_admin() && $query->is_main_query() && Requests::TYPE === $query->get( 'post_type' ) && current_user_can( Requests::MANAGE ) ) {
			$state = isset( $_GET['yfr_state_filter'] ) && is_string( $_GET['yfr_state_filter'] ) ? sanitize_key( wp_unslash( $_GET['yfr_state_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( Requests::states()[ $state ] ) ) {
				$query->set( 'meta_query', array( array( 'key' => Requests::META_STATE, 'value' => $state ) ) );
			}
			$tax_query = array( 'relation' => 'AND' );
			foreach ( array( Requests::CATEGORY, Requests::PRODUCT ) as $taxonomy ) {
				$slug = isset( $_GET[ $taxonomy ] ) && is_string( $_GET[ $taxonomy ] ) ? sanitize_title( wp_unslash( $_GET[ $taxonomy ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin filter.
				if ( '' !== $slug && '0' !== $slug ) {
					$tax_query[] = array( 'taxonomy' => $taxonomy, 'field' => 'slug', 'terms' => array( $slug ) );
				}
			}
			if ( count( $tax_query ) > 1 ) {
				$existing = $query->get( 'tax_query' );
				if ( is_array( $existing ) && $existing ) {
					$tax_query[] = $existing;
				}
				$query->set( 'tax_query', $tax_query );
			}
		}
	}
}
