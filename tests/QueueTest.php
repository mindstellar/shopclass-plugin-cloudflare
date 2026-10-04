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

exit($fail ? 1 : 0);
