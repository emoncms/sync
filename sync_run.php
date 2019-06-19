<?php
// Get script location
list($scriptPath) = get_included_files();
$scriptPath = str_replace("/sync_run.php","",$scriptPath);

$fp = fopen("/tmp/sync-runlock", "w");
if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

echo "SYNC: Starting\n";
define('EMONCMS_EXEC', 1);
chdir("/var/www/emoncms");
require "process_settings.php";
chdir($scriptPath);

require "lib/phpfina.php";
require "lib/phptimeseries.php";

// Load redis
if (!$redis_enabled) { echo "ERROR: Redis is not enabled"; die; }

$redis = new Redis();
$connected = $redis->connect($redis_server['host'], $redis_server['port']);
if (!$connected) { echo "Can't connect to redis at ".$redis_server['host'].":".$redis_server['port']; die; }
if (!empty($redis_server['prefix'])) $redis->setOption(Redis::OPT_PREFIX, $redis_server['prefix']);
if (!empty($redis_server['auth'])) {
    if (!$redis->auth($redis_server['auth'])) {
        echo "Can't connect to redis at ".$redis_server['host'].", autentication failed"; die;
    }
}
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
                    $feed_settings['phpfina']['datadir'],
                    $params->local_id,
                    $params->remote_server,
                    $params->remote_id,
                    $params->remote_apikey
                );
            }
            
            if ($params->engine==Engine::PHPTIMESERIES) {
                $lastvalue = phptimeseries_download(
                    $feed_settings['phptimeseries']['datadir'],
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

            if ($params->engine==Engine::PHPFINA) {
                phpfina_upload(
                    $feed_settings['phpfina']['datadir'],
                    $params->local_id,
                    $params->remote_server,
                    $params->remote_id,
                    $params->remote_apikey
                );
            }
            
            if ($params->engine==Engine::PHPTIMESERIES) {
                phptimeseries_upload(
                    $feed_settings['phptimeseries']['datadir'],
                    $params->local_id,
                    $params->remote_server,
                    $params->remote_id,
                    $params->remote_apikey
                );
            }
        }
        

    } else {
        break;
    }
    sleep(1);
}
