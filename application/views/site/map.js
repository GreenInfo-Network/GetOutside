var MAP; // the Leaflet Map object
var ALL_MARKERS = []; // array; the set of all markers that exist; a superset of VISIBLE_MARKERS
var VISIBLE_MARKERS; // L.markerClustergroup; the set of all markers currently displaying on the map via a clusterer; a subset of ALL_MARKERS



L.ColoredDivIcon = L.DivIcon.extend({
	options: {
		bgColor: '#FFFFFF',
		className: 'leaflet-div-icon',
		html: false
	},

	createIcon: function (oldIcon) {
		var div = (oldIcon && oldIcon.tagName === 'DIV') ? oldIcon : document.createElement('div'), options = this.options;

		if (options.html !== false) {
			div.innerHTML = options.html;
		} else {
			div.innerHTML = '';
		}

		if (options.bgPos) {
			div.style.backgroundPosition = (-options.bgPos.x) + 'px ' + (-options.bgPos.y) + 'px';
		}

div.style.backgroundColor = options.bgColor;

		this._setIconStyles(div, 'icon');
		return div;
	},

	createShadow: function () {
		return null;
	}
});




$(document).ready(function () {
    // start the map at the defrault bbox, add the basic layer
    MAP = L.map('map_canvas').fitBounds([[START_S,START_W],[START_N,START_E]]);
    L.tileLayer('http://{s}.tiles.mapbox.com/v3/greeninfo.map-fdff5ykx/{z}/{x}/{y}.jpg', {}).addTo(MAP);

    // load the point data sources as represented by the sources[] checkboxes
    // everything loads into the one VISIBLE_MARKERS cluster handler, so it clusters "between" data sources, rather than multiple clusters and outliers which looks funny
    VISIBLE_MARKERS = L.markerClusterGroup({
        showCoverageOnHover:false,
        iconCreateFunction: createClusterDiv
    }).addTo(MAP);
    $('input[name="sources[]"]').each(function () {
        // what is the source ID, and therefore also the cluster ID? create a new cluster group
        var sourceid = $(this).prop('value');

        // ping the server for these locations, and on success add those markers to "cluster"
        // the markers also get a .attributes attribute, including the sourceid, which will be used later for filtering markers based on checkbox choices
        var url = BASE_URL + 'site/ajax_map_points/' + sourceid;
        $.get(url, {}, function (points) {
            // for performance, collect an array and use addLayers(), instead of addLayer() individually
            var markers = [];

            // what color do we use?
            var color = DATA_SOURCES[sourceid]['color'];

            for (var i=0, l=points.length; i<l; i++) {
                // generate a simple DIV icon, so we can use CSS colors
                var icon = new L.ColoredDivIcon({ bgColor:color, className: 'marker-icon', iconAnchor:L.point(10,10), iconSize:L.point(20,20) });

                // assign the attributes incl the source ID into a marker
                points[i].sourceid = sourceid;
                var marker = L.marker([points[i].lat,points[i].lng], { icon:icon, attributes:points[i], keyboard:false, tooltip:points[i].name });

                // add this new marker to the current bundle that we'll add to the clusterer
                // AND ALSO add it to the global MARKERS list
                markers.push(marker);
                ALL_MARKERS.push(marker);
            }
            VISIBLE_MARKERS.addLayers(markers);
        });
    });

    // enable the checkboxes which select which data sources are displayed, and check them
    // do not trigger them; they are implicitly loaded already (above) so having them checked brings them into sync with the map display
    $('input[name="sources[]"]').prop('checked','checked').change(function () {
        var sourceid = $(this).prop('value');
        var viz      = $(this).is(':checked');
        togglePointsBySource(sourceid,viz);
    });

    // enable the geocoder so they can find their address. well, only if there's a Bing key given
    if (BING_API_KEY) {
        $('#geocode_go').click(function () {
            geocodeAndZoom( $('#geocode').val() );
        });
        $('#geocode').keydown(function (key) {
            if(key.keyCode == 13) $('#geocode_go').click();
        });
    } else {
        $('#geocode').hide();
        $('#geocode_go').hide();
    }

    // when the window resizes, resize the map too
    // then trigger a resize right now, so the map and other elements fit the current page size
    $(window).resize(handleResize);
    handleResize();
}); // end of onready



function handleResize() {
    var height = $(window).height();
    $('div.navbar').each(function () {
        height -= $(this).height() + 2;
    });
    $('#map_canvas').height(height);
    if (MAP) MAP.invalidateSize();
}


function geocodeAndZoom(address) {
    if (! address) return;
    if (! BING_API_KEY) return alert("Address searches disabled.\nNo Bing Maps API key has been entered by the site admin.");

    var url = 'http://dev.virtualearth.net/REST/v1/Locations/' + address + '?output=json&jsonp=handleGeocodeResult&key=' + BING_API_KEY;
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


function togglePointsBySource(sourceid,viz) {
    viz ? togglePointsBySource_show(sourceid) : togglePointsBySource_hide(sourceid);
}

function togglePointsBySource_show(sourceid) {
    // iterate over ALL_MARKERS, showing whichever ones match the given source-ID
    var changes = [];

    for (var i=0, l=ALL_MARKERS.length; i<l; i++) {
        if (ALL_MARKERS[i].options.attributes.sourceid == sourceid) changes.push(ALL_MARKERS[i]);
    }

    VISIBLE_MARKERS.addLayers(changes);
}

function togglePointsBySource_hide(sourceid) {
    // iterate over VISIBLE_MARKERS, hiding whichever ones match the given source-ID
    var changes = [];

    var markers = VISIBLE_MARKERS.getLayers();
    for (var i=0, l=markers.length; i<l; i++) {
        if (markers[i].options.attributes.sourceid == sourceid) changes.push(markers[i]);
    }

    VISIBLE_MARKERS.removeLayers(changes);
}


// callback to create a DIV element for this marker cluster
function createClusterDiv(cluster) {
    var count    = cluster.getChildCount(); // how many markers in this cluster
    var size     = new L.Point(40, 40); // all clusters same size, let the colors talk
    var cssclass = 'marker-cluster';
    var html     = '<div><span>' + count + '</span></div>';
    return new L.DivIcon({ html:html, className:cssclass, iconSize:size });
}

