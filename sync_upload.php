<?php
// Get script location
list($scriptPath) = get_included_files();
$scriptPath = str_replace("/sync_upload.php","",$scriptPath);

$fp = fopen("/tmp/sync-upload-lock", "w");
if (! flock($fp, LOCK_EX | LOCK_NB)) { echo "Already running\n"; die; }

chdir($scriptPath);
require "lib/phpfina.php";
require "lib/phptimeseries.php";

require_once "/var/www/emoncms/Lib/load_emoncms.php";

require_once "Modules/feed/feed_model.php";
$feed = new Feed($mysqli,$redis,$settings["feed"]);

require_once "Modules/input/input_model.php";
$input = new Input($mysqli,$redis, $feed);
    
include "Modules/sync/sync_model.php";
$sync = new Sync($mysqli,$feed);

$userid = 1;

$r = $sync->remote_load($userid);
$host = $r->host;
$apikey_read = $r->apikey_read;
$apikey_write = $r->apikey_write;

$feeds = $sync->get_feed_list($userid);
if ((isset($feeds['success']) && $feeds['success']==false) || !is_array($feeds)) {
    print "Error: could not load feeds\n";
    die;
}

// ------------------------------------------------
// Option to upload all and create remote feeds if they do not exist
// ------------------------------------------------
if (isset($argv[1]) && $argv[1]=="all") {
    print "Sync all\n";
    foreach ($feeds as $tagname=>$f){
        $local = $feeds[$tagname]->local;
        $remote = $feeds[$tagname]->remote;

        if ($local->exists && !$remote->exists) {
            if ($local->engine==Engine::PHPFINA || $local->engine==Engine::PHPTIMESERIES) {
                print "creating feed\n";
                print json_encode($local)."\n";

                $url = $host."/feed/create.json?";
                $url .= "apikey=".$apikey_write;
                $url .= "&name=".urlencode($local->name);
                $url .= "&tag=".urlencode($local->tag);
                $url .= "&engine=".$local->engine;
                $url .= "&options=".json_encode(array("interval"=>$local->interval));

                $result = json_decode(file_get_contents($url));
                if ($result->success) {
                    $remote->exists = true;
                    $remote->id = $result->feedid;
                    $remote->npoints = 0;
                }
            }
        } 
    }
}

// ------------------------------------------------
// Option to enable background continuous operation
// ------------------------------------------------
$background_service = false;
if (isset($argv[2]) && $argv[2]=="bg") {
    $background_service = true;
}

$remote_id_map = array();
foreach ($feeds as $tagname=>$f) {
    if ($feeds[$tagname]->remote->exists) {
        $remote_id_map[$feeds[$tagname]->remote->id] = $tagname;
    }
}

// Standard apache2 upload limit is 2 MB
// we limit upload size to a conservative 1 MB here
$max_upload_size = 1024*1024; // 1 MB

while(true) {

    $upload_str = "";

    foreach ($feeds as $tagname=>$f) {
            
        $local = $feeds[$tagname]->local;
        $remote = $feeds[$tagname]->remote;
        
        if ($local->exists && $remote->exists) {
                        
            // local ahead of remote
            if ($local->npoints>$remote->npoints) {
                $bytes_available = $max_upload_size - strlen($upload_str);
                    
                if ($local->engine==Engine::PHPFINA) {
                    $upload_str .= prepare_phpfina_segment($settings['feed']['phpfina']['datadir'],$local,$remote,$bytes_available);
                }
                
                if ($local->engine==Engine::PHPTIMESERIES) {
                    $upload_str .= prepare_phptimeseries_segment($settings['feed']['phptimeseries']['datadir'],$local,$remote,$bytes_available);                  
                }
            }
        }
    }

    if (strlen($upload_str)==0) {

        print date('m/d/Y h:i:s a', time())."\n";
        print "- Nothing to upload\n";
        
        if (!$background_service) die;
        sleep(60);
        
        foreach ($feeds as $tagname=>$f) {
            
            if ($feeds[$tagname]->local->exists && $feeds[$tagname]->remote->exists) {
                $latest_meta = $feed->get_meta($feeds[$tagname]->local->id);
                // print "- ".$feeds[$tagname]->local->id." ".$feeds[$tagname]->local->npoints." ".$latest_meta->npoints."\n";
                $feeds[$tagname]->local->npoints = $latest_meta->npoints;
                $feeds[$tagname]->local->start_time = $latest_meta->start_time;
                $feeds[$tagname]->local->interval = $latest_meta->interval;      
            }
        }
        
        continue;
    } else {
        print "- Upload size: ".strlen($upload_str)."\n";
        $redis->set("emoncms_sync:time",time());    
        $redis->set("emoncms_sync:len",strlen($upload_str));
    }

    $checksum = crc32($upload_str);
    $upload_str .= pack("I",$checksum);

    $result_sync = request("$host/feed/sync?apikey=$apikey_write",$upload_str);
    if (!$result_sync["success"]) {
        print "- ".$result_sync["message"]."\n";
        if (!$background_service) die();
        sleep(60);
        continue;
    }
    
    $result = json_decode($result_sync["result"]);
    if ($result==null) {
        print "- error parsing response from server: ".$result_sync["result"]."\n";
        if (!$background_service) die();
        sleep(60);
        continue;    
    }

    if ($result->success==false) {
        print "- ".$result->message."\n";
        if (!$background_service) die();
        sleep(60);
        continue;    
    }

    foreach ($result->updated_feed_meta as $updated_feed) {
        $tagname = $remote_id_map[$updated_feed->id];
        $feeds[$tagname]->remote->npoints = $updated_feed->npoints;
        $feeds[$tagname]->remote->start_time = $updated_feed->start_time;
        $feeds[$tagname]->remote->interval = $updated_feed->interval;
    }
    sleep(1);
}
