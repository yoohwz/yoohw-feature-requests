<?php
/**
 * Authenticated submission and personal request views.
 *
 * @package YoOhwFeatureRequests
 */

namespace YoOhwFeatureRequests;

defined( 'ABSPATH' ) || exit;

final class Frontend {
	public static function boot() {
		add_shortcode( 'yfr_requests', array( __CLASS__, 'render' ) );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ), 0 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_yfr_submit', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_yfr_submit', array( __CLASS__, 'handle_submit' ) );
	}

	public static function is_page() {
		$post = get_queried_object();
		return $post instanceof \WP_Post && has_shortcode( $post->post_content, 'yfr_requests' );
	}

	public static function no_cache() {
		if ( self::is_page() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared WordPress cache-plugin convention.
			}
			nocache_headers();
		}
	}

	public static function assets() {
		if ( self::is_page() ) {
			wp_enqueue_style( 'yfr-requests', plugins_url( 'assets/requests.css', YFR_FILE ), array(), YFR_VERSION );
		}
	}

	public static function handle_submit() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method ) {
			wp_die( esc_html__( 'Use the request form to submit a request.', 'yoohw-feature-requests' ), '', array( 'response' => 405 ) );
		}
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Log in to submit a request.', 'yoohw-feature-requests' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'yfr_submit', 'yfr_nonce' );
		$page_id = isset( $_POST['yfr_page'] ) && is_scalar( $_POST['yfr_page'] ) ? absint( $_POST['yfr_page'] ) : 0;
		$page    = get_post( $page_id );
		if ( ! $page || 'publish' !== $page->post_status || ! has_shortcode( $page->post_content, 'yfr_requests' ) ) {
			wp_die( esc_html__( 'This request form is unavailable. Please return to the request page.', 'yoohw-feature-requests' ), '', array( 'response' => 400 ) );
		}
		$input = array();
		foreach ( array( 'title', 'description', 'kind', 'reference_url', 'category', 'product' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && ! is_string( $_POST[ $key ] ) ) {
				wp_die( esc_html__( 'Invalid form data.', 'yoohw-feature-requests' ), '', array( 'response' => 400 ) );
			}
			$input[ $key ] = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Requests::validate validates and sanitizes this fixed field allowlist before persistence.
		}
		$result = Requests::submit( $input );
		$url    = get_permalink( $page );
		if ( is_wp_error( $result ) ) {
			set_transient( 'yfr_form_error_' . get_current_user_id(), $result->get_error_code(), 5 * MINUTE_IN_SECONDS );
			$url = add_query_arg( 'yfr_notice', 'error', $url );
		} else {
			$url = add_query_arg( array( 'yfr_notice' => 'submitted', 'yfr_request' => $result ), $url );
		}
		wp_safe_redirect( $url . '#yfr-requests', 303 );
		exit;
	}

	public static function notices() {
		$notice = isset( $_GET['yfr_notice'] ) && is_string( $_GET['yfr_notice'] ) ? sanitize_key( wp_unslash( $_GET['yfr_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice selector.
		if ( 'submitted' === $notice ) {
			$id = self::detail_id();
			if ( $id && Requests::can_view( $id ) ) {
				echo '<p class="yfr-notice" role="status">' . esc_html__( 'Your request was received and is awaiting moderation.', 'yoohw-feature-requests' ) . '</p>';
			}
		} elseif ( 'error' === $notice ) {
			$key  = 'yfr_form_error_' . get_current_user_id();
			$code = get_transient( $key );
			delete_transient( $key );
			$messages = array(
				'invalid_content' => __( 'Check the title and description lengths, then submit again.', 'yoohw-feature-requests' ),
				'invalid_kind' => __( 'Select a valid request type.', 'yoohw-feature-requests' ),
				'invalid_term' => __( 'Select an existing category or product.', 'yoohw-feature-requests' ),
				'invalid_url' => __( 'Use a complete http or https reference URL without credentials.', 'yoohw-feature-requests' ),
				'rate_limit' => __( 'Please wait one minute before submitting another request.', 'yoohw-feature-requests' ),
				'pending_limit' => __( 'You already have five requests awaiting moderation. Please wait for a review.', 'yoohw-feature-requests' ),
			);
			if ( $code ) {
				echo '<p class="yfr-notice yfr-error" role="alert">' . esc_html( $messages[ $code ] ?? __( 'The request could not be saved. Please try again.', 'yoohw-feature-requests' ) ) . '</p>';
			}
		}
	}

	public static function detail_id() {
		return isset( $_GET['yfr_request'] ) && is_string( $_GET['yfr_request'] ) ? absint( $_GET['yfr_request'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only object selector; authorization is checked separately.
	}

	public static function render() {
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		ob_start();
		?>
		<div class="yfr" id="yfr-requests">
		<?php if ( ! is_user_logged_in() ) : ?>
			<h2><?php esc_html_e( 'Feature Requests', 'yoohw-feature-requests' ); ?></h2>
			<p><?php esc_html_e( 'Log in to suggest a feature, plugin or integration and follow your submissions.', 'yoohw-feature-requests' ); ?></p>
			<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log in', 'yoohw-feature-requests' ); ?></a>
		<?php else : ?>
			<?php self::notices(); ?>
			<?php if ( self::detail_id() ) : ?>
				<?php $request = get_post( self::detail_id() ); ?>
				<?php if ( Requests::can_view( $request ) ) : ?>
					<article class="yfr-detail">
						<h2><?php echo esc_html( $request->post_title ); ?></h2>
						<?php self::meta( $request ); ?>
						<div class="yfr-content"><?php echo wp_kses( wpautop( $request->post_content ), Requests::allowed_html() ); ?></div>
						<?php $reference = get_post_meta( $request->ID, Requests::META_URL, true ); ?>
						<?php if ( $reference ) : ?>
							<p><a href="<?php echo esc_url( $reference, array( 'http', 'https' ) ); ?>" rel="nofollow ugc"><?php esc_html_e( 'Reference link', 'yoohw-feature-requests' ); ?></a></p>
						<?php endif; ?>
					</article>
				<?php else : ?>
					<p class="yfr-notice" role="status"><?php esc_html_e( 'Request unavailable.', 'yoohw-feature-requests' ); ?></p>
				<?php endif; ?>
				<p><a href="<?php echo esc_url( get_permalink() ); ?>#yfr-requests"><?php esc_html_e( 'Back to my requests', 'yoohw-feature-requests' ); ?></a></p>
			<?php else : ?>
				<?php self::personal_list(); ?>
				<?php include YFR_PATH . 'templates/form.php'; ?>
			<?php endif; ?>
		<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function meta( $request ) {
		?>
		<div class="yfr-meta">
			<span><?php echo esc_html( Requests::kinds()[ get_post_meta( $request->ID, Requests::META_KIND, true ) ] ?? '' ); ?></span>
			<span><?php echo esc_html( Requests::moderation_label( $request ) ); ?></span>
			<?php if ( 'publish' === $request->post_status ) : ?><span><?php echo esc_html( Requests::state_label( $request->ID ) ); ?></span><?php endif; ?>
			<?php foreach ( array( Requests::CATEGORY, Requests::PRODUCT ) as $taxonomy ) : ?>
				<?php $terms = wp_get_object_terms( $request->ID, $taxonomy, array( 'fields' => 'names' ) ); ?>
				<?php if ( ! is_wp_error( $terms ) && $terms ) : ?><span><?php echo esc_html( implode( ', ', $terms ) ); ?></span><?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public static function personal_list() {
		$page  = isset( $_GET['yfr_page'] ) && is_string( $_GET['yfr_page'] ) ? max( 1, min( 10000, absint( $_GET['yfr_page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination.
		$query = new \WP_Query( array( 'post_type' => Requests::TYPE, 'post_status' => Requests::STATUSES, 'author' => get_current_user_id(), 'posts_per_page' => 10, 'paged' => $page, 'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ) ) );
		?>
		<section aria-label="<?php esc_attr_e( 'My requests', 'yoohw-feature-requests' ); ?>">
			<h2><?php esc_html_e( 'My Requests', 'yoohw-feature-requests' ); ?></h2>
			<p class="yfr-note"><?php esc_html_e( 'Your submissions are visible here only to you and request moderators. Approved suggestions may appear on a community board in a future version.', 'yoohw-feature-requests' ); ?></p>
			<?php if ( ! $query->posts ) : ?>
				<p><?php esc_html_e( 'No requests on this page.', 'yoohw-feature-requests' ); ?></p>
			<?php else : ?>
				<ul class="yfr-list">
				<?php foreach ( $query->posts as $request ) : ?>
					<li><h3><a href="<?php echo esc_url( add_query_arg( 'yfr_request', $request->ID, get_permalink() ) ); ?>#yfr-requests"><?php echo esc_html( $request->post_title ); ?></a></h3><?php self::meta( $request ); ?></li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<?php if ( $query->max_num_pages > 1 ) : ?>
				<nav class="yfr-pagination" aria-label="<?php esc_attr_e( 'Request pages', 'yoohw-feature-requests' ); ?>">
				<?php echo wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'yfr_page', '%#%', get_permalink() ) . '#yfr-requests', 'format' => '', 'current' => $page, 'total' => $query->max_num_pages, 'prev_text' => __( 'Previous', 'yoohw-feature-requests' ), 'next_text' => __( 'Next', 'yoohw-feature-requests' ) ) ) ); ?>
				</nav>
			<?php endif; ?>
		</section>
		<?php
	}
}
