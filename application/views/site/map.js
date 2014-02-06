var MAP; // the Leaflet Map object
var VISIBLE_MARKERS; // L.markerClustergroup; the set of all markers currently displaying on the map via a clusterer; a subset of ALL_MARKERS

$(document).ready(function () {
    // One Moment Please
    $('#dialog_waiting').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: '',
        buttons: { }
    });

    // set up the accordion of filter options, on the right-hand side
    $('#tools').accordion({
        heightStyle:'fill',
        header: 'h5',
        collapsible: true
    });

    // start the map at the default bbox, add the basic layer
    MAP = L.map('map_canvas').fitBounds([[START_S,START_W],[START_N,START_E]]);
    L.tileLayer('http://{s}.tiles.mapbox.com/v3/greeninfo.map-fdff5ykx/{z}/{x}/{y}.jpg', {}).addTo(MAP);

    // add the marker clusterer, though with no markers just yet
    VISIBLE_MARKERS = L.markerClusterGroup({
        showCoverageOnHover:false,
        maxClusterRadius: 36,
        iconCreateFunction: createClusterDiv
    }).addTo(MAP);

    // enable the geocoder so they can find their address. well, only if there's a Bing key given
    if (BING_API_KEY) {
        $('#geocode_go').click(function () {
            geocodeAndZoom( $('#geocode').val() );
        });
    } else {
        $('#geocode').hide();
        $('#geocode_go').hide();
    }

    // general thing: any text input, when someone presses Enter, should trigger a click on the sibling button  (if any)
    $('input[type="text"]').keydown(function (key) {
        if(key.keyCode == 13) $(this).siblings('input[type="button"]').click();
    });

    // when the window resizes, resize the map too
    // then trigger a resize right now, so the map and other elements fit the current page size
    $(window).resize(handleResize);
    handleResize();

    // now various ways to trigger a search: picking a date & time, selecting the category checkboxes, entering a text search, ...
    // these all funnel to the same place:  submitFilters()
    $('input[name="categories[]"]').change(function () {
        submitFilters();
    });
    $('input.search_submit').click(function () {
        submitFilters();
    });
    $('a.check_all').click(function () {
        $(this).parent().parent().find('input[type="checkbox"]').prop('checked','checked');
        submitFilters();
    });
    $('a.check_none').click(function () {
        $(this).parent().parent().find('input[type="checkbox"]').removeAttr('checked');
        submitFilters();
    });
    $('input[name="event_locations"]').change(function () {
        submitFilters();
    });

    // enable the date picker, and a Now button to set them both to Right Now, and a Clear button to reset them to blank (no time/date filtering)
    $('input.dateinput').datepicker({
        dateFormat:'yy-mm-dd'
    });
    $('#datetime_now').click(function () {
        var now = new Date();
        $('#tools input[name="date"]').datepicker('setDate', now);
        submitFilters();
    });
    $('#datetime_clear').click(function () {
        $('#tools input[name="date"]').val('');
        submitFilters();
    });

    // submit our initial request, with whatever our initial conditions are
    submitFilters();
}); // end of onready



function submitFilters() {
    // quick sanity check: if a date or time are given, both must be given
    var has_date = $('#tools input[name="date"]').val();

    // compile the URL and params
    var url = BASE_URL + 'site/ajax_map_points/';
    var params = $('#tools').serialize();

    /* CUSTOM VERSION for when we come up with some custom need that won't just serialize()   Not sure what, but it's bound to happen...
    params.categories = [];
    $('input[name="categories[]"]:checked').each(function () {
        var catid = $(this).prop('value');
        params.categories.push(catid);
    });

    params.keywords = $('input[name="keywords"]').val();
    */

    // ready!
    $('#dialog_waiting').dialog('open');
    $.post(url, params, function (points) {
        $('#dialog_waiting').dialog('close');
        reloadMapPoints(points);
    }, 'json');
}


function reloadMapPoints(points) {
    // start by clearing the existing markers
    VISIBLE_MARKERS.clearLayers();

    // and load up the new ones; for performance, build them in memory before clustering, so we don't recluster for every single marker
    var markers = [];
    for (var i=0, l=points.length; i<l; i++) {
        // generate a simple DIV icon, using the selected color
        var icon = new L.DivIcon({ className:'marker-icon', iconAnchor:L.point(10,10), iconSize:L.point(20,20) });

        // hack: if the description has any hyperlinks, add a target to them so they open in a new window
        points[i].desc = points[i].desc.replace(/<a /g, '<a target="_blank" ');

        // compose the HTML for the popup
        var html = '';
        html += '<h5>' + points[i].name + '</h5>';
        html += points[i].desc;
        html += '<p>' + 'Categories: ' + points[i].category_names + '</p>';

        // assign the attributes into a marker, and bind it to a HTML popup with implicit click handler
        var marker = L.marker([points[i].lat,points[i].lng], { icon:icon, attributes:points[i], keyboard:false, title:points[i].name }).bindPopup(html);
        markers.push(marker);
    }

    // all set, cluster it!
    VISIBLE_MARKERS.addLayers(markers);
}


function handleResize() {
    // resize the map, to the height of the screen minus header & footer
    var mapheight = $(window).height();
    $('div.navbar').each(function () {
        mapheight -= $(this).height() + 2;
    });
    $('#map_canvas').height(mapheight - 5);
    if (MAP) MAP.invalidateSize();

    // resize the right hand side to be the same height as the map
    $('#tools').parent().height(mapheight - 4);
    $('#tools').accordion('refresh');
}


function geocodeAndZoom(address) {
    if (! address) return;
    if (! BING_API_KEY) return alert("Address searches disabled.\nNo Bing Maps API key has been entered by the site admin.");

    var url = 'http://dev.virtualearth.net/REST/v1/Locations/' + encodeURIComponent(address) + '?output=json&jsonp=handleGeocodeResult&key=' + BING_API_KEY;
    $('<script></script>').prop('type','text/javascript').prop('src',url).appendTo( jQuery('head') );
}


function handleGeocodeResult(results) {
    if (results.authenticationResultCode != 'ValidCredentials') return alert("The Bing Maps API key appears to be invalid.");

    var result, w, s, e, n;
    try {
        result = results.resourceSets[0].resources[0]
        w = result.bbox[1];
        s = result.bbox[0];
        e = result.bbox[3];
        n = result.bbox[2];
    } catch (e) {
        return alert('Could not find that location.');
    }

    // zoom the map, move the marker
    MAP.fitBounds([[s,w],[n,e]]);
}



// callback to create a DIV element for this marker cluster
function createClusterDiv(cluster) {
    var count    = cluster.getChildCount(); // how many markers in this cluster
    var size     = new L.Point(36, 36); // all clusters same size
    var cssclass = 'marker-cluster';
    var html     = '<div><span>' + count + '</span></div>';

    return new L.DivIcon({ html:html, className:'marker-cluster', iconAnchor:L.point(20,20), iconSize:L.point(40,0) });
}

