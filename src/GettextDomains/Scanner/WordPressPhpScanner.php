<?php

namespace LingoWP\GettextDomains\Scanner;

/*
 * Vendored from 10quality/gettext-wp-scanner v1.0.2 (MIT), src/WPPhpScanner.php.
 *
 * The upstream package bundles a PHP scanner and a JavaScript scanner and
 * requires both parsers. LingoWP only scans PHP, so the two PHP-side files
 * are carried here unchanged apart from the namespace, and the plugin depends
 * on gettext/php-scanner directly. That keeps gettext/js-scanner and its
 * parser (mck89/peast, ~1.1 MB) out of the release entirely.
 *
 * Copyright (c) 10 Quality Studio <http://www.10quality.com>
 * Licensed under the MIT License — see THIRD-PARTY-NOTICES.txt.
 */

use Gettext\Translation;
use Gettext\Scanner\CodeScanner;
use Gettext\Scanner\PhpFunctionsScanner;
use Gettext\Scanner\FunctionsScannerInterface;

/**
 * WordPress PHP scanner class.
 * 
 * @author 10 Quality Studio <http://www.10quality.com>
 * @package 10quality/gettext-wp-scanner
 * @license MIT
 * @version 1.0.0
 */
class WordPressPhpScanner extends CodeScanner
{
    use WordPressFunctionHandlers;

    /**
     * Functions to scan in code.
     * @since 1.0.0
     * 
     * @var array
     */
    protected $functions = [
        '__' => 'wpgettext',
        '_e' => 'wpgettext',
        '_n' => 'wpngettext',
        '_n_noop' => 'wpnngettext',
        '_x' => 'wpxgettext',
        '_nx' => 'wpnxgettext',
        '_nx_noop' => 'wpnnxgettext',
        'esc_attr__' => 'wpgettext',
        'esc_attr_e' => 'wpgettext',
        'esc_attr_x' => 'wpxgettext',
        'esc_html__' => 'wpgettext',
        'esc_html_e' => 'wpgettext',
        'esc_html_x' => 'wpxgettext',
    ];

    /**
     * Return the functions scanner for this php file scanner.
     * @since 1.0.0
     * 
     * @return \Gettext\Scanner\FunctionsScannerInterface
     */
    public function getFunctionsScanner(): FunctionsScannerInterface
    {
        return new PhpFunctionsScanner(array_keys($this->functions));
    }

    /**
     * Saves translation.
     * @since 1.0.0
     * 
     * @param null|string $domain
     * @param null|string $domain
     * @param string      $original
     * @param string      $string
     * 
     * @return \Gettext\Translation
     */
    protected function saveTranslation(
        ?string $domain,
        ?string $context,
        string $original,
        ?string $plural = null
    ): ?Translation {
        $translation = parent::saveTranslation($domain, $context, $original, $plural);
        if (!$translation) {
            return null;
        }
        $original = $translation->getOriginal();
        //Check if it includes a sprintf
        if (strpos($original, '%') !== false) {
            // %[argnum$][flags][width][.precision]specifier
            if (preg_match('/%(\d+\$)?([\-\+\s0]|\'.)?(\d+)?(\.\d+)?[bcdeEfFgGhHosuxX]/', $original)) {
                $translation->getFlags()->add('php-format');
            }
        }
        return $translation;
    }
}
