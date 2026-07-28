<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Core\Parsing\UnitStructureGuard;
use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Source\Value\TranslationStatus;
use TransLangs\Core\Text\TL_Key;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * CSV export/import for content translations (namespace translangs/v1).
 *
 * Scope is deliberately narrow: posts, post meta, terms, and site settings/
 * branding (tl_post/tl_meta/tl_term/tl_option) — the four tables the main
 * workspace table renders. Gettext and hardcoded-HTML strings are dependency/
 * generic strings with their own per-dependency export/import elsewhere.
 *
 * The CSV is a flat text-to-translation matrix, not a row-per-source dump:
 * one row per unique source text (deduplicated GLOBALLY across all four
 * tables — see SourceTranslationRepository::exportTextsForLanguages()), one
 * column per language. No ref/type/location/status columns: the client does
 * not want to know or reason about which post/term/meta a string came from,
 * only that identical text needs one translation per language. Import writes
 * that one translation to every currently-discovered source sharing the same
 * normalized text (TL_Key::currentHash()), across all four tables at once.
 *
 * GET /export streams a CSV directly (bypasses the JSON envelope — this is a
 * file download, not an API response) and exits. POST /import reuses
 * saveTranslation() (already correct for both child-key models) after the
 * same UnitStructureGuard check the manual single-text save endpoint runs.
 */
final class ExportImportRestController
{
    private const NS = 'translangs/v1';

    public function __construct(
        private SourceTranslationRepository $repo,
        private OptionLanguageRegistry $registry
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/export', ['methods' => 'GET', 'callback' => [$this, 'export']] + $guard);
        register_rest_route(self::NS, '/import', ['methods' => 'POST', 'callback' => [$this, 'importCsv']] + $guard);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Streams a CSV attachment and exits — never returns a WP_REST_Response,
     * because a file download has no JSON envelope to return.
     */
    public function export(WP_REST_Request $request): void
    {
        $targets = $this->registry->getTargetLanguages();
        $scope   = $request->get_param('scope') === 'all' ? 'all' : 'current';

        if ($scope === 'all') {
            $languages = $targets;
        } else {
            $lang = sanitize_key((string) $request->get_param('language'));
            if ($lang === '' || !in_array($lang, $targets, true)) {
                status_header(400);
                wp_send_json(['ok' => false, 'error' => 'ERR_INVALID_INPUT']);
                return; // wp_send_json() already exits; return keeps static analysis happy.
            }
            $languages = [$lang];
        }

        $rows = $this->repo->exportTextsForLanguages($languages);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="translangs-export-' . gmdate('Ymd-His') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['source_text', ...$languages]);

        foreach ($rows as $row) {
            $line = [$row['original_text']];
            foreach ($languages as $lang) {
                $line[] = $row['translations'][$lang] ?? '';
            }
            fputcsv($out, $line);
        }

        fclose($out);
        exit;
    }

    /**
     * Multipart CSV upload. Column 0 is always the source text (whatever it's
     * labeled); every other header column is treated as a language code IF
     * it's currently enabled — a renamed/unrecognized column is silently
     * skipped rather than failing the whole file (languages may have changed
     * since the file was exported).
     *
     * Each non-blank cell: validate the marker structure against the row's
     * own source_text (safe to trust — same hash implies same normalized
     * text, so the structure is identical everywhere it's written), then
     * write to every currently-discovered source sharing that hash across
     * all four tables. Always saves as REVIEWED — a human supplied this text
     * via CSV, same as the manual single-text save endpoint always does.
     */
    public function importCsv(WP_REST_Request $request): WP_REST_Response
    {
        $files = $request->get_file_params();
        $tmp   = $files['file']['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $handle = fopen($tmp, 'r');
        if ($handle === false) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $header = fgetcsv($handle);
        if ($header === false || count($header) < 2) {
            fclose($handle);
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $targets     = $this->registry->getTargetLanguages();
        $langColumns = []; // column index => lang code
        foreach ($header as $i => $name) {
            if ($i === 0) {
                continue;
            }
            $code = sanitize_key(trim((string) $name));
            if (in_array($code, $targets, true)) {
                $langColumns[$i] = $code;
            }
        }

        if ($langColumns === []) {
            fclose($handle);
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        $applied = 0;
        $skipped = 0;
        $errors  = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $sourceText = trim((string) ($data[0] ?? ''));
            if ($sourceText === '') {
                $skipped++;
                continue;
            }

            $hashHex = null; // computed lazily — only once a cell actually needs it
            $refs    = null;

            foreach ($langColumns as $i => $lang) {
                $text = trim((string) ($data[$i] ?? ''));
                if ($text === '') {
                    $skipped++;
                    continue;
                }

                if (UnitStructureGuard::validate($sourceText, $text) !== []) {
                    $errors++;
                    continue;
                }

                if ($refs === null) {
                    $hashHex = bin2hex(TL_Key::currentHash($sourceText));
                    $refs    = $this->repo->findContentRefsByHash($hashHex);
                }
                if ($refs === []) {
                    // No currently-discovered source has this text anymore
                    // (edited or removed since export) — nothing to write to.
                    $errors++;
                    continue;
                }

                foreach ($refs as $ref) {
                    $this->repo->saveTranslation($ref, $lang, $text, TranslationStatus::REVIEWED, null);
                }
                $applied++;
            }
        }

        fclose($handle);

        return rest_ensure_response(['ok' => true, 'applied' => $applied, 'skipped' => $skipped, 'errors' => $errors]);
    }
}
