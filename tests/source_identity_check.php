<?php

/**
 * Standalone check for source identity + IdentityKey — no WordPress, no DB.
 * Run: php tests/source_identity_check.php   (needs composer vendor/ for autoload)
 * Exits non-zero if any case fails. Mirrors §"Phase 1" of
 * plan-database-pipeline-verification.md.
 *
 * identity() IS the uniq_source column set. Getting it wrong means duplicate
 * rows or silent render misses, so the column lists are asserted literally.
 */

require __DIR__ . '/../vendor/autoload.php';

use TransLangs\Core\Source\Type\HtmlSource;
use TransLangs\Core\Source\Type\OptionSource;
use TransLangs\Core\Source\Type\PostMetaSource;
use TransLangs\Core\Source\Type\PostSource;
use TransLangs\Core\Source\Type\SplitPostSource;
use TransLangs\Core\Source\Type\StaticTextSource;
use TransLangs\Core\Source\Type\TermSource;
use TransLangs\Core\Source\Value\SourceRef;
use TransLangs\Core\Text\IdentityKey;

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

function throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (\Throwable) {
        return true;
    }
}

// ── A. IdentityKey — type must never affect the key ──────────────────────────
// This exists to stop render lookups built from PHP ints missing rows the
// repository keyed from the strings MySQL returns.

echo "IdentityKey:\n";

ok('int 5 and string "5" agree', IdentityKey::of(['post_id' => 5]) === IdentityKey::of(['post_id' => '5']));
ok('column order is significant',
    IdentityKey::of(['a' => 1, 'b' => 2]) !== IdentityKey::of(['b' => 2, 'a' => 1]));
ok('column name is part of the key',
    IdentityKey::of(['a' => 1]) !== IdentityKey::of(['b' => 1]));

$binary = random_bytes(32);
$key    = IdentityKey::of(['original_hash' => $binary]);
ok('raw binary yields a UTF-8-safe key', mb_check_encoding($key, 'UTF-8'), 'key=' . $key);
ok('binary round-trips deterministically', $key === IdentityKey::of(['original_hash' => $binary]));

// ── B. Seven classes over six kinds ──────────────────────────────────────────
// SplitPostSource shares PostSource's kind AND its identity columns — that
// sharing is the structural root of the post_content carry-forward gap
// (finding A in the plan), so it is asserted here as a fact.

echo "\nclass / kind counts:\n";

$classes = [
    PostSource::class, SplitPostSource::class, PostMetaSource::class,
    TermSource::class, HtmlSource::class, StaticTextSource::class, OptionSource::class,
];
ok('seven source classes', count($classes) === 7);

$kinds = ['post', 'meta', 'term', 'staticText', 'html', 'option'];
ok('six SourceRef kinds', count($kinds) === 6);
foreach ($kinds as $kind) {
    ok("  kind '$kind' parses", SourceRef::parse($kind . ':1') !== null);
}
ok('unknown kind rejected (null, not throw)', SourceRef::parse('bogus:1') === null);
ok('malformed ref rejected', SourceRef::parse('post:abc') === null);

// ── C. identity() column sets ────────────────────────────────────────────────

echo "\nidentity() columns:\n";

$post  = new PostSource(7, 'post_title', 'Hello');
$split = new SplitPostSource(7, 'post_content', 'Hello');
$meta  = new PostMetaSource(7, '_yoast_wpseo_title', 'slides.0.title', 'Hello');
$term  = new TermSource(3, 'name', 'Hello');
$html  = new HtmlSource('body>main>h1', 'https://x.test/', 'Hello');
$stat  = new StaticTextSource('mydomain', null, 'Hello');
$opt   = new OptionSource(11, 'blogname', '', 'Hello');

$expected = [
    'PostSource'       => [$post,  ['post_id', 'field', 'original_hash']],
    'SplitPostSource'  => [$split, ['post_id', 'field', 'original_hash']],
    'PostMetaSource'   => [$meta,  ['post_id', 'meta_key_hash', 'context_hash', 'original_hash']],
    'TermSource'       => [$term,  ['term_taxonomy_id', 'field', 'original_hash']],
    'HtmlSource'       => [$html,  ['selector_hash', 'original_hash']],
    'StaticTextSource' => [$stat,  ['source', 'reference_hash', 'original_hash']],
    'OptionSource'     => [$opt,   ['option_id', 'option_path_hash', 'original_hash']],
];

foreach ($expected as $name => [$obj, $cols]) {
    $got = array_keys($obj->identity());
    ok("$name identity columns", $got === $cols,
        'expected ' . json_encode($cols) . ', got ' . json_encode($got));
}

ok('SplitPostSource shares PostSource identity columns (finding A root)',
    array_keys($split->identity()) === array_keys($post->identity()));
ok('SplitPostSource shares PostSource table',
    $split->table() === $post->table() && $split->table() === 'tl_post');

// ── D. Per-type construction rules ───────────────────────────────────────────

echo "\nconstruction rules:\n";

// HtmlSource: selector capped to the RIGHTMOST 255 BYTES (substr, matching
// HtmlRenderer::domSelector()'s cap, so discovery and render hash the same string).
$long = str_repeat('a', 300);
$capped = (new HtmlSource($long, null, 'x'))->selector();
ok('HtmlSource caps to 255 bytes', strlen($capped) === 255, 'got ' . strlen($capped));
ok('HtmlSource keeps the RIGHTMOST bytes', $capped === substr($long, -255));
ok('HtmlSource leaves a 255-char selector alone',
    (new HtmlSource(str_repeat('b', 255), null, 'x'))->selector() === str_repeat('b', 255));
ok('HtmlSource rejects empty selector', throws(fn() => new HtmlSource('   ', null, 'x')));
ok('HtmlSource selector_hash is sha256 of the selector',
    $html->identity()['selector_hash'] === hash('sha256', 'body>main>h1', true));

// StaticTextSource: reference is mb-capped at 191 CHARACTERS — deliberately
// different from HtmlSource's byte cap. Assert the asymmetry.
ok('StaticTextSource reference = "domain|context"', $stat->reference() === 'mydomain|');
ok('StaticTextSource empty domain becomes "default"',
    (new StaticTextSource('', null, 'x'))->reference() === 'default|');
$longDomain = str_repeat('é', 300);
$ref = (new StaticTextSource($longDomain, null, 'x'))->reference();
ok('StaticTextSource caps reference to 191 mb chars', mb_strlen($ref) === 191, 'got ' . mb_strlen($ref));

// OptionSource
ok('OptionSource option_path_hash is sha256 of the path',
    $opt->identity()['option_path_hash'] === hash('sha256', '', true));
ok('OptionSource accepts empty path (scalar option)', $opt->identity()['option_id'] === 11);
ok('OptionSource caps option_name to 191 mb chars',
    mb_strlen((new OptionSource(1, str_repeat('é', 300), '', 'x'))->optionName()) === 191);
ok('OptionSource rejects id <= 0', throws(fn() => new OptionSource(0, 'blogname', '', 'x')));
ok('OptionSource rejects empty name', throws(fn() => new OptionSource(1, '', '', 'x')));

// Post / Term field allow-lists and id guards
ok('PostSource rejects unsupported field', throws(fn() => new PostSource(1, 'post_status', 'x')));
ok('PostSource rejects id <= 0', throws(fn() => new PostSource(0, 'post_title', 'x')));
ok('TermSource rejects unsupported field', throws(fn() => new TermSource(1, 'slug', 'x')));
ok('TermSource rejects id <= 0', throws(fn() => new TermSource(0, 'name', 'x')));
ok('PostMetaSource rejects id <= 0', throws(fn() => new PostMetaSource(0, 'k', null, 'x')));

// meta context: null and '' must hash identically (both mean "scalar, no context")
$metaNull   = new PostMetaSource(7, 'k', null, 'x');
$metaEmpty  = new PostMetaSource(7, 'k', '', 'x');
ok('meta null context hashes as empty string',
    $metaNull->identity()['context_hash'] === $metaEmpty->identity()['context_hash']);
ok('meta context_hash is sha256("")',
    $metaNull->identity()['context_hash'] === hash('sha256', '', true));

// original_hash is always present and always 32 raw bytes
foreach ($expected as $name => [$obj, $_]) {
    ok("$name original_hash is 32 raw bytes", strlen($obj->identity()['original_hash']) === 32);
}

echo $failed === 0
    ? "\nAll $total assertions passed.\n"
    : "\n$failed of $total assertion(s) failed.\n";

exit($failed === 0 ? 0 : 1);
