<?php

namespace LingoWP\GettextDomains\Scanner;

use Gettext\Scanner\ParsedFunction;
use Gettext\Translation;

trait WordPressFunctionHandlers
{
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

    abstract protected function addComments(ParsedFunction $function, ?Translation $translation): ?Translation;

    abstract protected function addFlags(ParsedFunction $function, ?Translation $translation): ?Translation;

    abstract protected function checkFunction(ParsedFunction $function, int $minLength): bool;

    abstract protected function saveTranslation(
        ?string $domain,
        ?string $context,
        string $original,
        ?string $plural = null
    ): ?Translation;
}