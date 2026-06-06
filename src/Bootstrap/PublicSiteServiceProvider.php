<?php

namespace TransLangs\Bootstrap;

use TransLangs\Language\Application\ResolveRequestLanguage;
use TransLangs\PageTranslation\Application\TranslateHtmlPage;
use TransLangs\PageTranslation\Domain\SkipRules;
use TransLangs\PageTranslation\Infrastructure\Html\HtmlParserFactory;
use TransLangs\PublicSite\Infrastructure\HreflangPresenter;
use TransLangs\PublicSite\Infrastructure\HtmlResponseGuard;
use TransLangs\PublicSite\Infrastructure\LanguageRouteRegistrar;
use TransLangs\PublicSite\Infrastructure\LanguageSwitcherShortcode;
use TransLangs\PublicSite\Infrastructure\LinkRewriter;
use TransLangs\PublicSite\Infrastructure\LocalizedUrlBuilder;
use TransLangs\PublicSite\Infrastructure\OutputBufferMiddleware;
use TransLangs\PublicSite\Infrastructure\RequestContext;

class PublicSiteServiceProvider
{
    public function register(ServiceContainer $container): void
    {
        // CoreServiceProvider wires ResolveRequestLanguage with LanguageRegistry;
        // reuse the same instance so language state is shared across providers.
        /** @var ResolveRequestLanguage $resolver */
        $resolver   = $container->get('language.resolve_request');
        $urlBuilder = new LocalizedUrlBuilder($resolver);
        $context    = new RequestContext();
        $guard      = new HtmlResponseGuard();

        $container->set('public_site.localized_url_builder', $urlBuilder);
        $container->set('public_site.request_context', $context);
        $container->set('public_site.html_response_guard', $guard);

        $routeRegistrar = new LanguageRouteRegistrar($resolver);
        $routeRegistrar->register();
        $container->set('public_site.language_route_registrar', $routeRegistrar);

        $renderer = new TranslateHtmlPage(
            new HtmlParserFactory(new SkipRules()),
            $container->get('translation_memory.resolve_translations'),
            $resolver,
            new LinkRewriter($resolver, $urlBuilder)
        );

        $buffer = new OutputBufferMiddleware($resolver, $context, $guard);
        $buffer->setPipeline(
            static fn(string $html, string $lang): string => $renderer->render($html, $lang)
        );
        $buffer->register();

        $container->set('page_translation.translate_html_page', $renderer);
        $container->set('public_site.output_buffer', $buffer);

        $switcher = new LanguageSwitcherShortcode($resolver, $urlBuilder);
        $switcher->register();
        $container->set('public_site.language_switcher', $switcher);

        $hreflang = new HreflangPresenter($resolver, $urlBuilder, $context);
        $hreflang->register();
        $container->set('public_site.hreflang_presenter', $hreflang);
    }
}
