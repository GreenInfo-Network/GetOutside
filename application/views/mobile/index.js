///////////////////////////////////////////////////////////////////////////////////////////////////////////
///// GLOBAL SETTINGS
///////////////////////////////////////////////////////////////////////////////////////////////////////////

// map stuff, see initMap() and ; see also onLocationFound()
var MAP;            // L.Map
var BASEMAPS = {};  // dict mapping a name onto a L.tileLayer instance; keys will be:   terrain   topo   photo
var MAX_EXTENT;     // L.latLngBounds; used for geocode biasing and as our starting extent
var MARKERS;        // PruneClusterForLeaflet marker cluster system; empty but gets filled with markers when the user searches, see performSearchHandleResults()
var LOCATION;       // L.Marker indicating their current location

// should we auto-recenter the map when location is found?
var AUTO_RECENTER = false;

// when we go back to search results, should it be to Places or to Events?
// a strange hack here, using a global for such state, but it's what works between a Leaflet control and two panels that will continue to change
// see also initSearchResultPanels()
var SEARCH_RESULTS_SUBTYPE = '#page-search-results-places';

///////////////////////////////////////////////////////////////////////////////////////////////////////////
///// JAVASCRIPT EXTENSIONS
///////////////////////////////////////////////////////////////////////////////////////////////////////////

// IE8 lacks the indexOf to find where/whether an item appears in an array
if (!Array.prototype.indexOf) {
  Array.prototype.indexOf = function(elt /*, from*/) {
    var len = this.length >>> 0;

    var from = Number(arguments[1]) || 0;
    from = (from < 0) ? Math.ceil(from) : Math.floor(from);
    if (from < 0) from += len;

    for (; from < len; from++) {
        if (from in this && this[from] === elt) return from;
    }
    return -1;
  };
}


// "hello world".capfirst() = "Hello world"
String.prototype.capfirst = function() {
    return this.charAt(0).toUpperCase() + this.slice(1);
}


///////////////////////////////////////////////////////////////////////////////////////////////////////////
///// LEAFLET EXTENSIONS
///////////////////////////////////////////////////////////////////////////////////////////////////////////


// extend Leaflet: add to LatLng the ability to calculate the bearing to another LatLng
L.LatLng.prototype.bearingTo = function(other) {
    var d2r  = L.LatLng.DEG_TO_RAD;
    var r2d  = L.LatLng.RAD_TO_DEG;
    var lat1 = this.lat * d2r;
    var lat2 = other.lat * d2r;
    var dLon = (other.lng-this.lng) * d2r;
    var y    = Math.sin(dLon) * Math.cos(lat2);
    var x    = Math.cos(lat1)*Math.sin(lat2) - Math.sin(lat1)*Math.cos(lat2)*Math.cos(dLon);
    var brng = Math.atan2(y, x);
    brng = parseInt( brng * r2d );
    brng = (brng + 360) % 360;
    return brng;
};

L.LatLng.prototype.bearingWordTo = function(other) {
    var bearing = this.bearingTo(other);
    var bearingword = '';
    if      (bearing >=  22 && bearing <=  67) bearingword = 'NE';
    else if (bearing >=  67 && bearing <= 112) bearingword =  'E';
    else if (bearing >= 112 && bearing <= 157) bearingword = 'SE';
    else if (bearing >= 157 && bearing <= 202) bearingword =  'S';
    else if (bearing >= 202 && bearing <= 247) bearingword = 'SW';
    else if (bearing >= 247 && bearing <= 292) bearingword =  'W';
    else if (bearing >= 292 && bearing <= 337) bearingword = 'NW';
    else if (bearing >= 337 || bearing <=  22) bearingword =  'N';
    return bearingword;
};


///////////////////////////////////////////////////////////////////////////////////////////////////////////
///// PAGE RESIZING
///// this is not at all as automated as some folks would have you believe  ;)
///// changing to #page-map has race conditions, iPads lie about their width & height, ...
///////////////////////////////////////////////////////////////////////////////////////////////////////////

$(window).bind('orientationchange pageshow pagechange resize', resizeMap);

function resizeMap() {
    if (! $("#map_canvas").is(':visible') ) return;

    var viewportHeight = $(window).height();

    var page    = $(":jqmData(role='page'):visible");
    var header  = $(":jqmData(role='header'):visible");
    var content = $(":jqmData(role='content'):visible");
    var contentHeight = viewportHeight - header.outerHeight();
    $(":jqmData(role='content')").first().height(contentHeight);

    $("#map_canvas").height(contentHeight);
    if (MAP) MAP.invalidateSize();


  // afterthought: the info panel needs to have an explicit width set on the description text
  // since iOS doesn't handle calc() for setting the width
  var width = $(window).width() - 50 - 10;
  $('#map_infopanel > div').width(width);
}

function switchToMap(callback) {
    $.mobile.changePage('#page-map');
    if (callback) setTimeout(callback,750);
    setTimeout(resizeMap,750);
}



///////////////////////////////////////////////////////////////////////////////////////////////////////////
///// INITIALIZATION
///////////////////////////////////////////////////////////////////////////////////////////////////////////

$(document).ready(function () {
    // go ahead and render the page elements, so we don't fall victim to lazy loading
    $('div[data-role="page"]').page();

    // now various sub-initializations
    initMap();
    initMapInfoPanel();
    initSearchForms();
    initSearchResultPanels();
});

function initSearchForms() {
    // enable the 2 "GO" buttons on the home (Search) page
    // one of them loads the whole map (clearing the filters) and switches over to it
    // the other is a shortcut to clear filters, set My Current Location, then click go
    $('#page-home button[name="search-browse-map"]').tap(function () {
        performBrowseMap();
    });
    $('#page-home a[name="search-everything"]').tap(function () {
        // whoa there! if they don't ev/en have a valid location this is about to get ugly; skip out
        if (! LOCATION.getLatLng().lat) {
            alert("Still waiting for your location.");
            return false;
        }

        // reset all search options, set to GPS mode, and submit
        // with the option to (after results had) proceed to the Map panel instead of the Results panel
        $.mobile.changePage('#page-search-results-places');
        setSearchFiltersToDefault();
        $('#page-search select[name="location"]').val('gps').selectmenu('refresh').trigger('change');
        performSearch();
    });

    // DOM handler: when the Address Type changed to address, show the address box; when it's not address, hide the box
    // then specifically force it to GPS option (Firefox caches controls selections) to hide the address box
    $('#page-search select[name="location"]').change(function () {
        switch ( $(this).val() ) {
            case 'address':
                $('#address-container').show();
                break;
            case 'gps':
                $('#address-container').hide();
                break;
        }
    }).val('gps').trigger('change');

    // Search Settings has a Show Results button
    // this should in fact switch over to the Search Results page as normal... but then perform a search
    // BUT... the joys of replacing submit buttons with nav hyperlinks...
    //        check that there's an address entered OR a location found, so we can "retrn false" and cancel navigation altogether
    $('#page-search a[href="#page-search-results-places"]').tap(function () {
        var $form = $('#page-search form');
        switch ( $form.find('select[name="location"]').val() ) {
            case 'gps':
                if (! LOCATION.getLatLng().lat) {
                    alert("Still waiting for your location.");
                    return false;
                }
                break;
            case 'address':
                if (! $form.find('input[name="address"]').val() ) {
                    alert("Please enter an address.");
                    return false;
                }
                break;
            default:
                break;
        }

        performSearch();
    });

    // Search Settings has this weekdays selector, as well as an option for Today vs Upcoming Week
    // spec is for these to be checkboxes,though they act kinda like radioboxes (kinda)
    // in no case may none of them be checked; if that happens select the One Week option
    // and THIS is why something elegant like Angular just doesn't cut it; we end up doing intricate DOM manipulation anyway...
    var weekdaypickers    = $('#page-search input[name="weekday"]');
    var howmanydaypickers = $('#page-search input[name="eventdays"]');
    weekdaypickers.change(function () {
        if ( $(this).is(':checked') ) {
            howmanydaypickers.removeAttr('checked').checkboxradio('refresh');
        } else {
            // none checked at all from the two lists? check a default
            if (! $('#page-search input[name="weekday"]:checked').length && ! $('#page-search input[name="eventdays"]:checked').length) {
                $('#page-search input[name="eventdays"][value="30"]').prop('checked',true).checkboxradio('refresh');
            }
        }
    });
    howmanydaypickers.change(function () {
        if ( $(this).is(':checked') ) {
            weekdaypickers.removeAttr('checked').checkboxradio('refresh');
            howmanydaypickers.not( $(this) ).removeAttr('checked').checkboxradio('refresh');
        } else {
            // none checked at all from the two lists? check a default
            if (! $('#page-search input[name="weekday"]:checked').length && ! $('#page-search input[name="eventdays"]:checked').length) {
                $('#page-search input[name="eventdays"][value="30"]').prop('checked',true).checkboxradio('refresh');
            }
        }
    });

    // Search Settings has an Age selector, which allows multiple selections UNLESS they pick All Ages (0) in which case it must unselect the others
    // and in no case must none of them be checked; if none are checked check the one by default
    $('#page-search input[name="agegroup"][value="0"]').change(function () {
        if ( $(this).is(':checked')) {
            $('#page-search input[name="agegroup"]').not($(this)).removeAttr('checked').checkboxradio('refresh');
        }
    });
    $('#page-search input[name="agegroup"][value!="0"]').change(function () {
        if ( $(this).is(':checked')) {
            $('#page-search input[name="agegroup"][value="0"]').removeAttr('checked').checkboxradio('refresh');
        }
    });
    $('#page-search input[name="agegroup"]').change(function () {
        if (! $('#page-search input[name="agegroup"]:checked').length) {
            $('#page-search input[name="agegroup"][value="0"]').prop('checked',true).checkboxradio('refresh');
        }
    });

    // Search Settings has an Gender selector, which allows only one selection like a radiobox, but spec is that it must be checkboxes...
    // never allow none of them to be checked; if that happens, select the default (0)
    $('#page-search input[name="gender"]').change(function () {
        $('#page-search input[name="gender"]').not($(this)).removeAttr('checked').checkboxradio('refresh');

        if (! $(this).is(':checked') && ! $('#page-search input[name="gender"]:checked').length ) {
            $('#page-search input[name="gender"][value="0"]').prop('checked',true).checkboxradio('refresh');
        }
    });

    // and since Firefox loves to cache controls (checkboxes)
    // explicitly uncheck all checkboxes in the search setttings,, then set these defaults
    setSearchFiltersToDefault();
}

function initMap() {
    // define that biasing box for geocoding, which is also our default starting view
    MAX_EXTENT = L.latLngBounds([[START_N,START_E],[START_S,START_W]]);

    // define the basemaps
    BASEMAPS['googleterrain']     = new L.Google('TERRAIN', { zIndex:-1 });
    BASEMAPS['googlestreets']     = new L.Google('ROADMAP', { zIndex:-1 });
    BASEMAPS['googlesatellite']   = new L.Google('HYBRID', { zIndex:-1 });
    if (BING_API_KEY) BASEMAPS['bingstreets']       = new L.BingLayer(BING_API_KEY, { zIndex:-1, type:'Road' });
    if (BING_API_KEY) BASEMAPS['bingsatellite']     = new L.BingLayer(BING_API_KEY, { zIndex:-1, type:'AerialWithLabels' });
    if (BASEMAP_TYPE == 'xyz') BASEMAPS['xyz']      = L.tileLayer(BASEMAP_XYZURL, { zIndex:-1 });

    // load the map and its initial view
    // tip: the minZoom is hardcoded here, but the maxZoom of the map is changed in selectBasemap() to suit Google Terrain's idiosyncracies
    MAP = new L.Map('map_canvas', {
        attributionControl: false,
        zoomControl: true,
        dragging: true,
        closePopupOnClick: false,
        crs: L.CRS.EPSG3857,
        minZoom: 6
    }).fitBounds(MAX_EXTENT);
    selectBasemap(BASEMAP_TYPE);

    // define the marker for our location
    // the marker is loaded from a data endpoint and the width & height are in HTML, since these are dynamically set by the admin UI
    var icon = L.icon({
        iconUrl: BASE_URL + 'mobile/image/marker_gps',
        iconSize: [GPS_MARKER_WIDTH, GPS_MARKER_HEIGHT]
    });
    LOCATION  = L.marker([0,0], { clickable:false, draggable:false, icon:icon }).addTo(MAP);

    // set up the event handler when our location is detected, and start continuous tracking
    // loose binding with an anonymous function, for easier debugging (can replace the function in the console)
    MAP.on('locationfound', function (event) { onLocationFound(event); });
    //MAP.on('locationerror', function (error) { onLocationError(error); });
    MAP.locate({ enableHighAccuracy:true, watch:true });

    // add some Controls, including our custom ones which are simply buttons; we use a Control so Leaflet will position and style them
    L.control.scale({ metric:false }).addTo(MAP);
    new L.controlCustomButtonPanel().addTo(MAP);
    // TFA: add jQuery mobile icons to leaflet custom icon panel (L.controlCustomButtonPanel)
    // var target = $('.leaflet-custombutton-search');
    // var icon = $('<a>', {
    //     href: '#'
    // });
    // icon.attr('data-role','button');
    // icon.attr('data-icon','search');
    // icon.attr('data-iconpos', 'notext');
    // icon.appendTo(target);

    // now add the empty MARKERS LayerGroup
    // this will be loaded with markers when a search is performed or when they pick Browse The Map
    MARKERS = new PruneClusterForLeaflet();
    MAP.addLayer(MARKERS);

    // now the Map Settings panel
    // this is relatively simple, in that there's no tile caching, seeding, database download, ...
    // then, go ahead and "check" the default basemap option
    var cbs = $('#panel-map-settings input[type="radio"][name="basemap"]').change(function () {
        var which = $('#panel-map-settings input[type="radio"][name="basemap"]:checked').prop('value');
        selectBasemap(which);
    });
    cbs.removeAttr('checked').filter('[value="'+BASEMAP_TYPE+'"]').prop('checked','checked').checkboxradio('refresh');

    // on the Results pages, the Map buttons; these should go to the map but also center on the best location
    // that being either LOCATION or else the search coordinates
    // this is a hack to get around problems of Leaflet in a DIV that's hidden at the moment; fitBounds() et al don't work well when the DIV isn't visible, so the map is likely zoomed to the whole world
    $('div.page-results a[href="#page-map"]').click(function (event) {
        // prevent the click from happening, intercept it with our own way to change to the map page
        event.preventDefault();
        switchToMap(function () {
            if (AUTO_RECENTER) {
                zoomToPoint( LOCATION.getLatLng() );
            } else {
                var lat = $('#page-search input[name="lat"]').val();
                var lng = $('#page-search input[name="lng"]').val();
                zoomToPoint(L.latLng([lat,lng]));
            }
        });
    });

}

function initMapInfoPanel() {
    // the slide-in panel over the map, showing info about whatever was clicked
    // this is primarily opened and populated by the clickMarker_ family of functions, so see them for more info

    // buttons in the info panel: close this slideout
    // this should also mean to unhighlight all markers, since we're not focusing them anymore
    // this may prove unreliable some day if we invent some other way to cause the panel to become hidden, e.g. swipe event, tap on map, ...
    $('#map_infopanel > a[data-icon="delete"]').click(function () {
        $('#map_infopanel').hide();
        highlightMarker(null);
    });

    // addendum: when someone clicks the map itself, the spidered out clusters will collapse, possibly taking your highlighted marker with it
    // to prevent confusion, clicking the map should also hide the infopanel, so they can't somehow have the panel open and nothing glowing
    MAP.on('click', function () {
        $('#map_infopanel > a[data-icon="delete"]').click();
    });

    // buttons in the info panel: Directions gation and More Info
    // Directions already works by having a href assigned by updateNavigationLinkFromMarker() which itself is called from the clickMarker_ family of functions
    // but this is a Samsung hack: Samsung ignores target=_blank and opens in current tab
    //      workaround: intercept these taps and turn them into window.open()
    // funny, as that href assignment thing was a workaround for window.open() not working...
    $('#map_infopanel a[target="_blank"]').click(function () {
        window.open( $(this).prop('href') );
        return false;
    });
}

function initSearchResultPanels() {
    // requirement: if the last Search Results-related panel the person visited was Places/Events, then the "go to result list" control on the map page must go to that same panel
    // meaning that the control needs to be able to know which panel you last visited (may not be your -1 history)
    // see also leaflet-custombutton-list in leaflet.custommobilecontrols.js
    $('.search-results-navbar a').tap(function () {
        SEARCH_RESULTS_SUBTYPE = $(this).prop('href');
    });

    // the Show On Map buttons on the result panels, should indeed go to the map...then should zoom to the extent of search results
    // the MARKERS were populated as part of renderResultsToMap() in turn a part of performSearchHandleResults()
    // side effect: hide the info panel since we've left the map and are now zooming around, so it's surely not relevant anymore
    $('.custom-navbar a[href="#page-map"]').tap(function () {
        $('#map_infopanel').hide();

        switchToMap(function () {
            MARKERS.FitBounds();
        });
        return false;
    });
}


///////////////////////////////////////////////////////////////////////////////////////////////////////////
///// FUNCTIONS
///////////////////////////////////////////////////////////////////////////////////////////////////////////

function is_ios() {
    return -1 != navigator.userAgent.indexOf('iOS');
}
function is_android() {
    return -1 != navigator.userAgent.indexOf('Android');
}

function selectBasemap(which) {
    // tip: can't use .addTo() nor .bringToBack() with these non0standard layer types (Google); instead, z-indexes are used, etc
    for (var i in BASEMAPS) MAP.removeLayer(BASEMAPS[i]);
    MAP.addLayer( BASEMAPS[which] );

    // now hacks based on the selected basemap, e.g. Google Terrain requires a different maxZoom AND handling for us already being in too close
    // this doesn't happen in desktop version, because there's no basemap picker there; but it was important for mobile to have one
    var maxZoom = 18;
    switch (which) {
        case 'googleterrain':
            maxZoom = 15;
            break;
        default:
            break;
    }

    // set the maxZoom on the map, then zoom to that if we're in too far
    MAP.options.maxZoom = maxZoom;
    if ( MAP.getZoom() > maxZoom) MAP.setZoom(maxZoom);
}

function setSearchFiltersToDefault() {
    $('#page-search input[type="checkbox"]').removeAttr('checked').checkboxradio('refresh');
    $('#page-search input[name="agegroup"][value="0"]').prop('checked',true).checkboxradio('refresh');
    $('#page-search input[name="eventdays"][value="30"]').prop('checked',true).checkboxradio('refresh');
    $('#page-search input[name="gender"][value="0"]').prop('checked',true).checkboxradio('refresh');
}

function onLocationFound(event) {
    // first and easiest: update the location marker
    var first_time = ! LOCATION.getLatLng().lat;
    LOCATION.setLatLng(event.latlng);

    // on the Map and Search pages, there are notifications that they're outside the supported area; show/hide these, depending on whether they're in the supported area
    // also if they're outside, turn off auto-centering and zoom to the max extent BUT ONLY IF this is our first time finding the location
    // otherwise we get an annoying behavior that we have zoomed in and are looking at some other area... then it changes zoom again every few seconds!
    if (MAX_EXTENT.contains(event.latlng) ) {
        $('.outside_area').hide();
    } else {
        $('.outside_area').show();
        autoCenterOff();
        if (first_time) MAP.fitBounds(MAX_EXTENT);
    }

    // then re-center onto the new location, if we have auto-centering enabled
    if (AUTO_RECENTER) {
        MAP.panTo(event.latlng);
        if (MAP.getZoom() < 14) MAP.setZoom(14);
    }

    // update distance and bearing listings for Places and Events
    updateEventsAndPlacesDistanceReadouts();
}

function autoCenterToggle() {
    AUTO_RECENTER ? autoCenterOff() : autoCenterOn();
}
function autoCenterOn() {
    // if their LOCATION is still 0,0 then something's screwy (location turned off?) so refuse to turn on GPS tracking
    if (! LOCATION.getLatLng().lat) {
        alert("Could not find your location.");
        autoCenterOff();
        return;
    }

    AUTO_RECENTER = true;
    $('#map_canvas div.leaflet-custombutton-gps').addClass('active');

    // and now that we want auto-centering, do an auto-center now
    zoomToCurrentLocation();
}
function autoCenterOff() {
    AUTO_RECENTER = false;
    $('#map_canvas div.leaflet-custombutton-gps').removeClass('active');
}

function zoomToCurrentLocation() {
    var latlng = LOCATION.getLatLng();
    var buffer = 0.1; // about 5-6 miles, a reasonable distance

    var w = latlng.lng - buffer;
    var s = latlng.lat - buffer;
    var e = latlng.lng + buffer;
    var n = latlng.lat + buffer;
    MAP.fitBounds([[n,e],[s,w]]);
}

function zoomToPoint(latlng) {
    var buffer = 0.025; // about 1-2 miles, a reasonable distance

    var w = latlng.lng - buffer;
    var s = latlng.lat - buffer;
    var e = latlng.lng + buffer;
    var n = latlng.lat + buffer;
    MAP.fitBounds([[n,e],[s,w]]);
}

function zoomToMaxExtent() {
    MAP.fitBounds(MAX_EXTENT);
}

function onLocationError(error) {
}

function performBrowseMap() {
    // reset all search options, set to GPS mode and submit the START_X and START_Y coords which are the center of the supported area, then submit that search
    // with the option to (after results had) proceed to the Map panel instead of the Results panel
    setSearchFiltersToDefault();
    $('#page-search select[name="location"]').val('gps').selectmenu('refresh').trigger('change');
    $('#page-search input[name="lat"]').val(START_Y);
    $('#page-search input[name="lng"]').val(START_X);

    // now switch to the map and zoom to the supported area
    // zooming in on your own location, is against the spirit of Browse Map... and has some awful timing issues
    // if they have AUTO_RECENTER enabled, they'll zoom on their own location in a moment anyway when a locationfound event happens
    switchToMap(function () {
        MAP.fitBounds(MAX_EXTENT);
        performSearchReally({ 'afterpage':'#page-map' });
    });
}

function getMarkerById(id) {
    // convenience function: given an ID from the fetchdata output, find the corresponding Marker within the MARKERS layergroup
    // if it doesn't exist, null is returned
    var lx = MARKERS.GetMarkers();
    var marker;
    for (var i=0, l=lx.length; i<l; i++) {
        // a simple Place has a simple ID
        if (id == lx[i].data.attributes.id) { marker = lx[i]; break; }

        // a LocationEvent marker has the eventlocation-ID under the location sub-attribute
        if (lx[i].data.attributes.location && id == lx[i].data.attributes.location.id) { marker = lx[i]; break; }
    }
    return marker ? marker : null;
}

function performSearch() {
    // validation and checking: if they picked an address search, they need to have given an address
    // further, we can't really search with an address, but need to geocode first
    var $form = $('#page-search form');
    switch ( $form.find('select[name="location"]').val() ) {
        case 'gps':
            // Near My search: fill in the lat & lng from their last known LOCATION
            // then go ahead and perform a search
            var latlng = LOCATION.getLatLng();
            $form.find('input[name="lat"]').val( latlng.lat );
            $form.find('input[name="lng"]').val( latlng.lng );
            performSearchReally();
            break;
        case 'address':
            // Address search: do an async geocoder call
            // have it fill in the lat & lng from whatever it finds, then it will perform the search
            var address = $form.find('input[name="address"]').val();
            if (! address) alert('Enter an address.');
            performSearchAfterGeocode(address);
            break;
        default:
            break;
    }
}

function performSearchReally(options) {
    // options? surprise! Browse Map should perform a search but should then go to Map instead of Results
    //      afterpage       jQuery UI selector for a page element, will go to that page after search is done
    if (typeof options == 'undefined') options = {};
    if (typeof options.afterpage == 'undefined') options.afterpage = SEARCH_RESULTS_SUBTYPE;

    // compose params, including both the form itself (simple address) and the Settings (checkboxes from a different page)
    // this is why we can't use serialize()
    var params = {};
    params.lat          = $('#page-search input[name="lat"]').val();
    params.lng          = $('#page-search input[name="lng"]').val();
    params.eventdays    = $('#page-search input[name="eventdays"]:checked').val();
    params.categories   = [];
    params.weekdays     = [];
    params.gender       = [];
    params.agegroup     = [];
    $('#page-search input[name="categories"]:checked').each(function () { params.categories.push($(this).prop('value')); });
    $('#page-search input[name="weekdays"]:checked').each(function () { params.weekdays.push($(this).prop('value')); });
    $('#page-search input[name="gender"]:checked').each(function () { params.gender.push($(this).prop('value')); });
    $('#page-search input[name="agegroup"]:checked').each(function () { params.agegroup.push($(this).prop('value')); });
    if (params.agegroup.length == 1 && params.agegroup[0] == '0')   delete(params.agegroup);
    if (params.gender.length   == 1 && params.gender[0] == '0')     delete(params.gender);

    // empty the results panels immediately so we see neither old results nor "nothing matched" messages
    // hide the map info panel, since that marker won't exist in a moment
    // both of these are done implicitly in the handle-results sub-handlers, but it's cosmetically pleasing to hide them now
    $('#page-search-results-places-list').empty();
    $('#page-search-results-events-list').empty();
    $('#map_infopanel').hide();

    // .. and send off
    $.mobile.loading('show', {theme:"a", text:"Searching", textonly:false, textVisible:true });
    $.post(BASE_URL + 'mobile/fetchdata', params, function (reply) {
        $.mobile.loading('hide');
        $.mobile.changePage(options.afterpage);
        performSearchHandleResults(reply);
    }, 'json');
}

function performSearchAfterGeocode(address) {
    var params = { address:address };
    $.get(BASE_URL + 'site/geocode', params, function (result) {
        $('#page-search input[name="lat"]').val( result.lat );
        $('#page-search input[name="lng"]').val( result.lng );
        performSearchReally();
    },'json');
}

function performSearchHandleResults(reply) {
    // fudge attributes!
    // for the info panel on the map, but perhaps others, we want an easy-to-use attribute to show a comma-joined list of categories
    for (var i=0, l=reply.places.length; i<l; i++) {
        reply.places[i].categorylist = reply.places[i].categories.join(", ");
    }

    // assign the results into the listing components (listviews, map) ...
    $('#page-search-results-places-list').data('rawresults', reply.places);
    $('#map_canavs').data('rawresults', reply.places);
    $('#page-search-results-events-list').data('rawresults', reply.events);

    // .. then have them re-render
    // the listing renderers check for 0 length and create a dummy "Nothing Found" item in the lists
    // tip: show and hide don't work with JQM tab content; it makes the element actually visible despite the tab selection
    renderEventsList();
    renderPlacesList();
    renderResultsToMap();

    // epimetheus: same as onLocationFound() does, update distance and bearing listings for Places and Events
    updateEventsAndPlacesDistanceReadouts();
}

function renderResultsToMap() {
    // start by closing the infopanel
    // we can't see it, but when they go to the map they will be able to and it's for a marker that may not even exist anymore
    $('#map_infopanel').hide();

    // the factory method to generate the markers; required for any click or popup handling (despite the shorter syntax documented at https://github.com/SINTEF-9012/PruneCluster)
    // these PruneCluster.Marker instances use PrepareLeafletMarker to generate click event handlers; the default behavior supports only popups and even that requires PrepareLeafletMarker
    // kinda goofy to override these functions here every time we load new points, but this places it where we'll be in the code, keeps it in-sight and in-mind
    MARKERS.PrepareLeafletMarker = function(leafletMarker, data){
        leafletMarker.setIcon(data.icon);
        leafletMarker.attributes = data.attributes;
        leafletMarker.clicker    = data.clicker;
        leafletMarker.on('click',function () {
            this.clicker(this);
        });
    }
    MARKERS.BuildLeafletClusterIcon = function(cluster) {
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

    // start by emptying the markers from the map
    MARKERS.RemoveMarkers();

    // now loop over Places and Events in the result lists, generating these icons to be massaged using the methods above
    // note the hardcoded category numbers when generating the markers; used in BuildLeafletClusterIcon() to count up place/event/both; arbitrary, as long as it's consistent
    $.each( $('#page-search-results-places-list').data('rawresults') , function () {
        // "this" really is just attributes, so here we are
        var attributes = this;

        // make up the icon
        var icon = L.icon({ iconUrl:BASE_URL+'mobile/image/marker_place', iconSize:[PLACE_MARKER_WIDTH,PLACE_MARKER_HEIGHT] });

        // which callback would we use for a click?  this is why we use named functions for even seemingly-simple behaviors, allows us to abstract this stuff from ad-hoc factory methods
        var clicker = clickMarker_Place;

        // finally
        // create the marker and add it to the clusterer
        var marker = new PruneCluster.Marker(attributes.lat, attributes.lng, { icon:icon, clicker:clicker, attributes:attributes });
        marker.category = 0;
        MARKERS.RegisterMarker(marker);
    });

    $.each( $('#page-search-results-events-list').data('rawresults') , function () {
        if (! this.locations) return; // an event with no Locations, doesn't need to be on the map
        var event = this;

        // now go over locations, these are the real markers
        // they have attributes indicating BOTH the event details and their own location details
        $.each(this.locations, function () {
            var location   = this;
            var attributes = { location:location, event:event };

            // make up the icon
            var icon = L.icon({ iconUrl:BASE_URL+'mobile/image/marker_event', iconSize:[EVENT_MARKER_WIDTH,EVENT_MARKER_HEIGHT] });

            // which callback would we use for a click?  this is why we use named functions for even seemingly-simple behaviors, allows us to abstract this stuff from ad-hoc factory methods
            var clicker = clickMarker_EventLocation;

            // finally
            // create the marker and add it to the clusterer
            var marker = new PruneCluster.Marker(location.lat, location.lng, { icon:icon, clicker:clicker, attributes:attributes });
            marker.category = 1;
            MARKERS.RegisterMarker(marker);
        });
    });

    // done, refresh the marker rendering  (though it may nnot in fact be visible if the map is hidden)
    MARKERS.RedrawIcons();
}

function renderPlacesList() {
    var $target = $('#page-search-results-places-list').empty();
    var items   = $target.data('rawresults');

    // bail condition: 0 items means we need to display only 1 item: Nothing Found
    if (! items.length) {
        $('<li></li>').html('No places matched your search.<br/>Use the Search Options to change your filters or the search location.').appendTo($target);
        $target.listview('refresh');
        return;
    }

    for (var i=0, l=items.length; i<l; i++) {
        var item = items[i];
        var li   = $('<li></li>').data('rawresult',item).attr('data-id',item.id).appendTo($target);


        // part 0: the plus-minus icon; not necessary since it's the title that does the toggling, but looks nice
        var plusminus = $('<i class="fa fa-lg fa-plus"></i>').appendTo(li);

        // part 1: the label of name and distance/heading
        var label = $('<div></div>').addClass('ui-btn-text').appendTo(li);
        $('<span></span>').addClass('ui-li-heading').text(item.name).appendTo(label);
        $('<span></span>').addClass('ui-li-count').text(' ').appendTo(label); // the distance & bearing aren't loaded yet; see onLocationFound()

        // part 2: the details: list of categories, list of activities, ...
        // then the Go To Map button, which for styling purposes is actually in a listview
        var details = $('<div></div>').addClass('search-result-details').appendTo(li).hide();
        $('<div></div>').addClass('search-result-details-place-categories').text( item.categories.join(' | ') ).appendTo(details);

        var sublist = $('<ul></ul>').attr('data-role','listview').attr('data-inset','true').appendTo(details);
        var link = $('<a></a>').addClass('maplink').prop('href','javascript:void(0);').html('&nbsp; Go To Map').data('markerid',item.id).data('lat',item.lat).data('lng',item.lng);
        $('<li></li>').attr('data-icon','map').attr('data-iconpos','left').append(link).appendTo(sublist);
        link.tap(function () {
            var markid = $(this).data('markerid');
            switchToMap(function () {
                handleResultListClick(markid,'Place');
            });
        });

        // part 3: activities
        // if this Place has activities, create a inset listview (a second listview)
        if (item.activities) {
            // make an assoc to guarantee uniqueness, each name having a list of days-and-times
            // see, the same activities may have different days (swimming is 9-10 MWF, 11-12 RF, 12-6 SaSu, ...)
            var activities = {};
            for (var ai=0, al=item.activities.length; ai<al; ai++) {
                var actname  = item.activities[ai].name;
                var actstart = item.activities[ai].start;
                var actend   = item.activities[ai].end;
                var actdays  = item.activities[ai].days;
                if (! activities[actname]) activities[actname] = [];
                activities[actname].push({ start:actstart, end:actend, days:actdays });
            }

            // make a list of the keys of the activities listing, and sort it; thus we can alphabetically iterate
            // remember, assocs are inherently unsorted and if they happen to come out alphabetically it was purely coincidental
            var activity_names = [];
            for (var act in activities) activity_names.push(act);
            activity_names.sort();

            // and finally compose the listview: one LI-row for each unique-named activity
            var sublist = $('<ul></ul>').attr('data-role','listview').attr('data-inset','true').addClass('search-results-places-activities').appendTo(details);
            for (var ai=0, al=activity_names.length; ai<al; ai++) {
                var actname  = activity_names[ai];
                var actlist  = activities[actname];

                var inset = $('<li></li>').appendTo(sublist);
                $('<div></div>').addClass('ui-btn-text').text(actname).appendTo(inset);
                for (var tai=0, tal=actlist.length; tai<tal; tai++) {
                    var days  = actlist[tai].days;
                    var start = actlist[tai].start;
                    var end   = actlist[tai].end;

                    $('<div></div>').addClass('ui-btn-text').html(days + ' &nbsp;&nbsp; ' + start + ' - ' + end).appendTo(inset);
                }
            }
        }

        // super glue: clicking the label toggles the visibility of the details
        plusminus.tap(function () {
            var button  = $(this).siblings('div.ui-btn-text').tap();
        });
        label.tap(function () {
            var details = $(this).siblings('div.search-result-details');
            var button  = $(this).siblings('i.fa');
            if (details.is(':visible')) {
                details.hide();
                button.removeClass('fa-minus').addClass('fa-plus');
            }
            else {
                details.show();
                button.removeClass('fa-plus').addClass('fa-minus');
            }
        });
    }

    $target.listview('refresh');
    $target.find('ul').listview();
    $target.find('a.maplink').removeClass('ui-btn-icon-right').addClass('ui-btn-icon-left'); // hack: JQM forces the icons to right, ignoring my data-iconpos
}

function renderEventsList() {
    var $target = $('#page-search-results-events-list').empty();
    var items   = $target.data('rawresults');

    // bail condition: 0 items means we need to display only 1 item: Nothing Found
    if (! items.length) {
        $('<li></li>').html('No events matched your search.<br/>Use the Search Options to change your filters or the search location.').appendTo($target);
        $target.listview('refresh');
        return;
    }

    for (var i=0, l=items.length; i<l; i++) {
        var item = items[i];
        var li   = $('<li></li>').data('rawresult',item).attr('data-id',item.id).appendTo($target);

        // part 0: the plus-minus icon; not necessary since it's the title that does the toggling, but looks nice
        var plusminus = $('<i class="fa fa-lg fa-plus"></i>').appendTo(li);

        // part 1: the label of name and date-time
        var label = $('<div></div>').addClass('ui-btn-text').appendTo(li);
        $('<span></span>').addClass('ui-li-heading').text(item.name).appendTo(label);
        $('<div></div>').addClass('ui-li-desc').text(item.datetime).appendTo(label);

        // part 2: the details: More Info link, list of locations
        var details = $('<div></div>').addClass('search-result-details').appendTo(li).hide();

        if (item.url) {
            var link = $('<a></a>').prop('target','_blank').addClass('search-results-moreinfo-hyperlink').prop('href',item.url).html('More Info');
            $('<div></div>').addClass('ui-li-desc').append(link).appendTo(details);
        }

        // each entry showing the location by name; clicking it goes to the map
        if (item.locations) {
            var sublist = $('<ul></ul>').attr('data-role','listview').attr('data-inset','true').appendTo(details);
            for (var ai=0, al=item.locations.length; ai<al; ai++) {
                var markerid    = item.locations[ai].id;
                var loctitle    = item.locations[ai].title;
                var locsubtitle = item.locations[ai].subtitle;
                var loclat      = item.locations[ai].lat;
                var loclng      = item.locations[ai].lng;

                var titlehtml = '&nbsp; ' + loctitle;
                var link      = $('<a></a>').addClass('maplink').prop('href','javascript:void(0);').html(titlehtml).data('markerid',markerid).data('lat',loclat).data('lng',loclng);
                var dislabel  = $('<span></span>').addClass('ui-li-count').text(' ').appendTo(label); // ignores CSS in files, must add it here
                $('<li></li>').attr('data-icon','map').attr('data-iconpos','left').append(link).append(dislabel).appendTo(sublist);

                link.tap(function () {
                    var markid = $(this).data('markerid');
                    switchToMap(function () {
                        handleResultListClick(markid,'EventLocation');
                    });
                });
            }
        }

        // super glue: clicking the label toggles the visibility of the details
        plusminus.tap(function () {
            var button  = $(this).siblings('div.ui-btn-text').tap();
        });
        label.tap(function () {
            var details = $(this).siblings('div.search-result-details');
            var button  = $(this).siblings('i.fa');
            if (details.is(':visible')) {
                details.hide();
                button.removeClass('fa-minus').addClass('fa-plus');
            }
            else {
                details.show();
                button.removeClass('fa-plus').addClass('fa-minus');
            }
        });
    }

    // hack (specific to Samsung?)
    // intercept all of the hyperlinks on the Events results panel, and have them explicitly call window.open instead of using target=_blank
    $('#page-search-results-events-list a.search-results-moreinfo-hyperlink').click(function () {
        window.open( $(this).prop('href') );
        return false;
    });

    // ready, done, refresh!
    $target.listview('refresh');
    $target.find('ul').listview();
    $target.find('a.maplink').removeClass('ui-btn-icon-right').addClass('ui-btn-icon-left'); // hack: JQM forces the icons to right, ignoring my data-iconpos
}

function updateEventsAndPlacesDistanceReadouts() {
    // prep
    // figure up the origin for the distance & bearing; whatever was our last search
    // if they somehow got here and never did a search of any sort, just bail; should never happen
    var origin = L.latLng([ $('#page-search input[name="lat"]').val() , $('#page-search input[name="lng"]').val() ]);
    if (! origin) return;

    // part 1
    // Events list gets distance and bearing... for anything which in fact has a lat & lon
    // unlike the Places, a small minority of Event entries will have Places, so it's more efficient to iterate over the distance readout elements and then find the containing listview LIs for the event and location
    var $target   = $('#page-search-results-events-list');
    var $readouts = $target.find('span.ui-li-count');
    $readouts.each(function () {
        // construct a L.LatLng and use our handy functions for distance and bearing, relative to our last search location
        var eventlocation = $(this).siblings('a');
        var latlng       =  L.latLng([ eventlocation.data('lat'),eventlocation.data('lng') ]);
        var meters        = origin.distanceTo(latlng);
        var direction     = origin.bearingWordTo(latlng);
        var readout;
        switch (DISTANCE_UNITS) {
            case 'mi':
                readout = (meters / 1609).toFixed(1) + ' ' + 'mi' + ' ' + direction;
                break;
            case 'km':
                readout = (meters / 1000).toFixed(1) + ' ' + 'km' + ' ' + direction;
                break;
        }

        // load the text field
        $(this).text(readout);

        // unlike Places we don't sort by distance cuz they're sorted by ending time
        // so we're done here
    });

    // part 2
    // Places list gets distance and bearing, but then gets sorted by distance
    var $target   = $('#page-search-results-places-list');
    var $children = $target.children('li');
    $children.each(function () {
        var raw = $(this).data('rawresult');
        if (! raw) return; // e.g. page startup when nothing has been found, the only LI is "Nothing to show" and that won't have a location readout

        // construct a L.LatLng and use our handy functions for distance and bearing, relative to our last search location
        var latlng    = L.latLng([ raw.lat,raw.lng ]);
        var meters    = origin.distanceTo(latlng);
        var direction = origin.bearingWordTo(latlng);
        var readout;
        switch (DISTANCE_UNITS) {
            case 'mi':
                readout = (meters / 1609).toFixed(1) + ' ' + 'mi' + ' ' + direction;
                break;
            case 'km':
                readout = (meters / 1000).toFixed(1) + ' ' + 'km' + ' ' + direction;
                break;
        }

        // load the text field and also save the meters to a data attribute, for distance sorting in a moment
        $(this).data('distance_meters',meters);
        $(this).find('span.ui-li-count').text(readout);
    });

    // sort the listview by distance
    $children.tsort({data:'distance_meters'});
}

// an attempt to abstract out clicks on the result lists, and look up a marker cluster and pick out the individual marker, so it can have a click triggered upon it
// this calls getMarkerById() which returns a prunecluster marker, which is not the same as a L.marker
// problem: the clusterer does not contain L.markers with event handlers and the like, but their own internal model
//          but client demand is that the marker be clicked (even though it doesn't exist) so it triggers the info slideout, gets highlighted, etc. same as a real mouse click
function handleResultListClick(markid,type) {
    // start by fetching the prunecluster marker data
    // then zoom to that point; this would usually result in a re-clustering, unless by some slim chance your target point is at the same location as your current map view...
    // tip: do not use zoomToPoint() as that doesn't necessarily use the tightest possible zoom, so "clicking" the cluster below may not spider, but simply zoom in again without triggering the spidering
    var marker = getMarkerById(markid);
    MAP.setView(marker.position,MAP.options.maxZoom);

    // wait a second for the reclustering to complete...
    setTimeout(function () {
        // find the cluster which contains this marker, and trigger a click on the underlying marker so it spiders out
        var cluster;
        var cs = MARKERS._objectsOnMap;
        for (var ci=0, cl=cs.length; ci<cl && !cluster; ci++) {
            var ms = cs[ci].GetClusterMarkers();
            for (var mi=0, ml=ms.length; mi<ml; mi++) {
                if (ms[mi].data.attributes.id == markid) { cluster = cs[ci]; break; } // Places
                if (ms[mi].data.attributes.location && ms[mi].data.attributes.location.id == markid) { cluster = cs[ci]; break; } // EventLocations
            }
        }
        cluster.data._leafletMarker.fire('click');

        // if there was only 1 marker in the cluster, then we're done
        // the click above was passed on to the only Marker, a true L.marker with the event handler to call clickMarker_() through the usual on('click') assignment
        // and the glow and data slideout are already showing
        if (cluster.population == 1) return;

        // if we got here, then the cluster has >1 marker in it AND it should be spidered out by now
        // find the specific marker and trigger a click upon it
        // but give it a moment for the spidering to render
        setTimeout(function () {
            var onscreen_marker;
            for (var i=0, l=MARKERS.spiderfier._currentMarkers.length; i<l; i++) {
                var m = MARKERS.spiderfier._currentMarkers[i];
                if (m.attributes.id == markid) { m.fire('click'); break; } // Places
                if (m.attributes.location && m.attributes.location.id == markid) { m.fire('click'); break; } // EventLocations
            }
        }, 500);
    }, 750);
}

function clickMarker_EventLocation(marker) {
    // start by highlighting, why not?   tip: do not zoom here, cuz then the clusterer freaks out, recentering and reclustering repeatedly
    highlightMarker(marker);

    // update the Directions link to navigate to this marker
    updateNavigationLinkFromMarker(marker);

    // expand the info panel, then show only this one subpanel for the marker type
    var panel = $('#map_infopanel').show();
    var subpanel = panel.children('div[data-type="eventlocation"]').show();
    subpanel.siblings('div').hide();

    // go over attributes, load them into the tagged field (if one exists)
    // note the switch for the tag type: A tags assign an URL, everything else gets text filled in, ... thus we have self-expanding code simply by adding fields whose data-field=FIELDNAME
    // this is done for both the location and the event, so we can have info about both:  event.name   location.name   location.desc   and so on
    var components = ['event','location'];
    for (var i=0, l=components.length; i<l; i++) {
        var component = components[i];

        for (var field in marker.attributes[component]) {
            var target = subpanel.find('[data-field="'+component+'.'+field+'"]');
            var value  = marker.attributes[component][field];

            switch ( target.prop("tagName") ) {
                case 'A':
                    // A anchor: insert the URL as the HREF, and if that's blank then hide this link
                    if (value) {
                        target.prop('href',value).show();
                    } else {
                        target.prop('href','about:blank').hide();
                    }
                    break;
                default:
                    target.html(value);
                    break;
            }
        }
    }
}

function clickMarker_Place(marker) {
    // start by highlighting, why not?   tip: do not zoom here, cuz then the clusterer freaks out, recentering and reclustering repeatedly
    highlightMarker(marker);

    // update the Directions link to navigate to this marker
    updateNavigationLinkFromMarker(marker);

    // expand the info panel, then show only this one subpanel for the marker type
    var panel = $('#map_infopanel').show();
    var subpanel = panel.children('div[data-type="place"]').show();
    subpanel.siblings('div').hide();

    // go over attributes, load them into the tagged field (if one exists)
    // note the switch for the tag type: A tags assign an URL, everything else gets text filled in, ... thus we have self-expanding code simply by adding fields whose data-field=FIELDNAME
    for (var field in marker.attributes) {
        var target = subpanel.find('[data-field="'+field+'"]');
        var value  = marker.attributes[field];
        switch ( target.prop("tagName") ) {
            case 'A':
                // A anchor: insert the URL as the HREF, and if that's blank then hide this link
                if (value) {
                    target.prop('href',value).show();
                } else {
                    target.prop('href','about:blank').hide();
                }
                break;
            default:
                target.html(value);
                break;
        }
    }
}

function highlightMarker(marker) {
    // remove the highlight CSS class from all marker images
    $('#map_canvas img.leaflet-marker-icon').removeClass('leaflet-marker-highlight');

    // bail: if the marker we're to highlight is a null, it means we don't want to highlight anything
    if (! marker ) return;

    // add the highlight CSS class to this marker image
    // WARNING: _icon is not a Leaflet API, so this may break in the future
    // WARNING: something of a hack to distinguish Place and Event, by existence of "event" sub-attributes
    var icondiv = $(marker._icon).addClass('leaflet-marker-highlight');
    if (marker.attributes.event) {
        icondiv.addClass('leaflet-marker-event').removeClass('leaflet-marker-place');
    } else {
        icondiv.removeClass('leaflet-marker-event').addClass('leaflet-marker-place');
    }
}

function updateNavigationLinkFromMarker(marker) {
    // this hyperlink is triggered on click, to open the navigation/directions to the given point; see also see initMapInfoPanel() 
    // updateNavigationLinkFromMarker() should be called from the clickMarker_ family of functions, to bring the nav link into line with the marker being displayed
    var target = $('#map_infopanel > a[data-icon="navigation"]');
    var there  = marker.getLatLng();

    if (is_ios()) {
        // iOS; open Apple Maps cuz there is no navigation intent
        var here = LOCATION.getLatLng();
        //var url  = 'http://maps.apple.com/maps?saddr=loc:'+here.lat+','+here.lng+'&daddr=loc:'+there.lat+','+there.lng;
        var url  = 'maps:daddr='+here.lat+','+here.lng;
        target.prop('href',url);
    } else if (is_android()) {
        // Android; open Google Maps, cuz the google.navigation intent is not consistently implemented
        var here = LOCATION.getLatLng();
        var url  = 'http://maps.google.com/maps?saddr=loc:'+here.lat+','+here.lng+'&daddr=loc:'+there.lat+','+there.lng;
        target.prop('href',url);
    }
    else {
        // desktop; fail over and open a new window, to Google Maps
        // for this case we explicitly give our starting point, since it's not an onboard app that's been tracking us all along
        var here = LOCATION.getLatLng();
        var url  = 'http://maps.google.com/maps?saddr=loc:'+here.lat+','+here.lng+'&daddr=loc:'+there.lat+','+there.lng;
        target.prop('href',url);
    }
}

