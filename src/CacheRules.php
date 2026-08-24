<?php
/*
 * This file is part of the Cloudflare plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\cloudflare;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/**
 * Installs and maintains the recommended Shopclass cache rules in the zone's
 * http_request_cache_settings phase (modern Rulesets API).
 *
 * The plugin owns ONLY the rules it creates, identified by a fixed marker in each
 * rule's description: on apply it preserves every rule the user added and replaces
 * just its own, so re-running is idempotent and never clobbers unrelated rules.
 *
 * Safe by construction: it respects the origin's Cache-Control (so a page the app
 * marks private/no-store is never stored) and additionally bypasses admin and any
 * request carrying a core personalization cookie — the cookie list is read from
 * osc_cache_relevant_cookies(), so it stays correct across core versions.
 */
class CacheRules
{
    private const MARKER = '[shopclass-cf]';

    /**
     * @return array{ok:bool,count:int,error:string}
     */
    public static function apply(Client $client): array
    {
        $entry    = $client->getCacheEntrypoint();
        $existing = $entry['rules'] ?? array();

        $kept    = self::withoutOurs($existing);
        $desired = self::desiredRules();
        $merged  = array_merge($kept, $desired);

        $res = $client->putCacheEntrypoint($merged);
        return array('ok' => (bool)$res['ok'], 'count' => count($desired), 'error' => $res['error']);
    }

    /**
     * @return array{ok:bool,error:string}
     */
    public static function remove(Client $client): array
    {
        $entry = $client->getCacheEntrypoint();
        if ($entry === null) {
            return array('ok' => true, 'error' => '');
        }
        $kept = self::withoutOurs($entry['rules']);
        if (count($kept) === count($entry['rules'])) {
            return array('ok' => true, 'error' => '');   // nothing of ours to remove
        }
        $res = $client->putCacheEntrypoint($kept);
        return array('ok' => (bool)$res['ok'], 'error' => $res['error']);
    }

    /** True if the zone currently has our rules installed. */
    public static function installed(Client $client): bool
    {
        $entry = $client->getCacheEntrypoint();
        if ($entry === null) {
            return false;
        }
        foreach ($entry['rules'] as $rule) {
            if (self::isOurs($rule)) {
                return true;
            }
        }
        return false;
    }

    // ── rule construction ──────────────────────────────────────────────────────
    /**
     * Order matters: for set_cache_settings the LAST matching rule wins, so the
     * broad "eligible" rule comes first and the bypass rules override it.
     *
     * @return array<int,array>
     */
    private static function desiredRules(): array
    {
        return array(
            array(
                'description'       => self::MARKER . ' cache public pages (respect origin)',
                'expression'        => '(not starts_with(http.request.uri.path, "/oc-admin"))',
                'action'            => 'set_cache_settings',
                'action_parameters' => array(
                    'cache'       => true,
                    'edge_ttl'    => array('mode' => 'respect_origin'),
                    'browser_ttl' => array('mode' => 'respect_origin'),
                ),
                'enabled'           => true,
            ),
            array(
                'description'       => self::MARKER . ' bypass admin',
                'expression'        => '(starts_with(http.request.uri.path, "/oc-admin"))',
                'action'            => 'set_cache_settings',
                'action_parameters' => array('cache' => false),
                'enabled'           => true,
            ),
            array(
                'description'       => self::MARKER . ' bypass personalized requests',
                'expression'        => self::cookieExpression(),
                'action'            => 'set_cache_settings',
                'action_parameters' => array('cache' => false),
                'enabled'           => true,
            ),
        );
    }

    /** OR of "cookie present" tests for each core personalization cookie. */
    private static function cookieExpression(): string
    {
        $terms = array();
        foreach (osc_cache_relevant_cookies() as $name) {
            $name = str_replace('"', '', (string)$name);
            if ($name !== '') {
                $terms[] = 'http.cookie contains "' . $name . '="';
            }
        }
        if ($terms === array()) {
            // Should not happen (core always returns cookies). Fall back to an
            // expression that never matches, so the bypass rule can't disable
            // caching site-wide — http.host is never empty on a real request.
            return '(http.host eq "")';
        }
        return '(' . implode(' or ', $terms) . ')';
    }

    /** @return array<int,array> */
    private static function withoutOurs(array $rules): array
    {
        $kept = array();
        foreach ($rules as $rule) {
            if (!self::isOurs($rule)) {
                $kept[] = self::sanitizeForPut($rule);
            }
        }
        return $kept;
    }

    private static function isOurs(array $rule): bool
    {
        return isset($rule['description']) && strpos((string)$rule['description'], self::MARKER) === 0;
    }

    /**
     * A ruleset GET returns read-only fields (id, version, last_updated, ref) that
     * a PUT rejects; keep only the writable shape when re-submitting a user's rule.
     */
    private static function sanitizeForPut(array $rule): array
    {
        $out = array();
        foreach (array('action', 'action_parameters', 'expression', 'description', 'enabled', 'ratelimit', 'logging') as $k) {
            if (array_key_exists($k, $rule)) {
                $out[$k] = $rule[$k];
            }
        }
        return $out;
    }
}
