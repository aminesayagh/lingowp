<?php

namespace LingoWP\Backend;

final class ConnectionStatus
{
    private const TRANSIENT = 'lingowp_backend_status';
    private const TTL       = 15 * MINUTE_IN_SECONDS;

    private BillingEntitlementsService $entitlements;
    private ConnectionRepository $connection;

    public function __construct(
        BillingEntitlementsService $entitlements,
        ConnectionRepository $connection
    ) {
        $this->entitlements = $entitlements;
        $this->connection   = $connection;
    }

    public function current(): array
    {
        $cached = get_transient(self::TRANSIENT);

        if (is_array($cached) && $cached['available']) {
            return $cached;
        }

        return $this->refresh();
    }

    public function refresh(): array
    {
        if (!$this->connection->isConnected()) {
            return $this->store(false, 'ERR_NOT_CONNECTED');
        }

        $result = $this->entitlements->rawEntitlements();

        return $result['ok']
            ? $this->store(true, '')
            : $this->store(false, $result['error']);
    }

    public function markUnavailable(string $error): void
    {
        $this->store(false, $error);
    }

    public function clearCache(): void
    {
        delete_transient(self::TRANSIENT);
    }

    private function store(bool $available, string $error): array
    {
        $status = ['available' => $available, 'error' => $error];
        set_transient(self::TRANSIENT, $status, self::TTL);

        return $status;
    }
}
