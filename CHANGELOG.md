# Changelog

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
