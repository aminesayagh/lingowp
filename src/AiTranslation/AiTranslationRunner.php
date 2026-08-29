<?php

namespace LingoWP\AiTranslation;

use LingoWP\Backend\BackendClient;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Shared\Parsing\TranslationSubmissionGuard;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

/**
 * The synchronous AI-translate batch loop: plan units into request-sized bins
 * (TranslationBatchPlanner — the backend caps at 25 items/group_id, 100/request),
 * call the backend once per bin, validate each result in memory, save the ones
 * that pass. Split out of AiTranslationController so the quota check → batch →
 * save flow is one application-service call instead of being interleaved with
 * HTTP request/response handling (Problem 5 of the reorg plan). The controller
 * still owns the quota pre-check (a pure HTTP short-circuit) and response
 * shaping; this class owns everything that actually spends quota and writes.
 */
final class AiTranslationRunner
{
    private const PROVIDER = 'ai';

    private BackendClient $backend;
    private WpDbSourceRepository $repo;
    private OptionLanguageRegistry $registry;
    private LanguageMetadataStore $metadata;

    public function __construct(
        BackendClient $backend,
        WpDbSourceRepository $repo,
        OptionLanguageRegistry $registry,
        LanguageMetadataStore $metadata
    ) {
        $this->backend  = $backend;
        $this->repo     = $repo;
        $this->registry = $registry;
        $this->metadata = $metadata;
    }

    /**
     * Per-target-language AI config for the /translations payload's `languages`
     * map: which provider to bill against (the subscription model is 'platform';
     * a stored key passes through as 'openai' / 'anthropic') plus the language's
     * saved writing style, omitted when blank. Legacy/absent 'auto' -> 'platform'.
     *
     * @param string[] $langs
     * @return array<string, array{provider:string, writing_style?:string}>
     */
    private function languageConfig(array $langs): array
    {
        $map = [];
        foreach ($langs as $lang) {
            $meta     = $this->metadata->get($lang) ?? [];
            $model    = (string) ($meta['ai_model'] ?? '');
            $provider = in_array($model, ['openai', 'anthropic'], true) ? $model : 'platform';

            $entry = ['provider' => $provider];
            $style = trim((string) ($meta['ai_style'] ?? ''));
            if ($style !== '') {
                $entry['writing_style'] = $style;
            }
            $map[$lang] = $entry;
        }

        return $map;
    }

    /**
     * @param array<int, array{ref:\LingoWP\Shared\Source\SourceRef, original:string, translated:?string, status:?int, meta_key?:?string, group_id:string}> $units
     * @param string[] $langs One or more normalized target languages. scope 'text'
     *        passes every configured language so CompareOverlay's "Translate with
     *        AI" is one backend request; every other scope passes a single one.
     * @return array{translated:int, words_used:int, failures:array, error:?string, by_language:array<string, array{translated:int}>}
     */
    public function run(array $units, array $langs): array
    {
        $source     = $this->registry->getDefaultLanguage();
        $langs      = array_values(array_unique(array_filter($langs, static fn ($l): bool => $l !== '')));
        $done       = 0;
        $words      = 0;
        $failures   = [];
        $byLanguage = array_fill_keys($langs, ['translated' => 0]);

        if ($langs === []) {
            return ['translated' => 0, 'words_used' => 0, 'failures' => [], 'error' => null, 'by_language' => []];
        }

        $languageConfig = $this->languageConfig($langs);

        foreach (TranslationBatchPlanner::plan($units) as $bin) {
            $payload = [
                'source_lang'  => $source,
                'target_langs' => $langs,
                'languages'    => $languageConfig,
                'texts'        => array_map(static fn (array $u): array => [
                    'text'     => $u['original'],
                    'group_id' => $u['group_id'],
                ], $bin),
            ];

            $res = $this->backend->translate($payload);
            if (! $res['ok']) {
                return ['translated' => $done, 'words_used' => $words, 'failures' => $failures, 'error' => $res['error'], 'by_language' => $byLanguage];
            }

            $data = is_array($res['data']) ? $res['data'] : [];

            // Demux by target language. The backend tags every entry with
            // target_lang; when only one language was asked for, an older shape
            // that omits it still resolves to that language.
            $only        = count($langs) === 1 ? $langs[0] : '';
            $byLangText  = [];
            foreach ((array) ($data['translations'] ?? []) as $t) {
                $tl = (string) ($t['target_lang'] ?? $only);
                $byLangText[$tl][(string) ($t['text'] ?? '')] = (string) ($t['translation'] ?? '');
            }
            $failedByLang = [];
            foreach ((array) ($data['failed'] ?? []) as $f) {
                $tl = (string) ($f['target_lang'] ?? $only);
                $failedByLang[$tl][(string) ($f['text'] ?? '')] = (string) ($f['reason'] ?? '');
            }

            foreach ($langs as $lang) {
                $byText        = $byLangText[$lang] ?? [];
                $failedReasons = $failedByLang[$lang] ?? [];

                foreach ($bin as $u) {
                    $text = $byText[$u['original']] ?? null;
                    if ($text === null || $text === '') {
                        // 'reason' is the backend's own free-text explanation (not a
                        // stable code) — kept distinct from the guard's 'error' below
                        // so nothing downstream mistakes it for one of our ERR_* codes.
                        if (isset($failedReasons[$u['original']])) {
                            $failures[] = [
                                'ref'         => $u['ref']->format(),
                                'target_lang' => $lang,
                                'reason'      => $failedReasons[$u['original']],
                            ];
                        }
                        continue;
                    }

                    // Validate from the in-memory unit — never a query per item.
                    $errors = TranslationSubmissionGuard::validate(
                        (string) $u['original'],
                        $text,
                        $u['meta_key'] ?? null
                    );
                    if ($errors !== []) {
                        // Reject this item only: a mangled %%sep%% must never reach a
                        // rendered title, but 49 good translations must not be lost.
                        $failures[] = [
                            'ref'         => $u['ref']->format(),
                            'target_lang' => $lang,
                            'error'       => 'ERR_PROTECTED_TOKEN_STRUCTURE',
                            'details'     => $errors,
                        ];
                        continue;
                    }

                    // AI output lands as needs_review, never "done" — a human still
                    // has to confirm it on the board / gettext board.
                    $this->repo->saveTranslation($u['ref'], $lang, $text, TranslationStatus::NEEDS_REVIEW, self::PROVIDER);
                    $done++;
                    $byLanguage[$lang]['translated']++;
                    // ponytail: the API no longer reports words actually billed, so this
                    // is the same str_word_count estimate used for the pre-flight quota
                    // check — GET /me/entitlements stays the source of truth for real balance.
                    $words += str_word_count(wp_strip_all_tags((string) $u['original']));
                }
            }
        }

        return ['translated' => $done, 'words_used' => $words, 'failures' => $failures, 'error' => null, 'by_language' => $byLanguage];
    }
}
