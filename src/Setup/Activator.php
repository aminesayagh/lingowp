<?php

namespace LingoWP\Setup;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\LocalizationRouting\LanguageRouteRegistrar;
use LingoWP\Extraction\Html\HtmlDiscoveryCrawl;
use LingoWP\Extraction\Structured\InitialDiscoveryScan;
use LingoWP\Database\TranslationMemorySchema;

/**
 * Runs once on plugin activation.
 *
 * Responsibilities:
 *  - create database tables
 *  - set default options
 *  - flush rewrite rules a single time (after rules are registered)
 *
 * Multisite: "Network Activate" sets up the current site only — looping over
 * every site in that one request could time out on a large network and leave
 * it half set up (WordPress.org review). Every other site sets itself up on
 * its own first request (setUpOnFirstLoad()), and a site created later is set
 * up by initializeSite(). Local setup only — no connection, no licence check:
 * each site still connects to the backend through its own onboarding.
 */
class Activator
{
    public static function run(bool $networkWide = false): void
    {
        $missing = [];
        if (!function_exists('mb_strtolower')) {
            $missing[] = 'mbstring';
        }
        if (!class_exists(\DOMDocument::class)) {
            $missing[] = 'DOM';
        }
        if ($missing !== []) {
            wp_die(
                sprintf(
                    /* translators: %s is a comma-separated list of required PHP extensions. */
                    esc_html__('Linvara requires these PHP extensions: %s.', 'linvara'),
                    esc_html(implode(', ', $missing))
                ),
                esc_html__('Linvara activation failed', 'linvara'),
                ['back_link' => true]
            );
        }

        // Network-wide or not, only this site now: see the class doc.
        unset($networkWide);
        self::activateSite();
    }

    /**
     * A site of a network where Linvara is network-active, reached for the
     * first time since "Network Activate": give it the full single-site setup
     * (tables, defaults, first-scan flag, rewrite rules) once. Called on every
     * boot, so the common path is a single read of an autoloaded option.
     */
    public static function setUpOnFirstLoad(): void
    {
        if (! is_multisite() || get_option(TranslationMemorySchema::OPTION_DB_VERSION) !== false) {
            return;
        }
        if (! function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (is_plugin_active_for_network(LINGOWP_BASENAME)) {
            self::activateSite();
        }
    }

    /**
     * A site created while Linvara is network-active gets the same setup as
     * one that existed at "Network Activate". Hooked on wp_initialize_site
     * after core (10) has created the site's own tables.
     */
    public static function initializeSite(\WP_Site $site): void
    {
        if (! function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (! is_plugin_active_for_network(LINGOWP_BASENAME)) {
            return;
        }

        switch_to_blog((int) $site->blog_id);
        self::activateSite();
        restore_current_blog();
    }

    /** Tables, defaults and first-run flags for the current site. */
    private static function activateSite(): void
    {
        // Never aborts on the database's capabilities: without foreign-key
        // support the tables are created without them (see install()).
        TranslationMemorySchema::install();

        TranslationMemorySchema::setDefaultOptions();

        $registry = new OptionLanguageRegistry();
        // Request a one-time initial discovery scan; the batched Action Scheduler
        // job is enqueued on the next init, once the AS store is ready.
        update_option(InitialDiscoveryScan::OPTION_FLAG, 1);

        // Same lazy-enqueue pattern, for the background HTML crawl (own flag —
        // see HtmlDiscoveryCrawl's class doc for why it isn't folded into the
        // scan above).
        update_option(HtmlDiscoveryCrawl::OPTION_FLAG, 1);

        // Register the rewrite compilation filter now, then flush so language
        // rule copies are included immediately. The normal plugin boot may not
        // have run during activation.
        $resolver = new ResolveRequestLanguage($registry);
        (new LanguageRouteRegistrar($resolver))->registerRewriteRulesFilter();

        // Flushing is expensive, so it happens only on activation, never per request.
        // Not on a site we switched into: flush_rewrite_rules() would build its
        // rules from the current site's permalink setup. Leaving the version
        // unset makes that site rebuild them itself on its first request
        // (LanguageRouteRegistrar::maybeRefreshRewriteRules()).
        if (is_multisite() && ms_is_switched()) {
            delete_option('lingowp_rewrite_rules_version');
        } else {
            flush_rewrite_rules();
            update_option('lingowp_rewrite_rules_version', LanguageRouteRegistrar::REWRITE_RULES_VERSION, false);
        }

        // Land the admin on the LingoWP workspace on the next page load instead
        // of the plugins list. One-shot: consumed by
        // OnboardingPageController::maybeRedirectAfterActivation(), which also
        // skips it for bulk ("activate selected") activation.
        set_transient('lingowp_activation_redirect', 1, 30);
    }
}
