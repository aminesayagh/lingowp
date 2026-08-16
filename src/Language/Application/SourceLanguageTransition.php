<?php

namespace TransLangs\Language\Application;

use TransLangs\Database\SourceLanguageSnapshot;
use TransLangs\Extraction\Html\HtmlDiscoveryCrawl;
use TransLangs\Extraction\Structured\InitialDiscoveryScan;
use TransLangs\Language\Domain\LocaleNormalizer;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use TransLangs\Language\Infrastructure\WordPressLanguageCatalog;

/**
 * Reacts to WordPress's own Site Language (WPLANG) changing after setup.
 *
 * The source locale is normally frozen at activation (OptionLanguageRegistry)
 * so runtime never re-keys off the filtered, mid-request-switchable
 * get_locale(). A persisted WPLANG change is a different thing entirely — a
 * deliberate, permanent change of the site's language identity — so this
 * class runs a one-time transition: the old source becomes a target (seeded
 * with its former content), the new locale becomes the frozen source, and any
 * translations the new locale already had as a target are preserved (just no
 * longer surfaced as a target). Runs on any real change; if the new locale
 * was never configured as a target, "remove it from targets" is simply a
 * no-op.
 */
final class SourceLanguageTransition
{
    private const NOTICE_TRANSIENT = 'translangs_wplang_transition_notice';

    public function __construct(
        private OptionLanguageRegistry $registry,
        private LanguageMetadataStore $store,
        private SourceLanguageSnapshot $snapshot,
        private \wpdb $wpdb
    ) {}

    public function register(): void
    {
        add_action('update_option_WPLANG', [$this, 'onWplangChanged'], 10, 2);
        add_action('add_option_WPLANG', [$this, 'onWplangAdded'], 10, 2);
        add_action('admin_notices', [$this, 'maybeRenderNotice']);
    }

    /**
     * update_option() uses the add-option hook when WPLANG does not exist yet.
     *
     * @param mixed $option
     * @param mixed $value
     */
    public function onWplangAdded($option, $value): void
    {
        $this->onWplangChanged('', $value);
    }

    /**
     * @param mixed $old
     * @param mixed $new
     */
    public function onWplangChanged($old, $new): void
    {
        // WordPress stores English (United States) as an empty WPLANG option.
        $newCode = (string) $new === ''
            ? 'en_US'
            : LocaleNormalizer::normalize((string) $new);

        if ($newCode === $this->registry->getDefaultLanguage()) {
            return;
        }

        $oldSourceCode = $this->registry->getDefaultLanguage();
        $oldSourceMeta = $this->registry->getDefaultLanguageDetails();

        $this->wpdb->query('START TRANSACTION'); // phpcs:ignore WordPress.DB

        try {
            // 1. Save the old source content as a translation under its own code.
            $this->snapshot->copyCurrentSourceAsTranslation($oldSourceCode);

            // 2. Remove the new source code from target configuration (no-op if absent).
            $existingNewSourceMeta = $this->store->get($newCode);
            $this->registry->forgetLanguage($newCode);
            $this->store->remove($newCode);

            // 3. Freeze the new locale as source.
            $sourceDetails = $this->resolveSourceDetails($newCode, $existingNewSourceMeta);
            update_option(OptionLanguageRegistry::OPTION_SOURCE_LOCALE, $newCode);
            update_option(OptionLanguageRegistry::OPTION_SOURCE_METADATA, $sourceDetails);

            // 4. Add the old source as a target, falling back to the new source.
            $this->store->save($this->buildTargetEntry($oldSourceCode, $oldSourceMeta, $newCode));
            $this->registry->enableLanguage($oldSourceCode);

            do_action('translangs_language_roster_changed', $this->registry->getTargetLanguages());

            // 5. Re-index current site content as the new source's content.
            update_option(InitialDiscoveryScan::OPTION_FLAG, 1);
            update_option(HtmlDiscoveryCrawl::OPTION_FLAG, 1);

            // 6. Invalidate rewrite rules — same as LanguageRestController.
            delete_option('rewrite_rules');
            delete_option('translangs_rewrite_rules_version');

            $this->wpdb->query('COMMIT'); // phpcs:ignore WordPress.DB
        } catch (\Throwable $e) {
            $this->wpdb->query('ROLLBACK'); // phpcs:ignore WordPress.DB

            throw $e;
        }

        set_transient(self::NOTICE_TRANSIENT, ['from' => $oldSourceCode, 'to' => $newCode], HOUR_IN_SECONDS);
    }

    public function maybeRenderNotice(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $data = get_transient(self::NOTICE_TRANSIENT);
        if (!is_array($data)) {
            return;
        }

        delete_transient(self::NOTICE_TRANSIENT);

        $from = (string) ($data['from'] ?? '');
        $to   = (string) ($data['to'] ?? '');

        printf(
            '<div class="notice notice-info is-dismissible"><p>%s</p></div>',
            sprintf(
                /* translators: 1: previous source locale (now a target), 2: new source locale */
                esc_html__('TransLangs: source language switched from %1$s to %2$s. %1$s is now a target language seeded with your previous content; any existing %2$s translations are preserved but no longer shown as a target.', 'translangs'),
                esc_html($from),
                esc_html($to)
            )
        );
    }

    /** @return array{code: string, language: string, region: string, name: string, display_name: string, direction: string} */
    private function resolveSourceDetails(string $code, ?array $existingMeta): array
    {
        if ($existingMeta !== null && !empty($existingMeta['display_name'])) {
            return [
                'code'         => $code,
                'language'     => (string) ($existingMeta['language'] ?: LocaleNormalizer::language($code)),
                'region'       => (string) ($existingMeta['region'] ?: LocaleNormalizer::region($code)),
                'name'         => (string) ($existingMeta['name'] ?: $existingMeta['display_name']),
                'display_name' => (string) $existingMeta['display_name'],
                'direction'    => (string) ($existingMeta['direction'] ?: 'ltr'),
            ];
        }

        $catalog      = WordPressLanguageCatalog::get($code);
        $languageName = (string) ($catalog['language_name'] ?? $code);
        $regionName   = (string) ($catalog['region_name'] ?? '');
        $name         = (string) ($catalog['english_name'] ?? '');

        if ($name === '' || $name === $code) {
            $name = $regionName !== '' ? sprintf('%s (%s)', $languageName, $regionName) : $languageName;
        }

        return [
            'code'         => $code,
            'language'     => (string) ($catalog['language'] ?? LocaleNormalizer::language($code)),
            'region'       => (string) ($catalog['region'] ?? LocaleNormalizer::region($code)),
            'name'         => $name,
            'display_name' => $name,
            'direction'    => (string) ($catalog['direction'] ?? 'ltr'),
        ];
    }

    /** @param array<string, mixed> $oldSourceMeta */
    private function buildTargetEntry(string $code, array $oldSourceMeta, string $fallback): array
    {
        $catalog = WordPressLanguageCatalog::get($code);

        return [
            'code'              => $code,
            'language'          => (string) ($catalog['language'] ?? $oldSourceMeta['language'] ?? LocaleNormalizer::language($code)),
            'locale'            => $code,
            'region'            => (string) ($catalog['region'] ?? $oldSourceMeta['region'] ?? LocaleNormalizer::region($code)),
            'name'              => (string) ($catalog['english_name'] ?? $oldSourceMeta['name'] ?? $code),
            'display_name'      => (string) ($oldSourceMeta['display_name'] ?? $catalog['english_name'] ?? $code),
            'slug'              => $this->uniqueSlug($code),
            'fallback_language' => $fallback,
            'direction'         => (string) ($catalog['direction'] ?? $oldSourceMeta['direction'] ?? 'ltr'),
        ];
    }

    private function uniqueSlug(string $code): string
    {
        $base = sanitize_title(str_replace('_', '-', strtolower($code)));
        $slug = $base !== '' ? $base : $code;

        $defaultSlug = sanitize_title(str_replace('_', '-', strtolower($this->registry->getDefaultLanguage())));
        $taken       = array_map(
            static fn(array $entry): string => sanitize_title((string) ($entry['slug'] ?? '')),
            $this->store->all()
        );

        $candidate = $slug;
        $suffix    = 2;
        while ($candidate === $defaultSlug || in_array($candidate, $taken, true)) {
            $candidate = $slug . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }
}
