<?php

if (!defined('ABSPATH')) {
    exit;
}

$lingowp_flag = static function (array $item): void {
    if ($item['flag_url'] === '') {
        return;
    }
    ?>
    <img class="lingowp-switcher__flag<?php echo $item['flag_square'] ? ' lingowp-switcher__flag--square' : ''; ?>" src="<?php echo esc_url($item['flag_url']); ?>" alt="" width="20" height="<?php echo $item['flag_square'] ? '20' : '15'; ?>" decoding="async">
    <?php
};

$lingowp_label = static function (array $item) use ($lingowp_flag): void {
    if ($item['flag_position'] === 'before') {
        $lingowp_flag($item);
    }
    ?>
    <?php if ($item['sr_text'] !== '') : ?>
        <span class="lingowp-switcher__text" lang="<?php echo esc_attr($item['text_lang']); ?>" dir="<?php echo esc_attr($item['text_dir']); ?>" aria-hidden="true"><?php echo esc_html($item['text']); ?></span>
        <span class="lingowp-switcher__sr" lang="<?php echo esc_attr($item['sr_lang']); ?>" dir="<?php echo esc_attr($item['sr_dir']); ?>"><?php echo esc_html($item['sr_text']); ?></span>
    <?php else : ?>
        <span class="<?php echo $item['text_visible'] ? 'lingowp-switcher__text' : 'lingowp-switcher__sr'; ?>" lang="<?php echo esc_attr($item['text_lang']); ?>" dir="<?php echo esc_attr($item['text_dir']); ?>"><?php echo esc_html($item['text']); ?></span>
    <?php endif; ?>
    <?php
    if ($item['flag_position'] === 'after') {
        $lingowp_flag($item);
    }
};
?>
<nav
    id="<?php echo esc_attr($switcher['id']); ?>"
    class="<?php echo esc_attr($switcher['classes']); ?>"
    <?php if ($switcher['style'] !== '') : ?>
        style="<?php echo esc_attr($switcher['style']); ?>"
    <?php endif; ?>
    aria-label="<?php echo esc_attr($switcher['label']); ?>"
    data-lingowp-switcher
    data-open-on="<?php echo esc_attr($switcher['open_on']); ?>"
    <?php if ($switcher['cookie'] !== '') : ?>
        data-cookie="<?php echo esc_attr($switcher['cookie']); ?>"
    <?php endif; ?>
>
    <?php if ($switcher['layout'] === 'dropdown') : ?>
        <button type="button" class="lingowp-switcher__trigger" aria-expanded="false" aria-controls="<?php echo esc_attr($switcher['list_id']); ?>">
            <?php $lingowp_label($switcher['current']); ?>
        </button>
    <?php endif; ?>
    <ul id="<?php echo esc_attr($switcher['list_id']); ?>" class="lingowp-switcher__list">
        <?php foreach ($switcher['items'] as $lingowp_item) : ?>
            <li class="lingowp-switcher__item<?php echo $lingowp_item['current'] ? ' is-current' : ''; ?>">
                <?php if ($lingowp_item['current']) : ?>
                    <span
                        class="lingowp-switcher__link"
                        lang="<?php echo esc_attr($lingowp_item['language_tag']); ?>"
                        aria-current="true"
                    >
                        <?php $lingowp_label($lingowp_item); ?>
                    </span>
                <?php else : ?>
                    <a
                        class="lingowp-switcher__link"
                        href="<?php echo esc_url($lingowp_item['url']); ?>"
                        hreflang="<?php echo esc_attr($lingowp_item['language_tag']); ?>"
                        lang="<?php echo esc_attr($lingowp_item['language_tag']); ?>"
                        data-lingowp-lang="<?php echo esc_attr($lingowp_item['code']); ?>"
                    >
                        <?php $lingowp_label($lingowp_item); ?>
                    </a>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
