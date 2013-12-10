$(document).ready(function () {
    // select the navbar entry
    $('#navbar_event_sources').addClass('ui-state-focus');

    // enable the dialog and button to enter the basics of a new data source
    $('#dialog_new').dialog({
        modal:true, closeOnEsc:true, autoOpen:false, width:'auto', height:'auto',
        title: 'New Data Source',
        buttons: {
            'Create': function () {
                newFromForm();
            },
            'Cancel': function () {
                $(this).dialog('close');
            }
        }
    });
    $('#button_new').click(function () {
        $('#dialog_new').dialog('open');
    });

    // enable the dialog and buttons to fetch content from a datasource
    $('#dialog_fetching').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: 'Fetching',
        buttons: { }
    });
    $('span.refresh').click(function () {
        var id = $(this).attr('data-id');
        refreshDataSourceById(id);
    });

    // One Moment Please
    $('#dialog_waiting').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: '',
        buttons: { }
    });

});


function newFromForm() {
    var url    = BASE_URL + 'administration/ajax_create_event_source';
    var params = $('#dialog_new form').serialize();
    $.post(url, params, function (reply) {
        // the reply should be an integer, the new item's ID#
        if (! parseInt(reply)) return alert(reply);
        document.location.href = BASE_URL + 'administration/event_source/' + reply;
    });
}


function refreshDataSourceById(id) {
    var url    = BASE_URL + 'administration/ajax_load_event_source/';
    var params = { id:id };

    $('#dialog_fetching').dialog('open');
    $.post(url, params, function (reply) {
        $('#dialog_fetching').dialog('close');
        alert(reply);
        document.location.reload(true);
    }).error(function () {
        $('#dialog_fetching').dialog('close');
        alert('There was a problem. To diagnose further, check your browser\'s debugging tools.');
    });
}

