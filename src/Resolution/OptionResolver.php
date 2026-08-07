<?php

namespace TransLangs\Resolution;
use TransLangs\Resolution\RequestContext;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\Language\Domain\LanguageRegistry;
use TransLangs\Database\Repository\WpDbSourceRepository;
use TransLangs\Shared\Text\TL_Key;

/**
 * Frontend rendering for translatable wp_options text (branding). Replaces the
 * value at the DISPLAY layer (the `bloginfo` filter), never at option retrieval,
 * so business/plugin logic always receives the original option value while the
 * visitor sees the translation (per the wp_options translation strategy spec).
 *
 * Scope: the two universal branding strings (site name + tagline), which surface
 * through `bloginfo` (e.g. <title>, meta tags) outside the page body. All other
 * discovered wp_options text (theme mods, widgets) is swapped in the final-HTML
 * output pass in PublicRenderPipeline, which shares the same tl_option hash map.
 */
final class OptionResolver
{
    use DeferredTranslationActivation;

    /** bloginfo `$show` values that carry translatable branding text. */
    private const TRANSLATABLE_SHOWS = ['name' => true, 'description' => true];

    /** @var array<string, string>|null hex(original_hash) => translated_text */
    private ?array $map = null;

    public function __construct(
        private ResolveRequestLanguage $language,
        private RequestContext $context,
        private WpDbSourceRepository $repository,
        private LanguageRegistry $languageRegistry
    ) {}

    public function register(): void
    {
        add_filter('bloginfo', [$this, 'bloginfo'], 10, 2);
    }

    /**
     * @param mixed $output
     * @param mixed $show
     * @return mixed
     */
    public function bloginfo($output, $show = '')
    {
        if (!isset(self::TRANSLATABLE_SHOWS[(string) $show]) || !is_string($output) || $output === '' || !$this->isActive()) {
            return $output;
        }

        // Hash-link: the rendered text IS the key (like post/term/html) — no
        // need to know the option row id.
        $hash = strtolower(bin2hex(TL_Key::currentHash($output)));

        return $this->map()[$hash] ?? $output;
    }

    /** @return array<string, string> */
    private function map(): array
    {
        if ($this->map === null) {
            $map = [];
            foreach ($this->languageRegistry->resolutionChain($this->lang) as $lang) {
                $map += $this->repository->fetchAllHashTranslations('tl_option', $lang);
            }
            $this->map = $map;
        }

        return $this->map;
    }
}
