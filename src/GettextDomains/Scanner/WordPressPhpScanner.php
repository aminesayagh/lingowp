<?php

namespace LingoWP\GettextDomains\Scanner;

use Gettext\Translation;
use Gettext\Scanner\CodeScanner;
use Gettext\Scanner\PhpFunctionsScanner;
use Gettext\Scanner\FunctionsScannerInterface;

class WordPressPhpScanner extends CodeScanner
{
    use WordPressFunctionHandlers;

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

    public function getFunctionsScanner(): FunctionsScannerInterface
    {
        return new PhpFunctionsScanner(array_keys($this->functions));
    }

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
        if (strpos($original, '%') !== false) {
            if (preg_match('/%(\d+\$)?([\-\+\s0]|\'.)?(\d+)?(\.\d+)?[bcdeEfFgGhHosuxX]/', $original)) {
                $translation->getFlags()->add('php-format');
            }
        }
        return $translation;
    }
}
