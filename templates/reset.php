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
            <?php esc_html_e('Deletes every discovered text and translation, your API connection and credentials, and all configuration (languages, behavior toggles) — then requests a fresh initial scan and HTML crawl. The end result is meant to look exactly like a brand-new install: you will need to go through onboarding again. This cannot be undone.', 'translangs'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            onsubmit="return confirm('<?php echo esc_js(__('Delete all discovered text, translations, connection, and configuration? This cannot be undone.', 'translangs')); ?>');">
            <input type="hidden" name="action" value="translangs_reset_data">
            <?php wp_nonce_field('translangs_reset_data', 'translangs_reset_nonce'); ?>
            <button type="submit" class="button button-link-delete">
                <?php esc_html_e('Reset everything', 'translangs'); ?>
            </button>
        </form>
    </div>
</div>
