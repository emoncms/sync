var max_wrapper_width = 1150;
var sidebar_enabled = true;
var sidebar_visible = true;
var menu_element = "#sync_menu";

$(menu_element).parent().attr("href","#");
$(menu_element).find("i").removeClass("icon-random");
$(menu_element).find("i").addClass("icon-list");

sidebar_resize();

$(window).resize(function(){
    sidebar_resize();
});

$(menu_element).parent().click(function(){
    if (sidebar_visible) {
        sidebar_enabled = false;
        hide_sidebar();
    } else {
        sidebar_enabled = true;
        show_sidebar();
    }
});

function sidebar_resize() {
    var width = $(window).width();
    var height = $(window).height();
    var nav = $(".navbar").height();
    
    $(".sidenav").height(height-nav);
    
    if (width<max_wrapper_width) {
        hide_sidebar()
    } else {
        if (sidebar_enabled) show_sidebar()
    }
}

function show_sidebar() {
    var width = $(window).width();
    sidebar_visible = true;
    $(".sidenav").css("left","250px");
    if (width>=max_wrapper_width) $("#wrapper").css("padding-left","250px");
    $("#wrapper").css("margin","0");
    $(".sidenav-open").hide();
    $(".sidenav-close").hide();
}

function hide_sidebar() {
    sidebar_visible = false;
    $(".sidenav").css("left","0");
    $("#wrapper").css("padding-left","0");
    $("#wrapper").css("margin","0 auto");
    $(".sidenav-open").show();
}
