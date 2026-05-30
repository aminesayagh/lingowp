<?php

namespace TransLangs\Bootstrap;

class ServiceContainer
{
    /** @var array<string, mixed> */
    private array $services = [];

    public function set(string $key, mixed $service): void
    {
        $this->services[$key] = $service;
    }

    public function get(string $key): mixed
    {
        return $this->services[$key] ?? null;
    }
}
