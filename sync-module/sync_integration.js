/**
 * Sync integration for the feed list page.
 *
 * Self-contained: this file is loaded by Modules/feed/Views/feed_list.php only
 * when the sync module is installed. It registers a "feed list plugin" that
 * decorates feed objects with a sync upload status, which the feed list
 * template renders in the data-col="sync" column.
 *
 * All status data is read from a local cache (sync/feed-status) which is
 * populated by the sync_upload.php background process - no remote server
 * calls are made from here, and polling is decoupled from the 5s feed list
 * refresh (status is refreshed every 60s).
 */
(function() {
    "use strict";

    // Map of feed id -> { upload, status, ... } from sync/feed-status
    var syncStatusCache = {};

    // Only feeds actively participating in upload get a visible badge.
    var SYNC_LABELS = {
        synced:    "Synced",
        behind:    "Pending",
        no_remote: "New",
        unknown:   "Enabled"
    };

    function decorate(feeds) {
        if (!feeds) return;
        for (var id in feeds) {
            var s = syncStatusCache[id];
            if (s && SYNC_LABELS[s.status] !== undefined) {
                feeds[id].sync_status = s.status;
                feeds[id].sync_label = SYNC_LABELS[s.status];
            } else {
                // Clear any previous badge (e.g. upload was disabled)
                feeds[id].sync_status = null;
                feeds[id].sync_label = null;
            }
        }
    }

    // Register the decorator so feed_list.js applies it on every refresh.
    window.feedListPlugins = window.feedListPlugins || [];
    window.feedListPlugins.push({ decorate: decorate });

    function applyNow() {
        if (typeof feedApp !== "undefined" && feedApp && feedApp.feeds) {
            decorate(feedApp.feeds);
        }
    }

    function fetchStatus() {
        $.ajax({
            url: path + "sync/feed-status.json",
            dataType: "json",
            async: true,
            success: function(data) {
                syncStatusCache = (data && typeof data === "object") ? data : {};
                applyNow();
            }
        });
    }

    // Check whether sync is configured before doing any further work.
    $.ajax({
        url: path + "sync/remote-load.json",
        dataType: "json",
        async: true,
        success: function(remote) {
            if (!remote || !remote.success || !remote.host) {
                // Sync module installed but not configured: stay silent.
                return;
            }
            fetchStatus();
            setInterval(fetchStatus, 60000);
        }
    });
})();
