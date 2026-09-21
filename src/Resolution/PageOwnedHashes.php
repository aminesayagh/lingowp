<?php

namespace LingoWP\Resolution;

final class PageOwnedHashes
{
    private array $owned = [];

    public function mark(string $rawHash): void
    {
        $this->owned[bin2hex($rawHash)] = true;
    }

    public function owns(string $rawHash): bool
    {
        return isset($this->owned[bin2hex($rawHash)]);
    }
}
