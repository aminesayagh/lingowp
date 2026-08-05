<?php

namespace TransLangs\Resolution;

/**
 * Answers questions about the current request.
 *
 * All "should we touch this request?" logic lives here, in one place, so the
 * output buffer (and later the link rewriter, SEO manager, etc.) can share the
 * same decisions instead of each re-checking globals. Pure and side-effect
 * free, which makes it easy to unit test.
 */
class RequestContext
{
    /**
     * True when the plugin should translate this request's output.
     *
     * It must be a normal, front-end, GET-ish HTML page request — not admin,
     * AJAX, REST, cron, login, a feed, a sitemap, or a static asset.
     */
    public function shouldTranslate(): bool
    {
        if ($this->isAdmin()) {
            return false;
        }

        if ($this->isAdminPath()) {
            return false;
        }

        if ($this->isAjax()) {
            return false;
        }

        if ($this->isRest()) {
            return false;
        }

        if ($this->isCron()) {
            return false;
        }

        if ($this->isLogin()) {
            return false;
        }

        if ($this->isXmlRpc()) {
            return false;
        }

        if ($this->isFeed()) {
            return false;
        }

        if ($this->isSitemap()) {
            return false;
        }

        if ($this->isStaticAsset()) {
            return false;
        }

        if (!$this->isGetRequest()) {
            return false;
        }

        return true;
    }

    /**
     * True when this request may DISCOVER gettext strings — a real frontend page
     * load. Deliberately request-context only: never calls conditional query tags
     * (is_feed/is_singular/…), which read the main query and fatal before `wp`.
     * Unlike shouldTranslate(), it is independent of the target language (default-
     * language loads discover too) and omits the feed/sitemap checks for that
     * reason. Cheap; callers memoize.
     */
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

        // REST_REQUEST is only defined once REST routing starts. Earlier in the
        // request we fall back to matching the REST URL prefix.
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
        // Core sitemaps expose a query var; also catch the common .xml endpoints.
        if (function_exists('get_query_var') && get_query_var('sitemap')) {
            return true;
        }

        $path = $this->path();

        return $path !== '' && (bool) preg_match('/sitemap.*\.xml$/i', $path);
    }

    /**
     * Static assets we should never run through the translator.
     */
    public function isStaticAsset(): bool
    {
        $path = $this->path();

        if ($path === '') {
            return false;
        }

        $extensions = [
            'css',
            'js',
            'mjs',
            'json',
            'xml',
            'txt',
            'map',
            'jpg',
            'jpeg',
            'png',
            'gif',
            'webp',
            'avif',
            'svg',
            'ico',
            'bmp',
            'woff',
            'woff2',
            'ttf',
            'otf',
            'eot',
            'mp4',
            'webm',
            'mp3',
            'wav',
            'ogg',
            'pdf',
            'zip',
            'gz',
        ];

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return $ext !== '' && in_array($ext, $extensions, true);
    }

    public function isGetRequest(): bool
    {
        $method = strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET')));

        // HEAD renders like GET; both are safe to buffer. POST etc. are not.
        return $method === 'GET' || $method === 'HEAD';
    }

    // ------------------------------------------------------------------
    // Low-level helpers
    // ------------------------------------------------------------------

    /**
     * Current request path (no host, no query string), trimmed of slashes.
     */
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

    /**
     * REST namespace prefix, e.g. "wp-json". Respects custom permalinks.
     */
    private function restPrefix(): string
    {
        if (function_exists('rest_get_url_prefix')) {
            return (string) rest_get_url_prefix();
        }

        return 'wp-json';
    }

    /**
     * Whether the running entry script is a given file.
     */
    private function scriptIs(string $file): bool
    {
        $script = sanitize_text_field(wp_unslash($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['PHP_SELF'] ?? ''));

        return basename($script) === $file;
    }
}
