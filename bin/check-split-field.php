<?php

/**
 * Standalone round-trip check for split-field extraction (split-field spec).
 * Run:  php bin/check-split-field.php   (needs composer vendor/ for Masterminds)
 * No WordPress, no DB.
 */

declare(strict_types=1);

$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
require $base . '/src/TranslationMemory/Domain/StringNormalizer.php';
require $base . '/src/TranslationMemory/Domain/TL_Key.php';
require $base . '/src/PublicSite/Infrastructure/TranslationUnitCodec.php';
require $base . '/src/PublicSite/Infrastructure/HtmlUnitExtractor.php';

use TransLangs\PublicSite\Infrastructure\HtmlUnitExtractor;
use TransLangs\PublicSite\Infrastructure\TranslationUnitCodec;
use TransLangs\TranslationMemory\Domain\TL_Key;

$ex = new HtmlUnitExtractor(new TranslationUnitCodec());

$html =
    '<!-- wp:paragraph --><p>About Us</p><!-- /wp:paragraph -->'
    . '<!-- wp:paragraph --><p>Get in <a href="/x">touch</a></p><!-- /wp:paragraph -->';

// 1. Discovery extracts each block-level piece; inline tag → {1}.
$units = $ex->units($html);
assert($units === ['About Us', 'Get in {1}touch{/1}'], 'units: ' . json_encode($units));

// 2. Render replaces only the translated piece; scaffolding/sibling untouched;
//    no <html>/<body> wrapper injected into a content fragment.
$out = $ex->render($html, fn(string $u): ?string => $u === 'About Us' ? 'À propos' : null);
assert(str_contains($out, 'À propos'), 'render replaced: ' . $out);
assert(str_contains($out, 'Get in'), 'render kept sibling: ' . $out);
assert(!str_contains($out, '<html'), 'render injected wrapper: ' . $out);

// 3. Discovery key == render key for the same piece (text-tail determinism).
$k1 = TL_Key::makeSplit(5, 'post_content', 'About Us');
$k2 = TL_Key::makeSplit(5, 'post_content', 'About Us');
assert($k1 === $k2 && strlen($k1) === 32, 'split key shape');

// 4. The split key shares the atomic post_content prefix (same [type][owner][H(ref1)]).
$atomic = TL_Key::make(TL_Key::TYPE_POST, 5, 'post_content', null, '');
assert(substr($k1, 0, 17) === substr($atomic, 0, 17), 'split/atomic prefix mismatch');

echo "OK\n";
