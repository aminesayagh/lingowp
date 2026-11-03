<?php

declare(strict_types=1);

/*
 * Strips comments from the plugin's own PHP files inside a staged build tree.
 *
 * Run by bin/build-release-zip.sh on the one release archive (WordPress.org,
 * backend download and public mirror alike). Comments a machine reads
 * are kept verbatim, because an earlier attempt at this stripped them and broke
 * things quietly:
 *   - phpcs:ignore / analyser directives  — WordPress.org's Plugin Check reads
 *     them in place to stay quiet about deliberately-unprepared SQL
 *   - translators: notes                  — the i18n tooling's only context
 *   - the Plugin Name: header             — WordPress parses it to register the
 *                                           plugin at all
 *
 * vendor/ is never touched: third-party licences require their copyright headers
 * to survive redistribution.
 *
 * Self-verifying. Every file's token stream, with comments and whitespace removed,
 * must be identical before and after; anything else exits non-zero and fails the
 * build rather than shipping a broken archive.
 *
 * Usage: php strip-comments.php <staged-build-dir>
 */

const KEEP = [
    'phpcs:',
    'phpstan-',
    'psalm-',
    'codingStandardsIgnore',
    'codeCoverageIgnore',
    'nosemgrep',
    'noinspection',
    'translators:',
    'Plugin Name:',
];

$root = rtrim($argv[1] ?? '', '/');
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "usage: strip-comments.php <staged-build-dir>\n");
    exit(2);
}

function keepComment(string $text): bool
{
    foreach (KEEP as $needle) {
        if (stripos($text, $needle) !== false) {
            return true;
        }
    }
    return false;
}

function codeSignature(string $src): string
{
    $sig = [];
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $sig[] = $t[0] . ':' . $t[1];
            continue;
        }
        $sig[] = $t;
    }
    return implode("\x1f", $sig);
}

/* Whole physical lines go when the comment owns its line; only the trailing
   spaces go when it sits after real code. */
function removalRange(string $src, int $start, int $end): array
{
    $lineStart = strrpos(substr($src, 0, $start), "\n");
    $lineStart = $lineStart === false ? 0 : $lineStart + 1;

    if (trim(substr($src, $lineStart, $start - $lineStart)) === '') {
        if ($end > 0 && $src[$end - 1] === "\n") {
            return [$lineStart, $end];
        }
        $pad = strspn($src, " \t", $end);
        if (($src[$end + $pad] ?? '') === "\n") {
            return [$lineStart, $end + $pad + 1];
        }
        return [$lineStart, $end];
    }

    $back = $start;
    while ($back > 0 && ($src[$back - 1] === ' ' || $src[$back - 1] === "\t")) {
        $back--;
    }
    return [$back, ($end > 0 && $src[$end - 1] === "\n") ? $end - 1 : $end];
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

$stripped = 0;
$keptComments = 0;
$removedComments = 0;
$failures = [];

foreach ($files as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    $path = $file->getPathname();
    $rel = substr($path, strlen($root) + 1);
    if (str_starts_with($rel, 'vendor/')) {
        continue;
    }

    $src = file_get_contents($path);
    $tokens = token_get_all($src);

    $offsets = [];
    $o = 0;
    foreach ($tokens as $i => $t) {
        $offsets[$i] = $o;
        $o += strlen(is_array($t) ? $t[1] : $t);
    }

    $edits = [];
    foreach ($tokens as $i => $t) {
        if (!is_array($t) || ($t[0] !== T_COMMENT && $t[0] !== T_DOC_COMMENT)) {
            continue;
        }
        if (keepComment($t[1])) {
            $keptComments++;
            continue;
        }
        $edits[] = removalRange($src, $offsets[$i], $offsets[$i] + strlen($t[1]));
        $removedComments++;
    }

    if ($edits === []) {
        continue;
    }

    $out = $src;
    foreach (array_reverse($edits) as [$s, $e]) {
        $out = substr($out, 0, $s) . substr($out, $e);
    }
    $out = preg_replace('~\n[ \t]*\n([ \t]*\n)+~', "\n\n", $out);
    $out = preg_replace('~(\{\n)[ \t]*\n~', '$1', $out);
    $out = preg_replace('~\n[ \t]*\n([ \t]*\})~', "\n$1", $out);

    if (codeSignature($src) !== codeSignature($out)) {
        $failures[] = "$rel: stripping changed the code, not just comments";
        continue;
    }
    foreach (KEEP as $needle) {
        if (substr_count(strtolower($src), strtolower($needle))
            !== substr_count(strtolower($out), strtolower($needle))) {
            $failures[] = "$rel: lost a '$needle' comment";
            continue 2;
        }
    }

    file_put_contents($path, $out);
    $stripped++;
}

echo "    files stripped:   $stripped\n";
echo "    comments removed: $removedComments\n";
echo "    comments kept:    $keptComments (tool directives, translators, plugin header)\n";

if ($failures !== []) {
    fwrite(STDERR, "Comment stripping failed:\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - $f\n");
    }
    exit(1);
}
