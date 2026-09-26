<?php global $path, $settings, $route; ?>
<?php
    load_js("Lib/js/vue.global.prod-3.5.22.min.js");
    load_css("Modules/feed/Views/feed_view.css");
    load_css("Modules/sync/sync_view.css");
    $tab = ($route->subaction == "download") ? "download" : "upload";
?>

<div class="sync-page">
<div class="page-header">
    <h3>Sync</h3>
</div>
<p class="page-lead">Upload feeds from this device to a remote emoncms server, or download feeds from it.</p>

<?php if ($settings["redis"]["enabled"]) { ?>

<div id="app" v-cloak>

    <ul class="nav nav-tabs sync-tabs">
        <li class="nav-item"><a class="nav-link" :class="{active: tab == 'upload'}" href="<?php echo $path; ?>sync" @click.prevent="set_tab('upload')">Upload</a></li>
        <li class="nav-item"><a class="nav-link" :class="{active: tab == 'download'}" href="<?php echo $path; ?>sync/view/download" @click.prevent="set_tab('download')">Download</a></li>
    </ul>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="panel h-100 mb-0">
                <div class="panel-header panel-header-static">
                    <span class="panel-accent"></span>
                    <span class="panel-name">Remote server</span>
                    <span class="panel-badge" v-if="connected">Linked</span>
                </div>
                <template v-if="connected && !edit_remote">
                    <div class="panel-row">
                        <div class="row-key">Host</div>
                        <div class="row-value">{{ remote_host }}</div>
                        <button class="btn btn-default btn-sm" @click="edit_remote = true">Change</button>
                    </div>
                    <div class="panel-row">
                        <div class="row-key">Signed in</div>
                        <div class="row-value">{{ linked_by }}</div>
                    </div>
                    <div class="panel-row">
                        <div class="row-key">Remote feeds</div>
                        <div class="row-value">{{ summary.remote_count }} <span class="text-muted">({{ size_format(summary.remote_size) }})</span></div>
                    </div>
                </template>
                <template v-else>
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
                        <div class="row-value sync-buttons">
                            <button @click="remote_save" class="btn btn-primary btn-sm">Connect</button>
                            <button v-if="connected" @click="edit_remote = false" class="btn btn-default btn-sm">Cancel</button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="col-lg-6" v-if="tab == 'upload'">
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
                    <div class="row-key">Uploading</div>
                    <div class="row-value">{{ summary.upload_count }} of {{ summary.local_count }} feeds</div>
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

        <div class="col-lg-6" v-else>
            <div class="panel h-100 mb-0">
                <div class="panel-header panel-header-static">
                    <span class="panel-accent"></span>
                    <span class="panel-name">Download</span>
                </div>
                <div class="panel-row">
                    <div class="row-key">Not on this device</div>
                    <div class="row-value">{{ summary.missing_count }} feeds <span class="text-muted">({{ size_format(summary.missing_size) }})</span></div>
                </div>
                <div class="panel-row">
                    <div class="row-key">Behind remote</div>
                    <div class="row-value">{{ summary.behind_count }} feeds</div>
                </div>
                <div class="panel-row" v-if="summary.downloading_count">
                    <div class="row-key">Downloading</div>
                    <div class="row-value">{{ summary.downloading_count }} feeds</div>
                </div>
                <div class="panel-row">
                    <div class="row-key"></div>
                    <div class="row-value">
                        <button class="btn btn-primary btn-sm" :disabled="!all_downloadable.length" @click="download_list(all_downloadable)"><i class="svg-icon-download"></i> Download all ({{ all_downloadable.length }})</button>
                    </div>
                </div>
                <div class="panel-body sync-note">Restores or copies feed data from the remote server. Each download adds the data the local feed is missing. A feed that is not on this device is created with the same tag and name.</div>
            </div>
        </div>
    </div>

    <div class="alert alert-info" v-if="alert">{{ alert }}</div>

    <template v-if="Object.keys(feeds).length">
    <div class="sync-controls-sentinel"></div>
    <div class="controls sync-controls">
        <button class="btn btn-default" :title="all_expanded ? 'Collapse' : 'Expand'" @click="expand_all"><i :class="all_expanded ? 'svg-icon-minimize-2' : 'svg-icon-expand'"></i></button>
        <button class="btn btn-default" :title="all_selected ? 'Unselect all' : 'Select all'" @click="select_all(!all_selected)">
            <i :class="all_selected ? 'svg-icon-ban-circle' : 'svg-icon-check'"></i> <span>{{ selected_list.length }}</span>
        </button>
        <template v-if="tab == 'upload'">
            <button class="btn btn-default" v-if="selected_list.length" @click="set_upload_selected(true)"><i class="svg-icon-upload"></i> Upload</button>
            <button class="btn btn-default" v-if="selected_list.length" @click="set_upload_selected(false)">Stop upload</button>
        </template>
        <template v-else>
            <button class="btn btn-default" v-if="selected_downloadable.length" @click="download_list(selected_downloadable)"><i class="svg-icon-download"></i> Download selected ({{ selected_downloadable.length }})</button>
        </template>
        <div class="sync-controls-right">
            <span class="sync-next">Next update {{ next_update_seconds }}s</span>
            <input type="text" class="form-control input-220" v-model="filter_text" placeholder="Filter feeds">
        </div>
    </div>

    <div class="group-list sync-list" :class="'sync-list-' + tab" v-if="Object.keys(view_tags).length">
        <div class="sync-list-head">
            <div></div><div>Name</div><div>Engine</div><div class="text-end">Size</div><div>Status</div>
            <div class="text-center" v-if="tab == 'upload'">Upload</div><div v-else></div>
        </div>
        <template v-for="(feeds, tag) in view_tags" :key="tag">
            <div class="group-list-group">
                <div class="group-list-header" :class="{'collapsed': !expandedTags[tag]}" @click="toggleTag(tag)">
                    <div class="group-list-cell text-center" data-col="select"><span class="group-list-chevron"></span></div>
                    <div class="group-list-cell group-list-name">{{ tag }}</div>
                    <div class="group-list-cell"></div>
                    <div class="group-list-cell text-end">{{ size_format(tag_size(feeds)) }}</div>
                    <div class="group-list-cell"></div>
                    <div class="group-list-cell"></div>
                </div>
                <div class="group-list-rows" :class="{'is-expanded': expandedTags[tag]}">
                    <div class="group-list-rows-inner">
                        <div v-for="(feed, tagname) in feeds" :key="tagname" class="group-list-row" :class="['sync-' + feed.state, {'selected': selected[tagname]}]" @click="selected[tagname] = !selected[tagname]">
                            <div class="group-list-cell text-center" @click.stop><input type="checkbox" v-model="selected[tagname]"></div>
                            <div class="group-list-cell" :title="`Start time: ${toDate(side(feed).start_time)}\nInterval: ${interval_format(side(feed).interval)}s`">{{ side(feed).name }}</div>
                            <div class="group-list-cell" v-html="engine_badge(side(feed))"></div>
                            <div class="group-list-cell text-end text-muted">{{ size_format(side(feed).size) }}</div>
                            <div class="group-list-cell sync-status">
                                {{ status(feed, tagname) }}
                                <a v-if="tab == 'upload' && feed.state == 'behind'" href="#" @click.prevent.stop="show_download(feed)">Download</a>
                            </div>
                            <div class="group-list-cell text-center" @click.stop v-if="tab == 'upload'">
                                <div class="form-check form-switch" :title="feed.upload ? 'Uploading, click to stop' : 'Upload to remote'">
                                    <input class="form-check-input" type="checkbox" role="switch" :checked="feed.upload" :disabled="feed.state == 'behind'" @change="set_upload(tagname, !feed.upload)">
                                </div>
                            </div>
                            <div class="group-list-cell text-end" @click.stop v-else>
                                <button class="btn btn-default btn-sm" @click="download_feed(tagname)" v-if="can_download(feed) && !downloading[tagname]"><i class="svg-icon-download"></i> Download</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="sync-list-gap"></div>
        </template>
    </div>
    <p class="sync-note" v-else-if="!filter_text">
        <span v-if="tab == 'upload'">There are no feeds on this device.</span>
        <span v-else>There are no feeds on the remote server.</span>
    </p>

    <button class="btn btn-default btn-sm" v-if="tab == 'upload'" @click="refresh_feed_size"><i class="svg-icon-refresh-cw"></i> <?php echo _('Refresh feed size'); ?></button>
    </template>
</div>

<?php } else { ?>

<div class="alert alert-warning"><b>Error:</b> Redis is not installed or enabled. Please ensure that redis is installed on your system and then enabled in settings.php.</div>

<?php } ?>
</div>

<script>
    var redis_enabled = <?php echo $settings["redis"]["enabled"] ? 1 : 0; ?>;
    var path = "<?php echo $path; ?>";
    var feed_list_refresh_interval = false;

    var app = Vue.createApp({
        data() {
            return {
                tab: "<?php echo $tab; ?>",
                upload_interval: 300,

                // Authentication
                auth_with_apikey: 0,
                remote_host: "https://emoncms.org",
                remote_username: "",
                remote_password: "",
                remote_apikey: "",
                connected: false,
                edit_remote: false,

                feeds: {},
                selected: {},
                downloading: {},
                expandedTags: {},
                filter_text: "",
                next_update_seconds: 0,
                alert: "Connecting to remote emoncms server...",

                service_running: false,
                last_upload_time: "",
                last_upload_time_desc: "",
                last_upload_length: "",

                upload_size: 1000000
            };
        },
        computed: {
            linked_by() {
                if (this.auth_with_apikey) return "With write apikey";
                return "As " + this.remote_username;
            },
            // Feeds of the current tab, grouped by tag and filtered
            view_tags() {
                var f = this.filter_text.trim().toLowerCase();
                var out = {};
                for (var tagname in this.feeds) {
                    var feed = this.feeds[tagname];
                    if (!this.side(feed).exists) continue;
                    if (f && tagname.toLowerCase().indexOf(f) == -1) continue;
                    var tag = this.side(feed).tag;
                    if (out[tag] == undefined) out[tag] = {};
                    out[tag][tagname] = feed;
                }
                return out;
            },
            view_list() {
                var list = [];
                for (var tag in this.view_tags) list = list.concat(Object.keys(this.view_tags[tag]));
                return list;
            },
            selected_list() {
                return this.view_list.filter(tagname => this.selected[tagname]);
            },
            all_selected() {
                return this.view_list.length > 0 && this.selected_list.length == this.view_list.length;
            },
            all_expanded() {
                return Object.keys(this.view_tags).every(tag => this.expandedTags[tag]);
            },
            // Unfiltered, for the Download panel
            all_downloadable() {
                return Object.keys(this.feeds).filter(tagname => this.can_download(this.feeds[tagname]) && !this.downloading[tagname]);
            },
            selected_downloadable() {
                return this.selected_list.filter(tagname => this.can_download(this.feeds[tagname]) && !this.downloading[tagname]);
            },
            // Counts for the panels
            summary() {
                var s = { remote_count: 0, remote_size: 0, local_count: 0, upload_count: 0, missing_count: 0, missing_size: 0, behind_count: 0, downloading_count: 0 };
                for (var tagname in this.feeds) {
                    var f = this.feeds[tagname];
                    if (f.remote.exists) { s.remote_count++; s.remote_size += 1 * f.remote.size || 0; }
                    if (f.local.exists) { s.local_count++; if (f.upload * 1) s.upload_count++; }
                    if (f.state == "remote") { s.missing_count++; s.missing_size += 1 * f.remote.size || 0; }
                    if (f.state == "behind") s.behind_count++;
                    if (this.downloading[tagname]) s.downloading_count++;
                }
                return s;
            }
        },
        methods: {
            set_tab(tab) {
                this.tab = tab;
                for (var tagname in this.selected) this.selected[tagname] = false;
                history.replaceState(null, "", path + (tab == "download" ? "sync/view/download" : "sync"));
            },
            // Local side on the upload tab, remote side on the download tab
            side(feed) {
                return this.tab == "upload" ? feed.local : feed.remote;
            },
            can_download(feed) {
                return feed.state == "remote" || feed.state == "behind";
            },
            status(feed, tagname) {
                if (this.tab == "upload") {
                    if (feed.state == "local") return "Not on remote yet";
                    if (feed.state == "same") return "Up to date";
                    if (feed.state == "ahead") return feed.diff + " points to upload";
                    if (feed.state == "behind") return "Remote has newer data.";
                    if (feed.state == "differs") return "Start time or interval differs from remote";
                } else {
                    if (this.downloading[tagname]) return "Downloading...";
                    if (feed.state == "remote") return "Not on this device";
                    if (feed.state == "same") return "Up to date";
                    if (feed.state == "behind") return "Behind by " + feed.diff + " points";
                    if (feed.state == "ahead") return "This device has newer data";
                    if (feed.state == "differs") return "Start time or interval differs from this device";
                }
                return "";
            },
            show_download(feed) {
                this.filter_text = feed.local.name;
                this.set_tab("download");
            },
            toggleTag(tag) {
                this.expandedTags[tag] = !this.expandedTags[tag];
            },
            expand_all() {
                var state = !this.all_expanded;
                for (var tag in this.view_tags) this.expandedTags[tag] = state;
            },
            select_all(state) {
                for (var tagname of this.view_list) this.selected[tagname] = state;
            },
            tag_size(feeds) {
                var size = 0;
                for (var tagname in feeds) size += 1 * this.side(feeds[tagname]).size || 0;
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
                            app.edit_remote = false;
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
            download_list: function(list) {
                list.forEach(function(tagname) {
                    app.download_feed(tagname);
                });
            },
            download_feed: function(tagname) {
                app.downloading[tagname] = true;
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
                            delete app.downloading[tagname];
                        }
                    }
                });
            },
            // ---------------------
            // Upload feeds
            // ---------------------
            set_upload: function(tagname, upload) {
                app.feeds[tagname].upload = upload;
                $.ajax({
                    url: path + "sync/upload",
                    data: {
                        localid: app.feeds[tagname].local.id,
                        upload: upload * 1
                    },
                    dataType: 'json',
                    async: true,
                    success(result) {
                        if (!result.success) alert(result.message);
                    }
                });
            },
            set_upload_selected: function(upload) {
                for (var tagname of app.selected_list) {
                    if (app.feeds[tagname].state != "behind") app.set_upload(tagname, upload);
                }
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

    // Sets state per feed: local, remote, same, ahead, behind or differs, and diff in points
    function process_feed_list(result) {
        for (var tagname in result) {
            let f = result[tagname];
            f.diff = 0;

            if (f.local.exists && f.remote.exists) {
                f.state = "differs";
                if (f.local.start_time != f.remote.start_time) continue;
                if (f.local.engine == 5 && f.local.interval != f.remote.interval) continue;

                f.diff = Math.abs(f.local.npoints - f.remote.npoints);
                if (f.local.npoints > f.remote.npoints) f.state = "ahead";
                else if (f.local.npoints < f.remote.npoints) f.state = "behind";
                else f.state = "same";

            } else if (f.remote.exists) {
                f.state = "remote";
            } else {
                f.state = "local";
            }
        }
        return result;
    }

    // Fetch the feed list
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
                    let f = result[tagname];
                    if (app.expandedTags[f.local.tag] == undefined) app.expandedTags[f.local.tag] = true;
                    if (app.selected[tagname] == undefined) app.selected[tagname] = false;
                }

                app.feeds = process_feed_list(result);

                // Downloads end when the local feed catches up
                for (var tagname in app.downloading) {
                    if (app.feeds[tagname] && app.feeds[tagname].state == "same") delete app.downloading[tagname];
                }

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
