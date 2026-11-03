<?php
/**
 * Plugin Name:       Linvara – Multilingual & AI Translation
 * Plugin URI:        https://www.lingowp.com/
 * Description:       Create multilingual WordPress sites with local manual translation and optional AI-assisted translation.
 * Version:           1.6.5
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Mohamed Amine Sayagh
 * Author URI:        https://github.com/aminesayagh
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linvara
 *
 * @package LingoWP
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LINGOWP_VERSION', '1.6.5');
define('LINGOWP_FILE', __FILE__);
define('LINGOWP_DIR', plugin_dir_path(__FILE__));
define('LINGOWP_URL', plugin_dir_url(__FILE__));
define('LINGOWP_BASENAME', plugin_basename(__FILE__));

/*
    * Autoloader.
    *
    * Prefer Composer's autoloader if it exists. Fall back to a small PSR-4
    * loader so the plugin still works if "composer install" was not run.
    */
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

// Public template functions (lingowp_get_language_links(), lingowp_switcher(), …).
require_once LINGOWP_DIR . 'functions.php';

// Load the bundled queue library before plugins_loaded, so Action Scheduler can register its version.
\LingoWP\Shared\ActionSchedulerLoader::loadBundled(LINGOWP_DIR);

/*
    * Lifecycle hooks. These run only on activation/deactivation, never per request.
    */
register_activation_hook(__FILE__, [\LingoWP\Setup\Activator::class, 'run']);
register_deactivation_hook(__FILE__, [\LingoWP\Setup\Deactivator::class, 'run']);

// Multisite: set up a site created while network-active (after core's own
// setup at 10), and drop Linvara's tables before core's when a site is deleted.
add_action('wp_initialize_site', [\LingoWP\Setup\Activator::class, 'initializeSite'], 200);
add_filter('wpmu_drop_tables', [\LingoWP\Database\TranslationMemorySchema::class, 'addTablesForSiteDeletion']);

/*
    * Boot the plugin after all plugins are loaded.
    */
add_action('plugins_loaded', function (): void {
    (new \LingoWP\Setup\Plugin())->boot();
});
