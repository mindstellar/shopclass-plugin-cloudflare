<?php
/* Run: php tests/QueueTest.php */
define('ABS_PATH', __DIR__);
define('DB_TABLE_PREFIX', 'oc_');
ini_set('error_log', '/dev/null');

// ── Child mode: load index.php with stubbed core and print the hooks it registers.
if (($argv[1] ?? '') === 'hooks') {
    if ($argv[2] === 'on') {
        function osc_purge_page_cache() {}
    }
    $hooks = array();
    function osc_register_plugin() {}
    function osc_plugin_path($f) { return 'cloudflare/index.php'; }
    function osc_add_hook($h, $cb) { global $hooks; $hooks[] = $h . '=>' . (is_array($cb) ? implode('::', $cb) : $cb); }
    function osc_add_filter() {}
    require __DIR__ . '/../index.php';
    echo json_encode($hooks);
    exit(0);
}

// ── In-memory stand-ins for the core functions the plugin calls.
$GLOBALS['prefs'] = array();
$GLOBALS['rows']  = array();   // id => [id, url, attempts, date]
$GLOBALS['next']  = 1;

function osc_get_preference($k, $s = '') { return $GLOBALS['prefs'][$s][$k] ?? ''; }
function osc_set_preference($k, $v, $s = '', $t = '') { $GLOBALS['prefs'][$s][$k] = (string)$v; return true; }

function osc_db_select(string $sql, array $p = array()): array
{
    $r = &$GLOBALS['rows'];
    if (strpos($sql, 'COUNT(*)') !== false) {
        return array(array('n' => count(array_filter($r, static fn($x) => $x['date'] < $p[0]))));
    }
    if (strpos($sql, 's_url = ?') !== false) {
        return array_values(array_map(static fn($x) => array('pk_i_id' => $x['id']), array_filter($r, static fn($x) => $x['url'] === $p[0])));
    }
    if (strpos($sql, 's_url <> ?') !== false) {
        $out = array_filter($r, static fn($x) => $x['url'] !== $p[0]);
        usort($out, static fn($a, $b) => $a['id'] <=> $b['id']);
        return array_map(static fn($x) => array('pk_i_id' => $x['id'], 's_url' => $x['url']), $out);
    }
    throw new RuntimeException('unexpected select: ' . $sql);
}

function osc_db_execute(string $sql, array $p = array())
{
    $r = &$GLOBALS['rows'];
    if (strpos($sql, 'INSERT IGNORE') === 0) {
        foreach ($r as $x) {
            if ($x['url'] === $p[0]) {
                return;
            }
        }
        $id = $GLOBALS['next']++;
        $r[$id] = array('id' => $id, 'url' => $p[0], 'attempts' => 0, 'date' => $p[1]);
    } elseif (strpos($sql, 'DELETE FROM oc_t_cf_purge_queue WHERE dt_date <') === 0) {
        $r = array_filter($r, static fn($x) => $x['date'] >= $p[0]);
    } elseif (strpos($sql, 'DELETE FROM oc_t_cf_purge_queue WHERE pk_i_id IN') === 0) {
        foreach ($p as $id) {
            unset($r[$id]);
        }
    } elseif ($sql === 'DELETE FROM oc_t_cf_purge_queue') {
        $r = array();
    } elseif (strpos($sql, 'UPDATE oc_t_cf_purge_queue SET i_attempts') === 0) {
        foreach ($p as $id) {
            if (isset($r[$id])) {
                $r[$id]['attempts'] = min($r[$id]['attempts'] + 1, 255);
            }
        }
    } else {
        throw new RuntimeException('unexpected sql: ' . $sql);
    }
}

require __DIR__ . '/../src/Queue.php';
require __DIR__ . '/../src/Client.php';
require __DIR__ . '/../src/Plugin.php';
require __DIR__ . '/../src/Purge.php';

use mindstellar\cloudflare\Client;
use mindstellar\cloudflare\Plugin;
use mindstellar\cloudflare\Purge;
use mindstellar\cloudflare\Queue;

/** Records calls; answers from the scripted results. */
class FakeClient extends Client
{
    public array $calls = array();
    public bool $everythingOk = true;
    public array $failUrls = array();

    public function __construct() { parent::__construct('t', 'zone'); }

    public function purgeEverything(): array
    {
        $this->calls[] = 'everything';
        return array('ok' => $this->everythingOk, 'error' => '');
    }

    public function purge(array $urls): array
    {
        $this->calls[] = 'urls:' . implode(',', $urls);
        $failed = array_values(array_intersect($urls, $this->failUrls));
        return array('ok' => $failed === array(), 'failed' => $failed, 'error' => '');
    }
}

$fail  = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$fail) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok ? '' : ($detail !== '' ? "  [$detail]" : '')) . "\n";
    $fail += $ok ? 0 : 1;
};

$fake  = null;
$reset = static function () use (&$fake) {
    $GLOBALS['prefs'] = array('cloudflare' => array('api_token' => 'tok', 'zone_id' => 'zone', 'purge_enabled' => '1'));
    $GLOBALS['rows']  = array();
    $GLOBALS['next']  = 1;
    $fake = new FakeClient();
    Client::useFactory(static fn() => $fake);
};
$urls  = static fn() => array_values(array_map(static fn($x) => $x['url'], $GLOBALS['rows']));
$last  = static fn() => (string)osc_get_preference('last_purge_all', 'cloudflare');
$row   = static fn(string $u) => array_values(array_filter($GLOBALS['rows'], static fn($x) => $x['url'] === $u))[0] ?? null;

// ── basics
$now = strtotime('2026-10-04 12:00:00');
$check('cutoff is 48 hours back', Queue::cutoff($now) === '2026-10-02 12:00:00');
$check('48 hour limit', Queue::MAX_AGE_HOURS === 48);
$check('queue sentinel is *', Queue::ALL === '*');

// ── Purge::onPurgeAll
$reset();
Purge::onPurgeAll();
$check('purge-all: calls purgeEverything once', $fake->calls === array('everything'), json_encode($fake->calls));
$check('purge-all: nothing queued on success', $urls() === array(), json_encode($urls()));
$check('purge-all: stamps last_purge_all', abs((int)$last() - time()) <= 2, $last());

Purge::onPurgeAll();
$check('purge-all: second call inside 60 s does not hit the API', $fake->calls === array('everything'), json_encode($fake->calls));
$check('purge-all: second call inside 60 s queues *', $urls() === array('*'), json_encode($urls()));

$reset();
osc_set_preference('last_purge_all', (string)(time() - 61), 'cloudflare');
Purge::onPurgeAll();
$check('purge-all: after the cooldown it purges again', $fake->calls === array('everything') && $urls() === array());

$reset();
osc_set_preference('last_purge_all', (string)(time() - 30), 'cloudflare');
Purge::onPurgeAll();
$check('purge-all: 30 s ago is still cooling down', $fake->calls === array() && $urls() === array('*'));

$reset();
$fake->everythingOk = false;
Purge::onPurgeAll();
$check('purge-all: API failure queues *', $fake->calls === array('everything') && $urls() === array('*'), json_encode($urls()));
$check('purge-all: API failure does not stamp the cooldown', $last() === '', $last());

$reset();
$GLOBALS['prefs']['cloudflare']['purge_enabled'] = '0';
Purge::onPurgeAll();
$check('purge-all: disabled plugin does nothing', $fake->calls === array() && $urls() === array());

$reset();
Client::useFactory(static fn() => null);
Purge::onPurgeAll();
$check('purge-all: no client queues *', $urls() === array('*'));

// ── Queue::flush
$reset();
Queue::add(array('*', 'https://x/a', 'https://x/b'));
Plugin::recordDropped(3);
Queue::flush();
$check('flush: purgeEverything runs first, and alone', $fake->calls === array('everything'), json_encode($fake->calls));
$check('flush: success clears every row', $urls() === array(), json_encode($urls()));
$check('flush: success clears the dropped counter', Plugin::droppedCount() === 0, (string)Plugin::droppedCount());
$check('flush: success stamps last_purge_all', abs((int)$last() - time()) <= 2, $last());

$reset();
Queue::add(array('*', 'https://x/a', 'https://x/b'));
$fake->everythingOk = false;
$fake->failUrls = array('https://x/a', 'https://x/b');
Plugin::recordDropped(2);
Queue::flush();
$check('flush: failure keeps all three rows', count($urls()) === 3, json_encode($urls()));
$check('flush: failure bumps attempts on *', ($row('*')['attempts'] ?? -1) === 1, json_encode($row('*')));
$check('flush: failure then retries the per-URL rows', $fake->calls === array('everything', 'urls:https://x/a,https://x/b'), json_encode($fake->calls));
$check('flush: failure keeps the dropped counter', Plugin::droppedCount() === 2, (string)Plugin::droppedCount());
$check('flush: failure does not stamp the cooldown', $last() === '', $last());

$reset();
Queue::add(array('*', 'https://x/a'));
$fake->everythingOk = false;
$fake->failUrls = array('https://x/a');
Queue::flush();
$check('flush: failing per-URL row is kept and bumped', ($row('https://x/a')['attempts'] ?? -1) === 1, json_encode($GLOBALS['rows']));
$check('flush: * is bumped once, not twice', ($row('*')['attempts'] ?? -1) === 1, json_encode($row('*')));

$reset();
Queue::add(array('*', 'https://x/a'));
$fake->everythingOk = false;
Queue::flush();
$check('flush: * never goes out in the per-URL batch', strpos(implode('|', $fake->calls), '*') === false, json_encode($fake->calls));

$reset();
Queue::add(array('https://x/a', 'https://x/b'));
$fake->failUrls = array('https://x/b');
Queue::flush();
$check('flush: no * row means no purgeEverything', $fake->calls === array('urls:https://x/a,https://x/b'), json_encode($fake->calls));
$check('flush: succeeded URL deleted, failed URL kept', $urls() === array('https://x/b'), json_encode($urls()));

$reset();
Client::useFactory(static fn() => null);
Queue::add(array('*'));
Queue::flush();
$check('flush: no client leaves the queue alone', $urls() === array('*'));

// ── expiry
$reset();
$old = date('Y-m-d H:i:s', time() - 49 * 3600);
$new = date('Y-m-d H:i:s', time() - 47 * 3600);
$GLOBALS['rows'] = array(
    1 => array('id' => 1, 'url' => '*', 'attempts' => 5, 'date' => $old),
    2 => array('id' => 2, 'url' => 'https://x/fresh', 'attempts' => 1, 'date' => $new),
);
$GLOBALS['next'] = 3;
$fake->everythingOk = false;
$fake->failUrls = array('https://x/fresh');
Queue::flush();
$check('expiry: 49 h old * is dropped', $row('*') === null, json_encode($urls()));
$check('expiry: the drop is counted', Plugin::droppedCount() === 1, (string)Plugin::droppedCount());
$check('expiry: a dropped * does not trigger purgeEverything', !in_array('everything', $fake->calls, true), json_encode($fake->calls));
$check('expiry: 47 h old row survives', $row('https://x/fresh') !== null, json_encode($urls()));

// ── shim
$hooksFor = static function (string $mode): array {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' hooks ' . $mode);
    return json_decode((string)$out, true) ?: array();
};
$on  = $hooksFor('on');
$off = $hooksFor('off');
$check('shim: core has osc_purge_page_cache, so only page_cache_purge is hooked',
    in_array('page_cache_purge=>cf_purge_all', $on, true)
    && !array_filter($on, static fn($h) => in_array($h, array('theme_activate=>cf_purge_all', 'after_plugin_activate=>cf_purge_all', 'after_plugin_deactivate=>cf_purge_all', 'admin_form_after_save=>cf_purge_all'), true)),
    json_encode($on));
$check('shim: old core hooks the four triggers and not page_cache_purge',
    !in_array('page_cache_purge=>cf_purge_all', $off, true)
    && count(array_intersect($off, array('theme_activate=>cf_purge_all', 'after_plugin_activate=>cf_purge_all', 'after_plugin_deactivate=>cf_purge_all', 'admin_form_after_save=>cf_purge_all'))) === 4,
    json_encode($off));

exit($fail ? 1 : 0);
