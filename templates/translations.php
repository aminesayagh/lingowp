<?php
/**
 * Translation Memory table screen.
 *
 * Expects: groups, total, page, perPage, filters, languages, statuses,
 *          notice, scanStats, tabs, current, pageSlug, view, showIgnoredUrl, activeUrl
 *
 * @package TransLangs
 */

use TransLangs\Shared\Infrastructure\PhpTemplateRenderer;
use TransLangs\TranslationMemory\Domain\TranslationStatus;
use TransLangs\TranslationMemory\Infrastructure\Repositories\SourceTypeCatalog;

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, array<string, mixed>> $groups */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var array<string, mixed> $filters */
/** @var array<string, array<string, string>> $languages */
/** @var string $defaultLanguage */
/** @var array<int, string> $statuses */
/** @var array{0:string,1:string}|null $notice */
/** @var array<string, int>|null $scanStats */
/** @var string $pageSlug */
/** @var string|null $current */
/** @var string $view */
/** @var array<string, array{slug: string, label: string, count: int, url: string}> $sourceTabs */
/** @var string $activeTab */
/** @var bool $multiLanguage */
/** @var string $showIgnoredUrl */
/** @var string $activeUrl */

$tabs    = $tabs    ?? [];
$current = $current ?? '';
$scanStats = $scanStats ?? null;
$sourceTabs = $sourceTabs ?? [];
$activeTab  = $activeTab ?? '';
$multiLanguage = $multiLanguage ?? false;
$isIgnoredView = ($view ?? 'active') === 'ignored';

$iconLabel = static function (string $label, string $icon): string {
    return sprintf(
        '<span class="dashicons dashicons-%1$s" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span>',
        esc_attr($icon),
        esc_html($label)
    );
};

$badgeClass = static function (int $status): string {
    return [
        TranslationStatus::DISCOVERED   => 'translangs-badge--discovered',
        TranslationStatus::MACHINE      => 'translangs-badge--machine',
        TranslationStatus::REVIEWED     => 'translangs-badge--reviewed',
        TranslationStatus::IGNORED      => 'translangs-badge--ignored',
        TranslationStatus::NEEDS_REVIEW => 'translangs-badge--review',
    ][$status] ?? 'translangs-badge--discovered';
};

$renderTable = static function (array $rows, bool $isIgnoredView, bool $showStatusColumn = true, bool $showImageColumn = false) use ($badgeClass, &$statuses, $iconLabel, $multiLanguage): void {
    $columnCount = 3 + ($showStatusColumn ? 1 : 0) + ($showImageColumn ? 1 : 0);
    ?>
    <table class="translangs-table">
        <colgroup>
            <?php if ($showImageColumn) : ?>
                <col class="translangs-table__col--image">
            <?php endif; ?>
            <col class="translangs-table__col--text">
            <col class="translangs-table__col--text">
            <?php if ($showStatusColumn) : ?>
                <col class="translangs-table__col--status">
            <?php endif; ?>
            <col class="translangs-table__col--actions">
        </colgroup>
        <thead>
            <tr>
                <?php if ($showImageColumn) : ?>
                    <th><?php esc_html_e('Image', 'translangs'); ?></th>
                <?php endif; ?>
                <th><?php esc_html_e('Original', 'translangs'); ?></th>
                <th><?php esc_html_e('Translation', 'translangs'); ?></th>
                <?php if ($showStatusColumn) : ?>
                    <th><?php esc_html_e('Status', 'translangs'); ?></th>
                <?php endif; ?>
                <th><?php esc_html_e('Actions', 'translangs'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ($rows === []) : ?>
                <tr><td colspan="<?php echo (int) $columnCount; ?>"><?php esc_html_e('No strings match these filters.', 'translangs'); ?></td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $row) :
                $rowRef  = (string) ($row['row_ref'] ?? '');
                $formId  = 'translangs-save-form-' . str_replace(':', '-', $rowRef);
                $status  = (int) ($row['status'] ?? 0);
                ?>
                <tr>
                    <?php if ($showImageColumn) :
                        $attachmentId = (int) ($row['post_id'] ?? 0);
                        $editLink     = $attachmentId > 0 ? get_edit_post_link($attachmentId) : '';
                        ?>
                        <td class="translangs-thumb">
                            <?php if ($attachmentId > 0 && wp_attachment_is_image($attachmentId)) :
                                $thumb = wp_get_attachment_image($attachmentId, [48, 48], false, ['loading' => 'lazy']);
                                ?>
                                <?php if ($editLink) : ?>
                                    <a href="<?php echo esc_url($editLink); ?>" target="_blank" rel="noopener noreferrer"><?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
                                <?php else : ?>
                                    <?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>

                    <!-- Original Text -->
                    <td>
                        <div class="translangs-original is-clamped">
                            <div class="translangs-original__text"><?php echo esc_html((string) ($row['original_text'] ?? '')); ?></div>
                            <button type="button" class="button-link translangs-original__toggle" hidden
                                data-expand="<?php esc_attr_e('Expand', 'translangs'); ?>"
                                data-collapse="<?php esc_attr_e('Collapse', 'translangs'); ?>"></button>
                        </div>
                        <?php $contextLabel = (string) ($row['context_label'] ?? ''); ?>
                        <?php if ($contextLabel !== '') : ?>
                            <span class="translangs-source-label"><?php echo esc_html($contextLabel); ?></span>
                        <?php endif; ?>
                    </td>

                    <!-- Translated Text -->
                    <td>
                        <?php if ($isIgnoredView) : ?>
                            <?php echo esc_html((string) ($row['translated_text'] ?? '')); ?>
                        <?php else : ?>
                            <form method="post" action="" class="translangs-cell-edit" id="<?php echo esc_attr($formId); ?>">
                                <?php wp_nonce_field('translangs_translation_action', 'translangs_nonce'); ?>
                                <input type="hidden" name="translangs_screen" value="translangs-translations">
                                <input type="hidden" name="row_ref" value="<?php echo esc_attr($rowRef); ?>">
                                <input type="hidden" name="translangs_action" value="save">
                                <?php $translatedText = (string) ($row['translated_text'] ?? ''); ?>
                                <textarea name="translated_text" rows="1" class="large-text translangs-translation-field" data-initial-value="<?php echo esc_attr($translatedText); ?>"><?php
                                    echo esc_textarea($translatedText);
                                ?></textarea>
                            </form>
                        <?php endif; ?>
                    </td>

                    <?php if ($showStatusColumn) : ?>
                        <td>
                            <?php if ($status === TranslationStatus::MACHINE) : ?>
                                <span class="translangs-status-icon" aria-label="<?php esc_attr_e('Generated by AI', 'translangs'); ?>" title="<?php esc_attr_e('Generated by AI', 'translangs'); ?>">
                                    <span class="dashicons dashicons-translation" aria-hidden="true"></span>
                                    <span class="screen-reader-text"><?php esc_html_e('Generated by AI', 'translangs'); ?></span>
                                </span>
                            <?php elseif ($status !== TranslationStatus::REVIEWED) : ?>
                                <span class="translangs-badge <?php echo esc_attr($badgeClass($status)); ?>">
                                    <?php echo esc_html($statuses[$status] ?? (string) $status); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>

                    <td class="translangs-actions">
                        <?php if (!$isIgnoredView) : ?>
                            <button type="submit" form="<?php echo esc_attr($formId); ?>" class="button button-primary button-small translangs-icon-button translangs-save-button" aria-label="<?php esc_attr_e('Save', 'translangs'); ?>" title="<?php esc_attr_e('Save', 'translangs'); ?>"><?php echo $iconLabel(__('Save', 'translangs'), 'yes'); ?></button>
                        <?php endif; ?>

                        <?php $targetUrl = (string) ($row['view_url'] ?? ''); ?>
                        <?php if ($targetUrl !== '') : ?>
                            <a class="button button-small translangs-icon-button" href="<?php echo esc_url($targetUrl); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e('View text', 'translangs'); ?>" title="<?php esc_attr_e('View text', 'translangs'); ?>"><?php echo $iconLabel(__('View text', 'translangs'), 'visibility'); ?></a>
                        <?php endif; ?>

                        <?php if (!$isIgnoredView && $multiLanguage) : ?>
                            <button type="button" class="button button-small translangs-icon-button translangs-all-langs" data-row-ref="<?php echo esc_attr($rowRef); ?>" aria-label="<?php esc_attr_e('All languages', 'translangs'); ?>" title="<?php esc_attr_e('All languages', 'translangs'); ?>"><?php echo $iconLabel(__('All languages', 'translangs'), 'translation'); ?></button>
                        <?php endif; ?>

                        <?php if ($isIgnoredView) : ?>
                            <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field('translangs_translation_action', 'translangs_nonce'); ?>
                                <input type="hidden" name="translangs_screen" value="translangs-translations">
                                <input type="hidden" name="row_ref" value="<?php echo esc_attr($rowRef); ?>">
                                <input type="hidden" name="translangs_action" value="reset_ignored">
                                <button type="submit" class="button button-small translangs-icon-button" aria-label="<?php esc_attr_e('Reset to discovered', 'translangs'); ?>" title="<?php esc_attr_e('Reset to discovered', 'translangs'); ?>"><?php echo $iconLabel(__('Reset to discovered', 'translangs'), 'undo'); ?></button>
                            </form>
                        <?php else : ?>
                            <?php if ($status === TranslationStatus::NEEDS_REVIEW) : ?>
                                <form method="post" action="" style="display:inline;">
                                    <?php wp_nonce_field('translangs_translation_action', 'translangs_nonce'); ?>
                                    <input type="hidden" name="translangs_screen" value="translangs-translations">
                                    <input type="hidden" name="row_ref" value="<?php echo esc_attr($rowRef); ?>">
                                    <input type="hidden" name="translangs_action" value="review">
                                    <button type="submit" class="button button-small translangs-icon-button" aria-label="<?php esc_attr_e('Review', 'translangs'); ?>" title="<?php esc_attr_e('Review', 'translangs'); ?>"><?php echo $iconLabel(__('Review', 'translangs'), 'yes'); ?></button>
                                </form>
                            <?php else : ?>
                                <?php if ($status !== TranslationStatus::DISCOVERED) : ?>
                                    <form method="post" action="" style="display:inline;">
                                        <?php wp_nonce_field('translangs_translation_action', 'translangs_nonce'); ?>
                                        <input type="hidden" name="translangs_screen" value="translangs-translations">
                                        <input type="hidden" name="row_ref" value="<?php echo esc_attr($rowRef); ?>">
                                        <input type="hidden" name="translangs_action" value="put_for_review">
                                        <button type="submit" class="button button-small translangs-icon-button translangs-put-review-button" aria-label="<?php esc_attr_e('Put for review', 'translangs'); ?>" title="<?php esc_attr_e('Put for review', 'translangs'); ?>" disabled><?php echo $iconLabel(__('Put for review', 'translangs'), 'edit'); ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($status === TranslationStatus::DISCOVERED) : ?>
                                    <form method="post" action="" style="display:inline;">
                                        <?php wp_nonce_field('translangs_translation_action', 'translangs_nonce'); ?>
                                        <input type="hidden" name="translangs_screen" value="translangs-translations">
                                        <input type="hidden" name="row_ref" value="<?php echo esc_attr($rowRef); ?>">
                                        <input type="hidden" name="translangs_action" value="ignore">
                                        <button type="submit" class="button button-small translangs-icon-button translangs-ignore-button" aria-label="<?php esc_attr_e('Ignore', 'translangs'); ?>" title="<?php esc_attr_e('Ignore', 'translangs'); ?>"><?php echo $iconLabel(__('Ignore', 'translangs'), 'no-alt'); ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
};

$baseUrl = admin_url('admin.php?page=' . $pageSlug);
?>
<div class="wrap translangs-page">
    <h1 class="translangs-page__title">
        <?php echo $isIgnoredView ? esc_html__('Ignored translations', 'translangs') : esc_html__('Translations', 'translangs'); ?>
    </h1>

    <?php PhpTemplateRenderer::render('admin-navbar.php', ['tabs' => $tabs, 'current' => $current]); ?>

    <?php if ($notice !== null) : ?>
        <div class="notice notice-<?php echo $notice[1] === 'error' ? 'error' : 'success'; ?> is-dismissible">
            <p><?php echo esc_html($notice[0]); ?></p>
            <?php if ($scanStats !== null) : ?>
                <p>
                    <?php
                    printf(
                        /* translators: 1: source strings, 2: inserted rows, 3: existing rows, 4: skipped fields, 5: target languages */
                        esc_html__('%1$d source strings, %2$d new rows, %3$d existing rows, %4$d skipped fields, %5$d target languages.', 'translangs'),
                        (int) $scanStats['scan_sources'],
                        (int) $scanStats['scan_inserted'],
                        (int) $scanStats['scan_existing'],
                        (int) $scanStats['scan_skipped'],
                        (int) $scanStats['scan_target_languages']
                    );
                    ?>
                </p>
                <p>
                    <?php
                    printf(
                        /* translators: 1: post source count, 2: postmeta source count, 3: term source count */
                        esc_html__('Sources by route: post %1$d, postmeta %2$d, terms %3$d.', 'translangs'),
                        (int) $scanStats['scan_source_post'],
                        (int) $scanStats['scan_source_postmeta'],
                        (int) $scanStats['scan_source_terms']
                    );
                    ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$isIgnoredView) : ?>
        <div class="translangs-page-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <?php wp_nonce_field('translangs_scan_structured_sources', 'translangs_nonce'); ?>
                <input type="hidden" name="action" value="translangs_scan_structured_sources">
                <button type="submit" class="button"><?php esc_html_e('Scan sources', 'translangs'); ?></button>
            </form>
        </div>
    <?php endif; ?>

    <div class="translangs-card">
        <form method="get" action="">
            <input type="hidden" name="page" value="<?php echo esc_attr($pageSlug); ?>">
            <?php if ($isIgnoredView) : ?>
                <input type="hidden" name="translangs_view" value="ignored">
            <?php endif; ?>

            <div class="translangs-filters">
                <select name="target_lang" aria-label="<?php esc_attr_e('Language', 'translangs'); ?>">
                    <?php foreach ($languages as $code => $meta) : ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($filters['target_lang'], $code); ?>>
                            <?php echo esc_html($meta['name'] . ' (' . $code . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="search" name="search_original" value="<?php echo esc_attr($filters['search_original']); ?>"
                    placeholder="<?php esc_attr_e('Search original...', 'translangs'); ?>">

                <input type="search" name="search_translated" value="<?php echo esc_attr($filters['search_translated']); ?>"
                    placeholder="<?php esc_attr_e('Search translation...', 'translangs'); ?>">

                <button type="submit" class="button button-primary"><?php esc_html_e('Filter', 'translangs'); ?></button>
                <a class="button" href="<?php echo esc_url($baseUrl); ?>"><?php esc_html_e('Reset', 'translangs'); ?></a>
            </div>
        </form>
    </div>

    <?php if ($isIgnoredView) : ?>
        <p class="translangs-page-actions">
            <a class="button" href="<?php echo esc_url($activeUrl); ?>"><?php esc_html_e('Back to active translations', 'translangs'); ?></a>
        </p>
    <?php endif; ?>

    <?php if (!$isIgnoredView) : ?>
        <?php if ($sourceTabs === []) : ?>
            <div class="translangs-card translangs-empty">
                <p><?php esc_html_e('No translations found for this source type.', 'translangs'); ?></p>
                <p class="translangs-empty__hint"><?php esc_html_e('Run a scan to discover content.', 'translangs'); ?></p>
            </div>
        <?php else : ?>
            <nav class="translangs-source-tabs">
                <?php foreach ($sourceTabs as $slug => $tab) : ?>
                    <a class="translangs-source-tab <?php echo $slug === $activeTab ? 'is-active' : ''; ?>"
                       href="<?php echo esc_url((string) $tab['url']); ?>">
                        <span class="translangs-source-tab__label"><?php echo esc_html((string) $tab['label']); ?></span>
                        <span class="translangs-source-tab__count"><?php echo (int) $tab['count']; ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php if ($groups !== []) : ?>
                <div class="translangs-summary">
                    <?php foreach ($groups as $summaryGroup) : ?>
                        <span class="translangs-summary__item">
                            <span class="translangs-summary__label"><?php echo esc_html((string) $summaryGroup['title']); ?></span>
                            <span class="translangs-summary__value"><?php echo (int) $summaryGroup['total']; ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($groups as $groupKey => $group) : ?>
        <div class="translangs-card">
            <div class="translangs-list-header">
                <div>
                    <h2 class="translangs-card__title"><?php echo esc_html((string) $group['title']); ?></h2>
                    <p class="translangs-card__desc"><?php echo esc_html((string) $group['description']); ?></p>
                </div>
                <span class="translangs-list-count">
                    <?php
                    printf(
                        /* translators: %d total translation memory items */
                        esc_html(_n('%d item', '%d items', (int) $group['total'], 'translangs')),
                        (int) $group['total']
                    );
                    ?>
                </span>
            </div>

            <?php
            $showImageColumn = !$isIgnoredView && $activeTab === SourceTypeCatalog::ATTACHMENTS;
            $renderTable((array) $group['rows'], $isIgnoredView, $isIgnoredView, $showImageColumn);
            ?>

            <?php
            $groupPage      = max(1, (int) ($group['page'] ?? 1));
            $groupPageParam = (string) ($group['page_param'] ?? 'paged');
            $groupTotalPages = max(1, (int) ceil((int) $group['total'] / $perPage));
            ?>

            <?php if ($groupTotalPages > 1) : ?>
                <div class="translangs-pagination">
                    <?php
                    $query = $_GET;
                    for ($i = 1; $i <= $groupTotalPages; $i++) :
                        $query[$groupPageParam] = $i;
                        $url = add_query_arg($query, admin_url('admin.php'));
                        ?>
                        <a class="button button-small <?php echo $i === $groupPage ? 'button-primary' : ''; ?>"
                           href="<?php echo esc_url($url); ?>"><?php echo (int) $i; ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!$isIgnoredView) : ?>
        <p class="translangs-page-actions">
            <a class="button" href="<?php echo esc_url($showIgnoredUrl); ?>"><?php esc_html_e('Show ignored', 'translangs'); ?></a>
        </p>
    <?php endif; ?>
</div>

<?php if ($multiLanguage && !$isIgnoredView) : ?>
    <div class="translangs-drawer__overlay" data-translangs-drawer-overlay></div>
    <aside class="translangs-drawer" data-translangs-drawer aria-hidden="true"
           data-nonce="<?php echo esc_attr(wp_create_nonce('translangs_translation_action')); ?>"
           data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
        <div class="translangs-drawer__header">
            <h2><?php esc_html_e('Translate in all languages', 'translangs'); ?></h2>
            <button type="button" class="button-link translangs-drawer__close" data-translangs-drawer-close aria-label="<?php esc_attr_e('Close', 'translangs'); ?>">
                <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
            </button>
        </div>
        <div class="translangs-drawer__body">
            <p class="translangs-drawer__original-label"><?php esc_html_e('Original text', 'translangs'); ?></p>
            <p class="translangs-drawer__original" data-translangs-drawer-original></p>
            <div data-translangs-drawer-languages></div>
        </div>
        <div class="translangs-drawer__footer">
            <button type="button" class="button button-primary" data-translangs-drawer-save><?php esc_html_e('Save all', 'translangs'); ?></button>
            <button type="button" class="button" data-translangs-drawer-close><?php esc_html_e('Cancel', 'translangs'); ?></button>
        </div>
    </aside>
<?php endif; ?>
<script>
(function () {
    const forms = document.querySelectorAll('.translangs-cell-edit');

    forms.forEach(function (form) {
        const field        = form.querySelector('.translangs-translation-field');
        const row          = form.closest('tr');
        const button       = row ? row.querySelector('.translangs-save-button') : form.querySelector('.translangs-save-button');
        const reviewButton = row ? row.querySelector('.translangs-put-review-button') : null;
        const ignoreButton = row ? row.querySelector('.translangs-ignore-button') : null;

        if (!field || !button) {
            return;
        }

        let initialValue = field.dataset.initialValue ?? '';

        const resize = function () {
            field.style.height = 'auto';
            field.style.height = field.scrollHeight + 'px';
        };

        const sync = function () {
            button.disabled = field.value === initialValue;
            if (reviewButton) {
                reviewButton.disabled = initialValue.trim() === '';
            }
            if (ignoreButton) {
                ignoreButton.disabled = initialValue.trim() !== '';
            }
            resize();
        };

        form.addEventListener('submit', function (event) {
            if (button.disabled) {
                return;
            }

            if (typeof window.ajaxurl === 'undefined') {
                return;
            }

            event.preventDefault();

            const payload = new FormData(form);
            payload.append('action', 'translangs_save_translation');

            button.disabled = true;
            button.classList.add('is-busy');

            fetch(window.ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                body: payload
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (result) {
                    if (!result || !result.success) {
                        throw new Error(result && result.data && result.data.message ? result.data.message : 'Save failed.');
                    }

                    initialValue = result.data.translated_text ?? field.value;
                    field.dataset.initialValue = initialValue;
                    sync();
                })
                .catch(function () {
                    button.disabled = false;
                })
                .finally(function () {
                    button.classList.remove('is-busy');
                });
        });

        field.addEventListener('input', sync);
        window.requestAnimationFrame(resize);
        sync();
    });
})();
</script>

<script>
(function () {
    const drawer = document.querySelector('[data-translangs-drawer]');
    if (!drawer) {
        return;
    }

    const overlay   = document.querySelector('[data-translangs-drawer-overlay]');
    const original  = drawer.querySelector('[data-translangs-drawer-original]');
    const list      = drawer.querySelector('[data-translangs-drawer-languages]');
    const saveButton = drawer.querySelector('[data-translangs-drawer-save]');
    const ajaxUrl   = drawer.dataset.ajaxUrl;
    const nonce     = drawer.dataset.nonce;

    const open = function () {
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        if (overlay) {
            overlay.classList.add('is-open');
        }
    };

    const close = function () {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        if (overlay) {
            overlay.classList.remove('is-open');
        }
    };

    const escapeHtml = function (value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);
        return span.innerHTML;
    };

    const renderLanguages = function (languages) {
        list.innerHTML = '';

        languages.forEach(function (lang) {
            const row = document.createElement('div');
            row.className = 'translangs-drawer__lang';

            const heading = lang.status_label
                ? escapeHtml(lang.name) + ' <span class="translangs-drawer__status">' + escapeHtml(lang.status_label) + '</span>'
                : escapeHtml(lang.name);

            row.innerHTML =
                '<label class="translangs-drawer__lang-label">' + heading + '</label>' +
                '<textarea class="large-text translangs-drawer__field" rows="2" dir="' + escapeHtml(lang.dir || 'ltr') + '"></textarea>';

            const field = row.querySelector('textarea');
            field.value = lang.translated_text || '';
            field.dataset.rowRef = lang.row_ref;

            list.appendChild(row);
        });
    };

    const load = function (rowRef) {
        original.textContent = '';
        list.innerHTML = '<p>' + escapeHtml('Loading…') + '</p>';
        open();

        const payload = new FormData();
        payload.append('action', 'translangs_language_siblings');
        payload.append('translangs_nonce', nonce);
        payload.append('row_ref', rowRef);

        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: payload })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result || !result.success) {
                    throw new Error('Load failed.');
                }
                original.textContent = result.data.original_text || '';
                renderLanguages(result.data.languages || []);
            })
            .catch(function () {
                list.innerHTML = '<p>' + escapeHtml('Could not load languages.') + '</p>';
            });
    };

    const saveAll = function () {
        const fields = list.querySelectorAll('.translangs-drawer__field');
        const payload = new FormData();
        payload.append('action', 'translangs_save_all');
        payload.append('translangs_nonce', nonce);

        fields.forEach(function (field, index) {
            payload.append('items[' + index + '][row_ref]', field.dataset.rowRef);
            payload.append('items[' + index + '][translated_text]', field.value);
        });

        saveButton.disabled = true;
        saveButton.classList.add('is-busy');

        fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: payload })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result || !result.success) {
                    throw new Error('Save failed.');
                }
                close();
                window.location.reload();
            })
            .catch(function () {
                saveButton.disabled = false;
            })
            .finally(function () {
                saveButton.classList.remove('is-busy');
            });
    };

    document.querySelectorAll('.translangs-all-langs').forEach(function (button) {
        button.addEventListener('click', function () {
            load(button.dataset.rowRef);
        });
    });

    drawer.querySelectorAll('[data-translangs-drawer-close]').forEach(function (button) {
        button.addEventListener('click', close);
    });

    if (overlay) {
        overlay.addEventListener('click', close);
    }

    if (saveButton) {
        saveButton.addEventListener('click', saveAll);
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
            close();
        }
    });
})();
</script>

<script>
(function () {
    document.querySelectorAll('.translangs-original').forEach(function (box) {
        const text   = box.querySelector('.translangs-original__text');
        const toggle = box.querySelector('.translangs-original__toggle');
        if (!text || !toggle) {
            return;
        }

        // Only offer the toggle when the clamped text actually overflows.
        if (text.scrollHeight - text.clientHeight > 2) {
            toggle.hidden = false;
            toggle.textContent = toggle.dataset.expand;
        }

        toggle.addEventListener('click', function () {
            const clamped = box.classList.toggle('is-clamped');
            toggle.textContent = clamped ? toggle.dataset.expand : toggle.dataset.collapse;
        });
    });
})();
</script>
