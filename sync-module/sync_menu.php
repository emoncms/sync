<?php

// setup menu item
$menu['sidebar']['emoncms'][] = array(
    'text' => _("Sync"),
    'path' => 'sync/view',
    'icon' => 'shuffle',
    'data'=> array('sidebar' => '#sync'),
    'order'=>'9'
);

// sub menu
$menu['sidebar']['includes']['emoncms']['sync'][] = array(
    'text' => _('Inputs'),
    'path' => 'sync/view/inputs',
);
$menu['sidebar']['includes']['emoncms']['sync'][] = array(
    'text' => _('Feeds'),
    'path' => 'sync/view/feeds',
);
$menu['sidebar']['includes']['emoncms']['sync'][] = array(
    'text' => _('Dashboards'),
    'path' => 'sync/view/dashboards',
);
$menu['sidebar']['includes']['emoncms']['sync'][] = array(
    'text' => _('New'),
    'icon' => 'plus',
    'path' => 'sync/view/new',
);
