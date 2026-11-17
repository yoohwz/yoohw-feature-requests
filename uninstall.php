<?php
/**
 * Retain all request data on uninstall. Use WordPress privacy tools or the
 * request moderation screen for intentional deletion before uninstalling.
 *
 * @package YoOhwFeatureRequests
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Capabilities are retained so reinstallation and delegated moderation keep
// their existing configuration. No requests, users, terms or metadata are removed.
