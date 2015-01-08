var MAP;

$(document).ready(function () {
    // start the map
    // No! this is done when a basemap option is selected, and one will be selected and event handlers added below
    //startMap();

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

    // enable special effects in the editing forms
    // when a basemap_type is selected
    // - toggle the corresponding div.basemap_option to show extended help/options specific to that option
    //      be sure to trigger it now (it'll be checked in the UI already) to show/hide the appropriate options
    // - reload the map with the given option
    $('input[type="radio"][name="basemap_type"]').change(function () {
        var which = $(this).val();

        // show the appropriate help text
        $(this).closest('td').children('div.basemap_option').hide().filter('[data-basemaptype="'+which+'"]').show();

        // then restart the Map with this specific basemap option
        startMap(which);
    }).filter(':checked').trigger('change');

    // enable special effects in the editing forms
    // if a Feedback URL was given, ensure that it starts with http:// because people forget to add it when they're pasting
    $('input[name="feedback_url"]').change(function () {
        var url = $(this).val();
        if (! url) return;                      // no URL at all; fine
        if (url.substr(0,4) == 'http') return;  // already has it, fine
        $(this).val( 'http://' + url );
    });

});


// initializing the map would normally go into the document.ready handler above,
// but then we couldn't throw out and re-initialize the map with a new basemap option!
function startMap(specific_basemap_choice) {
    // start by destroying the MAP if it exists
    // this global MAP handle will be recreated in a moment
    // some of this is kinda a hack to work around the Google layer not properly behaving with MAP.remove() events; the tiles stick around, etc.
    if (MAP) { MAP.remove(); MAP = null; $('#bbox_map_canvas').empty(); }

    // enable the map for setting the Map view
    // this uses a moveend handler to update the bbox_X text boxes when the map is moved, which will be saved along with the rest of the form
    var w = parseFloat( $('input[name="bbox_w"]').val() );
    var s = parseFloat( $('input[name="bbox_s"]').val() );
    var e = parseFloat( $('input[name="bbox_e"]').val() );
    var n = parseFloat( $('input[name="bbox_n"]').val() );
    MAP = L.map('bbox_map_canvas').fitBounds([[s,w],[n,e]]);

    // add the basemap
    // which basemap choice to use? well, use what we're told... or else the global sitewide default
    if (! specific_basemap_choice) specific_basemap_choice = BASEMAP_TYPE;
    switch (specific_basemap_choice) {
        case 'xyz':
            // a simple XYZ layer, and they provided the URL template too; sounds simple
            L.tileLayer(BASEMAP_XYZURL, {}).addTo(MAP);
            break;
        case 'googlestreets':
            MAP.addLayer( new L.Google('ROADMAP') );
            break;
        case 'googlesatellite':
            MAP.addLayer( new L.Google('HYBRID') );
            break;
        case 'googleterrain':
            MAP.addLayer( new L.Google('TERRAIN') );
            break;
        case 'bingstreets':
            new L.BingLayer(BING_API_KEY, { type:'Road' }).addTo(MAP);
            break;
        case 'bingsatellite':
            new L.BingLayer(BING_API_KEY, { type:'AerialWithLabels' }).addTo(MAP);
            break;
        default:
            return alert("Invalid basemap choice? How did that happen?");
            break;
    }

    // the point of the mini map: when they pan or zoom, update the input boxes with the new bounding box ordinates
    MAP.on('moveend', function () {
        var wsen = this.getBounds();
        $('input[name="bbox_w"]').val( wsen.getWest() );
        $('input[name="bbox_s"]').val( wsen.getSouth() );
        $('input[name="bbox_e"]').val( wsen.getEast() );
        $('input[name="bbox_n"]').val( wsen.getNorth() );
    });
}


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


