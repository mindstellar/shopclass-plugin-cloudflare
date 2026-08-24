<?php
/*
 * This file is part of the Cloudflare plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\cloudflare;

use Params;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/**
 * Settings, lifecycle, and the admin POST handler. Static by convention —
 * the plugin holds no per-request object state.
 */
class Plugin
{
    public const PREF_SECTION = 'cloudflare';

    /** Create the queue table and seed defaults. Idempotent. */
    public static function install()
    {
        Queue::install();
        osc_set_preference('api_token', '', self::PREF_SECTION, 'STRING');
        osc_set_preference('zone_id', '', self::PREF_SECTION, 'STRING');
        osc_set_preference('purge_enabled', '1', self::PREF_SECTION, 'INTEGER');
        osc_reset_preferences();
    }

    /** Remove our cache rules (best-effort), drop the table, forget every setting. */
    public static function uninstall()
    {
        try {
            $client = Client::fromSettings();
            if ($client !== null) {
                CacheRules::remove($client);
            }
        } catch (\Throwable $e) {
            // Never let a network error block an uninstall.
        }

        Queue::uninstall();

        // The whole section, not a key list — a setting dropped by a later
        // version would otherwise be orphaned forever.
        \Preference::newInstance()->delete(array('s_section' => self::PREF_SECTION));
        osc_reset_preferences();
    }

    // ── setting accessors (each validates; a stored value is never trusted raw) ──
    public static function token(): string
    {
        return trim((string)osc_get_preference('api_token', self::PREF_SECTION));
    }

    public static function zoneId(): string
    {
        return trim((string)osc_get_preference('zone_id', self::PREF_SECTION));
    }

    public static function purgeEnabled(): bool
    {
        return (int)osc_get_preference('purge_enabled', self::PREF_SECTION) === 1;
    }

    public static function isConfigured(): bool
    {
        return self::token() !== '' && self::zoneId() !== '';
    }

    /**
     * Handle every settings-page POST before the panel prints, so a redirect is
     * still possible. Dispatches on the cf_action field.
     */
    public static function handleAdminPost()
    {
        $action = Params::getParamString('cf_action');
        if ($action === '') {
            return;
        }
        osc_csrf_check();

        switch ($action) {
            case 'save':
                // A blank token field keeps the stored one (so it's never echoed
                // to the page and can't be wiped by an accidental save); the
                // "clear" checkbox is the explicit way to remove it.
                if (Params::getParamInt('clear_token') === 1) {
                    osc_set_preference('api_token', '', self::PREF_SECTION, 'STRING');
                } else {
                    $token = trim(Params::getParam('api_token', false, false));
                    if ($token !== '') {
                        osc_set_preference('api_token', $token, self::PREF_SECTION, 'STRING');
                    }
                }
                osc_set_preference('zone_id', trim(Params::getParamString('zone_id')), self::PREF_SECTION, 'STRING');
                osc_set_preference('purge_enabled', Params::getParamInt('purge_enabled') === 1 ? '1' : '0', self::PREF_SECTION, 'INTEGER');
                osc_reset_preferences();
                osc_add_flash_ok_message(__('Settings saved', 'cloudflare'), 'admin');
                break;

            case 'test':
                self::flashResult(self::testConnection(), __('Cloudflare connection OK', 'cloudflare'));
                break;

            case 'discover_zone':
                self::discoverZone();
                break;

            case 'install_rules':
                self::installRules();
                break;

            case 'purge_all':
                self::purgeAll();
                break;
        }

        osc_redirect_to(osc_admin_render_plugin_url(osc_plugin_folder(CF_PLUGIN_FILE) . 'admin/settings.php'));
    }

    private static function testConnection(): array
    {
        $client = Client::fromSettings();
        if ($client === null) {
            return array('ok' => false, 'error' => __('Enter an API token first.', 'cloudflare'));
        }
        return $client->verify();
    }

    private static function discoverZone(): void
    {
        $client = Client::fromSettings();
        if ($client === null) {
            osc_add_flash_error_message(__('Enter an API token first.', 'cloudflare'), 'admin');
            return;
        }
        $host = (string)parse_url(osc_base_url(), PHP_URL_HOST);
        $zone = $client->discoverZone($host);
        if ($zone === null) {
            osc_add_flash_error_message(sprintf(__('No Cloudflare zone found for %s. Enter the Zone ID manually.', 'cloudflare'), osc_esc_html($host)), 'admin');
            return;
        }
        osc_set_preference('zone_id', $zone['id'], self::PREF_SECTION, 'STRING');
        osc_reset_preferences();
        osc_add_flash_ok_message(sprintf(__('Found zone %1$s (%2$s).', 'cloudflare'), osc_esc_html($zone['name']), osc_esc_html($zone['id'])), 'admin');
    }

    private static function installRules(): void
    {
        $client = Client::fromSettings();
        if ($client === null || Plugin::zoneId() === '') {
            osc_add_flash_error_message(__('Set the API token and Zone ID first.', 'cloudflare'), 'admin');
            return;
        }
        $result = CacheRules::apply($client);
        if (!empty($result['ok'])) {
            osc_add_flash_ok_message(sprintf(__('Installed %d cache rule(s).', 'cloudflare'), (int)$result['count']), 'admin');
        } else {
            osc_add_flash_error_message(sprintf(__('Could not install cache rules: %s', 'cloudflare'), osc_esc_html($result['error'] ?? '')), 'admin');
        }
    }

    private static function purgeAll(): void
    {
        $client = Client::fromSettings();
        if ($client === null || Plugin::zoneId() === '') {
            osc_add_flash_error_message(__('Set the API token and Zone ID first.', 'cloudflare'), 'admin');
            return;
        }
        $result = $client->purgeEverything();
        if (!empty($result['ok'])) {
            osc_add_flash_ok_message(__('Purged the entire Cloudflare cache.', 'cloudflare'), 'admin');
        } else {
            osc_add_flash_error_message(sprintf(__('Purge failed: %s', 'cloudflare'), osc_esc_html($result['error'] ?? '')), 'admin');
        }
    }

    private static function flashResult(array $result, string $okMsg): void
    {
        if (!empty($result['ok'])) {
            osc_add_flash_ok_message($okMsg, 'admin');
        } else {
            osc_add_flash_error_message(sprintf(__('Cloudflare error: %s', 'cloudflare'), osc_esc_html($result['error'] ?? __('unknown', 'cloudflare'))), 'admin');
        }
    }
}
