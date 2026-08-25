<?php
/*
Plugin Name: Cloudflare
Plugin URI: https://github.com/mindstellar/shopclass-plugin-cloudflare
Description: Purge Cloudflare's cache when listings change, install the recommended cache rules, and view cache analytics — all from the admin.
Version: 1.1.3
Author: Mindstellar Community
Author URI: https://mindstellar.com
Short Name: cloudflare
Requires Shopclass: 6.2.0
Tested up to: 6.2
Requires PHP: 8.0
Support URI: https://github.com/mindstellar/shopclass-plugin-cloudflare/issues
*/

/*
 * This file is part of the Cloudflare plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 *
 * Distributed under the GNU General Public License v3.0 or later.
 * See LICENSE (GPL-3.0).
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use mindstellar\cloudflare\Plugin;
use mindstellar\cloudflare\Purge;
use mindstellar\cloudflare\Queue;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

define('CF_PLUGIN_FILE', __FILE__);

/**
 * The plugin's own classes, loaded on demand — a short PSR-4 loader for one
 * namespace, since core autoloads only its own tree.
 */
spl_autoload_register(static function ($class) {
    $prefix = 'mindstellar\\cloudflare\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path     = __DIR__ . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

// ── Lifecycle ────────────────────────────────────────────────────────────────
osc_register_plugin(osc_plugin_path(__FILE__), array('mindstellar\cloudflare\Plugin', 'install'));
osc_add_hook(osc_plugin_path(__FILE__) . '_uninstall', array('mindstellar\cloudflare\Plugin', 'uninstall'));
osc_add_hook(osc_plugin_path(__FILE__) . '_configure', 'cf_configure');

/** Route the plugin-list "Configure" link to the settings page. */
function cf_configure()
{
    osc_redirect_to(osc_admin_render_plugin_url(osc_plugin_folder(CF_PLUGIN_FILE) . 'admin/settings.php'));
}

// ── Admin ────────────────────────────────────────────────────────────────────
osc_add_hook('init_admin', array('mindstellar\cloudflare\Plugin', 'handleAdminPost'));
osc_add_hook('admin_menu_init', 'cf_admin_menu');

function cf_admin_menu()
{
    osc_add_admin_submenu_page(
        'settings',
        __('Cloudflare', 'cloudflare'),
        osc_admin_render_plugin_url(osc_plugin_folder(CF_PLUGIN_FILE) . 'admin/settings.php'),
        'cloudflare_settings',
        'administrator'
    );
}

// ── Cache purge on content change ────────────────────────────────────────────
// Item created/edited/deleted carry the full item array; the state-change hooks
// carry only an id, so Purge loads the item when it needs one.
osc_add_hook('posted_item', 'cf_purge_item_array');
osc_add_hook('edited_item', 'cf_purge_item_array');
osc_add_hook('after_delete_item', 'cf_purge_item_deleted');
foreach (array(
    'enable_item', 'disable_item', 'activate_item', 'deactivate_item',
    'item_premium_on', 'item_premium_off', 'item_expiration_updated',
) as $cf_hook) {
    osc_add_hook($cf_hook, 'cf_purge_item_id');
}

// Category / static page changes (core fires no edit_category/add_page here, so
// we purge on the add/edit/delete events that do exist).
osc_add_hook('add_category', 'cf_purge_category');
osc_add_hook('after_delete_category', 'cf_purge_category');
osc_add_hook('edit_page', 'cf_purge_page');
osc_add_hook('after_delete_page', 'cf_purge_page');

// Retry anything that failed its immediate purge.
osc_add_hook('cron_hourly', 'cf_flush_queue');

// Per-page-type Cloudflare edge TTL (when enabled) — emits a CDN-only header so the
// origin micro-cache keeps its short, self-healing s-maxage. See cf_public_cache_max_age.
osc_add_filter('public_cache_max_age', 'cf_public_cache_max_age');

// ── Glue: keep index.php thin, logic lives in src/ ───────────────────────────
function cf_purge_item_array($item)
{
    if (is_array($item)) {
        Purge::onItem($item);
    }
}

function cf_purge_item_deleted($itemId, $item = null)
{
    Purge::onItem(is_array($item) ? $item : (int)$itemId);
}

function cf_purge_item_id($itemId)
{
    Purge::onItem((int)$itemId);
}

function cf_purge_category($categoryId)
{
    Purge::onCategory((int)$categoryId);
}

function cf_purge_page($pageId)
{
    Purge::onPage((int)$pageId);
}

function cf_flush_queue()
{
    Queue::flush();
}

function cf_public_cache_max_age($ttl)
{
    // The per-page-type TTL targets Cloudflare's edge ONLY, via a CDN-specific
    // header Cloudflare honours above Cache-Control. The origin micro-cache (and any
    // other shared cache) keeps the short s-maxage returned below, so it self-heals —
    // it has no invalidation; only the CDN is purged on change. A long origin TTL
    // would otherwise serve stale pages for the whole TTL, defeating purge-on-change.
    if (Plugin::ttlEnabled() && !headers_sent()) {
        header('Cloudflare-CDN-Cache-Control: max-age=' . Plugin::pageTtl((int)$ttl), true);
    }
    return $ttl;
}
