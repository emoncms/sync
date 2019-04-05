<?php

$menu['sidebar']['setup'][] = array(
    'text' => _("Sync"),
    'path' => 'sync/view',
    'icon' => 'shuffle',
    'data'=> array('sidebar' => '#sidebar_sync') // selector for the sidebar menu to open on click
);

$menu['sidebar']['includes']['setup']['sync'][] = array(
    'text' => _("Inputs"),
    'path' => 'sync/view/inputs'
);
$menu['sidebar']['includes']['setup']['sync'][] = array(
    'text' => _("Feeds"),
    'path' => 'sync/view/feeds'
);
$menu['sidebar']['includes']['setup']['sync'][] = array(
    'text' => _("Dashboards"),
    'path' => 'sync/view/dashboards'
);