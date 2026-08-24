<?php
/*
 * This file is part of the Cloudflare plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace mindstellar\cloudflare;

use Item;
use Page;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

/**
 * Turns content-change events into the set of URLs to purge, then delivers them.
 *
 * The URL set is derived entirely from core helpers — nothing about any site's
 * structure is hardcoded. Search/filter result URLs are deliberately NOT purged:
 * they are unbounded and un-enumerable, so they rely on the site's configured TTL.
 *
 * Delivery is immediate and best-effort (a short call on save); whatever fails is
 * queued for the hourly retry, so a Cloudflare blip never blocks a listing save
 * and never silently drops a purge.
 */
class Purge
{
    /** @param array|int $itemOrId full item array (create/edit/delete) or an id (state changes). */
    public static function onItem($itemOrId): void
    {
        if (!self::active()) {
            return;
        }
        $item = is_array($itemOrId) ? $itemOrId : self::loadItem((int)$itemOrId);
        if (!$item || empty($item['pk_i_id'])) {
            return;
        }
        self::deliver(self::itemUrls($item));
    }

    public static function onCategory(int $categoryId): void
    {
        if (!self::active() || $categoryId <= 0) {
            return;
        }
        self::deliver(self::categoryUrls($categoryId));
    }

    public static function onPage(int $pageId): void
    {
        if (!self::active() || $pageId <= 0) {
            return;
        }
        self::deliver(array_merge(array(osc_base_url()), self::pageUrls($pageId)));
    }

    // ── url derivation ─────────────────────────────────────────────────────────
    /** @return string[] */
    private static function categoryUrls(int $categoryId): array
    {
        return array(
            osc_base_url(),
            self::sitemap(),
            osc_search_url(array('sCategory' => $categoryId)),
        );
    }

    /** @return string[] */
    private static function itemUrls(array $item): array
    {
        $urls   = array(osc_base_url(), self::sitemap());
        $catId  = (int)($item['fk_i_category_id'] ?? 0);
        $userId = (int)($item['fk_i_user_id'] ?? 0);

        if ($catId > 0) {
            $urls[] = osc_search_url(array('sCategory' => $catId));
        }
        if ($userId > 0) {
            $urls[] = osc_user_public_profile_url($userId);
        }

        if (!empty($item['s_title'])) {
            $urls[]  = osc_item_url_from_item($item);
            $locales = self::locales();
            if (count($locales) > 1) {
                foreach ($locales as $loc) {
                    $urls[] = osc_item_url_from_item($item, $loc);
                }
            }
        } else {
            // No title available (rare) — fall back to the non-friendly URL.
            $urls[] = osc_item_url_ns((int)$item['pk_i_id']);
        }

        return $urls;
    }

    /** @return string[] */
    private static function pageUrls(int $id): array
    {
        $page = Page::newInstance()->findByPrimaryKey($id);
        if (!$page || empty($page['pk_i_id'])) {
            return array();
        }

        if (!osc_rewrite_enabled()) {
            return array(osc_base_url(true) . '?page=page&id=' . $id);
        }

        // Rebuild the friendly page URL from the row (slug = s_internal_name),
        // mirroring osc_static_page_url() without needing loop context.
        $slug = urlencode((string)($page['s_internal_name'] ?? ''));
        $tail = str_replace(
            array('{PAGE_ID}', '{PAGE_SLUG}', '{PAGE_TITLE}'),
            array((string)$id, $slug, $slug),
            (string)osc_get_preference('rewrite_page_url')
        );

        $urls    = array(osc_base_url() . $tail);
        $locales = self::locales();
        if (count($locales) > 1) {
            foreach ($locales as $loc) {
                $urls[] = osc_base_url() . $loc . '/' . $tail;
            }
        }
        return $urls;
    }

    // ── helpers ────────────────────────────────────────────────────────────────
    private static function active(): bool
    {
        return Plugin::purgeEnabled() && Plugin::isConfigured();
    }

    private static function loadItem(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $item = Item::newInstance()->findByPrimaryKey($id);
        return (is_array($item) && $item) ? $item : null;
    }

    /** @return string[] enabled locale codes */
    private static function locales(): array
    {
        $out = array();
        foreach (osc_get_locales() as $l) {
            if (!empty($l['pk_c_code'])) {
                $out[] = $l['pk_c_code'];
            }
        }
        return $out;
    }

    private static function sitemap(): string
    {
        return osc_base_url() . 'sitemapindex.xml';
    }

    /** Immediate best-effort purge; queue whatever failed for the hourly retry. */
    private static function deliver(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls)));
        if ($urls === array()) {
            return;
        }
        $client = Client::fromSettings();
        if ($client === null || $client->zoneId() === '') {
            Queue::add($urls);
            return;
        }
        $res = $client->purge($urls);
        if (!empty($res['failed'])) {
            Queue::add($res['failed']);
        }
    }
}
