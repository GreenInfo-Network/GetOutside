$(document).ready(function () {
    // select the navbar entry
    $('#navbar_place_sources').addClass('ui-state-focus');

    // enable the dialog and button for a new data source
    $('#dialog_new_source').dialog({
        modal:true, closeOnEsc:true, autoOpen:false, width:'auto', height:'auto',
        title: 'New Place Source',
        buttons: {
            'Create': function () {
                newSourceFromForm();
            },
            'Cancel': function () {
                $(this).dialog('close');
            }
        }
    });
    $('#button_new_source').click(function () {
        $('#dialog_new_source').dialog('open');
    });

    // enable the dialog and button for a new data source
    $('#dialog_new_category').dialog({
        modal:true, closeOnEsc:true, autoOpen:false, width:'auto', height:'auto',
        title: 'New Category',
        buttons: {
            'Create': function () {
                newCategoryFromForm();
            },
            'Cancel': function () {
                $(this).dialog('close');
            }
        }
    });
    $('#button_new_category').click(function () {
        $('#dialog_new_category').dialog('open');
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


function newSourceFromForm() {
    var url    = BASE_URL + 'administration/ajax_create_place_source';
    var params = $('#dialog_new_source form').serialize();

    $('#dialog_waiting').dialog('open');
    $.post(url, params, function (reply) {
        $('#dialog_waiting').dialog('close');

        // the reply should be an integer, the new item's ID#
        if (! parseInt(reply)) return alert(reply);

        document.location.href = BASE_URL + 'administration/place_source/' + reply;
    });
}


function newCategoryFromForm() {
    var url    = BASE_URL + 'administration/ajax_create_place_category';
    var params = $('#dialog_new_category form').serialize();

    $('#dialog_waiting').dialog('open');
    $.post(url, params, function (reply) {
        $('#dialog_waiting').dialog('close');

        // the reply should be an integer, the new item's ID#
        if (! parseInt(reply)) return alert(reply);

        // reload the page so the listing refreshes
        document.location.reload(true);
    });
}


function refreshDataSourceById(id) {
    var url    = BASE_URL + 'administration/ajax_load_place_source/';
    var params = { id:id };

    $('#dialog_fetching').dialog('open');
    $.post(url, params, function (reply) {
        $('#dialog_fetching').dialog('close');
        alert(reply);

        // reload the page so the listing refreshes
        document.location.reload(true);
    }).error(function () {
        $('#dialog_fetching').dialog('close');
        alert('There was a problem. To diagnose further, check your browser\'s debugging tools.');
    });
}

