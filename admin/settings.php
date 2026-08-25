<?php
/*
 * This file is part of the Cloudflare plugin for Shopclass.
 * Copyright (c) 2021-2026 Mindstellar Community
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Render-only. Every POST is processed by Plugin::handleAdminPost() on the
 * init_admin hook, before this page prints. One form, multiple submit buttons
 * (name="cf_action"); the admin theme is Bootstrap 5, so this stays theme-native
 * and dark-mode-aware via --bs-* tokens.
 */

use mindstellar\cloudflare\Analytics;
use mindstellar\cloudflare\CacheRules;
use mindstellar\cloudflare\Client;
use mindstellar\cloudflare\Plugin;

if (!defined('ABS_PATH')) {
    exit('Direct access is not allowed.');
}

if (!function_exists('cf_bytes')) {
    function cf_bytes(int $bytes): string
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $i = 0;
        $n = (float)$bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }
        return round($n, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}

if (!function_exists('cf_icon')) {
    function cf_icon(string $name): string
    {
        $paths = array(
            'cloud'   => '<path d="M6.5 19a4.5 4.5 0 0 1-.5-8.98 6 6 0 0 1 11.66-1.52A4.5 4.5 0 0 1 17.5 19h-11Z"/>',
            'link'    => '<path d="M10.5 13.5a4 4 0 0 0 5.66 0l2-2a4 4 0 1 0-5.66-5.66l-1 1"/><path d="M13.5 10.5a4 4 0 0 0-5.66 0l-2 2a4 4 0 1 0 5.66 5.66l1-1"/>',
            'shield'  => '<path d="M12 3 5 6v5c0 4.4 3 7.4 7 8.5 4-1.1 7-4.1 7-8.5V6l-7-3Z"/><path d="m9 12 2 2 4-4"/>',
            'bolt'    => '<path d="M13 3 4 14h7l-1 7 9-11h-7l1-7Z"/>',
            'chart'   => '<path d="M4 20V4"/><path d="M4 20h16"/><path d="M8.5 17v-4"/><path d="M13 17V9"/><path d="M17.5 17v-6"/>',
            'refresh' => '<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 4v5h-5"/>',
            'trash'   => '<path d="M4 7h16M9 7V5h6v2m-7 0 .8 12a1 1 0 0 0 1 1h4.4a1 1 0 0 0 1-1L16 7"/>',
            'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/>',
            'chevron'  => '<path d="m6 9 6 6 6-6"/>',
            'external' => '<path d="M14 4h6v6m1-7L10 14"/><path d="M19 13v6a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h6"/>',
        );
        $d = $paths[$name] ?? '';
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
    }
}

$cfFile       = osc_plugin_folder(CF_PLUGIN_FILE) . 'admin/settings.php';
$hasToken     = Plugin::token() !== '';
$zoneId       = Plugin::zoneId();
$purgeEnabled = Plugin::purgeEnabled();
$ttlEnabled   = Plugin::ttlEnabled();
$configured   = Plugin::isConfigured();

// Live status (one API call each) — only when there's something to ask about.
$client         = Client::fromSettings();
$rulesInstalled = false;
$stats          = null;
if ($client !== null && $zoneId !== '') {
    $rulesInstalled = CacheRules::installed($client);
    $stats          = Analytics::summary($client);
}

$fileEsc = osc_esc_html($cfFile);
$base    = osc_esc_html(osc_admin_base_url(true));
$e       = 'osc_esc_html';
?>
<style>
.cf-settings { max-width: 960px; }
.cf-settings .cf-head { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin:.25rem 0 1.25rem; }
.cf-settings .cf-head-title { display:flex; align-items:center; gap:.55rem; }
.cf-settings .cf-head-title h2 { margin:0; font-size:1.5rem; font-weight:650; letter-spacing:-0.01em; }
.cf-settings .cf-head-title svg { color: var(--bs-primary); }
.cf-settings .cf-badges { display:flex; gap:.4rem; flex-wrap:wrap; }
.cf-settings .cf-badges .badge { font-weight:600; padding:.42em .7em; }
.cf-settings .card { border-color: var(--bs-border-color); }
.cf-settings .cf-title { display:flex; align-items:center; gap:.5rem; font-size:1.02rem; font-weight:640; margin:0 0 .9rem; }
.cf-settings .cf-title svg { color: var(--bs-secondary-color); }
.cf-settings .cf-lead { color: var(--bs-secondary-color); font-size:.9rem; margin:0 0 1rem; }
.cf-settings .form-label { font-weight:560; margin-bottom:.3rem; }
.cf-settings .cf-hint { color: var(--bs-secondary-color); font-size:.82rem; margin-top:.3rem; }
.cf-settings .cf-inline { display:flex; gap:.5rem; flex-wrap:wrap; margin-top:.85rem; }
.cf-settings .form-switch .form-check-input { width:2.4em; height:1.2em; }
.cf-settings .cf-ttl { transition: opacity .15s ease; }
.cf-settings .cf-ttl.is-off { opacity:.45; }
.cf-settings .cf-stats { display:grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap:.75rem; }
.cf-settings .cf-stat { padding:.95rem 1.05rem; border:1px solid var(--bs-border-color); border-radius: var(--bs-border-radius); background: var(--bs-tertiary-bg); }
.cf-settings .cf-stat-num { font-size:1.7rem; font-weight:680; line-height:1.05; letter-spacing:-0.02em; }
.cf-settings .cf-stat-lab { color: var(--bs-secondary-color); font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.04em; margin-top:.15rem; }
.cf-settings .cf-stat-sub { color: var(--bs-secondary-color); font-size:.82rem; margin-top:.15rem; }
.cf-settings .cf-ratio { grid-column: 1 / -1; }
.cf-settings .cf-ratio .progress { height:.5rem; margin-top:.55rem; background: var(--bs-secondary-bg); }
.cf-settings .cf-empty { text-align:center; padding:1.75rem 1rem; color: var(--bs-secondary-color); }
.cf-settings .cf-empty svg { color: var(--bs-border-color); }
.cf-settings .cf-savebar { display:flex; align-items:center; gap:.6rem; flex-wrap:wrap; margin-top:1.1rem; }
.cf-settings .cf-savebar .cf-spacer { flex:1 1 auto; }
.cf-settings .cf-setup > summary { list-style:none; cursor:pointer; display:flex; align-items:center; gap:.5rem; font-weight:640; padding:.85rem 1.05rem; }
.cf-settings .cf-setup > summary::-webkit-details-marker { display:none; }
.cf-settings .cf-setup > summary .cf-i { color: var(--bs-primary); display:inline-flex; }
.cf-settings .cf-setup > summary .cf-setup-sub { color: var(--bs-secondary-color); font-weight:400; font-size:.85rem; margin-left:auto; display:flex; align-items:center; gap:.35rem; }
.cf-settings .cf-setup > summary .cf-chev { display:inline-flex; transition: transform .15s ease; }
.cf-settings .cf-setup[open] > summary .cf-chev { transform: rotate(180deg); }
.cf-settings .cf-setup-body { padding:.25rem 1.15rem 1rem 1.15rem; }
.cf-settings .cf-steps { margin:0; padding-left:1.3rem; }
.cf-settings .cf-steps > li { margin-bottom:.55rem; }
.cf-settings .cf-scopes { display:flex; flex-wrap:wrap; gap:.3rem; margin-top:.4rem; }
.cf-settings .badge.text-bg-light { background-color: var(--bs-tertiary-bg) !important; color: var(--bs-secondary-color) !important; border-color: var(--bs-border-color); }
.cf-settings .cf-setup a { font-weight:560; white-space:nowrap; }
.cf-settings .cf-setup a svg { vertical-align:-3px; }
</style>

<div class="cf-settings">

  <div class="cf-head">
    <div class="cf-head-title"><?php echo cf_icon('cloud'); ?><h2><?php echo $e(__('Cloudflare', 'cloudflare')); ?></h2></div>
    <div class="cf-badges">
      <?php if ($configured) { ?>
        <span class="badge text-bg-success"><?php echo $e(__('Configured', 'cloudflare')); ?></span>
      <?php } else { ?>
        <span class="badge text-bg-secondary"><?php echo $e(__('Set-up needed', 'cloudflare')); ?></span>
      <?php } ?>
      <?php if ($zoneId !== '') { ?>
        <span class="badge text-bg-light border"><?php echo $e(__('Zone', 'cloudflare')) . ' ' . $e(substr($zoneId, 0, 8)); ?>…</span>
      <?php } ?>
      <span class="badge <?php echo $purgeEnabled ? 'text-bg-success' : 'text-bg-secondary'; ?>">
        <?php echo $e($purgeEnabled ? __('Auto-purge on', 'cloudflare') : __('Auto-purge off', 'cloudflare')); ?>
      </span>
    </div>
  </div>

  <details class="card cf-setup mb-3"<?php echo $configured ? '' : ' open'; ?>>
    <summary>
      <span class="cf-i"><?php echo cf_icon('info'); ?></span>
      <span><?php echo $e(__('Getting started', 'cloudflare')); ?></span>
      <span class="cf-setup-sub">
        <?php echo $e($configured ? __('setup & required permissions', 'cloudflare') : __('first-time setup', 'cloudflare')); ?>
        <span class="cf-chev"><?php echo cf_icon('chevron'); ?></span>
      </span>
    </summary>
    <div class="cf-setup-body">
      <ol class="cf-steps">
        <li>
          <?php echo $e(__('Create a Cloudflare', 'cloudflare')); ?>
          <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener noreferrer"><?php echo $e(__('API token', 'cloudflare')); ?> <?php echo cf_icon('external'); ?></a>
          <?php echo $e(__('with these permissions:', 'cloudflare')); ?>
          <div class="cf-scopes">
            <span class="badge text-bg-light border">Cache Purge · Edit</span>
            <span class="badge text-bg-light border">Cache Rules · Edit</span>
            <span class="badge text-bg-light border">Analytics · Read</span>
            <span class="badge text-bg-light border">Zone · Read</span>
          </div>
        </li>
        <li><?php echo $e(__('Paste it in Connection below and Save, then “Discover zone”.', 'cloudflare')); ?></li>
        <li><?php echo $e(__('Run “Test connection” — it names any missing permission.', 'cloudflare')); ?></li>
        <li><?php echo $e(__('Click “Install / update rules” to switch edge caching on.', 'cloudflare')); ?></li>
        <li><?php echo $e(__('In Cloudflare, keep Development Mode off — it bypasses the cache.', 'cloudflare')); ?></li>
      </ol>
    </div>
  </details>

  <form action="<?php echo $base; ?>" method="post">
    <?php echo osc_csrf_token_form(); ?>
    <input type="hidden" name="page" value="plugins"/>
    <input type="hidden" name="action" value="renderplugin"/>
    <input type="hidden" name="file" value="<?php echo $fileEsc; ?>"/>

    <div class="row g-3">
      <!-- Connection ------------------------------------------------------->
      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-body">
            <h3 class="cf-title"><?php echo cf_icon('link'); ?><?php echo $e(__('Connection', 'cloudflare')); ?></h3>

            <div class="mb-3">
              <label class="form-label" for="cf-token"><?php echo $e(__('API token', 'cloudflare')); ?></label>
              <input type="password" class="form-control" id="cf-token" name="api_token" autocomplete="off"
                     placeholder="<?php echo $hasToken ? $e(__('•••••••• saved', 'cloudflare')) : $e(__('Cloudflare API token', 'cloudflare')); ?>"/>
              <?php if ($hasToken) { ?>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" value="1" id="cf-clear" name="clear_token"/>
                  <label class="form-check-label cf-hint" for="cf-clear"><?php echo $e(__('Clear the stored token', 'cloudflare')); ?></label>
                </div>
              <?php } ?>
              <div class="cf-hint"><?php echo $e(__('Zone-scoped token — Cache Purge (edit), Cache Rules (edit), Analytics (read), Zone (read).', 'cloudflare')); ?></div>
            </div>

            <div class="mb-2">
              <label class="form-label" for="cf-zone"><?php echo $e(__('Zone ID', 'cloudflare')); ?></label>
              <div class="input-group">
                <input type="text" class="form-control" id="cf-zone" name="zone_id" value="<?php echo $e($zoneId); ?>"
                       placeholder="<?php echo $e(__('e.g. 023e105f4ecef8ad9ca31a8372d0c353', 'cloudflare')); ?>"/>
                <button class="btn btn-outline-secondary" type="submit" name="cf_action" value="discover_zone"><?php echo $e(__('Discover', 'cloudflare')); ?></button>
              </div>
            </div>

            <div class="cf-inline">
              <button class="btn btn-outline-secondary btn-sm" type="submit" name="cf_action" value="test"><?php echo $e(__('Test connection', 'cloudflare')); ?></button>
            </div>
          </div>
        </div>
      </div>

      <!-- Cache rules ------------------------------------------------------>
      <div class="col-12 col-xl-6">
        <div class="card h-100">
          <div class="card-body">
            <h3 class="cf-title"><?php echo cf_icon('shield'); ?><?php echo $e(__('Cache rules', 'cloudflare')); ?></h3>
            <p class="cf-lead"><?php echo $e(__('Three rules in your zone: cache public pages (respecting the origin), bypass the admin, and bypass any logged-in / personalized request. Only the plugin’s own rules are touched.', 'cloudflare')); ?></p>
            <p class="mb-3">
              <?php
              if ($client === null || $zoneId === '') {
                  echo '<span class="badge text-bg-warning">' . $e(__('Needs token + zone', 'cloudflare')) . '</span>';
              } elseif ($rulesInstalled) {
                  echo '<span class="badge text-bg-success">' . $e(__('Installed', 'cloudflare')) . '</span>';
              } else {
                  echo '<span class="badge text-bg-secondary">' . $e(__('Not installed', 'cloudflare')) . '</span>';
              }
              ?>
            </p>
            <button class="btn btn-outline-secondary btn-sm" type="submit" name="cf_action" value="install_rules">
              <?php echo cf_icon('refresh'); ?> <?php echo $e(__('Install / update rules', 'cloudflare')); ?>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Caching + TTL -------------------------------------------------------->
    <div class="card mt-3">
      <div class="card-body">
        <h3 class="cf-title"><?php echo cf_icon('bolt'); ?><?php echo $e(__('Caching', 'cloudflare')); ?></h3>

        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="cf-purge" name="purge_enabled" value="1" <?php echo $purgeEnabled ? 'checked' : ''; ?>/>
          <label class="form-check-label" for="cf-purge"><?php echo $e(__('Purge changed pages automatically when a listing, category, or page changes', 'cloudflare')); ?></label>
        </div>

        <hr class="my-3">

        <div class="form-check form-switch mb-1">
          <input class="form-check-input" type="checkbox" role="switch" id="cf-ttl-enabled" name="ttl_enabled" value="1" <?php echo $ttlEnabled ? 'checked' : ''; ?>/>
          <label class="form-check-label fw-semibold" for="cf-ttl-enabled"><?php echo $e(__('Set Cloudflare edge cache lifetime per page type', 'cloudflare')); ?></label>
        </div>
        <p class="cf-hint mb-3"><?php echo $e(__('How long Cloudflare’s edge keeps each page. The origin cache stays short and self-heals, so only Cloudflare holds the longer copy — safe because purge-on-change clears it. Off = the origin’s default everywhere.', 'cloudflare')); ?></p>

        <div class="row g-3 cf-ttl <?php echo $ttlEnabled ? '' : 'is-off'; ?>" id="cf-ttl-grid">
          <?php
          $ttlFields = array(
              'ttl_item'    => array(__('Item pages', 'cloudflare'), Plugin::ttlItem(), __('One listing — changes rarely; owners bypass cache.', 'cloudflare')),
              'ttl_static'  => array(__('Static pages', 'cloudflare'), Plugin::ttlStatic(), __('About, terms — effectively immutable.', 'cloudflare')),
              'ttl_listing' => array(__('Listings', 'cloudflare'), Plugin::ttlListing(), __('Home, search, category — keep short so new ads show fast.', 'cloudflare')),
          );
          foreach ($ttlFields as $key => $f) {
              echo '<div class="col-12 col-md-4">';
              echo '<label class="form-label" for="cf-' . $e($key) . '">' . $e($f[0]) . '</label>';
              echo '<div class="input-group">';
              echo '<input type="number" min="1" max="86400" class="form-control" id="cf-' . $e($key) . '" name="' . $e($key) . '" value="' . $e((string)$f[1]) . '"/>';
              echo '<span class="input-group-text">' . $e(__('sec', 'cloudflare')) . '</span>';
              echo '</div>';
              echo '<div class="cf-hint">' . $e($f[2]) . '</div>';
              echo '</div>';
          }
          ?>
        </div>
      </div>
    </div>

    <!-- Save / maintenance --------------------------------------------------->
    <div class="cf-savebar">
      <button type="submit" name="cf_action" value="save" class="btn btn-primary"><?php echo $e(__('Save changes', 'cloudflare')); ?></button>
      <span class="cf-spacer"></span>
      <button type="submit" name="cf_action" value="purge_all" class="btn btn-outline-danger btn-sm"
              onclick="return confirm('<?php echo $e(__('Purge the entire Cloudflare cache?', 'cloudflare')); ?>');">
        <?php echo cf_icon('trash'); ?> <?php echo $e(__('Purge everything', 'cloudflare')); ?>
      </button>
    </div>
  </form>

  <!-- Analytics ------------------------------------------------------------->
  <div class="card mt-3">
    <div class="card-body">
      <h3 class="cf-title"><?php echo cf_icon('chart'); ?><?php echo $e(__('Cache analytics', 'cloudflare')); ?> <span class="cf-lead ms-1 mb-0"><?php echo $e(__('last 24h', 'cloudflare')); ?></span></h3>
      <?php if ($stats !== null && !empty($stats['ok'])) { $ratio = (float)$stats['hitRatio']; ?>
        <div class="cf-stats">
          <div class="cf-stat cf-ratio">
            <div class="d-flex justify-content-between align-items-baseline">
              <span class="cf-stat-lab mb-0"><?php echo $e(__('Cache hit ratio', 'cloudflare')); ?></span>
              <span class="cf-stat-num" style="font-size:1.4rem;"><?php echo $e((string)$ratio); ?>%</span>
            </div>
            <div class="progress" role="progressbar" aria-valuenow="<?php echo $e((string)$ratio); ?>" aria-valuemin="0" aria-valuemax="100">
              <div class="progress-bar bg-success" style="width: <?php echo $e((string)max(0, min(100, $ratio))); ?>%"></div>
            </div>
          </div>
          <div class="cf-stat">
            <div class="cf-stat-num"><?php echo $e(number_format((float)$stats['requests'])); ?></div>
            <div class="cf-stat-lab"><?php echo $e(__('Requests', 'cloudflare')); ?></div>
            <div class="cf-stat-sub"><?php echo $e(number_format((float)$stats['cachedRequests'])) . ' ' . $e(__('cached', 'cloudflare')); ?></div>
          </div>
          <div class="cf-stat">
            <div class="cf-stat-num"><?php echo $e(cf_bytes((int)$stats['bytes'])); ?></div>
            <div class="cf-stat-lab"><?php echo $e(__('Bandwidth', 'cloudflare')); ?></div>
            <div class="cf-stat-sub"><?php echo $e(cf_bytes((int)$stats['cachedBytes'])) . ' ' . $e(__('from cache', 'cloudflare')); ?></div>
          </div>
        </div>
      <?php } else { ?>
        <div class="cf-empty">
          <?php echo cf_icon('chart'); ?>
          <p class="mb-0 mt-2">
            <?php
            if ($client !== null && $zoneId !== '') {
                echo $e(__('No analytics yet — the token may lack the Analytics (read) scope, or there’s no data for this window.', 'cloudflare'));
            } else {
                echo $e(__('Connect the plugin to see cache hit ratio, requests, and bandwidth.', 'cloudflare'));
            }
            ?>
          </p>
        </div>
      <?php } ?>
    </div>
  </div>
</div>

<script>
(function () {
  var toggle = document.getElementById('cf-ttl-enabled');
  var grid = document.getElementById('cf-ttl-grid');
  if (!toggle || !grid) { return; }
  function sync() { grid.classList.toggle('is-off', !toggle.checked); }
  toggle.addEventListener('change', sync);
  sync();
})();
</script>
