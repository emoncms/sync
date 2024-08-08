<?php

    $schema['sync'] = array(
        'userid' => array('type' => 'int(11)'),
        'host' => array('type' => 'varchar(64)'),
        'username' => array('type' => 'varchar(30)'),
        'apikey_read' => array('type' => 'varchar(64)'),
        'apikey_write' => array('type' => 'varchar(64)')
    );

    // Schema for feed sync (registers feeds to upload)
    $schema['sync_feeds'] = array(
        'userid' => array('type' => 'int(11)'),
        'local_id' => array('type' => 'int(11)'),
        'upload' => array('type' => 'tinyint(1)')
    );