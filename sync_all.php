<?php
// Get script location
list($scriptPath) = get_included_files();
$scriptPath = str_replace("/sync_upload.php","",$scriptPath);
// print $scriptPath;
// chdir($scriptPath);
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

foreach ($feeds as $tagname=>$feed){
        
    $local = $feeds[$tagname]->local;
    $remote = $feeds[$tagname]->remote;
    
    if (!$local->exists && $remote->exists) {
        // echo "remote only";
        // Create local feeds
    }
    
    else if ($local->exists && !$remote->exists) {
        // echo "local only"; 
        // Create remote feeds
        if ($local->engine==Engine::PHPFINA) {
            print "creating feed\n";
            print json_encode($local)."\n";

            $url = $host."/feed/create.json?";
            $url .= "apikey=".$apikey_write;
            $url .= "&name=".urlencode($local->name);
            $url .= "&tag=".urlencode($local->tag);
            $url .= "&engine=5";
            $url .= "&options=".json_encode(array("interval"=>$local->interval));

            $result = json_decode(file_get_contents($url));
            if ($result->success) {
                $remote_id = $result->feedid;
                phpfina_upload($settings['feed']['phpfina']['datadir'],$local->id,$host,$remote_id,$apikey_write);
            }
        }
    }
    
    else if ($local->start_time==$remote->start_time && $local->interval==$remote->interval) {
        // echo "both";
        
        if ($local->npoints>$remote->npoints) {
            // local ahead of remote
            echo $tagname."\n";
            if ($local->engine==Engine::PHPFINA) {
                phpfina_upload($settings['feed']['phpfina']['datadir'],$local->id,$host,$remote->id,$apikey_write);        
            }
        } /*else if ($local->npoints<$remote->npoints) {
            echo "local behind remote";
            
            if ($local->engine==Engine::PHPFINA) {
                $lastvalue = phpfina_download($settings['feed']['phpfina']['datadir'],$local->id,$host,$remote->id,$apikey_read);
                if ($lastvalue) $redis->hMset("feed:".$local->id, $lastvalue);
            }
            
            
        } else {
            echo " local and remote the same";
        }*/
    }
}