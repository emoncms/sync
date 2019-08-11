<?php

// setup menu item
$menu['sidebar']['emoncms'][] = array(
    'text' => _("Sync"),
    'path' => 'sync/view',
    'icon' => 'shuffle',
    'data'=> array('sidebar' => '#sync'),
    'order'=>'b4'
);

// sub menu
$menu['sidebar']['includes']['emoncms']['sync'][] = array(
    'text' => _('Sync Inputs'),
    'path' => 'sync/view/inputs',
);
$menu['sidebar']['includes']['emoncms']['sync'][] = array(
    'text' => _('Sync Feeds'),
    'path' => 'sync/view/feeds',
);
$menu['sidebar']['includes']['emoncms']['sync'][] = array(
    'text' => _('Sync Dashboards'),
    'path' => 'sync/view/dashboards',
);
