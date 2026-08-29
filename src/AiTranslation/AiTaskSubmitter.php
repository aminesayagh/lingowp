<?php

namespace LingoWP\AiTranslation;

use LingoWP\Backend\TranslationBackendPort;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

/**
 * Queue-and-return: submits a batch of units to the backend's async
 * translate-all queue and stamps ai_pending_since on success. Replaces
 * AiTranslationRunner's synchronous batch/save loop -- nothing here writes a
 * translation; results land later via AiResultCollector, pulled by the board.
 */
final class AiTaskSubmitter
{
    private TranslationBackendPort $backend;
    private WpDbSourceRepository $repo;
    private OptionLanguageRegistry $registry;
    private LanguageMetadataStore $metadata;

    public function __construct(
        TranslationBackendPort $backend,
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
     * Per-target-language AI config for the submit payload's `languages` map:
     * which provider to bill against (the subscription model is 'platform';
     * a stored key passes through as 'openai' / 'anthropic') plus the
     * language's saved writing style, omitted when blank.
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
     * @param array<int, array{ref:\LingoWP\Shared\Source\SourceRef, original:string, group_id:string}> $units
     * @param string[] $langs
     * @return array{error: ?string}
     */
    public function submit(array $units, array $langs): array
    {
        $langs = array_values(array_unique(array_filter($langs, static fn ($l): bool => $l !== '')));
        if ($langs === [] || $units === []) {
            return ['error' => null];
        }

        $payload = [
            'source_lang'  => $this->registry->getDefaultLanguage(),
            'target_langs' => $langs,
            'languages'    => $this->languageConfig($langs),
            'texts'        => array_map(static fn (array $u): array => [
                'text'     => $u['original'],
                'group_id' => $u['group_id'],
            ], $units),
        ];

        $res = $this->backend->submitTasks($payload);
        if (! $res['ok']) {
            return ['error' => $res['error']];
        }

        // Nothing is written on a failed submit -- stamping only happens once
        // the backend has actually accepted the batch.
        $this->repo->markPendingBatch(array_map(static fn (array $u) => $u['ref'], $units), $langs);

        return ['error' => null];
    }
}
