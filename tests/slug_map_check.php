<?php

/**
 * Standalone check for translated URL path segments (SlugMap).
 * Run:  php tests/slug_map_check.php
 * Exits non-zero if any case fails. No WordPress, no DB.
 *
 * Covers the two pure halves of the feature:
 *   1. build()       — the conflict rules that decide which derived slugs are
 *                      safe to use. This is where a mistake serves the WRONG
 *                      PAGE, so every rule gets its own case.
 *   2. mapSegments() — walking a path and swapping whole segments.
 *
 * The WordPress-facing half (the queries, the published-language gate, the
 * transient) is covered by tests/db/slug_translation_it.php.
 */

declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

use LingoWP\LocalizationRouting\RouteSlugSpans;
use LingoWP\LocalizationRouting\SlugMap;

$failed = 0;
$total  = 0;

function ok(string $label, bool $cond, string $detail = ''): void
{
    global $failed, $total;
    $total++;
    if ($cond) {
        echo "PASS  $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
    if ($detail !== '') {
        echo "        $detail\n";
    }
}

/**
 * @param array<int, array{0:int,1:string,2:?string}> $tuples [owner_id, src, dst]
 * @return array<int, array{owner_id:int, src:string, dst:?string}>
 */
function rows(array $tuples): array
{
    return array_map(
        static fn(array $t): array => ['owner_id' => $t[0], 'src' => $t[1], 'dst' => $t[2]],
        $tuples
    );
}

/**
 * build() takes the reserved sets explicitly now (loadRows()/realSlugs() own
 * them WP-side, so build() stays pure). Most cases here only care about the
 * rows, so default the real-slug set to every row's own slug — which is what
 * the site would really contain.
 *
 * @param array<int, array{owner_id:int, src:string, dst:?string}> $rows
 * @param array<int, string> $grammar
 * @param array<int, string>|null $realExtra
 */
function build(array $rows, ?array $realExtra = null): array
{
    $real = [];
    foreach ($rows as $row) {
        if ($row['src'] !== '') {
            $real[$row['src']] = true;
        }
    }
    foreach ($realExtra ?? [] as $slug) {
        $real[$slug] = true;
    }

    return SlugMap::build($rows, $real);
}

function dump(array $map): string
{
    $out = [];
    foreach ($map as $k => $v) {
        $out[] = "$k=>$v";
    }

    return $out === [] ? '(empty)' : implode(' ', $out);
}

// ── 1. build(): the happy path ───────────────────────────────────────────────

echo "\n-- build: baseline --\n";

$maps = build(rows([
    [1, 'about', 'a-propos'],
    [2, 'team', 'equipe'],
    [3, 'contact', null],      // not translated yet
]));

ok(
    'translated slugs map both ways',
    $maps['forward'] === ['about' => 'a-propos', 'team' => 'equipe']
    && $maps['reverse'] === ['a-propos' => 'about', 'equipe' => 'team'],
    dump($maps['forward']) . ' | ' . dump($maps['reverse'])
);
ok(
    'an untranslated slug is in neither map',
    !isset($maps['forward']['contact']) && !in_array('contact', $maps['reverse'], true),
    dump($maps['forward'])
);

// ── 2. build(): rule 1 — same source slug, different translations ────────────
// WordPress slugs are unique only per type+parent, so this is normal, not a
// conflict: the first claim wins and both pages still resolve, because the
// parent segments keep their full paths distinct.

echo "\n-- build: rule 1, same slug claimed twice --\n";

$maps = build(rows([
    [10, 'team', 'equipe'],
    [20, 'team', 'brigade'],
]));

ok(
    'first claim wins the segment',
    $maps['forward'] === ['team' => 'equipe'],
    dump($maps['forward'])
);
ok(
    'the losing translation is not reachable in reverse',
    !isset($maps['reverse']['brigade']),
    dump($maps['reverse'])
);

// ── 3. build(): rule 2 — two source slugs want one translated slug ───────────
// The dangerous one. Without this rule BOTH pages' links become /fr/a-propos/,
// so one page is unreachable and serves the other's content.

echo "\n-- build: rule 2, two slugs want one translation --\n";

$maps = build(rows([
    [11, 'about-us', 'a-propos'],
    [48, 'concerning-us', 'a-propos'],
]));

ok(
    'the winner keeps the translated slug',
    ($maps['forward']['about-us'] ?? null) === 'a-propos',
    dump($maps['forward'])
);
ok(
    'the loser is dropped from forward, so it keeps its source slug',
    !isset($maps['forward']['concerning-us']),
    dump($maps['forward'])
);
ok(
    'reverse is injective — one translated slug, one target',
    $maps['reverse'] === ['a-propos' => 'about-us'],
    dump($maps['reverse'])
);
ok(
    'reverse has no duplicate values at all',
    count(array_unique($maps['reverse'])) === count($maps['reverse']),
    dump($maps['reverse'])
);

// ── 4. build(): rule 3 — derived slug collides with a REAL slug ──────────────
// A page whose own slug is "a-propos" already owns /fr/a-propos/ by source-slug
// passthrough. Minting that URL for a different page would serve the wrong one.
// Dropping only the reverse entry is not enough: forward is what mints the href.

echo "\n-- build: rule 3, derived slug is already a real slug --\n";

$maps = build(rows([
    [5, 'aboutus', 'a-propos'],
    [9, 'a-propos', null],      // a real page that owns this slug
]));

ok(
    'dropped from forward (no href is ever minted for it)',
    !isset($maps['forward']['aboutus']),
    dump($maps['forward'])
);
ok(
    'dropped from reverse (the real page keeps resolving)',
    !isset($maps['reverse']['a-propos']),
    dump($maps['reverse'])
);

// The real slug reserves itself even when it is listed after the claimant, and
// even when the real page is the one with a translation.
$maps = build(rows([
    [9, 'a-propos', 'au-sujet'],
    [5, 'aboutus', 'a-propos'],
]));
ok(
    'reservation does not depend on row order',
    !isset($maps['forward']['aboutus']) && $maps['forward'] === ['a-propos' => 'au-sujet'],
    dump($maps['forward'])
);

// A title whose translation sanitizes back to the page's own slug is a no-op,
// not a self-collision.
$maps = build(rows([[7, 'contact', 'contact']]));
ok(
    'a translation equal to the source slug produces no entry',
    $maps['forward'] === [] && $maps['reverse'] === [],
    dump($maps['forward'])
);

// ── 5. build(): the global invariant ────────────────────────────────────────

echo "\n-- build: invariants over a mixed set --\n";

$maps = build(rows([
    [1, 'about', 'a-propos'],
    [2, 'team', 'equipe'],
    [3, 'crew', 'equipe'],       // rule 2 loser
    [4, 'news', null],
    [5, 'equipe', null],         // rule 3: reserves "equipe" outright
    [6, 'shop', 'boutique'],
]));

$reserved = ['about', 'team', 'crew', 'news', 'equipe', 'shop'];

ok(
    'no translated slug shadows a real slug',
    array_intersect(array_keys($maps['reverse']), $reserved) === [],
    dump($maps['reverse'])
);
ok(
    'reserved "equipe" knocks out both claimants',
    !isset($maps['forward']['team']) && !isset($maps['forward']['crew']),
    dump($maps['forward'])
);
ok(
    'unaffected rows still map',
    $maps['forward'] === ['about' => 'a-propos', 'shop' => 'boutique'],
    dump($maps['forward'])
);

// ── 5d. build(): rule 3 with a WIDER real-slug set ──────────────────────────
// Reservation is broader than translatability: an old-slug alias or a private
// page still owns its URL even though neither is a mapping candidate.

echo "\n-- build: rule 3 over slugs with no row of their own --\n";

$maps = build(
    rows([[1, 'claimant', 'lingering']]),
    ['lingering']   // e.g. a _wp_old_slug alias, a private page, a language prefix
);

ok(
    'a reserved slug with no row of its own still blocks the mapping',
    $maps['forward'] === [] && $maps['reverse'] === [],
    dump($maps['forward'])
);

// ── 6. mapSegments(): path walking ──────────────────────────────────────────

echo "\n-- mapSegments --\n";

$forward = ['about' => 'a-propos', 'team' => 'equipe'];
$reverse = ['a-propos' => 'about', 'equipe' => 'team'];

$cases = [
    ['/about/',          '/a-propos/'],
    ['/about',           '/a-propos'],
    ['/about/team/',     '/a-propos/equipe/'],
    ['/about/team',      '/a-propos/equipe'],
    ['about/team',       'a-propos/equipe'],      // no leading slash in a query var
    ['/',                '/'],
    ['',                 ''],
    ['/news/',           '/news/'],               // unknown segment untouched
    ['/about/news/',     '/a-propos/news/'],      // partial hit
    ['/teamwork/',       '/teamwork/'],           // substring must NOT match
    ['/about/about/',    '/a-propos/a-propos/'],  // repeated segment
];

foreach ($cases as [$in, $expected]) {
    $got = SlugMap::mapSegments($in, $forward);
    ok("localize [$in] -> $expected", $got === $expected, $got);
}

foreach ($cases as [$expectedBack, $localized]) {
    $got = SlugMap::mapSegments($localized, $reverse);
    ok("toSource [$localized] -> $expectedBack", $got === $expectedBack, $got);
}

ok(
    'round trip is identity',
    SlugMap::mapSegments(SlugMap::mapSegments('/about/team/', $forward), $reverse) === '/about/team/',
    SlugMap::mapSegments(SlugMap::mapSegments('/about/team/', $forward), $reverse)
);
ok(
    'an empty map is a pass-through',
    SlugMap::mapSegments('/about/team/', []) === '/about/team/'
);

// Non-Latin slugs arrive percent-encoded in a URL path but decoded in a query
// var. Keys are decoded and values raw, so both spellings resolve.
$encoded = ['%d8%b9%d9%86' => 'about'];
ok(
    'a percent-encoded path segment matches a decoded key',
    SlugMap::mapSegments('/%D8%B9%D9%86/', ['عن' => 'about']) === '/about/',
    SlugMap::mapSegments('/%D8%B9%D9%86/', ['عن' => 'about'])
);
ok(
    'an already-decoded segment matches the same key',
    SlugMap::mapSegments('/عن/', ['عن' => 'about']) === '/about/',
    SlugMap::mapSegments('/عن/', ['عن' => 'about'])
);
unset($encoded);

// ── 7. RouteSlugSpans: which positions are object slugs ─────────────────────
// The role table, driven from fixture rules in WordPress's own shape and order
// (specific rules before the generic page/post pair — see
// class-wp-rewrite.php:1455). This is where the "a segment has a ROLE" claim is
// actually tested; everything above only tests what happens once a position is
// known to be a slug.

echo "\n-- RouteSlugSpans --\n";

$rules = [
    // Specific routes first, exactly as core merges them.
    'search/(.+)/?$'                              => 'index.php?s=$matches[1]',
    'author/([^/]+)/?$'                           => 'index.php?author_name=$matches[1]',
    '([0-9]{4})/([0-9]{1,2})/?$'                  => 'index.php?year=$matches[1]&monthnum=$matches[2]',
    'category/(.+?)/?$'                           => 'index.php?category_name=$matches[1]',
    'shop/([^/]+)/?$'                             => 'index.php?product=$matches[1]&post_type=product',
    'shop/feed/(feed|rdf|rss|rss2|atom)/?$'       => 'index.php?post_type=product&feed=$matches[1]',
    // Then the generic page/post pair.
    '(.?.+?)/comment-page-([0-9]{1,})/?$'         => 'index.php?pagename=$matches[1]&cpage=$matches[2]',
    '(.?.+?)(?:/([0-9]+))?/?$'                    => 'index.php?pagename=$matches[1]&page=$matches[2]',
];

$slugVars = array_fill_keys(
    array_merge(RouteSlugSpans::CORE_SLUG_VARS, ['product']),
    true
);

/** The substrings RouteSlugSpans considers object slugs, in path order. */
function spans_of(string $path, array $rules, array $slugVars): array
{
    $spans = RouteSlugSpans::find($path, $rules, $slugVars);
    $out   = [];
    foreach (array_reverse($spans) as [$offset, $length]) {
        $out[] = substr($path, $offset, $length);
    }

    return $out;
}

$cases = [
    // [path, the slug-bearing substrings expected]
    ['/about/',                      ['about']],
    ['/about/team/',                 ['about/team']],          // hierarchical capture
    ['/about/2/',                    ['about']],               // page number is not a slug
    ['/author/jean/',                []],                      // a nicename
    ['/search/jean/',                []],                      // free text
    ['/2026/10/',                    []],                      // a date
    ['/category/news/',              ['news']],                // base literal, argument mapped
    ['/shop/chaussure/',             ['chaussure']],           // CPT base literal
    ['/shop/feed/rss2/',             []],                      // feed name is not a slug
    ['/story/comment-page-2/',       ['story']],               // literal suffix untouched
    ['/company/author/',             ['company/author']],      // "author" here is just a slug
];

foreach ($cases as [$path, $expected]) {
    $got = spans_of($path, $rules, $slugVars);
    ok(
        "spans [$path] -> " . ($expected === [] ? '(none)' : implode(', ', $expected)),
        $got === $expected,
        $got === [] ? '(none)' : implode(', ', $got)
    );
}

ok(
    'a path matching no rule yields no spans',
    RouteSlugSpans::find('/x/y/z/deep/er/', ['^only/this/?$' => 'index.php?name=$matches[1]'], $slugVars) === [],
    'expected none'
);
ok(
    'spans come back last-first, so substitution is offset-safe',
    (function () use ($rules, $slugVars): bool {
        $spans = RouteSlugSpans::find('/category/news/', $rules, $slugVars);
        for ($i = 1, $n = count($spans); $i < $n; $i++) {
            if ($spans[$i - 1][0] <= $spans[$i][0]) {
                return false;
            }
        }
        return true;
    })()
);

// The pairing is read off the query string, so an unrecognised var is ignored
// rather than guessed at — a plugin's own rule fails closed.
ok(
    'an unknown query var is never treated as a slug',
    RouteSlugSpans::find('/thing/abc/', ['thing/([^/]+)/?$' => 'index.php?plugin_thing=$matches[1]'], $slugVars) === [],
    'expected none'
);

echo "\n$total checks, $failed failed\n";
exit($failed === 0 ? 0 : 1);
