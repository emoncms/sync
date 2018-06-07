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
    private $connect_timeout = 5;
    private $total_timeout = 10;
    private $log;

    public function __construct($mysqli)
    {
        $this->mysqli = $mysqli;
        $this->log = new EmonLogger(__FILE__);
    }
    
    public function remote_load($userid)
    {
        $userid = (int) $userid;
        $result = $this->mysqli->query("SELECT * FROM sync WHERE `userid`='$userid'");
        if ($row = $result->fetch_object()) return $row;
        return array("success"=>false);
    }
    
    public function remote_save($userid,$host,$username,$password) 
    {
        $this->log->warn("remote save");
        // Input sanitisation
        $userid = (int) $userid;
        if (!$username || !$password) return array('success'=>false, 'message'=>_("Username or password empty"));
        $username_out = preg_replace('/[^\p{N}\p{L}_\s-]/u','',$username);
        if ($username_out!=$username) return array('success'=>false, 'message'=>_("Username must only contain a-z 0-9 dash and underscore"));
        $username = $this->mysqli->real_escape_string($username);        
        
        // Authentication request to target server
        $result = $this->request("POST",$host."/user/auth.json","username=$username&password=$password");
        $this->log->warn($result);
        $result = json_decode($result);
        
        
        
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
    
    private function request($method,$url,$body)
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        if ($body!=null) curl_setopt($curl, CURLOPT_POSTFIELDS,$body);
        
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT,$this->connect_timeout);
        curl_setopt($curl, CURLOPT_TIMEOUT,$this->total_timeout);
        
        $curl_response = curl_exec($curl);
        curl_close($curl);
        return $curl_response;
    }
    
    public function trigger_service($homedir) {
        $savepath = session_save_path();
        $update_flag = "$savepath/emoncms-flag-sync";
        $update_script = "$homedir/sync/emoncms-sync.sh";
        $update_logfile = "$homedir/data/emoncms-sync.log";
        
        $fh = @fopen($update_flag,"w");
        if (!$fh) {
            $result = "ERROR: Can't write the flag $update_flag.";
        } else {
            fwrite($fh,"$update_script>$update_logfile");
            $result = "Update flag set";
        }
        @fclose($fh);
    }
}
