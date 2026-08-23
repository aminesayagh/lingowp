<?php
/**
 * Dev-only reset-data screen markup.
 *
 * @package TransLangs
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap translangs-page">
    <h1 class="translangs-page__title"><?php esc_html_e('Reset Everything', 'translangs'); ?></h1>

    <div class="translangs-card">
        <h2 class="translangs-card__title"><?php esc_html_e('Reset to first activation', 'translangs'); ?></h2>
        <p class="translangs-card__desc">
            <?php esc_html_e('Drops every TransLangs table, deletes your API connection and credentials and all configuration (languages, behavior toggles), then deactivates the plugin. Reactivate it manually afterwards to start setup over from a clean slate. This cannot be undone.', 'translangs'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            onsubmit="return confirm('<?php echo esc_js(__('Drop all tables, delete the connection and configuration, and deactivate the plugin? This cannot be undone.', 'translangs')); ?>');">
            <input type="hidden" name="action" value="translangs_reset_data">
            <?php wp_nonce_field('translangs_reset_data', 'translangs_reset_nonce'); ?>
            <button type="submit" class="button button-link-delete">
                <?php esc_html_e('Reset everything', 'translangs'); ?>
            </button>
        </form>
    </div>
</div>
