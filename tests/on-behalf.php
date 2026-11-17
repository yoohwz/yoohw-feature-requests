<?php
/** Privileged creation checks in an explicitly isolated, mail-blocked fixture. */
use YoOhwFeatureRequests\Requests as R;
use YoOhwFeatureRequests\Frontend as F;
use YoOhwFeatureRequests\Notifications as N;
$marker_path = dirname( rtrim( ABSPATH, '/\\' ) ) . '/fixture.json';
$marker = is_file( $marker_path ) ? json_decode( file_get_contents( $marker_path ), true ) : null;
if ( ! is_array( $marker ) || realpath( $marker['root'] ) !== realpath( ABSPATH ) || DB_NAME !== $marker['db'] || 0 !== strpos( DB_NAME, 'yfr_wave1_' ) || null === apply_filters( 'pre_wp_mail', null, array() ) ) { throw new RuntimeException( 'Guarded disposable fixture with mail blocked required.' ); }
$checks = 0;
$check = static function ( $ok, $label ) use ( &$checks ) { if ( ! $ok ) { throw new RuntimeException( 'FAIL ' . $label ); } ++$checks; echo 'PASS ' . $label . "\n"; };
$error = static function ( $result, $code ) { return is_wp_error( $result ) && $code === $result->get_error_code(); };
$suffix = strtolower( wp_generate_password( 12, false ) );
$users = array();
foreach ( array( 'admin' => 'administrator', 'owner' => 'subscriber', 'other' => 'subscriber', 'editor' => 'editor' ) as $name => $role ) {
    $users[$name] = wp_insert_user( array( 'user_login' => 'behalf_' . $name . '_' . $suffix, 'user_email' => $name . '-' . $suffix . '@example.test', 'user_pass' => wp_generate_password(), 'role' => $role ) );
    if ( is_wp_error( $users[$name] ) ) { throw new RuntimeException( 'Synthetic identity failed.' ); }
}
extract( $users );
$input = array( 'title' => 'Synthetic managed request', 'description' => 'PRIVATE DESCRIPTION for a useful suggestion.', 'kind' => 'feature', 'post_author' => $other, 'created_by' => $other );
wp_set_current_user( 0 );
$check( $error( R::create_for_user( $input, $owner ), 'forbidden' ), 'Anonymous forbidden' );
foreach ( array( $owner, $editor ) as $actor ) { wp_set_current_user( $actor ); $check( $error( R::create_for_user( $input, $owner ), 'forbidden' ), 'Subscriber/editor forbidden' ); }
wp_set_current_user( $admin ); R::activate();
$user = get_userdata( $editor ); $user->add_cap( R::MANAGE ); wp_set_current_user( $editor );
$check( $error( R::create_for_user( $input, $owner ), 'forbidden' ), 'Manage without CPT create permission forbidden' );
wp_set_current_user( $admin );
foreach ( array( 0, PHP_INT_MAX, (string) $owner, array( $owner ) ) as $bad_owner ) { $check( $error( R::create_for_user( $input, $bad_owner ), 'invalid_owner' ), 'Missing or malformed owner rejected' ); }
foreach ( array( array( 'notify' => null ), array( 'notify' => 'false' ), array( 'creation_key' => 'invalid' ), array( 'creation_key' => array() ) ) as $context ) { $check( $error( R::create_for_user( $input, $owner, $context ), 'invalid_context' ), 'Malformed context rejected' ); }
$check( $error( R::create_for_user( array_merge( $input, array( 'title' => '' ) ), $owner ), 'invalid_content' ), 'Shared validation applied' );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Managed request fixture', 'post_content' => '[yfr_requests]' ) );
$old_config = get_option( N::OPTION );
update_option( N::OPTION, array( 'new' => 1, 'moderation' => 1, 'roadmap' => 1, 'admin_email' => 'moderators@example.test', 'page_id' => $page ) );
$mail = array(); $intercept = static function ( $pre, $args ) use ( &$mail ) { $mail[] = $args; return true; }; add_filter( 'pre_wp_mail', $intercept, PHP_INT_MAX, 2 );
$key = hash( 'sha256', $suffix );
$events = 0; $event = static function () use ( &$events ) { ++$events; }; add_action( 'yoohw_feature_requests_submitted', $event );
$result = R::create_for_user( $input, $owner, array( 'notify' => false, 'creation_key' => $key ) );
$check( is_array( $result ) && $result['created'], 'Manager creates pending request with deferred event' ); $id = $result['id'];
$check( $owner === (int) get_post_field( 'post_author', $id ) && $admin === (int) get_post_meta( $id, R::META_CREATED_BY, true ) && $admin === get_current_user_id() && 'pending' === get_post_status( $id ), 'Server actor and explicit owner ignore forged input without impersonation' );
$check( 0 === $events && ! $mail && get_post_field( 'post_date', $id ), 'Deferred creation persists audit before notifications' );
$replay = R::create_for_user( array_merge( $input, array( 'title' => 'Changed replay title' ) ), $owner, array( 'creation_key' => $key ) );
$check( array( 'id' => $id, 'created' => false ) === $replay && 'Synthetic managed request' === get_post_field( 'post_title', $id ) && ! $events && ! $mail, 'Replay returns prior ID without modifying or notifying' );
$check( $error( R::create_for_user( $input, $other, array( 'creation_key' => $key ) ), 'creation_conflict' ), 'Confirmation cannot change owner' );
$lock = 'yfr_create_' . hash( 'sha256', $admin . '|' . hash( 'sha256', 'busy' . $suffix ) ); add_option( $lock, 'other-writer', '', false );
$check( $error( R::create_for_user( $input, $owner, array( 'creation_key' => hash( 'sha256', 'busy' . $suffix ) ) ), 'creation_busy' ) && 'other-writer' === get_option( $lock ), 'Competing writer excluded without releasing its lock' ); delete_option( $lock );
$nested = null;
$race = static function ( $post_id, $post ) use ( &$nested, $input, $owner, $key ) { if ( R::TYPE === $post->post_type ) { $nested = R::create_for_user( $input, $owner, array( 'creation_key' => hash( 'sha256', $key ) ) ); } };
add_action( 'wp_insert_post', $race, 10, 2 );
$second = R::create_for_user( $input, $owner, array( 'notify' => false, 'creation_key' => hash( 'sha256', $key ) ) ); remove_action( 'wp_insert_post', $race );
$check( is_array( $second ) && $second['id'] !== $id && $error( $nested, 'creation_busy' ), 'Distinct confirmation permits multiple requests while nested concurrency is excluded' );
$fail_meta = static function ( $check, $object_id, $meta_key ) { return R::META_CREATED_BY === $meta_key ? false : $check; }; add_filter( 'add_post_metadata', $fail_meta, 10, 3 );
$failed = R::create_for_user( $input, $owner, array( 'notify' => false ) ); remove_filter( 'add_post_metadata', $fail_meta );
$check( $error( $failed, 'save_failed' ) && get_post( $id ) && ! $events, 'Missing audit metadata rolls back only new request before event' );
do_action( 'yoohw_feature_requests_submitted', $id );
$check( 1 === count( $mail ) && false !== strpos( $mail[0]['message'], 'Created on your behalf by a request moderator.' ) && false === strpos( $mail[0]['message'], 'behalf_admin_' ) && false === strpos( $mail[0]['message'], 'PRIVATE DESCRIPTION' ), 'Managed email includes generic audit without account/source/content leaks' );
$default = R::create_for_user( $input, $owner );
$check( is_array( $default ) && $default['created'] && 2 === count( $mail ), 'Default managed creation emits existing submitted event once' );
wp_update_user( array( 'ID' => $admin, 'display_name' => 'Manager & Team' ) );
wp_set_current_user( $owner );
$_GET['yfr_request'] = (string) $id;
$hooks = 0; $hook = static function () use ( &$hooks ) { ++$hooks; }; add_action( 'yoohw_feature_requests_details', $hook );
$html = F::render();
$check( 1 === $hooks && false !== strpos( $html, 'Manager &amp; Team' ) && false !== strpos( F::creation_note( $id ), 'on your behalf' ), 'Owner sees escaped actor note and authorized details hook' );
wp_set_current_user( $admin );
$check( false !== strpos( F::creation_note( $id ), 'for the request owner' ) && false === strpos( F::creation_note( $id ), 'on your behalf' ), 'Creating moderator sees owner-specific audit wording' );
wp_set_current_user( $editor );
$check( false !== strpos( F::creation_note( $id ), 'for the request owner' ), 'Other authorized moderator sees owner-specific audit wording' );
wp_set_current_user( $other ); $html = F::render();
$check( 1 === $hooks && '' === F::creation_note( $id ) && false === strpos( $html, 'Synthetic managed request' ), 'Other user cannot invoke details hook or inspect audit' );
unset( $_GET['yfr_request'] ); remove_action( 'yoohw_feature_requests_details', $hook );
wp_set_current_user( $admin );
for ( $n = 0; $n < 5; ++$n ) { R::create_for_user( $input, $owner, array( 'notify' => false ) ); }
wp_set_current_user( $owner ); delete_transient( 'yfr_submit_' . $owner );
$self = R::submit( $input );
$check( is_int( $self ) && $owner === (int) get_post_field( 'post_author', $self ) && '' === F::creation_note( $self ), 'Managed backlog excluded and self submission ignores forged author' );
for ( $n = 0; $n < 4; ++$n ) { wp_insert_post( array( 'post_type' => R::TYPE, 'post_status' => 'pending', 'post_author' => $owner, 'post_title' => 'Legacy pending' ) ); }
delete_transient( 'yfr_submit_' . $owner );
$check( $error( R::submit( $input ), 'pending_limit' ), 'Legacy and self-created pending still enforce five-request limit' );
wp_set_current_user( $admin );
$check( is_array( R::create_for_user( $input, $owner, array( 'notify' => false ) ) ), 'Privileged creation bypasses customer backlog' );
remove_filter( 'pre_wp_mail', $intercept, PHP_INT_MAX ); remove_action( 'yoohw_feature_requests_submitted', $event );
foreach ( $users as $user_id ) { foreach ( get_posts( array( 'post_type' => R::TYPE, 'post_status' => R::STATUSES, 'author' => $user_id, 'posts_per_page' => -1 ) ) as $post ) { wp_delete_post( $post->ID, true ); } delete_transient( 'yfr_submit_' . $user_id ); }
wp_delete_post( $page, true ); update_option( N::OPTION, $old_config );
require_once ABSPATH . 'wp-admin/includes/user.php'; wp_set_current_user( 0 ); foreach ( $users as $user_id ) { wp_delete_user( $user_id ); }
echo "PASS $checks privileged creation assertions\n";
