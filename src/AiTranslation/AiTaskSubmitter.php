<?php

namespace LingoWP\AiTranslation;

use LingoWP\Backend\TranslationBackendPort;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Infrastructure\LanguageMetadataStore;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

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

    public function submit(array $units, array $langs): array
    {
        $langs = array_values(array_unique(array_filter($langs, static fn ($l): bool => $l !== '')));
        if ($langs === [] || $units === []) {
            return ['error' => null];
        }

        $payload = [
            'source_lang'  => $this->registry->getDefaultLanguage(),
            'target_langs' => $langs,
            'languages'    => $this->metadata->providerConfig($langs),
            'texts'        => array_map(static fn (array $u): array => [
                'text'     => $u['original'],
                'group_id' => $u['group_id'],
            ], $units),
        ];

        $res = $this->backend->submitTasks($payload);
        if (! $res['ok']) {
            return ['error' => $res['error']];
        }

        $this->repo->markPendingBatch(array_map(static fn (array $u) => $u['ref'], $units), $langs);

        return ['error' => null];
    }
}
