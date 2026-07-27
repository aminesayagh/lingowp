<?php

namespace TransLangs\Admin\Rest;

use TransLangs\Core\Parsing\UnitStructureGuard;
use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Source\Value\SourceRef;
use TransLangs\Core\Source\Value\TranslationStatus;
use TransLangs\Language\Infrastructure\OptionLanguageRegistry;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Translation write endpoints (namespace translangs/v1) for the workspace.
 *
 * A text unit is addressed by its SourceRef ({kind}:{id}). Manual saves are
 * REVIEWED (2) with no provider (a manual edit of an AI string clears its AI
 * origin). Single-text status actions map to the existing repository operations
 * so all status/hash rules stay in one place. All actions work offline — no
 * backend call. See design-ux-data-mapping.md §2 for the status vocabulary.
 */
final class TranslationRestController
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

        register_rest_route(self::NS, '/translations', ['methods' => 'POST', 'callback' => [$this, 'save']] + $guard);
        register_rest_route(
            self::NS,
            '/texts/(?P<ref>[^/]+)/status',
            ['methods' => 'POST', 'callback' => [$this, 'status']] + $guard
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

        $errors = UnitStructureGuard::validate($this->repo->loadSourceText($ref) ?? '', $text);
        if ($errors !== []) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'ERR_TAG_STRUCTURE', 'errors' => $errors],
                422
            );
        }

        $this->repo->saveTranslation($ref, $lang, $text, TranslationStatus::REVIEWED, null);

        return rest_ensure_response(['ok' => true]);
    }

    /**
     * Single-text action: ignore (source-global), clear (this language),
     * review (flag needs_review), reviewed (mark reviewed).
     */
    public function status(WP_REST_Request $request): WP_REST_Response
    {
        $ref    = SourceRef::parse(urldecode((string) $request->get_param('ref')));
        $action = (string) $request->get_param('action');
        $lang   = $this->resolveLang($request);

        if ($ref === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        // Ignore is language-global; the others act on one language's child row.
        if ($action === 'ignore') {
            $this->repo->ignoreSource($ref);
            return rest_ensure_response(['ok' => true]);
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
            default:
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        return rest_ensure_response(['ok' => true]);
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
}
