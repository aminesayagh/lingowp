<?php

namespace LingoWP\Insights;

/** Resolves the WordPress admin address suggested by account-owner settings. */
final class NotifyEmailResolver
{
    /** @return array{email: ?string, source: ?string} */
    public function resolve(): array
    {
        $adminEmail = (string) get_option('admin_email', '');
        if ($adminEmail !== '') {
            return ['email' => $adminEmail, 'source' => 'admin'];
        }

        return ['email' => null, 'source' => null];
    }
}
