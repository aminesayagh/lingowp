<?php

namespace LingoWP\Backend;

/**
 * The translate-all queue's view of the backend, extracted purely so tests
 * can inject a fake instead of touching wp_remote_request — BackendClient is
 * the only production implementation. See AiTaskSubmitter/AiResultCollector.
 */
interface TranslationBackendPort
{
    /**
     * POST /api/v1/translations/tasks — queue-and-return. Payload:
     *   { source_lang, target_langs:[], languages:{lang:{provider, writing_style?}}, texts:[{text, group_id}] }
     * Success data: { queued:int, skipped_no_words:int }.
     */
    public function submitTasks(array $payload): array;

    /**
     * GET /api/v1/translations/results?cursor= — this site's finished rows,
     * id > cursor, 200/page. Does not change state. Success data:
     *   { results:[{source_hash, target_lang, output, error}], next_cursor:int, has_more:bool }
     */
    public function pullResults(int $cursor = 0): array;

    /**
     * POST /api/v1/translations/results/ack — deletes the given rows for
     * this site. Idempotent. Payload: { items:[{source_hash, target_lang}] }.
     * Success data: { deleted:int }.
     */
    public function ackResults(array $items): array;
}
