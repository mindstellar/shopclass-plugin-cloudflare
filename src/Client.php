<?php
/*
 * This file is part of the Cloudflare plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\cloudflare;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/**
 * Thin wrapper over the Cloudflare v4 REST + GraphQL APIs, built on the
 * symfony/http-client that ships in core's vendor tree. Every method returns a
 * plain array and never throws — a transport failure becomes ok:false so callers
 * (hooks, cron, admin) can degrade instead of fataling a page.
 */
class Client
{
    private const API     = 'https://api.cloudflare.com/client/v4';
    private const GRAPHQL = 'https://api.cloudflare.com/client/v4/graphql';

    /** Cloudflare caps a single purge_cache call at 30 URLs. */
    public const PURGE_BATCH = 30;

    private string $token;
    private string $zoneId;
    private ?HttpClientInterface $http;

    public function __construct(string $token, string $zoneId = '', ?HttpClientInterface $http = null)
    {
        $this->token  = $token;
        $this->zoneId = $zoneId;
        $this->http   = $http;   // null → a real client is created per request
    }

    /** Build from stored settings, or null when no token is configured. */
    public static function fromSettings(): ?self
    {
        $token = Plugin::token();
        if ($token === '') {
            return null;
        }
        return new self($token, Plugin::zoneId());
    }

    public function zoneId(): string
    {
        return $this->zoneId;
    }

    // ── low-level request ────────────────────────────────────────────────────
    /**
     * @return array{ok:bool,status:int,data:array,error:string}
     */
    private function request(string $method, string $url, ?array $json = null, int $timeout = 10): array
    {
        try {
            $options = array('auth_bearer' => $this->token, 'timeout' => $timeout);
            if ($json !== null) {
                $options['json'] = $json;
            }
            $response = ($this->http ?? HttpClient::create())->request($method, $url, $options);
            $status   = $response->getStatusCode();
            $data     = $response->toArray(false);
            $ok       = $data['success'] ?? ($status >= 200 && $status < 300);
            return array('ok' => (bool)$ok, 'status' => $status, 'data' => $data, 'error' => self::firstError($data));
        } catch (\Throwable $e) {
            return array('ok' => false, 'status' => 0, 'data' => array(), 'error' => $e->getMessage());
        }
    }

    private static function firstError(array $data): string
    {
        if (!empty($data['errors']) && is_array($data['errors'])) {
            $first = $data['errors'][0];
            if (is_array($first)) {
                $msg = (string)($first['message'] ?? '');
                return $first['code'] ?? '' ? $msg . ' (' . $first['code'] . ')' : $msg;
            }
        }
        return '';
    }

    // ── cache purge ──────────────────────────────────────────────────────────
    /**
     * Purge specific absolute URLs, in batches of 30.
     *
     * @param string[] $urls
     * @return array{ok:bool,failed:string[],error:string}
     */
    public function purge(array $urls): array
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === array() || $this->zoneId === '') {
            return array('ok' => $urls === array(), 'failed' => $urls, 'error' => $this->zoneId === '' ? 'no zone id' : '');
        }

        $failed = array();
        $error  = '';
        foreach (array_chunk($urls, self::PURGE_BATCH) as $chunk) {
            $res = $this->request('POST', self::API . '/zones/' . $this->zoneId . '/purge_cache', array('files' => $chunk));
            if (empty($res['ok'])) {
                $failed = array_merge($failed, $chunk);
                $error  = $res['error'] !== '' ? $res['error'] : ('HTTP ' . $res['status']);
            }
        }
        return array('ok' => $failed === array(), 'failed' => $failed, 'error' => $error);
    }

    /** @return array{ok:bool,error:string} */
    public function purgeEverything(): array
    {
        if ($this->zoneId === '') {
            return array('ok' => false, 'error' => 'no zone id');
        }
        $res = $this->request('POST', self::API . '/zones/' . $this->zoneId . '/purge_cache', array('purge_everything' => true));
        return array('ok' => (bool)$res['ok'], 'error' => $res['error']);
    }

    // ── zone discovery / verification ─────────────────────────────────────────
    /**
     * Look up the zone id for a host, falling back to the registrable domain.
     *
     * @return array{id:string,name:string}|null
     */
    public function discoverZone(string $host): ?array
    {
        $candidates = array($host);
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            $candidates[] = implode('.', array_slice($parts, -2));
        }
        foreach (array_unique($candidates) as $name) {
            $res = $this->request('GET', self::API . '/zones?name=' . rawurlencode($name) . '&status=active');
            if (!empty($res['ok']) && !empty($res['data']['result'][0]['id'])) {
                $zone = $res['data']['result'][0];
                return array('id' => (string)$zone['id'], 'name' => (string)($zone['name'] ?? $name));
            }
        }
        return null;
    }

    /**
     * Verify the token is valid and (if set) the zone is reachable with it.
     *
     * @return array{ok:bool,error:string}
     */
    public function verify(): array
    {
        $tokenRes = $this->request('GET', self::API . '/user/tokens/verify');
        if (empty($tokenRes['ok'])) {
            return array('ok' => false, 'error' => $tokenRes['error'] !== '' ? $tokenRes['error'] : __('token invalid', 'cloudflare'));
        }
        if ($this->zoneId !== '') {
            $zoneRes = $this->request('GET', self::API . '/zones/' . $this->zoneId);
            if (empty($zoneRes['ok'])) {
                return array('ok' => false, 'error' => $zoneRes['error'] !== '' ? $zoneRes['error'] : __('zone not reachable with this token', 'cloudflare'));
            }
        }
        return array('ok' => true, 'error' => '');
    }

    // ── rulesets (cache rules) ─────────────────────────────────────────────────
    /**
     * The zone's entrypoint ruleset for the cache-settings phase, or null.
     *
     * @return array{id:string,rules:array}|null
     */
    public function getCacheEntrypoint(): ?array
    {
        $res = $this->request('GET', self::API . '/zones/' . $this->zoneId . '/rulesets/phases/http_request_cache_settings/entrypoint');
        if (empty($res['ok']) || empty($res['data']['result'])) {
            return null;
        }
        $r = $res['data']['result'];
        return array('id' => (string)($r['id'] ?? ''), 'rules' => is_array($r['rules'] ?? null) ? $r['rules'] : array());
    }

    /**
     * Replace the cache-settings entrypoint rules.
     *
     * @param array $rules full desired rule list
     * @return array{ok:bool,error:string}
     */
    public function putCacheEntrypoint(array $rules): array
    {
        $res = $this->request(
            'PUT',
            self::API . '/zones/' . $this->zoneId . '/rulesets/phases/http_request_cache_settings/entrypoint',
            array('rules' => array_values($rules))
        );
        return array('ok' => (bool)$res['ok'], 'error' => $res['error']);
    }

    // ── analytics ──────────────────────────────────────────────────────────────
    /**
     * Run a GraphQL analytics query.
     *
     * @return array{ok:bool,data:array,error:string}
     */
    public function graphql(string $query, array $variables): array
    {
        $res = $this->request('POST', self::GRAPHQL, array('query' => $query, 'variables' => $variables));
        if (empty($res['ok']) || !empty($res['data']['errors'])) {
            $err = $res['error'];
            if ($err === '' && !empty($res['data']['errors'][0]['message'])) {
                $err = (string)$res['data']['errors'][0]['message'];
            }
            return array('ok' => false, 'data' => $res['data']['data'] ?? array(), 'error' => $err);
        }
        return array('ok' => true, 'data' => $res['data']['data'] ?? array(), 'error' => '');
    }
}
