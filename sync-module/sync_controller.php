<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function sync_controller()
{
    global $homedir,$path,$session,$route,$mysqli,$redis,$user,$feed_settings;

    $result = false;

    require_once "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli,$redis,$feed_settings);
    
    include "Modules/sync/sync_model.php";
    $sync = new Sync($mysqli);
    
    if ($route->action == "1") {
        $route->format = "text";
        $result = "hello world";
    }
    
    if ($route->action == "view" && $session["write"]) {
        $route->format = "html";
        $result = view("Modules/sync/view.php",array());
    }
    
    // 1. User enters username, password and host of remote installation
    //    local emoncms fetches the remote read and write apikey and stores locally
    if ($route->action == "remove-save" && $session["write"]) {
        $route->format = "json";
        $result = $sync->remote_save($session["userid"],post("host"),post("username"),post("password"));
    }
    
    if ($route->action == "remote-load" && $session["write"]) {
        $route->format = "json";
        $result = $sync->remote_load($session["userid"]);
    }
    
    if ($route->action == "feed-list" && $session["write"]) {
        $route->format = "json";
        
        // 1. Load local feeds
        $localfeeds = json_decode(json_encode($feed->get_user_feeds_with_meta($session['userid'])));
        // 2. Load remote settings
        $remote = $sync->remote_load($session["userid"]);
        // 3. Load remote feeds
        $remotefeeds = json_decode(file_get_contents($remote->host."/feed/listwithmeta.json?apikey=".$remote->apikey_read));
        
        $feeds = array();
        
        // Load all local feeds into feed list array
        foreach ($localfeeds as $f) {
            if ($f->engine==5) {
                $l = new stdClass();
                $l->exists = true;
                $l->id = (int) $f->id;
                $l->tag = $f->tag;
                $l->start_time = $f->start_time; 
                $l->interval = $f->interval; 
                $l->npoints = $f->npoints; 
                
                // Create empty remote feed entry
                // may be overwritten in the next step
                $r = new stdClass();
                $r->exists = false;
                $r->start_time = "";
                $r->interval = "";
                $r->npoints = "";
                
                $feeds[$f->name] = new stdClass();
                $feeds[$f->name]->local = $l;
                $feeds[$f->name]->remote = $r;
            }
        }

        // Load all remote feeds into feed list array
        foreach ($remotefeeds as $f) {
            if ($f->engine==5) {
                // Move remote meta under remote heading
                $r = new stdClass();
                $r->exists = true;
                $r->id = (int) $f->id;
                $r->tag = $f->tag;
                $r->start_time = $f->start_time;
                $r->interval = $f->interval;
                $r->npoints = $f->npoints;
                
                // Only used if no local feed
                $l = new stdClass();
                $l->exists = false;
                $l->start_time = "";
                $l->interval = "";
                $l->npoints = "";
                
                if (!isset($feeds[$f->name])) {
                    $feeds[$f->name] = new stdClass();
                    $feeds[$f->name]->local = $l;
                }
                $feeds[$f->name]->remote = $r;
            }
        }        
        
        $result = $feeds;
    }
    
    if ($route->action == "download" && $session["write"]) {
        $route->format = "json";
        
        $name = $_GET['name'];
        $tag = $_GET['tag'];
        $remote_id = (int) $_GET['remoteid'];
        $interval = (int) $_GET['interval'];
        
        if (!$local_id = $feed->get_id($session["userid"],$name)) {
            $result = $feed->create($session['userid'],$tag,$name,DataType::REALTIME,Engine::PHPFINA,json_decode(json_encode(array("interval"=>$interval))));
            $local_id = $result["feedid"];
        }
        
        if ($local_id) {
            $remote = $sync->remote_load($session["userid"]);
            
            $params = array(
                "local_id"=>$local_id,
                "remote_server"=>$remote->host,
                "remote_id"=>$remote_id,
                "remote_apikey"=>$remote->apikey_write
            );
            $redis->lpush("sync-queue",json_encode($params));
            $sync->trigger_service($homedir);
            
            $result = array("success"=>true);
        } else {
            $result = array("success"=>false);
        }
        
    }
    
    if ($route->action == "upload" && $session["write"]) {
        $route->format = "json";
        
        $name = $_GET['name'];
        $tag = $_GET['tag'];
        $localid = (int) $_GET['localid'];
        $interval = (int) $_GET['interval'];
        
        $remote = $sync->remote_load($session["userid"]);
        
        $url = $remote->host."/feed/create.json?";
        $url .= "apikey=".$remote->apikey_write;
        $url .= "&name=".urlencode($name);
        $url .= "&tag=".urlencode($tag);
        $url .= "&datatype=".DataType::REALTIME;
        $url .= "&engine=".Engine::PHPFINA;
        $url .= "&options=".json_encode(array("interval"=>$interval));

        $result = file_get_contents($url);
        $sync->trigger_service($homedir);
    }
    
    return array('content'=>$result, 'fullwidth'=>true);
}


