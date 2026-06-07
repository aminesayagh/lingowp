<?php

namespace TransLangs\BackOffice\Infrastructure;

use InvalidArgumentException;
use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\PublicSite\Infrastructure\LocalizedUrlBuilder;
use TransLangs\PublicSite\Infrastructure\TranslatedPageCacheInvalidator;
use TransLangs\Shared\Infrastructure\PhpTemplateRenderer;
use TransLangs\TranslationMemory\Application\ApplyTranslationToSameText;
use TransLangs\TranslationMemory\Application\IgnoreTranslation;
use TransLangs\TranslationMemory\Application\PutTranslationForReview;
use TransLangs\TranslationMemory\Application\QueryTranslations;
use TransLangs\TranslationMemory\Application\ReviewTranslation;
use TransLangs\TranslationMemory\Application\ResetIgnoredTranslation;
use TransLangs\TranslationMemory\Application\SaveTranslation;
use TransLangs\TranslationMemory\Domain\RowRef;
use TransLangs\TranslationMemory\Domain\TranslationStatus;
use TransLangs\TranslationMemory\Infrastructure\Repositories\SourceTypeCatalog;
use TransLangs\TranslationMemory\Infrastructure\StructuredSourceScanner;

/**
 * The Translation Memory admin screen.
 *
 * Lets an admin browse discovered strings, filter them, and translate/review/
 * ignore them. The controller owns WordPress admin concerns; all translation
 * memory reads/writes go through application services.
 *
 * Each form submission carries a row_ref field (e.g. "post:123") that
 * identifies the specific translation row. RowRef::parse() is called exactly
 * once in each write handler; the resulting RowRef is passed to services.
 */
class TranslationMemoryPageController
{
    private const CAPABILITY = 'manage_options';
    public const SLUG        = 'translangs-translations';
    private const PER_PAGE   = 20;

    public function __construct(
        private LanguageRegistry $registry,
        private QueryTranslations $queryTranslations,
        private SaveTranslation $saveTranslation,
        private PutTranslationForReview $putTranslationForReview,
        private ReviewTranslation $reviewTranslation,
        private IgnoreTranslation $ignoreTranslation,
        private ResetIgnoredTranslation $resetIgnoredTranslation,
        private TranslatedPageCacheInvalidator $cacheInvalidator,
        private StructuredSourceScanner $structuredSourceScanner,
        private LocalizedUrlBuilder $urlBuilder,
        private ApplyTranslationToSameText $applyToSameText
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 11);
        add_action('admin_menu', [$this, 'reorderMenu'], 999);
        add_action('admin_init', [$this, 'handleWrites']);
        add_action('admin_post_translangs_scan_structured_sources', [$this, 'handleStructuredScan']);
        add_action('wp_ajax_translangs_save_translation', [$this, 'handleAjaxSave']);
        add_action('wp_ajax_translangs_language_siblings', [$this, 'handleAjaxLanguageSiblings']);
        add_action('wp_ajax_translangs_save_all', [$this, 'handleAjaxSaveAll']);
    }

    /**
     * List the Translations submenu above Settings. The parent menu always
     * mirrors itself (Settings) as the first item, so we move the Translations
     * entry to the front once both submenus are registered.
     */
    public function reorderMenu(): void
    {
        global $submenu;

        $parent = SettingsPageController::SLUG;
        if (empty($submenu[$parent]) || !is_array($submenu[$parent])) {
            return;
        }

        $translations = [];
        $rest         = [];

        foreach ($submenu[$parent] as $item) {
            if (isset($item[2]) && $item[2] === self::SLUG) {
                $translations[] = $item;
            } else {
                $rest[] = $item;
            }
        }

        $submenu[$parent] = array_merge($translations, $rest);
    }

    public function addMenu(): void
    {
        if ($this->registry->getTargetLanguages() === []) {
            return;
        }

        add_submenu_page(
            'translangs',
            __('Translations', 'translangs'),
            __('Translations', 'translangs'),
            self::CAPABILITY,
            self::SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Runs on admin_init. Only acts on a POST to this screen; otherwise no-op.
     * Performs the write and redirects (Post-Redirect-Get) before output.
     */
    public function handleWrites(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['translangs_action'])) {
            return;
        }

        if (!isset($_POST['translangs_screen']) || $_POST['translangs_screen'] !== self::SLUG) {
            return;
        }

        check_admin_referer('translangs_translation_action', 'translangs_nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Permission denied.', 'translangs'));
        }

        $action = sanitize_key($_POST['translangs_action']);

        $rowRef = $this->parseRowRef(sanitize_text_field(wp_unslash($_POST['row_ref'] ?? '')));

        if ($rowRef === null) {
            $this->redirectWithNotice('invalid');
        }

        switch ($action) {
            case 'save':
                $text = wp_kses_post(wp_unslash($_POST['translated_text'] ?? ''));
                $this->saveTranslation->save($rowRef, $text);
                $this->cacheInvalidator->invalidateTranslations();
                $this->redirectWithNotice('saved');
                break;

            case 'put_for_review':
                if (!$this->putTranslationForReview->putForReview($rowRef)) {
                    $this->redirectWithNotice('already_needs_review');
                }
                $this->cacheInvalidator->invalidateTranslations();
                $this->redirectWithNotice('put_for_review');
                break;

            case 'review':
                if (!$this->reviewTranslation->review($rowRef)) {
                    $this->redirectWithNotice('not_reviewable');
                }
                $this->cacheInvalidator->invalidateTranslations();
                $this->redirectWithNotice('reviewed');
                break;

            case 'ignore':
                $this->ignoreTranslation->ignore($rowRef);
                $this->cacheInvalidator->invalidateTranslations();
                $this->redirectWithNotice('ignored');
                break;

            case 'reset_ignored':
                if (!$this->resetIgnoredTranslation->reset($rowRef)) {
                    $this->redirectWithNotice('not_ignored');
                }
                $this->cacheInvalidator->invalidateTranslations();
                $this->redirectWithNotice('reset_ignored');
                break;

            case 'apply_elsewhere':
                $applied = $this->applyToSameText->apply($rowRef);
                if ($applied === 0) {
                    $this->redirectWithNotice('applied_none');
                }
                $this->cacheInvalidator->invalidateTranslations();
                $this->redirectWithNotice('applied_elsewhere');
                break;
        }
    }

    public function handleAjaxSave(): void
    {
        if (!check_ajax_referer('translangs_translation_action', 'translangs_nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'translangs')], 403);
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Permission denied.', 'translangs')], 403);
        }

        if (!isset($_POST['translangs_screen']) || $_POST['translangs_screen'] !== self::SLUG) {
            wp_send_json_error(['message' => __('Invalid screen.', 'translangs')], 400);
        }

        $rowRef = $this->parseRowRef(sanitize_text_field(wp_unslash($_POST['row_ref'] ?? '')));

        if ($rowRef === null) {
            wp_send_json_error(['message' => __('Invalid string reference.', 'translangs')], 400);
        }

        $text = wp_kses_post(wp_unslash($_POST['translated_text'] ?? ''));
        $this->saveTranslation->save($rowRef, $text);
        $this->cacheInvalidator->invalidateTranslations();

        wp_send_json_success([
            'message'          => __('Translation saved.', 'translangs'),
            'translated_text'  => $text,
            'has_translation'  => trim(wp_strip_all_tags($text)) !== '',
        ]);
    }

    /**
     * AJAX: return the same source string across every target language, for the
     * multi-language drawer.
     */
    public function handleAjaxLanguageSiblings(): void
    {
        if (!check_ajax_referer('translangs_translation_action', 'translangs_nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'translangs')], 403);
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Permission denied.', 'translangs')], 403);
        }

        $rowRef = $this->parseRowRef(sanitize_text_field(wp_unslash($_POST['row_ref'] ?? '')));
        if ($rowRef === null) {
            wp_send_json_error(['message' => __('Invalid string reference.', 'translangs')], 400);
        }

        $details      = $this->filterLanguages();
        $statusLabels = TranslationStatus::labels();
        $original     = '';
        $languages    = [];

        foreach ($this->queryTranslations->languageSiblings($rowRef) as $row) {
            $original = $row['original_text'];
            $code     = $row['target_lang'];
            $meta     = $details[$code] ?? ['name' => $code, 'native' => '', 'dir' => 'ltr'];

            $languages[] = [
                'row_ref'         => $row['row_ref'],
                'code'            => $code,
                'name'            => $meta['name'],
                'dir'             => $meta['dir'],
                'translated_text' => $row['translated_text'] ?? '',
                'status_label'    => $statusLabels[$row['status']] ?? '',
            ];
        }

        wp_send_json_success([
            'original_text' => $original,
            'languages'     => $languages,
        ]);
    }

    /**
     * AJAX: save translations for several rows at once (the drawer's "Save all").
     */
    public function handleAjaxSaveAll(): void
    {
        if (!check_ajax_referer('translangs_translation_action', 'translangs_nonce', false)) {
            wp_send_json_error(['message' => __('Invalid security token.', 'translangs')], 403);
        }

        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('Permission denied.', 'translangs')], 403);
        }

        $items = $_POST['items'] ?? [];
        if (!is_array($items) || $items === []) {
            wp_send_json_error(['message' => __('Nothing to save.', 'translangs')], 400);
        }

        $saved = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rowRef = $this->parseRowRef(sanitize_text_field(wp_unslash($item['row_ref'] ?? '')));
            if ($rowRef === null) {
                continue;
            }

            $text = wp_kses_post(wp_unslash($item['translated_text'] ?? ''));
            $this->saveTranslation->save($rowRef, $text);
            ++$saved;
        }

        if ($saved > 0) {
            $this->cacheInvalidator->invalidateTranslations();
        }

        wp_send_json_success([
            'saved'   => $saved,
            'message' => __('Translations saved.', 'translangs'),
        ]);
    }

    public function handleStructuredScan(): void
    {
        check_admin_referer('translangs_scan_structured_sources', 'translangs_nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Permission denied.', 'translangs'));
        }

        $result = $this->structuredSourceScanner->scan();

        wp_safe_redirect(add_query_arg([
            'page'                   => self::SLUG,
            'translangs_notice'      => 'scan_complete',
            'scan_sources'           => $result->totalSourceStrings(),
            'scan_inserted'          => $result->totalInsertedRows(),
            'scan_existing'          => $result->totalExistingRows(),
            'scan_skipped'           => $result->skipped,
            'scan_target_languages'  => $result->targetLanguageCount,
            'scan_source_post'       => $result->sourceByRoute['post'] ?? 0,
            'scan_source_postmeta'   => $result->sourceByRoute['postmeta'] ?? 0,
            'scan_source_terms'      => $result->sourceByRoute['terms'] ?? 0,
            'scan_inserted_post'     => $result->insertedByRoute['post'] ?? 0,
            'scan_inserted_postmeta' => $result->insertedByRoute['postmeta'] ?? 0,
            'scan_inserted_terms'    => $result->insertedByRoute['terms'] ?? 0,
        ], admin_url('admin.php')));
        exit;
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'translangs'));
        }

        $notice = isset($_GET['translangs_notice'])
            ? $this->noticeFromFlag(sanitize_key($_GET['translangs_notice']))
            : null;

        $filters = $this->readFilters();
        $view    = $this->viewMode();

        if ($view === 'ignored') {
            $page       = max(1, (int) ($_GET['paged'] ?? 1));
            $groups     = $this->ignoredGroups($filters, $page);
            $sourceTabs = [];
            $activeTab  = '';
        } else {
            $page       = 1;
            $sourceTabs = $this->buildSourceTabs($this->queryTranslations->sourceTypeCounts($filters), $filters);
            $activeTab  = $this->activeTab($sourceTabs);
            $groups     = $activeTab === ''
                ? []
                : $this->activeGroups(array_merge($filters, ['source_type' => $activeTab]));
        }

        $total = array_sum(array_map(static fn(array $group): int => (int) $group['total'], $groups));

        PhpTemplateRenderer::render('translations.php', [
            'groups'          => $groups,
            'total'           => $total,
            'page'            => $page,
            'perPage'         => self::PER_PAGE,
            'filters'         => $filters,
            'languages'       => $this->filterLanguages(),
            'statuses'        => TranslationStatus::labels(),
            'defaultLanguage' => $this->defaultLanguage(),
            'notice'          => $notice,
            'scanStats'       => $this->scanStatsFromQuery(),
            'pageSlug'        => self::SLUG,
            'view'            => $view,
            'sourceTabs'      => $sourceTabs,
            'activeTab'       => $activeTab,
            'multiLanguage'   => count($this->filterLanguages()) > 1,
            'showIgnoredUrl'  => $this->pageUrl($filters, 'ignored'),
            'activeUrl'       => $this->pageUrl($filters, 'active'),
        ]);
    }

    /**
     * Build the ordered, non-empty source-type tabs for the current filters.
     *
     * @param  array<string, int>   $counts  slug => row count
     * @param  array<string, mixed> $filters
     * @return array<string, array{slug: string, label: string, count: int, url: string}>
     */
    private function buildSourceTabs(array $counts, array $filters): array
    {
        $tabs = [];

        foreach (SourceTypeCatalog::orderedSlugs() as $slug) {
            $count = (int) ($counts[$slug] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $tabs[$slug] = [
                'slug'  => $slug,
                'label' => SourceTypeCatalog::label($slug),
                'count' => $count,
                'url'   => $this->tabUrl($filters, $slug),
            ];
        }

        return $tabs;
    }

    /**
     * Resolve the active tab: the requested one if it has rows, else the first.
     *
     * @param array<string, array<string, mixed>> $sourceTabs
     */
    private function activeTab(array $sourceTabs): string
    {
        if ($sourceTabs === []) {
            return '';
        }

        $requested = isset($_GET['translangs_tab']) ? sanitize_key(wp_unslash($_GET['translangs_tab'])) : '';
        if ($requested !== '' && isset($sourceTabs[$requested])) {
            return $requested;
        }

        return (string) array_key_first($sourceTabs);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function tabUrl(array $filters, string $slug): string
    {
        $args = ['page' => self::SLUG, 'translangs_tab' => $slug];

        foreach ($filters as $key => $value) {
            if ($value !== '' && $value !== null) {
                $args[$key] = $value;
            }
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * Parse a raw row_ref string from POST input.
     * Returns null on any parse failure; caller redirects or sends JSON error.
     */
    private function parseRowRef(string $raw): ?RowRef
    {
        if ($raw === '') {
            return null;
        }

        try {
            return RowRef::parse($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Redirect back to the list, preserving filters/page, with a result flag.
     * Never returns.
     */
    private function redirectWithNotice(string $flag): void
    {
        $args = [
            'page'              => self::SLUG,
            'translangs_notice' => $flag,
        ];

        foreach (['target_lang', 'search_original', 'search_translated', 'paged', 'translated_paged', 'discovered_paged', 'needs_review_paged', 'translangs_view', 'translangs_tab'] as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
            }
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function noticeFromFlag(string $flag): ?array
    {
        $map = [
            'saved'               => [__('Translation saved.', 'translangs'), 'success'],
            'put_for_review'      => [__('Marked as needs review.', 'translangs'), 'success'],
            'reviewed'            => [__('Marked as reviewed.', 'translangs'), 'success'],
            'ignored'             => [__('String ignored.', 'translangs'), 'success'],
            'reset_ignored'       => [__('String reset to discovered.', 'translangs'), 'success'],
            'applied_elsewhere'   => [__('Translation applied to other untranslated occurrences.', 'translangs'), 'success'],
            'applied_none'        => [__('No other untranslated occurrences of this text were found.', 'translangs'), 'success'],
            'scan_complete'       => [__('Structured scan completed.', 'translangs'), 'success'],
            'invalid'             => [__('Invalid string reference.', 'translangs'), 'error'],
            'already_needs_review' => [__('This string is already marked as needs review.', 'translangs'), 'error'],
            'not_reviewable'      => [__('Only strings marked as needs review can be reviewed.', 'translangs'), 'error'],
            'not_ignored'         => [__('Only ignored strings can be reset.', 'translangs'), 'error'],
        ];

        return $map[$flag] ?? null;
    }

    /**
     * Read and sanitize filters from the query string.
     * source_lang is intentionally absent — source language is never stored.
     *
     * @return array<string, mixed>
     */
    private function readFilters(): array
    {
        $enabledLanguages = $this->filterLanguages();
        $targetLang       = isset($_GET['target_lang']) ? sanitize_key($_GET['target_lang']) : '';

        if ($targetLang === '' || !isset($enabledLanguages[$targetLang])) {
            $targetLang = array_key_first($enabledLanguages) ?: $this->defaultLanguage();
        }

        return [
            'target_lang'       => $targetLang,
            'search_original'   => isset($_GET['search_original'])   ? sanitize_text_field(wp_unslash($_GET['search_original']))   : '',
            'search_translated' => isset($_GET['search_translated']) ? sanitize_text_field(wp_unslash($_GET['search_translated'])) : '',
        ];
    }

    private function viewMode(): string
    {
        $view = isset($_GET['translangs_view'])
            ? sanitize_key(wp_unslash($_GET['translangs_view']))
            : 'active';

        return $view === 'ignored' ? 'ignored' : 'active';
    }

    /**
     * @param  array<string, mixed> $filters
     * @return array<string, array<string, mixed>>
     */
    private function activeGroups(array $filters): array
    {
        return [
            'translated' => $this->group(
                __('Translated', 'translangs'),
                __('Reviewed and machine translated strings.', 'translangs'),
                $filters,
                [TranslationStatus::REVIEWED, TranslationStatus::MACHINE],
                $this->pageNumber('translated_paged'),
                'translated_paged'
            ),
            'discovered' => $this->group(
                __('Discovered', 'translangs'),
                __('New strings waiting for translation.', 'translangs'),
                $filters,
                [TranslationStatus::DISCOVERED],
                $this->pageNumber('discovered_paged'),
                'discovered_paged'
            ),
            'needs_review' => $this->group(
                __('Needs review', 'translangs'),
                __('Strings flagged for human review.', 'translangs'),
                $filters,
                [TranslationStatus::NEEDS_REVIEW],
                $this->pageNumber('needs_review_paged'),
                'needs_review_paged'
            ),
        ];
    }

    /**
     * @param  array<string, mixed> $filters
     * @return array<string, array<string, mixed>>
     */
    private function ignoredGroups(array $filters, int $page): array
    {
        return [
            'ignored' => $this->group(
                __('Ignored', 'translangs'),
                __('Strings intentionally kept in the source language.', 'translangs'),
                $filters,
                [TranslationStatus::IGNORED],
                $page,
                'paged'
            ),
        ];
    }

    /**
     * @param  array<string, mixed> $filters
     * @param  int[]                $statuses
     * @return array<string, mixed>
     */
    private function group(string $title, string $description, array $filters, array $statuses, int $page = 1, string $pageParam = 'paged'): array
    {
        $result = $this->queryTranslations->query(
            array_merge($filters, ['statuses' => $statuses]),
            $page,
            self::PER_PAGE
        );

        $rows = array_map(function (array $row): array {
            $row['view_url']      = $this->focusViewUrl($row);
            $row['context_label'] = $this->contextLabel($row);
            return $row;
        }, $result['rows']);

        return [
            'title'       => $title,
            'description' => $description,
            'rows'        => $rows,
            'total'       => $result['total'],
            'page'        => $page,
            'page_param'  => $pageParam,
        ];
    }

    /**
     * Human-readable source label shown under the original string, e.g.
     * "Page: Home · post_title" or "gettext · WooCommerce". Built from the
     * row's route table and context columns.
     *
     * @param array<string, mixed> $row
     */
    private function contextLabel(array $row): string
    {
        $route  = (string) ($row['route_table'] ?? '');
        $ctx1   = trim((string) ($row['ctx1'] ?? ''));
        $ctx2   = trim((string) ($row['ctx2'] ?? ''));
        $postId = (int) ($row['post_id'] ?? 0);

        $parts = [];

        switch ($route) {
            case 'post':
            case 'postmeta':
                if ($postId > 0) {
                    $typeLabel = $this->postTypeLabel($postId);
                    $title     = (string) get_the_title($postId);
                    $parts[]   = $typeLabel !== '' ? "{$typeLabel}: {$title}" : $title;
                }
                break;

            case 'terms':
                $parts[] = __('Taxonomy', 'translangs');
                break;

            case 'i18n':
                // ctx1 is the source bucket (gettext / rendered_html).
                break;
        }

        foreach ([$ctx1, $ctx2] as $context) {
            if ($context !== '') {
                $parts[] = $context;
            }
        }

        return implode(' · ', $parts);
    }

    private function postTypeLabel(int $postId): string
    {
        $type = get_post_type($postId);
        if (!is_string($type) || $type === '') {
            return '';
        }

        $object = get_post_type_object($type);

        return $object !== null && isset($object->labels->singular_name)
            ? (string) $object->labels->singular_name
            : $type;
    }

    /**
     * Front-end URL for a row's "View text" action: the localized page for the
     * row's target language, with a focus marker the highlighter reads.
     * Empty for rows not tied to a post (terms/i18n have no single page).
     *
     * @param array<string, mixed> $row
     */
    private function focusViewUrl(array $row): string
    {
        $rowRef = (string) ($row['row_ref'] ?? '');
        if ($rowRef === '') {
            return '';
        }

        $postId = (int) ($row['post_id'] ?? 0);
        if ($postId > 0) {
            $permalink = get_permalink($postId);
            if (!is_string($permalink) || $permalink === '') {
                return '';
            }

            $targetLang = (string) ($row['target_lang'] ?? '');
            $path       = (string) (wp_parse_url($permalink, PHP_URL_PATH) ?: '/');
            $localized  = $targetLang !== '' ? $this->urlBuilder->addPrefix($path, $targetLang) : $path;

            return add_query_arg('translangs_focus', $rowRef, home_url($localized));
        }

        // i18n rows: the localized page where the string was last captured.
        $sourceUrl = (string) ($row['source_url'] ?? '');
        if ($sourceUrl !== '') {
            return add_query_arg('translangs_focus', $rowRef, $sourceUrl);
        }

        return '';
    }

    private function pageNumber(string $key): int
    {
        return max(1, (int) ($_GET[$key] ?? 1));
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function pageUrl(array $filters, string $view): string
    {
        $args = ['page' => self::SLUG];

        if ($view === 'ignored') {
            $args['translangs_view'] = 'ignored';
        }

        foreach ($filters as $key => $value) {
            if ($value !== '' && $value !== null) {
                $args[$key] = $value;
            }
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    private function defaultLanguage(): string
    {
        return $this->registry->getDefaultLanguage();
    }

    /**
     * @return array<string, int>|null
     */
    private function scanStatsFromQuery(): ?array
    {
        if (!isset($_GET['translangs_notice']) || sanitize_key($_GET['translangs_notice']) !== 'scan_complete') {
            return null;
        }

        $keys = [
            'scan_sources',
            'scan_inserted',
            'scan_existing',
            'scan_skipped',
            'scan_target_languages',
            'scan_source_post',
            'scan_source_postmeta',
            'scan_source_terms',
            'scan_inserted_post',
            'scan_inserted_postmeta',
            'scan_inserted_terms',
        ];

        $stats = [];
        foreach ($keys as $key) {
            $stats[$key] = isset($_GET[$key]) ? max(0, (int) $_GET[$key]) : 0;
        }

        return $stats;
    }

    /**
     * @return array<string, array{name: string, native: string, dir: string}>
     */
    private function filterLanguages(): array
    {
        return $this->registry->getTargetLanguageDetails();
    }
}
