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
 * A durable retry queue for URLs whose immediate purge failed (a Cloudflare
 * blip, an expired token). Drained on cron_hourly. Entries that keep failing are
 * dropped after MAX_ATTEMPTS so the table can't grow without bound.
 */
class Queue
{
    public const MAX_ATTEMPTS = 5;
    public const FLUSH_LIMIT  = 200;

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

    /** Retry queued URLs; drop the ones that succeed and the ones that give up. */
    public static function flush(): void
    {
        $client = Client::fromSettings();
        if ($client === null || $client->zoneId() === '') {
            return;
        }

        $rows = osc_db_select('SELECT pk_i_id, s_url FROM ' . self::table() . ' ORDER BY pk_i_id ASC LIMIT ' . (int)self::FLUSH_LIMIT);
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
            osc_db_execute('DELETE FROM ' . self::table() . ' WHERE i_attempts >= ?', array(self::MAX_ATTEMPTS));
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
        osc_db_execute('UPDATE ' . self::table() . ' SET i_attempts = i_attempts + 1 WHERE pk_i_id IN (' . $in . ')', $params);
    }

    /** @return array{0:string,1:int[]} placeholder string + bound int ids */
    private static function inClause(array $ids): array
    {
        $ids = array_map('intval', $ids);
        return array(implode(',', array_fill(0, count($ids), '?')), $ids);
    }
}
