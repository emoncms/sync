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
        
        if (!isset($remote->id)) {
            // This skips all feeds that do not exist on the remote server
            // Remote server feeds should be created when doing the sync
            // module selective manual upload
            continue;
        }
        
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
        
        else if ($local->exists && $remote->exists) {
            
            // local ahead of remote
            if ($local->npoints>$remote->npoints) {
                
                if ($local->engine==Engine::PHPFINA) {
                
                    // Allow upload if remote is blank or if meta match
                    if ($remote->npoints==0 || ($local->start_time==$remote->start_time && $local->interval==$remote->interval)) {
                    
                        $npoints =  $local->npoints - $remote->npoints;
                        $data_start = $remote->npoints*4;

                        // limit by upload limit
                        $bytes_available = $max_upload_size - strlen($upload_str) - 20;
                        if ($bytes_available>0) {

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

                                // Data length for this feed including 20 byte meta
                                $upload_str .= pack("I",strlen($data_str)+20);
                                // Meta part (16 bytes)
                                $upload_str .= pack("I",$remote->id);
                                $upload_str .= pack("I",$local->start_time);
                                $upload_str .= pack("I",$local->interval);
                                $upload_str .= pack("I",$data_start);
                                // Data part (variable length)
                                $upload_str .= $data_str;
                            }
                        }
                    }
                }
                
                if ($local->engine==Engine::PHPTIMESERIES) {

                    // Allow upload if remote is blank or if meta match
                    if ($remote->npoints==0 || ($local->start_time==$remote->start_time)) {

                        $npoints =  $local->npoints - $remote->npoints;
                        $data_start = $remote->npoints*9;

                        // limit by upload limit
                        $bytes_available = $max_upload_size - strlen($upload_str) - 12;
                        if ($bytes_available>0) {

                            $available_npoints = floor($bytes_available/9);
                            if ($available_npoints<$npoints) $npoints = $available_npoints;

                            if ($npoints>0) {
                                // Read binary data
                                $fh = fopen($settings['feed']['phptimeseries']['datadir']."feed_".$local->id.".MYD", 'rb');
                                fseek($fh,$data_start);
                                $data_str = fread($fh,$npoints*9);
                                fclose($fh);
                                
                                // Verify data_str len must be multiple of 4
                                // cut off any extra bytes - this should not happen
                                if (strlen($data_str) % 9 != 0) {
                                    $data_str = substr($data_str,0,floor(strlen($data_str)/9)*9);
                                }

                                // Data length for this feed including 12 byte meta
                                $upload_str .= pack("I",strlen($data_str)+12);
                                // Meta part (16 bytes)
                                $upload_str .= pack("I",$remote->id);
                                $upload_str .= pack("I",$data_start);
                                // Data part (variable length)
                                $upload_str .= $data_str;
                            }
                        }
                    }
                }
            }
        }
    }

    print "upload size: ".strlen($upload_str)."\n";
    
    if (strlen($upload_str)==0) {
        die("nothing to upload");
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


