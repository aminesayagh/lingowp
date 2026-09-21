<?php

namespace LingoWP\AiTranslation;

use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Shared\Source\SourceRef;

final class AiScopeUnits
{
    private WpDbSourceRepository $repo;

    public function __construct(WpDbSourceRepository $repo)
    {
        $this->repo = $repo;
    }

    public function forScope(string $scope, string $lang, string $collectionId = '', ?SourceRef $ref = null): ?array
    {
        if ($scope === 'text') {
            if ($ref === null) {
                return null;
            }
            $context = $this->repo->loadSourceValidationContext($ref);
            if ($context === null) {
                return [];
            }

            return [[
                'ref'      => $ref,
                'original' => $context['text'],
                'meta_key' => $context['meta_key'],
                'group_id' => $ref->format(),
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

    private function languageUnits(string $lang): array
    {
        $units = array_merge(
            $this->unitsFromRows('option', $this->repo->listOptionTexts($lang), 'option:site'),
            $this->unitsFromRows('user', $this->repo->listUserTexts($lang), 'user:bios'),
            $this->unitsFromRows('html', $this->repo->listHtmlTexts($lang), $this->htmlGroupId()),
            $this->unitsFromRows('post', $this->repo->listAllPostTexts($lang), $this->postGroupId()),
            $this->unitsFromRows('meta', $this->repo->listAllMetaTexts($lang), $this->postGroupId())
        );

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

    private function postGroupId(): callable
    {
        return static fn (array $r): string => 'content:' . (string) ($r['post_id'] ?? '');
    }

    private function termGroupId(): callable
    {
        return static fn (array $r): string => 'term:' . (string) ($r['term_id'] ?? '');
    }

    private function htmlGroupId(): callable
    {
        return static fn (array $r): string =>
            isset($r['source_url']) && $r['source_url'] !== ''
                ? 'html:' . md5((string) $r['source_url'])
                : 'html:all';
    }

    private function unitsFromRows(string $kind, array $rows, $groupId): array
    {
        $hasMetaKey = $kind === 'meta' || $kind === 'term_meta';

        return array_map(function (array $r) use ($kind, $groupId, $hasMetaKey): array {
            return [
                'ref'              => SourceRef::make($kind, (int) $r['id']),
                'original'         => (string) $r['original_text'],
                'translated'       => $r['translated_text'],
                'ai_pending_since' => $r['ai_pending_since'] ?? null,
                'meta_key'         => $hasMetaKey ? (string) ($r['field'] ?? '') : null,
                'group_id'         => is_string($groupId) ? $groupId : $groupId($r),
            ];
        }, $rows);
    }
}
