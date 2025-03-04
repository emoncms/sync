<?php

// no direct access
defined('EMONCMS_EXEC') or die('Restricted access');

function sync_controller()
{
    global $linked_modules_dir,$path,$session,$route,$mysqli,$redis,$user,$settings;

    $result = '#UNDEFINED#';

    require_once "Modules/feed/feed_model.php";
    $feed = new Feed($mysqli,$redis,$settings["feed"]);

    require_once "Modules/input/input_model.php";
    $input = new Input($mysqli,$redis, $feed);
        
    include "Modules/sync/sync_model.php";
    $sync = new Sync($mysqli,$feed);
    
    if (!$session["write"]) return emoncms_error("sync module requires write access");
    
    // ----------------------------------------------------
    
    if ($route->action == "view") {
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
        $name = $_GET['name'];
        
        if (!isset($_GET['tag'])) return emoncms_error("missing tag parameter");
        $tag = $_GET['tag'];
        
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
        
        $update_script = "$linked_modules_dir/sync/emoncms-sync.sh";
        $update_logfile = $settings["log"]["location"]."/sync.log";
        $redis->rpush("service-runner","$update_script>$update_logfile");
        
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

    // ---------------------------------------------------------------------------------------------------
    // Download inputs
    // ---------------------------------------------------------------------------------------------------  
    if ($route->action == "download-inputs") {
        $route->format = "text"; $out = "";
        $remote = $sync->remote_load($session["userid"]);
        $inputs = file_get_contents($remote->host."/input/list.json?apikey=".$remote->apikey_write);
        $inputs = json_decode($inputs);
        
        // Feed re-mapping only for emoncms.org so far due to difference in input/getallprocesses API
        $process_list = false;
        $localfeeds = false;
        if (strpos($remote->host,"emoncms.org")!==false) {
            // 1. Load process list
            $process_list = json_decode(file_get_contents($remote->host."/input/getallprocesses.json?apikey=".$remote->apikey_write));
            // 2. Load remote feed list and map to feed id for easier reference
            $tmp = json_decode(file_get_contents($remote->host."/feed/listwithmeta.json?apikey=".$remote->apikey_read));
            $remotefeeds = array(); foreach ($tmp as $f) $remotefeeds[$f->id] = $f;
            // 3. Load local feed list
            $localfeeds = json_decode(json_encode($feed->get_user_feeds_with_meta($session['userid'])));
        } else {
            $out .= "Host is not emoncms.org, feed remapping works with emoncms.org at present\n";  
        }
        
        foreach ($inputs as $i)
        {
            // remap processList
            $processList = $i->processList;
            
            if ($process_list) {
                $processList = array();
                $pairs = explode(",",$i->processList);
                foreach ($pairs as $pair) {
                     $pair = explode(":",$pair);
                     if (count($pair)==2) {
                          $process_id = $pair[0];
                          $process_value = $pair[1];
                          $process_name = $process_id;
                          if (isset($process_list->$process_id)) {
                              $process_tmp = $process_list->$process_id;
                              $process_name = $process_tmp[0];
                              $process_type = $process_tmp[1];
                          
                              if ($process_type==ProcessArg::FEEDID) {
                                  $remote_feed_name = $remotefeeds[$process_value]->name;
                                  foreach ($localfeeds as $f) {
                                      if ($f->name==$remote_feed_name) {
                                          $local_feed = $f->id;
                                          $process_value = $local_feed;
                                      }
                                  }
                              }
                          } else {
                              $out .= "process does not exist $process_id\n";
                          }
                          $processList[] = implode(":",array($process_id,$process_value));
                     } else {
                         $out .= "invalid processid:arg pair\n";
                     }
                }
                $processList = implode(",",$processList);
            } else {
                $out .= "process_list empty or false\n";
            }
        
            if ($inputid = $input->exists_nodeid_name($session["userid"],$i->nodeid,$i->name)) {
                $out .= "UPDATE Input $i->nodeid:$i->name\n";
                $input->set_timevalue($inputid, $i->time, $i->value);
                $out .= "--set timevalue: $i->time,$i->value\n";
                
                $stmt = $mysqli->prepare("UPDATE input SET description=?, processList=? WHERE id=?");
                $stmt->bind_param("ssi", $i->description, $processList, $inputid);
                $stmt->execute();
                if ($redis) $redis->hset("input:$inputid",'description',$i->description);
                if ($redis) $redis->hset("input:$inputid",'processList',$processList);
                $out .= "--set description: $i->description\n";
                $out .= "--set processList: $processList\n";
            
            } else {
                $inputid = $input->create_input($session["userid"],$i->nodeid,$i->name);
                $out .= "CREATE Input $i->nodeid:$i->name\n";
                $input->set_timevalue($inputid, $i->time, $i->value);
                $out .= "--set timevalue: $i->time,$i->value\n";
                
                $stmt = $mysqli->prepare("UPDATE input SET description=?, processList=? WHERE id=?");
                $stmt->bind_param("ssi", $i->description, $processList, $inputid);
                $stmt->execute();
                if ($redis) $redis->hset("input:$inputid",'description',$i->description);
                if ($redis) $redis->hset("input:$inputid",'processList',$processList);
                $out .= "--set description: $i->description\n";
                $out .= "--set processList: $processList\n";
            }
        }
        
        return $out;
    }

    // ---------------------------------------------------------------------------------------------------
    // Download dashboards, no feed id remaps
    // ---------------------------------------------------------------------------------------------------  
    if ($route->action == "download-dashboards") {
       $route->format = "text"; $out = "";
        $remote = $sync->remote_load($session["userid"]);
        $dashboards = file_get_contents($remote->host."/dashboard/list.json?apikey=".$remote->apikey_write);
        $dashboards = json_decode($dashboards);
        
        foreach ($dashboards as $d)
        {
            $d = json_decode(file_get_contents($remote->host."/dashboard/getcontent.json?id=".$d->id."&apikey=".$remote->apikey_write));
            
            $stmt = $mysqli->prepare("SELECT id FROM dashboard WHERE userid=? AND name=?");
            $stmt->bind_param("is", $session["userid"], $d->name);
            $stmt->execute();
            $stmt->bind_result($id);
            $result = $stmt->fetch();
            $stmt->close();
        
            if ($result && $id>0) {
                $out .= "UPDATE dashboard $d->name\n";
                $out .= "--description: $d->description\n";
                $out .= "--main: $d->main\n";
                $out .= "--alias: $d->alias\n";
                $out .= "--public: $d->public\n";
                $out .= "--published: $d->published\n";
                $out .= "--showdescription: $d->showdescription\n";
                $out .= "--height: $d->height\n";
                $out .= "--backgroundcolor: $d->backgroundcolor\n";
                $out .= "--gridsize: $d->gridsize\n";
                $stmt = $mysqli->prepare("UPDATE dashboard SET userid=?, content=?, name=?, description=?, main=?, alias=?, public=?, published=?, showdescription=?, height=?, backgroundcolor=?, gridsize=? WHERE id=?");
                $stmt->bind_param("isssisiiiisii",$session["userid"], $d->content, $d->name, $d->description, $d->main, $d->alias, $d->public, $d->published, $d->showdescription, $d->height, $d->backgroundcolor, $d->gridsize,$id);
                $stmt->execute();
            } else {
                $out .= "INSERT dashboard $d->name\n";
                $out .= "--description: $d->description\n";
                $out .= "--main: $d->main\n";
                $out .= "--alias: $d->alias\n";
                $out .= "--public: $d->public\n";
                $out .= "--published: $d->published\n";
                $out .= "--showdescription: $d->showdescription\n";
                $out .= "--height: $d->height\n";
                $out .= "--backgroundcolor: $d->backgroundcolor\n";
                $out .= "--gridsize: $d->gridsize\n";
                $stmt = $mysqli->prepare("INSERT INTO dashboard (userid,content,name,description,main,alias,public,published,showdescription,height,backgroundcolor,gridsize) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param("isssisiiiisi",$session["userid"], $d->content, $d->name, $d->description, $d->main, $d->alias, $d->public, $d->published, $d->showdescription, $d->height, $d->backgroundcolor, $d->gridsize);
                $stmt->execute();
            }
        }
        
        return $out;

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


