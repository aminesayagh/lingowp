<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Source\Value\SourceRef;
use TransLangs\Core\Source\Value\TranslationStatus;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Workspace content REST endpoints (namespace translangs/v1) — read-only board.
 *
 * Collections are DERIVED groups, not stored rows: "Site settings" is the whole
 * tl_option table; each content collection is one WP post's tl_post units. A
 * collection id is an opaque token this controller owns — `option:site` or
 * `content:{postId}` — and is deliberately distinct from a SourceRef ({kind}:{id},
 * which addresses a single text unit for writes). Status/counts derive from the
 * schema per design-ux-data-mapping.md §2; nothing new is stored.
 *
 * v1 covers post content + site settings. Term/meta/html/dependency collections
 * are added next (see plan-ux-implementation-dashboard.md, Phase A).
 */
final class CollectionRestController
{
    private const NS = 'translangs/v1';

    public function __construct(
        private SourceTranslationRepository $repo,
        private OptionLanguageRegistry $registry
    ) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/collections', ['methods' => 'GET', 'callback' => [$this, 'index']] + $guard);
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/texts',
            ['methods' => 'GET', 'callback' => [$this, 'texts']] + $guard
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/ignore',
            ['methods' => 'POST', 'callback' => [$this, 'ignoreAll']] + $guard
        );
        register_rest_route(
            self::NS,
            '/collections/(?P<id>[^/]+)/clear',
            ['methods' => 'POST', 'callback' => [$this, 'clearAll']] + $guard
        );
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /** Collection groups for the selected language: Site settings first, then content. */
    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $lang        = $this->resolveLang($request);
        $collections = [];

        $option = $this->repo->optionCollectionSummary($lang);
        if ($option['total'] > 0) {
            $collections[] = [
                'id'             => 'option:site',
                'label'          => __('Site settings', 'translangs'),
                'type'           => 'site_settings',
                'preview_url'    => admin_url('options-general.php'),
                'preview_target' => 'admin',
            ] + $option;
        }

        $html = $this->repo->htmlCollectionSummary($lang);
        if ($html['total'] > 0) {
            $collections[] = [
                'id'             => 'html:all',
                'label'          => __('Template content', 'translangs'),
                'type'           => 'html',
                'preview_url'    => null,
                'preview_target' => 'frontend',
            ] + $html;
        }

        // A content collection is one WP post's tl_post fields + its tl_meta,
        // merged by post_id (preserving the post-type/title ordering of tl_post).
        $byPost = [];
        foreach ($this->repo->listPostCollections($lang) as $c) {
            $byPost[$c['post_id']] = $c;
        }
        foreach ($this->repo->postMetaCounts($lang) as $m) {
            $pid = $m['post_id'];
            if (! isset($byPost[$pid])) {
                $byPost[$pid] = $m;
                continue;
            }
            foreach (['total', 'translated', 'needs_review', 'missing'] as $k) {
                $byPost[$pid][$k] += $m[$k];
            }
        }
        foreach ($byPost as $pid => $c) {
            $permalink = get_permalink((int) $pid);
            $collections[] = [
                'id'             => 'content:' . $pid,
                'label'          => $c['post_title'] !== '' ? $c['post_title'] : __('(no title)', 'translangs'),
                'type'           => $this->uxType($c['post_type']),
                'total'          => $c['total'],
                'translated'     => $c['translated'],
                'needs_review'   => $c['needs_review'],
                'missing'        => $c['missing'],
                'preview_url'    => $permalink === false ? null : $this->frontendPreviewUrl($permalink, $lang),
                'preview_target' => 'frontend',
            ];
        }

        // Terms group by their taxonomy: one collection per taxonomy (all post
        // categories together, all product categories together, …), not one per
        // term. Counts are summed across the taxonomy's terms.
        $byTax = [];
        foreach ($this->repo->listTermCollections($lang) as $t) {
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

        return rest_ensure_response([
            'ok'          => true,
            'language'    => $lang,
            'collections' => $collections,
        ]);
    }

    /** Text units of one collection for the selected language. */
    public function texts(WP_REST_Request $request): WP_REST_Response
    {
        $lang = $this->resolveLang($request);
        $id   = urldecode((string) $request->get_param('id'));

        if ($id === 'option:site') {
            $optionPreview = ['preview_url' => admin_url('options-general.php'), 'preview_target' => 'admin'];
            $texts = array_map(
                fn (array $r): array => $this->presentText('option', $r, (string) $r['option_name']) + $optionPreview,
                $this->repo->listOptionTexts($lang)
            );
        } elseif ($id === 'html:all') {
            $texts = array_map(
                fn (array $r): array => $this->presentText('html', $r, (string) $r['field']) + [
                    'preview_url'    => empty($r['source_url'])
                        ? null
                        : $this->frontendPreviewUrl((string) $r['source_url'], $lang),
                    'preview_target' => 'frontend',
                ],
                $this->repo->listHtmlTexts($lang)
            );
        } elseif (preg_match('/^content:([0-9]+)$/', $id, $m)) {
            $postId    = (int) $m[1];
            $permalink = get_permalink($postId);
            $preview   = [
                'preview_url'    => $permalink === false ? null : $this->frontendPreviewUrl($permalink, $lang),
                'preview_target' => 'frontend',
            ];
            $posts = array_map(
                fn (array $r): array => $this->presentText('post', $r, (string) $r['field']) + $preview,
                $this->repo->listPostTexts($postId, $lang)
            );
            $metas = array_map(
                fn (array $r): array => $this->presentText('meta', $r, (string) $r['field']) + $preview,
                $this->repo->listMetaTexts($postId, $lang)
            );
            $texts = array_merge($posts, $metas);
        } elseif (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            $taxonomy = $m[1];
            $texts = array_map(
                fn (array $r): array => $this->presentText('term', $r, (string) $r['field']) + [
                    'preview_url'    => admin_url(
                        'term.php?taxonomy=' . urlencode($taxonomy) . '&tag_ID=' . (int) $r['term_id']
                    ),
                    'preview_target' => 'admin',
                ],
                $this->repo->listTaxonomyTexts($taxonomy, $lang)
            );
        } else {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        return rest_ensure_response([
            'ok'       => true,
            'language' => $lang,
            'texts'    => $texts,
        ]);
    }

    /** Ignore every text in a collection (source-global). Confirmation-gated in the UI. */
    public function ignoreAll(WP_REST_Request $request): WP_REST_Response
    {
        $refs = $this->collectionRefs(urldecode((string) $request->get_param('id')), $this->resolveLang($request));
        if ($refs === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        foreach ($refs as $ref) {
            $this->repo->ignoreSource($ref);
        }

        return rest_ensure_response(['ok' => true, 'count' => count($refs)]);
    }

    /** Clear every translation in a collection for the selected language. */
    public function clearAll(WP_REST_Request $request): WP_REST_Response
    {
        $lang = $this->resolveLang($request);
        if ($lang === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        $refs = $this->collectionRefs(urldecode((string) $request->get_param('id')), $lang);
        if ($refs === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }
        foreach ($refs as $ref) {
            $this->repo->deleteTranslation($ref, $lang);
        }

        return rest_ensure_response(['ok' => true, 'count' => count($refs)]);
    }

    /**
     * Every source position in a collection as a SourceRef, or null for an unknown
     * id. Enumerates via the same list reads the board uses (collections are bounded,
     * so a per-ref loop is fine); reuses the tested ignore/clear code paths.
     *
     * @return SourceRef[]|null
     */
    private function collectionRefs(string $id, string $lang): ?array
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
        if (preg_match('/^taxonomy:(.+)$/', $id, $m)) {
            return array_map(
                static fn (array $u): SourceRef => SourceRef::make('term', (int) $u['id']),
                $this->repo->listTaxonomyTexts($m[1], $lang)
            );
        }

        return null;
    }

    /** Shape one text unit for the board, deriving the UI status from schema state. */
    private function presentText(string $kind, array $row, string $field): array
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
            'id'         => SourceRef::make($kind, (int) $row['id'])->format(),
            'field'      => $field,
            'original'   => $original,
            'translated' => $translated,
            // v1: fallback shows the source text; fallback-language lookup is later.
            'fallback'   => $original,
            'status'     => $statusLabel,
            'origin'     => $translated === null ? null : ($provider !== null ? 'ai' : 'manual'),
            'is_ai'      => $isAi,
            'word_count' => str_word_count(wp_strip_all_tags($original)),
            'char_count' => mb_strlen($original),
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

    /** Selected target language, defaulting to the first enabled target. */
    private function resolveLang(WP_REST_Request $request): string
    {
        $lang = sanitize_key((string) $request->get_param('language'));
        if ($lang !== '') {
            return $lang;
        }

        return $this->registry->getTargetLanguages()[0] ?? '';
    }

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
}
