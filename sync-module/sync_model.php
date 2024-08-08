<?php

/*
     All Emoncms code is released under the GNU Affero General Public License.
     See COPYRIGHT.txt and LICENSE.txt.

     ---------------------------------------------------------------------
     Emoncms - open source energy visualisation
     Part of the OpenEnergyMonitor project:
     http://openenergymonitor.org

*/

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

class Sync
{
    private $mysqli;
    private $connect_timeout = 2;
    private $total_timeout = 6;
    private $log;
    private $feed;

    public function __construct($mysqli,$feed)
    {
        $this->mysqli = $mysqli;
        $this->log = new EmonLogger(__FILE__);
        $this->feed = $feed;
    }
    
    public function remote_load($userid)
    {
        $userid = (int) $userid;
        if (!$result = $this->mysqli->query("SELECT userid,host,apikey_read,apikey_write FROM sync WHERE `userid`='$userid'")) {
            return array("success"=>false, "message"=>"SQL error");
        }
        
        if ($row = $result->fetch_object()) return $row;
        return array("success"=>false);
    }
    
    public function remote_save($userid,$host,$username,$password) 
    {
        $this->log->warn("remote save");
        // Input sanitisation
        $userid = (int) $userid;
        if (!$username || !$password) return array('success'=>false, 'message'=>_("Username or password empty"));
        $username_out = preg_replace('/[^\p{N}\p{L}_\s\-]/u','',$username);
        if ($username_out!=$username) return array('success'=>false, 'message'=>_("Username must only contain a-z 0-9 dash and underscore"));
        $username = $this->mysqli->real_escape_string($username);        
        
        // Authentication request to target server
        $password = urlencode($password);
        $result = $this->request("POST",$host."/user/auth.json","username=$username&password=$password");
        if (!$result['success']) return array("success"=>false, "message"=>"No response from remote server");

        $result = json_decode($result['result']);
        
        
        
        // If successful, save to local sync table
        if (isset($result->success) && $result->success) {  
            $apikey_read = $result->apikey_read;
            $apikey_write = $result->apikey_write;

            // Insert of Update existing entry
            $result = $this->mysqli->query("SELECT userid FROM sync WHERE `userid`='$userid'");
            if ($result->num_rows) {
                $stmt = $this->mysqli->prepare("UPDATE sync SET `host`=?, `username`=?, `apikey_read`=?, `apikey_write`=? WHERE `userid`=?");
                $stmt->bind_param("ssssi",$host,$username,$apikey_read,$apikey_write,$userid);
                if (!$stmt->execute()) return array("success"=>false, "message"=>"Error on sync module mysqli update");
            } else {
                $stmt = $this->mysqli->prepare("INSERT INTO sync (`host`,`username`,`apikey_read`,`apikey_write`,`userid`) VALUES (?,?,?,?,?)");
                $stmt->bind_param("ssssi",$host,$username,$apikey_read,$apikey_write,$userid);
                if (!$stmt->execute()) return array("success"=>false, "message"=>"Error on sync module mysqli insert");
            }
            return array("success"=>true, "host"=>$host, "username"=>$username, "apikey_read"=>$apikey_read, "apikey_write"=>$apikey_write, "userid"=>$userid);
        } else {
            return array("success"=>false, "message"=>"Authentication failure, username or password incorrect");
        }
    }
    
    public function get_feed_list($userid) {

        // 1. Load local feeds
        $localfeeds = json_decode(json_encode($this->feed->get_user_feeds_with_meta($userid)));
        // 2. Load remote settings
        $remote = $this->remote_load($userid);
        if (is_array($remote) && isset($remote['success']) && $remote['success']==false) {
            return array("success"=>false, "message"=>"Could not load remote configuration");
        }
        // 3. Load remote feeds
        
        $result = $this->request("GET",$remote->host."/feed/listwithmeta.json?apikey=".$remote->apikey_read,false);
        if (!$result['success']) return array("success"=>false, "message"=>"No response from remote server");

        $remotefeeds = json_decode($result['result']);
        if ($remotefeeds === null) {
            return array("success"=>false, "message"=>"No response from remote server: ".$result['result']);
        }
        
        $feeds = array();
        
        // Load all local feeds into feed list array
        foreach ($localfeeds as $f) {
            if (in_array($f->engine,array(Engine::PHPFINA,Engine::PHPTIMESERIES))) {
                $l = new stdClass();
                $l->exists = true;
                $l->id = (int) $f->id;
                $l->tag = $f->tag;
                $l->name = $f->name;
                
                $l->engine = isset($f->engine) ? $f->engine: '';
                $l->start_time = isset($f->start_time) ? $f->start_time: ''; 
                $l->interval = isset($f->interval) ? $f->interval: ''; 
                $l->npoints = isset($f->npoints) ? $f->npoints: ''; 
                $l->size = isset($f->size) ? $f->size: '';
                
                // Create empty remote feed entry
                // may be overwritten in the next step
                $r = new stdClass();
                $r->exists = false;
                $r->start_time = "";
                $r->interval = "";
                $r->npoints = "";
                
                $feeds[$f->tag."/".$f->name] = new stdClass();
                $feeds[$f->tag."/".$f->name]->local = $l;
                $feeds[$f->tag."/".$f->name]->remote = $r;
            }
        }

        // Load all remote feeds into feed list array
        foreach ($remotefeeds as $f) {
            if (isset($f->engine)) {
                if (in_array($f->engine,array(Engine::PHPFINA,Engine::PHPTIMESERIES))) {
                    // Move remote meta under remote heading
                    $r = new stdClass();
                    $r->exists = true;
                    $r->id = (int) $f->id;
                    $r->tag = $f->tag;
                    $r->name = $f->name;
                    
                    $r->engine = isset($f->engine) ? $f->engine: '';
                    $r->start_time = isset($f->start_time) ? $f->start_time: ''; 
                    $r->interval = isset($f->interval) ? $f->interval: ''; 
                    $r->npoints = isset($f->npoints) ? $f->npoints: ''; 
                    
                    // Only used if no local feed
                    $l = new stdClass();
                    $l->exists = false;
                    $l->tag = $f->tag;
                    $l->name = $f->name;
                    $l->start_time = "";
                    $l->interval = "";
                    $l->npoints = "";
                    
                    if (!isset($feeds[$f->tag."/".$f->name])) {
                        $feeds[$f->tag."/".$f->name] = new stdClass();
                        $feeds[$f->tag."/".$f->name]->local = $l;
                    }
                    $feeds[$f->tag."/".$f->name]->remote = $r;
                }
            }
        }

        $upload_flags = $this->get_upload_flags($userid);

        // Add upload flag to each feed
        foreach ($feeds as $key=>$f) {
            $f->upload = 0;
            if (isset($f->local->id)) {
                if (isset($upload_flags[$f->local->id])) {
                    $f->upload = $upload_flags[$f->local->id];
                }
            }
        }

        return $feeds;
    }
    
    private function request($method,$url,$body)
    {   
        $curl = curl_init($url);

        if ($curl === false) {
            return array("success"=>false, "message"=>"failed to init curl");
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        if ($body!=null) curl_setopt($curl, CURLOPT_POSTFIELDS,$body);
        
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT,$this->connect_timeout);
        curl_setopt($curl, CURLOPT_TIMEOUT,$this->total_timeout);
        
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

    // Sync feeds flags

    public function set_upload_flag($userid,$local_id,$upload)
    {
        $userid = (int) $userid;
        $local_id = (int) $local_id;
        $upload = (int) $upload;
        
        $result = $this->mysqli->query("SELECT * FROM sync_feeds WHERE `userid`='$userid' AND `local_id`='$local_id'");
        
        // if upload is set to 0, remove entry, else update or insert
        if ($upload==0) {
            if ($result->num_rows) {
                $this->mysqli->query("DELETE FROM sync_feeds WHERE `userid`='$userid' AND `local_id`='$local_id'");
            }
        } else {
            if (!$result->num_rows) {
                $this->mysqli->query("INSERT INTO sync_feeds (`userid`,`local_id`,`upload`) VALUES ('$userid','$local_id','1')");
            } else {
                $this->mysqli->query("UPDATE sync_feeds SET `upload`='1' WHERE `userid`='$userid' AND `local_id`='$local_id'");
            }
        }

        return array("success"=>true);
    }

    // Get upload flags for userid
    public function get_upload_flags($userid)
    {
        $userid = (int) $userid;
        $result = $this->mysqli->query("SELECT * FROM sync_feeds WHERE `userid`='$userid'");

        // arrange by local_id
        $upload_flags = array();
        while ($row = $result->fetch_object()) {
            $upload_flags[$row->local_id] = (int) $row->upload;
        }

        $valid_feeds = array();
        $result = $this->mysqli->query("SELECT id FROM feeds WHERE `userid`='$userid'");
        while ($row = $result->fetch_object()) {
            $valid_feeds[$row->id] = 1;
        }

        // remove invalid feed entries
        foreach ($upload_flags as $local_id=>$upload) {
            if (!isset($valid_feeds[$local_id])) {
                $this->mysqli->query("DELETE FROM sync_feeds WHERE `userid`='$userid' AND `local_id`='$local_id'");
                unset($upload_flags[$local_id]);
            }
        }
        
        return $upload_flags;
    }
}
