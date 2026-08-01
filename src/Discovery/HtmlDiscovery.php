<?php

namespace TransLangs\Discovery;

use TransLangs\Core\Source\Type\HtmlSource;
use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Repository\OwnedStringIndex;
use TransLangs\Rendering\Html\HtmlRenderer;

/**
 * Shared hardcoded-HTML discovery walk: mints a tl_html parent for each
 * block-level unit no structured source already owns, and refreshes
 * last_seen_at for every candidate hash the walk resolves.
 *
 * The one piece shared by PublicRenderPipeline (marking mode: a logged-in
 * editor viewing a source-language page) and HtmlDiscoveryCrawl (the
 * background sweep of known public URLs) — neither duplicates the
 * walk/ownership/insert logic, so a string is discovered and kept "seen" the
 * same way regardless of which caller encountered it.
 */
final class HtmlDiscovery
{
    public function __construct(
        private HtmlRenderer $htmlRenderer,
        private OwnedStringIndex $ownedIndex,
        private SourceTranslationRepository $repository
    ) {
    }

    /**
     * @param string      $html      final rendered page HTML (or fragment) to walk
     * @param string|null $sourceUrl page URL, stored on newly minted tl_html rows
     */
    public function discover(string $html, ?string $sourceUrl): void
    {
        $units = [];

        $this->htmlRenderer->render($html, [], static function (string $selector, string $text) use (&$units, $sourceUrl): void {
            $units[$selector . "\0" . $text] = new HtmlSource($selector, $sourceUrl, $text);
        });

        if ($units === []) {
            return;
        }

        $hashes = array_map(static fn(HtmlSource $s): string => $s->originalHash(), $units);
        $owned  = $this->ownedIndex->ownedHexes($hashes);

        $records = array_values(array_filter(
            $units,
            static fn(HtmlSource $s): bool => !isset($owned[bin2hex($s->originalHash())])
        ));

        if ($records !== []) {
            $this->repository->upsertSources($records);
        }

        // Refresh last_seen_at for every candidate hash this pass touched that
        // has a tl_html row — the inserts above and any pre-existing html-owned
        // matches alike. Hashes owned by post/meta/term simply match no row in
        // tl_html (those tables have their own save-time lifecycle and carry no
        // staleness tracking), so this scopes itself correctly with no branch.
        $this->repository->touchHtmlSeen($hashes);
    }

    /**
     * Flag tl_html rows nobody has re-encountered in over $graceDays as stale,
     * for admin review. Called by HtmlDiscoveryCrawl once a full sweep
     * completes — see WpDbSourceTranslationRepository::flagStaleHtmlSources().
     */
    public function flagStale(int $graceDays): int
    {
        return $this->repository->flagStaleHtmlSources($graceDays);
    }
}
