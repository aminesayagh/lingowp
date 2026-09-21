<?php

namespace LingoWP\Insights;

final class NotifyEmailResolver
{
    public function resolve(): array
    {
        $adminEmail = (string) get_option('admin_email', '');
        if ($adminEmail !== '') {
            return ['email' => $adminEmail, 'source' => 'admin'];
        }

        return ['email' => null, 'source' => null];
    }
}
