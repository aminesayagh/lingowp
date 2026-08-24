<?php

namespace TransLangs\AiTranslation;

use TransLangs\Backend\BackendClient;
use TransLangs\Database\Repository\WpDbSourceRepository;
use TransLangs\Shared\Parsing\TranslationSubmissionGuard;
use TransLangs\Shared\Source\TranslationStatus;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;

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

    public function __construct(
        private BackendClient $backend,
        private WpDbSourceRepository $repo,
        private OptionLanguageRegistry $registry
    ) {}

    /**
     * @param array<int, array{ref:\TransLangs\Shared\Source\SourceRef, original:string, translated:?string, status:?int, meta_key?:?string, group_id:string}> $units
     * @return array{translated:int, words_used:int, failures:array, error:?string}
     */
    public function run(array $units, string $lang): array
    {
        $source   = $this->registry->getDefaultLanguage();
        $target   = $lang;
        $done     = 0;
        $words    = 0;
        $failures = [];

        foreach (TranslationBatchPlanner::plan($units) as $bin) {
            $payload = [
                'source_lang'  => $source,
                'target_langs' => [$target],
                'texts'        => array_map(static fn (array $u): array => [
                    'text'     => $u['original'],
                    'group_id' => $u['group_id'],
                ], $bin),
            ];

            $res = $this->backend->translate($payload);
            if (! $res['ok']) {
                return ['translated' => $done, 'words_used' => $words, 'failures' => $failures, 'error' => $res['error']];
            }

            $data = is_array($res['data']) ? $res['data'] : [];
            $byText = [];
            foreach ((array) ($data['translations'] ?? []) as $t) {
                $byText[(string) ($t['text'] ?? '')] = (string) ($t['translation'] ?? '');
            }
            $failedReasons = [];
            foreach ((array) ($data['failed'] ?? []) as $f) {
                $failedReasons[(string) ($f['text'] ?? '')] = (string) ($f['reason'] ?? '');
            }

            foreach ($bin as $u) {
                $text = $byText[$u['original']] ?? null;
                if ($text === null || $text === '') {
                    // 'reason' is the backend's own free-text explanation (not a
                    // stable code) — kept distinct from the guard's 'error' below
                    // so nothing downstream mistakes it for one of our ERR_* codes.
                    if (isset($failedReasons[$u['original']])) {
                        $failures[] = [
                            'ref'    => $u['ref']->format(),
                            'reason' => $failedReasons[$u['original']],
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
                        'ref'     => $u['ref']->format(),
                        'error'   => 'ERR_PROTECTED_TOKEN_STRUCTURE',
                        'details' => $errors,
                    ];
                    continue;
                }

                $this->repo->saveTranslation($u['ref'], $lang, $text, TranslationStatus::MACHINE, self::PROVIDER);
                $done++;
                // ponytail: the API no longer reports words actually billed, so this
                // is the same str_word_count estimate used for the pre-flight quota
                // check — GET /me/entitlements stays the source of truth for real balance.
                $words += str_word_count(wp_strip_all_tags((string) $u['original']));
            }
        }

        return ['translated' => $done, 'words_used' => $words, 'failures' => $failures, 'error' => null];
    }
}
