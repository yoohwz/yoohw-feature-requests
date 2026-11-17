<?php
/**
 * Plugin Name: YoOhw Feature Requests
 * Description: Submit and moderate feature, plugin and integration requests using WordPress accounts.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: YoOhw
 * Author URI: https://yoohw.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: yoohw-feature-requests
 *
 * @package YoOhwFeatureRequests
 */

defined( 'ABSPATH' ) || exit;

define( 'YFR_VERSION', '0.1.0' );
define( 'YFR_FILE', __FILE__ );
define( 'YFR_PATH', plugin_dir_path( __FILE__ ) );

require_once YFR_PATH . 'includes/class-requests.php';
require_once YFR_PATH . 'includes/class-admin.php';
require_once YFR_PATH . 'includes/class-frontend.php';
require_once YFR_PATH . 'includes/class-privacy.php';

register_activation_hook( __FILE__, array( 'YoOhwFeatureRequests\\Requests', 'activate' ) );
add_action( 'init', array( 'YoOhwFeatureRequests\\Requests', 'register' ) );
add_filter( 'map_meta_cap', array( 'YoOhwFeatureRequests\\Requests', 'map_read_capability' ), 10, 4 );
YoOhwFeatureRequests\Admin::boot();
YoOhwFeatureRequests\Frontend::boot();
YoOhwFeatureRequests\Privacy::boot();
