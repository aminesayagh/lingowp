<?php

/**
 * Helper for gettext_scanner_it.php — not a test on its own. Run in a fresh
 * PHP process (no WordPress) so the autoload order is forced, not left to
 * plugin load order. Composer prepends each autoloader it registers, so the
 * one required LAST wins for a class both can load: LingoWP's own first
 * (argv[1]), then another plugin's (argv[2], e.g. Plugin Check's, with
 * php-parser v4). PhpParser\* then comes from the other plugin, while
 * Gettext\* and LingoWP\* only exist in LingoWP's. Prints one JSON line.
 */

require $argv[1];
require $argv[2];

use Gettext\Translations;
use LingoWP\GettextDomains\Scanner\WordPressPhpScanner;

$translations = Translations::create('d');
$scanner      = new WordPressPhpScanner($translations);
$scanner->setDefaultDomain('d');
$scanner->ignoreInvalidFunctions(true);
$scanner->scanString("<?php __('Hello', 'd'); _e('Bye'); _x('Post', 'noun', 'd'); __('Other', 'x');", 'snippet.php');

echo json_encode([
    'parser_file' => (new ReflectionClass(\PhpParser\NodeTraverser::class))->getFileName(),
    'v4'          => (new ReflectionMethod(\PhpParser\NodeTraverser::class, '__construct'))->getNumberOfParameters() === 0,
    'found'       => count($translations),
]), "\n";
