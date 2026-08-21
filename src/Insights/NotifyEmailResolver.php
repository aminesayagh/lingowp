<?php

namespace TransLangs\Insights;

/**
 * Resolves the address the Insights "Notify me" button emails. License email
 * first, WordPress admin_email as fallback — but this plugin has no local
 * record of a license email anywhere yet (see ConnectionRepository: it stores
 * only an opaque site id + token, never an address), so the license branch is
 * a no-op until the backend exposes one. In practice this always returns
 * admin_email today, with source 'admin'.
 */
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
