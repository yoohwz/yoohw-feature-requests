<?php
/** Synthetic, intercepted-mail notification acceptance. @package YoOhwFeatureRequests */
use YoOhwFeatureRequests\Requests;
use YoOhwFeatureRequests\Notifications as N;

$marker_path = dirname( rtrim( ABSPATH, '/\\' ) ) . '/fixture.json';
$marker = is_file( $marker_path ) ? json_decode( file_get_contents( $marker_path ), true ) : null;
if ( ! is_array( $marker ) || realpath( $marker['root'] ) !== realpath( ABSPATH ) || DB_NAME !== $marker['db'] || 0 !== strpos( DB_NAME, 'yfr_wave1_' ) || null === apply_filters( 'pre_wp_mail', null, array() ) ) { throw new RuntimeException( 'Guarded disposable fixture with mail blocked required.' ); }
require_once ABSPATH . 'wp-admin/includes/template.php';
$checks = 0;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $label ); } ++$checks; echo 'PASS ' . $label . "\n"; };
$suffix = wp_generate_password( 10, false );
$admin = wp_insert_user( array( 'user_login' => 'mail_admin_' . $suffix, 'user_email' => 'admin-' . strtolower( $suffix ) . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
$owner = wp_insert_user( array( 'user_login' => 'mail_owner_' . $suffix, 'user_email' => 'owner-' . strtolower( $suffix ) . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$other = wp_insert_user( array( 'user_login' => 'mail_other_' . $suffix, 'user_email' => 'other-' . strtolower( $suffix ) . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
foreach ( array( $admin, $owner, $other ) as $user ) { if ( is_wp_error( $user ) ) { throw new RuntimeException( 'Cannot create synthetic identity.' ); } }
wp_set_current_user( $admin ); Requests::activate();
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Mail fixture', 'post_content' => '[yfr_requests]' ) );
$config = array( 'new' => 1, 'moderation' => 1, 'roadmap' => 1, 'admin_email' => 'moderators@example.test', 'page_id' => $page );
update_option( N::OPTION, $config );
$mail = array(); $accept = true;
$intercept = static function ( $pre, $args ) use ( &$mail, &$accept ) { $mail[] = $args; return $accept; };
add_filter( 'pre_wp_mail', $intercept, PHP_INT_MAX, 2 );
$seed = static function ( $status = 'pending', $state = 'under_review' ) use ( $owner ) { return wp_insert_post( array( 'post_type' => Requests::TYPE, 'post_status' => $status, 'post_author' => $owner, 'post_title' => 'Synthetic mail title', 'post_content' => 'PRIVATE BODY MUST NOT BE EMAILED', 'meta_input' => array( Requests::META_STATE => $state, Requests::META_KIND => 'feature', Requests::META_URL => '' ) ) ); };
$review = static function ( $id, $status, $state ) { do_action( 'yoohw_feature_requests_before_update', $id ); wp_update_post( array( 'ID' => $id, 'post_status' => $status ) ); update_post_meta( $id, Requests::META_STATE, $state ); do_action( 'yoohw_feature_requests_updated', $id ); };
$job = static function ( $id, $type = 'update' ) { return get_post_meta( $id, '_yfr_notice_' . $type, true ); };

wp_set_current_user( $owner );
$id = Requests::submit( array( 'title' => 'Synthetic submitted idea', 'description' => 'PRIVATE BODY MUST NOT BE EMAILED', 'kind' => 'feature' ) );
$check( is_int( $id ) && 1 === count( $mail ) && 'moderators@example.test' === $mail[0]['to'], 'Completed frontend submission emails only configured moderator' );
$check( false === strpos( $mail[0]['message'], 'PRIVATE BODY' ) && false === strpos( $mail[0]['message'], 'owner-' ), 'New request email excludes description and account identity' );
$check( false !== strpos( $mail[0]['subject'], '#' . $id ) && false !== strpos( $mail[0]['subject'], 'needs review' ) && false !== strpos( $mail[0]['message'], 'Review request:' ), 'Administrator copy identifies the request and gives a review action' );
do_action( 'yoohw_feature_requests_submitted', $id );
$check( 1 === count( $mail ), 'Repeated submission-complete event does not resend' );
$bad = Requests::submit( array( 'title' => '', 'description' => 'Invalid', 'kind' => 'feature' ) );
$check( is_wp_error( $bad ) && 1 === count( $mail ), 'Invalid submission produces no message' );

// Make taxonomy persistence fail after a valid input was accepted.
$term = wp_insert_term( 'Mail term ' . $suffix, Requests::CATEGORY );
$remove_taxonomy = static function ( $data ) { if ( Requests::TYPE === ( $data['post_type'] ?? '' ) ) { unregister_taxonomy( Requests::CATEGORY ); } return $data; };
delete_transient( 'yfr_submit_' . $owner ); add_filter( 'wp_insert_post_data', $remove_taxonomy );
$bad = Requests::submit( array( 'title' => 'Synthetic failed terms', 'description' => 'Private failed submission.', 'kind' => 'feature', 'category' => (string) $term['term_id'] ) );
remove_filter( 'wp_insert_post_data', $remove_taxonomy ); Requests::register();
$check( is_wp_error( $bad ) && 1 === count( $mail ), 'Failed term persistence rolls back without email' );

wp_set_current_user( $admin ); $mail = array();
$review( $id, 'publish', 'planned' );
$check( 1 === count( $mail ) && get_userdata( $owner )->user_email === $mail[0]['to'] && false !== strpos( $mail[0]['message'], 'Published' ) && false !== strpos( $mail[0]['message'], 'Planned' ), 'Combined moderation and roadmap save creates one final-state author email' );
$check( false !== strpos( $mail[0]['message'], 'yfr_request=' . $id ) && false !== strpos( $mail[0]['message'], 'does not promise' ) && false === strpos( $mail[0]['message'], 'PRIVATE BODY' ), 'Author email has private-page link and no delivery commitment or request body' );
$check( 'accepted' === $job( $id )['result'] && ! wp_next_scheduled( N::RETRY, array( $id, 'update', $job( $id )['token'] ) ), 'Accepted message has no pending retry' );
$review( $id, 'publish', 'planned' ); wp_update_post( array( 'ID' => $id, 'post_title' => 'Title edit only' ) );
$check( 1 === count( $mail ), 'Unchanged review and title-only edit send nothing' );
$review( $id, 'publish', 'in_progress' );
$check( 2 === count( $mail ) && false !== strpos( $mail[1]['message'], 'In Progress' ), 'Published roadmap change sends author update' );
$review( $id, 'publish', 'under_review' );
$check( 2 === count( $mail ), 'Return to Under Review is not a meaningful-roadmap email' );
$review( $id, 'draft', 'declined' );
$check( 3 === count( $mail ) && false !== strpos( $mail[2]['message'], 'Not approved' ) && false === strpos( $mail[2]['message'], 'Roadmap:' ), 'Not-approved decision sends one moderation email' );
$review( $id, 'draft', 'released' ); wp_trash_post( $id ); wp_untrash_post( $id );
$check( 3 === count( $mail ), 'Unpublished roadmap and Trash/restore are silent' );

// Native bulk/quick publication without a metabox nonce remains supported.
$native = $seed(); $mail = array(); $_POST = array(); wp_update_post( array( 'ID' => $native, 'post_status' => 'publish' ) );
$check( 1 === count( $mail ), 'Native publication without metabox form sends moderation notification' );
$_POST = array( 'yfr_details_nonce' => wp_create_nonce( 'yfr_details_' . $native ), 'yfr_kind' => 'feature', 'yfr_state' => 'released', 'yfr_reference_url' => '' );
wp_update_post( array( 'ID' => $native ) );
$check( 2 === count( $mail ) && false !== strpos( $mail[1]['message'], 'Released' ), 'Native metabox roadmap save completes after metadata' );
$_POST['yfr_state'] = 'not-a-state'; wp_update_post( array( 'ID' => $native, 'post_status' => 'draft' ) );
$check( 2 === count( $mail ), 'Invalid metabox validation suppresses partially changed status email' );
$_POST['yfr_state'] = 'planned'; $_POST['yfr_details_nonce'] = 'bad'; wp_update_post( array( 'ID' => $native, 'post_status' => 'publish' ) );
$check( 2 === count( $mail ), 'Invalid metabox nonce never produces a success message' );
$_POST['yfr_details_nonce'] = wp_create_nonce( 'yfr_details_' . $native );
$fail_meta = static function ( $value, $post_id, $key ) use ( $native ) { return $native === $post_id && Requests::META_STATE === $key ? false : $value; };
add_filter( 'update_post_metadata', $fail_meta, 10, 3 ); wp_update_post( array( 'ID' => $native, 'post_status' => 'draft' ) ); remove_filter( 'update_post_metadata', $fail_meta ); $_POST = array();
$check( 2 === count( $mail ), 'Native metadata readback failure does not notify success' );
$partial = $seed(); do_action( 'yoohw_feature_requests_before_update', $partial ); wp_update_post( array( 'ID' => $partial, 'post_status' => 'publish' ) );
$check( 2 === count( $mail ), 'Uncompleted multi-step adapter operation suppresses native inner save' );
N::cancel( $partial ); do_action( 'yoohw_feature_requests_updated', $partial );
$check( 2 === count( $mail ), 'Cancelled operation does not flush at completion' );

$mail = array(); $retry = $seed(); $accept = false; $review( $retry, 'publish', 'planned' ); $pending = $job( $retry );
$check( 1 === count( $mail ) && 1 === $pending['attempts'] && 'pending' === $pending['result'] && (bool) wp_next_scheduled( N::RETRY, array( $retry, 'update', $pending['token'] ) ), 'Failed transport preserves successful request and schedules bounded retry' );
N::deliver( $retry, 'update', $pending['token'] ); N::deliver( $retry, 'update', $pending['token'] ); N::deliver( $retry, 'update', $pending['token'] );
$check( 3 === count( $mail ) && 3 === $job( $retry )['attempts'] && 'failed' === $job( $retry )['result'], 'Transport failure stops after three attempts' );
$retry = $seed(); $review( $retry, 'publish', 'planned' ); $old = $job( $retry ); $review( $retry, 'publish', 'released' ); $new = $job( $retry ); $count = count( $mail ); N::deliver( $retry, 'update', $old['token'] );
$check( $old['token'] !== $new['token'] && count( $mail ) === $count && ! wp_next_scheduled( N::RETRY, array( $retry, 'update', $old['token'] ) ), 'New committed update replaces failed old event and stale callback is silent' );
$accept = true; N::deliver( $retry, 'update', $new['token'] ); $count = count( $mail ); N::deliver( $retry, 'update', $new['token'] );
$check( 'accepted' === $job( $retry )['result'] && count( $mail ) === $count, 'Retry success is recorded and duplicate callback is silent' );

$accept = false; $changed = $seed(); $review( $changed, 'publish', 'planned' ); $pending = $job( $changed ); update_option( N::OPTION, array_merge( $config, array( 'roadmap' => 0 ) ) ); update_option( N::OPTION, $config ); $accept = true; $count = count( $mail ); N::deliver( $changed, 'update', $pending['token'] );
$check( count( $mail ) === $count && 'cancelled' === $job( $changed )['result'], 'Disable/re-enable settings cannot replay old queued messages' );
$accept = false; $changed = $seed(); $review( $changed, 'publish', 'planned' ); $pending = $job( $changed ); wp_update_post( array( 'ID' => $changed, 'post_author' => $other ) ); $accept = true; $count = count( $mail ); N::deliver( $changed, 'update', $pending['token'] );
$check( count( $mail ) === $count && 'cancelled' === $job( $changed )['result'], 'Ownership change cancels queued author message' );
$accept = false; $changed = $seed(); $review( $changed, 'publish', 'planned' ); $pending = $job( $changed ); update_post_meta( $changed, Requests::META_STATE, 'declined' ); $accept = true; $count = count( $mail ); N::deliver( $changed, 'update', $pending['token'] );
$check( count( $mail ) === $count, 'State changed without a completed event invalidates old retry' );

update_option( N::OPTION, array_merge( $config, array( 'new' => 0, 'moderation' => 0, 'roadmap' => 0 ) ) ); $mail = array(); $silent = $seed(); do_action( 'yoohw_feature_requests_submitted', $silent ); $review( $silent, 'publish', 'planned' );
$check( ! $mail, 'All event switches are enforced' ); update_option( N::OPTION, $config ); $review( $silent, 'publish', 'planned' );
$check( ! $mail, 'Re-enabling does not backfill unchanged existing requests' );
$unknown = $seed(); wp_update_post( array( 'ID' => $unknown, 'post_author' => 2147483647 ) ); $review( $unknown, 'publish', 'planned' );
$check( ! $mail, 'Missing author is skipped' );
$address = get_userdata( $owner )->user_email; global $wpdb;
$wpdb->update( $wpdb->users, array( 'user_email' => "owner@example.test\r\nBcc: other@example.test" ), array( 'ID' => $owner ) ); clean_user_cache( $owner ); $unknown = $seed(); $review( $unknown, 'publish', 'planned' );
$check( ! $mail, 'Malformed author email cannot inject recipients or headers' );
$wpdb->update( $wpdb->users, array( 'user_email' => $address ), array( 'ID' => $owner ) ); clean_user_cache( $owner );
$clean = N::sanitize( array_merge( $config, array( 'admin_email' => "ok@example.test\r\nBcc: attacker@example.test" ) ) );
$check( $clean === N::config(), 'Settings reject header-injection administrator address' );
$clean = N::sanitize( array_merge( $config, array( 'new' => array( 1 ), 'page_id' => array( $page ) ) ) );
$check( 0 === $clean['new'] && 0 === $clean['page_id'], 'Settings reject array checkbox and page values safely' );
$check( '' !== N::request_url( $id ), 'Published shortcode Page yields author detail URL' );
$bad_url = static function () { return 'https://outside.example.test/?yfr_request=1'; }; add_filter( 'yoohw_feature_requests_notification_url', $bad_url );
$check( '' === N::request_url( $id ), 'External notification-link override is rejected' ); remove_filter( 'yoohw_feature_requests_notification_url', $bad_url );
wp_update_post( array( 'ID' => $page, 'post_status' => 'draft' ) );
$check( '' === N::request_url( $id ), 'Unpublished Page does not become an email link' );
wp_set_current_user( $other ); $check( ! Requests::can_view( $id ), 'Email links do not grant unrelated user access' );
wp_set_current_user( $owner ); $check( Requests::can_view( $id ) && ! current_user_can( 'manage_options' ), 'Author remains ordinary user with object access only' );

wp_set_current_user( $admin ); $accept = false; $locked = $seed(); $review( $locked, 'publish', 'planned' ); $pending = $job( $locked ); $count = count( $mail );
$lock_name = 'yfr_mail_lock_' . $locked . '_update';
$wpdb->insert( $wpdb->options, array( 'option_name' => $lock_name, 'option_value' => ( time() + 300 ) . ':another-owner', 'autoload' => 'no' ) );
$accept = true; N::deliver( $locked, 'update', $pending['token'] );
$check( count( $mail ) === $count, 'Concurrent delivery lock prevents a second transport attempt' );
$wpdb->update( $wpdb->options, array( 'option_value' => '1:expired-owner' ), array( 'option_name' => $lock_name ) ); N::deliver( $locked, 'update', $pending['token'] );
$check( count( $mail ) === $count + 1 && 'accepted' === $job( $locked )['result'], 'Expired delivery lock recovers and releases ownership' );
$check( ! $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $lock_name ) ), 'Delivery does not leave a live lock behind' );
$mail = array(); wp_set_current_user( $owner ); delete_transient( 'yfr_submit_' . $owner );
$fail_insert_meta = static function ( $value, $post_id, $key ) { return Requests::META_KIND === $key ? false : $value; };
add_filter( 'add_post_metadata', $fail_insert_meta, 10, 3 );
$bad = Requests::submit( array( 'title' => 'Synthetic failed metadata', 'description' => 'A valid suggestion with failed metadata.', 'kind' => 'feature' ) );
remove_filter( 'add_post_metadata', $fail_insert_meta );
$check( is_wp_error( $bad ) && ! $mail, 'Failed initial metadata readback produces no submission email' );

wp_set_current_user( $admin ); delete_option( N::OPTION ); $accept = false; $fresh = $seed(); do_action( 'yoohw_feature_requests_submitted', $fresh ); $pending = $job( $fresh, 'new' ); $count = count( $mail );
update_option( N::OPTION, $config ); $accept = true; N::deliver( $fresh, 'new', $pending['token'] );
$check( count( $mail ) === $count && 'cancelled' === $job( $fresh, 'new' )['result'], 'First settings save invalidates default-configuration retry' );
foreach ( array( 'claim', 'result' ) as $phase ) {
 $accept = false; $race = $seed(); $review( $race, 'publish', 'planned' ); $pending = $job( $race ); $count = count( $mail ); $replacement = $pending; $replacement['token'] = wp_generate_uuid4(); $replacement['attempts'] = 0;
 $inject = null;
 $inject = static function ( $pre, $post_id, $key, $value ) use ( $race, $phase, $replacement, &$inject ) {
  if ( $race === $post_id && '_yfr_notice_update' === $key && ( 'claim' === $phase || 'accepted' === ( $value['result'] ?? '' ) ) ) {
   remove_filter( 'update_post_metadata', $inject, 10 ); update_post_meta( $post_id, $key, $replacement );
  }
  return $pre;
 };
 add_filter( 'update_post_metadata', $inject, 10, 4 ); $accept = true; N::deliver( $race, 'update', $pending['token'] ); remove_filter( 'update_post_metadata', $inject, 10 );
 $check( $job( $race )['token'] === $replacement['token'], 'Old worker cannot overwrite replacement event during ' . $phase );
 if ( 'claim' === $phase ) { $check( count( $mail ) === $count, 'Failed compare-and-swap attempt claim never calls transport' ); }
 N::deliver( $race, 'update', $replacement['token'] );
 $check( 'accepted' === $job( $race )['result'], 'Replacement event can still deliver after ' . $phase . ' race' );
}

wp_set_current_user( $admin ); $mail = array(); $accept = true; $copy = $seed();
$review( $copy, 'publish', 'planned' );
$check( false !== strpos( $mail[0]['subject'], '#' . $copy ) && false !== strpos( $mail[0]['subject'], 'approved for consideration' ) && false !== strpos( $mail[0]['message'], 'does not promise implementation' ), 'Approval copy identifies the request without promising delivery' );
$review( $copy, 'publish', 'in_progress' );
$check( false !== strpos( $mail[1]['subject'], 'In Progress' ) && false !== strpos( $mail[1]['message'], 'timing may change' ), 'In-progress copy describes the milestone and uncertain timing' );
$review( $copy, 'publish', 'released' );
$check( false !== strpos( $mail[2]['subject'], 'Released' ) && false !== strpos( $mail[2]['message'], 'release notes' ) && false === strpos( $mail[2]['message'], 'does not promise implementation' ), 'Released copy directs to product release notes without the approval disclaimer' );
$review( $copy, 'publish', 'declined' );
$check( false !== strpos( $mail[3]['subject'], 'Declined' ) && false !== strpos( $mail[3]['message'], 'not planned for implementation at this time' ), 'Declined copy explains the roadmap decision without inventing a reason' );
$review( $copy, 'draft', 'declined' );
$check( false !== strpos( $mail[4]['subject'], 'was not approved' ) && false === strpos( $mail[4]['message'], 'Roadmap:' ), 'Not-approved copy is distinct from a published roadmap decline' );
remove_filter( 'pre_wp_mail', $intercept, PHP_INT_MAX );
echo 'Notification assertions passed: ' . $checks . "\n";
