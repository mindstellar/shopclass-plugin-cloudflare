<?php
/*
 * This file is part of the Cloudflare plugin for Shopclass.
 * Copyright (c) 2021-2026 Navjot Tomer (Mindstellar) and contributors
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\cloudflare;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/**
 * A durable retry queue for URLs whose immediate purge failed (a Cloudflare
 * blip, an expired token). Drained on cron_hourly. An entry that is still failing
 * after MAX_AGE_HOURS is dropped and counted, so the admin can see it.
 */
class Queue
{
    public const MAX_AGE_HOURS = 48;
    public const FLUSH_LIMIT  = 200;
    /** Queue row meaning "purge the whole zone". */
    public const ALL = '*';

    public static function table(): string
    {
        return DB_TABLE_PREFIX . 't_cf_purge_queue';
    }

    public static function install(): void
    {
        osc_db_execute(
            'CREATE TABLE IF NOT EXISTS ' . self::table() . ' ('
            . ' pk_i_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' s_url VARCHAR(700) NOT NULL,'
            . ' i_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,'
            . ' dt_date DATETIME NOT NULL,'
            . ' PRIMARY KEY (pk_i_id),'
            . ' UNIQUE KEY uq_url (s_url(190))'
            . ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci'
        );
    }

    public static function uninstall(): void
    {
        osc_db_execute('DROP TABLE IF EXISTS ' . self::table());
    }

    /** @param string[] $urls */
    public static function add(array $urls): void
    {
        $now = date('Y-m-d H:i:s');
        foreach (array_unique(array_filter($urls)) as $url) {
            // INSERT IGNORE: a URL already queued stays with its earlier attempt count.
            osc_db_execute(
                'INSERT IGNORE INTO ' . self::table() . ' (s_url, i_attempts, dt_date) VALUES (?, 0, ?)',
                array($url, $now)
            );
        }
    }

    /** Oldest queue time still worth retrying, as a DB datetime. */
    public static function cutoff(int $now): string
    {
        return date('Y-m-d H:i:s', $now - self::MAX_AGE_HOURS * 3600);
    }

    /** Drop entries older than MAX_AGE_HOURS and tell the admin how many. */
    private static function expire(): void
    {
        $rows = osc_db_select('SELECT COUNT(*) AS n FROM ' . self::table() . ' WHERE dt_date < ?', array(self::cutoff(time())));
        $n    = (int)($rows[0]['n'] ?? 0);
        if ($n > 0) {
            osc_db_execute('DELETE FROM ' . self::table() . ' WHERE dt_date < ?', array(self::cutoff(time())));
            Plugin::recordDropped($n);
        }
    }

    /** Retry queued URLs; drop the ones that succeed, and the ones too old to keep. */
    public static function flush(): void
    {
        self::expire();

        $client = Client::fromSettings();
        if ($client === null || $client->zoneId() === '') {
            return;
        }

        $all = osc_db_select('SELECT pk_i_id FROM ' . self::table() . ' WHERE s_url = ?', array(self::ALL));
        if ($all) {
            if ($client->purgeEverything()['ok']) {
                osc_db_execute('DELETE FROM ' . self::table());
                Plugin::clearDropped();
                osc_set_preference('last_purge_all', (string)time(), Plugin::PREF_SECTION, 'INTEGER');
                return;
            }
            self::bumpAttempts(array_map(static fn($r) => (int)$r['pk_i_id'], $all));
        }

        $rows = osc_db_select('SELECT pk_i_id, s_url FROM ' . self::table() . ' WHERE s_url <> ? ORDER BY pk_i_id ASC LIMIT ' . (int)self::FLUSH_LIMIT, array(self::ALL));
        if (!$rows) {
            return;
        }

        $urlById = array();
        foreach ($rows as $r) {
            $urlById[(int)$r['pk_i_id']] = (string)$r['s_url'];
        }

        $res    = $client->purge(array_values($urlById));
        $failed = array_flip($res['failed']);

        $done = array();
        $fail = array();
        foreach ($urlById as $id => $url) {
            if (isset($failed[$url])) {
                $fail[] = $id;
            } else {
                $done[] = $id;
            }
        }

        self::deleteIds($done);
        if ($fail !== array()) {
            self::bumpAttempts($fail);
        }
    }

    private static function deleteIds(array $ids): void
    {
        if ($ids === array()) {
            return;
        }
        [$in, $params] = self::inClause($ids);
        osc_db_execute('DELETE FROM ' . self::table() . ' WHERE pk_i_id IN (' . $in . ')', $params);
    }

    private static function bumpAttempts(array $ids): void
    {
        if ($ids === array()) {
            return;
        }
        [$in, $params] = self::inClause($ids);
        osc_db_execute('UPDATE ' . self::table() . ' SET i_attempts = LEAST(i_attempts + 1, 255) WHERE pk_i_id IN (' . $in . ')', $params);
    }

    /** @return array{0:string,1:int[]} placeholder string + bound int ids */
    private static function inClause(array $ids): array
    {
        $ids = array_map('intval', $ids);
        return array(implode(',', array_fill(0, count($ids), '?')), $ids);
    }
}
