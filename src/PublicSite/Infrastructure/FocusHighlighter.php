<?php

namespace TransLangs\PublicSite\Infrastructure;

use InvalidArgumentException;
use TransLangs\TranslationMemory\Domain\RowRef;
use TransLangs\TranslationMemory\Domain\TranslationMemoryRepository;

/**
 * Frontend helper for the admin "View text" action.
 *
 * When a capable user opens a page with ?translangs_focus=<row_ref>, this looks
 * up the row's text and injects a small script that finds the matching element,
 * scrolls to it, and highlights it. Visible only to users who can edit content;
 * the page cache is bypassed for focus requests (see PublicRenderPipeline) so
 * the script is never served to anonymous visitors.
 */
final class FocusHighlighter
{
    public function __construct(private TranslationMemoryRepository $repository) {}

    public function register(): void
    {
        add_action('wp_footer', [$this, 'maybeRender'], 9999);
    }

    public function maybeRender(): void
    {
        if (empty($_GET['translangs_focus']) || !current_user_can('edit_posts')) {
            return;
        }

        try {
            $rowRef = RowRef::parse(sanitize_text_field(wp_unslash($_GET['translangs_focus'])));
        } catch (InvalidArgumentException) {
            return;
        }

        $row = $this->repository->find($rowRef);
        if ($row === null) {
            return;
        }

        // Locator priority: translated text first (the page is in the target
        // language), then the original as a fallback.
        $targets = array_values(array_filter([
            trim((string) ($row['translated_text'] ?? '')),
            trim((string) ($row['original_text'] ?? '')),
        ], static fn(string $text): bool => $text !== ''));

        if ($targets === []) {
            return;
        }

        $json = wp_json_encode($targets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>
        <style>
            .translangs-focus-highlight {
                background: #fff3b0 !important;
                outline: 2px solid #dba617;
                outline-offset: 2px;
                animation: translangs-focus-pulse 1s ease 2;
            }
            @keyframes translangs-focus-pulse {
                0%, 100% { background: #fff3b0; }
                50% { background: #ffe066; }
            }
        </style>
        <script>
        (function () {
            var targets = <?php echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;
            if (!targets || !targets.length) {
                return;
            }

            var skip = { SCRIPT: 1, STYLE: 1, NOSCRIPT: 1 };

            function inAdminBar(node) {
                while (node) {
                    if (node.id === 'wpadminbar') {
                        return true;
                    }
                    node = node.parentElement;
                }
                return false;
            }

            function findElement(text) {
                var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, null);
                var node, exact = null, partial = null;

                while ((node = walker.nextNode())) {
                    var parent = node.parentElement;
                    if (!parent || skip[parent.tagName] || inAdminBar(parent)) {
                        continue;
                    }

                    var value = (node.nodeValue || '').trim();
                    if (value === '') {
                        continue;
                    }

                    if (value === text) {
                        return parent;
                    }
                    if (!partial && value.indexOf(text) !== -1) {
                        partial = parent;
                    }
                }

                return exact || partial;
            }

            var element = null;
            for (var i = 0; i < targets.length && !element; i++) {
                element = findElement(targets[i]);
            }

            if (!element) {
                return;
            }

            element.classList.add('translangs-focus-highlight');
            element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        })();
        </script>
        <?php
    }
}
