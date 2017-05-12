<?php global $path; ?>
<style>
.syncprogress {
    background-color:#7bc3e2;
    border: 1px solid #29abe2;
    color:#fff;
    font-size:10px;
    padding:2px;
}
</style>

<h2>SYNC</h2>

Select: <button id="select-all" class="btn">All</button><button id="select-none" class="btn">None</button>
<br>
<table class="table">
    <tr><th></th><th>Location</th><th>Feed Name</th><th>Start time</th><th>Interval</th><th></th><th></th></tr>
    <tbody id="feeds"></tbody>
</table>


<h3>Remote</h3>
<p>Host<br><input id="remote-host" type="text" value="http://localhost/master"></p>
<p>Username<br><input id="remote-username" type="text" ></p>
<p>Password<br><input id="remote-password" type="password" ></p>
<button id="remote-save" class="btn">Save</button>

<script>

var path = "<?php echo $path; ?>";
var remote = false;
var feeds = [];

// Load remote details
$.ajax({ url: path+"sync/remote-load", dataType: 'json', async: false, success: function(result){
    if (result.success!=undefined && !result.success) remote=false; else remote=result;
}});

if (remote) {
    $("#remote-host").val(remote.host);
    $("#remote-username").val(remote.username);

    $.ajax({ url: path+"sync/feed-list", dataType: 'json', async: false, success: function(result){
        feeds = result;
    }});

    var out = "";
    for (var name in feeds) {
        out += "<tr>";
        out += "<td><input class='feed-select-checkbox' type=checkbox></td>";
        
        
        var feedlocation = "";
        var action = "";
        var status = "";
        
        var interval = 0;
        var start_time = 0;
        
        if (!feeds[name].local.exists && feeds[name].remote.exists) {
            feedlocation = "Remote"; 
            action = "<button class='btn download' name='"+name+"'><i class='icon-arrow-left' ></i> Download</button>";
            
            start_time = feeds[name].remote.start_time;
            interval = feeds[name].remote.interval;
        }
        
        if (feeds[name].local.exists && !feeds[name].remote.exists) {
            feedlocation = "Local";
            action = "<button class='btn upload' name='"+name+"'><i class='icon-arrow-right' ></i> Upload</button>";
            
            start_time = feeds[name].local.start_time;
            interval = feeds[name].local.interval;
        }
        
        
        
        if (feeds[name].local.start_time==feeds[name].remote.start_time && feeds[name].local.interval==feeds[name].remote.interval) {
            feedlocation = "Both";   
            if (feeds[name].local.npoints>feeds[name].remote.npoints) {
                status = "Local ahead of Remote by "+(feeds[name].local.npoints-feeds[name].remote.npoints)+" points";
            } else {
                status = "Local behind Remote by "+(feeds[name].remote.npoints-feeds[name].local.npoints)+" points";
                action = "<button class='btn download' name='"+name+"'><i class='icon-arrow-left' ></i> Download</button>";
            }
            
            start_time = feeds[name].local.start_time;
            interval = feeds[name].local.interval;
        }
        
        out += "<td>"+feedlocation+"</td>";
        out += "<td>"+name+"</td>";
        out += "<td>"+timeConverter(start_time)+"</td>";
        out += "<td>"+interval+"s</td>";
        
        out += "<td>"+status+"</td>";
        
        //out += "<td>"+feeds[name].local.start_time+":"+feeds[name].local.interval+":"+feeds[name].local.npoints+"</td>";
        out += "<td>"+action+"</td>";
       
        //out += "<td>"+feeds[z].remote.start_time+":"+feeds[z].remote.interval+":"+feeds[z].remote.npoints+"</td>";
        //out += "<td><div class='syncprogress' style='width:"+Math.round(feeds[name].remote.npoints*0.0001)+"px'>"+feeds[name].local.start_time+":"+feeds[name].local.interval+":"+feeds[name].remote.npoints+"</div></td>";
        out += "</tr>";
    }
    $("#feeds").html(out);
}

$("#remote-save").click(function(){
    var host = $("#remote-host").val();
    var username = $("#remote-username").val();
    var password = $("#remote-password").val();
    
    $.ajax({ 
        type: "POST",
        url: path+"sync/remove-save", 
        data: "host="+host+"&username="+username+"&password="+password,
        dataType: 'json',
        async: true, 
        success: function(result){
            if (result.success) {
                // feed list scan
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

$(".download").click(function(){
    var name = $(this).attr("name");
    $.ajax({
        url: path+"sync/download", 
        data: "name="+name+"&tag="+feeds[name].remote.tag+"&remoteid="+feeds[name].remote.id+"&interval="+feeds[name].remote.interval,
        dataType: 'json',
        async: true, 
        success: function(result){
            if (result.success) {
                // success
            } else alert(result.message);
        } 
    });
});

$(".upload").click(function(){
    var name = $(this).attr("name");
    $.ajax({
        url: path+"sync/upload", 
        data: "name="+name+"&tag="+feeds[name].local.tag+"&localid="+feeds[name].local.id+"&interval="+feeds[name].local.interval,
        dataType: 'json',
        async: true, 
        success: function(result){
            if (result.success) {
                // success
            } else alert(result.message);
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


</script>
