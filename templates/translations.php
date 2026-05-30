<?php
/**
 * Translation Memory table screen.
 *
 * Expects: rows, total, page, perPage, filters, languages, statuses, pairs,
 *          notice, tabs, current, pageSlug
 *
 * @package TransLangs
 */

use TransLangs\Config\View;

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<int, array<string, mixed>> $rows */
/** @var int $total */
/** @var int $page */
/** @var int $perPage */
/** @var array<string, mixed> $filters */
/** @var array<string, array<string, string>> $languages */
/** @var array<int, string> $statuses */
/** @var array<int, array{source_lang:string,target_lang:string}> $pairs */
/** @var array{0:string,1:string}|null $notice */
/** @var string $pageSlug */
/** @var string|null $current */

$tabs = $tabs ?? [];
$current = $current ?? '';

$badgeClass = static function (int $status): string {
    return [
        0 => 'translangs-badge--discovered',
        1 => 'translangs-badge--machine',
        2 => 'translangs-badge--reviewed',
        3 => 'translangs-badge--ignored',
        4 => 'translangs-badge--review',
    ][$status] ?? 'translangs-badge--discovered';
};

$totalPages = max(1, (int) ceil($total / $perPage));
$baseUrl    = admin_url('admin.php?page=' . $pageSlug);
?>
<div class="wrap translangs-page">
    <h1 class="translangs-page__title"><?php esc_html_e('Translations', 'translangs'); ?></h1>

    <?php View::render('admin/navbar.php', ['tabs' => $tabs, 'current' => $current]); ?>

    <?php if ($notice !== null) : ?>
        <div class="notice notice-<?php echo $notice[1] === 'error' ? 'error' : 'success'; ?> is-dismissible">
            <p><?php echo esc_html($notice[0]); ?></p>
        </div>
    <?php endif; ?>

    <div class="translangs-card">
        <form method="get" action="">
            <input type="hidden" name="page" value="<?php echo esc_attr($pageSlug); ?>">
            <div class="translangs-filters">
                <select name="status">
                    <option value=""><?php esc_html_e('All statuses', 'translangs'); ?></option>
                    <?php foreach ($statuses as $code => $label) : ?>
                        <option value="<?php echo esc_attr((string) $code); ?>" <?php selected($filters['status'], $code); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="target_lang">
                    <option value=""><?php esc_html_e('Any language pair', 'translangs'); ?></option>
                    <?php foreach ($pairs as $pair) : ?>
                        <?php $val = $pair['target_lang']; ?>
                        <option value="<?php echo esc_attr($val); ?>" <?php selected($filters['target_lang'], $val); ?>>
                            <?php echo esc_html($pair['source_lang'] . ' → ' . $pair['target_lang']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="search" name="search_original" value="<?php echo esc_attr($filters['search_original']); ?>"
                    placeholder="<?php esc_attr_e('Search original…', 'translangs'); ?>">

                <input type="search" name="search_translated" value="<?php echo esc_attr($filters['search_translated']); ?>"
                    placeholder="<?php esc_attr_e('Search translation…', 'translangs'); ?>">

                <input type="search" name="source_url" value="<?php echo esc_attr($filters['source_url']); ?>"
                    placeholder="<?php esc_attr_e('Source URL…', 'translangs'); ?>">

                <button type="submit" class="button"><?php esc_html_e('Filter', 'translangs'); ?></button>
                <a class="button button-link" href="<?php echo esc_url($baseUrl); ?>"><?php esc_html_e('Reset', 'translangs'); ?></a>
            </div>
        </form>
    </div>

    <div class="translangs-card">
        <p class="translangs-card__desc">
            <?php
            printf(
                /* translators: %d total strings */
                esc_html__('%d strings found.', 'translangs'),
                (int) $total
            );
            ?>
        </p>

        <table class="translangs-table">
            <thead>
                <tr>
                    <th><?php esc_html_e('Original', 'translangs'); ?></th>
                    <th><?php esc_html_e('Translation', 'translangs'); ?></th>
                    <th><?php esc_html_e('Pair', 'translangs'); ?></th>
                    <th><?php esc_html_e('Status', 'translangs'); ?></th>
                    <th><?php esc_html_e('Context', 'translangs'); ?></th>
                    <th><?php esc_html_e('Source URL', 'translangs'); ?></th>
                    <th><?php esc_html_e('Last seen', 'translangs'); ?></th>
                    <th><?php esc_html_e('Actions', 'translangs'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ($rows === []) : ?>
                    <tr><td colspan="8"><?php esc_html_e('No strings match these filters.', 'translangs'); ?></td></tr>
                <?php endif; ?>

                <?php foreach ($rows as $row) :
                    $id     = (int) $row['id'];
                    $status = (int) $row['status'];
                    ?>
                    <tr>
                        <td><?php echo esc_html((string) $row['original_text']); ?></td>

                        <td>
                            <span class="translangs-cell-view" data-row="<?php echo esc_attr((string) $id); ?>">
                                <?php echo esc_html((string) ($row['translated_text'] ?? '')); ?>
                            </span>

                            <form method="post" action="" class="translangs-cell-edit" data-row="<?php echo esc_attr((string) $id); ?>" style="display:none;">
                                <?php wp_nonce_field('translangs_translation_action', 'translangs_nonce'); ?>
                                <input type="hidden" name="translangs_screen" value="translangs-translations">
                                <input type="hidden" name="string_id" value="<?php echo esc_attr((string) $id); ?>">
                                <input type="hidden" name="translangs_action" value="save">
                                <textarea name="translated_text" rows="2" class="large-text"><?php
                                    echo esc_textarea((string) ($row['translated_text'] ?? ''));
                                ?></textarea>
                                <button type="submit" class="button button-primary button-small"><?php esc_html_e('Save', 'translangs'); ?></button>
                                <button type="button" class="button button-small translangs-cancel" data-row="<?php echo esc_attr((string) $id); ?>"><?php esc_html_e('Cancel', 'translangs'); ?></button>
                            </form>
                        </td>

                        <td><?php echo esc_html($row['source_lang'] . ' → ' . $row['target_lang']); ?></td>

                        <td>
                            <span class="translangs-badge <?php echo esc_attr($badgeClass($status)); ?>">
                                <?php echo esc_html($statuses[$status] ?? (string) $status); ?>
                            </span>
                        </td>

                        <td>
                            <?php
                            $ctx = (string) ($row['context'] ?? '');
                            if (strpos($ctx, 'img@alt|') === 0) {
                                $imgSrc = substr($ctx, strlen('img@alt|'));
                                ?>
                                <span class="translangs-context-img">
                                    <img src="<?php echo esc_url($imgSrc); ?>" alt="" class="translangs-thumb" loading="lazy">
                                    <span class="translangs-context-label"><?php esc_html_e('Image alt', 'translangs'); ?></span>
                                </span>
                                <?php
                            } else {
                                echo esc_html($ctx);
                            }
                            ?>
                        </td>
                        <td><?php echo esc_html((string) ($row['source_url'] ?? '')); ?></td>
                        <td><?php echo esc_html((string) ($row['last_seen_at'] ?? '')); ?></td>

                        <td class="translangs-actions">
                            <button type="button" class="button button-small translangs-edit" data-row="<?php echo esc_attr((string) $id); ?>"><?php esc_html_e('Edit', 'translangs'); ?></button>

                            <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field('translangs_translation_action', 'translangs_nonce'); ?>
                                <input type="hidden" name="translangs_screen" value="translangs-translations">
                                <input type="hidden" name="string_id" value="<?php echo esc_attr((string) $id); ?>">
                                <input type="hidden" name="translangs_action" value="review">
                                <button type="submit" class="button button-small"><?php esc_html_e('Review', 'translangs'); ?></button>
                            </form>

                            <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field('translangs_translation_action', 'translangs_nonce'); ?>
                                <input type="hidden" name="translangs_screen" value="translangs-translations">
                                <input type="hidden" name="string_id" value="<?php echo esc_attr((string) $id); ?>">
                                <input type="hidden" name="translangs_action" value="ignore">
                                <button type="submit" class="button button-small"><?php esc_html_e('Ignore', 'translangs'); ?></button>
                            </form>

                            <form method="post" action="" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this string?', 'translangs')); ?>');">
                                <?php wp_nonce_field('translangs_translation_action', 'translangs_nonce'); ?>
                                <input type="hidden" name="translangs_screen" value="translangs-translations">
                                <input type="hidden" name="string_id" value="<?php echo esc_attr((string) $id); ?>">
                                <input type="hidden" name="translangs_action" value="delete">
                                <button type="submit" class="button button-small button-link-delete"><?php esc_html_e('Delete', 'translangs'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($totalPages > 1) : ?>
            <div class="translangs-pagination">
                <?php
                $query = $_GET;
                for ($i = 1; $i <= $totalPages; $i++) :
                    $query['paged'] = $i;
                    $url = $baseUrl . '&' . http_build_query(array_diff_key($query, ['page' => '']));
                    ?>
                    <a class="button button-small <?php echo $i === $page ? 'button-primary' : ''; ?>"
                       href="<?php echo esc_url(add_query_arg('paged', $i, $baseUrl)); ?>"><?php echo (int) $i; ?></a>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    function toggle(row, editing) {
        document.querySelectorAll('.translangs-cell-view[data-row="' + row + '"]').forEach(function (el) {
            el.style.display = editing ? 'none' : '';
        });
        document.querySelectorAll('.translangs-cell-edit[data-row="' + row + '"]').forEach(function (el) {
            el.style.display = editing ? 'block' : 'none';
        });
    }
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('translangs-edit')) {
            toggle(e.target.getAttribute('data-row'), true);
        }
        if (e.target.classList.contains('translangs-cancel')) {
            toggle(e.target.getAttribute('data-row'), false);
        }
    });
})();
</script>
