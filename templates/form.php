<?php
/**
 * Authenticated request form.
 *
 * @package YoOhwFeatureRequests
 */

use YoOhwFeatureRequests\Requests;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Included inside Frontend::render(); these variables have function scope, not global scope.
?>
<section aria-labelledby="<?php echo esc_attr( $yoohw_feature_requests_id . '-submit-heading' ); ?>">
	<h2 id="<?php echo esc_attr( $yoohw_feature_requests_id . '-submit-heading' ); ?>"><?php esc_html_e( 'Suggest an improvement', 'yoohw-feature-requests' ); ?></h2>
	<p id="<?php echo esc_attr( $yoohw_feature_requests_id . '-submission-policy' ); ?>"><?php esc_html_e( 'Share an idea for a feature, plugin or integration. Submissions are reviewed before publication. A suggestion does not create a project, payment obligation, delivery date or commitment to implement. For paid custom development, use the site’s separate contact or project workflow.', 'yoohw-feature-requests' ); ?></p>
	<p class="yfr-note"><?php esc_html_e( 'Do not include passwords, license keys, customer details or other confidential information. Approved suggestions may be shared publicly in a future community board.', 'yoohw-feature-requests' ); ?></p>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" aria-describedby="<?php echo esc_attr( $yoohw_feature_requests_id . '-submission-policy' ); ?>">
		<input type="hidden" name="action" value="yfr_submit">
		<input type="hidden" name="yfr_page" value="<?php echo esc_attr( get_queried_object_id() ); ?>">
		<input type="hidden" id="<?php echo esc_attr( $yoohw_feature_requests_id . '-nonce' ); ?>" name="yfr_nonce" value="<?php echo esc_attr( wp_create_nonce( 'yfr_submit' ) ); ?>">
		<?php wp_referer_field(); ?>
		<div><label for="<?php echo esc_attr( $yoohw_feature_requests_id . '-kind' ); ?>"><?php esc_html_e( 'Request type', 'yoohw-feature-requests' ); ?></label>
			<select id="<?php echo esc_attr( $yoohw_feature_requests_id . '-kind' ); ?>" name="kind" required><?php foreach ( Requests::kinds() as $yoohw_feature_requests_value => $yoohw_feature_requests_label ) : ?><option value="<?php echo esc_attr( $yoohw_feature_requests_value ); ?>"><?php echo esc_html( $yoohw_feature_requests_label ); ?></option><?php endforeach; ?></select>
		</div>
		<div><label for="<?php echo esc_attr( $yoohw_feature_requests_id . '-title' ); ?>"><?php esc_html_e( 'Title', 'yoohw-feature-requests' ); ?></label><input id="<?php echo esc_attr( $yoohw_feature_requests_id . '-title' ); ?>" name="title" type="text" required minlength="3" maxlength="180"></div>
		<div><label for="<?php echo esc_attr( $yoohw_feature_requests_id . '-description' ); ?>"><?php esc_html_e( 'Description', 'yoohw-feature-requests' ); ?></label><textarea id="<?php echo esc_attr( $yoohw_feature_requests_id . '-description' ); ?>" name="description" required minlength="10" maxlength="10000" aria-describedby="<?php echo esc_attr( $yoohw_feature_requests_id . '-description-help' ); ?>"></textarea><small id="<?php echo esc_attr( $yoohw_feature_requests_id . '-description-help' ); ?>"><?php esc_html_e( 'Describe the problem, who needs it and the outcome you want. Use 10–10,000 characters.', 'yoohw-feature-requests' ); ?></small></div>
		<?php foreach ( array( 'category' => Requests::CATEGORY, 'product' => Requests::PRODUCT ) as $yoohw_feature_requests_key => $yoohw_feature_requests_taxonomy ) : ?>
			<?php $yoohw_feature_requests_terms = get_terms( array( 'taxonomy' => $yoohw_feature_requests_taxonomy, 'hide_empty' => false ) ); ?>
			<?php if ( ! is_wp_error( $yoohw_feature_requests_terms ) && $yoohw_feature_requests_terms ) : ?>
				<div><label for="<?php echo esc_attr( $yoohw_feature_requests_id . '-' . $yoohw_feature_requests_key ); ?>"><?php echo esc_html( 'category' === $yoohw_feature_requests_key ? __( 'Category (optional)', 'yoohw-feature-requests' ) : __( 'Related product (optional)', 'yoohw-feature-requests' ) ); ?></label>
					<select id="<?php echo esc_attr( $yoohw_feature_requests_id . '-' . $yoohw_feature_requests_key ); ?>" name="<?php echo esc_attr( $yoohw_feature_requests_key ); ?>"><option value=""><?php esc_html_e( 'None / not sure', 'yoohw-feature-requests' ); ?></option><?php foreach ( $yoohw_feature_requests_terms as $yoohw_feature_requests_term ) : ?><option value="<?php echo esc_attr( $yoohw_feature_requests_term->term_id ); ?>"><?php echo esc_html( $yoohw_feature_requests_term->name ); ?></option><?php endforeach; ?></select>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>
		<div><label for="<?php echo esc_attr( $yoohw_feature_requests_id . '-reference' ); ?>"><?php esc_html_e( 'Reference URL (optional)', 'yoohw-feature-requests' ); ?></label><input id="<?php echo esc_attr( $yoohw_feature_requests_id . '-reference' ); ?>" name="reference_url" type="url" maxlength="2048" placeholder="https://example.com/"></div>
		<button type="submit"><?php esc_html_e( 'Submit for review', 'yoohw-feature-requests' ); ?></button>
	</form>
</section>
