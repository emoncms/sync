<?php

$domain = "messages";

$menu_left[] = array(
    'id'=>"sync_menu",
    'name'=>"Sync", 
    'path'=>"sync/view" , 
    'session'=>"write", 
    'order' => 0,
    'icon'=>'icon-random icon-white',
    'hideinactive'=>1
);

$menu_dropdown_config[] = array(
    'id'=>"sync_menu_config",
    'name'=> "Sync", 
    'icon'=>'icon-random',
    'path'=>"sync/view" ,
    'session'=>"write",
    'order' => 31
);
