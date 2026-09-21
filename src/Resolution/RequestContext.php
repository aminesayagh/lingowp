<?php

namespace LingoWP\Resolution;

use LingoWP\Shared\StaticAssetExtensions;

class RequestContext
{
    public function shouldTranslate(): bool
    {
        return !$this->isAdmin()
            && !$this->isAdminPath()
            && !$this->isAjax()
            && !$this->isRest()
            && !$this->isCron()
            && !$this->isLogin()
            && !$this->isXmlRpc()
            && !$this->isFeed()
            && !$this->isSitemap()
            && !$this->isStaticAsset()
            && $this->isGetRequest();
    }

    public function shouldCaptureGettext(): bool
    {
        if (function_exists('did_action') && !did_action('plugins_loaded')) {
            return false;
        }
        if (defined('WP_CLI') && WP_CLI) {
            return false;
        }
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            return false;
        }

        return !$this->isAdmin()
            && !$this->isAdminPath()
            && !$this->isAjax()
            && !$this->isRest()
            && !$this->isCron()
            && !$this->isLogin()
            && !$this->isXmlRpc()
            && !$this->isStaticAsset()
            && $this->isGetRequest();
    }

    public function isAdmin(): bool
    {
        return function_exists('is_admin') && is_admin();
    }

    public function isAdminPath(): bool
    {
        return $this->pathStartsWith('wp-admin');
    }

    public function isAjax(): bool
    {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }

        return defined('DOING_AJAX') && DOING_AJAX;
    }

    public function isRest(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        return $this->pathStartsWith($this->restPrefix());
    }

    public function isCron(): bool
    {
        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }

        if (defined('DOING_CRON') && DOING_CRON) {
            return true;
        }

        return $this->scriptIs('wp-cron.php');
    }

    public function isLogin(): bool
    {
        global $pagenow;

        if (isset($pagenow) && $pagenow === 'wp-login.php') {
            return true;
        }

        return $this->scriptIs('wp-login.php')
            || $this->scriptIs('wp-register.php');
    }

    public function isXmlRpc(): bool
    {
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return true;
        }

        return $this->scriptIs('xmlrpc.php');
    }

    public function isFeed(): bool
    {
        return function_exists('is_feed') && is_feed();
    }

    public function isSitemap(): bool
    {
        if (function_exists('get_query_var') && get_query_var('sitemap')) {
            return true;
        }

        $path = $this->path();

        return $path !== '' && (bool) preg_match('/sitemap.*\.xml$/i', $path);
    }

    public function isStaticAsset(): bool
    {
        $path = $this->path();
        if ($path === '') {
            return false;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' && isset(StaticAssetExtensions::LIST[$ext]);
    }

    public function isGetRequest(): bool
    {
        $method = strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET')));

        return $method === 'GET' || $method === 'HEAD';
    }

    public function path(): string
    {
        $uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? ''));

        return trim((string) parse_url($uri, PHP_URL_PATH), '/');
    }

    private function pathStartsWith(string $needle): bool
    {
        $needle = trim($needle, '/');

        if ($needle === '') {
            return false;
        }

        return strpos($this->path(), $needle) === 0;
    }

    private function restPrefix(): string
    {
        if (function_exists('rest_get_url_prefix')) {
            return (string) rest_get_url_prefix();
        }

        return 'wp-json';
    }

    private function scriptIs(string $file): bool
    {
        $script = sanitize_text_field(wp_unslash($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? ''));

        return basename($script) === $file;
    }
}
