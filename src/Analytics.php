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
 * Read-only cache analytics for the admin widget, via the Cloudflare GraphQL API.
 * Degrades gracefully: on any error (token lacks Analytics scope, Free-plan
 * retention, transport failure) it returns ok:false and the widget shows a hint
 * instead of numbers.
 */
class Analytics
{
    private const QUERY = <<<'GQL'
query CfCache($zone: String!, $since: Time!, $until: Time!) {
  viewer {
    zones(filter: {zoneTag: $zone}) {
      httpRequests1hGroups(limit: 200, filter: {datetime_geq: $since, datetime_lt: $until}) {
        sum { requests bytes cachedRequests cachedBytes }
      }
    }
  }
}
GQL;

    /**
     * Aggregate the last $hours of cache stats.
     *
     * @return array{ok:bool,requests:int,cachedRequests:int,bytes:int,cachedBytes:int,hitRatio:float,error:string}
     */
    public static function summary(Client $client, int $hours = 24): array
    {
        $empty = array(
            'ok' => false, 'requests' => 0, 'cachedRequests' => 0,
            'bytes' => 0, 'cachedBytes' => 0, 'hitRatio' => 0.0, 'error' => '',
        );

        if ($client->zoneId() === '') {
            $empty['error'] = 'no zone id';
            return $empty;
        }

        $now   = time();
        $since = gmdate('Y-m-d\TH:i:s\Z', $now - ($hours * 3600));
        $until = gmdate('Y-m-d\TH:i:s\Z', $now);

        $res = $client->graphql(self::QUERY, array(
            'zone'  => $client->zoneId(),
            'since' => $since,
            'until' => $until,
        ));
        if (empty($res['ok'])) {
            $empty['error'] = $res['error'];
            return $empty;
        }

        $groups = $res['data']['viewer']['zones'][0]['httpRequests1hGroups'] ?? array();
        $requests = $cachedRequests = $bytes = $cachedBytes = 0;
        foreach ($groups as $g) {
            $sum             = $g['sum'] ?? array();
            $requests       += (int)($sum['requests'] ?? 0);
            $cachedRequests += (int)($sum['cachedRequests'] ?? 0);
            $bytes          += (int)($sum['bytes'] ?? 0);
            $cachedBytes    += (int)($sum['cachedBytes'] ?? 0);
        }

        return array(
            'ok'             => true,
            'requests'       => $requests,
            'cachedRequests' => $cachedRequests,
            'bytes'          => $bytes,
            'cachedBytes'    => $cachedBytes,
            'hitRatio'       => $requests > 0 ? round($cachedRequests / $requests * 100, 1) : 0.0,
            'error'          => '',
        );
    }
}
