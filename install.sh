#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" >/dev/null 2>&1 && pwd )"

if [ -f /opt/openenergymonitor/EmonScripts/common/emoncmsdbupdate.php ]; then
    php /opt/openenergymonitor/EmonScripts/common/emoncmsdbupdate.php
fi

# 1. Create givenergy emoncms service
cat <<EOF > $DIR/emoncms_sync.service
[Unit]
Description=emoncms_sync service
StartLimitIntervalSec=10
Wants=mysql.service redis-server.service
After=mysql.service redis-server.service

[Service]
Type=simple
ExecStart=/usr/bin/php /opt/emoncms/modules/sync/sync_upload.php sel bg
User=pi
Restart=always
RestartSec=60s

# View with: sudo journalctl -f -u emoncms_sync -o cat
SyslogIdentifier=emoncms_sync

[Install]
WantedBy=multi-user.target
EOF

service=emoncms_sync
# Remove old service if exists
if [ -f /lib/systemd/system/$service.service ]; then
    echo "- reinstalling $service.service"
    sudo systemctl stop $service.service
    sudo systemctl disable $service.service
    sudo rm /lib/systemd/system/$service.service
else
    echo "- installing $service.service"
fi

sudo mv $DIR/$service.service /lib/systemd/system

sudo systemctl enable $service.service
sudo systemctl restart $service.service

state=$(systemctl show $service | grep ActiveState)
echo "- Service $state"


