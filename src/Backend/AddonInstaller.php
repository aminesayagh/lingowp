<?php

namespace LingoWP\Backend;

/**
 * Unpacks an add-on plugin zip (already downloaded as raw bytes by
 * BackendClient::downloadAddonZip()) into WP_PLUGIN_DIR and activates it.
 * Every add-on zip is contracted to unpack to a single top-level
 * `lingowp-<product>/` folder (per the backend's addon catalog) — this
 * class trusts that contract, it doesn't inspect zip contents beyond what
 * unzip_file() itself checks.
 *
 * Writes directly to WP_PLUGIN_DIR rather than through WP_Filesystem /
 * request_filesystem_credentials() — fine for this Docker setup where
 * PHP-FPM owns the mounted wp-content, but a host requiring FTP credentials
 * for writes would fail here. Not handled this pass.
 */
final class AddonInstaller
{
    /** @return array{ok: bool, error: string} */
    public function install(string $zipBytes, string $product): array
    {
        if (! function_exists('unzip_file')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (! function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $tmpFile = wp_tempnam("lingowp-{$product}.zip");
        if ($tmpFile === '' || file_put_contents($tmpFile, $zipBytes) === false) {
            return ['ok' => false, 'error' => 'ERR_ADDON_INSTALL_FAILED'];
        }

        try {
            $unzipped = unzip_file($tmpFile, WP_PLUGIN_DIR);
            if (is_wp_error($unzipped)) {
                return ['ok' => false, 'error' => 'ERR_ADDON_INSTALL_FAILED'];
            }

            $pluginFile = "lingowp-{$product}/lingowp-{$product}.php";
            if (! file_exists(WP_PLUGIN_DIR . '/' . $pluginFile)) {
                return ['ok' => false, 'error' => 'ERR_ADDON_INSTALL_FAILED'];
            }

            $activated = activate_plugin($pluginFile);
            if (is_wp_error($activated)) {
                return ['ok' => false, 'error' => 'ERR_ADDON_ACTIVATE_FAILED'];
            }

            return ['ok' => true, 'error' => ''];
        } finally {
            @unlink($tmpFile);
        }
    }
}
