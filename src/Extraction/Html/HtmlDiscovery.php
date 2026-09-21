<?php

namespace LingoWP\Extraction\Html;

use LingoWP\Shared\Source\Type\HtmlSource;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Extraction\OwnedStringIndex;
use LingoWP\Resolution\HtmlRenderer;

final class HtmlDiscovery
{
    private HtmlRenderer $htmlRenderer;
    private OwnedStringIndex $ownedIndex;
    private WpDbSourceRepository $repository;

    public function __construct(
        HtmlRenderer $htmlRenderer,
        OwnedStringIndex $ownedIndex,
        WpDbSourceRepository $repository
    ) {
        $this->htmlRenderer = $htmlRenderer;
        $this->ownedIndex   = $ownedIndex;
        $this->repository   = $repository;
    }

    public function discover(string $html, ?string $sourceUrl): void
    {
        $units = [];

        $this->htmlRenderer->render($html, [], static function (string $selector, string $text) use (&$units, $sourceUrl): void {
            $units[$selector . "\0" . $text] = new HtmlSource($selector, $sourceUrl, $text);
        });

        if ($units === []) {
            return;
        }

        $candidates = [];
        foreach ($units as $unit) {
            $candidates[bin2hex($unit->originalHash())] ??= $unit;
        }

        $hashes = array_map(static fn(HtmlSource $s): string => $s->originalHash(), $candidates);
        $owned  = $this->ownedIndex->ownedHexes($hashes);

        $records = array_values(array_filter(
            $candidates,
            static fn(string $hex): bool => !isset($owned[$hex]),
            ARRAY_FILTER_USE_KEY
        ));

        if ($records !== []) {
            $this->repository->upsertSources($records);
        }

        $this->repository->touchHtmlSeen($hashes);
    }

    public function flagStale(int $graceDays): int
    {
        return $this->repository->flagStaleHtmlSources($graceDays);
    }
}
