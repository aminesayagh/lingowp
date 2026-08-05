<?php

namespace TransLangs\AiTranslation;

use TransLangs\Backend\BackendClient;
use TransLangs\Backend\SiteMetadataProvider;
use TransLangs\Database\SourceRepository;
use TransLangs\Shared\Parsing\TranslationSubmissionGuard;
use TransLangs\Shared\Source\TranslationStatus;
use TransLangs\Language\Infrastructure\LanguageMetadataStore;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;

/**
 * The synchronous AI-translate batch loop: chunk units of 50, call the
 * backend, validate each result in memory, save the ones that pass. Split
 * out of AiTranslationController so the quota check → batch → save flow is
 * one application-service call instead of being interleaved with HTTP
 * request/response handling (Problem 5 of the reorg plan). The controller
 * still owns the quota pre-check (a pure HTTP short-circuit) and response
 * shaping; this class owns everything that actually spends quota and writes.
 */
final class AiTranslationRunner
{
    private const BATCH    = 50;
    private const PROVIDER = 'ai';

    public function __construct(
        private BackendClient $backend,
        private SourceRepository $repo,
        private OptionLanguageRegistry $registry,
        private LanguageMetadataStore $langStore
    ) {}

    /**
     * @param array<int, array{ref:\TransLangs\Shared\Source\SourceRef, original:string, translated:?string, status:?int, meta_key?:?string}> $units
     * @return array{translated:int, words_used:int, failures:array, error:?string}
     */
    public function run(array $units, string $lang): array
    {
        $source  = $this->registry->getDefaultLanguage();
        $aiStyle = (string) ($this->langStore->get($lang)['ai_style'] ?? '');
        $cats    = array_values((array) get_option(SiteMetadataProvider::OPTION_CATEGORIES, []));
        $done     = 0;
        $words    = 0;
        $failures = [];

        foreach (array_chunk($units, self::BATCH) as $chunk) {
            $payload = [
                'source_language' => $source,
                'target_language' => $lang,
                'ai_style'        => $aiStyle,
                'categories'      => $cats,
                'texts'           => array_map(
                    static fn (array $u): array => ['id' => $u['ref']->format(), 'text' => $u['original']],
                    $chunk
                ),
            ];

            $res = $this->backend->translate($payload);
            if (! $res['ok']) {
                return ['translated' => $done, 'words_used' => $words, 'failures' => $failures, 'error' => $res['error']];
            }

            $data = is_array($res['data']) ? $res['data'] : [];
            $byId = [];
            foreach ((array) ($data['translations'] ?? []) as $t) {
                $byId[(string) ($t['id'] ?? '')] = (string) ($t['text'] ?? '');
            }

            foreach ($chunk as $u) {
                $text = $byId[$u['ref']->format()] ?? null;
                if ($text === null || $text === '') {
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
            }
            $words += (int) ($data['words_used'] ?? 0);
        }

        return ['translated' => $done, 'words_used' => $words, 'failures' => $failures, 'error' => null];
    }
}
