#!/bin/bash
DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
if [ -f "/.dockerenv" ]; then
    php $DIR/sync_run.php
else
    sudo php $DIR/sync_run.php
fi
