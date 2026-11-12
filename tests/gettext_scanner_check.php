<?php

/**
 * Standalone check for PhpGettextScanner (the token_get_all() gettext
 * scanner) — no framework, no DB. Run: php tests/gettext_scanner_check.php
 *
 * Each case is a PHP snippet and the exact strings the scanner must return
 * for domain 'd' ("context|msgid" when there is a context), compared with
 * what WP-CLI's `wp i18n make-pot` extracts for the same calls.
 */

require __DIR__ . '/../src/GettextDomains/Scanner/PhpGettextScanner.php';

use LingoWP\GettextDomains\Scanner\PhpGettextScanner;

$failed = 0;

function found(string $code): array
{
    $out = [];
    foreach ((new PhpGettextScanner())->scan("<?php\n" . $code, 'd') as $e) {
        $out[] = ($e['context'] !== null ? $e['context'] . '|' : '') . $e['msgid'];
    }
    sort($out);

    return $out;
}

function check(string $label, string $code, array $expected): void
{
    global $failed;
    sort($expected);
    $got = found($code);
    if ($got === $expected) {
        echo "ok    $label\n";
        return;
    }
    $failed++;
    echo "FAIL  $label\n";
    echo "        expected: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
    echo "        got:      " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n";
}

// Every function, with its own argument positions.
check('all 13 functions', <<<'PHP'
__('a', 'd'); _e('b', 'd'); esc_attr__('c', 'd'); esc_attr_e('e', 'd');
esc_html__('f', 'd'); esc_html_e('g', 'd');
_x('h', 'ctx1', 'd'); esc_attr_x('i', 'ctx2', 'd'); esc_html_x('j', 'ctx3', 'd');
_n('one k', 'many k', $n, 'd'); _n_noop('one l', 'many l', 'd');
_nx('one m', 'many m', $n, 'ctx4', 'd'); _nx_noop('one o', 'many o', 'ctx5', 'd');
PHP, ['a', 'b', 'c', 'e', 'f', 'g', 'ctx1|h', 'ctx2|i', 'ctx3|j', 'one k', 'one l', 'ctx4|one m', 'ctx5|one o']);

// Domain rules.
check('other domain skipped, no domain kept', "__('mine', 'd'); __('theirs', 'x'); __('none');", ['mine', 'none']);
check('variable domain treated as own', "__('dyn', \$domain); _x('dx', 'c', self::D);", ['dyn', 'c|dx']);
check('domain passed after a nested call', "_n('s', 'p', count(\$a, 1), 'x'); _n('s2', 'p2', count(\$a, 1), 'd');", ['s2']);

// Text must be literal.
check('variable / constant / call text skipped', "__(\$t, 'd'); __(FOO, 'd'); __(get('x'), 'd'); __(\"Hi \$name\", 'd');", []);
check('concatenated literals joined', "__('Hello ' . 'world', 'd'); __('a' . \"\\tb\", 'd');", ['Hello world', "a\tb"]);
check('concatenation with a variable skipped', "__('Hello ' . \$name . '!', 'd');", []);
check('variable context becomes no context', "_x('t', \$ctx, 'd');", ['t']);
check('empty call ignored', '__(); _x();', []);

// Escapes.
check('single-quote escapes', "__('it\\'s \\\\ \\n', 'd');", ["it's \\ \\n"]);
check('double-quote escapes', '__("tab\\t nl\\n q\\" d\\$ hex\\x41 oct\\101 u\\u{e9} keep\\q", \'d\');', ["tab\t nl\n q\" d\$ hexA octA u\u{e9} keep\\q"]);
check('binary-string prefix', "__(b'bin', 'd');", ['bin']);
check('percent placeholders untouched', "__('%1\$s of %2\$d', 'd');", ['%1$s of %2$d']);
check('multibyte text', "__('Café — naïve', 'd');", ['Café — naïve']);

// Which calls count.
check('method/static/nullsafe calls skipped', "\$o->__('m', 'd'); Foo::__('s', 'd'); \$o?->__('n', 'd');", []);
check('function definition skipped', "function __(\$t, \$d = 'default') { return \$t; } __('real', 'd');", ['real']);
check('namespaced function skipped, global \\ kept', "Foo\\__('ns', 'd'); \\__('global', 'd');", ['global']);
check('new __() skipped', "new __('cls', 'd');", []);
check('case-sensitive name match (as WordPress tools)', "__('lower', 'd'); _E('upper', 'd');", ['lower']);
check('nested translation calls both found', "_e(__('inner', 'd') ? 'x' : 'y', 'd'); sprintf(__('%s a', 'd'), __('b', 'd'));", ['inner', '%s a', 'b']);
check('arrays and closures in arguments do not split (non-literal domain = own)', "_n('a', 'b', fn(\$x) => [\$x, 2], 'd'); __('c', ['k' => 1] ? 'd' : 'e');", ['a', 'c']);
check('comments between tokens ignored', "__( /* c */ 'commented' // x\n , 'd' );", ['commented']);
check('duplicate strings returned once', "__('dup', 'd'); _e('dup'); _x('dup', 'c', 'd');", ['dup', 'c|dup']);
check('inside HTML template', "?><p><?php esc_html_e('Tpl', 'd'); ?></p><?= __('Short', 'd') ?>", ['Tpl', 'Short']);
check('truncated file does not throw', "__('half', 'd'", ['half']);

echo $failed === 0 ? "\nall passed\n" : "\n$failed FAILED\n";
exit($failed === 0 ? 0 : 1);
