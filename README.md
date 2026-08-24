# Cloudflare

Connect a Shopclass site to Cloudflare: purge changed pages automatically, install
the recommended cache rules, and view cache analytics — all from the admin.

## What it does

**Automatic cache purge.** When a listing is created, edited, deleted, or changes
state (enabled/disabled, activated, made premium, expiry changed), and when a
category or static page changes, the plugin purges the affected URLs at Cloudflare:
the item's own page (per enabled locale), the home page, the item's category
listing, the seller's public profile, and the sitemap index. All URLs are derived
from core helpers — nothing about your site's structure is hardcoded.

Purges are immediate and best-effort; anything that fails (a Cloudflare blip, an
expired token) is queued and retried on the hourly cron, so a purge is never lost
and a listing save is never blocked.

Search and filter result URLs are **not** purged — they are unbounded and can't be
enumerated, so they rely on whatever TTL your cache rules give them.

**Cache rules.** One click installs three rules in your zone's cache phase:

1. cache public pages, respecting the origin's `Cache-Control`;
2. bypass the admin (`/oc-admin/*`);
3. bypass any request carrying a login/personalization cookie.

The cookie list comes from core (`osc_cache_relevant_cookies()`), so it stays
correct across versions. The plugin touches only the rules it created — your other
rules are preserved — and it is safe by construction: a page the app marks
`private, no-store` is never cached.

**Analytics.** A read-only widget shows the last 24h cache hit ratio, requests, and
bandwidth served from cache.

## Requirements

- Shopclass 6.2.0+ (uses the core caching contract and `osc_cache_relevant_cookies()`).
- PHP 8.0+.
- A Cloudflare account with the site as a zone.

## Setup

1. Create a zone-scoped Cloudflare API token with these permissions:
   - **Cache Purge** — Edit
   - **Cache Rules** — Edit
   - **Analytics** — Read
   - **Zone** — Read
2. In admin, open **Settings → Cloudflare**, paste the token, and Save.
3. Click **Discover zone** to fill the Zone ID from your site domain (or paste it),
   then **Test connection**.
4. Click **Install / update cache rules**.

## Purge on deploy

To clear the whole cache after a deploy that changes site-wide markup:

```sh
php oc-content/plugins/cloudflare/bin/purge.php
```

It reads the same saved credentials and purges everything.

## Data and privacy

The plugin stores only your API token and Zone ID (in the site's preferences) and
talks solely to the Cloudflare API over HTTPS. It sends no analytics or telemetry
anywhere else.

## Licence

GPL-3.0-or-later. See [LICENSE](LICENSE).
