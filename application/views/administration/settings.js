$(document).ready(function () {
    // pick the navbar entry to show where we are
    $('#navbar_settings').addClass('ui-state-focus');

    // handle the editing form as AJAX, so we can get some nicer errror handling
    // rather than ditching them at an error message
    $('#settingsform').submit(function () {
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


});
