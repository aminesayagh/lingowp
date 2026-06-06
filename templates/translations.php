<?php
/**
 * Translation Memory table screen.
 *
 * Expects: groups, total, page, perPage, filters, languages, statuses,
 *          notice, tabs, current, pageSlug, view, showIgnoredUrl, activeUrl
 *
 * @package TransLangs
 */

use TransLangs\Shared\Infrastructure\PhpTemplateRenderer;
use TransLangs\TranslationMemory\Domain\TranslationStatus;

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
/** @var string $pageSlug */
/** @var string|null $current */
/** @var string $view */
/** @var string $showIgnoredUrl */
/** @var string $activeUrl */

$tabs    = $tabs    ?? [];
$current = $current ?? '';
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

/**
 * Resolve the front-end "View" URL only for rows tied to a post.
 */
$viewUrl = static function (array $row): string {
    echo $row['route_table'] ?? '';
    $postId = isset($row['post_id']) ? (int) $row['post_id'] : 0;

    if ($postId <= 0) {
        return '';
    }

    $url = get_permalink($postId);
    return is_string($url) ? $url : '';
};

$renderTable = static function (array $rows, bool $isIgnoredView, bool $showStatusColumn = true) use ($badgeClass, &$statuses, $viewUrl, $iconLabel): void {
    ?>
    <table class="translangs-table">
        <colgroup>
            <col class="translangs-table__col--text">
            <col class="translangs-table__col--text">
            <?php if ($showStatusColumn) : ?>
                <col class="translangs-table__col--status">
            <?php endif; ?>
            <col class="translangs-table__col--actions">
        </colgroup>
        <thead>
            <tr>
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
                <tr><td colspan="<?php echo $showStatusColumn ? '4' : '3'; ?>"><?php esc_html_e('No strings match these filters.', 'translangs'); ?></td></tr>
            <?php endif; ?>

            <?php foreach ($rows as $row) :
                $rowRef  = (string) ($row['row_ref'] ?? '');
                $formId  = 'translangs-save-form-' . str_replace(':', '-', $rowRef);
                $status  = (int) ($row['status'] ?? 0);
                ?>
                <tr>
                    <!-- Original Text -->
                    <td><?php echo esc_html((string) ($row['original_text'] ?? '')); ?></td>

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

                        <?php $targetUrl = $viewUrl($row); ?>
                        <?php if ($targetUrl !== '') : ?>
                            <a class="button button-small translangs-icon-button" href="<?php echo esc_url($targetUrl); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e('View', 'translangs'); ?>" title="<?php esc_attr_e('View', 'translangs'); ?>"><?php echo $iconLabel(__('View', 'translangs'), 'visibility'); ?></a>
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

            <?php $renderTable((array) $group['rows'], $isIgnoredView, $isIgnoredView); ?>

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
