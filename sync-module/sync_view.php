<?php global $path, $settings, $route; ?>
<script src="<?php echo $path; ?>Lib/vue.min.js"></script>

<h2>Emoncms Sync: <span id="page"></span></h2>

<p>The module can be used to download or upload data to or from a remote emoncms installation.</p>
<p>Start by entering the remote emoncms installation location in the <i>host</i> field (e.g https://emoncms.org). Then enter the <i>username</i> and <i>password</i> of the account you wish to link to.</p>
<p>Download or upload specific feeds as required.</p>

<br>
<?php if ($settings["redis"]["enabled"]) { ?>

    <div id="app">
        <div class="input-prepend input-append">
            <span class="add-on">Host</span><input v-model="remote_host" type="text">
            <span v-if="show_login">
                <span class="add-on">Username</span><input v-model="remote_username" type="text" style="width:150px">
                <span class="add-on">Password</span><input v-model="remote_password" type="text" style="width:150px">
                <button @click="remote_save" class="btn">Connect</button>
            </span>
            <span v-else>
                <span class="add-on">Apikey</span><input v-model="remote_apikey" type="text" style="width:250px" disabled>
                <button @click="remote_change" class="btn">Change</button>
            </span>
        </div>

        <div style="float:right; padding-top:10px; padding-right:20px" v-if="view=='feeds'">Next update: {{ next_update_seconds }}s</div>

        <br>
        <div class="input-prepend input-append" v-if="view=='feeds'">
            <button class="btn" @click="download_all">Download All</button>
            <button class="btn" @click="upload_all">Upload All</button>
        </div>

        <div class="alert alert-info" v-if="alert">{{ alert }}</div>

        <table class="table" v-if="view=='feeds'">
            <tr>
                <th>Location</th>
                <th>Feed Name</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <template v-for="(feeds, tag) in feeds_by_tag">
                <tr style="background-color: #eee">
                    <td colspan="6"><b>{{ tag }}</b></td>
                </tr>
                <tr v-for="(feed, tagname) in feeds" v-bind:class="feed.class">
                    <td>{{ feed.location }}</td>
                    <td :title="`Start time: ${toDate(feed.local.start_time)}\nInterval: ${interval_format(feed.local.interval)}s`">{{ feed.local.name }}</td>  

                    <td>{{ feed.status }}</td>
                    <td>
                        <button class="btn btn-small" @click="download_feed(tagname)" v-if="feed.button=='Download'"><i class='icon-arrow-left'></i> Download</button>
                        <button class="btn btn-small" @click="upload_feed(tagname)" v-if="feed.button=='Upload'"><i class='icon-arrow-right'></i> Upload</button>
                    </td>
                </tr>
                <!-- spacing -->
                <tr><td colspan="6"></td></tr>

            </template>
        </table>


        <div v-if="view=='inputs'">
            <p>Download remote emoncms inputs <button @click="download_inputs" class="btn">Download</button></p>
            <pre v-if="input_log">{{ input_log }}</pre>
        </div>

        <div v-if="view=='dashboards'">
            <p>Download remote emoncms dashboards <button @click="download_dashboards" class="btn">Download</button></p>
            <pre v-if="dashboard_log">{{ dashboard_log }}</pre>
        </div>
    </div>

<?php } else { ?>

    <div class="alert alert-warning"><b>Error:</b> Redis is not installed or enabled. Please ensure that redis is installed on your system and then enabled in settings.php.</div>

<?php } ?>

<script>
    var subaction = "<?php echo $route->subaction; ?>";
    if (!subaction || subaction == "") subaction = "feeds";

    var redis_enabled = <?php echo $settings["redis"]["enabled"]; ?>;
    var path = "<?php echo $path; ?>";

    var feeds_to_upload = [];
    var feeds_to_download = [];
    var feed_list_refresh_interval = false;

    var app = new Vue({
        el: '#app',
        data: {
            // Authentication
            remote_host: "https://emoncms.org",
            remote_username: "",
            remote_password: "",
            remote_apikey: "",
            show_login: false,

            view: 'feeds',
            feeds: {},
            feeds_by_tag: {},
            next_update_seconds: 0,
            alert: "Connecting to remote emoncms server...",

            dashboard_log: "",
            input_log: ""
        },
        methods: {
            // ---------------------
            // Remote Auth
            // ---------------------
            remote_save: function() {
                var host = this.remote_host;
                var username = this.remote_username;
                var password = encodeURIComponent(this.remote_password);

                $(".feed-view").hide();
                app.alert = "Connecting to remote emoncms server...";

                clearInterval(feed_list_refresh_interval);

                $.ajax({
                    type: "POST",
                    url: path + "sync/remote-save",
                    data: "host=" + host + "&username=" + username + "&password=" + password,
                    dataType: 'json',
                    async: true,
                    success(result) {
                        if (result.success) {
                            //remote = result;
                            // feed list scan
                            remoteLoad();
                        } else {
                            alert(result.message);
                        }
                    }
                });
            },

            remote_change: function() {
                this.show_login = true;
            },

            // ---------------------
            // Download feeds
            // ---------------------  
            download_all: function() {
                feeds_to_download.forEach(function(tagname) {
                    app.download_feed(tagname);
                });
            },
            download_feed: function(tagname) {
                app.feeds[tagname].status = "Downloading...";
                let f = app.feeds[tagname].remote;
                var request = "name=" + f.name + "&tag=" + f.tag + "&remoteid=" + f.id + "&interval=" + f.interval + "&engine=" + f.engine;
                $.ajax({
                    url: path + "sync/download",
                    data: request,
                    dataType: 'json',
                    async: true,
                    success(result) {
                        if (result.success) {
                            // success
                        } else {
                            alert(result.message);
                            app.feeds[tagname].status = "";

                        }
                    }
                });
            },
            // ---------------------
            // Upload feeds
            // ---------------------
            upload_all: function() {
                feeds_to_upload.forEach(function(tagname) {
                    app.upload_feed(tagname);
                });
            },
            upload_feed: function(tagname) {
                app.feeds[tagname].status = "Uploading...";
                let f = app.feeds[tagname].local;
                var request = "name=" + f.name + "&tag=" + f.tag + "&localid=" + f.id + "&interval=" + f.interval + "&engine=" + f.engine;
                $.ajax({
                    url: path + "sync/upload",
                    data: request,
                    dataType: 'json',
                    async: true,
                    success(result) {
                        if (result.success) {
                            // success
                        } else {
                            alert(result.message);
                            app.feeds[tagname].status = "";
                        }
                    }
                });
            },
            toDate: function(value) {
                if (!value) return '';
                var a = new Date(value * 1000);
                var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                var year = a.getFullYear();
                var month = months[a.getMonth()];
                var date = a.getDate();
                var hour = a.getHours();
                if (hour < 10) hour = "0" + hour;
                var min = a.getMinutes();
                if (min < 10) min = "0" + min;
                var sec = a.getSeconds();
                if (sec < 10) sec = "0" + sec;
                return date + ' ' + month + ' ' + year + ', ' + hour + ':' + min + ':' + sec;
            },
            interval_format: function(value) {
                // if whole number
                if (value % 1 == 0) {
                    return value;
                } else {
                    // if more than 20 round to 0 dp
                    if (value > 20) {
                        return value.toFixed(0);
                    } else {
                        return value.toFixed(1);
                    }
                }
            },

            // ---------------------
            // Dashboards
            // ---------------------
            download_dashboards: function() {
                $.ajax({
                    url: path + "sync/download-dashboards",
                    dataType: 'text',
                    async: true,
                    success(result) {
                        app.dashboard_log = result;
                    }
                });
            },

            // ---------------------
            // Inputs
            // ---------------------
            download_inputs: function() {
                $.ajax({
                    url: path + "sync/download-inputs",
                    dataType: 'text',
                    async: true,
                    success(result) {
                        app.input_log = result;
                    }
                });
            }
        }
    });

    function process_feed_list(result) {

        feeds_to_upload = [];
        feeds_to_download = [];

        for (var tagname in result) {
            result[tagname].status = "";
            result[tagname].class = "";
            result[tagname].location = "";
            result[tagname].button = "";

            // Add location to feeds
            if (result[tagname].local.exists && result[tagname].remote.exists) {
                result[tagname].location = "Both";

                if (result[tagname].local.start_time == result[tagname].remote.start_time) {

                    if (result[tagname].local.engine == 5 && result[tagname].local.interval != result[tagname].remote.interval) continue;

                    if (result[tagname].local.npoints > result[tagname].remote.npoints) {
                        result[tagname].status = "Local ahead of Remote by " + (result[tagname].local.npoints - result[tagname].remote.npoints) + " points";
                        result[tagname].class = "info";
                        result[tagname].button = "Upload";
                        feeds_to_upload.push(tagname);

                    } else if (result[tagname].local.npoints < result[tagname].remote.npoints) {
                        result[tagname].status = "Local behind Remote by " + (result[tagname].remote.npoints - result[tagname].local.npoints) + " points";
                        result[tagname].class = "warning";
                        result[tagname].button = "Download";
                        feeds_to_download.push(tagname);

                    } else {
                        result[tagname].status = "Local and Remote are the same";
                        result[tagname].class = "success";
                    }
                }

            } else if (result[tagname].remote.exists) {
                result[tagname].location = "Remote";
                result[tagname].button = "Download";
                feeds_to_download.push(tagname);

            } else {
                result[tagname].location = "Local";
                result[tagname].button = "Upload";
                feeds_to_upload.push(tagname);

            }

        }

        return result;
    }


    //interrogate the API and update the list
    //update the gloabl variable new_update
    //update the global variable feeds
    function syncList() {
        app.next_update_seconds = 10;
        // app.alert = "Connected. Fetching emoncms feeds...";

        $.ajax({
            url: path + "sync/feed-list",
            dataType: 'json',
            async: true,
            success(result) {
                if (result.success != undefined) {
                    app.alert = result.message;
                    return false;
                }

                app.feeds = process_feed_list(result);

                // Arrange feeds by tag
                var feeds_by_tag = {};
                for (var tagname in app.feeds) {
                    var tag = app.feeds[tagname].local.tag;
                    if (feeds_by_tag[tag] == undefined) feeds_by_tag[tag] = {};
                    feeds_by_tag[tag][tagname] = app.feeds[tagname];
                }

                app.feeds_by_tag = feeds_by_tag;


                app.alert = false;
            }
        });
    }

    // interrogate the API and Load all the remote details
    function remoteLoad() {
        $.ajax({
            url: path + "sync/remote-load",
            dataType: 'json',
            async: true,
            success(result) {
                if (result.success != undefined && !result.success) {
                    remote = false;
                    app.alert = false;
                } else {
                    //remote=result;
                    app.alert = false;
                    app.remote_host = result.host;
                    app.remote_username = result.username;
                    app.remote_apikey = result.apikey_write;

                    if (subaction == "feeds") {
                        app.view = "feeds";
                        syncList();

                        clearInterval(feed_list_refresh_interval);
                        feed_list_refresh_interval = setInterval(syncList, 10000);
                    }
                    if (subaction == "inputs") {
                        app.view = "inputs";
                    }
                    if (subaction == "dashboards") {
                        app.view = "dashboards";
                    }

                    if (result.username == undefined && result.apikey_write != "") {
                        app.show_login = false;
                    } else {
                        app.show_login = true;
                    }
                }
            },
            error(xhr) {
                var errorMessage = xhr.status + ": " + xhr.statusText;
                alert("Error - " + errorMessage);
            }
        });
    }

    $("#page").html(subaction.charAt(0).toUpperCase() + subaction.slice(1));

    if (redis_enabled) {
        app.alert = "Connecting to remote emoncms server...";
        remoteLoad();
    }

    setInterval(ticker, 1000);

    function ticker() {
        app.next_update_seconds --;
    }
</script>