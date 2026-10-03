<?php

namespace LingoWP\LocalizationRouting;

/**
 * Which parts of a URL path are OBJECT SLUGS, according to WordPress's own
 * compiled rewrite rules.
 *
 * Slug translation rewrites path segments, and a segment's spelling says
 * nothing about its role: `/author/jean/` and `/about/jean/` look identical to
 * a per-segment map, but the first `jean` is a user nicename and rewriting it
 * serves a different author's archive. The previous answer was a blacklist of
 * route words, which can only ever protect BASES — never the argument that
 * follows one — while simultaneously blocking a legitimate page slugged
 * "author" from translating anywhere on the site.
 *
 * The rules already carry the answer. Each one is `regex => index.php?query`,
 * and the query says which capture index feeds which query var:
 *
 *   author/([^/]+)/?$                   => index.php?author_name=$matches[1]
 *   search/(.+)/?$                      => index.php?s=$matches[1]
 *   category/(.+?)/?$                   => index.php?category_name=$matches[1]
 *   (.?.+?)/comment-page-([0-9]{1,})/?$ => index.php?pagename=$matches[1]&cpage=$matches[2]
 *
 * So: match the path, then keep only the captures whose var is an object-slug
 * var. `author_name`, `s`, `year`, `page`, `cpage`, `feed`, `embed` and anything
 * unrecognised are left alone — and route BASES need no protecting at all,
 * because a literal in a regex is never a capture.
 *
 * WHY THE FIRST STRUCTURAL MATCH IS ENOUGH: with verbose page rules on (any
 * %postname%/%category%/%tag%/%author%-leading permalink structure), core merges
 * rules as ... root, comments, search, author, date, page, post, extra
 * (class-wp-rewrite.php:1455), so every specific rule precedes the generic
 * page/post pair and wins the match. Core may later reject a page rule via
 * get_page_by_path() and continue to the post rule, but `pagename` and `name`
 * are BOTH slug vars, so the role conclusion is identical either way. That is
 * what makes this safe without replicating core's verbose-page check.
 *
 * ponytail: depends on that merge order. A plugin injecting a rule through
 * extra_rules_top fails CLOSED — an unrecognised query var is not a slug var,
 * so nothing is mapped. Upgrade path, if a real site ever needs it: match the
 * path against each rule in turn and consult get_page_by_path() the way core
 * does, instead of taking the first structural hit.
 */
final class RouteSlugSpans
{
    /**
     * Query vars whose value is an object slug, independent of the site's
     * registered types. Callers add the post-type and taxonomy query vars.
     */
    public const CORE_SLUG_VARS = [
        'pagename',
        'name',
        'attachment',
        'category_name',
        'tag',
        'term',
    ];

    private function __construct() {}

    /**
     * Byte spans of $path that hold an object slug, as [offset, length] pairs,
     * ordered last-first so a caller can substitute without shifting offsets.
     *
     * Returns [] when no rule matches: an unroutable path stays unroutable, and
     * a 404 stays a 404.
     *
     * Pure — no WordPress, no database — so tests/slug_map_check.php can drive
     * the whole role table from fixture rules.
     *
     * @param array<string, string> $rules    regex => index.php?query, in core's order
     * @param array<string, true>   $slugVars query var name => true
     * @return array<int, array{0:int,1:int}>
     */
    public static function find(string $path, array $rules, array $slugVars): array
    {
        $subject = ltrim($path, '/');
        if ($subject === '' || $rules === [] || $slugVars === []) {
            return [];
        }

        $offset = strlen($path) - strlen($subject); // the leading slash we trimmed

        foreach ($rules as $regex => $query) {
            if (!is_string($regex) || !is_string($query)) {
                continue;
            }

            // Same matching core does, including its urldecode() second chance.
            if (preg_match('#^' . $regex . '#', $subject, $matches, PREG_OFFSET_CAPTURE) !== 1) {
                continue;
            }

            $slugIndexes = self::slugCaptureIndexes($query, $slugVars);
            if ($slugIndexes === []) {
                return []; // a rule matched, and none of its captures is a slug
            }

            $spans = [];
            foreach ($slugIndexes as $index) {
                // An unmatched optional group reports offset -1.
                if (!isset($matches[$index]) || $matches[$index][1] < 0 || $matches[$index][0] === '') {
                    continue;
                }

                $spans[] = [$offset + $matches[$index][1], strlen($matches[$index][0])];
            }

            usort($spans, static fn(array $a, array $b): int => $b[0] <=> $a[0]);

            return $spans;
        }

        return [];
    }

    /**
     * Capture indexes in a rule's query string that feed an object-slug var.
     *
     * The query is WordPress's own `index.php?pagename=$matches[1]&page=$matches[2]`
     * shape, so this reads the pairing straight out of it rather than guessing.
     *
     * @param array<string, true> $slugVars
     * @return array<int, int>
     */
    private static function slugCaptureIndexes(string $query, array $slugVars): array
    {
        $position = strpos($query, '?');
        if ($position === false) {
            return [];
        }

        $indexes = [];

        foreach (explode('&', substr($query, $position + 1)) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$var, $value] = $parts;
            if (!isset($slugVars[$var])) {
                continue;
            }

            if (preg_match('/^\$matches\[([0-9]+)\]$/', $value, $m) === 1) {
                $indexes[] = (int) $m[1];
            }
        }

        return $indexes;
    }
}
