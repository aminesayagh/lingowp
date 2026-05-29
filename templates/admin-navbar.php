<?php
/**
 * Shared top navigation for TransLangs admin screens.
 *
 * Expects:
 *   $tabs    array<string slug, string label>
 *   $current string  active page slug
 *
 * @package TransLangs
 */

if (!defined('ABSPATH')) {
    exit;
}

/** @var array<string, string> $tabs */
/** @var string $current */
?>
<nav class="translangs-navbar">
    <?php foreach ($tabs as $slug => $label) : ?>
        <a
            class="translangs-navbar__item <?php echo $slug === $current ? 'is-active' : ''; ?>"
            href="<?php echo esc_url(admin_url('admin.php?page=' . $slug)); ?>"
        >
            <?php echo esc_html($label); ?>
        </a>
    <?php endforeach; ?>
</nav>
