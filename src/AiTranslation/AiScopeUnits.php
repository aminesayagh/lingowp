<?php

namespace LingoWP\AiTranslation;

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Shared\Source\SourceRef;

/**
 * Resolves "what should AI translate" for a scope (text | collection | language)
 * into a flat list of source units, ready for AiTaskSubmitter.
 *
 * Split out of AiTranslationController so it can be exercised against a real DB
 * without standing up the controller's six collaborators (see
 * tests/db/ai_translate_scope_it.php); the controller keeps the HTTP half.
 *
 * The kinds handled here must stay in step with the board's own collections --
 * CollectionBoard::collectionRefs() is the mirror. A kind missing here is
 * invisible in a way nothing reports: those texts simply never get queued, and
 * the language's progress percentage can never reach 100.
 */
final class AiScopeUnits
{
    private WpDbSourceRepository $repo;

    public function __construct(WpDbSourceRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * Pending source units for a scope as [{ref, original, group_id, translated?, status?}].
     * 'text' translates only the one passed ref, regardless of its own status
     * (preserves "re-translate this one on demand") -- never siblings from the
     * same post, so a single-text click stays a single-item request no matter
     * how large the surrounding post is. 'collection'/'language' take only
     * missing units. Returns null on a malformed scope/id.
     *
     * 'text' also carries `previous` -- the unit's current translation per
     * language in $langs -- so the AI is asked for a different wording instead
     * of returning the same text again. Bulk scopes never need it: they only
     * take missing units, which have no translation yet.
     *
     * @param string[] $langs Target languages, read only by the 'text' scope.
     * @return array<int, array{ref:SourceRef, original:string, group_id:string, previous?:array<string,string>}>|null
     */
    public function forScope(string $scope, string $lang, string $collectionId = '', ?SourceRef $ref = null, array $langs = []): ?array
    {
        if ($scope === 'text') {
            if ($ref === null) {
                return null;
            }
            $context = $this->repo->loadSourceValidationContext($ref);
            if ($context === null) {
                return [];
            }

            $previous = [];
            foreach ($langs as $l) {
                $text = $this->repo->translatedText($ref, $l);
                if ($text !== null) {
                    $previous[$l] = $text;
                }
            }

            return [[
                'ref'      => $ref,
                'original' => $context['text'],
                'meta_key' => $context['meta_key'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a result array key, not a WP_Query argument.
                'group_id' => $ref->format(),
                'previous' => $previous,
            ]];
        }

        if ($scope === 'collection') {
            $units = $this->collectionUnits($collectionId, $lang);
            return $units === null ? null : $this->pending($units);
        }

        if ($scope === 'language') {
            return $this->pending($this->languageUnits($lang));
        }

        return null;
    }

    /**
     * Keep only untranslated units -- needs_review already has a translation
     * (a human may have started reviewing/editing it), so a bulk collection/
     * language translate must never silently overwrite it; that stays a
     * per-unit decision via the row's own AI button. Also drops anything
     * already queued (a fresh ai_pending_since) -- without this, a resubmit
     * of the same collection/language scope before the worker finishes
     * would keep re-resolving the exact same units, and a
     * >MAX_TEXTS_PER_SUBMIT collection's `more`-driven resubmit loop would
     * never converge (the same first slice forever, since none of them
     * transition out of "missing" until the async worker actually finishes).
     */
    private function pending(array $units): array
    {
        $now = time();
        return array_values(array_filter(
            $units,
            static fn (array $u): bool =>
                ($u['translated'] ?? null) === null
                && ! AiQueueConfig::isFreshPending($u['ai_pending_since'] ?? null, $now)
        ));
    }

    /**
     * Units of one collection id. ponytail: the id vocabulary is duplicated from
     * CollectionBoard -- acceptable for two call sites; centralize if a
     * third appears.
     *
     * @return array<int, array{ref:SourceRef, original:string, translated:?string, ai_pending_since:?string}>|null
     */
    private function collectionUnits(string $id, string $lang): ?array
    {
        if ($id === 'option:site') {
            return $this->unitsFromRows('option', $this->repo->listOptionTexts($lang), 'option:site');
        }
        if ($id === 'user:bios') {
            return $this->unitsFromRows('user', $this->repo->listUserTexts($lang), 'user:bios');
        }
        if ($id === 'html:all') {
            return $this->unitsFromRows('html', $this->repo->listHtmlTexts($lang), $this->htmlGroupId());
        }
        if ($id === 'media:all') {
            // Attachment title/caption/description live in the post tables, alt
            // text in meta -- the same split the board's media card shows.
            return array_merge(
                $this->unitsFromRows('post', $this->repo->listMediaPostTexts($lang), $this->postGroupId()),
                $this->unitsFromRows('meta', $this->repo->listMediaAltTexts($lang), $this->postGroupId())
            );
        }
        if (preg_match('/^content:([0-9]+)$/', $id, $m)) {
            $pid = (int) $m[1];
            return array_merge(
                $this->unitsFromRows('post', $this->repo->listPostTexts($pid, $lang), "content:{$pid}"),
                $this->unitsFromRows('meta', $this->repo->listMetaTexts($pid, $lang), "content:{$pid}")
            );
        }
        if (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            return array_merge(
                $this->unitsFromRows('term', $this->repo->listTaxonomyTexts($m[1], $lang), $this->termGroupId()),
                $this->unitsFromRows('term_meta', $this->repo->listTermMetaTexts($m[1], $lang), $this->termGroupId())
            );
        }
        return null;
    }

    /**
     * Every unit for a language: posts/meta/terms/term-meta/options/bios/html --
     * the sidebar's per-language "Translate All" always uses this.
     *
     * Flat reads only: one query per kind, plus two per taxonomy. Resolving this
     * post-by-post and term-by-term was an N+1 over the whole site, re-run from
     * scratch on every `more` resubmit.
     */
    private function languageUnits(string $lang): array
    {
        $units = array_merge(
            $this->unitsFromRows('option', $this->repo->listOptionTexts($lang), 'option:site'),
            $this->unitsFromRows('user', $this->repo->listUserTexts($lang), 'user:bios'),
            $this->unitsFromRows('html', $this->repo->listHtmlTexts($lang), $this->htmlGroupId()),
            $this->unitsFromRows('post', $this->repo->listAllPostTexts($lang), $this->postGroupId()),
            $this->unitsFromRows('meta', $this->repo->listAllMetaTexts($lang), $this->postGroupId())
        );

        // Terms go per taxonomy, not per term: listTermCollections() already
        // names every taxonomy in play, and a site has a handful of those where
        // it may have thousands of terms.
        $taxonomies = array_unique(array_map(
            static fn (array $t): string => (string) $t['taxonomy'],
            $this->repo->listTermCollections($lang)
        ));
        foreach ($taxonomies as $taxonomy) {
            $units = array_merge(
                $units,
                $this->unitsFromRows('term', $this->repo->listTaxonomyTexts($taxonomy, $lang), $this->termGroupId()),
                $this->unitsFromRows('term_meta', $this->repo->listTermMetaTexts($taxonomy, $lang), $this->termGroupId())
            );
        }

        return $units;
    }

    /** Post + post-meta rows from a flat read carry their own post_id. */
    private function postGroupId(): callable
    {
        return static fn (array $r): string => 'content:' . (string) ($r['post_id'] ?? '');
    }

    /** Term + term-meta rows carry term_id (listTaxonomyTexts/listTermMetaTexts). */
    private function termGroupId(): callable
    {
        return static fn (array $r): string => 'term:' . (string) ($r['term_id'] ?? '');
    }

    /** html rows have no post link, so group by crawl source when known; a shared
     *  fallback bucket for url-less rows still gets sub-chunked correctly downstream. */
    private function htmlGroupId(): callable
    {
        return static fn (array $r): string =>
            isset($r['source_url']) && $r['source_url'] !== ''
                ? 'html:' . md5((string) $r['source_url'])
                : 'html:all';
    }

    /**
     * @param string|callable(array $r):string $groupId constant string, or a
     *        per-row callable when the group varies per row (html: by source_url;
     *        post/term rows from a flat read: by their own id column)
     * @return array<int, array{ref:SourceRef, original:string, translated:?string, ai_pending_since:?string, meta_key:?string, group_id:string}>
     */
    private function unitsFromRows(string $kind, array $rows, $groupId): array
    {
        // meta and term_meta rows already carry the key as `field` (listMetaTexts /
        // listTermMetaTexts); keeping it on the unit is what lets the write guard
        // pick an SEO variable grammar without a per-item query inside the result loop.
        $hasMetaKey = $kind === 'meta' || $kind === 'term_meta';

        return array_map(function (array $r) use ($kind, $groupId, $hasMetaKey): array {
            return [
                'ref'              => SourceRef::make($kind, (int) $r['id']),
                'original'         => (string) $r['original_text'],
                'translated'       => $r['translated_text'],
                'ai_pending_since' => $r['ai_pending_since'] ?? null,
                'meta_key'         => $hasMetaKey ? (string) ($r['field'] ?? '') : null, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a result array key, not a WP_Query argument.
                'group_id'         => is_string($groupId) ? $groupId : $groupId($r),
            ];
        }, $rows);
    }
}
