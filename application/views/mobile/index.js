///////////////////////////////////////////////////////////////////////////////////////////////////////////
///// GLOBAL SETTINGS
///////////////////////////////////////////////////////////////////////////////////////////////////////////

// map stuff, see initMap() and ; see also onLocationFound()
var MAP;            // L.Map
var BASEMAPS = {};  // dict mapping a name onto a L.tileLayer instance; keys will be:   terrain   topo   photo
var MAX_EXTENT;     // L.latLngBounds; used for geocode biasing and as our starting extent
var MARKERS;        // L.LayerGroup; empty but gets filled with markers when they search
var LOCATION;       // L.Marker indicating their current location
var ACCURACY;       // L.Circle showing the accuracy of their location

// should we auto-recenter the map when location is found?
var AUTO_RECENTER = true;


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
}

function switchToMap(callback) {
    $.mobile.changePage('#page-map');
    if (callback) setTimeout(callback,500);
    setTimeout(resizeMap,600);
}



///////////////////////////////////////////////////////////////////////////////////////////////////////////
///// INITIALIZATION
///////////////////////////////////////////////////////////////////////////////////////////////////////////

$(document).ready(function () {
    // go ahead and render the page elements, so we don't fall victim to lazy loading
    $('div[data-role="page"]').page();

    // now various sub-initializations
    initMap();
    initSearchForms();
});

function initSearchForms() {
    // enable the 2 "GO" buttons on the home (Search) page
    $('#page-search button[name="search-browse-map"]').tap(function () {
        performBrowseMap();
    });
    $('#page-search button[name="search-go"]').tap(function () {
        performSearch();
    });

    // DOM handler: when the Address Type changed to address, show the address box; when it's not address, hide the box
    // then specifically force it to GPS option (Firefox caches controls selections) to hide the address box
    $('#page-search select[name="location"]').change(function () {
        switch ( $(this).val() ) {
            case 'address':
                $('#page-search input[name="address"]').show();
                break;
            case 'gps':
                $('#page-search input[name="address"]').hide();
                break;
        }
    }).val('gps').trigger('change');

    // jQuery Mobile bug workaround: when changing pages, tabs won't keep their previous selected state
    // so when we go to Search Results, switch to Places so we're switched to SOMETHING
    $(document).on('pageshow', '#page-search-results', function(){
        $(this).find('div[data-role="navbar"] li a').first().click();
    });

    // trigger a rendering of Nothing Found at this time, as if a search had been performed
    // this populates the Results panel, which someone could find via the Map panel having not done a search
    performSearchHandleResults({ places:[], events:[] });
}

function initMap() {
    // define that biasing box for geocoding, which is also our default starting view
    MAX_EXTENT = L.latLngBounds([[START_N,START_E],[START_S,START_W]]);

    // define the basemaps
    BASEMAPS['terrain'] = L.tileLayer("http://{s}.tiles.mapbox.com/v3/greeninfo.map-3x7sb5iq/{z}/{x}/{y}.jpg", { name:'Terrain', subdomains:['a','b','c','d'] });
    BASEMAPS['photo']   = L.tileLayer("http://{s}.tiles.mapbox.com/v3/greeninfo.map-zudfckcw/{z}/{x}/{y}.jpg", { name:'Photo', subdomains:['a','b','c','d'] });
    BASEMAPS['topo']    = L.tileLayer("http://services.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}.jpg", { name:'Topo' });

    // load the map and its initial view
    MAP = new L.Map('map_canvas', {
        attributionControl: false,
        zoomControl: true,
        dragging: true,
        closePopupOnClick: false,
        crs: L.CRS.EPSG3857
    }).fitBounds(MAX_EXTENT);
    selectBasemap('terrain');

    // define the marker and circle for our location and accuracy
    var icon = L.icon({
        iconUrl: BASE_URL + 'application/views/mobile/images/marker-gps.png',
        iconSize:     [25, 41], // size of the icon
        iconAnchor:   [13, 41], // point of the icon which will correspond to marker's location
        popupAnchor:  [13,  1] // point from which the popup should open relative to the iconAnchor
    });
    LOCATION  = L.marker([0,0], { clickable:false, draggable:false, icon:icon }).addTo(MAP);
    ACCURACY  = L.circle([0,0], 1000, { clickable:false }).addTo(MAP);

    // set up the event handler when our location is detected, and start continuous tracking
    // loose binding with an anonymous function, for easier debugging (can replace the function in the console)
    MAP.on('locationfound', function (event) { onLocationFound(event); });
    MAP.on('locationerror', function (error) { onLocationError(error); });
    MAP.locate({ enableHighAccuracy:true, watch:true });

    // add some Controls, including our custom ones which are simply buttons; we use a Control so Leaflet will position and style them
    L.control.scale({ metric:false }).addTo(MAP);
    new L.controlCustomButtonPanel().addTo(MAP);

    // now add the empty MARKERS LayerGroup
    // this will be loaded with markers when a search is performed or when they pick Browse The Map
    MARKERS = L.layerGroup([]).addTo(MAP);

    // now the Map Settings panel
    // this is relatively simple, in that there's no tile caching, seeding, database download, ...
    $('#panel-map-settings input[type="radio"][name="basemap"]').change(function () {
        var which = $('#panel-map-settings input[type="radio"][name="basemap"]:checked').prop('value');
        selectBasemap(which);
    });
}


///////////////////////////////////////////////////////////////////////////////////////////////////////////
///// FUNCTIONS
///////////////////////////////////////////////////////////////////////////////////////////////////////////

function selectBasemap(which) {
    for (var i in BASEMAPS) MAP.removeLayer(BASEMAPS[i]);
    BASEMAPS[which].addTo(MAP).bringToBack();
}

function onLocationFound(event) {
    // first and easiest: update the location and accuracy markers
    LOCATION.setLatLng(event.latlng);
    ACCURACY.setLatLng(event.latlng).setRadius(event.accuracy);

    if (AUTO_RECENTER) {
        MAP.panTo(event.latlng);
        if (MAP.getZoom() < 14) MAP.setZoom(14);
    }
}

function autoCenterToggle() {
    AUTO_RECENTER ? autoCenterOff() : autoCenterOn();
}
function autoCenterOn() {
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

function zoomToCurrentMaxExtent() {
    MAP.fitBounds(MAX_EXTENT);
}

function onLocationError(error) {
    //gda show warnings on map and search panel?
}

function performBrowseMap() {
    // gda: fetch All markers, I guess?

    // now switch to the map and zoom to either the whole area (if we have no LOCATION known) or else to our own area (if we do have LOCATION)
    switchToMap(function () {
        var has = LOCATION.getLatLng().lat;
        if (has) {
            zoomToCurrentLocation();
        } else {
            zoomToCurrentMaxExtent();
        }
    });
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
            if (! BING_API_KEY) return alert("Address searches disabled.\nNo Bing Maps API key has been entered by the site admin.");
            var address = $form.find('input[name="address"]').val();
            if (! address) return alert('Enter an address.');
            performSearchAfterGeocode(address);
            break;
        default:
            break;
    }
}

function performSearchReally() {
    // compose params, send it off
    var params = $('#page-search form').serialize();
    $.mobile.loading('show', {theme:"a", text:"Searching", textonly:false, textVisible:true });
    $.post(BASE_URL + 'mobile/fetchdata', params, function (reply) {
        $.mobile.loading('hide');
        performSearchHandleResults(reply);
    }, 'json');
}

function performSearchAfterGeocode(address) {
    var handleReply = function (result) {
        if (! result || ! result.resourceSets.length) return alert("Could not find that address.");
        if (result.authenticationResultCode != 'ValidCredentials') return alert("The Bing Maps API key appears to be invalid.");

        try {
            var $form = $('#page-search form');
            var best  = result.resourceSets[0].resources[0].geocodePoints[0].coordinates;
            $form.find('input[name="lat"]').val( best[0] );
            $form.find('input[name="lng"]').val( best[1] );
            performSearchReally();
        } catch (e) {
            return alert('Could not process the geocoder reply.');
        }
    };

    var url           = 'http://dev.virtualearth.net/REST/v1/Locations';
    var params        = {};
    params.query      = address;
    params.key        = BING_API_KEY;
    params.output     = 'json';
    params.maxResults = 1;
    $.ajax({
        url: url,
        'data': params,
        dataType: 'jsonp',
        jsonp: 'jsonp',
        success: handleReply,
        crossDomain: true
    });
}

function performSearchHandleResults(reply) {
    // assign the results into the listing components (listviews, map) ...
    $('#page-search-results-places-list').data('rawresults', reply.places);
    $('#map_canavs').data('rawresults', reply.places);
    $('#page-search-results-events-list').data('rawresults', reply.events);

    // .. then have them re-render
    // the listing renderers check for 0 length and create a dummy "Nothing Found" item in the lists
    // tip: show and hide don't work with JQM tab content; it makes the element actually visible despite the tab selection
    renderEventsList();
    renderPlacesMap();
    renderPlacesList();

    // ... then show the results
    $.mobile.changePage('#page-search-results');
}

function renderPlacesMap() {
    var items = $('#page-search-results-places-list').data('rawresults');
    MARKERS.clearLayers();

    for (var i=0, l=items.length; i<l; i++) {
        var lat  = items[i].lat;
        var lng  = items[i].lng;
        var name = items[i].name;

        var html  = '<h2>' + items[i].name + '</h2>';
            html += items[i].desc;
        if (items[i].url) {
            html += '<p><a target="_blank" href="'+items[i].url+'">More Info</a></p>';
        }

        L.marker([lat,lng], { title:name }).bindPopup(html).addTo(MARKERS);
    }
}

function renderPlacesList() {
    var $target = $('#page-search-results-places-list').empty();
    var items   = $target.data('rawresults');

    // bail condition: 0 items means we need to display only 1 item: Nothing Found
    if (! items.length) {
        $('<li></li>').html('No places matched your filters.<br/>Use the Find button below, to search for places and events.').appendTo($target);
        $target.listview('refresh');
        return;
    }

    for (var i=0, l=items.length; i<l; i++) {
        var item = items[i];
        var li   = $('<li></li>').data('rawresult',item).appendTo($target);

        var label = $('<div></div>').addClass('ui-btn-text').appendTo(li);
        var categories = item.category_names.join(", ");
        $('<span></span>').addClass('ui-li-heading').text(item.name).appendTo(label);
        $('<div></div>').addClass('ui-li-desc').text(categories).appendTo(label);
        $('<span></span>').addClass('ui-li-count').text(' ').appendTo(label); // the distance & bearing aren't loaded yet; see onLocationFound()
    }

    $target.listview('refresh');
}

function renderEventsList() {
    var $target = $('#page-search-results-events-list').empty();
    var items   = $target.data('rawresults');

    // bail condition: 0 items means we need to display only 1 item: Nothing Found
    if (! items.length) {
        $('<li></li>').html('No events matched your filters.<br/>Use the Find button below, to search for places and events.').appendTo($target);
        $target.listview('refresh');
        return;
    }

    for (var i=0, l=items.length; i<l; i++) {
        var item = items[i];
        var li   = $('<li></li>').data('rawresult',item).appendTo($target);

        var label = $('<div></div>').addClass('ui-btn-text').appendTo(li);
        var link  = $('<a></a>').text('More Info').prop('$target','_blank').prop('href',item.url);
        $('<span></span>').addClass('ui-li-heading').text(item.name).appendTo(label);
        $('<div></div>').addClass('ui-li-desc').append(link).appendTo(label);

//gda ddd date and time
    }

    $target.listview('refresh');
}

