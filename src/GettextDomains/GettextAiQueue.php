<?php

namespace LingoWP\GettextDomains;

use LingoWP\AiTranslation\AiQueueConfig;
use LingoWP\Backend\TranslationBackendPort;
use LingoWP\Database\TranslationMemorySchema;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use LingoWP\Shared\Parsing\TranslationSubmissionGuard;
use LingoWP\Shared\Text\TranslationKey;

/**
 * Async "Translate All" for the Plugins & Themes board — the gettext twin of
 * the Content board's AiTaskSubmitter + AiResultCollector, but standalone
 * (that pair is concrete-coupled to WpDbSourceRepository) and pointed at a
 * file-backed store: results land in `.mo` files via
 * GettextTranslationUnitsService::saveUnit(), not a DB child table.
 *
 * Bookkeeping stays on the one `wp_lingowp_gettext_unit` row (still one row
 * per source string, locale-blind): `ai_pending_locales` is a pipe-list of
 * targets with an AI request in flight ('fr_FR|en_GB'); `ai_pending_since`
 * anchors AiQueueConfig's 6h freshness TTL; `ai_error` is the last failure
 * code. `norm_hash` = SHA-256 of the normalized msgid — the hash the backend
 * echoes as `source_hash` (the byte-exact `unit_hash` can't match it).
 *
 * The result channel returns only {source_hash, target_lang} — no context —
 * so an AI translation of a string with several `msgctxt` variants (same
 * normalized text, one norm_hash) is written to every pending variant. Same
 * hash fan-out the Content board already accepts.
 */
final class GettextAiQueue
{
    /** Bound on collectOnce() per call — see its own docblock. */
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

    // ── pipe-list helpers (also exercised by tests/gettext_ai_hash_check.php) ──

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

    // ── submit ───────────────────────────────────────────────────────────────

    /**
     * Queue one locale's worth of a batch. $units:
     * [{type, domain, msgid, context, unit_hash(hex)}]. Stamps the rows only
     * after the backend accepts the batch (matches AiTaskSubmitter).
     *
     * ai_pending_since is always reset to now on submit, even when the locale
     * (or another one) was already in the pending list — a shared row-level
     * anchor that only ever COALESCE'd onto an old value could stay stuck
     * past the 6h TTL forever once ANY locale went stale, silently hiding a
     * just-submitted locale as "not pending" and letting it be resubmitted on
     * every subsequent Translate All click. Re-arming it on every submit is
     * harmless for a genuinely still-in-flight locale (it just reads as
     * fresher, which is accurate — something about this row is still active).
     *
     * @return array{error: ?string, submitted: int}
     */
    public function submit(array $units, string $locale): array
    {
        if ($units === []) {
            return ['error' => null, 'submitted' => 0];
        }

        $res = $this->backend->submitTasks([
            'source_lang'  => $this->languageRegistry->getDefaultLanguage(),
            'target_langs' => [$locale],
            // Required by the backend's request model — a 422 "field
            // required" without it (confirmed against the actual Pydantic
            // model).
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
            // updated_at/ai_pending_since always change, so rows_affected is a
            // reliable "the row exists" signal (mysqli, no CLIENT_FOUND_ROWS).
            if ((int) $this->wpdb->rows_affected > 0) {
                $stamped++;
            }
        }

        // Report only what was actually stamped — a unit whose gettext_unit
        // row doesn't exist can't be polled or matched back to a result, so
        // it must not count as submitted.
        return ['error' => null, 'submitted' => $stamped];
    }

    /** True if an active (non-ignored) row exists for this exact unit_hash. */
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

    /**
     * Every active, still-untranslated, not-already-in-flight string of one
     * component+locale — the candidate list the controller slices and submits.
     *
     * @return array<int, array{type: string, domain: string, msgid: string, context: ?string, unit_hash: string}>
     */
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

    // ── collect ──────────────────────────────────────────────────────────────

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

    /**
     * Drain up to MAX_RESULTS_PER_COLLECT results, applying each to its `.mo`
     * + row. Called from ordinary board reads (list()/units()), so it must
     * stay bounded — a page can fan out to many real .mo read-modify-writes,
     * and this can run again on the next poll if more is left (has_more
     * staying true server-side just means the next call resumes).
     */
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
                // Only ack what was actually persisted locally — a saveUnit()
                // failure (disk full, unwritable dir) must NOT be acked, or the
                // backend deletes its copy and the translation is lost for
                // good. Leaving it unacked means the next collectOnce() sees
                // it again and can retry.
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

    /**
     * Apply one {source_hash, target_lang} result. Fans out to every
     * `gettext_unit` row with that norm_hash whose pending list holds $locale
     * (the msgctxt variants). `.mo` written first, the row updated second.
     *
     * @return bool false if ANY row's `.mo` write failed (disk full, an
     *              unwritable languages dir, a failed rename) — the caller
     *              must NOT ack that backend result when this returns false,
     *              or the backend deletes its copy and the translation is
     *              gone for good with nothing left to retry.
     */
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
                    // Local write failed — leave this row's pending state
                    // exactly as it was so the next collectOnce() retries it.
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

    // ── read ─────────────────────────────────────────────────────────────────

    /**
     * Per-string AI state for one component+locale, for the board's unit list
     * and row counts.
     *
     * @return array<string, array{pending: bool, error: ?string}> keyed by lowercase unit_hash hex
     */
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
