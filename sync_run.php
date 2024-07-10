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

while(true){
    $len = $redis->llen("sync-queue");

    if ($len>0) {
        $syncitem = $redis->lpop("sync-queue");
        print $syncitem."\n";
        
        $params = json_decode($syncitem);

        // ----------------------------------------------------------------------------
        
        if ($params->action=="download") {
        
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
        
        // ----------------------------------------------------------------------------
        
        if ($params->action=="upload") {
        
            if (!$local = $feed->get_meta($params->local_id)) {
                continue;
            }
            
            if (!$remote = json_decode(file_get_contents($params->remote_server."/feed/getmeta.json?apikey=".$params->remote_apikey."&id=".$params->remote_id))) {
                continue;
            }
            
            if ($params->engine==Engine::PHPFINA) {
                phpfina_upload(
                    $settings['feed']['phpfina']['datadir'],
                    $local,
                    $remote,
                    $params->remote_server,
                    $params->remote_apikey
                );
            }
            
            if ($params->engine==Engine::PHPTIMESERIES) {
                phptimeseries_upload(
                    $settings['feed']['phptimeseries']['datadir'],
                    $local,
                    $remote,
                    $params->remote_server,
                    $params->remote_apikey
                );
            }
        }
        
        

    } else {
        break;
    }
    sleep(1);
}
