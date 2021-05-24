<?php
global $session;
if ($session["write"]) {
    $menu["setup"]["l2"]['sync'] = array(
        "name"=>_("Sync"),
        "href"=>"sync",
        "default"=>"sync/view/feeds",
        "order"=>9, 
        "icon"=>"shuffle",
        
        "l3"=>array(
            "inputs"=>array(
                "name"=>_("Sync Inputs"),
                "href"=>"sync/view/inputs", 
                "order"=>1, 
                "icon"=>"input"
            ),
            "feeds"=>array(
                "name"=>_("Sync Feeds"),
                "href"=>"sync/view/feeds", 
                "order"=>2, 
                "icon"=>"format_list_bulleted"
            ),
            "dashboard"=>array(
                "name"=>_("Sync Dashboards"),
                "href"=>"sync/view/dashboards", 
                "order"=>3, 
                "icon"=>"dashboard"
            )
        )
    );
}
