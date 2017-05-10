<?php

$fp = fopen("/tmp/sync-runlock", "w");
if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

echo "SYNC: Starting\n";
define('EMONCMS_EXEC', 1);
chdir("/var/www/emoncms");
require "process_settings.php";
echo "SYNC: Home dir: $homedir\n";
if (!isset($homedir)) $homedir = "/home/pi";
chdir("$homedir/sync/");

require "lib/phpfina.php";

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
        
        import_phpfina(
            $feed_settings['phpfina']['datadir'],
            $params->local_id,
            $params->remote_server,
            $params->remote_id,
            $params->remote_apikey
        );
        
        // process($syncitem);
    } else {
        // break;
    }
    sleep(1);
}
