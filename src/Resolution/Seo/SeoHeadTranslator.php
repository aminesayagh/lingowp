<?php

namespace LingoWP\Resolution\Seo;

use LingoWP\Shared\Parsing\SeoTemplateVariables;
use LingoWP\Database\Repository\WpDbSourceRepository;
use LingoWP\Shared\Source\TranslatableMetaKeys;
use LingoWP\Shared\Text\TranslationKey;

final class SeoHeadTranslator
{
    private array $stateCache = [];

    private WpDbSourceRepository $repository;

    public function __construct(WpDbSourceRepository $repository)
    {
        $this->repository = $repository;
    }

    public function translate(\DOMDocument $dom, string $lang): void
    {
        if ($lang === '' || !function_exists('is_singular') || !is_singular()) {
            return;
        }

        $postId = (int) get_queried_object_id();
        if ($postId <= 0) {
            return;
        }

        $changes = [];

        $title      = $dom->getElementsByTagName('title')->item(0);
        $finalTitle = null;
        if ($title instanceof \DOMElement) {
            $rendered    = trim($title->textContent);
            $replacement = $this->resolve($rendered, $postId, $lang, 'title');
            $finalTitle  = $replacement ?? $rendered;
            if ($replacement !== null) {
                $changes[] = static function () use ($title, $replacement): void {
                    $title->textContent = $replacement;
                };
            }
        }

        $finalDescription = null;
        foreach ($this->metaNodes($dom, 'name', 'description') as $meta) {
            $rendered          = trim($meta->getAttribute('content'));
            $replacement       = $this->resolve($rendered, $postId, $lang, 'description');
            $finalDescription ??= $replacement ?? $rendered;
            if ($replacement !== null) {
                $changes[] = static function () use ($meta, $replacement): void {
                    $meta->setAttribute('content', $replacement);
                };
            }
        }

        foreach ([
            ['property', 'og:title', 'og_title'],
            ['property', 'og:description', 'og_description'],
            ['name', 'twitter:title', 'twitter_title'],
            ['name', 'twitter:description', 'twitter_description'],
        ] as [$attribute, $value, $field]) {
            foreach ($this->metaNodes($dom, $attribute, $value) as $meta) {
                $replacement = $this->resolve(trim($meta->getAttribute('content')), $postId, $lang, $field);
                if ($replacement !== null) {
                    $changes[] = static function () use ($meta, $replacement): void {
                        $meta->setAttribute('content', $replacement);
                    };
                }
            }
        }

        foreach ($changes as $apply) {
            $apply();
        }

        $this->insertMissingOpenGraphTags($dom, $postId, $lang, $finalTitle, $finalDescription);
    }

    private function metaNodes(\DOMDocument $dom, string $attribute, string $value): array
    {
        $out = [];
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof \DOMElement) {
                continue;
            }
            if (strcasecmp(trim($meta->getAttribute($attribute)), $value) === 0) {
                $out[] = $meta;
            }
        }

        return $out;
    }

    private function insertMissingOpenGraphTags(
        \DOMDocument $dom,
        int $postId,
        string $lang,
        ?string $title,
        ?string $description
    ): void {
        if ($this->metaNodes($dom, 'property', 'og:title') !== []) {
            return;
        }

        $head = $dom->getElementsByTagName('head')->item(0);
        if (!$head instanceof \DOMElement) {
            return;
        }

        if ($description === null) {
            $post   = get_post($postId);
            $source = $post instanceof \WP_Post ? trim((string) $post->post_excerpt) : '';
            if ($source !== '') {
                $description = $this->translatedHash('post', $source, $lang) ?? $source;
            }
        }

        if ($title !== null && $title !== '') {
            $this->appendMetaTag($dom, $head, 'og:title', $title);
        }
        if ($description !== null && $description !== '') {
            $this->appendMetaTag($dom, $head, 'og:description', $description);
        }

        $this->appendMetaTag($dom, $head, 'og:type', get_post_type($postId) === 'post' ? 'article' : 'website');

        $canonical = function_exists('wp_get_canonical_url') ? wp_get_canonical_url($postId) : false;
        if (is_string($canonical) && $canonical !== '') {
            $this->appendMetaTag($dom, $head, 'og:url', $canonical);
        }

        $siteName = (string) get_option('blogname');
        if ($siteName !== '') {
            $this->appendMetaTag(
                $dom,
                $head,
                'og:site_name',
                $this->translatedHash('option', $siteName, $lang) ?? $siteName
            );
        }
    }

    private function appendMetaTag(\DOMDocument $dom, \DOMElement $head, string $property, string $content): void
    {
        $meta = $dom->createElement('meta');
        $meta->setAttribute('property', $property);
        $meta->setAttribute('content', $content);
        $head->appendChild($meta);
    }

    private function resolve(string $rendered, int $postId, string $lang, string $field): ?string
    {
        $rendered = trim($rendered);
        if ($rendered === '') {
            return null;
        }

        $candidates = array_filter(
            $this->states($postId, $lang),
            static fn (array $c): bool => SeoTemplateVariables::fieldFor($c['meta_key']) === $field
        );

        $explicitMatched = false;
        $results         = [];

        foreach ($candidates as $candidate) {
            $hasVariables = SeoTemplateVariables::extract($candidate['meta_key'], $candidate['original_text']) !== [];

            if ($candidate['translated_text'] !== null && $this->matches($rendered, $candidate['translated_text'])) {
                return null;
            }

            if ($this->matches($rendered, $candidate['original_text'])) {
                $explicitMatched = true;

                if (!$hasVariables && $candidate['translated_text'] !== null) {
                    $results[$candidate['translated_text']] = true;
                }
            }

            if ($hasVariables && $candidate['translated_text'] !== null) {
                $rebuilt = $this->rebuildFromTemplate($rendered, $candidate, $postId, $lang);
                if ($rebuilt !== null) {
                    $explicitMatched          = true;
                    $results[$rebuilt]        = true;
                }
            }
        }

        if ($results !== []) {
            return count($results) === 1 ? array_key_first($results) : null;
        }

        if ($explicitMatched) {
            return null;
        }

        return in_array($field, ['title', 'og_title', 'twitter_title'], true)
            ? $this->substituteKnownParts($rendered, $postId, $lang)
            : null;
    }

    private function rebuildFromTemplate(string $rendered, array $candidate, int $postId, string $lang): ?string
    {
        $metaKey    = $candidate['meta_key'];
        $translated = (string) $candidate['translated_text'];

        $captures = null;
        foreach ([$candidate['original_text'], $translated] as $shape) {
            foreach ($this->needles($shape) as $variant) {
                $captures = $this->captureWith($metaKey, $variant, $rendered);
                if ($captures !== null) {
                    break 2;
                }
            }
        }
        if ($captures === null) {
            return null;
        }

        $rebuiltPattern = SeoTemplateVariables::pattern($metaKey, $translated);
        if ($rebuiltPattern === null) {
            return null;
        }

        $out    = $translated;
        $byName = [];
        foreach ($captures as $capture) {
            $byName[SeoTemplateVariables::tokenName($capture['token'])] = $capture['value'];
        }

        foreach ($rebuiltPattern['tokens'] as $token) {
            $name        = SeoTemplateVariables::tokenName($token);
            $captured    = $byName[$name] ?? null;
            $replacement = $captured === null
                ? $token
                : $this->translateCapture($name, $captured, $postId, $lang);

            $position = strpos($out, $token);
            if ($position !== false) {
                $out = substr_replace($out, $replacement, $position, strlen($token));
            }
        }

        return $out === '' ? null : $out;
    }

    private function captureWith(string $metaKey, string $shape, string $rendered): ?array
    {
        $compiled = SeoTemplateVariables::pattern($metaKey, $shape);
        if ($compiled === null) {
            return null;
        }

        $ok = preg_match($compiled['regex'], $rendered, $m);
        if ($ok !== 1 || preg_last_error() !== PREG_NO_ERROR) {
            return null;
        }

        $out    = [];
        $byName = [];
        foreach ($compiled['tokens'] as $group => $token) {
            $value = (string) ($m[$group] ?? '');
            $name  = SeoTemplateVariables::tokenName($token);

            if (array_key_exists($name, $byName) && $byName[$name] !== $value) {
                return null;
            }
            $byName[$name] = $value;
            $out[]         = ['token' => $token, 'value' => $value];
        }

        return $out;
    }

    private function translateCapture(string $name, string $captured, int $postId, string $lang): string
    {
        $post = get_post($postId);

        $known = [
            'title'    => [$post instanceof \WP_Post ? (string) $post->post_title : '', 'post'],
            'excerpt'  => [$post instanceof \WP_Post ? (string) $post->post_excerpt : '', 'post'],
            'sitename' => [(string) get_option('blogname'), 'option'],
            'sitedesc' => [(string) get_option('blogdescription'), 'option'],
        ];

        $name = self::canonicalTokenName($name);

        if (!isset($known[$name])) {
            return $captured;
        }

        [$source, $table] = $known[$name];
        if ($source === '' || !$this->matches($captured, $source)) {
            return $captured;
        }

        return $this->translatedHash($table, $source, $lang) ?? $captured;
    }

    private static function canonicalTokenName(string $name): string
    {
        $aliases = function_exists('apply_filters')
            ? apply_filters('lingowp_seo_template_token_aliases', [])
            : [];

        return is_array($aliases) ? (string) ($aliases[$name] ?? $name) : $name;
    }

    private function substituteKnownParts(string $rendered, int $postId, string $lang): ?string
    {
        $post      = get_post($postId);
        $postTitle = $post instanceof \WP_Post ? (string) $post->post_title : '';

        $siteName = (string) get_option('blogname');

        $pairs = [];
        foreach ([[$postTitle, 'post'], [$siteName, 'option']] as [$source, $table]) {
            if (trim($source) === '') {
                continue;
            }
            $translated = $this->translatedHash($table, $source, $lang);
            if ($translated !== null && $translated !== $source) {
                $pairs[] = [$source, $translated];
            }
        }

        if (count($pairs) === 2 && $pairs[0][0] === $pairs[1][0] && $pairs[0][1] !== $pairs[1][1]) {
            return null;
        }

        return $this->replaceDisjoint($rendered, $pairs);
    }

    private function replaceDisjoint(string $subject, array $pairs): ?string
    {
        $hits = [];
        foreach ($pairs as [$source, $translated]) {
            foreach ($this->needles($source) as $needle) {
                $offset = mb_strpos($subject, $needle);
                if ($offset !== false) {
                    $hits[] = ['offset' => $offset, 'length' => mb_strlen($needle), 'text' => $translated];
                    break;
                }
            }
        }
        if ($hits === []) {
            return null;
        }

        usort($hits, static fn (array $a, array $b): int => $b['length'] <=> $a['length']);

        $kept = [];
        foreach ($hits as $hit) {
            foreach ($kept as $existing) {
                $overlaps = $hit['offset'] < $existing['offset'] + $existing['length']
                    && $existing['offset'] < $hit['offset'] + $hit['length'];
                if ($overlaps) {
                    continue 2;
                }
            }
            $kept[] = $hit;
        }

        usort($kept, static fn (array $a, array $b): int => $b['offset'] <=> $a['offset']);

        foreach ($kept as $hit) {
            $subject = mb_substr($subject, 0, $hit['offset'])
                . $hit['text']
                . mb_substr($subject, $hit['offset'] + $hit['length']);
        }

        return $subject;
    }

    private function matches(string $rendered, string $source): bool
    {
        foreach ($this->needles($source) as $needle) {
            if ($rendered === $needle) {
                return true;
            }
        }

        return false;
    }

    private function needles(string $source): array
    {
        $source = trim($source);
        if ($source === '') {
            return [];
        }

        $variants = [$source];
        if (function_exists('wptexturize')) {
            $variants[] = wptexturize($source);
        }

        return array_values(array_unique($variants));
    }

    private function translatedHash(string $tableKey, string $original, string $lang): ?string
    {
        $hash    = TranslationKey::currentHash($original);
        $fetched = $this->repository->fetchHashTranslations($tableKey, [$hash], $lang);

        return $fetched[strtolower(bin2hex($hash))] ?? null;
    }

    private function states(int $postId, string $lang): array
    {
        $key = $postId . '|' . $lang;

        return $this->stateCache[$key] ??= $this->repository->fetchPostMetaTranslationStates(
            $postId,
            array_values(array_filter(TranslatableMetaKeys::keys(), [SeoTemplateVariables::class, 'supports'])),
            $lang
        );
    }
}
