<?php

namespace TransLangs\Bootstrap;

class Plugin
{
    private ServiceContainer $container;

    public function __construct()
    {
        $this->container = new ServiceContainer();
    }

    public function boot(): void
    {
        (new CoreServiceProvider())->register($this->container);
        (new PublicSiteServiceProvider())->register($this->container);

        if (is_admin()) {
            (new AdminServiceProvider())->register($this->container);
        }
    }

    public function get(string $key): mixed
    {
        return $this->container->get($key);
    }
}
