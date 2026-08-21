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

    /**
     * Validate that a host URL is http/https and does not point to a
     * private or reserved IP range (SSRF prevention).
     */
    private function validate_host($host)
    {
        if (!preg_match('/^https?:\/\//i', $host)) {
            return false;
        }
        $parsed = parse_url($host);
        if (!$parsed || !isset($parsed['host'])) {
            return false;
        }
        $hostname = $parsed['host'];
        // Strip IPv6 brackets
        $hostname = trim($hostname, '[]');

        $ip = filter_var($hostname, FILTER_VALIDATE_IP);
        if (!$ip) {
            $ip = gethostbyname($hostname);
        }
        // Block loopback and reserved ranges only.
        // Private ranges (192.168.x.x, 10.x.x.x, 172.16-31.x.x) are allowed
        // because syncing to another Emoncms on the local network is a valid use case.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        // Explicitly block loopback (covered by NO_RES_RANGE for IPv4 but be explicit for IPv6)
        if ($ip === '127.0.0.1' || $ip === '::1' || substr($ip, 0, 4) === '127.') {
            return false;
        }
        return true;
    }
    
    public function remote_load($userid)
    {
        $userid = (int) $userid;
        $result = $this->mysqli->query("SELECT * FROM sync WHERE `userid`='$userid'");
        
        if ($row = $result->fetch_object()) {
            $row->success = true;
        
            if (isset($row->auth_with_apikey)) {
                $row->auth_with_apikey = (int) $row->auth_with_apikey;
            } else {
                $row->auth_with_apikey = 0;
            }
            
            if (!isset($row->upload_interval)) {
                $row->upload_interval = 300;
            } else {
                $row->upload_interval = (int) $row->upload_interval;
                // Minimum upload interval
                if ($row->upload_interval<300) {
                    $row->upload_interval = 300;
                }
            }
            
            return $row;
        } else {
            $row = new StdClass();
            $row->success = false;
            $row->host = "";
            $row->username = "";
            $row->apikey_read = "";
            $row->apikey_write = "";
            $row->upload_interval = 300;
            return $row;
        }
    }
    
    public function remote_save($userid,$host,$username,$password) 
    {
        $this->log->warn("remote save");
        // Input sanitisation
        $userid = (int) $userid;
        if (!$this->validate_host($host)) return array('success'=>false, 'message'=>_('Invalid or disallowed host URL'));
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
            return $this->remote_save_username_and_keys($userid,$host,$username,$result->apikey_read,$result->apikey_write,0);
        } else {
            return array("success"=>false, "message"=>"Authentication failure, username or password incorrect");
        }
    }

    // Save upload_interval
    public function remote_save_upload_interval($userid,$upload_interval) {
        $userid = (int) $userid;
        $upload_interval = (int) $upload_interval;
        if ($upload_interval<300) $upload_interval = 300;
        $this->mysqli->query("UPDATE sync SET `upload_interval`='$upload_interval' WHERE `userid`='$userid'");
        return array("success"=>true);
    }

    // Save upload size
    public function remote_save_upload_size($userid,$size) {
        $userid = (int) $userid;
        $size = (int) $size;

        // Upload size options are: 1000000 and 100000
        if ($size!=1000000 && $size!=100000) {
            return array("success"=>false, "message"=>"Invalid upload size");
        }

        $this->mysqli->query("UPDATE sync SET `upload_size`='$size' WHERE `userid`='$userid'");
        return array("success"=>true);
    }

    public function remote_save_apikey($userid,$host,$write_apikey) {
        $userid = (int) $userid;
        if (!$this->validate_host($host)) return array('success'=>false, 'message'=>_('Invalid or disallowed host URL'));

        $apikey_url = urlencode($write_apikey);

        // Ask the remote server what this apikey can do. user/session.json returns the userid and
        // whether the key grants write access, it does not expose the remote username, email
        // address or the account's other apikey.
        $result = $this->request("GET",$host."/user/session.json?apikey=$apikey_url",false);
        if (!$result['success']) {
            // A 401 is the remote server rejecting the key itself
            if (strpos($result['message'],"401")!==false) {
                return array("success"=>false, "message"=>"Authentication failure, apikey incorrect");
            }
            return array("success"=>false, "message"=>"No response from remote server: ".$result['message']);
        }
        $remote_session = json_decode($result['result']);

        if (isset($remote_session->userid) && $remote_session->userid) {
            if (!isset($remote_session->type) || $remote_session->type!="write") {
                return array("success"=>false, "message"=>"That is a read only apikey, sync requires the read & write apikey");
            }
            // The write apikey is used for every request, there is no need to hold the read apikey,
            // and the remote username is not used by sync
            return $this->remote_save_username_and_keys($userid,$host,"",$write_apikey,$write_apikey,1);
        }

        // Remote servers from before user/session.json respond 200 false to it. Fall back to
        // user/get.json, which on those servers still accepts an apikey and requires a write key.
        // This fallback can be removed once servers have had time to update.
        $result = $this->request("GET",$host."/user/get.json?apikey=$apikey_url",false);
        if (!$result['success']) {
            return array("success"=>false, "message"=>"Authentication failure, apikey incorrect");
        }
        $remote_user = json_decode($result['result']);

        if (isset($remote_user->id) && $remote_user->id) {
            return $this->remote_save_username_and_keys($userid,$host,$remote_user->username,$remote_user->apikey_read,$remote_user->apikey_write,1);
        } else {
            return array("success"=>false, "message"=>"Authentication failure, apikey incorrect");
        }
    }

    public function remote_save_username_and_keys($userid,$host,$username,$apikey_read,$apikey_write, $auth_with_apikey) {
        $userid = (int) $userid;
        $auth_with_apikey = (int) $auth_with_apikey;

        // delete al entries for this user
        // copy over upload_interval if it exists
        $result = $this->mysqli->query("SELECT * FROM sync WHERE `userid`='$userid'");
        if ($row = $result->fetch_object()) {
            $upload_interval = $row->upload_interval;
        } else {
            $upload_interval = 300;
        }

        $this->mysqli->query("DELETE FROM sync WHERE `userid`='$userid'");
        $stmt = $this->mysqli->prepare("INSERT INTO sync (`userid`,`host`,`username`,`apikey_read`,`apikey_write`,`auth_with_apikey`,`upload_interval`) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("issssii",$userid,$host,$username,$apikey_read,$apikey_write,$auth_with_apikey,$upload_interval);
        if (!$stmt->execute()) return array("success"=>false, "message"=>"Error saving remote configuration");

        return array(
            "success"=>true, 
            "userid"=>$userid, 
            "host"=>$host, 
            "username"=>$username, 
            "apikey_read"=>$apikey_read, 
            "apikey_write"=>$apikey_write, 
            'auth_with_apikey'=>$auth_with_apikey,
            'upload_interval'=>$upload_interval
        );
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
        
        // The write apikey is used throughout, apikey_read is empty when linked by apikey
        $result = $this->request("GET",$remote->host."/feed/listwithmeta.json?apikey=".$remote->apikey_write,false);
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
                    $r->size = isset($f->size) ? $f->size: '';
                 
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

            if ($error_code == CURLE_OPERATION_TIMEOUTED) {
                return array("success"=>false, "message"=>"timeout error");
            } else {
                return array("success"=>false, "message"=>$error_msg);       
            }
        }

        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

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
