<?php
// Get script location
list($scriptPath) = get_included_files();
$scriptPath = str_replace("/sync_upload.php","",$scriptPath);
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
        
        // We dont strictly need to map these here as these are linked objects..
        $remote->start_time = $remote_meta[$remote->id]->start_time;
        $remote->interval = $remote_meta[$remote->id]->interval;
        $remote->npoints = $remote_meta[$remote->id]->npoints;
        
        if (!$local->exists && $remote->exists) {
            // echo "remote only";
            // Create local feeds
        }
        
        else if ($local->exists && !$remote->exists) {
            // echo "local only"; 
            // Create remote feeds
        }
        
        else if ($remote->npoints==0 || ($local->start_time==$remote->start_time && $local->interval==$remote->interval)) {
            // echo "both";
            
            if ($local->npoints>$remote->npoints) {
                // local ahead of remote
                // echo $tagname."\n";
                if ($local->engine==Engine::PHPFINA) {
                    // echo "phpfina_upload: $local->id,$remote->id\n";
                    
                    // uploaded 1 point, to_meta = 1, from_meta = 5, we need to upload 4 points so:
                    $npoints =  $local->npoints - $remote->npoints;
                    $data_start = $remote->npoints*4;

                    // limit by upload limit
                    $bytes_available = $max_upload_size - strlen($upload_str) - 20;
                    if ($bytes_available>0) {
                        // print "bytes_available: ".($bytes_available)."\n";

                        $available_npoints = floor($bytes_available/4);
                        if ($available_npoints<$npoints) $npoints = $available_npoints;

                        if ($npoints>0) {
                            // Read binary data
                            $fh = fopen($settings['feed']['phpfina']['datadir'].$local->id.".dat", 'rb');
                            fseek($fh,$data_start);
                            $data_str = fread($fh,$npoints*4);
                            fclose($fh);
                            
                            // Verify data_str len must be multiple of 4
                            // cut off any extra bytes - this should not happen
                            if (strlen($data_str) % 4 != 0) {
                                $data_str = substr($data_str,0,floor(strlen($data_str)/4)*4);
                            }

                            $upload_str .= pack("I",$remote->id);
                            $upload_str .= pack("I",$local->start_time);
                            $upload_str .= pack("I",$local->interval);
                            $upload_str .= pack("I",$data_start); 
                            $upload_str .= pack("I",strlen($data_str));
                            $upload_str .= $data_str;
                        }
                    }
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

    print "upload size: ".strlen($upload_str)."\n";

    if (strlen($upload_str)==0) {
        die("nothing to upload");
    }

    $checksum = crc32($upload_str);
    $upload_str .= pack("I",$checksum);

    print "\n\n";
    $result = request("$host/feed/sync?apikey=$apikey_write",$upload_str);

    $result = json_decode($result);
    if ($result==null) {
       die("error parsing response from server");
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


