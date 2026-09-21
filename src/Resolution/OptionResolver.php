<?php

namespace LingoWP\Resolution;
use LingoWP\Resolution\RequestContext;

use LingoWP\Language\Application\ResolveRequestLanguage;
use LingoWP\Language\Domain\LanguageRegistry;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Shared\Text\TranslationKey;

final class OptionResolver
{
    use DeferredTranslationActivation;

    private const TRANSLATABLE_SHOWS = ['name' => true, 'description' => true];

    private ?array $map = null;

    private ResolveRequestLanguage $language;
    private RequestContext $context;
    private WpDbSourceRepository $repository;
    private LanguageRegistry $languageRegistry;

    public function __construct(
        ResolveRequestLanguage $language,
        RequestContext $context,
        WpDbSourceRepository $repository,
        LanguageRegistry $languageRegistry
    ) {
        $this->language         = $language;
        $this->context          = $context;
        $this->repository       = $repository;
        $this->languageRegistry = $languageRegistry;
    }

    public function register(): void
    {
        add_filter('bloginfo', [$this, 'bloginfo'], 10, 2);
    }

    public function bloginfo($output, $show = '')
    {
        if (!isset(self::TRANSLATABLE_SHOWS[(string) $show]) || !is_string($output) || $output === '' || !$this->isActive()) {
            return $output;
        }

        $hash = strtolower(bin2hex(TranslationKey::currentHash($output)));

        return $this->map()[$hash] ?? $output;
    }

    private function map(): array
    {
        if ($this->map === null) {
            $map = [];
            foreach ($this->languageRegistry->resolutionChain($this->lang) as $lang) {
                $map += $this->repository->fetchAllHashTranslations('option', $lang);
            }
            $this->map = $map;
        }

        return $this->map;
    }
}
