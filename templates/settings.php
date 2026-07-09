<?php
/**
 * Settings screen markup.
 *
 * Expects:
 *   $settings  array  current option values
 *   $languages array  full language catalog (code => meta)
 *
 * @package TransLangs
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, mixed> $settings */
/** @var array<string, array<string, string>> $languages */
?>
<div class="wrap translangs-page">
    <h1 class="translangs-page__title"><?php esc_html_e('TransLangs Settings', 'translangs'); ?></h1>

    <form method="post" action="">
        <?php wp_nonce_field('translangs_save_settings', 'translangs_nonce'); ?>

        <div class="translangs-card">
            <h2 class="translangs-card__title"><?php esc_html_e('Languages', 'translangs'); ?></h2>

            <div class="translangs-form">
                <div class="translangs-form__row">
                    <label class="translangs-form__label" for="tl-default-language">
                        <?php esc_html_e('Default language', 'translangs'); ?>
                        <span class="translangs-form__hint">
                            <?php esc_html_e('The original language of your content.', 'translangs'); ?>
                        </span>
                    </label>
                    <div class="translangs-form__field">
                        <?php
                        // Read-only: the source language is frozen at setup (translangs_source_locale)
                        // and inherited from WordPress; it is not editable here (see onboarding arch).
                        $tlDefault = $settings['default_language'];
                        $tlName    = $languages[$tlDefault]['name'] ?? strtoupper((string) $tlDefault);
                        ?>
                        <p id="tl-default-language">
                            <strong><?php echo esc_html($tlName . ' (' . $tlDefault . ')'); ?></strong>
                        </p>
                    </div>
                </div>

                <div class="translangs-form__row">
                    <span class="translangs-form__label">
                        <?php esc_html_e('Enabled languages', 'translangs'); ?>
                        <span class="translangs-form__hint">
                            <?php esc_html_e('Languages visitors can switch to.', 'translangs'); ?>
                        </span>
                    </span>
                    <div class="translangs-form__field">
                        <div class="translangs-checklist">
                            <?php foreach ($languages as $code => $meta) : ?>
                                <?php if ($code === $settings['default_language']) : continue; endif; ?>
                                <label>
                                    <input
                                        type="checkbox"
                                        name="enabled_languages[]"
                                        value="<?php echo esc_attr($code); ?>"
                                        <?php checked(in_array($code, (array) $settings['enabled_languages'], true)); ?>
                                    >
                                    <?php echo esc_html($meta['name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="translangs-card">
            <h2 class="translangs-card__title"><?php esc_html_e('URL &amp; Behavior', 'translangs'); ?></h2>
            <p class="translangs-card__desc">
                <?php esc_html_e('Example: /about is the default language, /fr/about is French.', 'translangs'); ?>
            </p>

            <div class="translangs-form">
                <div class="translangs-form__row">
                    <span class="translangs-form__label"><?php esc_html_e('Prefix default language', 'translangs'); ?></span>
                    <div class="translangs-form__field translangs-form__field--toggle">
                        <input type="checkbox" name="prefix_default_language" id="tl-prefix" value="1"
                            <?php checked($settings['prefix_default_language']); ?>>
                        <label for="tl-prefix"><?php esc_html_e('Add a prefix to the default language too (e.g. /en/about)', 'translangs'); ?></label>
                    </div>
                </div>

                <div class="translangs-form__row">
                    <span class="translangs-form__label"><?php esc_html_e('Cookie preference', 'translangs'); ?></span>
                    <div class="translangs-form__field translangs-form__field--toggle">
                        <input type="checkbox" name="cookie_preference" id="tl-cookie" value="1"
                            <?php checked($settings['cookie_preference']); ?>>
                        <label for="tl-cookie"><?php esc_html_e('Remember the visitor\'s last chosen language', 'translangs'); ?></label>
                    </div>
                </div>

                <div class="translangs-form__row">
                    <span class="translangs-form__label"><?php esc_html_e('Browser detection', 'translangs'); ?></span>
                    <div class="translangs-form__field translangs-form__field--toggle">
                        <input type="checkbox" name="browser_detection" id="tl-browser" value="1"
                            <?php checked($settings['browser_detection']); ?>>
                        <label for="tl-browser"><?php esc_html_e('Suggest a language based on the browser', 'translangs'); ?></label>
                    </div>
                </div>

                <div class="translangs-form__row">
                    <span class="translangs-form__label"><?php esc_html_e('Auto redirect', 'translangs'); ?></span>
                    <div class="translangs-form__field translangs-form__field--toggle">
                        <input type="checkbox" name="auto_redirect" id="tl-redirect" value="1"
                            <?php checked($settings['auto_redirect']); ?>>
                        <label for="tl-redirect"><?php esc_html_e('Redirect visitors automatically (not recommended)', 'translangs'); ?></label>
                    </div>
                </div>
            </div>
        </div>

        <p>
            <button type="submit" class="button button-primary">
                <?php esc_html_e('Save settings', 'translangs'); ?>
            </button>
        </p>
    </form>

    <div class="translangs-card">
        <h2 class="translangs-card__title"><?php esc_html_e('Restart onboarding', 'translangs'); ?></h2>
        <p class="translangs-card__desc">
            <?php esc_html_e('Reset the setup experience and go through onboarding again from the start. This disconnects Translangs and clears your categories and language list; your saved translations are kept.', 'translangs'); ?>
        </p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
            onsubmit="return confirm('<?php echo esc_js(__('Restart onboarding? This disconnects Translangs and clears your categories and languages.', 'translangs')); ?>');">
            <input type="hidden" name="action" value="translangs_restart">
            <?php wp_nonce_field('translangs_restart', 'translangs_restart_nonce'); ?>
            <button type="submit" class="button button-link-delete">
                <?php esc_html_e('Restart onboarding', 'translangs'); ?>
            </button>
        </form>
    </div>
</div>
