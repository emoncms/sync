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
$upload_queue = array();

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
            $upload_queue[] = $params;
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

// -----------------------------------------------------------------
// 3. Process upload queue (using efficient sync)
// -----------------------------------------------------------------

if (count($upload_queue)==0) {
    die;
}

$local_meta = array();
$remote_meta = array();

foreach ($upload_queue as $params) {
    if (!$local = $feed->get_meta($params->local_id)) {
        continue;
    }

    if (!$remote = json_decode(file_get_contents($params->remote_server."/feed/getmeta.json?apikey=".$params->remote_apikey."&id=".$params->remote_id))) {
        continue;
    }
    
    $local_meta[$params->local_id] = $local;
    $remote_meta[$params->remote_id] = $remote;
}

// Standard apache2 upload limit is 2 MB
// we limit upload size to a conservative 1 MB here
$max_upload_size = 1024*1024; // 1 MB

while(true) {

    $upload_str = "";

    foreach ($upload_queue as $params) {
        $local = $local_meta[$params->local_id];
        $remote = $remote_meta[$params->remote_id];
                   
        // local ahead of remote
        if ($local->npoints>$remote->npoints) {
            $bytes_available = $max_upload_size - strlen($upload_str);
                
            if ($params->engine==Engine::PHPFINA) {
                $upload_str .= prepare_phpfina_segment($settings['feed']['phpfina']['datadir'],$local,$remote,$bytes_available);
            }
            
            if ($params->engine==Engine::PHPTIMESERIES) {
                $upload_str .= prepare_phptimeseries_segment($settings['feed']['phptimeseries']['datadir'],$local,$remote,$bytes_available);                  
            }
        }
    }
    
    if (strlen($upload_str)==0) {
        die;
    } else {
        print "upload size: ".strlen($upload_str)."\n";
    }

    $checksum = crc32($upload_str);
    $upload_str .= pack("I",$checksum);

    $result_sync = request($params->remote_server."/feed/sync?apikey=".$params->remote_apikey,$upload_str);

    $result = json_decode($result_sync);
    if ($result==null) {
       die("error parsing response from server: $result_sync");
    }

    if ($result->success==false) {
       die($result->message);
    }

    foreach ($result->updated_feed_meta as $updated_feed) {
        $remote_meta[$updated_feed->id]->start_time = $updated_feed->start_time;
        $remote_meta[$updated_feed->id]->interval = $updated_feed->interval;
        $remote_meta[$updated_feed->id]->npoints = $updated_feed->npoints;
    }
    sleep(1);
}
