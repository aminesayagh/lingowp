<?php

/**
 * Cloud connection is opt-in: with no key stored, SiteConnector::ensureValid()
 * — what every SPA root calls on load via POST /connection/check — must
 * report ERR_NOT_CONNECTED and write nothing, never register the site by
 * itself. Only an existing-but-broken key self-heals.
 *
 * Run:  ./wp eval-file tests/db/no_auto_connect_it.php
 * Runs inside a rolled-back transaction (see _harness.php), so the dev
 * site's own token/site id survive untouched.
 */

require __DIR__ . '/_harness.php';

use LingoWP\Backend\BackendClient;
use LingoWP\Backend\BackendEndpointResolver;
use LingoWP\Backend\BillingEntitlementsService;
use LingoWP\Backend\ConnectionRepository;
use LingoWP\Backend\ConnectionStatus;
use LingoWP\Backend\SiteConnector;
use LingoWP\Backend\SiteMetadataProvider;
use LingoWP\Language\Infrastructure\OptionLanguageRegistry;

test_guard();
test_begin();

$connection   = new ConnectionRepository();
$client       = new BackendClient(new BackendEndpointResolver(), $connection);
$entitlements = new BillingEntitlementsService($client);
$status       = new ConnectionStatus($entitlements, $connection);
$connector    = new SiteConnector($client, $connection, new SiteMetadataProvider(new OptionLanguageRegistry(), $connection), $status, $entitlements);

test_section('fresh install: no key');
$connection->clear();
$status->clearCache();

$result = $connector->ensureValid();
test_ok('ensureValid() reports ERR_NOT_CONNECTED', $result['error'] === 'ERR_NOT_CONNECTED', 'got: ' . $result['error']);
test_ok('AI reported unavailable', $result['available'] === false);
test_ok('no token was written', get_option(ConnectionRepository::OPTION_TOKEN, '') === '');
test_ok('no site id was written', get_option(ConnectionRepository::OPTION_SITE_ID, '') === '');
test_ok('repository still says not connected', !$connection->isConnected());

test_done();
