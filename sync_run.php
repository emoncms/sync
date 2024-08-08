<?php
// Get script location
list($scriptPath) = get_included_files();
$scriptPath = str_replace("/sync_run.php","",$scriptPath);

$fp = fopen("/tmp/sync-runlock", "w");
if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

echo "SYNC: Starting\n";

chdir($scriptPath);
require "lib/phpfina.php";
require "lib/phptimeseries.php";

require_once "/var/www/emoncms/Lib/load_emoncms.php";

require_once "Modules/feed/feed_model.php";
$feed = new Feed($mysqli,$redis,$settings["feed"]);

echo "SYNC: Connected to Redis\n";

// -----------------------------------------------------------------
// 1. Seperate sync queue into feeds to download and feeds to upload
// -----------------------------------------------------------------
$download_queue = array();
// $upload_queue = array();

while(true){
    $len = $redis->llen("sync-queue");

    if ($len>0) {
        $syncitem = $redis->lpop("sync-queue");
        print $syncitem."\n";
        
        $params = json_decode($syncitem);
        
        if ($params->action=="download") {
            $download_queue[] = $params;
        }

        if ($params->action=="upload") {
            // No longer handled here (use sync_upload via emoncms_sync.service).
            // $upload_queue[] = $params;
        }
    } else {
        break;
    }
}

// -----------------------------------------------------------------
// 2. Process download
// -----------------------------------------------------------------
foreach ($download_queue as $params) {
    if ($params->engine==Engine::PHPFINA) {
        $lastvalue = phpfina_download(
            $settings['feed']['phpfina']['datadir'],
            $params->local_id,
            $params->remote_server,
            $params->remote_id,
            $params->remote_apikey
        );
    }

    if ($params->engine==Engine::PHPTIMESERIES) {
        $lastvalue = phptimeseries_download(
            $settings['feed']['phptimeseries']['datadir'],
            $params->local_id,
            $params->remote_server,
            $params->remote_id,
            $params->remote_apikey
        );
    }

    if ($lastvalue) $redis->hMset("feed:".$params->local_id, $lastvalue);
}
