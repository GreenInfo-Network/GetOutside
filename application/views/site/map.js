var MAP;


$(document).ready(function () {
    // enable the map for setting the Map view
    // this uses a moveend handler to update the bbox_X text boxes when the map is moved, which will be saved along with the rest of the form
    var w = parseFloat( $('input[name="bbox_w"]').val() );
    var s = parseFloat( $('input[name="bbox_s"]').val() );
    var e = parseFloat( $('input[name="bbox_e"]').val() );
    var n = parseFloat( $('input[name="bbox_n"]').val() );
    MAP = L.map('map_canvas').fitBounds([[START_S,START_W],[START_N,START_E]]);
    L.tileLayer('http://{s}.tiles.mapbox.com/v3/greeninfo.map-fdff5ykx/{z}/{x}/{y}.jpg', {}).addTo(MAP);
    MAP.on('moveend', function () {
        var wsen = this.getBounds();
        $('input[name="bbox_w"]').val( wsen.getWest() );
        $('input[name="bbox_s"]').val( wsen.getSouth() );
        $('input[name="bbox_e"]').val( wsen.getEast() );
        $('input[name="bbox_n"]').val( wsen.getNorth() );
    });

    // pertaining to the MAP, is a geocoder so they can find their city
    $('#geocode_go').click(function () {
        geocodeAndZoom( $('#geocode').val() );
    });
    $('#geocode').keydown(function (key) {
        if(key.keyCode == 13) $('#geocode_go').click();
    });

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