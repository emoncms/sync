<?php
global $session;
if ($session["write"]) {
    $menu["setup"]["l2"]['sync'] = array(
        "name"=>_("Sync"),
        "href"=>"sync",
        "order"=>9,
        "icon"=>"shuffle"
    );
}
