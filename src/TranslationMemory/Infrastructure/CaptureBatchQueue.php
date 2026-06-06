<?php

namespace TransLangs\TranslationMemory\Infrastructure;

final class CaptureBatchQueue
{
    public const HOOK             = 'translangs_process_capture_batch';
    public const GROUP            = 'translangs';
    public const TRANSIENT_PREFIX = 'translangs_capture_batch_';
    public const TRANSIENT_TTL    = 3600;

    /**
     * Called near request shutdown after render/gettext capture has collected a batch.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueue(array $payload, int $priority = 10): ?int
    {
        if (!function_exists('as_enqueue_async_action')) {
            return null;
        }

        // Keep the heavy payload out of the scheduled action row; the job receives only this ID.
        $batchId = $this->newBatchId();
        $stored  = set_transient(
            self::transientName($batchId),
            $payload,
            self::TRANSIENT_TTL
        );

        if (!$stored) {
            return null;
        }

        $actionId = as_enqueue_async_action(
            self::HOOK,
            ['batch_id' => $batchId],
            self::GROUP,
            true,
            $priority
        );

        if ((int) $actionId <= 0) {
            delete_transient(self::transientName($batchId));
            return null;
        }

        return (int) $actionId;
    }

    public static function transientName(string $batchId): string
    {
        return self::TRANSIENT_PREFIX . $batchId;
    }

    private function newBatchId(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return md5(uniqid('', true));
        }
    }
}
