<?php
/**
 * Plugin Name:       LingoWP
 * Plugin URI:        https://www.lingowp.com/
 * Description:       Create multilingual WordPress sites with local manual translation and optional AI-assisted translation.
 * Version:           1.3.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Mohamed Amine Sayagh
 * Author URI:        https://github.com/aminesayagh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lingowp
 *
 * @package LingoWP
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LINGOWP_VERSION', '1.3.1');
define('LINGOWP_FILE', __FILE__);
define('LINGOWP_DIR', plugin_dir_path(__FILE__));
define('LINGOWP_URL', plugin_dir_url(__FILE__));
define('LINGOWP_BASENAME', plugin_basename(__FILE__));

if (file_exists(LINGOWP_DIR . 'vendor/autoload.php')) {
    require_once LINGOWP_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        $prefix  = 'LingoWP\\';
        $baseDir = LINGOWP_DIR . 'src/';

        if (strpos($class, $prefix) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

require_once LINGOWP_DIR . 'functions.php';

\LingoWP\Shared\ActionSchedulerLoader::loadBundled(LINGOWP_DIR);

register_activation_hook(__FILE__, [\LingoWP\Setup\Activator::class, 'run']);
register_deactivation_hook(__FILE__, [\LingoWP\Setup\Deactivator::class, 'run']);

add_action('plugins_loaded', function (): void {
    (new \LingoWP\Setup\Plugin())->boot();
});
