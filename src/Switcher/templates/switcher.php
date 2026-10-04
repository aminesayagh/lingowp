<?php
/**
 * Language switcher markup.
 *
 * Override: copy this file to your-theme/lingowp/switcher.php.
 *
 * $switcher keys: id, list_id, layout ('dropdown' | 'horizontal' | 'vertical'),
 * classes, label, open_on, cookie, current (one item), items (list of items).
 * Item keys: code, url, language_tag, dir, current, text, text_visible,
 * flag_url, flag_position. Values are raw — escape at output, as below.
 *
 * Keep data-lingowp-switcher and the __trigger / __list classes if the bundled
 * script should keep turning the dropdown layout into a dropdown. Without the
 * script the list stays visible as plain links and the button stays hidden.
 *
 * @package LingoWP
 *
 * @var array<string, mixed> $switcher
 */

if (!defined('ABSPATH')) {
    exit;
}

$lingowp_flag = static function (array $item): void {
    if ($item['flag_url'] === '') {
        return;
    }
    ?>
    <img class="lingowp-switcher__flag" src="<?php echo esc_url($item['flag_url']); ?>" alt="" width="20" height="15" decoding="async">
    <?php
};

$lingowp_label = static function (array $item) use ($lingowp_flag): void {
    if ($item['flag_position'] === 'before') {
        $lingowp_flag($item);
    }
    ?>
    <span class="<?php echo $item['text_visible'] ? 'lingowp-switcher__text' : 'lingowp-switcher__sr'; ?>" dir="<?php echo esc_attr($item['dir']); ?>"><?php echo esc_html($item['text']); ?></span>
    <?php
    if ($item['flag_position'] === 'after') {
        $lingowp_flag($item);
    }
};
?>
<nav
    id="<?php echo esc_attr($switcher['id']); ?>"
    class="<?php echo esc_attr($switcher['classes']); ?>"
    aria-label="<?php echo esc_attr($switcher['label']); ?>"
    data-lingowp-switcher
    data-open-on="<?php echo esc_attr($switcher['open_on']); ?>"
    <?php if ($switcher['cookie'] !== '') : ?>
        data-cookie="<?php echo esc_attr($switcher['cookie']); ?>"
    <?php endif; ?>
>
    <?php if ($switcher['layout'] === 'dropdown') : ?>
        <button type="button" class="lingowp-switcher__trigger" aria-expanded="false" aria-controls="<?php echo esc_attr($switcher['list_id']); ?>" hidden>
            <?php $lingowp_label($switcher['current']); ?>
        </button>
    <?php endif; ?>
    <ul id="<?php echo esc_attr($switcher['list_id']); ?>" class="lingowp-switcher__list">
        <?php foreach ($switcher['items'] as $lingowp_item) : ?>
            <li class="lingowp-switcher__item<?php echo $lingowp_item['current'] ? ' is-current' : ''; ?>">
                <a
                    class="lingowp-switcher__link"
                    href="<?php echo esc_url($lingowp_item['url']); ?>"
                    hreflang="<?php echo esc_attr($lingowp_item['language_tag']); ?>"
                    lang="<?php echo esc_attr($lingowp_item['language_tag']); ?>"
                    data-lingowp-lang="<?php echo esc_attr($lingowp_item['code']); ?>"
                    <?php if ($lingowp_item['current']) : ?>
                        aria-current="page"
                    <?php endif; ?>
                >
                    <?php $lingowp_label($lingowp_item); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</nav>
