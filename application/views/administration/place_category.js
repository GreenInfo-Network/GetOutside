$(document).ready(function () {
    // pick the navbar entry to show where we are
    $('#navbar_place_sources').addClass('ui-state-focus');

    // the Fetch and Save buttons
    // Save calls the AJAX save-and-exit
    // Fetch does a save also, but then runs a fetch same as if from the Event Sources menu page
    $('#button_save').click(function () {
        saveAndExit();
    });

    // enable the "Fetching..." dialog
    $('#dialog_fetching').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: 'Fetching',
        buttons: { }
    });
});


function saveAndExit() {
    var url    = BASE_URL + 'administration/ajax_save_place_category';
    var params = $('#editform').serialize();
    $('#dialog_fetching').dialog('open');
    $.post(url, params, function (reply) {
        $('#dialog_fetching').dialog('close');
        if (reply != 'ok') return alert(reply);
        document.location.href = BASE_URL + 'administration/place_sources';
    });
}

