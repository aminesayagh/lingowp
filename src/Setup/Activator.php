<?php

namespace LingoWP\Setup;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\LocalizationRouting\LanguageRouteRegistrar;
use LingoWP\Extraction\Html\HtmlDiscoveryCrawl;
use LingoWP\Extraction\Structured\InitialDiscoveryScan;
use LingoWP\Database\SchemaFailure;
use LingoWP\Database\TranslationMemorySchema;

/**
 * Runs once on plugin activation.
 *
 * Responsibilities:
 *  - create database tables
 *  - set default options
 *  - flush rewrite rules a single time (after rules are registered)
 */
class Activator
{
    public static function run(): void
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
                    esc_html__('LingoWP requires these PHP extensions: %s.', 'lingowp'),
                    esc_html(implode(', ', $missing))
                ),
                esc_html__('LingoWP activation failed', 'lingowp'),
                ['back_link' => true]
            );
        }

        // Abort activation with a specific, human-readable message rather than a
        // white screen or raw SQL error when the DB prerequisites are not met
        // (non-InnoDB parent tables, or FKs that could not be created — §10).
        try {
            TranslationMemorySchema::install();
        } catch (SchemaFailure $failure) {
            wp_die(
                esc_html($failure->getMessage()),
                esc_html__('LingoWP activation failed', 'lingowp'),
                ['back_link' => true]
            );
        }

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
        flush_rewrite_rules();
        update_option('lingowp_rewrite_rules_version', LanguageRouteRegistrar::REWRITE_RULES_VERSION, false);

        // Land the admin on the LingoWP workspace on the next page load instead
        // of the plugins list. One-shot: consumed by
        // OnboardingPageController::maybeRedirectAfterActivation(), which also
        // skips it for bulk ("activate selected") activation.
        set_transient('lingowp_activation_redirect', 1, 30);
    }
}
