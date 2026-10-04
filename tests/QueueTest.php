<?php
/* Run: php tests/QueueTest.php */
define('ABS_PATH', __DIR__);
require __DIR__ . '/../src/Queue.php';

use mindstellar\cloudflare\Queue;

$fail = 0;
$check = static function (string $name, bool $ok) use (&$fail) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $fail += $ok ? 0 : 1;
};

$now = strtotime('2026-10-04 12:00:00');
$check('cutoff is 48 hours back', Queue::cutoff($now) === '2026-10-02 12:00:00');
$check('48 hour limit', Queue::MAX_AGE_HOURS === 48);

$src = file_get_contents(__DIR__ . '/../index.php');
$check('edited_category purges categories', (bool)preg_match("/osc_add_hook\('edited_category', 'cf_purge_category'\)/", $src));
$check('page_cache_purge is registered', (bool)preg_match("/osc_add_hook\('page_cache_purge', 'cf_purge_all'\)/", $src));
$check('shim only without osc_purge_page_cache', (bool)preg_match("/if \(function_exists\('osc_purge_page_cache'\)\) \{.*?\} else \{.*?theme_activate.*?after_plugin_activate.*?after_plugin_deactivate.*?admin_form_after_save/s", $src));
$check('queue sentinel is *', Queue::ALL === '*');
$queue = file_get_contents(__DIR__ . '/../src/Queue.php');
$check('flush purges everything first and clears the queue', (bool)preg_match("/purgeEverything\(\)\['ok'\].*DELETE FROM.*clearDropped/s", $queue));
$check('flush keeps * out of the URL batch', strpos($queue, "WHERE s_url <> ?") !== false);
$purge = file_get_contents(__DIR__ . '/../src/Purge.php');
$check('purge-all has a 60 s cooldown that queues *', strpos($purge, 'PURGE_ALL_COOLDOWN = 60') !== false && strpos($purge, 'Queue::add(array(Queue::ALL))') !== false);

exit($fail ? 1 : 0);
