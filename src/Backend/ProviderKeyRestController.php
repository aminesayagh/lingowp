<?php

namespace TransLangs\Backend;

use WP_REST_Request;
use WP_REST_Response;

/**
 * BYO provider key REST endpoints (namespace translangs/v1).
 *
 * Pure pass-through to the backend's /provider-keys routes. manage_options only.
 * The raw key is read from the request, forwarded, and unset immediately after —
 * it is never written to a WP option, transient, or log.
 */
final class ProviderKeyRestController
{
    private const NS = 'translangs/v1';

    private const PROVIDERS = ['openai', 'anthropic'];

    public function __construct(private BackendClient $backendClient) {}

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        $guard = ['permission_callback' => [$this, 'canManage']];

        register_rest_route(self::NS, '/provider-keys', ['methods' => 'GET', 'callback' => [$this, 'list']] + $guard);
        register_rest_route(self::NS, '/provider-keys', ['methods' => 'POST', 'callback' => [$this, 'save']] + $guard);
        register_rest_route(self::NS, '/provider-keys/(?P<provider>[a-z]+)', ['methods' => 'DELETE', 'callback' => [$this, 'delete']] + $guard);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function list(): WP_REST_Response
    {
        $result = $this->backendClient->getProviderKeys();

        return rest_ensure_response([
            'ok'    => $result['ok'],
            'keys'  => $result['data'] ?? [],
            'error' => $result['error'],
        ]);
    }

    public function save(WP_REST_Request $request): WP_REST_Response
    {
        $provider = sanitize_text_field((string) $request->get_param('provider'));
        $apiKey   = (string) $request->get_param('api_key'); // raw on purpose — sanitizing can corrupt a valid key

        if (!in_array($provider, self::PROVIDERS, true) || $apiKey === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_INVALID_PAYLOAD'], 200);
        }

        $result = $this->backendClient->saveProviderKey(['provider' => $provider, 'api_key' => $apiKey]);
        unset($apiKey);

        return rest_ensure_response(['ok' => $result['ok'], 'key' => $result['data'], 'error' => $result['error']]);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response
    {
        $provider = sanitize_text_field((string) $request->get_param('provider'));
        $result   = $this->backendClient->deleteProviderKey($provider);

        return rest_ensure_response(['ok' => $result['ok'], 'error' => $result['error']]);
    }
}
