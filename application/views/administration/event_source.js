$(document).ready(function () {
    // pick the navbar entry to show where we are
    $('#navbar_event_sources').addClass('ui-state-focus');

    // the Fetch and Save buttons
    // Save calls the AJAX save-and-exit
    // Fetch does a save also, but then runs a fetch same as if from the Event Sources menu page
    $('#button_save').click(function () {
        saveAndExit();
    });
    $('#button_fetch').click(function () {
        saveAndFetch();
    });

    // enable the "Fetching..." dialog
    $('#dialog_fetching').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: 'Fetching',
        buttons: { }
    });

    // enable that snazzy color picker
    $('input[name="color"]').ColorPicker({
        color: $('input[name="color"]').val(),
        onSubmit: function(hsb, hex, rgb, el) {
            $(el).val('#'+hex).css({ 'background-color':'#'+hex });
            $(el).ColorPickerHide();
        }
    }).keyup(function () {
        $(this).ColorPickerSetColor(this.value);
    });
    var already = $('input[name="color"]').val();
    $('input[name="color"]').css({ 'background-color':already });
});


function saveAndExit() {
    var url    = BASE_URL + 'administration/ajax_save_event_source';
    var params = $('#editform').serialize();
    $.post(url, params, function (reply) {
        if (reply != 'ok') return alert(reply);
        document.location.href = BASE_URL + 'administration/event_sources';
    });
}


function saveAndFetch() {
    var url    = BASE_URL + 'administration/ajax_save_event_source';
    var params = $('#editform').serialize();
    $.post(url, params, function (reply) {
        if (reply != 'ok') return alert(reply);

        // now call the "load this source" AJAX endpoint, same params
        // yes, the POST callback creates this POST and callback
        $('#dialog_fetching').dialog('open');
        var url    = BASE_URL + 'administration/ajax_load_event_source/';
        $.post(url, params, function (reply) {
            $('#dialog_fetching').dialog('close');
            alert(reply);
        }).error(function () {
            $('#dialog_fetching').dialog('close');
            alert('There was a problem. To diagnose further, check your browser\'s debugging tools.');
        });
    });
}


