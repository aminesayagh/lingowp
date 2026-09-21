<?php

namespace LingoWP\Setup;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\LocalizationRouting\LanguageRouteRegistrar;
use LingoWP\Extraction\Html\HtmlDiscoveryCrawl;
use LingoWP\Extraction\Structured\InitialDiscoveryScan;
use LingoWP\Database\SchemaFailure;
use LingoWP\Database\TranslationMemorySchema;

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
        update_option(InitialDiscoveryScan::OPTION_FLAG, 1);

        update_option(HtmlDiscoveryCrawl::OPTION_FLAG, 1);

        $resolver = new ResolveRequestLanguage($registry);
        (new LanguageRouteRegistrar($resolver))->registerRewriteRulesFilter();

        flush_rewrite_rules();
        update_option('lingowp_rewrite_rules_version', LanguageRouteRegistrar::REWRITE_RULES_VERSION, false);

        set_transient('lingowp_activation_redirect', 1, 30);
    }
}
