<?php
// Get script location
list($scriptPath) = get_included_files();
$scriptPath = str_replace("/sync_all.php","",$scriptPath);
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

// Copy remote meta to array by id
$remote_meta = array();
foreach ($feeds as $tagname=>$feed){
    $remote = $feeds[$tagname]->remote;
    if (!isset($remote->id)) continue;
    $remote_meta[$remote->id] = $remote;
}

// Standard apache2 upload limit is 2 MB
// we limit upload size to a conservative 1 MB here
$max_upload_size = 1024*1024; // 1 MB

while(true) {

    $upload_str = "";

    foreach ($feeds as $tagname=>$feed){
            
        $local = $feeds[$tagname]->local;
        $remote = $feeds[$tagname]->remote;
        
        if ($local->exists && !$remote->exists) {
            // echo "local only"; 
            // Create remote feeds
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
                    $remote_meta[$remote->id] = $remote;
                }
            }
        }
        
        if ($local->exists && $remote->exists) {
            
            $remote->npoints = $remote_meta[$remote->id]->npoints;
            
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
        die;
    } else {
        print "upload size: ".strlen($upload_str)."\n";
    }

    $checksum = crc32($upload_str);
    $upload_str .= pack("I",$checksum);

    $result_sync = request("$host/feed/sync?apikey=$apikey_write",$upload_str);

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
