<?php

function phpfina_download($local_datadir,$local_id,$remote_server,$remote_id,$remote_apikey)
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
    
    $lastvalue = false;
    
    if ($dnsize>=4) {
        // Last time and value
        $d = substr($data,strlen($data)-4,4);
        $val = unpack("f",$d);
        
        clearstatcache($local_datadir.$local_id.".dat");
        $npoints = floor(filesize($local_datadir.$local_id.".dat")/4);
        $time = $local_meta->start_time + ($local_meta->interval * $npoints);
        $lastvalue = array("time"=>$time, "value"=>$val[1]);
    }
    
    echo "--downloaded: ".$dnsize." bytes\n";
    
    return $lastvalue;
}

function prepare_phpfina_segment($datadir,$local,$remote,$bytes_available) {

    // Segment data (binary)
    $segment_binary = "";

    // Allow upload if remote is blank or if meta match
    if ($remote->npoints==0 || ($local->start_time==$remote->start_time && $local->interval==$remote->interval)) {

        $npoints =  $local->npoints - $remote->npoints;
        $data_start = $remote->npoints*4;

        $header_length = 20;
        $bytes_available = $bytes_available - $header_length;
        
        if ($bytes_available>0) {

            $available_npoints = floor($bytes_available/4);
            if ($available_npoints<$npoints) $npoints = $available_npoints;

            if ($npoints>0) {
                // Read binary data
                $fh = fopen($datadir.$local->id.".dat", 'rb');
                fseek($fh,$data_start);
                $data_str = fread($fh,$npoints*4);
                fclose($fh);
                
                // Verify data_str len must be multiple of 4
                // cut off any extra bytes - this should not happen
                if (strlen($data_str) % 4 != 0) {
                    $data_str = substr($data_str,0,floor(strlen($data_str)/4)*4);
                }

                // Data length for this feed including 20 byte meta
                $segment_binary .= pack("I",strlen($data_str)+$header_length);
                // Meta part (16 bytes)
                $segment_binary .= pack("I",$remote->id);
                $segment_binary .= pack("I",$local->start_time);
                $segment_binary .= pack("I",$local->interval);
                $segment_binary .= pack("I",$data_start);
                // Data part (variable length)
                $segment_binary .= $data_str;
            }
        }
    }
    
    return $segment_binary;
}

function request($url, $data)
{
    $curl = curl_init($url);

    if ($curl === false) {
        return array("success"=>false, "message"=>"failed to init curl");
    }

    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
    curl_setopt($curl, CURLOPT_POSTFIELDS, $data);
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($curl, CURLOPT_TIMEOUT, 10);

    $headers = [
        'Content-Type: application/octet-stream'
    ];

    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

    $curl_response = curl_exec($curl);

    if ($curl_response === false) {
        $error_code = curl_errno($curl);
        $error_msg = curl_error($curl);
        curl_close($curl);

        if ($error_code == CURLE_OPERATION_TIMEOUTED) {
            return array("success"=>false, "message"=>"timeout error");
        } else {
            return array("success"=>false, "message"=>$error_msg);       
        }
    }

    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_code >= 400) {
        return array("success"=>false, "message"=>"HTTP error: $http_code");       
    }

    return array("success"=>true, "result"=>$curl_response);
}

