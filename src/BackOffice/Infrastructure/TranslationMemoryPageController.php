<?php

namespace TransLangs\BackOffice\Infrastructure;

use TransLangs\Language\Infrastructure\SupportedLanguages;
use TransLangs\Shared\Infrastructure\PhpTemplateRenderer;
use TransLangs\TranslationMemory\Application\DeleteTranslation;
use TransLangs\TranslationMemory\Application\IgnoreTranslation;
use TransLangs\TranslationMemory\Application\QueryTranslations;
use TransLangs\TranslationMemory\Application\ReviewTranslation;
use TransLangs\TranslationMemory\Application\SaveTranslation;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

/**
 * The Translation Memory admin screen.
 *
 * Lets an admin browse discovered strings, filter them, and translate/review/
 * ignore/delete them. The controller owns WordPress admin concerns; translation
 * memory reads/writes go through application services.
 */
class TranslationMemoryPageController
{
    private const CAPABILITY = 'manage_options';
    private const SLUG       = 'translangs-translations';
    private const PER_PAGE   = 20;

    public function __construct(
        private QueryTranslations $queryTranslations,
        private SaveTranslation $saveTranslation,
        private ReviewTranslation $reviewTranslation,
        private IgnoreTranslation $ignoreTranslation,
        private DeleteTranslation $deleteTranslation
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 11);
        // Process writes before any HTML is sent, so wp_safe_redirect() works.
        add_action('admin_init', [$this, 'handleWrites']);
    }

    public function addMenu(): void
    {
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

        // Only handle our own screen.
        if (!isset($_POST['translangs_screen']) || $_POST['translangs_screen'] !== self::SLUG) {
            return;
        }

        // Verifies nonce AND admin referer; dies on failure.
        check_admin_referer('translangs_translation_action', 'translangs_nonce');

        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Permission denied.', 'translangs'));
        }

        $action = sanitize_key($_POST['translangs_action']);
        $id     = (int) ($_POST['string_id'] ?? 0);

        if ($id <= 0) {
            $this->redirectWithNotice('invalid');
        }

        switch ($action) {
            case 'save':
                // Sanitize input; allow only post-safe HTML in the translation.
                $text = wp_kses_post(wp_unslash($_POST['translated_text'] ?? ''));
                $this->saveTranslation->save($id, $text);
                $this->redirectWithNotice('saved');
                break;

            case 'review':
                $this->reviewTranslation->review($id);
                $this->redirectWithNotice('reviewed');
                break;

            case 'ignore':
                $this->ignoreTranslation->ignore($id);
                $this->redirectWithNotice('ignored');
                break;

            case 'delete':
                $this->deleteTranslation->delete($id);
                $this->redirectWithNotice('deleted');
                break;
        }
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
        $page    = max(1, (int) ($_GET['paged'] ?? 1));
        $result  = $this->queryTranslations->query($filters, $page, self::PER_PAGE);

        PhpTemplateRenderer::render('translations.php', [
            'rows'        => $result['rows'],
            'total'       => $result['total'],
            'page'        => $page,
            'perPage'     => self::PER_PAGE,
            'filters'     => $filters,
            'languages'   => SupportedLanguages::all(),
            'statuses'    => TranslationStatus::labels(),
            'pairs'       => $this->queryTranslations->languagePairs(),
            'notice'      => $notice,
            'tabs'        => SettingsPageController::tabs(),
            'current'     => self::SLUG,
            'pageSlug'    => self::SLUG,
        ]);
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

        // Preserve the current filter/pagination context across the redirect.
        foreach (['source_lang', 'target_lang', 'status', 'search_original', 'search_translated', 'source_url', 'paged'] as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $args[$key] = sanitize_text_field(wp_unslash($_GET[$key]));
            }
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    /**
     * Map a redirect flag to a [message, type] notice.
     *
     * @return array{0: string, 1: string}|null
     */
    private function noticeFromFlag(string $flag): ?array
    {
        $map = [
            'saved'    => [__('Translation saved.', 'translangs'), 'success'],
            'reviewed' => [__('Marked as reviewed.', 'translangs'), 'success'],
            'ignored'  => [__('String ignored.', 'translangs'), 'success'],
            'deleted'  => [__('String deleted.', 'translangs'), 'success'],
            'invalid'  => [__('Invalid string.', 'translangs'), 'error'],
        ];

        return $map[$flag] ?? null;
    }

    /**
     * Read and sanitize filters from the query string.
     *
     * @return array<string, mixed>
     */
    private function readFilters(): array
    {
        $statusRaw = $_GET['status'] ?? '';

        return [
            'source_lang'       => isset($_GET['source_lang']) ? sanitize_key($_GET['source_lang']) : '',
            'target_lang'       => isset($_GET['target_lang']) ? sanitize_key($_GET['target_lang']) : '',
            'status'            => ($statusRaw === '' ? null : (int) $statusRaw),
            'search_original'   => isset($_GET['search_original']) ? sanitize_text_field(wp_unslash($_GET['search_original'])) : '',
            'search_translated' => isset($_GET['search_translated']) ? sanitize_text_field(wp_unslash($_GET['search_translated'])) : '',
            'source_url'        => isset($_GET['source_url']) ? sanitize_text_field(wp_unslash($_GET['source_url'])) : '',
        ];
    }
}
