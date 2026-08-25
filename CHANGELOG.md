# Changelog

## 1.1.2

### New
- "Test connection" now checks every permission the plugin needs (token, zone, cache purge, cache rules, analytics) and names any that are missing — so a token short a scope (e.g. Cache Purge) surfaces instead of failing silently.

## 1.1.1

### Changed
- The per-page-type cache TTL now targets Cloudflare's edge only, via a `Cloudflare-CDN-Cache-Control` header, instead of raising `s-maxage`. The origin micro-cache keeps its short, self-healing TTL — a long `s-maxage` would have made the un-purgeable origin cache serve stale pages for the whole TTL, defeating purge-on-change.

## 1.1.0

### New
- Editable per-page-type cache TTL (item / static / listings), applied via the `public_cache_max_age` filter — off by default; a longer item TTL is safe because purge-on-change keeps it fresh.
- Redesigned settings page: Bootstrap-native cards, status badges, a cache-analytics widget with stat tiles, and dark-mode support.

### Changed
- Settings save as one form; every action button (Test, Discover, Install rules) persists the form first, so it acts on what's on screen.

## 1.0.0

First release.

### New
- Automatic Cloudflare cache purge on listing, category, and static-page changes, with a durable retry queue drained on the hourly cron.
- One-click install of the recommended cache rules (cache public pages respecting the origin, bypass admin, bypass personalized requests), owning only the plugin's own rules.
- Read-only cache analytics widget (hit ratio, requests, bandwidth) for the last 24h.
- Admin settings: API token, zone auto-discovery, connection test, manual "purge everything".
- `bin/purge.php` standalone script for purging the whole cache after a deploy.
