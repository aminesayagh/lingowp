<?php

namespace TenQuality\Gettext\Scanner;

use Gettext\Scanner\ParsedFunction;
use Gettext\Translation;

/**
 * Function handlers for scanner.
 * 
 * @author 10 Quality Studio <http://www.10quality.com>
 * @package 10quality/gettext-wp-scanner
 * @license MIT
 * @version 1.0.0
 */
trait WPFunctionsHandlersTrait
{
    /**
     * Handles WordPress gettext.
     * @since 1.0.0
     * 
     * @param \Gettext\Scanner\ParsedFunction
     * 
     * @return Gettext\Translation
     */
    protected function wpgettext(ParsedFunction $function): ?Translation
    {
        if (!$this->checkFunction($function, 1)) {
            return null;
        }
        $arguments = $function->getArguments();
        return $this->addFlags($function, $this->addComments(
            $function,
            $this->saveTranslation($arguments[1] ?? null, null, $arguments[0])
        ));
    }

    /**
     * Handles WordPress ngettext (plural).
     * @since 1.0.0
     * 
     * @param \Gettext\Scanner\ParsedFunction
     * 
     * @return Gettext\Translation
     */
    protected function wpngettext(ParsedFunction $function): ?Translation
    {
        if (!$this->checkFunction($function, 1)) {
            return null;
        }
        $arguments = $function->getArguments();
        return $this->addFlags($function, $this->addComments(
            $function,
            $this->saveTranslation($arguments[3] ?? null, null, $arguments[0], $arguments[1] ?? null)
        ));
    }

    /**
     * Handles WordPress pgettext (with context).
     * @since 1.0.0
     * 
     * @param \Gettext\Scanner\ParsedFunction
     * 
     * @return Gettext\Translation
     */
    protected function wpxgettext(ParsedFunction $function): ?Translation
    {
        if (!$this->checkFunction($function, 1)) {
            return null;
        }
        $arguments = $function->getArguments();
        return $this->addFlags($function, $this->addComments(
            $function,
            $this->saveTranslation($arguments[2] ?? null, $arguments[1] ?? null, $arguments[0])
        ));
    }

    /**
     * Handles WordPress npgettext (plural with context).
     * @since 1.0.0
     * 
     * @param \Gettext\Scanner\ParsedFunction
     * 
     * @return Gettext\Translation
     */
    protected function wpnnxgettext(ParsedFunction $function): ?Translation
    {
        if (!$this->checkFunction($function, 1)) {
            return null;
        }
        $arguments = $function->getArguments();
        return $this->addFlags($function, $this->addComments(
            $function,
            $this->saveTranslation($arguments[3] ?? null, $arguments[2] ?? null, $arguments[0], $arguments[1] ?? null)
        ));
    }

    /**
     * Handles WordPress npgettext (plural with context).
     * Method takes into consideration a number parameter.
     * @since 1.0.0
     * 
     * @param \Gettext\Scanner\ParsedFunction
     * 
     * @return Gettext\Translation
     */
    protected function wpnxgettext(ParsedFunction $function): ?Translation
    {
        if (!$this->checkFunction($function, 1)) {
            return null;
        }
        $arguments = $function->getArguments();
        return $this->addFlags($function, $this->addComments(
            $function,
            $this->saveTranslation($arguments[4] ?? null, $arguments[3] ?? null, $arguments[0], $arguments[1] ?? null)
        ));
    }

    /**
     * Handles WordPress npgettext (plural).
     * Method takes into consideration a number parameter.
     * @since 1.0.0
     * 
     * @param \Gettext\Scanner\ParsedFunction
     * 
     * @return Gettext\Translation
     */
    protected function wpnngettext(ParsedFunction $function): ?Translation
    {
        if (!$this->checkFunction($function, 1)) {
            return null;
        }
        $arguments = $function->getArguments();
        return $this->addFlags($function, $this->addComments(
            $function,
            $this->saveTranslation($arguments[2] ?? null, null, $arguments[0], $arguments[1] ?? null)
        ));
    }

    /**
     * Abstract method coverage.
     * @since 1.0.0
     * @link https://github.com/php-gettext/Gettext/blob/master/src/Scanner/FunctionsHandlersTrait.php
     */
    abstract protected function addComments(ParsedFunction $function, ?Translation $translation): ?Translation;

    /**
     * Abstract method coverage.
     * @since 1.0.0
     * @link https://github.com/php-gettext/Gettext/blob/master/src/Scanner/FunctionsHandlersTrait.php
     */
    abstract protected function addFlags(ParsedFunction $function, ?Translation $translation): ?Translation;

    /**
     * Abstract method coverage.
     * @since 1.0.0
     * @link https://github.com/php-gettext/Gettext/blob/master/src/Scanner/FunctionsHandlersTrait.php
     */
    abstract protected function checkFunction(ParsedFunction $function, int $minLength): bool;

    /**
     * Abstract method coverage.
     * @since 1.0.0
     * @link https://github.com/php-gettext/Gettext/blob/master/src/Scanner/FunctionsHandlersTrait.php
     */
    abstract protected function saveTranslation(
        ?string $domain,
        ?string $context,
        string $original,
        ?string $plural = null
    ): ?Translation;
}