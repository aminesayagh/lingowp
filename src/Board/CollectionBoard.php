<?php

namespace LingoWP\Board;

use LingoWP\AiTranslation\AiQueueConfig;
use LingoWP\Shared\Source\Type\HtmlSource;
use LingoWP\Shared\Source\TranslatableMetaKeys;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;

final class CollectionBoard
{
    private WpDbSourceRepository $repo;
    private LanguageRegistry $languages;

    public function __construct(WpDbSourceRepository $repo, LanguageRegistry $languages)
    {
        $this->repo      = $repo;
        $this->languages = $languages;
    }

    public function buildCollections(string $lang, string $search, string $source = 'content', array $filters = []): array
    {
        $collections = [];

        $option = $this->repo->optionCollectionSummary($lang, $search, $filters);
        if ($option['total'] > 0) {
            $collections[] = [
                'id'             => 'option:site',
                'label'          => __('Site settings', 'lingowp'),
                'type'           => 'site_settings',
                'preview_url'    => admin_url('options-general.php'),
                'preview_target' => 'admin',
            ] + $option;
        }

        $userBios = $this->repo->userCollectionSummary($lang, $search, $filters);
        if ($userBios['total'] > 0) {
            $collections[] = [
                'id'             => 'user:bios',
                'label'          => __('Author bios', 'lingowp'),
                'type'           => 'author_bios',
                'preview_url'    => admin_url('users.php'),
                'preview_target' => 'admin',
            ] + $userBios;
        }

        $byPost = [];
        foreach ($this->repo->listPostCollections($lang, $search, $filters) as $c) {
            $byPost[$c['post_id']] = $c;
        }
        foreach ($this->repo->postMetaCounts($lang, $search, $filters) as $m) {
            $pid = $m['post_id'];
            if (! isset($byPost[$pid])) {
                $byPost[$pid] = $m;
                continue;
            }
            foreach (['total', 'translated', 'needs_review', 'pending', 'missing'] as $k) {
                $byPost[$pid][$k] += $m[$k];
            }
        }
        $contentRows = [];
        $mediaTotals = ['total' => 0, 'translated' => 0, 'needs_review' => 0, 'pending' => 0, 'missing' => 0];
        foreach ($byPost as $pid => $c) {
            $pid = (int) $pid;

            $isImageAttachment = $c['post_type'] === 'attachment'
                && str_starts_with((string) ($c['post_mime_type'] ?? ''), 'image/');
            if ($isImageAttachment) {
                foreach (array_keys($mediaTotals) as $k) {
                    $mediaTotals[$k] += $c[$k];
                }
                continue;
            }

            $isFront    = $this->isFrontPage($pid, (string) $c['post_type'], (string) get_post_field('post_name', $pid));
            $isMedia    = $c['post_type'] === 'attachment';
            $permalink  = get_permalink($pid);

            if ($isFront) {
                $previewUrl    = $this->frontendPreviewUrl(home_url('/'), $lang);
                $previewTarget = 'frontend';
            } elseif ($isMedia) {
                $previewUrl    = admin_url('post.php?post=' . $pid . '&action=edit');
                $previewTarget = 'admin';
            } else {
                $previewUrl    = $permalink === false ? null : $this->frontendPreviewUrl($permalink, $lang);
                $previewTarget = 'frontend';
            }

            $row = [
                'id'             => 'content:' . $pid,
                'label'          => $c['post_title'] !== '' ? $c['post_title'] : __('(no title)', 'lingowp'),
                'type'           => $isFront ? 'page' : $this->uxType($c['post_type']),
                'total'          => $c['total'],
                'translated'     => $c['translated'],
                'needs_review'   => $c['needs_review'],
                'pending'        => $c['pending'],
                'missing'        => $c['missing'],
                'preview_url'    => $previewUrl,
                'preview_target' => $previewTarget,
            ];

            if ($isFront) {
                array_unshift($contentRows, $row);
            } else {
                $contentRows[] = $row;
            }
        }
        foreach ($contentRows as $row) {
            $collections[] = $row;
        }

        if ($mediaTotals['total'] > 0) {
            $collections[] = [
                'id'             => 'media:all',
                'label'          => __('Media', 'lingowp'),
                'type'           => 'media',
                'preview_url'    => admin_url('upload.php'),
                'preview_target' => 'admin',
            ] + $mediaTotals;
        }

        $byTax = [];
        foreach ($this->repo->listTermCollections($lang, $search, $filters) as $t) {
            $tax = $t['taxonomy'];
            if (! isset($byTax[$tax])) {
                $byTax[$tax] = ['total' => 0, 'translated' => 0, 'needs_review' => 0, 'pending' => 0, 'missing' => 0];
            }
            foreach (['total', 'translated', 'needs_review', 'pending', 'missing'] as $k) {
                $byTax[$tax][$k] += $t[$k];
            }
        }
        foreach ($this->repo->termMetaCounts($lang, $search, $filters) as $m) {
            $tax = $m['taxonomy'];
            if (! isset($byTax[$tax])) {
                $byTax[$tax] = ['total' => 0, 'translated' => 0, 'needs_review' => 0, 'pending' => 0, 'missing' => 0];
            }
            foreach (['total', 'translated', 'needs_review', 'pending', 'missing'] as $k) {
                $byTax[$tax][$k] += $m[$k];
            }
        }
        foreach ($byTax as $tax => $c) {
            $collections[] = [
                'id'             => 'taxonomy:' . $tax,
                'label'          => $this->taxonomyLabel($tax),
                'type'           => 'taxonomy',
                'preview_url'    => admin_url('edit-tags.php?taxonomy=' . urlencode($tax)),
                'preview_target' => 'admin',
            ] + $c;
        }

        $html = $this->repo->htmlCollectionSummary($lang, $search, $filters);
        if ($html['total'] > 0) {
            $collections[] = [
                'id'             => 'html:all',
                'label'          => __('Template content', 'lingowp'),
                'type'           => 'html',
                'preview_url'    => null,
                'preview_target' => 'frontend',
            ] + $html;
        }

        return $collections;
    }

    public function collectionTexts(string $id, string $lang, string $search, array $filters = [], bool $withFallback = true): ?array
    {
        if ($id === 'ignored:all') {
            return array_map(
                fn (array $r): array => $this->presentText($r['kind'], $r, (string) $r['field'])
                    + ['ignored' => true, 'preview_url' => null, 'preview_target' => 'admin'],
                $this->repo->listIgnoredTexts($search)
            );
        }

        if ($id === 'option:site') {
            $optionPreview = ['preview_url' => admin_url('options-general.php'), 'preview_target' => 'admin'];
            $rows          = $this->repo->listOptionTexts($lang, $search, $filters);
            $fallbacks     = $withFallback ? $this->fallbacksByHash('option', $rows, $lang) : [];
            return array_map(
                fn (array $r): array => $this->presentText('option', $r, (string) $r['option_name'], $fallbacks[$r['original_hash']] ?? null) + $optionPreview,
                $rows
            );
        }

        if ($id === 'user:bios') {
            $userPreview = ['preview_url' => admin_url('users.php'), 'preview_target' => 'admin'];
            $rows        = $this->repo->listUserTexts($lang, $search, $filters);
            $fallbacks   = $withFallback ? $this->fallbacksByHash('user', $rows, $lang) : [];
            return array_map(
                fn (array $r): array => $this->presentText('user', $r, (string) $r['display_name'], $fallbacks[$r['original_hash']] ?? null) + $userPreview,
                $rows
            );
        }

        if ($id === 'html:all') {
            $rows      = $this->repo->listHtmlTexts($lang, $search, $filters);
            $fallbacks = $withFallback ? $this->fallbacksByHash('html', $rows, $lang) : [];
            return array_map(
                fn (array $r): array => $this->presentText('html', $r, (string) $r['field'], $fallbacks[$r['original_hash']] ?? null) + [
                    'preview_url'    => $this->htmlPreviewUrl($r['source_url'] ?? null, $lang),
                    'preview_target' => 'frontend',
                ],
                $rows
            );
        }

        if (preg_match('/^content:([0-9]+)$/', $id, $m)) {
            $postId = (int) $m[1];
            $post   = get_post($postId);
            if ($post && $this->isFrontPage($postId, $post->post_type, $post->post_name)) {
                $preview = [
                    'preview_url'    => $this->frontendPreviewUrl(home_url('/'), $lang),
                    'preview_target' => 'frontend',
                ];
            } elseif (get_post_type($postId) === 'attachment') {
                $preview = [
                    'preview_url'    => admin_url('post.php?post=' . $postId . '&action=edit'),
                    'preview_target' => 'admin',
                ];
            } else {
                $permalink = get_permalink($postId);
                $preview   = [
                    'preview_url'    => $permalink === false ? null : $this->frontendPreviewUrl($permalink, $lang),
                    'preview_target' => 'frontend',
                ];
            }
            $postRows      = $this->repo->listPostTexts($postId, $lang, $search, $filters);
            $postFallbacks = $withFallback ? $this->fallbacksByHash('post', $postRows, $lang) : [];
            $posts         = array_map(
                fn (array $r): array => $this->presentText('post', $r, (string) $r['field'], $postFallbacks[$r['original_hash']] ?? null) + $preview,
                $postRows
            );
            $metaRows      = $this->repo->listMetaTexts($postId, $lang, $search, $filters);
            $metaFallbacks = $withFallback ? $this->fallbacksByParentId($metaRows, $lang) : [];
            $metas         = array_map(
                fn (array $r): array => $this->presentText('meta', $r, (string) $r['field'], $metaFallbacks[(int) $r['id']] ?? null) + $preview,
                $metaRows
            );
            return array_merge($posts, $metas);
        }

        if ($id === 'media:all') {
            $postRows      = $this->repo->listMediaPostTexts($lang, $search, $filters);
            $postFallbacks = $withFallback ? $this->fallbacksByHash('post', $postRows, $lang) : [];
            $posts         = array_map(
                fn (array $r): array => $this->presentText('post', $r, (string) $r['field'], $postFallbacks[$r['original_hash']] ?? null) + $this->mediaPreview($r),
                $postRows
            );
            $altRows       = $this->repo->listMediaAltTexts($lang, $search, $filters);
            $altFallbacks  = $withFallback ? $this->fallbacksByParentId($altRows, $lang) : [];
            $alts          = array_map(
                fn (array $r): array => $this->presentText('meta', $r, (string) $r['field'], $altFallbacks[(int) $r['id']] ?? null) + $this->mediaPreview($r),
                $altRows
            );
            return array_merge($posts, $alts);
        }

        if (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            $taxonomy = $m[1];
            $preview  = fn (int $termId): array => [
                'preview_url'    => admin_url('term.php?taxonomy=' . urlencode($taxonomy) . '&tag_ID=' . $termId),
                'preview_target' => 'admin',
            ];

            $rows      = $this->repo->listTaxonomyTexts($taxonomy, $lang, $search, $filters);
            $fallbacks = $withFallback ? $this->fallbacksByHash('term', $rows, $lang) : [];
            $terms     = array_map(
                fn (array $r): array => $this->presentText('term', $r, (string) $r['field'], $fallbacks[$r['original_hash']] ?? null)
                    + $preview((int) $r['term_id']),
                $rows
            );

            $metaRows      = $this->repo->listTermMetaTexts($taxonomy, $lang, $search, $filters);
            $metaFallbacks = $withFallback ? $this->fallbacksByParentId($metaRows, $lang, 'term_meta') : [];
            $metas         = array_map(
                fn (array $r): array => $this->presentText('term_meta', $r, (string) $r['field'], $metaFallbacks[(int) $r['id']] ?? null)
                    + $preview((int) $r['term_id']),
                $metaRows
            );

            return array_merge($terms, $metas);
        }

        return null;
    }

    public function summaryFor(string $id, string $lang): ?array
    {
        $texts = $this->collectionTexts($id, $lang, '', [], false);
        if ($texts === null) {
            return null;
        }

        $summary = ['id' => $id, 'total' => count($texts), 'translated' => 0, 'needs_review' => 0, 'pending' => 0, 'missing' => 0];
        foreach ($texts as $t) {
            if ($t['status'] === 'pending') {
                $summary['pending']++;
            } elseif ($t['status'] === 'missing') {
                $summary['missing']++;
            } elseif ($t['status'] === 'needs_review') {
                $summary['needs_review']++;
            } else {
                $summary['translated']++;
            }
        }

        return $summary;
    }

    public function unitAndSummaryFor(string $id, string $lang, string $refFormatted): array
    {
        $texts = $this->collectionTexts($id, $lang, '', [], false);
        if ($texts === null) {
            return ['unit' => null, 'summary' => null];
        }

        $unit    = null;
        $summary = ['id' => $id, 'total' => count($texts), 'translated' => 0, 'needs_review' => 0, 'pending' => 0, 'missing' => 0];
        foreach ($texts as $t) {
            if ($t['id'] === $refFormatted) {
                $unit = $t;
            }
            if ($t['status'] === 'pending') {
                $summary['pending']++;
            } elseif ($t['status'] === 'missing') {
                $summary['missing']++;
            } elseif ($t['status'] === 'needs_review') {
                $summary['needs_review']++;
            } else {
                $summary['translated']++;
            }
        }

        return ['unit' => $unit, 'summary' => $summary];
    }

    public function collectionRefs(string $id, string $lang): ?array
    {
        if ($id === 'option:site') {
            return array_map(
                static fn (array $u): SourceRef => SourceRef::make('option', (int) $u['id']),
                $this->repo->listOptionTexts($lang)
            );
        }
        if ($id === 'user:bios') {
            return array_map(
                static fn (array $u): SourceRef => SourceRef::make('user', (int) $u['id']),
                $this->repo->listUserTexts($lang)
            );
        }
        if ($id === 'html:all') {
            return array_map(
                static fn (array $u): SourceRef => SourceRef::make('html', (int) $u['id']),
                $this->repo->listHtmlTexts($lang)
            );
        }
        if (preg_match('/^content:([0-9]+)$/', $id, $m)) {
            $postId = (int) $m[1];
            $posts  = array_map(
                static fn (array $u): SourceRef => SourceRef::make('post', (int) $u['id']),
                $this->repo->listPostTexts($postId, $lang)
            );
            $metas = array_map(
                static fn (array $u): SourceRef => SourceRef::make('meta', (int) $u['id']),
                $this->repo->listMetaTexts($postId, $lang)
            );
            return array_merge($posts, $metas);
        }
        if ($id === 'media:all') {
            $posts = array_map(
                static fn (array $u): SourceRef => SourceRef::make('post', (int) $u['id']),
                $this->repo->listMediaPostTexts($lang)
            );
            $alts = array_map(
                static fn (array $u): SourceRef => SourceRef::make('meta', (int) $u['id']),
                $this->repo->listMediaAltTexts($lang)
            );
            return array_merge($posts, $alts);
        }
        if (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            $terms = array_map(
                static fn (array $u): SourceRef => SourceRef::make('term', (int) $u['id']),
                $this->repo->listTaxonomyTexts($m[1], $lang)
            );
            $metas = array_map(
                static fn (array $u): SourceRef => SourceRef::make('term_meta', (int) $u['id']),
                $this->repo->listTermMetaTexts($m[1], $lang)
            );
            return array_merge($terms, $metas);
        }
        return null;
    }

    public function presentText(string $kind, array $row, string $field, ?string $fallbackText = null): array
    {
        $translated = $row['translated_text'];
        $status     = $row['status'];
        $provider   = $row['provider'];
        $original   = (string) $row['original_text'];
        $isPending  = AiQueueConfig::isFreshPending($row['ai_pending_since'] ?? null, time());

        if ($isPending) {
            $statusLabel = 'pending';
        } elseif ($translated === null) {
            $statusLabel = 'missing';
        } elseif ($status === TranslationStatus::REVIEWED) {
            $statusLabel = 'reviewed';
        } elseif ($status === TranslationStatus::NEEDS_REVIEW) {
            $statusLabel = 'needs_review';
        } else {
            $statusLabel = 'translated';
        }

        $isAi = $translated !== null && $status === TranslationStatus::MACHINE && $provider !== null;

        return [
            'id'            => SourceRef::make($kind, (int) $row['id'])->format(),
            'field'         => $field,
            'seo_field'     => TranslatableMetaKeys::seoFieldType($field),
            'original'      => $original,
            'translated'    => $translated,
            'fallback'      => $fallbackText ?? $original,
            'status'        => $statusLabel,
            'ai_error'      => $row['ai_error'] ?? null,
            'origin'        => $translated === null ? null : ($provider !== null ? 'ai' : 'manual'),
            'is_ai'         => $isAi,
            'word_count'    => str_word_count(wp_strip_all_tags($original)),
            'char_count'    => mb_strlen($original),
            'original_hash' => $row['original_hash'] ?? null,
        ];
    }

    private function fallbacksByHash(string $tableKey, array $rows, string $lang): array
    {
        $pending = [];
        foreach ($rows as $r) {
            if ($r['translated_text'] === null && ! empty($r['original_hash'])) {
                $pending[(string) $r['original_hash']] = true;
            }
        }

        $resolved = [];
        foreach ($this->languages->fallbackChain($lang) as $fallbackLang) {
            if ($pending === []) {
                break;
            }
            $hashes = array_map('hex2bin', array_keys($pending));
            foreach ($this->repo->fetchHashTranslations($tableKey, $hashes, $fallbackLang) as $hex => $text) {
                if ($text !== null && isset($pending[$hex])) {
                    $resolved[$hex] = $text;
                    unset($pending[$hex]);
                }
            }
        }

        return $resolved;
    }

    private function fallbacksByParentId(array $rows, string $lang, string $tableKey = 'meta'): array
    {
        $pending = [];
        foreach ($rows as $r) {
            if ($r['translated_text'] === null) {
                $pending[(int) $r['id']] = true;
            }
        }

        $resolved = [];
        foreach ($this->languages->fallbackChain($lang) as $fallbackLang) {
            if ($pending === []) {
                break;
            }
            foreach ($this->repo->fetchTranslationsByParentIds(array_keys($pending), $fallbackLang, $tableKey) as $id => $text) {
                if ($text !== null && isset($pending[$id])) {
                    $resolved[$id] = $text;
                    unset($pending[$id]);
                }
            }
        }

        return $resolved;
    }

    private function mediaPreview(array $row): array
    {
        $postId = (int) $row['post_id'];

        return [
            'image_url'      => wp_get_attachment_image_url($postId, 'thumbnail') ?: null,
            'preview_url'    => admin_url('post.php?post=' . $postId . '&action=edit'),
            'preview_target' => 'admin',
        ];
    }

    private function frontendPreviewUrl(string $base, string $lang): string
    {
        $sep = str_contains($base, '?') ? '&' : '?';

        return $base . $sep . 'lingowp_preview=' . rawurlencode($lang);
    }

    public function htmlPreviewUrl($sourceUrl, string $lang): ?string
    {
        $path = HtmlSource::normalizeSourceUrl(is_string($sourceUrl) ? $sourceUrl : null);

        return $path === null
            ? null
            : $this->frontendPreviewUrl(home_url($path), $lang);
    }

    public function collectionMatchesFilters(array $collection, array $filters): bool
    {
        $contentTypes = $filters['content_type'] ?? [];
        if ($contentTypes !== [] && ! in_array($collection['type'], $contentTypes, true)) {
            return false;
        }

        $progress = $filters['progress'] ?? [];
        if ($progress === []) {
            return true;
        }

        foreach ($progress as $value) {
            switch ($value) {
                case 'not_started':
                    $matches = $collection['translated'] === 0;
                    break;
                case 'in_progress':
                    $matches = $collection['translated'] > 0
                        && ($collection['missing'] > 0 || $collection['needs_review'] > 0);
                    break;
                case 'complete':
                    $matches = $collection['missing'] === 0 && $collection['needs_review'] === 0;
                    break;
                case 'has_review':
                    $matches = $collection['needs_review'] > 0;
                    break;
                default:
                    throw new \RuntimeException('Unhandled match case: ' . var_export($value, true));
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }

    private function taxonomyLabel(string $taxonomy): string
    {
        $obj = get_taxonomy($taxonomy);
        if ($obj && ! empty($obj->labels->name)) {
            return (string) $obj->labels->name;
        }

        return $taxonomy;
    }

    private function uxType(string $postType): string
    {
        if ($postType === 'page') {
            return 'page';
        }
        if ($postType === 'post') {
            return 'post';
        }

        return 'cpt';
    }

    private function isFrontPage(int $postId, string $postType, string $postName): bool
    {
        if (get_option('show_on_front') === 'page') {
            return $postId === (int) get_option('page_on_front');
        }

        return $postType === 'wp_template'
            && in_array($postName, ['front-page', 'home'], true);
    }
}
