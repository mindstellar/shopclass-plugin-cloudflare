<?php
/*
 * This file is part of the Cloudflare plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Standalone entry point: purge the entire Cloudflare cache from the CLI, e.g.
 * after a deploy that changes site-wide markup:
 *
 *   php oc-content/plugins/cloudflare/bin/purge.php
 *
 * Core exposes no oc-cli command-registration API, so this boots the app itself
 * (the same way oc-cli.php does) and then calls the plugin's client.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('This endpoint is command-line only.');
}

define('CLI', true);

// Walk up from bin/ to the site root (where oc-load.php lives), so this works
// regardless of the installed plugin folder name.
$root = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $root = dirname($root);
    if (is_file($root . '/oc-load.php')) {
        break;
    }
}
if (!is_file($root . '/oc-load.php')) {
    fwrite(STDERR, "Could not locate oc-load.php (run this from inside a Shopclass install).\n");
    exit(1);
}
require_once $root . '/oc-load.php';

if (!class_exists('mindstellar\\cloudflare\\Client')) {
    fwrite(STDERR, "Cloudflare plugin is not active.\n");
    exit(1);
}

$client = \mindstellar\cloudflare\Client::fromSettings();
if ($client === null || $client->zoneId() === '') {
    fwrite(STDERR, "Cloudflare plugin is not configured (set the API token and Zone ID in admin).\n");
    exit(1);
}

$res = $client->purgeEverything();
if (!empty($res['ok'])) {
    fwrite(STDOUT, "Purged the entire Cloudflare cache.\n");
    exit(0);
}
fwrite(STDERR, 'Purge failed: ' . $res['error'] . "\n");
exit(1);
