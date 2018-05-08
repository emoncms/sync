<?php

function phptimeseries_download($local_datadir,$local_id,$remote_server,$remote_id,$remote_apikey)
{
    $feedname = $local_datadir."feed_$local_id.MYD";

    if (file_exists($feedname)) {
        $downloadfrom = filesize($feedname);

        if (intval($downloadfrom/9.0)!=($downloadfrom/9.0)) { 
            echo "PHPTimeSeries: local datafile filesize is not an integer number of 9 bytes\n"; 
            return false;
        }
        
    } else {
        $downloadfrom = 0;
    }
    
    $url = $remote_server."/feed/export.json?apikey=$remote_apikey&id=$remote_id&start=$downloadfrom";
    
    if (!$backup = @fopen($feedname, 'a')) {
        echo "Cannot open local data file\n";
        return false;
    }

    $primary = @fopen( $url, 'r' );
    if (!$primary) {
        echo "Failed to open remote url\n";
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
    fclose($primary);
    fclose($backup);

    echo "--downloaded: ".$dnsize." bytes\n";
}

function phptimeseries_upload($local_dir,$local_feed,$remote_host,$remote_feedid,$remote_apikey)
{

}

