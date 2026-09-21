<?php

namespace TenQuality\Gettext\Scanner;

use Gettext\Translation;
use Gettext\Scanner\CodeScanner;
use Gettext\Scanner\JsFunctionsScanner;
use Gettext\Scanner\FunctionsScannerInterface;

/**
 * WordPress JavaScript scanner class.
 * 
 * @author 10 Quality Studio <http://www.10quality.com>
 * @package 10quality/gettext-wp-scanner
 * @license MIT
 * @version 1.0.0
 */
class WPJsScanner extends CodeScanner
{
    use WPFunctionsHandlersTrait;

    /**
     * Functions to scan in code.
     * @since 1.0.0
     * 
     * @var array
     */
    protected $functions = [
        '__' => 'wpgettext',
        '_n' => 'wpngettext',
        '_x' => 'wpxgettext',
        '_nx' => 'wpnxgettext',
    ];

    /**
     * Return the functions scanner for this php file scanner.
     * @since 1.0.0
     * 
     * @return \Gettext\Scanner\FunctionsScannerInterface
     */
    public function getFunctionsScanner(): FunctionsScannerInterface
    {
        return new JsFunctionsScanner(array_keys($this->functions));
    }
}