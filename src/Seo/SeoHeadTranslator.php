<?php

namespace TransLangs\Seo;

use TransLangs\Core\Parsing\SeoTemplateVariables;
use TransLangs\Core\Repository\SourceTranslationRepository;
use TransLangs\Core\Source\Type\TranslatableMetaKeys;
use TransLangs\Core\Text\TL_Key;

/**
 * Translates the SEO <title> and meta description in the already-parsed page DOM
 * (plan-seo-integration-layer §3).
 *
 * This is the ENTIRE output layer, and it registers no filters. Rather than an
 * adapter per SEO plugin — each with its own hook names, argument counts and
 * variable-expansion order — it reads what was actually rendered. Native
 * WordPress, Yoast, Rank Math and SEOPress all emit the same two tags, so one
 * implementation covers them, and Yoast's indexable bypass stops mattering
 * because we never ask Yoast what it stored.
 *
 * Nothing is guessed: a string is replaced only when it matches a source we
 * already discovered. Anything unrecognised is left exactly as the plugin wrote
 * it.
 */
final class SeoHeadTranslator
{
    /** @var array<string, array<int, array{meta_key:string, original_text:string, translated_text:?string}>> */
    private array $stateCache = [];

    public function __construct(private SourceTranslationRepository $repository) {}

    /**
     * Translate the head tags of an already-parsed document, in place.
     *
     * Resolve-then-apply: every replacement is computed before any node is
     * written, so a failure midway cannot leave the head half-translated.
     */
    public function translate(\DOMDocument $dom, string $lang): void
    {
        // Only singular views have a post whose SEO fields we can resolve. On an
        // archive, taxonomy, author or search page get_queried_object_id() returns
        // a TERM/USER id — treating that as a post id would load an unrelated
        // post's SEO template (term 42 vs post 42).
        if ($lang === '' || !function_exists('is_singular') || !is_singular()) {
            return;
        }

        $postId = (int) get_queried_object_id();
        if ($postId <= 0) {
            return;
        }

        $changes = [];

        $title = $dom->getElementsByTagName('title')->item(0);
        if ($title instanceof \DOMElement) {
            $replacement = $this->resolve($title->textContent, $postId, $lang, 'title');
            if ($replacement !== null) {
                $changes[] = static function () use ($title, $replacement): void {
                    $title->textContent = $replacement;
                };
            }
        }

        foreach ($this->descriptionNodes($dom) as $meta) {
            $replacement = $this->resolve($meta->getAttribute('content'), $postId, $lang, 'description');
            if ($replacement !== null) {
                $changes[] = static function () use ($meta, $replacement): void {
                    $meta->setAttribute('content', $replacement);
                };
            }
        }

        foreach ($changes as $apply) {
            $apply();
        }
    }

    /**
     * Every <meta name="description">. Plugins occasionally emit more than one;
     * translating only the first would leave the head in two languages. The
     * attribute VALUE is compared case-insensitively (the parser normalises
     * attribute names, not their values).
     *
     * @return \DOMElement[]
     */
    private function descriptionNodes(\DOMDocument $dom): array
    {
        $out = [];
        foreach ($dom->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof \DOMElement) {
                continue;
            }
            if (strcasecmp(trim($meta->getAttribute('name')), 'description') === 0) {
                $out[] = $meta;
            }
        }

        return $out;
    }

    /**
     * The replacement for one rendered value, or null to leave it untouched.
     *
     * Ordered strategies (plan §Layer 2). Template-shape matching for explicit
     * templates carrying variables is Phase 4 and is deliberately absent: until it
     * lands, such a candidate simply does not match and the value is left alone.
     */
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

            // 0. Already our own output — makes the pass idempotent.
            if ($candidate['translated_text'] !== null && $this->matches($rendered, $candidate['translated_text'])) {
                return null;
            }

            if ($this->matches($rendered, $candidate['original_text'])) {
                $explicitMatched = true;

                // 1. Explicit, variable-free, translated → straight replacement.
                //    (A template WITH variables never reaches here verbatim: the
                //    plugin expands it before output — see strategy 2.)
                if (!$hasVariables && $candidate['translated_text'] !== null) {
                    $results[$candidate['translated_text']] = true;
                }
            }

            // 2. Explicit template with variables: match its expanded SHAPE.
            if ($hasVariables && $candidate['translated_text'] !== null) {
                $rebuilt = $this->rebuildFromTemplate($rendered, $candidate, $postId, $lang);
                if ($rebuilt !== null) {
                    $explicitMatched          = true;
                    $results[$rebuilt]        = true;
                }
            }
        }

        // Several candidates can match the same text — two SEO plugins installed,
        // or a migration leftover. Applying the first would make output depend on
        // descriptor order, so require consensus: one distinct result, or nothing.
        if ($results !== []) {
            return count($results) === 1 ? array_key_first($results) : null;
        }

        // 3. An explicit source matched but has no translation — authored text, not
        //    generated output. Substituting into it would corrupt real prose.
        if ($explicitMatched) {
            return null;
        }

        // 4. Nothing explicit matched: generated output. Known-part substitution is
        //    TITLE ONLY — a description is prose, and replacing a post title that
        //    appears in it as an ordinary word is exactly the guessing this design
        //    avoids.
        return $field === 'title'
            ? $this->substituteKnownParts($rendered, $postId, $lang)
            : null;
    }

    /**
     * Strategy 2 — recover what a template's variables expanded to, then rebuild
     * the same shape from the TRANSLATED template.
     *
     * Two patterns are tried, because both situations are live at once on a site
     * with two SEO plugins installed:
     *
     *   source pattern matches      the plugin rendered the untranslated template
     *                               (Yoast, from its indexable)
     *   translated pattern matches  the plugin already got the translated template
     *                               through Layer 1 and expanded that (Rank Math)
     *
     * Either way the rebuild is identical: translated template + captures, each
     * capture translated only if we recognise the variable behind it.
     *
     * @param array{meta_key:string, original_text:string, translated_text:?string} $candidate
     */
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

        // Rebuild by substituting each token occurrence in order. Tokens are keyed
        // v0..vN in the order they appear, and the translated template must carry
        // the same multiset (the write guard enforces that), so a positional walk
        // over ITS own token list is safe.
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
                ? $token                                   // unmatched variable: leave as written
                : $this->translateCapture($name, $captured, $postId, $lang);

            $position = strpos($out, $token);
            if ($position !== false) {
                $out = substr_replace($out, $replacement, $position, strlen($token));
            }
        }

        return $out === '' ? null : $out;
    }

    /**
     * Run one compiled template shape against the rendered value.
     *
     * A repeated variable produces separate captures; if they disagree the shape
     * did not really match and we fail closed rather than guess which is right.
     *
     * @return array<int, array{token:string, value:string}>|null
     */
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
                return null; // same variable expanded two different ways
            }
            $byName[$name] = $value;
            $out[]         = ['token' => $token, 'value' => $value];
        }

        return $out;
    }

    /**
     * Translate one recovered capture — but only for variables whose meaning we
     * know. A capture from `%customfield(company_code)%` could coincidentally
     * equal some unrelated translated string, so a blind hash lookup across the
     * whole memory would corrupt it. Anything unrecognised is returned verbatim.
     */
    private function translateCapture(string $name, string $captured, int $postId, string $lang): string
    {
        $post = get_post($postId);

        $known = [
            'title'    => [$post instanceof \WP_Post ? (string) $post->post_title : '', 'tl_post'],
            'excerpt'  => [$post instanceof \WP_Post ? (string) $post->post_excerpt : '', 'tl_post'],
            'sitename' => [(string) get_option('blogname'), 'tl_option'],
            'sitedesc' => [(string) get_option('blogdescription'), 'tl_option'],
        ];

        if (!isset($known[$name])) {
            return $captured;
        }

        [$source, $table] = $known[$name];
        if ($source === '' || !$this->matches($captured, $source)) {
            return $captured; // not the value we expected — do not touch it
        }

        return $this->translatedHash($table, $source, $lang) ?? $captured;
    }

    /**
     * Replace the parts of a generated title we can identify: the post title and
     * the site name. Both are hash-linked, so their translations come from the
     * maps the rest of the pipeline already uses.
     */
    private function substituteKnownParts(string $rendered, int $postId, string $lang): ?string
    {
        // Raw post_title, NOT get_the_title(): that applies the `the_title` filter,
        // which StructuredRenderResolver hooks — it would hand back the TRANSLATION
        // and we need the source string as the search needle. SEO plugins build the
        // document title from the raw post object too, so this is what is actually
        // sitting in the rendered output.
        $post      = get_post($postId);
        $postTitle = $post instanceof \WP_Post ? (string) $post->post_title : '';

        // get_option() is deliberate for the same reason: OptionResolver translates
        // at the `bloginfo` DISPLAY filter, never at retrieval, so this returns the
        // SOURCE string.
        $siteName = (string) get_option('blogname');

        $pairs = [];
        foreach ([[$postTitle, 'tl_post'], [$siteName, 'tl_option']] as [$source, $table]) {
            if (trim($source) === '') {
                continue;
            }
            $translated = $this->translatedHash($table, $source, $lang);
            if ($translated !== null && $translated !== $source) {
                $pairs[] = [$source, $translated];
            }
        }

        // Identical sources translating differently cannot be told apart by
        // position ("AI | AI"), so bail rather than substitute wrongly.
        if (count($pairs) === 2 && $pairs[0][0] === $pairs[1][0] && $pairs[0][1] !== $pairs[1][1]) {
            return null;
        }

        return $this->replaceDisjoint($rendered, $pairs);
    }

    /**
     * Apply every replacement in ONE pass over the original string.
     *
     * Sequential replacement corrupts overlapping needles: with post title
     * "WordPress" and site name "WordPress Developers", replacing the shorter one
     * first matches inside the longer. Sorting longest-first is still not enough,
     * because a replacement can contain a later needle. So: locate all matches
     * against the untouched original, drop overlaps (longest wins), then rewrite
     * right-to-left so earlier offsets stay valid.
     *
     * @param array<int, array{0:string,1:string}> $pairs
     */
    private function replaceDisjoint(string $subject, array $pairs): ?string
    {
        $hits = [];
        foreach ($pairs as [$source, $translated]) {
            foreach ($this->needles($source) as $needle) {
                $offset = mb_strpos($subject, $needle);
                if ($offset !== false) {
                    $hits[] = ['offset' => $offset, 'length' => mb_strlen($needle), 'text' => $translated];
                    break; // one occurrence per part
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

    /** True when a rendered value is one of a source string's escaped variants. */
    private function matches(string $rendered, string $source): bool
    {
        foreach ($this->needles($source) as $needle) {
            if ($rendered === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comparison variants for a stored source. DOM text is already
     * entity-decoded, so only texturization can differ — no esc_html() variants,
     * and never a normalized comparison (a normalized offset cannot be mapped
     * back to a raw one for replacement).
     *
     * @return string[]
     */
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

    /** Hash-link translation lookup for post titles and option branding. */
    private function translatedHash(string $tableKey, string $original, string $lang): ?string
    {
        $hash    = TL_Key::currentHash($original);
        $fetched = $this->repository->fetchHashTranslations($tableKey, [$hash], $lang);

        return $fetched[strtolower(bin2hex($hash))] ?? null;
    }

    /**
     * SEO candidates for a post, cached per request — the title and description
     * passes must not re-query, and a page can carry several meta nodes.
     *
     * @return array<int, array{meta_key:string, original_text:string, translated_text:?string}>
     */
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
