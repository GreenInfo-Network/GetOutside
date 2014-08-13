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

    // enable the "Fetching..." and "Waiting" dialogs
    $('#dialog_fetching').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: 'Fetching',
        buttons: { }
    });
    $('#dialog_waiting').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: '',
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
    $('input[name="bgcolor"]').ColorPicker({
        color: $('input[name="bgcolor"]').val(),
        onSubmit: function(hsb, hex, rgb, el) {
            $(el).val('#'+hex).css({ 'background-color':'#'+hex });
            $(el).ColorPickerHide();
        }
    }).keyup(function () {
        $(this).ColorPickerSetColor(this.value);
    });
    var txalready = $('input[name="color"]').val();
    var bgalready = $('input[name="bgcolor"]').val();
    $('input[name="color"]').css({ 'background-color':txalready });
    $('input[name="bgcolor"]').css({ 'background-color':bgalready });

    // enable the "Loaded OK, now what?" dialog
    $('#dialog_loadok').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: 'Loaded',
        buttons: {
            'Close and Continue': function () {
                $(this).dialog('close');
            },
            'Close and Return to Main Menu': function () {
                $(this).dialog('close');
                document.location.href = BASE_URL + 'administration/event_sources';
            }
        }
    });
});


function saveAndExit() {
    var url    = BASE_URL + 'administration/ajax_save_event_source';
    var params = $('#editform').serialize();

    $('#dialog_waiting').dialog('open');
    $.post(url, params, function (reply) {
        $('#dialog_waiting').dialog('close');
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
        // yes, the POST callback to SAVE the settings, makes another POST to RELOAD using the settings; the parsimonious way to save-and-reload without duplicating code
        // on success, loads the message into a dialog; #dialog_loadok has button choices to stay here or bail to the menu
        $('#dialog_fetching').dialog('open');
        var url    = BASE_URL + 'administration/ajax_load_event_source/';
        $.post(url, params, function (reply) {
            $('#dialog_fetching').dialog('close');

            // open the results dialog... so so carefully and in this order, cuz the varied-length text can throw off the position
            var dialog = $('#dialog_loadok');
            dialog.children('div').text(reply);
            dialog.dialog('open').position({ my:'center', at:'center', of:window });
        }).error(function () {
            $('#dialog_fetching').dialog('close');
            alert('There was a problem. To diagnose further, check your browser\'s debugging tools.');
        });
    });
}


