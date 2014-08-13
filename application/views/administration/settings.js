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

    // also pertaining to the MAP, whenever the tab changes we need to tell the map to update its size
    // when the map tab isn't selected, the DIV has a wize of 0x0, screws up the map when you switch back
    $('#settingsform > .tabs').on('tabsactivate', function(event,ui) {
        var mapdiv = $('#bbox_map_canvas');
        if (! mapdiv.is(':visible') ) return;
        MAP.invalidateSize();

        var w = parseFloat( $('input[name="bbox_w"]').val() );
        var s = parseFloat( $('input[name="bbox_s"]').val() );
        var e = parseFloat( $('input[name="bbox_e"]').val() );
        var n = parseFloat( $('input[name="bbox_n"]').val() );
        MAP.fitBounds([[s,w],[n,e]]);
    });

    // pick the navbar entry to show where we are
    $('#navbar_settings').addClass('ui-state-focus');

    // the editing form is handled via AjaxForm, since we want to validate and barf on errors without losing the page
    // but we also need file uploads, which exceeds what can be done with simple $.post
    $('#settingsform').ajaxForm({
        beforeSubmit: function (paramlist,$form,options) {
            // thank you, TinyMCE for the HTML editor; but first, save the content back to the textarea
            tinyMCE.triggerSave();
        },
        success: function (responseText, statusText, xhr, $form) {
            if (responseText != 'ok') return alert(responseText);
            document.location.href = BASE_URL + 'administration';
        },
        error: function (error) {
            return alert("Uncaught error?");
        }
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

    // enable that snazzy color picker for all text inputs with class=color
    $('input.color').ColorPicker({
        color: $(this).val(),
        onSubmit: function(hsb, hex, rgb, el) {
            $(el).val('#'+hex).css({ 'background-color':'#'+hex });
            $(el).ColorPickerHide();
        }
    }).keyup(function () {
        $(this).ColorPickerSetColor(this.value);
    }).each(function () {
        var already = $(this).val();
        $(this).css({ 'background-color':already });
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


