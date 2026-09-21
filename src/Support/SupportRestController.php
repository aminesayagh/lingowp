<?php

namespace LingoWP\Support;

use LingoWP\Backend\BackendClient;
use LingoWP\Backend\ConnectionRepository;
use WP_REST_Request;
use WP_REST_Response;

final class SupportRestController
{
    private const NS = 'lingowp/v1';

    private const MAX_MESSAGE_LENGTH = 2000;

    private BackendClient $backendClient;
    private ConnectionRepository $connection;

    public function __construct(
        BackendClient $backendClient,
        ConnectionRepository $connection
    ) {
        $this->backendClient = $backendClient;
        $this->connection    = $connection;
    }

    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NS, '/support/message', [
            'methods'             => 'POST',
            'callback'            => [$this, 'send'],
            'permission_callback' => [$this, 'canManage'],
        ]);
    }

    public function canManage(): bool
    {
        return current_user_can('manage_options');
    }

    public function send(WP_REST_Request $request): WP_REST_Response
    {
        if (!$this->connection->isConnected()) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ERR_NOT_CONNECTED'], 200);
        }

        $email   = sanitize_email((string) $request->get_param('email'));
        $message = sanitize_textarea_field((string) $request->get_param('message'));

        $rejection = self::rejectionReason($email, $message);
        if ($rejection !== '') {
            return new WP_REST_Response(['ok' => false, 'error' => $rejection], 200);
        }

        $result = $this->backendClient->submitSupportSuggestion(self::composeMessage($email, $message));

        return rest_ensure_response(['ok' => $result['ok'], 'error' => $result['error']]);
    }

    public static function rejectionReason(string $email, string $message): string
    {
        if (!is_email($email) || $message === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return 'ERR_INVALID_PAYLOAD';
        }

        return '';
    }

    public static function composeMessage(string $email, string $message): string
    {
        return "support_request from {$email}:\n\n{$message}";
    }
}
