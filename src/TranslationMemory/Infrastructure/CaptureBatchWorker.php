<?php

namespace TransLangs\TranslationMemory\Infrastructure;

final class CaptureBatchWorker
{
    public function register(): void
    {
        add_action(CaptureBatchQueue::HOOK, [$this, 'handle'], 10, 1);
    }

    /**
     * Runs later in Action Scheduler, outside the frontend response.
     */
    public function handle(string $batchId): void
    {
        if (!$this->isValidBatchId($batchId)) {
            return;
        }

        $transient = CaptureBatchQueue::transientName($batchId);
        $payload   = get_transient($transient);

        delete_transient($transient);

        if (!is_array($payload)) {
            return;
        }

        /**
         * Later pipeline steps process gettext captures, rendered-html fallback
         * candidates, and observation metadata from this payload.
         */
    }

    private function isValidBatchId(string $batchId): bool
    {
        return (bool) preg_match('/\\A[a-f0-9-]{32,36}\\z/i', $batchId);
    }
}
