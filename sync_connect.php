<?php
require_once "/var/www/emoncms/Lib/load_emoncms.php";

require_once "Modules/feed/feed_model.php";
$feed = new Feed($mysqli,$redis,$settings["feed"]);

require_once "Modules/input/input_model.php";
$input = new Input($mysqli,$redis, $feed);
    
include "Modules/sync/sync_model.php";
$sync = new Sync($mysqli,$feed);

// Ask the cli user for the userid, default = 1
fwrite(STDOUT, "Enter the userid (default = 1): ");
$userid = trim(fgets(STDIN));
if ($userid=="") $userid = 1;

// Ask the cli user for the host (default = https://emoncms.org)
fwrite(STDOUT, "Enter the host (default = https://emoncms.org): ");
$host = trim(fgets(STDIN));
if ($host=="") $host = "https://emoncms.org";

// Ask the cli user for the username
fwrite(STDOUT, "Enter the username: ");
$username = trim(fgets(STDIN));

// Ask the cli user for the password
fwrite(STDOUT, "Enter the password: ");
$password = trim(fgets(STDIN));

// Print the values
print "userid: $userid\n";
print "host: $host\n";
print "username: $username\n";
print "password: $password\n";

// Ask the cli user for confirmation
fwrite(STDOUT, "Do you want to save these values? (y/n): ");
$confirmation = trim(fgets(STDIN));
if ($confirmation!="y") {
    print "Exiting\n";
    exit;
}

$result = $sync->remote_save($userid,$host,$username,$password);

print json_encode($result)."\n";