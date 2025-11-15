#!/usr/bin/env php
<?php
/**
 * CLI runner for moovenipakstatus module
 * Usage examples:
 *   php modules/moovenipakstatus/bin/cron.php
 *   php modules/moovenipakstatus/bin/cron.php --limit=200
 */

declare(strict_types=1);

$moduleDir = realpath(__DIR__ . '/..');
if (!$moduleDir) { fwrite(STDERR, "Cannot resolve module dir\n"); exit(1); }
// Expected shop root is two levels up from module dir: .../modules/moovenipakstatus -> ../../
$root = realpath($moduleDir . '/../..');
// Fallback: try one more up if structure differs
if (!$root || !file_exists($root . '/config/config.inc.php')) {
    $alt = realpath($moduleDir . '/../../..');
    if ($alt && file_exists($alt . '/config/config.inc.php')) {
        $root = $alt;
    }
}
if (!$root || !file_exists($root . '/config/config.inc.php')) { fwrite(STDERR, "Cannot resolve PS root or config.inc.php not found\n"); exit(1); }

chdir($root);
require_once $root . '/config/config.inc.php';

// Optional simple argv parser
function cliOpt(string $name, $default=null){
    foreach($GLOBALS['argv'] as $arg){
        if(strpos($arg, '--'.$name.'=') === 0){ return substr($arg, strlen($name)+3); }
        if($arg === '--'.$name){ return true; }
    }
    return $default;
}

require_once _PS_MODULE_DIR_ . 'mijoravenipak/classes/MjvpDb.php';
// Ensure main module class is loaded for MjvpBase -> new MijoraVenipak()
require_once _PS_MODULE_DIR_ . 'mijoravenipak/mijoravenipak.php';
require_once _PS_MODULE_DIR_ . 'mijoravenipak/classes/MjvpApi.php';
require_once _PS_MODULE_DIR_ . 'moovenipakstatus/classes/MoovEnipakStatusService.php';

$force = (bool) cliOpt('force', false);
$verbose = (bool) cliOpt('verbose', false);
$enabled = (bool) Configuration::get('MOOVENIPAK_AUTO_ENABLED');
if (!$enabled && !$force){
    fwrite(STDOUT, "moovenipakstatus: WARNING automation disabled, proceeding anyway (non-blocking). Use --force to suppress this warning.\n");
}

$limitArg = cliOpt('limit');
$limit = $limitArg !== null ? (int)$limitArg : (int)Configuration::get('MOOVENIPAK_AUTO_MAX_PER_RUN');
if($limit <= 0){ $limit = 100; }

$service = new MoovEnipakStatusService();
$start = microtime(true);
$refreshed = 0;
$updated = 0;
try {
    $refreshed = $service->refreshVenipakTracking($limit);
    $updated   = $service->applyScenarios($limit);
} catch (Throwable $e) {
    fwrite(STDERR, "ERROR: ".$e->getMessage()."\n");
    // For visibility also output trace top line
    $trace = $e->getTraceAsString();
    fwrite(STDERR, substr($trace, 0, 1000)."\n");
    exit(1);
}
$elapsed = microtime(true) - $start;

if ($verbose){
    $sc = Configuration::get('MOOVENIPAK_AUTO_SCENARIOS');
    fwrite(STDOUT, "scenarios=".$sc."\n");
}

fwrite(STDOUT, sprintf("moovenipakstatus: refreshed=%d updated=%d time=%.2fs\n", $refreshed, $updated, $elapsed));

exit(0);
