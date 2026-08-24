<?php
/*
 * This file is part of the Cloudflare plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Render-only. Every POST is processed by Plugin::handleAdminPost() on the
 * init_admin hook, before this page prints.
 */

use mindstellar\cloudflare\Analytics;
use mindstellar\cloudflare\CacheRules;
use mindstellar\cloudflare\Client;
use mindstellar\cloudflare\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/** Human-readable byte size for the analytics table. Guarded: this file may be included once per request. */
if (!function_exists('cf_bytes')) {
    function cf_bytes(int $bytes): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        $n = (float)$bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return round($n, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}

$cfFile       = osc_plugin_folder(CF_PLUGIN_FILE) . 'admin/settings.php';
$hasToken     = Plugin::token() !== '';
$zoneId       = Plugin::zoneId();
$purgeEnabled = Plugin::purgeEnabled();

// Live status (one API call each) — only when there's something to ask about.
$client         = Client::fromSettings();
$rulesInstalled = false;
$stats          = null;
if ($client !== null && $zoneId !== '') {
    $rulesInstalled = CacheRules::installed($client);
    $stats          = Analytics::summary($client);
}

$fileEsc = osc_esc_html($cfFile);
$base    = osc_esc_html(osc_admin_base_url(true));

$hidden = static function ($action) use ($fileEsc) {
    echo osc_csrf_token_form();
    echo '<input type="hidden" name="page" value="plugins"/>';
    echo '<input type="hidden" name="action" value="renderplugin"/>';
    echo '<input type="hidden" name="file" value="' . $fileEsc . '"/>';
    echo '<input type="hidden" name="cf_action" value="' . osc_esc_html($action) . '"/>';
};
?>
<h2 class="render-title"><?php echo osc_esc_html(__('Cloudflare', 'cloudflare')); ?></h2>

<p class="help-block">
    <?php echo osc_esc_html(__('Connect this site to Cloudflare to purge changed pages automatically, install the recommended cache rules, and see cache analytics.', 'cloudflare')); ?>
</p>

<!-- ── Credentials + purge toggle ─────────────────────────────────────────── -->
<form action="<?php echo $base; ?>" method="post" class="form-horizontal">
    <?php $hidden('save'); ?>

    <div class="form-row">
        <div class="form-label"><?php echo osc_esc_html(__('API token', 'cloudflare')); ?></div>
        <div class="form-controls">
            <input type="password" name="api_token" class="input-large" autocomplete="off"
                   placeholder="<?php echo $hasToken ? osc_esc_html(__('•••••••• (saved — leave blank to keep)', 'cloudflare')) : osc_esc_html(__('Cloudflare API token', 'cloudflare')); ?>"/>
            <?php if ($hasToken) { ?>
                <label class="help-block">
                    <input type="checkbox" name="clear_token" value="1"/> <?php echo osc_esc_html(__('Clear the stored token', 'cloudflare')); ?>
                </label>
            <?php } ?>
            <p class="help-block">
                <?php echo osc_esc_html(__('A zone-scoped token. Needed permissions: Cache Purge (edit), Cache Rules (edit), Analytics (read), Zone (read).', 'cloudflare')); ?>
            </p>
        </div>
    </div>

    <div class="form-row">
        <div class="form-label"><?php echo osc_esc_html(__('Zone ID', 'cloudflare')); ?></div>
        <div class="form-controls">
            <input type="text" name="zone_id" class="input-large" value="<?php echo osc_esc_html($zoneId); ?>"
                   placeholder="<?php echo osc_esc_html(__('e.g. 023e105f4ecef8ad9ca31a8372d0c353', 'cloudflare')); ?>"/>
            <p class="help-block"><?php echo osc_esc_html(__('Leave set, or use “Discover zone” below to fill it from your site domain.', 'cloudflare')); ?></p>
        </div>
    </div>

    <div class="form-row">
        <div class="form-label"><?php echo osc_esc_html(__('Automatic purge', 'cloudflare')); ?></div>
        <div class="form-controls">
            <label>
                <input type="checkbox" name="purge_enabled" value="1" <?php echo $purgeEnabled ? 'checked' : ''; ?>/>
                <?php echo osc_esc_html(__('Purge changed pages when a listing, category, or page changes', 'cloudflare')); ?>
            </label>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(__('Save', 'cloudflare')); ?></button>
    </div>
</form>

<!-- ── Connection / zone actions ──────────────────────────────────────────── -->
<h3 class="render-title"><?php echo osc_esc_html(__('Connection', 'cloudflare')); ?></h3>
<div class="form-actions" style="display:flex;gap:.5rem;flex-wrap:wrap;">
    <form action="<?php echo $base; ?>" method="post"><?php $hidden('test'); ?>
        <button type="submit" class="btn"><?php echo osc_esc_html(__('Test connection', 'cloudflare')); ?></button>
    </form>
    <form action="<?php echo $base; ?>" method="post"><?php $hidden('discover_zone'); ?>
        <button type="submit" class="btn"><?php echo osc_esc_html(__('Discover zone', 'cloudflare')); ?></button>
    </form>
</div>

<!-- ── Cache rules ────────────────────────────────────────────────────────── -->
<h3 class="render-title"><?php echo osc_esc_html(__('Cache rules', 'cloudflare')); ?></h3>
<p class="help-block">
    <?php echo osc_esc_html(__('Installs three rules in your zone: cache public pages (respecting the origin), bypass the admin, and bypass any logged-in / personalized request. Only the plugin’s own rules are touched.', 'cloudflare')); ?>
</p>
<p>
    <?php
    if ($client === null || $zoneId === '') {
        echo '<span class="flashmessage flashmessage-warning">' . osc_esc_html(__('Set the API token and Zone ID to manage cache rules.', 'cloudflare')) . '</span>';
    } elseif ($rulesInstalled) {
        echo osc_esc_html(__('Status: installed.', 'cloudflare'));
    } else {
        echo osc_esc_html(__('Status: not installed.', 'cloudflare'));
    }
    ?>
</p>
<form action="<?php echo $base; ?>" method="post"><?php $hidden('install_rules'); ?>
    <button type="submit" class="btn btn-submit"><?php echo osc_esc_html(__('Install / update cache rules', 'cloudflare')); ?></button>
</form>

<!-- ── Manual purge ───────────────────────────────────────────────────────── -->
<h3 class="render-title"><?php echo osc_esc_html(__('Purge', 'cloudflare')); ?></h3>
<form action="<?php echo $base; ?>" method="post"><?php $hidden('purge_all'); ?>
    <button type="submit" class="btn"><?php echo osc_esc_html(__('Purge everything', 'cloudflare')); ?></button>
    <span class="help-block"><?php echo osc_esc_html(__('Clears the whole Cloudflare cache. Handy after a theme or site-wide change.', 'cloudflare')); ?></span>
</form>

<!-- ── Analytics ──────────────────────────────────────────────────────────── -->
<h3 class="render-title"><?php echo osc_esc_html(__('Cache analytics (last 24h)', 'cloudflare')); ?></h3>
<?php if ($stats !== null && !empty($stats['ok'])) { ?>
    <table class="table">
        <tbody>
        <tr>
            <td><?php echo osc_esc_html(__('Cache hit ratio', 'cloudflare')); ?></td>
            <td><strong><?php echo osc_esc_html((string)$stats['hitRatio']); ?>%</strong></td>
        </tr>
        <tr>
            <td><?php echo osc_esc_html(__('Requests', 'cloudflare')); ?></td>
            <td><?php echo osc_esc_html(number_format((float)$stats['requests'])); ?>
                (<?php echo osc_esc_html(number_format((float)$stats['cachedRequests'])); ?> <?php echo osc_esc_html(__('cached', 'cloudflare')); ?>)</td>
        </tr>
        <tr>
            <td><?php echo osc_esc_html(__('Bandwidth', 'cloudflare')); ?></td>
            <td><?php echo osc_esc_html(cf_bytes((int)$stats['bytes'])); ?>
                (<?php echo osc_esc_html(cf_bytes((int)$stats['cachedBytes'])); ?> <?php echo osc_esc_html(__('from cache', 'cloudflare')); ?>)</td>
        </tr>
        </tbody>
    </table>
<?php } elseif ($client !== null && $zoneId !== '') { ?>
    <p class="help-block">
        <?php echo osc_esc_html(__('No analytics available yet. The token may lack the Analytics (read) scope, or your plan’s retention window has no data for this period.', 'cloudflare')); ?>
        <?php if ($stats !== null && $stats['error'] !== '') { ?>
            <br><em><?php echo osc_esc_html($stats['error']); ?></em>
        <?php } ?>
    </p>
<?php } else { ?>
    <p class="help-block"><?php echo osc_esc_html(__('Connect the plugin to see cache analytics.', 'cloudflare')); ?></p>
<?php } ?>
