<?php

    $domain = "messages";
    bindtextdomain($domain, "Modules/feed/locale");
    bind_textdomain_codeset($domain, 'UTF-8');

    $menu_dropdown_config[] = array('name'=> dgettext($domain, "Sync"), 'icon'=>'icon-random', 'path'=>"sync/view" , 'session'=>"write", 'order' => 31 );
