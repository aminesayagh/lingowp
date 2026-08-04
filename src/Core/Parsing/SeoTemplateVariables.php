<?php

namespace TransLangs\Core\Parsing;

/**
 * The single source of truth for SEO replacement-variable syntax, keyed by the
 * meta key that stores the template (plan-seo-integration-layer §2a).
 *
 * SEO plugins do NOT share one grammar: Yoast writes %%title%%, Rank Math writes
 * %title% and argument-bearing forms like %customfield(my_key)%. A Yoast-shaped
 * pattern silently matches nothing in a Rank Math template, so the allow-list
 * widening in TranslatableMetaKeys would otherwise make more templates
 * translatable while protecting none of them.
 *
 * Grammar is therefore resolved from the KEY, never sniffed from the text —
 * sniffing would misclassify ordinary prose containing percent signs.
 *
 * The same descriptor drives three jobs: skipping pure-template values at
 * discovery, validating writes, and (later) building output-matching patterns.
 */
final class SeoTemplateVariables
{
    /** Which SEO field a key holds, and whose variable syntax it uses. */
    private const DESCRIPTORS = [
        '_yoast_wpseo_title'     => ['field' => 'title',       'grammar' => 'yoast'],
        '_yoast_wpseo_metadesc'  => ['field' => 'description', 'grammar' => 'yoast'],
        'rank_math_title'        => ['field' => 'title',       'grammar' => 'rankmath'],
        'rank_math_description'  => ['field' => 'description', 'grammar' => 'rankmath'],
        '_seopress_titles_title' => ['field' => 'title',       'grammar' => 'yoast'],
        '_seopress_titles_desc'  => ['field' => 'description', 'grammar' => 'yoast'],
    ];

    /**
     * Argument lists are matched as one opaque token — %customfield( my_key )%
     * is a single unit and must never be split on the parentheses.
     *
     * The rankmath pattern is copied VERBATIM from Rank Math's own replacer
     * (includes/replace-variables/class-replacer.php:172). Tokenizing differently
     * from the engine that expands these would make our output matching drift:
     * note its plain-variable branch is `[^\s]+`, not `[a-z0-9_-]+`, so it accepts
     * far more than a naive pattern would.
     */
    private const GRAMMARS = [
        'yoast'    => '/%%[a-z0-9_-]+%%/i',
        'rankmath' => '/%(([a-z0-9_-]+)\(([^)]*)\)|[^\s]+)%/iu',
    ];

    /** Templates are plugin- and user-controlled input; keep the regex bounded. */
    private const MAX_TEMPLATE_LENGTH = 1000;
    private const MAX_VARIABLES       = 20;

    private function __construct() {}

    /**
     * Compile a template into a pattern that recognises its EXPANDED output.
     *
     * A stored template never appears verbatim on the page — the SEO plugin
     * replaces its variables first. Matching the template's *shape* recovers what
     * each variable expanded to:
     *
     *   Buy %%title%% online  ->  /^Buy (?<v0>.+?) online$/u
     *   "Buy Product Name online" then yields v0 = "Product Name".
     *
     * Captures are INDEXED, never named after the variable: `%%title%% | %%title%%`
     * would produce duplicate group names, and `%customfield(my_key)%` is not a
     * legal group name at all. The returned token map says what each index meant.
     *
     * Returns null when the template cannot be matched safely:
     *  - two variables with no literal between them (`(.+?)(.+?)` is ambiguous)
     *  - no literal text at all (the pattern would match every possible title)
     *  - over the size guards
     *
     * @return array{regex: string, tokens: array<string, string>}|null
     */
    public static function pattern(string $metaKey, string $template): ?array
    {
        if (!self::supports($metaKey) || $template === '' || strlen($template) > self::MAX_TEMPLATE_LENGTH) {
            return null;
        }

        $grammar = self::DESCRIPTORS[$metaKey]['grammar'];
        if (preg_match_all(self::GRAMMARS[$grammar], $template, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $found = $matches[0];
        if ($found === [] || count($found) > self::MAX_VARIABLES) {
            return null; // nothing to recover, or implausibly complex
        }

        $regex              = '';
        $tokens             = [];
        $cursor             = 0;
        $literalText        = '';
        $previousWasCapture = false;

        foreach ($found as $i => [$token, $offset]) {
            $literal = substr($template, $cursor, $offset - $cursor);

            // Adjacent captures cannot be split unambiguously.
            if ($literal === '' && $previousWasCapture) {
                return null;
            }

            $literalText       .= $literal;
            $regex             .= preg_quote($literal, '/') . '(?<v' . $i . '>.+?)';
            $tokens['v' . $i]   = $token;
            $cursor             = $offset + strlen($token);
            $previousWasCapture = true;
        }

        $tail         = substr($template, $cursor);
        $literalText .= $tail;
        $regex       .= preg_quote($tail, '/');

        // Separators alone ("%%title%% | %%sitename%%") are not enough to anchor a
        // match — a pattern of nothing but captures and punctuation would claim
        // every title on the site.
        if (preg_match('/\p{L}/u', $literalText) !== 1) {
            return null;
        }

        return ['regex' => '/^' . $regex . '$/u', 'tokens' => $tokens];
    }

    /**
     * The semantic name behind a variable token, so a recovered capture can be
     * mapped to something we actually know how to translate. `%%title%%` and
     * `%title%` both mean 'title'.
     */
    public static function tokenName(string $token): string
    {
        return strtolower(trim($token, '% '));
    }

    /** True when this key stores an SEO template whose variables we understand. */
    public static function supports(string $metaKey): bool
    {
        return isset(self::DESCRIPTORS[$metaKey]);
    }

    /** 'title' | 'description' | null — used by the output layer to pick a target tag. */
    public static function fieldFor(string $metaKey): ?string
    {
        return self::DESCRIPTORS[$metaKey]['field'] ?? null;
    }

    /**
     * Ordered variable tokens, as written. Repeats are kept: the write guard
     * compares multisets by count, so dropping one of two %%title%% must fail.
     *
     * @return string[]
     */
    public static function extract(string $metaKey, string $text): array
    {
        $grammar = self::DESCRIPTORS[$metaKey]['grammar'] ?? null;
        if ($grammar === null) {
            return [];
        }

        preg_match_all(self::GRAMMARS[$grammar], $text, $matches);

        return $matches[0];
    }

    /**
     * Whether a value carries prose worth translating once its variables are
     * stripped. `%%title%% %%sep%% %%sitename%%` is pure structure — it holds no
     * words, so making it a source row only invites a translator to break it.
     *
     * Mirrors PostMetaValuePaths::isTranslatable()'s rule (non-empty + at least
     * one letter) so the two agree on everything except the variable stripping.
     * Unsupported keys get no opinion here — their normal filter still applies.
     */
    public static function hasTranslatableProse(string $metaKey, string $text): bool
    {
        if (!self::supports($metaKey)) {
            return true;
        }

        $grammar = self::DESCRIPTORS[$metaKey]['grammar'];
        $bare    = preg_replace(self::GRAMMARS[$grammar], '', $text) ?? $text;

        return trim($bare) !== '' && preg_match('/\p{L}/u', $bare) === 1;
    }
}
