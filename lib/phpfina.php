<?php

function import_phpfina($local_datadir,$local_id,$remote_server,$remote_id,$remote_apikey)
{    
    // Download phpfiwa feed meta data
    $remote_meta = json_decode(file_get_contents($remote_server."/feed/getmeta.json?apikey=$remote_apikey&id=".$remote_id));
    
    if ($remote_meta==false || !isset($remote_meta->start_time) || !isset($remote_meta->interval)) {
        echo "ERROR: Invalid remote meta, returned false\n";
        echo json_encode($remote_meta)."\n";
        return false;
    }
    
    $local_meta = new stdClass();
    $local_meta->start_time = 0;
    $local_meta->npoints = 0;
    
    // Load local meta data file
    if (file_exists($local_datadir.$local_id.".meta"))
    {
        $local_meta = new stdClass();
        
        if (!$metafile = @fopen($local_datadir.$local_id.".meta", 'rb')) {
            echo "Cannot open local metadata file\n";
            return false;
        }
        
        fseek($metafile,8);
        
        $tmp = unpack("I",fread($metafile,4)); 
        $local_meta->interval = $tmp[1];
        $tmp = unpack("I",fread($metafile,4)); 
        $local_meta->start_time = $tmp[1];
        
        fclose($metafile);
        
        $bytesize = 0;
        if (file_exists($local_datadir.$local_id.".dat")) {
            clearstatcache($local_datadir.$local_id.".dat");
            $bytesize += filesize($local_datadir.$local_id.".dat");
        }
        $npoints = floor($bytesize / 4.0);
        $local_meta->npoints = $npoints;
        
    }
    
    if ($local_meta->start_time==0 && $local_meta->npoints==0)
    {
        $local_meta = $remote_meta;

        if (!$metafile = @fopen($local_datadir.$local_id.".meta", 'wb')) {
            echo "Cannot open local metadata file\n";
            return false;
        }
        
        // First 8 bytes used to hold id and npoints but are now removed.
        fwrite($metafile,pack("I",0));
        fwrite($metafile,pack("I",0)); 
        fwrite($metafile,pack("I",$local_meta->interval));
        fwrite($metafile,pack("I",$local_meta->start_time)); 
        fclose($metafile);
    }
    
    // We now check if the local meta data is the same as the remote meta data.
    // Given that the starttime, the interval and the feedname is the same we assume
    // that we are dealing with the same feed
    if ($local_meta->start_time != $remote_meta->start_time || $local_meta->interval != $remote_meta->interval)
    {
        echo "ERROR: Local and remote meta data do not match\n";
        echo "-- local->start = ".$local_meta->start_time." remote->start = ".$remote_meta->start_time."\n";
        echo "-- local->interval = ".$local_meta->interval." remote->interval = ".$remote_meta->interval."\n";
        return false;
    }
    
    $downloadfrom = 0;
    if (file_exists($local_datadir.$local_id.".dat")) {
        $downloadfrom = filesize($local_datadir.$local_id.".dat");
        if (intval($downloadfrom/4.0)!=($downloadfrom/4.0)) { 
            echo "ERROR: local datafile filesize is not an integer number of 4 bytes\n";  
            return false; 
        }
    }

    $url = $remote_server."/feed/export.json?apikey=$remote_apikey&id=$remote_id&start=$downloadfrom";
    
    if (!$primary = @fopen( $url, 'r' )) {
        echo "Cannot access remote server\n";
        return false;
    }

    if ($downloadfrom>=4) {
        // update last datapoint
        $firstdp = fread($primary,4);
        if (!$backup = @fopen($local_datadir.$local_id.".dat", 'c')) {
            echo "Cannot open local data file - to update last datapoint\n";
            return false;
        }
        fseek($backup,$downloadfrom-4);
        fwrite($backup,$firstdp);
        fclose($backup);
    }

    if (!$backup = @fopen($local_datadir.$local_id.".dat", 'a')) {
        echo "Cannot open local data file - to append data\n";
        return false;
    }

    $dnsize = 0;
    if ($primary)
    {
        for (;;)
        {
            $data = fread($primary,8192);
            fwrite($backup,$data);
            $dnsize += strlen($data);
            if (feof($primary)) break;
        }
    }

    fclose($backup);
    fclose($primary);
    
    // Last time and value
    $d = substr($data,strlen($data)-4,4);
    $val = unpack("f",$d);
    
    clearstatcache($local_datadir.$local_id.".dat");
    $npoints = floor(filesize($local_datadir.$local_id.".dat")/4);
    $time = $local_meta->start_time + ($local_meta->interval * $npoints);
    
    echo "--downloaded: ".$dnsize." bytes\n";
    
    return array("time"=>$time, "value"=>$val[1]);
}
