<?php

namespace TransLangs\Discovery\Infrastructure\StaticText;

use TransLangs\Core\Source\GettextSource;
use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Repository\OwnedStringIndex;

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
    public const HOOK        = 'translangs_capture_gettext';
    public const HOOK_PLURAL = 'translangs_capture_gettext_plural';

    private const TRANSIENT_PREFIX        = 'tl_gx_';
    private const TRANSIENT_PREFIX_PLURAL = 'tl_gxp_';

    public function __construct(
        private SourceTranslationRepository $repository,
        private OwnedStringIndex $ownedIndex
    ) {}

    public function register(): void
    {
        add_action(self::HOOK, [$this, 'run']);
        add_action(self::HOOK_PLURAL, [$this, 'runPlural']);
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
     * @param array<int, array{domain: string, context: ?string, single: string, plural: string}> $payload
     */
    public static function enqueuePlural(array $payload): void
    {
        if ($payload === [] || !function_exists('as_enqueue_async_action') || !function_exists('set_transient')) {
            return;
        }

        $key = self::TRANSIENT_PREFIX_PLURAL . uniqid('', true);
        set_transient($key, $payload, HOUR_IN_SECONDS);

        as_enqueue_async_action(self::HOOK_PLURAL, [$key], 'translangs');
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

        // Tier-2 ownership gate: drop any string a structured source already owns,
        // so the same text is never stored as both structured and static.
        $records = $this->rejectOwned($records);

        if ($records !== []) {
            $this->repository->upsertSources($records);
        }
    }

    /**
     * Worker for gettext plural messages. Builds (single, plural) candidates and
     * upserts them; the singular anchor goes through the same TranslatableText gate
     * as any gettext string. Page-ownership was already applied at capture time.
     *
     * @param string $key transient holding the captured plural payload
     */
    public function runPlural($key = ''): void
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

        $candidates = [];
        foreach ($payload as $row) {
            if (!is_array($row)) {
                continue;
            }
            $single = (string) ($row['single'] ?? '');
            $plural = (string) ($row['plural'] ?? '');
            if ($single === '' || $plural === '') {
                continue;
            }
            $candidates[] = [
                'domain'  => (string) ($row['domain'] ?? ''),
                'context' => isset($row['context']) ? (string) $row['context'] : null,
                'single'  => $single,
                'plural'  => $plural,
            ];
        }

        if ($candidates !== []) {
            $this->repository->upsertPluralSources($candidates);
        }
    }

    /**
     * @param GettextSource[] $records
     * @return GettextSource[]
     */
    private function rejectOwned(array $records): array
    {
        if ($records === []) {
            return [];
        }

        $owned = $this->ownedIndex->ownedHexes(
            array_map(static fn(GettextSource $r): string => $r->originalHash(), $records)
        );

        return array_values(array_filter(
            $records,
            static fn(GettextSource $r): bool => !isset($owned[bin2hex($r->originalHash())])
        ));
    }
}
