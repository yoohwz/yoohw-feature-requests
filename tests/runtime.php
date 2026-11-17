<?php
/**
 * Integration checks for an explicitly isolated synthetic WordPress fixture.
 * Run with: wp --path=/path/to/fixture/wordpress eval-file tests/runtime.php
 *
 * @package YoOhwFeatureRequests
 */

use YoOhwFeatureRequests\Admin;
use YoOhwFeatureRequests\Frontend;
use YoOhwFeatureRequests\Privacy;
use YoOhwFeatureRequests\Requests;

if ( ! defined( 'ABSPATH' ) || ! defined( 'DB_NAME' ) || 0 !== strpos( DB_NAME, 'yfr_wave1_' ) ) {
	throw new RuntimeException( 'Refusing to run outside a dedicated yfr_wave1_ database.' );
}
$marker_path = dirname( rtrim( ABSPATH, '/\\' ) ) . '/fixture.json';
$marker      = is_file( $marker_path ) ? json_decode( file_get_contents( $marker_path ), true ) : null;
if ( ! is_array( $marker ) || empty( $marker['root'] ) || empty( $marker['db'] ) || realpath( $marker['root'] ) !== realpath( ABSPATH ) || DB_NAME !== $marker['db'] ) {
	throw new RuntimeException( 'Refusing to run without a matching fixture.json root and database marker.' );
}
if ( ! class_exists( Requests::class ) ) {
	throw new RuntimeException( 'Activate YoOhw Feature Requests in the isolated fixture first.' );
}

$checks   = 0;
$failures = array();
$assert   = function ( $condition, $message ) use ( &$checks, &$failures ) {
	++$checks;
	if ( ! $condition ) {
		$failures[] = $message;
	}
};
$error_is = function ( $value, $code ) {
	return is_wp_error( $value ) && $code === $value->get_error_code();
};
$suffix   = strtolower( wp_generate_password( 10, false, false ) );
$user_ids = array();
$new_user = function ( $label, $role = 'subscriber' ) use ( $suffix, &$user_ids ) {
	$id = wp_insert_user( array( 'user_login' => 'yfr_' . $label . '_' . $suffix, 'user_pass' => wp_generate_password( 30, true ), 'user_email' => $label . '-' . $suffix . '@example.test', 'role' => $role ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( 'Synthetic user creation failed: ' . $id->get_error_message() );
	}
	$user_ids[] = $id;
	return $id;
};
$admin   = $new_user( 'admin', 'administrator' );
$owner   = $new_user( 'owner' );
$other   = $new_user( 'other' );
$editor  = $new_user( 'editor', 'editor' );
$privacy = $new_user( 'privacy' );
$input   = array( 'title' => 'YFR owner request ' . $suffix, 'description' => 'A useful synthetic feature suggestion for integration tests.', 'kind' => 'feature', 'reference_url' => 'https://example.test/reference', 'category' => '', 'product' => '' );

// Bootstrap grants only administrators the isolated request-management rights.
wp_set_current_user( $admin );
Requests::activate();
Requests::activate();
$type = get_post_type_object( Requests::TYPE );
$assert( $type && ! $type->public && ! $type->publicly_queryable && ! $type->show_in_rest && $type->exclude_from_search && ! $type->has_archive && ! $type->rewrite && ! $type->query_var, 'The request CPT has no native public, search, REST, archive or rewrite surface.' );
$assert( current_user_can( Requests::MANAGE ) && current_user_can( 'edit_yfr_requests' ) && current_user_can( 'publish_yfr_requests' ), 'Administrators can moderate requests after repeated activation.' );
foreach ( array( $owner, $editor ) as $id ) {
	wp_set_current_user( $id );
	$assert( ! current_user_can( Requests::MANAGE ) && ! current_user_can( 'edit_yfr_requests' ) && ! current_user_can( 'publish_yfr_requests' ), 'Subscriber/editor does not inherit request management: ' . $id );
	$assert( ! current_user_can( get_taxonomy( Requests::CATEGORY )->cap->manage_terms ), 'Subscriber/editor cannot manage request taxonomy terms: ' . $id );
}
wp_set_current_user( 0 );
$assert( $error_is( Requests::submit( $input ), 'login_required' ), 'Anonymous submission is refused.' );

wp_set_current_user( $owner );
$forged = array_merge( $input, array( 'post_author' => $other, 'author' => $other, 'post_status' => 'publish', 'status' => 'publish', 'roadmap_status' => 'released', 'state' => 'released' ) );
$request = Requests::submit( $forged );
if ( is_wp_error( $request ) || ! $request ) {
	throw new RuntimeException( 'Baseline submission failed: ' . ( is_wp_error( $request ) ? $request->get_error_message() : 'no ID' ) );
}
$post = get_post( $request );
$assert( (int) $post->post_author === $owner && 'pending' === $post->post_status && 'under_review' === get_post_meta( $request, Requests::META_STATE, true ), 'Submission ignores forged owner, publication and roadmap authority.' );
$assert( 'closed' === $post->comment_status && 'closed' === $post->ping_status, 'Request creation disables comments and pingbacks.' );
$assert( $error_is( Requests::submit( $input ), 'rate_limit' ), 'A second submission by the same account is throttled.' );
$assert( Requests::can_view( $request ) && current_user_can( 'read_post', $request ), 'The author can read their pending request.' );
$assert( ! current_user_can( 'edit_post', $request ) && ! current_user_can( 'delete_post', $request ), 'Submission does not grant frontend authors moderation or delete rights.' );

wp_set_current_user( $other );
$other_request = Requests::submit( array_merge( $input, array( 'title' => 'PRIVATE_OTHER_' . $suffix ) ) );
$assert( ! is_wp_error( $other_request ), 'Rate limiting is scoped to the submitting account.' );
$assert( ! Requests::can_view( $request ) && ! current_user_can( 'read_post', $request ), 'Another customer cannot read a pending request by ID.' );
wp_set_current_user( $editor );
$assert( ! Requests::can_view( $request ) && ! current_user_can( 'read_post', $request ), 'An ordinary editor cannot read another author request.' );
wp_set_current_user( 0 );
$assert( ! Requests::can_view( $request ) && ! current_user_can( 'read_post', $request ), 'Anonymous readers cannot access pending request objects.' );
wp_set_current_user( $admin );
$assert( Requests::can_view( $request ) && current_user_can( 'read_post', $request ) && current_user_can( 'edit_post', $request ), 'Request moderators can inspect and edit submissions.' );
wp_update_post( array( 'ID' => $request, 'post_status' => 'publish' ) );
foreach ( array( 0, $other, $editor ) as $id ) {
	wp_set_current_user( $id );
	$assert( ! Requests::can_view( $request ) && ! current_user_can( 'read_post', $request ), 'Publication does not expose a Wave 1 request to non-owner: ' . $id );
}
wp_set_current_user( $owner );
$assert( Requests::can_view( $request ) && current_user_can( 'read_post', $request ), 'The author can still read a published submission.' );

// Validate both stored data and negative inputs, including UTF-8 lengths.
$dirty = Requests::validate( array_merge( $input, array( 'title' => '<b>Clean title</b>', 'description' => '<p onclick="alert(1)">Useful text <script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">reference</a></p>' ) ) );
$assert( ! is_wp_error( $dirty ) && 'Clean title' === $dirty['title'] && false === strpos( $dirty['content'], '<script' ) && false === strpos( $dirty['content'], '<img' ) && false === strpos( $dirty['content'], 'onclick' ) && false === strpos( $dirty['content'], 'javascript:' ), 'Allowed description HTML strips executable tags, event handlers, images and unsafe links.' );
$assert( ! is_wp_error( Requests::validate( array_merge( $input, array( 'title' => str_repeat( 'ệ', 180 ) ) ) ) ), 'The 180-character title limit counts Unicode characters rather than UTF-8 bytes.' );
$assert( $error_is( Requests::validate( array_merge( $input, array( 'title' => str_repeat( 'ệ', 181 ) ) ) ), 'invalid_content' ), 'A 181-character Unicode title is refused.' );
foreach ( array( 'javascript:alert(1)', 'data:text/html,test', '//example.test/path', '/relative', 'https://user:pass@example.test', 'https://example.test/' . str_repeat( 'x', 2048 ) ) as $url ) {
	$assert( $error_is( Requests::validate( array_merge( $input, array( 'reference_url' => $url ) ) ), 'invalid_url' ), 'Unsafe or oversized reference URL is refused: ' . substr( $url, 0, 60 ) );
}
$assert( $error_is( Requests::validate( array_merge( $input, array( 'kind' => array( 'feature' ) ) ) ), 'invalid_kind' ), 'Non-scalar request kinds are refused.' );
$assert( $error_is( Requests::validate( array_merge( $input, array( 'description' => '<p>short</p>' ) ) ), 'invalid_content' ), 'Description length checks visible text.' );
$assert( $error_is( Requests::validate( array_merge( $input, array( 'description' => str_repeat( 'x', 10001 ) ) ) ), 'invalid_content' ), 'Oversized descriptions are refused.' );
$assert( $error_is( Requests::validate( array_merge( $input, array( 'category' => array( 1 ) ) ) ), 'invalid_term' ), 'Non-scalar term input is refused.' );
$assert( $error_is( Requests::validate( array_merge( $input, array( 'product' => '-1' ) ) ), 'invalid_term' ), 'Negative term input is refused.' );
$assert( $error_is( Requests::validate( array_merge( $input, array( 'category' => '999999999' ) ) ), 'invalid_term' ), 'Nonexistent categories are refused without creating terms.' );
$term = wp_insert_term( 'Shared synthetic category ' . $suffix, Requests::CATEGORY );
$product = wp_insert_term( 'Shared synthetic product ' . $suffix, Requests::PRODUCT );
if ( is_wp_error( $term ) || is_wp_error( $product ) ) {
	throw new RuntimeException( 'Synthetic taxonomy creation failed.' );
}
$assert( $error_is( Requests::validate( array_merge( $input, array( 'product' => (string) $term['term_id'] ) ) ), 'invalid_term' ), 'A category ID cannot be forged as a related product.' );
$valid_terms = Requests::validate( array_merge( $input, array( 'category' => (string) $term['term_id'], 'product' => (string) $product['term_id'] ) ) );
$assert( ! is_wp_error( $valid_terms ) && (int) $term['term_id'] === $valid_terms['terms'][ Requests::CATEGORY ] && (int) $product['term_id'] === $valid_terms['terms'][ Requests::PRODUCT ], 'Existing term IDs from the correct taxonomy validate.' );

// Backlog checks are independent from the one-minute submit flag.
wp_set_current_user( $owner );
for ( $i = 0; $i < 5; ++$i ) {
	delete_transient( 'yfr_submit_' . $owner );
	$id = Requests::submit( array_merge( $input, array( 'title' => 'Pending backlog ' . $suffix . ' ' . $i ) ) );
	$assert( ! is_wp_error( $id ), 'A request below the pending backlog cap is accepted: ' . $i );
}
delete_transient( 'yfr_submit_' . $owner );
$assert( $error_is( Requests::submit( $input ), 'pending_limit' ), 'Five pending submissions block a sixth even without the throttle flag.' );

// Shortcode list, details and alternate WordPress reads must stay private.
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => $admin, 'post_title' => 'YFR test page ' . $suffix, 'post_content' => '[yfr_requests]' ) );
$GLOBALS['post'] = get_post( $page );
setup_postdata( $GLOBALS['post'] );
$_GET = array();
$html = Frontend::render();
$assert( false !== strpos( $html, esc_html( $input['title'] ) ) && false === strpos( $html, 'PRIVATE_OTHER_' . $suffix ), 'The personal list includes the current owner and excludes another author before output.' );
$_GET = array( 'yfr_request' => (string) $other_request );
$html = Frontend::render();
$assert( false !== strpos( $html, 'Request unavailable.' ) && false === strpos( $html, 'PRIVATE_OTHER_' . $suffix ), 'A forged detail ID returns an unavailable response without disclosing title/content.' );
$_GET = array( 'yfr_request' => (string) $request );
$html = Frontend::render();
$assert( false !== strpos( $html, esc_html( $input['title'] ) ) && false === strpos( $html, get_userdata( $owner )->user_email ) && false === strpos( $html, get_userdata( $owner )->user_login ), 'Authorized details do not disclose email or login identifiers.' );
wp_set_current_user( 0 );
$html = Frontend::render();
$assert( false !== strpos( $html, 'Log in' ) && false === strpos( $html, esc_html( $input['title'] ) ), 'Anonymous shortcode output contains login guidance rather than private request content.' );
$_GET = array();
$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/yfr_request/' . $request ) );
$assert( 404 === $rest->get_status(), 'No core REST request route exposes a stored request.' );
$rest_search = new WP_REST_Request( 'GET', '/wp/v2/search' );
$rest_search->set_param( 'search', $suffix );
$rest_search->set_param( 'per_page', 100 );
$response = rest_do_request( $rest_search );
$assert( false === strpos( wp_json_encode( $response->get_data() ), $input['title'] ), 'Core REST search does not expose private YFR titles.' );
$search = new WP_Query( array( 's' => $suffix, 'post_status' => 'publish', 'posts_per_page' => 100 ) );
$assert( ! in_array( $request, wp_list_pluck( $search->posts, 'ID' ), true ), 'Native WordPress search excludes the published private CPT.' );

// Metadata saves must reject unauthorized, nonce-less and invalid updates atomically.
wp_set_current_user( $admin );
$old_meta = array( get_post_meta( $request, Requests::META_KIND, true ), get_post_meta( $request, Requests::META_STATE, true ), get_post_meta( $request, Requests::META_URL, true ) );
$meta_values = function ( $id ) {
	return array( get_post_meta( $id, Requests::META_KIND, true ), get_post_meta( $id, Requests::META_STATE, true ), get_post_meta( $id, Requests::META_URL, true ) );
};
$details = array( 'yfr_kind' => 'integration', 'yfr_state' => 'planned', 'yfr_reference_url' => 'https://example.test/updated' );
$_POST = $details;
Admin::save( $request, get_post( $request ) );
$assert( $old_meta === $meta_values( $request ), 'A missing admin details nonce prevents every metadata update.' );
$_POST['yfr_details_nonce'] = 'invalid';
Admin::save( $request, get_post( $request ) );
$assert( $old_meta === $meta_values( $request ), 'An invalid admin details nonce prevents every metadata update.' );
$_POST['yfr_details_nonce'] = wp_create_nonce( 'yfr_details_' . $request );
$_POST['yfr_state'] = 'invented';
Admin::save( $request, get_post( $request ) );
$assert( $old_meta === $meta_values( $request ), 'An invalid roadmap state leaves all request metadata unchanged.' );
$_POST['yfr_state'] = 'planned';
$_POST['yfr_reference_url'] = 'javascript:alert(1)';
Admin::save( $request, get_post( $request ) );
$assert( $old_meta === $meta_values( $request ), 'An invalid reference URL leaves all request metadata unchanged.' );
$_POST = array_merge( $details, array( 'yfr_details_nonce' => wp_create_nonce( 'yfr_details_' . $request ) ) );
Admin::save( $request, get_post( $request ) );
$assert( array( 'integration', 'planned', 'https://example.test/updated' ) === $meta_values( $request ), 'A valid moderator save persists allowlisted details.' );
$updated_meta = $meta_values( $request );
wp_set_current_user( $owner );
$_POST = array_merge( $details, array( 'yfr_state' => 'released', 'yfr_details_nonce' => wp_create_nonce( 'yfr_details_' . $request ) ) );
Admin::save( $request, get_post( $request ) );
$assert( $updated_meta === $meta_values( $request ), 'An author with a valid nonce cannot set roadmap status.' );
wp_set_current_user( $admin );
$_POST = array_merge( $details, array( 'yfr_details_nonce' => wp_create_nonce( 'yfr_details_' . $page ) ) );
Admin::save( $page, get_post( $page ) );
$assert( '' === get_post_meta( $page, Requests::META_KIND, true ) && '' === get_post_meta( $page, Requests::META_STATE, true ), 'Saving an unrelated post type does not create YFR metadata.' );
$_POST = array();
$revision = wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $request, 'post_author' => $owner, 'post_name' => $request . '-autosave-v1', 'post_title' => 'Synthetic autosave ' . $suffix, 'post_content' => 'Synthetic autosave content.' ) );
$_POST = array_merge( $details, array( 'yfr_state' => 'released', 'yfr_details_nonce' => wp_create_nonce( 'yfr_details_' . $request ) ) );
Admin::save( $request, get_post( $revision ) );
$assert( $updated_meta === $meta_values( $request ), 'Revision/autosave metadata cannot alter the parent request.' );
$_POST = array();

// Privacy export and destructive erasure use >2 batches without skipping records.
$privacy_ids = array();
$statuses = array( 'pending', 'publish', 'draft', 'trash', 'private', 'future', 'auto-draft' );
for ( $i = 0; $i < 45; ++$i ) {
	$status = $statuses[ $i % count( $statuses ) ];
	$id = wp_insert_post( array( 'post_type' => Requests::TYPE, 'post_status' => $status, 'post_date' => 'future' === $status ? '2099-01-01 12:00:00' : current_time( 'mysql' ), 'post_date_gmt' => 'future' === $status ? '2099-01-01 12:00:00' : current_time( 'mysql', true ), 'post_author' => $privacy, 'post_title' => 'Privacy request ' . $suffix . ' ' . $i, 'post_content' => 'Synthetic privacy export and erasure content.', 'meta_input' => array( Requests::META_KIND => 'feature', Requests::META_STATE => 'under_review', Requests::META_URL => 'https://example.test/privacy' ) ) );
	$privacy_ids[] = $id;
	wp_set_object_terms( $id, array( (int) $term['term_id'] ), Requests::CATEGORY );
	wp_set_object_terms( $id, array( (int) $product['term_id'] ), Requests::PRODUCT );
}
$assert( 'future' === get_post_status( $privacy_ids[5] ) && 'auto-draft' === get_post_status( $privacy_ids[6] ), 'Privacy fixtures include actual scheduled and native auto-draft requests.' );
$assert( $error_is( Requests::validate( array_merge( $input, array( 'reference_url' => array( 'invalid' ) ) ) ), 'invalid_url' ), 'Non-scalar optional reference URLs are rejected rather than treated as empty.' );
wp_set_object_terms( $other_request, array( (int) $term['term_id'] ), Requests::CATEGORY );
$privacy_revision = wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $privacy_ids[0], 'post_author' => $privacy, 'post_title' => 'Private old revision ' . $suffix, 'post_content' => 'Old sensitive synthetic content.' ) );
$privacy_email = get_userdata( $privacy )->user_email;
$export_ids = array();
$pages = array();
for ( $i = 1; $i <= 3; ++$i ) {
	$batch = Privacy::export( $privacy_email, $i );
	$pages[] = count( $batch['data'] );
	foreach ( $batch['data'] as $item ) {
		$export_ids[] = (int) substr( $item['item_id'], 4 );
	}
	$assert( ( 3 === $i ) === $batch['done'], 'Privacy exporter reports completion accurately for batch ' . $i );
}
$assert( array( 20, 20, 5 ) === $pages && count( array_unique( $export_ids ) ) === 45 && ! array_diff( $privacy_ids, $export_ids ) && ! in_array( $other_request, $export_ids, true ), 'Privacy exporter returns all 45 authored requests exactly once across three pages and excludes other authors.' );
$missing_export = Privacy::export( 'missing-' . $suffix . '@example.test', 1 );
$assert( array() === $missing_export['data'] && $missing_export['done'], 'Export for an unknown email is empty and complete.' );
set_transient( 'yfr_submit_' . $privacy, 1, MINUTE_IN_SECONDS );
set_transient( 'yfr_form_error_' . $privacy, 'invalid_content', MINUTE_IN_SECONDS );
set_transient( 'yfr_admin_error_' . $privacy, 1, MINUTE_IN_SECONDS );
$erasure_done = false;
for ( $i = 1; $i <= 4; ++$i ) {
	$batch = Privacy::erase( $privacy_email, $i );
	$assert( $batch['items_removed'] && ! $batch['items_retained'], 'Privacy erasure removes the intended remaining batch: ' . $i );
	if ( $batch['done'] ) {
		$erasure_done = true;
		break;
	}
}
$remaining = array_filter( $privacy_ids, 'get_post' );
$assert( $erasure_done && ! $remaining && ! get_post( $privacy_revision ), 'Privacy erasure deletes every authored request and its revision without offset skipping.' );
$assert( get_post( $request ) && get_post( $other_request ) && get_post( $page ) && get_userdata( $privacy ), 'Privacy erasure preserves other authors, ordinary pages and the WordPress account.' );
$assert( term_exists( (int) $term['term_id'], Requests::CATEGORY ) && term_exists( (int) $product['term_id'], Requests::PRODUCT ) && has_term( (int) $term['term_id'], Requests::CATEGORY, $other_request ), 'Privacy erasure retains shared category/product terms and another request relationships.' );
$assert( ! get_post_meta( $privacy_ids[0], Requests::META_URL, true ) && ! get_transient( 'yfr_submit_' . $privacy ) && ! get_transient( 'yfr_form_error_' . $privacy ) && ! get_transient( 'yfr_admin_error_' . $privacy ), 'Erasure clears request metadata and user-linked transient flags.' );
$empty_erase = Privacy::erase( $privacy_email, 4 );
$assert( $empty_erase['done'] && ! $empty_erase['items_removed'] && ! $empty_erase['items_retained'], 'Repeated erasure is complete and harmless.' );

// A vetoed child revision must leave its parent discoverable for privacy retry.
$retained_request = wp_insert_post( array( 'post_type' => Requests::TYPE, 'post_status' => 'pending', 'post_author' => $privacy, 'post_title' => 'Retained privacy parent ' . $suffix, 'post_content' => 'Synthetic data whose old revision cannot yet be erased.' ) );
$retained_revision = wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $retained_request, 'post_author' => $privacy, 'post_name' => $retained_request . '-autosave-v1', 'post_title' => 'Retained synthetic revision', 'post_content' => 'Old private synthetic autosave content.' ) );
$removed_revision = wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $retained_request, 'post_author' => $admin, 'post_title' => 'Moderator synthetic revision', 'post_content' => 'A moderator revision of the same private request.' ) );
$veto_revision = function ( $delete, $post ) use ( $retained_revision ) {
	return (int) $post->ID === (int) $retained_revision ? false : $delete;
};
$disable_revisions = function () { return 0; };
add_filter( 'pre_delete_post', $veto_revision, 10, 2 );
add_filter( 'wp_revisions_to_keep', $disable_revisions );
$blocked_erase = Privacy::erase( $privacy_email );
$assert( $blocked_erase['items_removed'] && $blocked_erase['items_retained'] && $blocked_erase['messages'] && $blocked_erase['done'], 'Partial revision erasure reports both removed and retained data, with a reason and a bounded completion.' );
$assert( get_post( $retained_request ) && get_post( $retained_revision ), 'A vetoed autosave retains its parent for retry even when new revisions are disabled.' );
$assert( ! get_post( $removed_revision ), 'A deletable moderator revision is removed even if another child is retained.' );
remove_filter( 'pre_delete_post', $veto_revision, 10 );
$retry_erase = Privacy::erase( $privacy_email );
remove_filter( 'wp_revisions_to_keep', $disable_revisions );
$assert( $retry_erase['items_removed'] && ! $retry_erase['items_retained'] && ! get_post( $retained_request ) && ! get_post( $retained_revision ), 'Retry deletes the retained request and autosave after the veto is removed.' );
$assert( get_post( $other_request ) && term_exists( (int) $term['term_id'], Requests::CATEGORY ), 'Revision failure and retry preserve unrelated requests and shared terms.' );

// Uninstall is evaluated in place; do not call wp plugin delete on a source symlink.
wp_set_current_user( $admin );
$before_uninstall = $meta_values( $request );
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', 'yoohw-feature-requests/yoohw-feature-requests.php' );
}
include YFR_PATH . 'uninstall.php';
Requests::activate();
$assert( get_post( $request ) && $before_uninstall === $meta_values( $request ) && term_exists( (int) $term['term_id'], Requests::CATEGORY ), 'Uninstall and reactivation retain request records, metadata and terms.' );
$assert( ! class_exists( 'WooCommerce' ) && ! class_exists( 'YoOhw_Support_Router' ) && ! class_exists( 'YoOhwWorkspace\\Router' ), 'The integration fixture runs without WooCommerce, Support Portal or Workspace.' );

$_POST = array();
$_GET = array();
wp_reset_postdata();
wp_set_current_user( 0 );
if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
	}
	throw new RuntimeException( count( $failures ) . ' of ' . $checks . ' YFR runtime assertions failed.' );
}
echo 'PASS ' . $checks . ' YFR runtime assertions on WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . ".\n";
