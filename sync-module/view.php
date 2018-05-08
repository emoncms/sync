<?php global $path,$redis_enabled;

?>
<style>
.syncprogress {
    background-color:#7bc3e2;
    border: 1px solid #29abe2;
    color:#fff;
    font-size:10px;
    padding:2px;
}
</style>

<h3>Emoncms Sync</h3>

<p>The module can be used to download or upload data to or from a remote emoncms installation.</p>
<p>Start by entering the remote emoncms installation location in the <i>host</i> field (e.g https://emoncms.org). Then enter the <i>username</i> and <i>password</i> of the account you wish to link to.</p>
<p>Download or upload specific feeds as required.</p>
<p><b>Note:</b> Data upload is not yet enabled on emoncms.org.</p>
<br>
<?php if ($redis_enabled) { ?>

<div class="input-prepend input-append">
<span class="add-on">Host</span><input id="remote-host" type="text" value="https://emoncms.org">
<span class="add-on">Username</span><input id="remote-username" type="text" style="width:150px" >
<span class="add-on">Password</span><input id="remote-password" type="password" style="width:150px" >
<button id="remote-save" class="btn">Connect</button>
</div>

<!--
<div class="input-prepend input-append" style="float:left">
<span class="add-on">Select</span><button id="select-all" class="btn">All</button><button id="select-none" class="btn">None</button>
</div>
-->

<div id="time" style="float:right; padding-top:10px; padding-right:20px">Next update: 0s</div>


<div class="alert alert-info">

</div>

<table id="sync-table" class="table hide">
    <tr><th>Location</th><th>Feed Name</th><th>Start time</th><th>Interval</th><th></th><th></th></tr>
    <tbody id="feeds"></tbody>
</table>

<?php } else { ?>

<div class="alert alert-warning"><b>Error:</b> Redis is not installed or enabled. Please ensure that redis is installed on your system and then enabled in settings.php.</div>

<?php } ?>

<script>

var redis_enabled = <?php echo $redis_enabled; ?>;
var path = "<?php echo $path; ?>";
var remote = false;
var feeds = [];

if (redis_enabled) {
    $(".alert").show().html("Connecting to remote emoncms server...");
    // Load remote details
    $.ajax({ url: path+"sync/remote-load", dataType: 'json', async: false, success: function(result){
        if (result.success!=undefined && !result.success) remote=false; else remote=result;
    }});

    $("#remote-host").val(remote.host);
    $("#remote-username").val(remote.username);

    if (remote) {
        $("#sync-table").show();
        sync_list();
    }

    setInterval(sync_list,10000);
}

function sync_list()
{
    next_update = 10;

    $(".alert").html("Connected. Fetching emoncms feeds...");
    $.ajax({ url: path+"sync/feed-list", dataType: 'json', async: false, success: function(result){
        feeds = result;
    }});

    var out = "";
    for (var name in feeds) {
    
        var action = "";
        var status = "";
        var feedlocation = "";
        var interval = 0;
        var start_time = 0;
        var tr = "<tr>";
        
        if (!feeds[name].local.exists && feeds[name].remote.exists) {
            feedlocation = "Remote"; 
            action = "<button class='btn btn-small download' name='"+name+"'><i class='icon-arrow-left' ></i> Download</button>";
            
            start_time = feeds[name].remote.start_time;
            interval = feeds[name].remote.interval;
        }
        
        if (feeds[name].local.exists && !feeds[name].remote.exists) {
            feedlocation = "Local";
            action = "<button class='btn btn-small upload' name='"+name+"'><i class='icon-arrow-right' ></i> Upload</button>";
            
            start_time = feeds[name].local.start_time;
            interval = feeds[name].local.interval;
        }
        
        if (feeds[name].local.start_time==feeds[name].remote.start_time && feeds[name].local.interval==feeds[name].remote.interval) {
            feedlocation = "Both";
            
            start_time = feeds[name].local.start_time;
            interval = feeds[name].local.interval;
        }
        
        if (feeds[name].local.start_time==feeds[name].remote.start_time && feeds[name].local.interval==feeds[name].remote.interval) {
            if (feeds[name].local.npoints>feeds[name].remote.npoints) {
                tr = "<tr class='info'>";
                
                status = "Local ahead of Remote by "+(feeds[name].local.npoints-feeds[name].remote.npoints)+" points";
                action = "<button class='btn btn-small upload' name='"+name+"'><i class='icon-arrow-right' ></i> Upload</button>";
                
            } else if (feeds[name].local.npoints<feeds[name].remote.npoints) {
                tr = "<tr class='warning'>";
                
                status = "Local behind Remote by "+(feeds[name].remote.npoints-feeds[name].local.npoints)+" points";
                action = "<button class='btn btn-small download' name='"+name+"'><i class='icon-arrow-left' ></i> Download</button>";
                
            } else {
                tr = "<tr class='success'>";
                
                status = "Local and Remote are the same";
                action = "";
            }
        }
        
        //out += "<td><input class='feed-select-checkbox' type=checkbox></td>";
        
        out += tr;
        out += "<td>"+feedlocation+"</td>";
        out += "<td>"+name+"</td>";
        
        if (interval!=undefined) {
            out += "<td>"+timeConverter(start_time)+"</td>";
        } else {
            out += "<td>n/a</td>";
        }
        
        if (interval!=undefined) {
            out += "<td>"+interval+"s</td>";
        } else {
            out += "<td>n/a</td>";
        } 
        
        out += "<td class='status' name='"+name+"'>"+status+"</td>";
        
        //out += "<td>"+feeds[name].local.start_time+":"+feeds[name].local.interval+":"+feeds[name].local.npoints+"</td>";
        out += "<td>"+action+"</td>";
       
        //out += "<td>"+feeds[z].remote.start_time+":"+feeds[z].remote.interval+":"+feeds[z].remote.npoints+"</td>";
        //out += "<td><div class='syncprogress' style='width:"+Math.round(feeds[name].remote.npoints*0.0001)+"px'>"+feeds[name].local.start_time+":"+feeds[name].local.interval+":"+feeds[name].remote.npoints+"</div></td>";
        out += "</tr>";
    }
    $("#feeds").html(out);
    $(".alert").hide();
}

$("#remote-save").click(function(){
    var host = $("#remote-host").val();
    var username = $("#remote-username").val();
    var password = $("#remote-password").val();
    
    $("#sync-table").hide();
    $(".alert").show().html("Connecting to remote emoncms server...");
    
    $.ajax({ 
        type: "POST",
        url: path+"sync/remove-save", 
        data: "host="+host+"&username="+username+"&password="+password,
        dataType: 'json',
        async: true, 
        success: function(result){
            if (result.success) {
                $("#sync-table").show();
                remote = result;
                // feed list scan
                sync_list();
                
            } else {
                alert(result.message);
            }
        } 
    });
});

$("#select-all").click(function(){
    $(".feed-select-checkbox").each(function(){
        $(this)[0].checked = true;
    });
});

$("#select-none").click(function(){
    $(".feed-select-checkbox").each(function(){
        $(this)[0].checked = false;
    });
});

$("#feeds").on("click",".download",function(){
    var name = $(this).attr("name");
    $(".status[name='"+name+"']").html("Downloading...");
    $.ajax({
        url: path+"sync/download", 
        data: "name="+name+"&tag="+feeds[name].remote.tag+"&remoteid="+feeds[name].remote.id+"&interval="+feeds[name].remote.interval+"&engine="+feeds[name].remote.engine+"&datatype="+feeds[name].remote.datatype,
        dataType: 'json',
        async: true, 
        success: function(result){
            if (result.success) {
                // success
            } else { 
                alert(result.message); 
                $(".status[name='"+name+"']").html("");
            }
        } 
    });
});

$("#feeds").on("click",".upload",function(){
    var name = $(this).attr("name");
    $(".status[name='"+name+"']").html("Uploading...");
    $.ajax({
        url: path+"sync/upload", 
        data: "name="+name+"&tag="+feeds[name].local.tag+"&localid="+feeds[name].local.id+"&interval="+feeds[name].local.interval,
        dataType: 'json',
        async: true, 
        success: function(result){
            if (result.success) {
                // success
            } else { 
                alert(result.message); 
                $(".status[name='"+name+"']").html("");
            }
        } 
    });
});



function timeConverter(UNIX_timestamp){
  var a = new Date(UNIX_timestamp * 1000);
  var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var year = a.getFullYear();
  var month = months[a.getMonth()];
  var date = a.getDate();
  var hour = a.getHours();
  if (hour<10) hour = "0"+hour;
  var min = a.getMinutes();
  if (min<10) min = "0"+min;
  var sec = a.getSeconds();
  if (sec<10) sec = "0"+sec;
  var time = date + ' ' + month + ' ' + year + ', ' + hour + ':' + min + ':' + sec ;
  return time;
}

setInterval(ticker,1000);

function ticker() {
    next_update --;
    $("#time").html("Next update: "+next_update+"s");
}
</script>
