<?php

namespace TransLangs\Admin;

use TransLangs\Core\TranslationRepository;
use TransLangs\Config\Languages;
use TransLangs\Config\View;

/**
 * The Translation Memory admin screen.
 *
 * Lets an admin browse discovered strings, filter them, and translate/review/
 * ignore/delete them — all without touching the database. Reads go through
 * TranslationRepository::query(); writes go through the repository's
 * save/review/ignore/delete methods. Every write is nonce- and
 * capability-checked.
 */
class TranslationsPage
{
    private const CAPABILITY = 'manage_options';
    private const SLUG       = 'translangs-translations';
    private const PER_PAGE   = 20;

    public function __construct(private TranslationRepository $repository)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu'], 11);
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

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You do not have permission to access this page.', 'translangs'));
        }

        $notice = $this->maybeHandleAction();

        $filters = $this->readFilters();
        $page    = max(1, (int) ($_GET['paged'] ?? 1));
        $result  = $this->repository->query($filters, $page, self::PER_PAGE);

        View::render('translations.php', [
            'rows'        => $result['rows'],
            'total'       => $result['total'],
            'page'        => $page,
            'perPage'     => self::PER_PAGE,
            'filters'     => $filters,
            'languages'   => Languages::all(),
            'statuses'    => TranslationRepository::statusLabels(),
            'pairs'       => $this->repository->languagePairs(),
            'notice'      => $notice,
            'tabs'        => SettingsPage::tabs(),
            'current'     => self::SLUG,
            'pageSlug'    => self::SLUG,
        ]);
    }

    /**
     * Handle a row action POST. Returns a [message, type] notice or null.
     *
     * @return array{0: string, 1: string}|null
     */
    private function maybeHandleAction(): ?array
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['translangs_action'])) {
            return null;
        }

        if (
            !isset($_POST['translangs_nonce'])
            || !wp_verify_nonce(sanitize_key($_POST['translangs_nonce']), 'translangs_translation_action')
        ) {
            return [__('Security check failed. Please try again.', 'translangs'), 'error'];
        }

        if (!current_user_can(self::CAPABILITY)) {
            return [__('Permission denied.', 'translangs'), 'error'];
        }

        $action = sanitize_key($_POST['translangs_action']);
        $id     = (int) ($_POST['string_id'] ?? 0);

        if ($id <= 0) {
            return [__('Invalid string.', 'translangs'), 'error'];
        }

        switch ($action) {
            case 'save':
                $text   = wp_kses_post(wp_unslash($_POST['translated_text'] ?? ''));
                $status = $text === ''
                    ? TranslationRepository::STATUS_DISCOVERED
                    : TranslationRepository::STATUS_REVIEWED;
                $this->repository->saveTranslation($id, $text, $status);
                return [__('Translation saved.', 'translangs'), 'success'];

            case 'review':
                $this->repository->markReviewed($id);
                return [__('Marked as reviewed.', 'translangs'), 'success'];

            case 'ignore':
                $this->repository->ignoreString($id);
                return [__('String ignored.', 'translangs'), 'success'];

            case 'delete':
                $this->repository->delete($id);
                return [__('String deleted.', 'translangs'), 'success'];
        }

        return null;
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
