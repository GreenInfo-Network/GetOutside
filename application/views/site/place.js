var MAP, MARKER; // the Leaflet Map object and the one Marker we'll lay down
var DIRECTIONS_MARKER, DIRECTIONS_LINE; // we lied; there's a second marker, and also a line for drawing directions
var DIRECTIONS_LINE_STYLE = {
    color: 'red'
};
var ZOOM = 15;

$(document).ready(function () {
    // start the map centered at the given point, add the base map layer
    MAP = L.map('map_canvas').panTo([LAT,LON]).setZoom(ZOOM);
    L.tileLayer('http://{s}.tiles.mapbox.com/v3/greeninfo.map-fdff5ykx/{z}/{x}/{y}.jpg', {}).addTo(MAP);

    // add the one marker, keep the default marker
    L.marker([LAT,LON], { clickable:false }).addTo(MAP);

    // enable the directions geodcoder if we have a Bing API key; hide it if not
    if (BING_API_KEY) {
        $('#geocode_go').click(function () {
            geocodeAndGetDirections( $('#geocode').val() );
        });
        $('#geocode').keydown(function (key) {
            if (key.keyCode == 13) $('#geocode_go').click();
        });
    } else {
        $('#geocode').hide();
        $('#geocode_go').hide();
    }
});


function clearDirections() {
    // clear any previous directions results
    if (DIRECTIONS_LINE) {
        MAP.removeLayer(DIRECTIONS_LINE);
        DIRECTIONS_LINE = null;
    }
    if (DIRECTIONS_MARKER) {
        MAP.removeLayer(DIRECTIONS_MARKER);
        DIRECTIONS_MARKER = null;
    }

    $('#directions').empty().hide();
}

function geocodeAndGetDirections(address) {
    if (! address) return;
    if (! BING_API_KEY) return alert("Address searches disabled.\nNo Bing Maps API key has been entered by the site admin.");

    // clear previous results
    clearDirections();

    // correct the URL cuz we're using a naive REST/JSONP technique: remove any & characters cuz encodeURIComponent() doesn't solve & causing a Bad Request error
    address = address.replace('&', 'and');

    var url = 'http://dev.virtualearth.net/REST/v1/Locations/' + encodeURIComponent(address) + '?output=json&jsonp=handleGeocodeResult&key=' + BING_API_KEY;
    $('<script></script>').prop('type','text/javascript').prop('src',url).appendTo( jQuery('head') );
}

function handleGeocodeResult(results) {
    if (results.authenticationResultCode != 'ValidCredentials') return alert("The Bing Maps API key appears to be invalid.");

    var result, lat, lon;
    try {
        result = results.resourceSets[0].resources[0];
        lat    = result.point.coordinates[0];
        lon    = result.point.coordinates[1];
    } catch (e) {
        return alert('Could not find that location.');
    }

    // got the point, so lay down the second marker
    DIRECTIONS_MARKER = L.marker([lat,lon], { clickable:false }).addTo(MAP);

    // ... then get directions
    var start = lat + ',' + lon;
    var dest  = LAT + ',' + LON;
    var mode  = 'driving';

    var url = 'http://dev.virtualearth.net/REST/v1/Routes?wp.0=' + encodeURIComponent(start) + '&wp.1=' + encodeURIComponent(dest) + '&routePathOutput=Points&travelMode='+mode+'&output=json&distanceUnit='+ DISTANCE_UNITS +'&jsonp=RouteCallback&key=' + BING_API_KEY;
    $('<script></script>').prop('type','text/javascript').prop('src',url).appendTo( jQuery('head') );
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
    $('#directions').append(directions).show();
}

