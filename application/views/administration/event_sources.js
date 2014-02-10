var RELOAD_SOURCES = []; // a list of {id,name} objects, being a list of data sources to update sequentially

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
        var id   = $(this).closest('tr').attr('data-source-id');
        var name = $(this).closest('tr').attr('data-source-name');
        refreshDataSource(id,name);
    });

    // One Moment Please
    $('#dialog_waiting').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: '',
        buttons: { }
    });

    // Reload All
    $('#button_reload_sources').click(function () {
        reloadAllSources();
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


function refreshDataSource(id,name) {
    // load the global source list with this one item
    RELOAD_SOURCES = [ {id:id, name:name }];

    // reload from the list; async plus sequential really is tedious  ;)
    reloadSourcesList();
}



function reloadAllSources() {
    // load the global source list with all items in the list
    RELOAD_SOURCES = [];
    $('#sources tbody tr').each(function () {
        var id      = $(this).attr('data-source-id');
        var name    = $(this).attr('data-source-name');
        var enabled = parseInt( $(this).attr('data-source-enabled') );
        if (! enabled) return;
        RELOAD_SOURCES.push({ id:id, name:name });
    });

    // reload from the list; async plus sequential really is tedious  ;)
    reloadSourcesList();
}


// load the RELOAD_SOURCES items, sequentially but asynchronously    it's as tedious as it sounds   :)
// reloadSourcesList() checks the length of RELOAD_SOURCES and grabs the first item, calls a reload, then calls reloadSourcesList() again recursively
// the break condition is simply that RELOAD_SOURCES is empty, at which point we reload the page
function reloadSourcesList() {
    // nothing left to reload? peachy; reload the page so the listing refreshes   (could reload listing via AJAX but no immediate need for that)
    if (! RELOAD_SOURCES.length) document.location.reload(true);

    // grab the first item, make the reload AJAX call
    var source = RELOAD_SOURCES.shift();
    var id     = source.id;
    var name   = source.name;
    var url    = BASE_URL + 'administration/ajax_load_event_source/';
    var params = { id:id };

    $('#dialog_fetching').dialog('option','title',name).dialog('open');
    $.post(url, params, function (reply) {
        $('#dialog_fetching').dialog('option','title','').dialog('close');
        alert(name + "\n\n" + reply);

        // done with this one, re-call reloadSourcesList() and let it decide whether there's a next item
        reloadSourcesList();
    }).error(function () {
        $('#dialog_fetching').dialog('option','title','').dialog('close');
        alert(name + "\n\n" + 'There was a problem. To diagnose further, check your browser\'s debugging tools.');

        // done with this one, re-call reloadSourcesList() and let it decide whether there's a next item
        reloadSourcesList();
    });
}
