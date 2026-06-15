<?php

namespace TransLangs\TranslationMemory\Infrastructure;

use TransLangs\TranslationMemory\Domain\GettextSource;
use TransLangs\TranslationMemory\Domain\SourceTranslationRepository;

/**
 * Off-request writer for discovered gettext (type-4) strings. GettextResolver
 * buffers candidates during a frontend render and, at shutdown, enqueues ONE
 * Action Scheduler job — the bulk INSERT runs here in the worker, never at
 * shutdown (managed hosts kill the process on response flush).
 *
 * The payload (a page's worth of strings) can exceed Action Scheduler's 8000-char
 * arg limit, so it is parked in a transient and only a short key rides the action
 * arg. The worker reads the transient and deletes it.
 */
final class GettextCaptureWorker
{
    public const HOOK = 'translangs_capture_gettext';

    private const TRANSIENT_PREFIX = 'tl_gx_';

    public function __construct(private SourceTranslationRepository $repository) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
    }

    /**
     * @param array<int, array{domain: string, context: ?string, text: string}> $payload
     */
    public static function enqueue(array $payload): void
    {
        if ($payload === [] || !function_exists('as_enqueue_async_action') || !function_exists('set_transient')) {
            return; // Action Scheduler not loaded → capture is best-effort, skip.
        }

        $key = self::TRANSIENT_PREFIX . uniqid('', true);
        set_transient($key, $payload, HOUR_IN_SECONDS);

        as_enqueue_async_action(self::HOOK, [$key], 'translangs');
    }

    /**
     * @param string $key transient holding the captured payload
     */
    public function run($key = ''): void
    {
        $key = (string) $key;
        if ($key === '' || !function_exists('get_transient')) {
            return;
        }

        $payload = get_transient($key);
        if (function_exists('delete_transient')) {
            delete_transient($key);
        }
        if (!is_array($payload)) {
            return;
        }

        $records = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = (string) ($row['text'] ?? '');
            if ($text === '') {
                continue;
            }
            $context = isset($row['context']) ? (string) $row['context'] : null;
            $records[] = new GettextSource((string) ($row['domain'] ?? ''), $context, $text);
        }

        if ($records !== []) {
            $this->repository->upsertSources($records);
        }
    }
}
