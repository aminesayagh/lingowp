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

    public function save(WP_REST_Request $request): WP_REST_Response
    {
        $ref  = SourceRef::parse((string) $request->get_param('text_unit_id'));
        $lang = $this->resolveLang($request);
        $text = (string) $request->get_param('text');

        if ($ref === null || $lang === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

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

    public function status(WP_REST_Request $request): WP_REST_Response
    {
        $ref    = SourceRef::parse(urldecode((string) $request->get_param('ref')));
        $action = (string) $request->get_param('action');
        $lang   = $this->resolveLang($request);

        if ($ref === null) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        if ($action === 'ignore') {
            $this->repo->ignoreSource($ref);
            return rest_ensure_response(
                ['ok' => true, 'ignore_status_changed' => true] + $this->boardPatch($request, $lang, $ref)
            );
        }
        if ($action === 'restore') {
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
            default:
                return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_INPUT'], 400);
        }

        return rest_ensure_response(['ok' => true] + $this->boardPatch($request, $lang, $ref));
    }

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

    private function resolveLang(WP_REST_Request $request): string
    {
        $lang = LocaleNormalizer::normalize((string) $request->get_param('language'));
        if ($lang !== '') {
            return $lang;
        }

        return $this->registry->getTargetLanguages()[0] ?? '';
    }
}
