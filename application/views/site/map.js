var MAP; // the Leaflet Map object
var VISIBLE_MARKERS; // PruneClusterForLeaflet marker cluster system; the set of all markers currently displaying on the map via a clusterer
var DIRECTIONS_LINE, DIRECTIONS_MARKER1, DIRECTIONS_MARKER2; // for directions: the origin & destination markers, and the overlaid line
var DIRECTIONS_LINE_STYLE = {
    color: 'red'
};

// this dialog box needs it position re-asserted in a few places, so make it a constant
var DIRECTIONS_DIALOG_POSITION = { my:'right top', at:'right top', of:'#rhs' };

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

    // start the map at the default bbox, add the basemap layer
    // but which basemap layer... is why we have a switch
    // important: googleterrain has a max of 15, as opposed to Leaflet's default maxZoom of 18; so artificially change the maxZoom to suit the basemap
    var maxZoom = 18, minZoom = 6;
    var basemap;
    switch (BASEMAP_TYPE) {
        case 'xyz':
            // a simple XYZ layer, and they provided the URL template too; sounds simple
            basemap = L.tileLayer(BASEMAP_XYZURL, {});
            break;
        case 'googlestreets':
            basemap = new L.Google('ROADMAP');
            break;
        case 'googlesatellite':
            basemap = new L.Google('HYBRID');
            break;
        case 'googleterrain':
            basemap = new L.Google('TERRAIN');
            maxZoom = 15;
            break;
        case 'bingstreets':
            basemap = new L.BingLayer(BING_API_KEY, { type:'Road' });
            break;
        case 'bingsatellite':
            basemap = new L.BingLayer(BING_API_KEY, { type:'AerialWithLabels' });
            break;
        default:
            return alert("Invalid basemap choice? How did that happen?");
            break;
    }
    MAP = L.map('map_canvas',{ minZoom:minZoom, maxZoom:maxZoom }).fitBounds([[START_S,START_W],[START_N,START_E]]);
    MAP.addLayer(basemap);

    // add the marker clusterer, though with no markers just yet
    VISIBLE_MARKERS = new PruneClusterForLeaflet();
    MAP.addLayer(VISIBLE_MARKERS);

//gda
    // map event: when a popup is opened highlight the corresponding marker; when a popup is closed un-highlight them
    // the popupopen event has an undocumentedc (non-API! DANGER) _source attribute to connect to the marker, so huzzah!
    MAP.on('popupopen', function (event) {
console.log(event.popup._source);
        highlightMarker(event.popup._source);
    }).on('popupclose', function (event) {
        highlightMarker(null);
    });

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
    $('#keywords_clear').click(function () {
        $('#tools input[name="keywords"]').val('');
        submitFilters();
    });

    // the directions dialog: a dialog with an address field; hitting enter in the text box submits it, 
    $('#dialog_directions').dialog({
        modal:false, closeOnEsc:true, autoOpen:false, draggable:false, resizable:false,
        width:'auto', height:'auto',
        title: 'Get Directions',
        position: DIRECTIONS_DIALOG_POSITION,
        buttons: {
            'Get Directions': function () {
                getDirectionsFromDialog();
            },
            'Close': function () {
                $(this).dialog('close');
            }
        },
        open: function () {
            // on open: clear any previous directions which may still be showing from a prior run
            clearDirections();
            $(this).dialog('option', 'position', DIRECTIONS_DIALOG_POSITION);
        }
    });
    $('#directions_address').keydown(function (key) {
        if(key.keyCode == 13) getDirectionsFromDialog();
    });

    // DONE!
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
    VISIBLE_MARKERS.RemoveMarkers();

    // override the factory method so we can assign popups and all; the PruneCluster docs are dead wrong about assigning marker.data.popup
    // and override the factory method for the cluster icon itself, to use event/place/both
    // kinda goofy to override these functions here every time we load new points, but this places it where we'll be in the code, keeps it in-sight and in-mind
    // tip: highlighting of markers is handled by popupclose and popupopen event handlers on the map, see the L.map() constructor for the connections to highlightMarker()
    VISIBLE_MARKERS.PrepareLeafletMarker = function(leafletMarker, data){
        leafletMarker.setIcon(data.icon);
        leafletMarker.bindPopup(data.html);
        leafletMarker.attributes = data.attributes;
    }
    VISIBLE_MARKERS.BuildLeafletClusterIcon = function(cluster) {
        // use the built-in categories facility to generate an icon
        // see the "category" switch below for the assignment of these integer codes; we only use 2 of these codes at this time
        var stats = cluster.stats;

        var icon;
        if (stats[0] && stats[1]) {
            // both places and events
            icon = L.icon({ iconUrl: BASE_URL + 'mobile/image/marker_both', iconSize: [BOTH_MARKER_WIDTH, BOTH_MARKER_HEIGHT] });
        } else if (stats[0]) {
            // places only
            icon = L.icon({ iconUrl: BASE_URL + 'mobile/image/marker_place', iconSize: [PLACE_MARKER_WIDTH, PLACE_MARKER_HEIGHT] });
        } else {
            // events only   (got here and it can't both be 0)
            icon = L.icon({ iconUrl: BASE_URL + 'mobile/image/marker_event', iconSize: [EVENT_MARKER_WIDTH, EVENT_MARKER_HEIGHT] });
        }

        return icon;
    };

    // and load up the new ones; note that we refresh at the end  (slightly different from old clusterer, where we add markers en masse, and that triggers a redraw)
    for (var i=0, l=points.length; i<l; i++) {
        // choose the icon and the type, and compose the attributes
        // the category is just some integer 0-7, used by the clusterer's internal "stats" calculation and thus by BuildLeafletClusterIcon()
        var icon, category, attributes;
        switch (points[i].type) {
            case 'place':
                icon       = L.icon({ iconUrl: BASE_URL + 'mobile/image/marker_place', iconSize: [PLACE_MARKER_WIDTH, PLACE_MARKER_HEIGHT] });
                category   = 0;
                attributes = points[i];
                break;
            case 'event':
                icon       = L.icon({ iconUrl: BASE_URL + 'mobile/image/marker_event', iconSize: [EVENT_MARKER_WIDTH, EVENT_MARKER_HEIGHT] });
                category   = 1;
                attributes = points[i];
                break;
            default:
                throw "Weird: unknown type of marker?";
        }

        // choose a hover title
        var name = points[i].name;

        // HTML prep
        // data fix: if the description has any hyperlinks, add a target to them so they open in a new window
        var description = points[i].desc.replace(/<a /g, '<a target="_blank" ');

        // HTML prep
        // if this point has an URL create a link
        var weblink = '';
        if (points[i].url) weblink = '<a target="_blank" href="' + points[i].url + '">more info</a>';

        // HTML prep
        // create the directions link but only if we're Bing enabled
        var dirlink = '';
        if (BING_API_KEY) dirlink = '<a href="javascript:void(0);" onClick="clickDirectionsLink(this);" data-lat="'+points[i].lat+'" data-lon="'+points[i].lng+'" data-title="'+ htmlEntities(points[i].name) +'">directions</a>';

        // finally
        // compose the HTML for the popup
        var html = '';
        html += '<h5>' + points[i].name + '</h5>';
        if (weblink || dirlink) {
            html += '<div>' + weblink + ' &nbsp; &nbsp; ' + dirlink + '</div>';
        }
        html += description;
        html += '<p>' + 'Categories: ' + points[i].category_names.join(', ') + '</p>';

        // and we're set; make up the marker
        // start by fetching coordinates, creating the bare marker, adding it to the clusterer
        var marker = new PruneCluster.Marker(points[i].lat, points[i].lng, { attributes:attributes, title:name, html:html, icon:icon });
        marker.category = category;
        VISIBLE_MARKERS.RegisterMarker(marker);
    }

    // now the refresh
    VISIBLE_MARKERS.RedrawIcons();
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

    // correct the URL cuz we're using a naive REST/JSONP technique: remove any & characters cuz encodeURIComponent() doesn't solve & causing a Bad Request error
    address = address.replace('&', 'and');

    var url = 'http://dev.virtualearth.net/REST/v1/Locations/' + encodeURIComponent(address) + '?output=json&jsonp=handleGeocodeResult&key=' + BING_API_KEY;
    $('<script></script>').prop('type','text/javascript').prop('src',url).appendTo( jQuery('head') );
}


function handleGeocodeResult(results) {
    if (results.authenticationResultCode != 'ValidCredentials') return alert("The Bing Maps API key appears to be invalid.");

    var result, w, s, e, n;
    try {
        result = results.resourceSets[0].resources[0];
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


// this onClick hook is added to Directions hyperlinks in popup bubbles
// the link has data-lat= and data=lon= attributes, which form our intended destination
function clickDirectionsLink(link) {
    if (! BING_API_KEY) return alert('This site has not enabled Bing services, so directions are not available.');

    var $link = $(link);
    var lat   = parseFloat( $link.attr('data-lat'));
    var lon   = parseFloat( $link.attr('data-lon') );
    var title = $link.attr('data-title');
    if (!lat || !lon) return alert("No lat/lon available. How did this happen?");

    // reset the Directions form (fill in the address from the zoom address, if that's appropriate) and open it
    var addrbox = $('#directions_address');
    if (! addrbox.val()) addrbox.val( $('#geocode').val() );
    $('#directions_lat').val(lat);
    $('#directions_lon').val(lon);
    $('#directions_destname').text(title);
    $('#dialog_directions').dialog('open');
}


// look over the directions dialog content, fetch the origin address and target lat/lon, get directions
function getDirectionsFromDialog() {
    // various checks: do we have Bing enabled, are lat & lon given, is there an address, ...?
    if (! BING_API_KEY) return alert('This site has not enabled Bing services, so directions are not available.');
    if (! parseFloat( $('#directions_lat').val() )) return alert("No lat/lon available. How did this happen?");
    if (! parseFloat( $('#directions_lon').val() )) return alert("No lat/lon available. How did this happen?"); 

    var address = $('#directions_address').val() ;
    if (! address) return alert("Enter an address.");

    // clear previous results
    clearDirections();

    // correct the URL cuz we're using a naive REST/JSONP technique: remove any & characters cuz encodeURIComponent() doesn't solve & causing a Bad Request error
    address = address.replace('&', 'and');
    var url = 'http://dev.virtualearth.net/REST/v1/Locations/' + encodeURIComponent(address) + '?output=json&jsonp=handleDirectionsGeocodeResult&key=' + BING_API_KEY;
    $('<script></script>').prop('type','text/javascript').prop('src',url).appendTo( jQuery('head') );
}

function handleDirectionsGeocodeResult(results) {
    if (results.authenticationResultCode != 'ValidCredentials') return alert("The Bing Maps API key appears to be invalid.");

    var result, start_lat, start_lon;
    try {
        result    = results.resourceSets[0].resources[0];
        start_lat = result.point.coordinates[0];
        start_lon = result.point.coordinates[1];
    } catch (e) {
        return alert('Could not find that location.');
    }

    // got the point, so go ahead and lay down the start and destination markers
    var end_lat = parseFloat( $('#directions_lat').val() );
    var end_lon = parseFloat( $('#directions_lon').val() );
    DIRECTIONS_MARKER1 = L.marker([start_lat,start_lon], { clickable:false }).addTo(MAP);
    DIRECTIONS_MARKER2 = L.marker([end_lat,end_lon], { clickable:false }).addTo(MAP);

    // ... then get directions between the two markers
    var start = start_lat + ',' + start_lon;
    var dest  = end_lat   + ',' + end_lon;
    var mode  = 'driving';

    var url = 'http://dev.virtualearth.net/REST/v1/Routes?wp.0=' + encodeURIComponent(start) + '&wp.1=' + encodeURIComponent(dest) + '&routePathOutput=Points&travelMode='+mode+'&output=json&distanceUnit='+ DISTANCE_UNITS +'&jsonp=RouteCallback&key=' + BING_API_KEY;
    $('<script></script>').prop('type','text/javascript').prop('src',url).appendTo( jQuery('head') );
}

function clearDirections() {
    // clear any previous directions results
    if (DIRECTIONS_LINE) {
        MAP.removeLayer(DIRECTIONS_LINE);
        DIRECTIONS_LINE = null;
    }
    if (DIRECTIONS_MARKER1) {
        MAP.removeLayer(DIRECTIONS_MARKER1);
        DIRECTIONS_MARKER1 = null;
    }
    if (DIRECTIONS_MARKER2) {
        MAP.removeLayer(DIRECTIONS_MARKER2);
        DIRECTIONS_MARKER2 = null;
    }

    $('#directions_results').empty();
}


function RouteCallback(response) {
    console.log(response);
    if (response.authenticationResultCode != 'ValidCredentials') return alert("The Bing Maps API key appears to be invalid.");

    var result, w, s, e, n;
    try {
        result = response.resourceSets[0].resources[0];
        w = result.bbox[1];
        s = result.bbox[0];
        e = result.bbox[3];
        n = result.bbox[2];
        } catch (e) {
        return alert('Could not find directions between these locations.');
    }

    // zoom to the bounding box of the route so the user can see the overview
    MAP.fitBounds([[s,w],[n,e]]);

    // compose the line and draw it onto the map
    // very easy: Leaflet can accept a [ [lat,lon],... ] which is exactly what Bing returns
    var vertices = result.routePath.line.coordinates;
    DIRECTIONS_LINE = L.polyline(vertices, DIRECTIONS_LINE_STYLE).addTo(MAP);

    // render the text directions and show them in a popup
    // three steps: the steps, the grand total, then sticking it into the DOM
    var directions = $('<div></div>');

    // 1: the steps
    var steps = result.routeLegs[0].itineraryItems;
    var table = $('<table></table>').appendTo(directions);
    for (var i=0, l=steps.length; i<l; i++) {
        var step     = steps[i];
        var text     = step.instruction.text;
        var distance = step.travelDistance.toFixed(1) + ' ' + DISTANCE_UNITS;
        if (i+1==steps.length) distance = ' ';

        var td1 = $('<td></td>').text(text);
        var td2 = $('<td></td>').addClass('rhs').text(distance);
        var tr = $('<tr></tr>').appendTo(table).append(td1).append(td2);
    }

    // 2: grand totals
    var total_distance = result.routeLegs[0].travelDistance; // numeric; in a moment we convert to friendly text
    total_distance = total_distance >= 5 ? Math.round(total_distance) : total_distance.toFixed(1);
    total_distance += ' ' + DISTANCE_UNITS;
    var total_time     = Math.round(result.routeLegs[0].travelDuration / 60); // minutes; in a moment we convert to friendly text
    if (total_time % 60 == 0) {
        total_time = (total_time/60) + ' hours';
    } else if (total_time > 60) {
        total_time = Math.floor(total_time/60) + ' hours, ' + (total_time%60) + ' minutes';
    }
    else {
        total_time = total_time + ' minutes';
    }
    $('<div></div>').text('Estimated total: ' + total_time + ' ' + '('+total_distance+')' ).appendTo(directions);

   // 3: show it
    $('#directions_results').append(directions);

    // epimetheus: the dialog has possibly changed width due to new content; reassert its position
    $('#dialog_directions').dialog('option', 'position', DIRECTIONS_DIALOG_POSITION);
}


function highlightMarker(marker) {
    // remove the highlight CSS class from all marker images
    $('#map_canvas img.leaflet-marker-icon').removeClass('leaflet-marker-highlight');

    // bail: if the marker we're to highlight is a null, it means we don't want to highlight anything
    if (! marker ) return;

    // add the highlight CSS class to this marker image
    // WARNING: _icon is not a Leaflet API, so this may break in the future
    var icondiv = $(marker._icon).addClass('leaflet-marker-highlight');
    if (marker.attributes.type == 'event') {
        icondiv.addClass('leaflet-marker-event').removeClass('leaflet-marker-place');
    } else {
        icondiv.removeClass('leaflet-marker-event').addClass('leaflet-marker-place');
    }
}
