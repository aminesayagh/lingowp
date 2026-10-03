<?php

namespace LingoWP\Support;

use LingoWP\Backend\BackendClient;
use LingoWP\Backend\ConnectionRepository;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The floating support bubble's only interaction (namespace lingowp/v1):
 * an admin types a message, optionally corrects the reply address, and sends.
 *
 * There is no dedicated support-message route on the backend — this reuses the
 * same POST /support/suggestions the "Notify me" buttons already go through
 * (InsightsRestController, BillingRestController::notifyComingSoon), which
 * accepts a single `message` string. The reply address is folded into that
 * string rather than sent as its own field, so nothing here depends on a
 * backend contract change. Split it out once /support/suggestions takes an
 * `email` of its own.
 *
 * Fire-and-forget, exactly like the notify buttons: no local persistence, no
 * retry queue, no ticket history. A failed send is the admin's to retry.
 */
final class SupportRestController
{
    private const NS = 'lingowp/v1';

    /** Generous enough for a real bug report, short enough to bound the request. */
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

    /**
     * '' when the payload is usable, otherwise the ERR_* code to hand back.
     * Split out from send() so tests/support_payload_check.php can exercise the
     * branches without standing up WP_REST_Request.
     */
    public static function rejectionReason(string $email, string $message): string
    {
        if (!is_email($email) || $message === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return 'ERR_INVALID_PAYLOAD';
        }

        return '';
    }

    /**
     * The wire format the backend sees. Prefixed with a machine-readable tag so
     * a human support request is distinguishable from the "feature_interest:*"
     * codes the notify buttons send through the same route.
     */
    public static function composeMessage(string $email, string $message): string
    {
        return "support_request from {$email}:\n\n{$message}";
    }
}
