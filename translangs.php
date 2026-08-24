<?php
/**
 * Plugin Name:       TransLangs
 * Plugin URI:        https://github.com/aminesayagh/translangs
 * Description:       Create multilingual WordPress sites with local manual translation and optional AI-assisted translation.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.1
 * Author:            Amine Sayagh
 * Author URI:        https://github.com/aminesayagh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       translangs
 * Domain Path:       /languages
 *
 * @package TransLangs
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TRANSLANGS_VERSION', '1.0.0');
define('TRANSLANGS_FILE', __FILE__);
define('TRANSLANGS_DIR', plugin_dir_path(__FILE__));
define('TRANSLANGS_URL', plugin_dir_url(__FILE__));
define('TRANSLANGS_BASENAME', plugin_basename(__FILE__));

/*
    * Autoloader.
    *
    * Prefer Composer's autoloader if it exists. Fall back to a small PSR-4
    * loader so the plugin still works if "composer install" was not run.
    */
if (file_exists(TRANSLANGS_DIR . 'vendor/autoload.php')) {
    require_once TRANSLANGS_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(function (string $class): void {
        $prefix  = 'TransLangs\\';
        $baseDir = TRANSLANGS_DIR . 'src/';

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

// Load the bundled queue library before plugins_loaded, so Action Scheduler can register its version.
\TransLangs\Shared\ActionSchedulerLoader::loadBundled(TRANSLANGS_DIR);

/*
    * Lifecycle hooks. These run only on activation/deactivation, never per request.
    */
register_activation_hook(__FILE__, [\TransLangs\Setup\Activator::class, 'run']);
register_deactivation_hook(__FILE__, [\TransLangs\Setup\Deactivator::class, 'run']);

/*
    * Boot the plugin after all plugins are loaded.
    */
add_action('plugins_loaded', function (): void {
    (new \TransLangs\Setup\Plugin())->boot();
});
