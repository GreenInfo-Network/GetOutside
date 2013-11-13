var MAP;

$(document).ready(function () {
    // enable the map for setting the Map view
    // this uses a moveend handler to update the bbox_X text boxes when the map is moved, which will be saved along with the rest of the form
    var w = parseFloat( $('input[name="bbox_w"]').val() );
    var s = parseFloat( $('input[name="bbox_s"]').val() );
    var e = parseFloat( $('input[name="bbox_e"]').val() );
    var n = parseFloat( $('input[name="bbox_n"]').val() );
    MAP = L.map('bbox_map_canvas').fitBounds([[s,w],[n,e]]);
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

    // pick the navbar entry to show where we are
    $('#navbar_settings').addClass('ui-state-focus');

    // handle the editing form as AJAX, so we can get some nicer errror handling
    // rather than ditching them at an error message
    $('#settingsform').submit(function () {
        // thank you, TinyMCE for the HTML editor; but first, save the content back to the textarea
        tinyMCE.triggerSave();

        var url    = BASE_URL + 'administration/ajax_save_settings';
        var params = $(this).serialize();
        $.post(url, params, function (reply) {
            if (reply != 'ok') return alert(reply);
            document.location.href = BASE_URL + 'administration';
        });
    });

    // when the Theme picker is picked, update the swatch
    $('select[name="jquitheme"]').change(function () {
        var target = $('#jquitheme_swatch');
        var url = BASE_URL + 'application/views/common/jquery-ui-1.10.3/css/' + $(this).val() + '/swatch.png';
        target.prop('src',url);
    }).trigger('change');

    // enable the TB tooltips
    $('*[data-toggle]').tooltip();

    // enable TinyMCE HTML editor for all textareas
    tinymce.init({
        selector: "#settingsform textarea",
        plugins: "link image code",
        width:800,
        height:300
    });

    // now set up the tabs
    // when the tabsset is created, drop the ui-widget-content class from the tabs and the panels, cuz it's hideously ugly!
    $('.tabs').tabs({
        heightStyle:'content',
        create: function (event,ui) {
            ui.panel.closest('.tabs').removeClass('ui-widget-content').children('.ui-tabs-panel').removeClass('ui-widget-content');
        }
    });
});



function geocodeAndZoom(address) {
    if (! address) return;

    // try to find a Bing Maps API key
    // ideally it would be in BING_API_KEY but we should also handle that they just now entered one into the box, and try to use it
    var api_key;
    if (BING_API_KEY) {
        api_key = BING_API_KEY;
    } else {
        api_key = $('input[name="bing_api_key"]').val();
    }
    if (! api_key) return alert("Use the administration settings panel to enter a Bing Maps API key.");

    var url = 'http://dev.virtualearth.net/REST/v1/Locations/' + address + '?output=json&jsonp=handleGeocodeResult&key=' + api_key;
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


