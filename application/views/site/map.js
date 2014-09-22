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

    // map event: when a popup is opened highlight the corresponding marker; when a popup is closed un-highlight them
    // the popupopen event has an undocumentedc (non-API! DANGER) _source attribute to connect to the marker, so huzzah!
    MAP.on('popupopen', function (event) {
        highlightMarker(event.popup._source);
    }).on('popupclose', function (event) {
        highlightMarker(null);
    });

    // enable the geocoder so they can find their address
    $('#geocode_go').click(function () {
        geocodeAndZoom( $('#geocode').val() );
    });

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
        var dirlink = '<a href="javascript:void(0);" onClick="clickDirectionsLink(this);" data-lat="'+points[i].lat+'" data-lon="'+points[i].lng+'" data-title="'+ htmlEntities(points[i].name) +'">directions</a>';

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

    var params = { address:address };
    $.get(BASE_URL + 'site/geocode', params, function (result) {
        MAP.fitBounds([[result.s,result.w],[result.n,result.e]]);
    },'json');
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
// the link has data-lat= and data=lon= attributes, which form our intended destination --- fill in the directions form and then trigger it
function clickDirectionsLink(link) {
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
    // do we in fact have a destination set?
    if (! parseFloat( $('#directions_lat').val() )) return alert("No lat/lon available. How did this happen?");
    if (! parseFloat( $('#directions_lon').val() )) return alert("No lat/lon available. How did this happen?"); 

    var address = $('#directions_address').val() ;
    if (! address) return alert("Enter an address.");

    // clear previous results
    clearDirections();

    // run the address through the geocoder to get starting coordinates, and when that's done hand the coordionates back for directions
    address = address.replace('&', 'and');
    var params = { address:address };
    $.get(BASE_URL + 'site/geocode', params, function (result) {
        var end_lat   = $('#directions_lat').val();
        var end_lng   = $('#directions_lon').val();
        var start_lat = result.lat;
        var start_lng = result.lng;

        var params = { start_lat:start_lat, start_lng:start_lng, end_lat:end_lat, end_lng:end_lng };
        $.get(BASE_URL + 'site/directions', params, function (directions) {
            // if the directions object is empty, guess directions failed
            if (! directions || ! directions.w && ! directions.e) return alert('Could not find directions between these locations.');

            // lay down a pair of markers for the start and end
            DIRECTIONS_MARKER1 = L.marker([start_lat,start_lng], { clickable:false }).addTo(MAP);
            DIRECTIONS_MARKER2 = L.marker([end_lat,end_lng], { clickable:false }).addTo(MAP);

            // zoom to the bounding box of the route so the user can see the overview
            MAP.fitBounds([[directions.s,directions.w],[directions.n,directions.e]]);

            // draw the line onto the map
            DIRECTIONS_LINE = L.polyline(directions.vertices, DIRECTIONS_LINE_STYLE).addTo(MAP);

            // render the text directions and show them in a popup
            // three steps: the steps, the grand total, then sticking it into the DOM
            var target = $('<div></div>');

            // 1: the steps
            var table = $('<table></table>').appendTo(target);
            for (var i=0, l=directions.steps.length; i<l; i++) {
                var td1 = $('<td></td>').text( directions.steps[i].text );
                var td2 = $('<td></td>').addClass('rhs').text( directions.steps[i].distance );
                var tr  = $('<tr></tr>').appendTo(table).append(td1).append(td2);
            }

            // 2: grand totals
            $('<div></div>').text('Estimated total: ' + directions.total_time + ' ' + '('+directions.total_distance+')' ).appendTo(target);

            // 3: show it
            $('#directions_results').append(target);

            // epimetheus: the dialog has possibly changed width due to new content; reassert its position
            $('#dialog_directions').dialog('option', 'position', DIRECTIONS_DIALOG_POSITION);
        },'json');
    },'json');
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
