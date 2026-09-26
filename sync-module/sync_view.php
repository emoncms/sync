<?php global $path, $settings; ?>
<?php
    load_js("Lib/js/vue.global.prod-3.5.22.min.js");
    load_css("Modules/feed/Views/feed_view.css");
    load_css("Modules/sync/sync_view.css");
?>

<div class="sync-page">
<div class="page-header">
    <h3>Sync</h3>
</div>
<p class="page-lead">Upload local feeds to a remote emoncms server, or download remote feeds to this one.</p>

<?php if ($settings["redis"]["enabled"]) { ?>

<div id="app" v-cloak>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="panel h-100 mb-0">
                <div class="panel-header panel-header-static">
                    <span class="panel-accent"></span>
                    <span class="panel-name">Remote server</span>
                    <span class="panel-badge" v-if="connected">Linked</span>
                </div>
                <div class="panel-row">
                    <div class="row-key">Sign in with</div>
                    <div class="row-value sync-radios">
                        <label><input type="radio" :value="0" v-model="auth_with_apikey"> Username and password</label>
                        <label><input type="radio" :value="1" v-model="auth_with_apikey"> Write apikey</label>
                    </div>
                </div>
                <div class="panel-row">
                    <div class="row-key">Host</div>
                    <div class="row-value"><input v-model="remote_host" type="text" class="form-control input-285" placeholder="https://emoncms.org"></div>
                </div>
                <template v-if="!auth_with_apikey">
                    <div class="panel-row">
                        <div class="row-key">Username</div>
                        <div class="row-value"><input v-model="remote_username" type="text" class="form-control input-285"></div>
                    </div>
                    <div class="panel-row">
                        <div class="row-key">Password</div>
                        <div class="row-value"><input v-model="remote_password" type="password" class="form-control input-285" @keyup.enter="remote_save"></div>
                    </div>
                </template>
                <div class="panel-row" v-else>
                    <div class="row-key">Write apikey</div>
                    <div class="row-value"><input v-model="remote_apikey" type="text" class="form-control input-285 sync-apikey" @keyup.enter="remote_save"></div>
                </div>
                <div class="panel-row">
                    <div class="row-key"></div>
                    <div class="row-value"><button @click="remote_save" class="btn btn-primary btn-sm">Connect</button></div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="panel h-100 mb-0">
                <div class="panel-header panel-header-static">
                    <span class="panel-accent"></span>
                    <span class="panel-name">Upload service</span>
                </div>
                <div class="panel-row">
                    <div class="row-key">Service</div>
                    <div class="row-value">
                        <span class="badge bg-success" v-if="service_running">Running</span>
                        <template v-else>
                            <span class="badge bg-danger">Not running</span>
                            <span class="row-note">Start the <code>emoncms_sync</code> service to upload feeds.</span>
                        </template>
                    </div>
                </div>
                <div class="panel-row">
                    <div class="row-key">Last upload</div>
                    <div class="row-value">
                        <span v-if="last_upload_time_desc">{{ last_upload_time_desc }} ({{ size_format(last_upload_length) }})</span>
                        <span class="text-muted" v-else>None</span>
                    </div>
                </div>
                <div class="panel-row">
                    <div class="row-key">Sync interval</div>
                    <div class="row-value">
                        <select class="form-select input-165" v-model="upload_interval" @change="save_upload_interval">
                            <option :value="300">5 mins</option>
                            <option :value="600">10 mins</option>
                            <option :value="900">15 mins</option>
                            <option :value="1800">30 mins</option>
                            <option :value="3600">Hourly</option>
                            <option :value="86400">Daily</option>
                        </select>
                    </div>
                </div>
                <div class="panel-row">
                    <div class="row-key">Upload size</div>
                    <div class="row-value">
                        <select class="form-select input-165" v-model="upload_size" @change="save_upload_size">
                            <option :value="100000">100 kB</option>
                            <option :value="1000000">1 MB</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info" v-if="alert">{{ alert }}</div>

    <template v-if="Object.keys(feeds_by_tag).length">
    <div class="sync-controls-sentinel"></div>
    <div class="controls sync-controls">
        <button class="btn btn-default" :title="all_expanded ? 'Collapse' : 'Expand'" @click="expand_all"><i :class="all_expanded ? 'svg-icon-minimize-2' : 'svg-icon-expand'"></i></button>
        <button class="btn btn-default" :title="all_selected ? 'Unselect all' : 'Select all'" @click="all_selected ? unselect_all() : select_all()">
            <i :class="all_selected ? 'svg-icon-ban-circle' : 'svg-icon-check'"></i> <span>{{ selected_count }}</span>
        </button>
        <button class="btn btn-default" v-if="show_upload_selected" @click="upload_selected"><i class="svg-icon-upload"></i> Upload</button>
        <button class="btn btn-default" v-if="show_stop_upload_selected" @click="stop_upload_selected">Stop upload</button>
        <button class="btn btn-default" v-if="available_to_download_count" @click="download_all"><i class="svg-icon-download"></i> Download all ({{ available_to_download_count }})</button>
        <div class="sync-controls-right">
            <span class="sync-next">Next update {{ next_update_seconds }}s</span>
            <input type="text" class="form-control input-220" v-model="filter_text" placeholder="Filter feeds">
        </div>
    </div>

    <div class="group-list sync-list">
        <div class="sync-list-head">
            <div></div><div>Name</div><div>Location</div><div>Engine</div><div class="text-end">Size</div><div>Status</div><div class="text-center">Upload</div><div></div>
        </div>
        <template v-for="(feeds, tag) in filtered_tags" :key="tag">
            <div class="group-list-group">
                <div class="group-list-header" :class="{'collapsed': !expandedTags[tag]}" @click="toggleTag(tag)">
                    <div class="group-list-cell text-center" data-col="select"><span class="group-list-chevron"></span></div>
                    <div class="group-list-cell group-list-name">{{ tag }}</div>
                    <div class="group-list-cell"></div>
                    <div class="group-list-cell"></div>
                    <div class="group-list-cell text-end">{{ size_format(tag_size(feeds)) }}</div>
                    <div class="group-list-cell"></div>
                    <div class="group-list-cell"></div>
                    <div class="group-list-cell"></div>
                </div>
                <div class="group-list-rows" :class="{'is-expanded': expandedTags[tag]}">
                    <div class="group-list-rows-inner">
                        <div v-for="(feed, tagname) in feeds" :key="tagname" class="group-list-row" :class="['sync-' + feed.state, {'selected': selected[tagname]}]" @click="toggle_select(tagname)">
                            <div class="group-list-cell text-center" @click.stop><input type="checkbox" v-model="selected[tagname]" @change="select_change"></div>
                            <div class="group-list-cell" :title="`Start time: ${toDate(feed.local.start_time)}\nInterval: ${interval_format(feed.local.interval)}s`">{{ feed.local.name }}</div>
                            <div class="group-list-cell text-muted">{{ feed.location }}</div>
                            <div class="group-list-cell" v-html="engine_badge(feed.local.id ? feed.local : feed.remote)"></div>
                            <div class="group-list-cell text-end text-muted">{{ size_format(feed.button == 'Download' ? feed.remote.size : feed.local.size) }}</div>
                            <div class="group-list-cell sync-status">{{ feed.status }}</div>
                            <div class="group-list-cell text-center" @click.stop>
                                <div class="form-check form-switch" v-if="feed.local.exists" :title="feed.upload ? 'Uploading, click to stop' : 'Upload to remote'">
                                    <input class="form-check-input" type="checkbox" role="switch" :checked="feed.upload" :disabled="feed.button == 'Download'" @change="toggle_upload(tagname)">
                                </div>
                            </div>
                            <div class="group-list-cell text-end" @click.stop>
                                <button class="btn btn-default btn-sm" @click="download_feed(tagname)" v-if="feed.button == 'Download'"><i class="svg-icon-download"></i> Download</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sync-list-gap"></div>
        </template>
    </div>

    <button class="btn btn-default btn-sm" @click="refresh_feed_size"><i class="svg-icon-refresh-cw"></i> <?php echo _('Refresh feed size'); ?></button>
    </template>
</div>

<?php } else { ?>

<div class="alert alert-warning"><b>Error:</b> Redis is not installed or enabled. Please ensure that redis is installed on your system and then enabled in settings.php.</div>

<?php } ?>
</div>

<script>
    var redis_enabled = <?php echo $settings["redis"]["enabled"] ? 1 : 0; ?>;
    var path = "<?php echo $path; ?>";

    var feeds_to_upload = [];
    var feeds_to_download = [];
    var feed_list_refresh_interval = false;

    var app = Vue.createApp({
        data() {
            return {
                upload_interval: 300,

                // Authentication
                auth_with_apikey: 0,
                remote_host: "https://emoncms.org",
                remote_username: "",
                remote_password: "",
                remote_apikey: "",
                connected: false,

                feeds: {},
                feeds_by_tag: {},
                selected: {},
                expandedTags: {},
                filter_text: "",
                next_update_seconds: 0,
                alert: "Connecting to remote emoncms server...",

                service_running: false,
                last_upload_time: "",
                last_upload_time_desc: "",
                last_upload_length: "",

                show_upload_selected: false,
                show_stop_upload_selected: false,

                available_to_download_count: 0,

                upload_size: 1000000
            };
        },
        computed: {
            filtered_tags() {
                var f = this.filter_text.trim().toLowerCase();
                if (!f) return this.feeds_by_tag;
                var out = {};
                for (var tag in this.feeds_by_tag) {
                    for (var tagname in this.feeds_by_tag[tag]) {
                        if (tagname.toLowerCase().indexOf(f) == -1) continue;
                        if (out[tag] == undefined) out[tag] = {};
                        out[tag][tagname] = this.feeds_by_tag[tag][tagname];
                    }
                }
                return out;
            },
            selected_count() {
                return Object.values(this.selected).filter(Boolean).length;
            },
            all_selected() {
                var n = Object.keys(this.feeds).length;
                return n > 0 && this.selected_count == n;
            },
            all_expanded() {
                return Object.values(this.expandedTags).every(Boolean);
            }
        },
        methods: {
            toggleTag(tag) {
                this.expandedTags[tag] = !this.expandedTags[tag];
            },
            expand_all() {
                var state = !this.all_expanded;
                for (var tag in this.expandedTags) this.expandedTags[tag] = state;
            },
            toggle_select(tagname) {
                this.selected[tagname] = !this.selected[tagname];
                this.prepare_selected();
            },
            tag_size(feeds) {
                var size = 0;
                for (var tagname in feeds) {
                    var f = feeds[tagname];
                    size += 1 * (f.button == 'Download' ? f.remote.size : f.local.size) || 0;
                }
                return size;
            },
            engine_badge(f) {
                if (f.engine == 5) return '<span class="engine-badge engine-fixed">FIXED<span class="interval-sep"></span><span class="interval-tag">' + f.interval + 's</span></span>';
                if (f.engine == 2) return '<span class="engine-badge engine-variable">VARIABLE</span>';
                return '';
            },
            // ---------------------
            // Remote Auth
            // ---------------------
            remote_save: function() {
                app.alert = "Connecting to remote emoncms server...";

                clearInterval(feed_list_refresh_interval);

                var params = {};
                if (app.auth_with_apikey) {
                    params = {
                        host: this.remote_host,
                        write_apikey: this.remote_apikey
                    };
                } else {
                    params = {
                        host: this.remote_host,
                        username: this.remote_username,
                        password: encodeURIComponent(this.remote_password)
                    };
                }

                $.ajax({
                    type: "POST",
                    url: path + "sync/remote-save",
                    data: params,
                    dataType: 'json',
                    async: true,
                    success(result) {
                        if (result.success) {
                            remoteLoad();
                        } else {
                            app.alert = false;
                            alert(result.message);
                        }
                    }
                });
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
                        if (!result.success) {
                            alert(result.message);
                            app.feeds[tagname].status = "";
                        }
                    }
                });
            },
            // ---------------------
            // Upload feeds
            // ---------------------
            set_upload: function(tagname) {
                $.ajax({
                    url: path + "sync/upload",
                    data: {
                        localid: app.feeds[tagname].local.id,
                        upload: app.feeds[tagname].upload*1
                    },
                    dataType: 'json',
                    async: true,
                    success(result) {
                        if (!result.success) {
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
            size_format: function(value) {
                // value is in bytes, format to kB or MB
                if (value < 1000) {
                    return value + " B";
                } else if (value < 1000000) {
                    return (value / 1000).toFixed(1) + " kB";
                } else {
                    return (value / 1000000).toFixed(1) + " MB";
                }
            },
            refresh_feed_size: function() {
                $.ajax({
                    url: path + "feed/updatesize.json",
                    dataType: 'json',
                    async: true,
                    success(result) {
                        syncList();
                        alert("Total size of used space for feeds: " + app.size_format(result));
                    }
                });
            },
            // ---------------------
            // Check emoncms_sync service status
            // ---------------------
            is_service_running: function() {
                $.ajax({
                    url: path + "admin/service/status?name=emoncms_sync",
                    async: true,
                    dataType: "json",
                    success: function(result) {
                        if (result.reauth == true) {
                            window.location.reload(true);
                        }
                        app.service_running = (result.ActiveState == "active");
                    }
                });
            },
            // Toggle upload
            toggle_upload: function(tagname) {
                if (app.feeds[tagname].button != 'Download') {
                    app.feeds[tagname].upload = !app.feeds[tagname].upload;
                    app.set_upload(tagname);
                }
            },

            // Select & unselect all
            select_all: function() {
                for (var tagname in app.feeds) {
                    app.selected[tagname] = true;
                }
                app.prepare_selected();
            },
            unselect_all: function() {
                for (var tagname in app.feeds) {
                    app.selected[tagname] = false;
                }
                app.prepare_selected();
            },
            select_change: function() {
                app.prepare_selected();
            },
            prepare_selected: function() {
                // Show upload and stop upload only when no selected feed is for download
                var download = false;
                var select_count = 0;
                for (var tagname in app.selected) {
                    if (app.selected[tagname]) {
                        if (app.feeds[tagname].button == "Download") download = true;
                        select_count++;
                    }
                }
                app.show_upload_selected = !download && select_count > 0;
                app.show_stop_upload_selected = !download && select_count > 0;
            },
            upload_selected: function() {
                for (var tagname in app.selected) {
                    if (app.selected[tagname]) {
                        app.feeds[tagname].upload = true;
                        app.set_upload(tagname);
                    }
                }
            },
            stop_upload_selected: function() {
                for (var tagname in app.selected) {
                    if (app.selected[tagname]) {
                        app.feeds[tagname].upload = false;
                        app.set_upload(tagname);
                    }
                }
            },
            save_upload_interval: function() {
                $.ajax({
                    url: path + "sync/save-upload-interval",
                    data: { interval: app.upload_interval },
                    dataType: 'json',
                    async: true,
                    success(result) {
                        if (!result.success) alert(result.message);
                    }
                });
            },

            save_upload_size: function() {
                $.ajax({
                    url: path + "sync/save-upload-size",
                    data: { size: app.upload_size },
                    dataType: 'json',
                    async: true,
                    success(result) {
                        if (!result.success) alert(result.message);
                    }
                });
            }
        }
    }).mount('#app');

    // Sets location, status, state (row colour) and button per feed
    function process_feed_list(result) {

        feeds_to_upload = [];
        feeds_to_download = [];

        for (var tagname in result) {
            let f = result[tagname];
            f.status = "";
            f.state = "none";
            f.location = "";
            f.button = "";

            if (f.local.exists && f.remote.exists) {
                f.location = "Both";

                if (f.local.start_time == f.remote.start_time) {

                    if (f.local.engine == 5 && f.local.interval != f.remote.interval) continue;

                    if (f.local.npoints > f.remote.npoints) {
                        f.status = "Ahead of remote by " + (f.local.npoints - f.remote.npoints) + " points";
                        f.state = "ahead";
                        f.button = "Upload";
                        feeds_to_upload.push(tagname);

                    } else if (f.local.npoints < f.remote.npoints) {
                        f.status = "Behind remote by " + (f.remote.npoints - f.local.npoints) + " points";
                        f.state = "behind";
                        f.button = "Download";
                        feeds_to_download.push(tagname);

                    } else {
                        f.status = "Same as remote";
                        f.state = "same";
                    }
                }

            } else if (f.remote.exists) {
                f.location = "Remote";
                f.button = "Download";
                feeds_to_download.push(tagname);

            } else {
                f.location = "Local";
                f.button = "Upload";
                feeds_to_upload.push(tagname);
            }
        }

        app.available_to_download_count = feeds_to_download.length;
        return result;
    }

    // Fetch the feed list and group it by tag
    function syncList() {
        app.next_update_seconds = 10;

        $.ajax({
            url: path + "sync/feed-list",
            dataType: 'json',
            async: true,
            success(result) {
                if (result.success != undefined) {
                    app.alert = result.message;
                    return false;
                }

                for (var tagname in result) {
                    let tag = result[tagname].local.tag;
                    if (app.expandedTags[tag] == undefined) app.expandedTags[tag] = true;
                    if (app.selected[tagname] == undefined) app.selected[tagname] = false;
                }

                app.feeds = process_feed_list(result);

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

    // Load the remote details, then the feed list
    function remoteLoad() {
        $.ajax({
            url: path + "sync/remote-load",
            dataType: 'json',
            async: true,
            success(result) {
                app.alert = false;
                if (result.success != undefined && !result.success) {
                    app.connected = false;
                    return;
                }
                app.remote_host = result.host;
                app.auth_with_apikey = result.auth_with_apikey*1;

                if (result.username != undefined) {
                    app.remote_username = result.username;
                    app.remote_password = "";
                }
                if (result.apikey_write != undefined) {
                    app.remote_apikey = result.apikey_write;
                }
                if (result.upload_interval != undefined) {
                    app.upload_interval = 1*result.upload_interval;
                }
                if (result.upload_size != undefined) {
                    app.upload_size = 1*result.upload_size;
                }
                app.connected = !!result.apikey_write;

                syncList();
                clearInterval(feed_list_refresh_interval);
                feed_list_refresh_interval = setInterval(syncList, 10000);
            },
            error(xhr) {
                var errorMessage = xhr.status + ": " + xhr.statusText;
                alert("Error - " + errorMessage);
            }
        });
    }

    // Load service last upload time and length
    function service_status() {
        $.ajax({
            url: path + "sync/service-status",
            dataType: 'json',
            async: true,
            success(result) {
                if (result.success) {
                    app.last_upload_time = result.time;
                    app.last_upload_time_desc = result.time_desc;
                    app.last_upload_length = result.length;
                }
            }
        });
    }

    // Sticky toolbar: is-sticky when it scrolls behind the top menu
    function watch_controls() {
        var sentinel = document.querySelector('.sync-controls-sentinel');
        if (!sentinel || !('IntersectionObserver' in window)) return;
        new IntersectionObserver(function(entries) {
            var controls = document.querySelector('.sync-controls');
            if (controls) controls.classList.toggle('is-sticky', !entries[0].isIntersecting);
        }, { rootMargin: '-46px 0px 0px 0px', threshold: 0 }).observe(sentinel);
    }

    if (redis_enabled) {
        remoteLoad();
        app.is_service_running();
        setInterval(app.is_service_running, 10000);

        service_status();
        setInterval(service_status, 5000);

        setInterval(function() { app.next_update_seconds--; }, 1000);

        // Toolbar is rendered once the feed list loads
        var controls_wait = setInterval(function() {
            if (document.querySelector('.sync-controls-sentinel')) {
                clearInterval(controls_wait);
                watch_controls();
            }
        }, 500);
    }
</script>
