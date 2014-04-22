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
    //gda to be determined; discuss w JS
}
