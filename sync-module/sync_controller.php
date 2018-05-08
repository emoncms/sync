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
    
    if (!$session["write"]) return emoncms_error("sync module requires write access");
    
    // ----------------------------------------------------
    
    if ($route->action == "view") {
        $route->format = "html";
        return view("Modules/sync/sync_view.php",array());
    }
    
    // 1. User enters username, password and host of remote installation
    //    local emoncms fetches the remote read and write apikey and stores locally
    if ($route->action == "remove-save") {
        $route->format = "json";
        return $sync->remote_save($session["userid"],post("host"),post("username"),post("password"));
    }
    
    if ($route->action == "remote-load") {
        $route->format = "json";
        return $sync->remote_load($session["userid"]);
    }
    
    if ($route->action == "feed-list") {
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
            if (in_array($f->engine,array(Engine::PHPFINA,Engine::PHPTIMESERIES))) {
                $l = new stdClass();
                $l->exists = true;
                $l->id = (int) $f->id;
                $l->tag = $f->tag;
                $l->engine = $f->engine;
                $l->datatype = $f->datatype;
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
            if (in_array($f->engine,array(Engine::PHPFINA,Engine::PHPTIMESERIES))) {
                // Move remote meta under remote heading
                $r = new stdClass();
                $r->exists = true;
                $r->id = (int) $f->id;
                $r->tag = $f->tag;
                $r->engine = $f->engine;
                $r->datatype = $f->datatype;
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
    
    // ---------------------------------------------------------------------------------------------------
    // Download feed
    // ---------------------------------------------------------------------------------------------------
    if ($route->action == "download") {
        $route->format = "json";
        
        if (!isset($_GET['name'])) return emoncms_error("missing name parameter");
        $name = $_GET['name'];
        
        if (!isset($_GET['tag'])) return emoncms_error("missing tag parameter");
        $tag = $_GET['tag'];
        
        if (!isset($_GET['remoteid'])) return emoncms_error("missing remoteid parameter");
        $remote_id = (int) $_GET['remoteid'];
        
        if (!isset($_GET['interval'])) return emoncms_error("missing interval parameter");
        $interval = (int) $_GET['interval'];

        if (!isset($_GET['engine'])) return emoncms_error("missing engine parameter");
        $engine = (int) $_GET['engine'];
        
        if (!isset($_GET['datatype'])) return emoncms_error("missing datatype parameter");
        $datatype = (int) $_GET['datatype'];
        
        // Check that engine is supported
        if (!in_array($engine,array(Engine::PHPFINA,Engine::PHPTIMESERIES))) return emoncms_error("unsupported engine");
        
        // Create local feed entry if no feed exists of given name
        if (!$local_id = $feed->get_id($session["userid"],$name)) {
            $options = new stdClass();
            if ($engine==Engine::PHPFINA) $options->interval = $interval;
            $result = $feed->create($session['userid'],$tag,$name,$datatype,$engine,$options);
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
            "datatype"=>$datatype,
            "remote_apikey"=>$remote->apikey_write
        );
        $redis->lpush("sync-queue",json_encode($params));
        $sync->trigger_service($homedir);
        
        $result = array("success"=>true);
    }

    // ---------------------------------------------------------------------------------------------------
    // Upload feed
    // ---------------------------------------------------------------------------------------------------    
    if ($route->action == "upload") {
        $route->format = "json";

        if (!isset($_GET['name'])) return emoncms_error("missing name parameter");
        $name = $_GET['name'];
        
        if (!isset($_GET['tag'])) return emoncms_error("missing tag parameter");
        $tag = $_GET['tag'];
        
        if (!isset($_GET['localid'])) return emoncms_error("missing localid parameter");
        $local_id = (int) $_GET['remoteid'];
        
        if (!isset($_GET['interval'])) return emoncms_error("missing interval parameter");
        $interval = (int) $_GET['interval'];

        if (!isset($_GET['engine'])) return emoncms_error("missing engine parameter");
        $engine = (int) $_GET['engine'];
        
        // Check that engine is supported
        if (!in_array($engine,array(Engine::PHPFINA,Engine::PHPTIMESERIES))) return emoncms_error("unsupported engine");
        
        $remote = $sync->remote_load($session["userid"]);
        
        $remote_id = (int) file_get_contents($remote->host."/feed/getid.json?apikey=".$remote->apikey_read."&name=".$name);
        
        if (!$remote_id) {
            print "creating feed";
        
            $url = $remote->host."/feed/create.json?";
            $url .= "apikey=".$remote->apikey_write;
            $url .= "&name=".urlencode($name);
            $url .= "&tag=".urlencode($tag);
            $url .= "&datatype=".DataType::REALTIME;
            $url .= "&engine=".Engine::PHPFINA;
            $url .= "&options=".json_encode(array("interval"=>$interval));

            $result = json_decode(file_get_contents($url));
            if ($result->success) {
                $remote_id = $result->feedid;
            }
            
            print $remote_id;
        }
        usleep(100);
        
        $params = array(
            "action"=>"upload",
            "local_id"=>$local_id,
            "remote_server"=>$remote->host,
            "remote_id"=>$remote_id,
            "remote_apikey"=>$remote->apikey_write
        );
        $redis->lpush("sync-queue",json_encode($params));
            
        $sync->trigger_service($homedir);
        $result = array("success"=>true);
    }
    
    return array('content'=>$result, 'fullwidth'=>true);
}


