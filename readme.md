# Sync module for emoncms

Download and upload feeds between a local and remote emoncms server

![syncmodule.png](syncmodule3.png)

1. Login to your local installation of emoncms.
2. Navigate to Setup > Sync
3. Enter your emoncms.org username and password to bring up the list of available feeds.
4. Click Download all, or download feeds individually
5. Browse the feeds locally, that's it!

You may also be interested in the python based emoncms backup utility if you do not wish to setup a local emoncms instance to download/backup/archive emoncms data from a remote server such as emoncms.org: https://github.com/emoncms/usefulscripts/tree/master/backup_py

### Automatic EmonPi, Emonbase Update

The Sync module is included in the default EmonPi/EmonBase software stack as of 26th April 2018. If you do not see the sync module under the emoncms Setup tab try running EmonPi or EmonBase update from the Administration page on your EmonPi/EmonBase.

## Automatic sync 

Version 3.x.x includes a background service called emoncms_sync.service that handles automatic upload of data to a remote server at a regular interval.

### Manual Linux Installation

**Note:** The sync module has hard coded paths for the emoncms directory location that point to: /var/www/emoncms. If your installation has emoncms installed in /var/www/html/emoncms you will need a symlink to /var/www/emoncms:

    ln -s /var/www/html/emoncms /var/www/emoncms
    
The setting **$home_dir** or **$emoncms_dir** in emoncms settings.php also need to be set to reflect your system.    

Install the sync module to /opt/emoncms/modules (you may need to create those directories first):

    cd /opt/emoncms/modules
    git clone https://github.com/emoncms/sync.git
    
Run the installation script:

    ./install.sh
    
### Install Service Runner

The sync module downloads data using a script that runs in the background. This script is automatically called using the emoncms service runner. See [Emoncms: Service-runner installation details here](https://github.com/emoncms/emoncms/blob/master/scripts/services/install-service-runner-update.md).

### Troubleshooting

- Check that the $homedir or $emoncms_dir setting is set appropriately on your emoncms installation
- If your emoncms installation is in /var/www/html/emoncms, make a symlink to /var/www/emoncms as described above.
