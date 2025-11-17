#!/usr/bin/env php
<?php
/**
 * Track-only helper to refresh Venipak tracking statuses
 * Usage: php modules/moovenipakstatus/bin/track.php [--limit=200] [--verbose]
 */

declare(strict_types=1);

$moduleDir = realpath(__DIR__ . '/..');
if (!$moduleDir) { fwrite(STDERR, "Cannot resolve module dir\n"); exit(1); }
$root = realpath($moduleDir . '/../..');
if (!$root || !file_exists($root . '/config/config.inc.php')) {
    $alt = realpath($moduleDir . '/../../..');
    if ($alt && file_exists($alt . '/config/config.inc.php')) { $root = $alt; }
}
if (!$root || !file_exists($root . '/config/config.inc.php')) { fwrite(STDERR, "Cannot resolve PS root or config.inc.php not found\n"); exit(1); }

chdir($root);
require_once $root . '/config/config.inc.php';

function cliOpt(string $name, $default=null){
    foreach($GLOBALS['argv'] as $arg){
        if(strpos($arg, '--'.$name.'=') === 0){ return substr($arg, strlen($name)+3); }
        if($arg === '--'.$name){ return true; }
    }
    return $default;
}

require_once _PS_MODULE_DIR_ . 'mijoravenipak/mijoravenipak.php';
require_once _PS_MODULE_DIR_ . 'mijoravenipak/classes/MjvpApi.php';
require_once _PS_MODULE_DIR_ . 'moovenipakstatus/classes/MoovEnipakStatusService.php';

$limitArg = cliOpt('limit');
$limit = $limitArg !== null ? (int)$limitArg : (int)Configuration::get('MOOVENIPAK_AUTO_MAX_PER_RUN');
if($limit <= 0){ $limit = 100; }
$verbose = (bool) cliOpt('verbose', false);

$service = new MoovEnipakStatusService();
$start = microtime(true);
$refreshed = 0;
try {
    $refreshed = $service->refreshVenipakTracking($limit);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: ".$e->getMessage()."\n");
    exit(1);
}
$elapsed = microtime(true) - $start;
if ($verbose){ fwrite(STDOUT, "limit=$limit\n"); }
 fwrite(STDOUT, sprintf("moovenipakstatus-track: refreshed=%d time=%.2fs\n", $refreshed, $elapsed));
