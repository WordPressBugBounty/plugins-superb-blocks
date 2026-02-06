<?php

namespace SuperbAddons;

/*
Plugin Name: Superb Addons: Blocks, Patterns & Theme Designer for the Block Editor & FSE
Plugin URI: https://superbthemes.com/
Description: Enhance your website building experience with our user-friendly tools and features. Create stunning designs effortlessly using our blocks, patterns, and theme designer for the Block Editor & FSE.
Version: 3.7.1
Author: SuperbThemes
Author URI: https://superbthemes.com/
License: GPL-3.0+
License URI: https://www.gnu.org/licenses/gpl-3.0.txt
Text Domain: superb-blocks
* Elementor tested up to: 3.34
* Elementor Pro tested up to: 3.34
*/

defined('ABSPATH') || exit;

if (!defined('WPINC')) {
    die;
}
// Constants
if (!defined('SUPERBADDONS_VERSION')) {
    define('SUPERBADDONS_VERSION', '3.7.1');
}

if (!defined('SUPERBADDONS_LIBRARY_VERSION')) {
    define('SUPERBADDONS_LIBRARY_VERSION', 101);
}

if (!defined('SUPERBADDONS_BASE')) {
    define('SUPERBADDONS_BASE', plugin_basename(__FILE__));
}

if (!defined('SUPERBADDONS_BASE_PATH')) {
    define('SUPERBADDONS_BASE_PATH', __FILE__);
}

if (!defined('SUPERBADDONS_PATH')) {
    define('SUPERBADDONS_PATH', untrailingslashit(plugins_url('', SUPERBADDONS_BASE_PATH)));
}

if (!defined('SUPERBADDONS_PLUGIN_DIR')) {
    define('SUPERBADDONS_PLUGIN_DIR', plugin_dir_path(SUPERBADDONS_BASE_PATH));
}

if (!defined('SUPERBADDONS_ASSETS_PATH')) {
    define('SUPERBADDONS_ASSETS_PATH', SUPERBADDONS_PATH . '/assets');
}
//

// Autoload
require_once SUPERBADDONS_PLUGIN_DIR . 'vendor/autoload.php';

use SuperbAddons\SuperbAddonsPlugin;

SuperbAddonsPlugin::GetInstance();

//
