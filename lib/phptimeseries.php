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

function prepare_phptimeseries_segment($datadir,$local,$remote,$bytes_available) {

    // Segment data (binary)
    $segment_binary = "";

    // Allow upload if remote is blank or if meta match
    if ($remote->npoints==0 || ($local->start_time==$remote->start_time)) {

        $npoints =  $local->npoints - $remote->npoints;
        $data_start = $remote->npoints*9;

        $header_length = 12;
        $bytes_available = $bytes_available - $header_length;

        if ($bytes_available>0) {

            $available_npoints = floor($bytes_available/9);
            if ($available_npoints<$npoints) $npoints = $available_npoints;

            if ($npoints>0) {
                // Read binary data
                $fh = fopen($datadir."feed_".$local->id.".MYD", 'rb');
                fseek($fh,$data_start);
                $data_str = fread($fh,$npoints*9);
                fclose($fh);
                
                // Verify data_str len must be multiple of 9
                // cut off any extra bytes - this should not happen
                if (strlen($data_str) % 9 != 0) {
                    $data_str = substr($data_str,0,floor(strlen($data_str)/9)*9);
                }

                // Data length for this feed including 12 byte meta
                $segment_binary .= pack("I",strlen($data_str)+$header_length);
                // Meta part (16 bytes)
                $segment_binary .= pack("I",$remote->id);
                $segment_binary .= pack("I",$data_start);
                // Data part (variable length)
                $segment_binary .= $data_str;
            }
        }
    }
    
    return $segment_binary;
}
