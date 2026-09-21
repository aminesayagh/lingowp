<?php

namespace LingoWP\AiTranslation;

use LingoWP\Backend\TranslationBackendPort;
use LingoWP\Database\Repository\WpDbSourceRepository;

final class AiResultCollector
{
    private TranslationBackendPort $backend;
    private WpDbSourceRepository $repo;

    public function __construct(TranslationBackendPort $backend, WpDbSourceRepository $repo)
    {
        $this->backend = $backend;
        $this->repo    = $repo;
    }

    public function collectOnce(): void
    {
        $cursor = 0;

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

            $ackItems = [];
            foreach ($results as $r) {
                $hashHex = (string) ($r['source_hash'] ?? '');
                $lang    = (string) ($r['target_lang'] ?? '');
                if ($hashHex === '' || $lang === '') {
                    continue;
                }
                $this->repo->applyQueueResult($hashHex, $lang, $r['output'] ?? null, $r['error'] ?? null);
                $ackItems[] = ['source_hash' => $hashHex, 'target_lang' => $lang];
            }

            if ($ackItems !== []) {
                $this->backend->ackResults($ackItems);
            }

            $cursor  = (int) ($data['next_cursor'] ?? $cursor);
            $hasMore = (bool) ($data['has_more'] ?? false);
        } while ($hasMore);
    }
}
