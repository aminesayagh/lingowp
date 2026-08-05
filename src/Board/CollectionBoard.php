<?php

namespace TransLangs\Board;

use TransLangs\Shared\Source\Type\HtmlSource;
use TransLangs\Shared\Source\TranslatableMetaKeys;
use TransLangs\Database\Repository\SourceRepository;
use TransLangs\Shared\Source\SourceRef;
use TransLangs\Shared\Source\TranslationStatus;

/**
 * The Collections read-model: derives board groups, expands one collection's
 * text units, and shapes presentation rows (preview URLs, SEO badges, status
 * labels). Split out of CollectionsRestController, which now only handles
 * HTTP routing/param parsing and delegates every query here — the class's own
 * docblock used to admit this coupling was a stopgap for two sibling REST
 * controllers reaching into a REST controller's internals; they now depend on
 * this plain query class instead (Problem 1 of the reorg plan).
 */
final class CollectionBoard
{
    public function __construct(private SourceRepository $repo) {}

    /**
     * Every collection group for a language + search term (Site settings first,
     * then content, then taxonomies), unpaged. The aggregate queries behind this
     * cost about the same regardless of how much of the result index() keeps, so
     * building the full filtered list once and slicing it is cheaper than trying
     * to paginate at the SQL layer.
     *
     * $source picks the board: 'content' (default, currently the only value)
     * is today's Site settings / Template content / posts / taxonomies tree.
     */
    public function buildCollections(string $lang, string $search, string $source = 'content', array $filters = []): array
    {
        $collections = [];

        // With a search active every count describes the MATCHING subset, and a
        // collection with no hits produces no row at all — that is how empty groups
        // drop out of the board without any filtering here.
        $option = $this->repo->optionCollectionSummary($lang, $search, $filters);
        if ($option['total'] > 0) {
            $collections[] = [
                'id'             => 'option:site',
                'label'          => __('Site settings', 'translangs'),
                'type'           => 'site_settings',
                'preview_url'    => admin_url('options-general.php'),
                'preview_target' => 'admin',
            ] + $option;
        }

        // A content collection is one WP post's tl_post fields + its tl_meta,
        // merged by post_id (preserving the post-type/title ordering of tl_post).
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
            foreach (['total', 'translated', 'needs_review', 'missing'] as $k) {
                $byPost[$pid][$k] += $m[$k];
            }
        }
        $contentRows = [];
        $mediaTotals = ['total' => 0, 'translated' => 0, 'needs_review' => 0, 'missing' => 0];
        foreach ($byPost as $pid => $c) {
            $pid = (int) $pid;

            // Image attachments get folded into the single media:all collection
            // below instead of their own card — see that block for why.
            $isImageAttachment = $c['post_type'] === 'attachment'
                && str_starts_with((string) ($c['post_mime_type'] ?? ''), 'image/');
            if ($isImageAttachment) {
                foreach (array_keys($mediaTotals) as $k) {
                    $mediaTotals[$k] += $c[$k];
                }
                continue;
            }

            $isFront    = $this->isFrontPage($pid, (string) $c['post_type'], (string) get_post_field('post_name', $pid));
            // A non-image attachment (PDF, audio, video) has no alt-text/caption
            // concept and stays an ordinary individual card, same as before.
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
                'label'          => $c['post_title'] !== '' ? $c['post_title'] : __('(no title)', 'translangs'),
                'type'           => $isFront ? 'page' : $this->uxType($c['post_type']),
                'total'          => $c['total'],
                'translated'     => $c['translated'],
                'needs_review'   => $c['needs_review'],
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

        // One collection for every image attachment's alt/title/caption/description,
        // positioned right after the individual page/post/cpt cards — mirrors the
        // option:site/html:all pattern (one id aggregating many underlying rows)
        // rather than content:{postId}'s one-card-per-post pattern, since these
        // rows span many different attachment posts.
        if ($mediaTotals['total'] > 0) {
            $collections[] = [
                'id'             => 'media:all',
                'label'          => __('Media', 'translangs'),
                'type'           => 'media',
                'preview_url'    => admin_url('upload.php'),
                'preview_target' => 'admin',
            ] + $mediaTotals;
        }

        // Terms group by their taxonomy: one collection per taxonomy (all post
        // categories together, all product categories together, …), not one per
        // term. Counts are summed across the taxonomy's terms.
        $byTax = [];
        foreach ($this->repo->listTermCollections($lang, $search, $filters) as $t) {
            $tax = $t['taxonomy'];
            if (! isset($byTax[$tax])) {
                $byTax[$tax] = ['total' => 0, 'translated' => 0, 'needs_review' => 0, 'missing' => 0];
            }
            foreach (['total', 'translated', 'needs_review', 'missing'] as $k) {
                $byTax[$tax][$k] += $t[$k];
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
                'label'          => __('Template content', 'translangs'),
                'type'           => 'html',
                'preview_url'    => null,
                'preview_target' => 'frontend',
            ] + $html;
        }

        return $collections;
    }

    /** Text units of one collection for the selected language. */
    /** Text units of one collection, or null if `$id` doesn't resolve to a known collection. */
    public function collectionTexts(string $id, string $lang, string $search, array $filters = []): ?array
    {
        if ($id === 'option:site') {
            $optionPreview = ['preview_url' => admin_url('options-general.php'), 'preview_target' => 'admin'];
            return array_map(
                fn (array $r): array => $this->presentText('option', $r, (string) $r['option_name']) + $optionPreview,
                $this->repo->listOptionTexts($lang, $search, $filters)
            );
        }

        if ($id === 'html:all') {
            return array_map(
                fn (array $r): array => $this->presentText('html', $r, (string) $r['field']) + [
                    'preview_url'    => $this->htmlPreviewUrl($r['source_url'] ?? null, $lang),
                    'preview_target' => 'frontend',
                ],
                $this->repo->listHtmlTexts($lang, $search, $filters)
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
            $posts = array_map(
                fn (array $r): array => $this->presentText('post', $r, (string) $r['field']) + $preview,
                $this->repo->listPostTexts($postId, $lang, $search, $filters)
            );
            $metas = array_map(
                fn (array $r): array => $this->presentText('meta', $r, (string) $r['field']) + $preview,
                $this->repo->listMetaTexts($postId, $lang, $search, $filters)
            );
            return array_merge($posts, $metas);
        }

        if ($id === 'media:all') {
            $posts = array_map(
                fn (array $r): array => $this->presentText('post', $r, (string) $r['field']) + $this->mediaPreview($r),
                $this->repo->listMediaPostTexts($lang, $search, $filters)
            );
            $alts = array_map(
                fn (array $r): array => $this->presentText('meta', $r, (string) $r['field']) + $this->mediaPreview($r),
                $this->repo->listMediaAltTexts($lang, $search, $filters)
            );
            return array_merge($posts, $alts);
        }

        if (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            $taxonomy = $m[1];
            return array_map(
                fn (array $r): array => $this->presentText('term', $r, (string) $r['field']) + [
                    'preview_url'    => admin_url(
                        'term.php?taxonomy=' . urlencode($taxonomy) . '&tag_ID=' . (int) $r['term_id']
                    ),
                    'preview_target' => 'admin',
                ],
                $this->repo->listTaxonomyTexts($taxonomy, $lang, $search, $filters)
            );
        }

        return null;
    }

    /**
     * Recomputed total/translated/needs_review/missing for one collection,
     * post-mutation — lets a mutation response patch the board's cached
     * summary directly instead of the caller re-fetching the whole page.
     * Reuses collectionTexts() so bucket semantics (reviewed + translated
     * both count as "translated") stay identical to the board's own numbers.
     */
    public function summaryFor(string $id, string $lang): ?array
    {
        $texts = $this->collectionTexts($id, $lang, '');
        if ($texts === null) {
            return null;
        }

        $summary = ['id' => $id, 'total' => count($texts), 'translated' => 0, 'needs_review' => 0, 'missing' => 0];
        foreach ($texts as $t) {
            if ($t['status'] === 'missing') {
                $summary['missing']++;
            } elseif ($t['status'] === 'needs_review') {
                $summary['needs_review']++;
            } else {
                // 'reviewed' and 'translated' share one aggregate bucket.
                $summary['translated']++;
            }
        }

        return $summary;
    }


    /**
     * The just-written unit (by its formatted SourceRef) plus the collection's
     * recomputed summary, in one collectionTexts() call — a single-text
     * mutation needs both to patch the client's cache directly.
     *
     * @return array{unit: ?array, summary: ?array}
     */
    public function unitAndSummaryFor(string $id, string $lang, string $refFormatted): array
    {
        $texts = $this->collectionTexts($id, $lang, '');
        if ($texts === null) {
            return ['unit' => null, 'summary' => null];
        }

        $unit    = null;
        $summary = ['id' => $id, 'total' => count($texts), 'translated' => 0, 'needs_review' => 0, 'missing' => 0];
        foreach ($texts as $t) {
            if ($t['id'] === $refFormatted) {
                $unit = $t;
            }
            if ($t['status'] === 'missing') {
                $summary['missing']++;
            } elseif ($t['status'] === 'needs_review') {
                $summary['needs_review']++;
            } else {
                $summary['translated']++;
            }
        }

        return ['unit' => $unit, 'summary' => $summary];
    }

    /**
     * Every matching group's text-unit ids, in board order (same group order
     * and filters as index(), each group's own text order within it). Powers
     * the compare overlay's cross-group prev/next — not part of the paginated
     * board itself, so it's only fetched when the overlay actually needs it.
     * Grouped (not a flat {id,cid} list) so the client can label a neighbor
     * group even if the board hasn't paginated its way to it yet.
     *
     * ponytail: reuses collectionTexts() per matching group, the same cost as
     * opening every one of them (a deliberate "walk everything" action, not
     * something called on scroll). If that cost bites at scale, project id
     * columns only instead of running full presentText() per row.
     */
    /**
     * Every source position in a collection as a SourceRef, or null for an unknown
     * id. Enumerates via the same list reads the board uses (collections are bounded,
     * so a per-ref loop is fine); reuses the tested ignore/clear code paths.
     *
     * @return SourceRef[]|null
     */
    public function collectionRefs(string $id, string $lang): ?array
    {
        if ($id === 'option:site') {
            return array_map(
                static fn (array $u): SourceRef => SourceRef::make('option', (int) $u['id']),
                $this->repo->listOptionTexts($lang)
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
            return array_map(
                static fn (array $u): SourceRef => SourceRef::make('term', (int) $u['id']),
                $this->repo->listTaxonomyTexts($m[1], $lang)
            );
        }
        return null;
    }

    /** Shape one text unit for the board, deriving the UI status from schema state. */
    public function presentText(string $kind, array $row, string $field): array
    {
        $translated = $row['translated_text'];
        $status     = $row['status'];
        $provider   = $row['provider'];
        $original   = (string) $row['original_text'];

        if ($translated === null) {
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
            // Fallback shows the source text; fallback-language lookup is not implemented yet.
            'fallback'      => $original,
            'status'        => $statusLabel,
            'origin'        => $translated === null ? null : ($provider !== null ? 'ai' : 'manual'),
            'is_ai'         => $isAi,
            'word_count'    => str_word_count(wp_strip_all_tags($original)),
            'char_count'    => mb_strlen($original),
            // Hash-link kinds (post/term/html/option) share one translation row
            // per (original_hash, lang) across every position with the same
            // text; null for parent_id kinds (meta), which never share. Lets
            // the client patch every other visible occurrence of this exact
            // text after a save, instead of only the one just edited.
            'original_hash' => $row['original_hash'] ?? null,
        ];
    }

    /**
     * Thumbnail + admin edit-link for one Media-collection row, keyed by the
     * underlying attachment's post_id (carried through by listMediaPostTexts()/
     * listMediaAltTexts() since these rows span many different images, unlike
     * every other collection where the post is already known from context).
     */
    private function mediaPreview(array $row): array
    {
        $postId = (int) $row['post_id'];

        return [
            'image_url'      => wp_get_attachment_image_url($postId, 'thumbnail') ?: null,
            'preview_url'    => admin_url('post.php?post=' . $postId . '&action=edit'),
            'preview_target' => 'admin',
        ];
    }

    /**
     * Append the editor language-preview param to a front-end URL, so the page
     * renders in the selected target language (ResolveRequestLanguage::resolveFromPreview).
     */
    private function frontendPreviewUrl(string $base, string $lang): string
    {
        $sep = str_contains($base, '?') ? '&' : '?';

        return $base . $sep . 'translangs_preview=' . rawurlencode($lang);
    }

    /** Build an HTML preview on the current domain, including for legacy absolute rows. */
    public function htmlPreviewUrl(mixed $sourceUrl, string $lang): ?string
    {
        $path = HtmlSource::normalizeSourceUrl(is_string($sourceUrl) ? $sourceUrl : null);

        return $path === null
            ? null
            : $this->frontendPreviewUrl(home_url($path), $lang);
    }

    /** Selected target language, defaulting to the first enabled target. */
    /** Collection-level Content Type and Progress matching. */
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
            $matches = match ($value) {
                'not_started' => $collection['translated'] === 0,
                'in_progress' => $collection['translated'] > 0
                    && ($collection['missing'] > 0 || $collection['needs_review'] > 0),
                'complete'    => $collection['missing'] === 0 && $collection['needs_review'] === 0,
                'has_review'  => $collection['needs_review'] > 0,
            };
            if ($matches) {
                return true;
            }
        }

        return false;
    }


    /** The board status filter, defaulting to 'all'. Validity is index()'s job. */
    /** Human-readable taxonomy name (plural label), falling back to the slug. */
    private function taxonomyLabel(string $taxonomy): string
    {
        $obj = get_taxonomy($taxonomy);
        if ($obj && ! empty($obj->labels->name)) {
            return (string) $obj->labels->name;
        }

        return $taxonomy;
    }

    /** Map a WordPress post_type to the workspace source group. */
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

    /**
     * Is this post the one WordPress renders at the site root (`/`)?
     *
     * Classic setup: a static page selected under Settings → Reading
     * (`show_on_front = page`, `page_on_front = <id>`). Block (FSE) theme with the
     * root showing posts (`show_on_front = posts`): the site root is rendered by the
     * `front-page` template, or by `home` when no `front-page` template exists.
     */
    private function isFrontPage(int $postId, string $postType, string $postName): bool
    {
        if (get_option('show_on_front') === 'page') {
            return $postId === (int) get_option('page_on_front');
        }

        return $postType === 'wp_template'
            && in_array($postName, ['front-page', 'home'], true);
    }
}
