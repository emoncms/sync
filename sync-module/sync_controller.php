<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function sync_controller()
{
    global $linked_modules_dir,$path,$session,$route,$mysqli,$redis,$user,$settings;

    $result = '#UNDEFINED#';

    require_once "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli,$redis,$settings["feed"]);

    include "Modules/sync/sync_model.php";
    $sync = new Sync($mysqli,$feed);
    
    if (!$session["write"]) return emoncms_error("sync module requires write access");
    
    // ----------------------------------------------------
    
    if ($route->action == "view" || $route->action == "") {
        $route->format = "html";
        return view("Modules/sync/sync_view.php",array('version'=>1));
    }
    
    // 1. User enters username, password and host of remote installation
    //    local emoncms fetches the remote read and write apikey and stores locally
    if ($route->action == "remote-save") {
        $route->format = "json";

        // Apikey authentication
        if (isset($_POST['host']) && isset($_POST['write_apikey'])) {
            
            $result = $sync->remote_save_apikey($session["userid"],post("host"),post("write_apikey"));
            $redis->set("emoncms_sync:reload",1);
            return $result;
        }

        // Username and password authentication
        if (isset($_POST['host']) && isset($_POST['username']) && isset($_POST['password'])) {
            $password = post("password");
            $_password = urldecode($password);
            if ($password=="") return array("success"=>false,"message"=>"Password cannot be empty");
            $result = $sync->remote_save($session["userid"],post("host"),post("username"),$_password);
            $redis->set("emoncms_sync:reload",1);
            return $result;
        }

        return array("success"=>false,"message"=>"Invalid request");
    }

    // Save remote upload_interval
    if ($route->action == "save-upload-interval") {
        $route->format = "json";
        if (isset($_GET['interval'])) {
            $interval = (int) get("interval");
            $result = $sync->remote_save_upload_interval($session["userid"],$interval);
            $redis->set("emoncms_sync:reload",1);
            return $result;
        }
        return array("success"=>false,"message"=>"Invalid request");
    }

    // Save remote upload size, 1000000 = 1MB, 100000 = 100KB
    if ($route->action == "save-upload-size") {
        $route->format = "json";
        if (isset($_GET['size'])) {
            $size = (int) get("size");
            $result = $sync->remote_save_upload_size($session["userid"],$size);
            $redis->set("emoncms_sync:reload",1);
            return $result;
        }
        return array("success"=>false,"message"=>"Invalid request");
    }
    
    if ($route->action == "remote-load") {
        $route->format = "json";
        return $sync->remote_load($session["userid"]);
    }
    
    if ($route->action == "feed-list") {
        $route->format = "json";
        return $sync->get_feed_list($session["userid"]);
    }
    
    // ---------------------------------------------------------------------------------------------------
    // Download feed
    // ---------------------------------------------------------------------------------------------------
    if ($route->action == "download") {
        $route->format = "json";
        
        if (!isset($_GET['name'])) return emoncms_error("missing name parameter");
        $name = preg_replace('/[^\p{N}\p{L}_\s\-:]/u', '', $_GET['name']);
        if ($name !== $_GET['name']) return emoncms_error("invalid characters in feed name");
        
        if (!isset($_GET['tag'])) return emoncms_error("missing tag parameter");
        $tag = preg_replace('/[^\p{N}\p{L}_\s\-:]/u', '', $_GET['tag']);
        if ($tag !== $_GET['tag']) return emoncms_error("invalid characters in feed tag");
        
        if (!isset($_GET['remoteid'])) return emoncms_error("missing remoteid parameter");
        $remote_id = (int) $_GET['remoteid'];
        
        if (!isset($_GET['interval'])) return emoncms_error("missing interval parameter");
        $interval = (int) $_GET['interval'];

        if (!isset($_GET['engine'])) return emoncms_error("missing engine parameter");
        $engine = (int) $_GET['engine'];
        
        // Check that engine is supported
        if (!in_array($engine,array(Engine::PHPFINA,Engine::PHPTIMESERIES))) return emoncms_error("unsupported engine");
        
        // Create local feed entry if no feed exists of given name
        if (!$local_id = $feed->exists_tag_name($session["userid"],$tag,$name)) {
            $options = new stdClass();
            if ($engine==Engine::PHPFINA) $options->interval = $interval;
            $result = $feed->create($session['userid'],$tag,$name,$engine,$options);
            $local_id = $result["feedid"];
        }
        
        if (!$local_id) return emoncms_error("invalid local id");
        
        $remote = $sync->remote_load($session["userid"]);
        
        $params = array(
            "action"=>"download",
            "local_id"=>$local_id,
            "remote_server"=>$remote->host,
            "remote_id"=>$remote_id,
            "engine"=>$engine,
            "remote_apikey"=>$remote->apikey_write
        );
        $redis->lpush("sync-queue",json_encode($params));
        
        $redis->rpush("service-runner", json_encode(["run" => "sync-run", "args" => [], "log" => "sync"]));
        
        $result = array("success"=>true);
    }

    // ---------------------------------------------------------------------------------------------------
    // Upload feed
    // ---------------------------------------------------------------------------------------------------    
    if ($route->action == "upload") {
        $route->format = "json";
        
        if (!isset($_GET['localid'])) return emoncms_error("missing localid parameter");
        $local_id = (int) $_GET['localid'];
        
        $upload = 1;
        if (isset($_GET['upload'])) $upload = (int) $_GET['upload'];
        
        $sync->set_upload_flag($session["userid"],$local_id,$upload);
        $redis->set("emoncms_sync:reload",1);

        $result = array("success"=>true);
    }

    // Fetch service last upload length and time
    if ($route->action == "service-status") {
        $route->format = "json";
        $time = $redis->get("emoncms_sync:time");
        $length = $redis->get("emoncms_sync:len");

        if ($time && $length) {
            $time_desc = "";
            // Convert to seconds, minutes, hours, days ago
            $elapsed = time()-$time;
            if ($elapsed<60) {
                $time_desc = $elapsed." seconds ago";
            } else if ($elapsed<3600) {
                $time_desc = round($elapsed/60)." minutes ago";
                if (round($elapsed/60)==1) $time_desc = "1 minute ago";
            } else if ($elapsed<86400) {
                $time_desc = round($elapsed/3600)." hours ago";
            } else {
                $time_desc = round($elapsed/86400)." days ago";
            }

            return array("success"=>true, "time"=>(int) $time, "time_desc"=>$time_desc, "length"=>(int) $length);
        } else {
            return array("success"=>false);
        }
    }
    
    return array('content'=>$result);
}


