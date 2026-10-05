<?php

namespace LingoWP\Board;

use LingoWP\Language\Domain\LocaleNormalizer;

use LingoWP\Shared\Parsing\TranslationSubmissionGuard;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Shared\Source\SourceRef;
use LingoWP\Shared\Source\TranslationStatus;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

final class TranslationRestController
{
    private const NS = 'lingowp/v1';

    private WpDbSourceRepository $repo;
    private OptionLanguageRegistry $registry;
    private CollectionBoard $collections;

    public function __construct(
        WpDbSourceRepository $repo,
        OptionLanguageRegistry $registry,
        CollectionBoard $collections
    ) {
        $this->repo        = $repo;
        $this->registry    = $registry;
        $this->collections = $collections;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/translations', ['methods' => 'POST', 'callback' => [$this, 'save'], 'permission_callback' => [$this, 'canManage']]);
        register_rest_route(
            self::NS,
            '/texts/(?P<ref>[^/]+)/status',
            ['methods' => 'POST', 'callback' => [$this, 'status'], 'permission_callback' => [$this, 'canManage']]
        );
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    /** Save one manual translation (status = reviewed, no provider). */
    public function save(WP_REST_Request $request): WP_REST_Response
    {
        $ref  = SourceRef::parse((string) $request->get_param('text_unit_id'));
        $lang = $this->resolveLang($request);
        $text = (string) $request->get_param('text');

        if ($ref === null || $lang === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        // An empty (or whitespace-only) submission is someone clearing the field,
        // not a confirmed empty translation — treat it as Clear rather than
        // saving "" as reviewed (the structure guard below has nothing to check
        // against an empty string anyway for plain, marker-free text).
        if (trim($text) === '') {
            $this->repo->deleteTranslation($ref, $lang);

            return rest_ensure_response(['ok' => true] + $this->boardPatch($request, $lang, $ref));
        }

        $context = $this->repo->loadSourceValidationContext($ref);
        $errors  = TranslationSubmissionGuard::validate(
            $context['text'] ?? '',
            $text,
            $context['meta_key'] ?? null
        );
        if ($errors !== []) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'ERR_TAG_STRUCTURE', 'errors' => $errors],
                422
            );
        }

        $this->repo->saveTranslation($ref, $lang, $text, TranslationStatus::REVIEWED, null);

        return rest_ensure_response(['ok' => true] + $this->boardPatch($request, $lang, $ref));
    }

    /**
     * Single-text action: ignore / restore (source-global), clear (this
     * language), review (flag needs_review), reviewed (mark reviewed),
     * manual (drop the provider: an imported or AI translation becomes manual).
     */
    public function status(WP_REST_Request $request): WP_REST_Response
    {
        $ref    = SourceRef::parse(urldecode((string) $request->get_param('ref')));
        $action = (string) $request->get_param('action');
        $lang   = $this->resolveLang($request);

        if ($ref === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        // Ignore/restore are language-global; the others act on one language's child row.
        // `ignore_status_changed` tells the client the row moved between status
        // views (in/out of the Ignored filter), which a single-collection summary
        // patch can't express — it invalidates the whole board instead.
        if ($action === 'ignore') {
            $this->repo->ignoreSource($ref);
            return rest_ensure_response(
                ['ok' => true, 'ignore_status_changed' => true] + $this->boardPatch($request, $lang, $ref)
            );
        }
        if ($action === 'restore') {
            // Back to discovered (status 0). Children were deleted on ignore, so
            // the string is untranslated again and reappears in the normal board.
            $this->repo->restoreSources([$ref]);
            return rest_ensure_response(
                ['ok' => true, 'ignore_status_changed' => true] + $this->boardPatch($request, $lang, $ref)
            );
        }

        if ($lang === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        switch ($action) {
            case 'clear':
                $this->repo->deleteTranslation($ref, $lang);
                break;
            case 'review':
                $this->repo->setTranslationStatus($ref, $lang, TranslationStatus::NEEDS_REVIEW);
                break;
            case 'reviewed':
                $this->repo->setTranslationStatus($ref, $lang, TranslationStatus::REVIEWED);
                break;
            case 'manual':
                if (!$this->repo->clearTranslationProvider($ref, $lang)) {
                    return new WP_REST_Response(['ok' => false, 'error' => 'ERR_SAVE_FAILED'], 500);
                }
                break;
            default:
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        return rest_ensure_response(['ok' => true] + $this->boardPatch($request, $lang, $ref));
    }

    /**
     * When the client tells us which board collection this text belongs to
     * (`collection_id`), recompute that collection's summary AND reshape the
     * just-written unit itself — the client uses `updated_unit.original_hash`
     * to patch every other visible occurrence of the same text (hash-link
     * kinds share one translation across positions), not just this one row.
     * Optional: omitted entirely if the client didn't send a collection_id,
     * or it doesn't resolve — callers fall back to invalidating instead.
     */
    private function boardPatch(WP_REST_Request $request, string $lang, SourceRef $ref): array
    {
        $collectionId = (string) $request->get_param('collection_id');
        if ($collectionId === '' || $lang === '') {
            return [];
        }
        $result = $this->collections->unitAndSummaryFor($collectionId, $lang, $ref->format());

        $patch = [];
        if ($result['summary'] !== null) {
            $patch['collection_summary'] = $result['summary'];
        }
        if ($result['unit'] !== null) {
            $patch['updated_unit'] = $result['unit'];
        }

        return $patch;
    }

    /** Selected target language, defaulting to the first enabled target. */
    private function resolveLang(WP_REST_Request $request): string
    {
        $lang = LocaleNormalizer::normalize((string) $request->get_param('language'));
        if ($lang !== '') {
            return $lang;
        }

        return $this->registry->getTargetLanguages()[0] ?? '';
    }
}
