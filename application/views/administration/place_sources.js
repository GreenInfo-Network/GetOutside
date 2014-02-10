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

    // enable the filters; the rows/entries in #places_list have data tags for their source ID and category IDs
    // lastly, since Firefox loves to cache controls' statuses (keeping selectors selected) trigger a filter right now, for whatever Firefox thinks is still selected
    $('#places_list tr').slice(1).each(function () {
        // preprocessing: each row has a comma-joined list of category IDs; split it into a list and save as a .data item
        // so we don't need to split every single row, every single time they pick a category filter
        var $row  = $(this);
        var ids   = $row.attr('data-category-ids').split(',');
        $row.data('category-ids',ids);
    });
    $('select[name="places_filter_category"]').change(function () {
        applyFiltersToPlaceListing();
    });
    $('select[name="places_filter_source"]').change(function () {
        applyFiltersToPlaceListing();
    });
    applyFiltersToPlaceListing();
});


function applyFiltersToPlaceListing() {
    var cid = $('select[name="places_filter_category"]').val();
    var sid = $('select[name="places_filter_source"]').val();
    $('#places_list tr').slice(1).show().each(function () {
        var $row  = $(this);
        var match = (!sid || $row.attr('data-source-id') == sid) && (!cid || $row.data('category-ids').indexOf(cid) != -1); // if a sid, must match; if a cid, must match
        if (! match) $row.hide();
    });
}

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
    var url    = BASE_URL + 'administration/ajax_load_place_source/';
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
