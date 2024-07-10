<?php global $path, $settings, $route; ?>
<script src="<?php echo $path; ?>Lib/vue.min.js"></script>
<style>
    .syncprogress {
        background-color: #7bc3e2;
        border: 1px solid #29abe2;
        color: #fff;
        font-size: 10px;
        padding: 2px;
    }
</style>


<h2>Emoncms Sync: <span id="page"></span></h2>

<p>The module can be used to download or upload data to or from a remote emoncms installation.</p>
<p>Start by entering the remote emoncms installation location in the <i>host</i> field (e.g https://emoncms.org). Then enter the <i>username</i> and <i>password</i> of the account you wish to link to.</p>
<p>Download or upload specific feeds as required.</p>

<br>
<?php if ($settings["redis"]["enabled"]) { ?>

    <div id="app">
        <div class="input-prepend input-append">
            <span class="add-on">Host</span><input id="remote-host" type="text" value="https://emoncms.org">
            <span id="login_auth">
                <span class="add-on">Username</span><input id="remote-username" type="text" style="width:150px">
                <span class="add-on">Password</span><input id="remote-password" type="password" style="width:150px">
                <button id="remote-save" class="btn">Connect</button>
            </span>
            <span id="apikey_div" style="display:none">
                <span class="add-on">Apikey</span><input id="remote-apikey" type="text" style="width:250px" disabled>
                <button id="remote-change" class="btn">Change</button>
            </span>
        </div>

        <div id="time" style="float:right; padding-top:10px; padding-right:20px">Next update: {{ next_update_seconds }}s</div>

        <br>
        <div class="input-prepend input-append">
            <button class="btn" @click="download_all">Download All</button>
            <button class="btn" @click="upload_all">Upload All</button>
        </div>

        <div class="alert alert-info" v-if="alert">{{ alert }}</div>

        <table class="table" v-if="view=='feeds'">
            <tr>
                <th>Location</th>
                <th>Feed Tag</th>
                <th>Feed Name</th>
                <th>Start time</th>
                <th>Interval</th>
                <th></th>
                <th></th>
            </tr>
            <tbody id="all_feed_datas">
                <tr v-for="(feed,tagname) in feeds" v-bind:class="feed.class">
                    <td>{{ feed.location }}</td>
                    <td>{{ feed.local.tag }}</td>
                    <td>{{ feed.local.name }}</td>
                    <td>{{ feed.local.start_time | toDate }}</td>
                    <td>{{ feed.local.interval | interval_format }}s</td>
                    <td>{{ feed.status }}</td>
                    <td>
                        <button class="btn btn-small" @click="download_feed(tagname)" v-if="feed.button=='Download'"><i class='icon-arrow-left'></i> Download</button>
                        <button class="btn btn-small" @click="upload_feed(tagname)" v-if="feed.button=='Upload'"><i class='icon-arrow-right'></i> Upload</button>
                    </td>
                </tr>

            </tbody>
        </table>

        <div v-if="view=='inputs'">
            <p>Download remote emoncms inputs <button id="download-inputs" class="btn">Download</button></p>
            <pre id="input-output"></pre>
        </div>

        <div v-if="view=='dashboards'">
            <p>Download remote emoncms dashboards <button id="download-dashboards" class="btn">Download</button></p>
            <pre id="dashboard-output"></pre>
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

    var feeds = [];

    var feeds_to_upload = [];
    var feeds_to_download = [];
    var feed_list_refresh_interval = false;

    var app = new Vue({
        el: '#app',
        data: {
            view: 'feeds',
            feeds: {},
            next_update_seconds: 0,
            alert: "Connecting to remote emoncms server..."
        },
        methods: {
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
            }

        },
        filters: {
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
        app.alert = "Connected. Fetching emoncms feeds...";

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
                    $("#remote-host").val(result.host);
                    $("#remote-username").val(result.username);
                    $("#remote-apikey").val(result.apikey_write);
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
                        $("#login_auth").hide();
                        $("#apikey_div").show();
                    } else {
                        $("#login_auth").show();
                        $("#apikey_div").hide();
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

    $("#remote-save").click(function() {
        var host = $("#remote-host").val();
        var username = $("#remote-username").val();
        var password = encodeURIComponent($("#remote-password").val());

        $(".feed-view").hide();
        app.alert = "Connecting to remote emoncms server...";

        clearInterval(feed_list_refresh_interval);

        $.ajax({
            type: "POST",
            url: path + "sync/remove-save",
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
    });

    $("#select-all").click(function() {
        $(".feed-select-checkbox").each(function() {
            $(this)[0].checked = true;
        });
    });

    $("#select-none").click(function() {
        $(".feed-select-checkbox").each(function() {
            $(this)[0].checked = false;
        });
    });

    $("#remote-change").click(function() {
        $("#login_auth").show();
        $("#apikey_div").hide();
    });

    $("#download-inputs").click(function() {
        $.ajax({
            url: path + "sync/download-inputs",
            dataType: 'text',
            async: true,
            success(result) {
                $("#input-output").html(result);
            }
        });
    });

    $("#download-dashboards").click(function() {
        $.ajax({
            url: path + "sync/download-dashboards",
            dataType: 'text',
            async: true,
            success(result) {
                $("#dashboard-output").html(result);
            }
        });
    });

    setInterval(ticker, 1000);

    function ticker() {
        app.next_update_seconds --;
    }
</script>