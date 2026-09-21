<?php

namespace LingoWP\GettextDomains;

use LingoWP\AiTranslation\AiQueueConfig;
use LingoWP\Backend\TranslationBackendPort;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Shared\Parsing\TranslationSubmissionGuard;
use LingoWP\Shared\Text\TranslationKey;

final class GettextAiQueue
{
    private const MAX_RESULTS_PER_COLLECT = 100;

    private \wpdb $wpdb;
    private TranslationBackendPort $backend;
    private GettextTranslationUnitsService $units;
    private GettextDomainRegistry $registry;
    private GettextTranslatedPercentCache $percentCache;
    private OptionLanguageRegistry $languageRegistry;
    private LanguageMetadataStore $languageMetadata;

    public function __construct(
        \wpdb $wpdb,
        TranslationBackendPort $backend,
        GettextTranslationUnitsService $units,
        GettextDomainRegistry $registry,
        GettextTranslatedPercentCache $percentCache,
        OptionLanguageRegistry $languageRegistry,
        LanguageMetadataStore $languageMetadata
    ) {
        $this->wpdb             = $wpdb;
        $this->backend          = $backend;
        $this->units            = $units;
        $this->registry         = $registry;
        $this->percentCache     = $percentCache;
        $this->languageRegistry = $languageRegistry;
        $this->languageMetadata = $languageMetadata;
    }

    public static function addLocale(string $list, string $locale): string
    {
        if (self::hasLocale($list, $locale)) {
            return $list;
        }

        return $list === '' ? $locale : $list . '|' . $locale;
    }

    public static function removeLocale(string $list, string $locale): string
    {
        if ($list === '') {
            return '';
        }

        return implode('|', array_filter(
            explode('|', $list),
            static fn (string $p): bool => $p !== '' && $p !== $locale
        ));
    }

    public static function hasLocale(string $list, string $locale): bool
    {
        return $list !== '' && in_array($locale, explode('|', $list), true);
    }

    public function submit(array $units, string $locale): array
    {
        if ($units === []) {
            return ['error' => null, 'submitted' => 0];
        }

        $res = $this->backend->submitTasks([
            'source_lang'  => $this->languageRegistry->getDefaultLanguage(),
            'target_langs' => [$locale],
            'languages'    => $this->languageMetadata->providerConfig([$locale]),
            'texts'        => array_map(static fn (array $u): array => [
                'text'     => (string) $u['msgid'],
                'group_id' => $u['type'] . ':' . $u['domain'],
            ], $units),
        ]);
        if (! $res['ok']) {
            return ['error' => $res['error'] !== '' ? $res['error'] : 'ERR_BACKEND', 'submitted' => 0];
        }

        $table   = TranslationMemorySchema::gettextUnitTable();
        $now     = current_time('mysql', true);
        $like    = '%|' . $this->wpdb->esc_like($locale) . '|%';
        $stamped = 0;

        foreach ($units as $u) {
            $normHex = bin2hex(TranslationKey::currentHash((string) $u['msgid']));

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $this->wpdb->query($this->wpdb->prepare(
                "UPDATE {$table}
                 SET norm_hash = UNHEX(%s),
                     ai_pending_locales = CASE
                         WHEN CONCAT('|', ai_pending_locales, '|') LIKE %s THEN ai_pending_locales
                         WHEN ai_pending_locales = '' THEN %s
                         ELSE CONCAT(ai_pending_locales, '|', %s)
                     END,
                     ai_pending_since = %s,
                     ai_error = NULL,
                     updated_at = %s
                 WHERE type = %s AND domain = %s AND unit_hash = UNHEX(%s)",
                $normHex,
                $like,
                $locale,
                $locale,
                $now,
                $now,
                (string) $u['type'],
                (string) $u['domain'],
                (string) $u['unit_hash']
            ));
            if ((int) $this->wpdb->rows_affected > 0) {
                $stamped++;
            }
        }

        return ['error' => null, 'submitted' => $stamped];
    }

    public function hasActiveUnit(string $type, string $domain, string $unitHashHex): bool
    {
        $table = TranslationMemorySchema::gettextUnitTable();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (bool) $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT EXISTS(SELECT 1 FROM {$table}
             WHERE type = %s AND domain = %s AND unit_hash = UNHEX(%s) AND status = 0)",
            $type,
            $domain,
            $unitHashHex
        ));
    }

    public function resolvePendingUnits(string $path, string $type, string $domain, string $locale): array
    {
        $all = $this->units->list($path, $type, $domain, $locale);
        if ($all === null) {
            return [];
        }

        $pending = $this->pendingForList($type, $domain, $locale);

        $out = [];
        foreach ($all as $u) {
            if ($u['msgstr'] !== '') {
                continue;
            }
            $uh = GettextUnitRepository::hashHex($u['msgid'], $u['context']);
            if (! empty($pending[$uh]['pending'])) {
                continue;
            }
            $out[] = [
                'type'      => $type,
                'domain'    => $domain,
                'msgid'     => $u['msgid'],
                'context'   => $u['context'],
                'unit_hash' => $uh,
            ];
        }

        return $out;
    }

    public function hasAnyPending(): bool
    {
        $table = TranslationMemorySchema::gettextUnitTable();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        return (bool) $this->wpdb->get_var("SELECT EXISTS(SELECT 1 FROM {$table} WHERE ai_pending_since IS NOT NULL)"); // nosemgrep: wpdb-interpolated-sql -- table name only, from TranslationMemorySchema::gettextUnitTable()
    }

    public function tryPullLock(): bool
    {
        return (int) $this->wpdb->get_var("SELECT GET_LOCK('lingowp_gettext_ai_pull', 0)") === 1;
    }

    public function releasePullLock(): void
    {
        $this->wpdb->query("SELECT RELEASE_LOCK('lingowp_gettext_ai_pull')");
    }

    public function collectOnce(): void
    {
        $cursor    = 0;
        $processed = 0;

        do {
            $res = $this->backend->pullResults($cursor);
            if (! $res['ok']) {
                return;
            }

            $data    = is_array($res['data']) ? $res['data'] : [];
            $results = (array) ($data['results'] ?? []);
            if ($results === []) {
                return;
            }

            $ack = [];
            foreach ($results as $r) {
                $hashHex = strtolower((string) ($r['source_hash'] ?? ''));
                $lang    = (string) ($r['target_lang'] ?? '');
                if ($hashHex === '' || $lang === '') {
                    continue;
                }
                if ($this->applyResult($hashHex, $lang, $r['output'] ?? null, $r['error'] ?? null)) {
                    $ack[] = ['source_hash' => $hashHex, 'target_lang' => $lang];
                }
                $processed++;
            }

            if ($ack !== []) {
                $this->backend->ackResults($ack);
            }

            $cursor  = (int) ($data['next_cursor'] ?? $cursor);
            $hasMore = (bool) ($data['has_more'] ?? false);
        } while ($hasMore && $processed < self::MAX_RESULTS_PER_COLLECT);
    }

    public function applyResult(string $srcHashHex, string $locale, ?string $output, ?string $error): bool
    {
        $table = TranslationMemorySchema::gettextUnitTable();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = (array) $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT type, domain, LOWER(HEX(unit_hash)) AS uh, msgid, context, ai_pending_locales
             FROM {$table}
             WHERE norm_hash = UNHEX(%s) AND CONCAT('|', ai_pending_locales, '|') LIKE %s",
            $srcHashHex,
            '%|' . $this->wpdb->esc_like($locale) . '|%'
        ), ARRAY_A);

        $now = current_time('mysql', true);
        $ok  = true;

        foreach ($rows as $row) {
            $type   = (string) $row['type'];
            $domain = (string) $row['domain'];
            $msgid  = (string) $row['msgid'];
            $ctx    = $row['context'] !== null && $row['context'] !== '' ? (string) $row['context'] : null;

            $failCode = null;
            if ($error !== null && $error !== '') {
                $failCode = (string) $error;
            } elseif ($output === null || $output === '') {
                $failCode = 'ERR_BACKEND_EMPTY_RESULT';
            } elseif (TranslationSubmissionGuard::validate($msgid, (string) $output, null) !== []) {
                $failCode = 'ERR_PROTECTED_TOKEN_STRUCTURE';
            }

            if ($failCode === null) {
                if (! $this->units->saveUnit($type, $domain, $locale, $msgid, $ctx, (string) $output)) {
                    $ok = false;
                    continue;
                }
                if ($this->registry->get($type, $domain, $locale) === null) {
                    $this->registry->set($type, $domain, $locale, GettextDomainRegistry::STATUS_IMPORTED);
                }
                $this->percentCache->invalidate($type, $domain, $locale);
            }

            $remaining      = self::removeLocale((string) $row['ai_pending_locales'], $locale);
            $pendingSinceCol = $remaining === '' ? 'NULL' : 'ai_pending_since';

            if ($failCode === null) {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$table}
                     SET ai_pending_locales = %s, ai_pending_since = {$pendingSinceCol}, ai_error = NULL, updated_at = %s
                     WHERE type = %s AND domain = %s AND unit_hash = UNHEX(%s)",
                    $remaining,
                    $now,
                    $type,
                    $domain,
                    (string) $row['uh']
                ));
            } else {
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
                $this->wpdb->query($this->wpdb->prepare(
                    "UPDATE {$table}
                     SET ai_pending_locales = %s, ai_pending_since = {$pendingSinceCol}, ai_error = %s, updated_at = %s
                     WHERE type = %s AND domain = %s AND unit_hash = UNHEX(%s)",
                    $remaining,
                    $failCode,
                    $now,
                    $type,
                    $domain,
                    (string) $row['uh']
                ));
            }
        }

        return $ok;
    }

    public function pendingForList(string $type, string $domain, string $locale): array
    {
        $table = TranslationMemorySchema::gettextUnitTable();

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $rows = (array) $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT LOWER(HEX(unit_hash)) AS uh, ai_pending_locales, ai_pending_since, ai_error
             FROM {$table}
             WHERE type = %s AND domain = %s AND (ai_pending_since IS NOT NULL OR ai_error IS NOT NULL)",
            $type,
            $domain
        ), ARRAY_A);

        $now = time();
        $out = [];
        foreach ($rows as $row) {
            $pending = self::hasLocale((string) $row['ai_pending_locales'], $locale)
                && AiQueueConfig::isFreshPending($row['ai_pending_since'] ?? null, $now);
            $error = ! $pending && $row['ai_error'] !== null && $row['ai_error'] !== ''
                ? (string) $row['ai_error']
                : null;
            if ($pending || $error !== null) {
                $out[(string) $row['uh']] = ['pending' => $pending, 'error' => $error];
            }
        }

        return $out;
    }
}
