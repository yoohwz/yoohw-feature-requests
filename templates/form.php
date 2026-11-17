<?php
/**
 * Authenticated request form.
 *
 * @package YoOhwFeatureRequests
 */

use YoOhwFeatureRequests\Requests;

defined( 'ABSPATH' ) || exit;
?>
<section aria-labelledby="yfr-submit-heading">
	<h2 id="yfr-submit-heading"><?php esc_html_e( 'Suggest an improvement', 'yoohw-feature-requests' ); ?></h2>
	<p id="yfr-submission-policy"><?php esc_html_e( 'Share an idea for a feature, plugin or integration. Submissions are reviewed before publication. A suggestion does not create a project, payment obligation, delivery date or commitment to implement. For paid custom development, use the site’s separate contact or project workflow.', 'yoohw-feature-requests' ); ?></p>
	<p class="yfr-note"><?php esc_html_e( 'Do not include passwords, license keys, customer details or other confidential information. Approved suggestions may be shared publicly in a future community board.', 'yoohw-feature-requests' ); ?></p>
	<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" aria-describedby="yfr-submission-policy">
		<input type="hidden" name="action" value="yfr_submit">
		<input type="hidden" name="yfr_page" value="<?php echo esc_attr( get_queried_object_id() ); ?>">
		<?php wp_nonce_field( 'yfr_submit', 'yfr_nonce' ); ?>
		<div><label for="yfr-kind"><?php esc_html_e( 'Request type', 'yoohw-feature-requests' ); ?></label>
			<select id="yfr-kind" name="kind" required><?php foreach ( Requests::kinds() as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select>
		</div>
		<div><label for="yfr-title"><?php esc_html_e( 'Title', 'yoohw-feature-requests' ); ?></label><input id="yfr-title" name="title" type="text" required minlength="3" maxlength="180"></div>
		<div><label for="yfr-description"><?php esc_html_e( 'Description', 'yoohw-feature-requests' ); ?></label><textarea id="yfr-description" name="description" required minlength="10" maxlength="10000" aria-describedby="yfr-description-help"></textarea><small id="yfr-description-help"><?php esc_html_e( 'Describe the problem, who needs it and the outcome you want. Use 10–10,000 characters.', 'yoohw-feature-requests' ); ?></small></div>
		<?php foreach ( array( 'category' => Requests::CATEGORY, 'product' => Requests::PRODUCT ) as $key => $taxonomy ) : ?>
			<?php $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) ); ?>
			<?php if ( ! is_wp_error( $terms ) && $terms ) : ?>
				<div><label for="yfr-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( 'category' === $key ? __( 'Category (optional)', 'yoohw-feature-requests' ) : __( 'Related product (optional)', 'yoohw-feature-requests' ) ); ?></label>
					<select id="yfr-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"><option value=""><?php esc_html_e( 'None / not sure', 'yoohw-feature-requests' ); ?></option><?php foreach ( $terms as $term ) : ?><option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option><?php endforeach; ?></select>
				</div>
			<?php endif; ?>
		<?php endforeach; ?>
		<div><label for="yfr-reference"><?php esc_html_e( 'Reference URL (optional)', 'yoohw-feature-requests' ); ?></label><input id="yfr-reference" name="reference_url" type="url" maxlength="2048" placeholder="https://example.com/"></div>
		<button type="submit"><?php esc_html_e( 'Submit for review', 'yoohw-feature-requests' ); ?></button>
	</form>
</section>
