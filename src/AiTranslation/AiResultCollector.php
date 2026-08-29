<?php

namespace LingoWP\AiTranslation;

use LingoWP\Backend\TranslationBackendPort;
use LingoWP\Database\Repository\WpDbSourceRepository;

/**
 * Pull whatever the async translate-all queue has finished, apply each
 * result, ack what was applied. Triggered inline from the board's own poll
 * endpoints (CollectionsRestController), rate-limited there -- there is no
 * background sweep and no push callback (see decision log).
 *
 * A failed pull or ack is logged and dropped: the row survives on the
 * backend and the next board load collects it. Nothing here retries.
 */
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
