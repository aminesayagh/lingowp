<?php
/**
 * Dev-only reset-data screen markup.
 *
 * @package LingoWP
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap lingowp-page">
    <h1 class="lingowp-page__title"><?php esc_html_e('Reset Everything', 'lingostack'); ?></h1>

    <div class="lingowp-card">
        <h2 class="lingowp-card__title"><?php esc_html_e('Reset to first activation', 'lingostack'); ?></h2>
        <p class="lingowp-card__desc">
            <?php esc_html_e('Drops every Lingostack table, deletes your API connection and credentials and all configuration (languages, behavior toggles), then deactivates the plugin. Reactivate it manually afterwards to start setup over from a clean slate. This cannot be undone.', 'lingostack'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            onsubmit="return confirm('<?php echo esc_js(__('Drop all tables, delete the connection and configuration, and deactivate the plugin? This cannot be undone.', 'lingostack')); ?>');">
            <input type="hidden" name="action" value="lingowp_reset_data">
            <?php wp_nonce_field('lingowp_reset_data', 'lingowp_reset_nonce'); ?>
            <button type="submit" class="button button-link-delete">
                <?php esc_html_e('Reset everything', 'lingostack'); ?>
            </button>
        </form>
    </div>
</div>
