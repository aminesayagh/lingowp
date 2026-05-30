<?php

namespace TransLangs\Bootstrap;

use TransLangs\BackOffice\Infrastructure\AdminAssets;
use TransLangs\BackOffice\Infrastructure\SettingsPageController;
use TransLangs\BackOffice\Infrastructure\TranslationMemoryPageController;

class AdminServiceProvider
{
    public function register(ServiceContainer $container): void
    {
        (new AdminAssets())->register();
        (new SettingsPageController())->register();
        (new TranslationMemoryPageController(
            $container->get('translation_memory.query_translations'),
            $container->get('translation_memory.save_translation'),
            $container->get('translation_memory.put_translation_for_review'),
            $container->get('translation_memory.review_translation'),
            $container->get('translation_memory.ignore_translation'),
            $container->get('translation_memory.reset_ignored_translation')
        ))->register();
    }
}
