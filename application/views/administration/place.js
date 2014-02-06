$(document).ready(function () {
    // pick the navbar entry to show where we are
    $('#navbar_place_sources').addClass('ui-state-focus');

    // One Moment Please
    $('#dialog_fetching').dialog({
        modal:true, closeOnEsc:false, autoOpen:false, width:'auto', height:'auto',
        title: 'Fetching',
        buttons: { }
    });

    // explanatory dialog, as to data sources and local editing
    $('#dialog_explainnoedit').dialog({
        modal:true, closeOnEsc:true, autoOpen:false,
        width:500, height:'auto',
        title: 'Editing attributes',
        buttons: {
            'Close': function () {
                $(this).dialog('close');
            }
        }
    });
    $('#button_explainnoedit').click(function () {
        $('#dialog_explainnoedit').dialog('open');
    });

    // the dialog to open a new Activity popup: start time, end time, hours, days, ...
    // this same dialog is used for both editing and creating; the presence/absence of the "id" submission is what makes the difference
    $('#dialog_activity').dialog({
        modal:true, closeOnEsc:true, autoOpen:false,
        width:'auto', height:'auto',
        title: 'New Activity',
        buttons: {
            'Save': function () {
                saveActivityForm();
            },
            'Close': function () {
                $(this).dialog('close');
            }
        }
    });
    $('#button_new').click(function () {
        // reset the form
        $('#dialog_activity input[type="checkbox"]').removeAttr('checked');
        $('#dialog_activity input[name="name"]').val('');
        $('#dialog_activity input[name="starttime"]').val('09:00');
        $('#dialog_activity input[name="endtime"]').val('17:00');
        $('#dialog_activity input[name="id"]').val('0');

        // now open it
        $('#dialog_activity').dialog('option','title','New activity').dialog('open');
    });

    // the hyperlinks to open the Edit Activity dialog
    // this is the selfsame dialog and form as for a New Activity; it's the hdden "id" field that makes the difference
    $('#activities a').click(function () {
        var id = $(this).closest('tr').attr('data-activity-id');
        editActivityById(id);
    });

    // activate the time pickers
    $('#dialog_activity input[name="starttime"]').timepicker();
    $('#dialog_activity input[name="endtime"]').timepicker();
});




function editActivityById(id) {
    // find the given row, parse it into some values
    // a bit of a hack to use the text values, but the alternative is a bunch more data-field="value" attributes which isn't much better
    var row = $('#activities tr[data-activity-id="'+id+'"]');
    var cells = row.children('td');

    var name  = cells.eq(0).text();
    var start = cells.eq(1).text();
    var end   = cells.eq(2).text();
    var mon   = -1 !== cells.eq(3).text().indexOf('Yes');
    var tue   = -1 !== cells.eq(4).text().indexOf('Yes');
    var wed   = -1 !== cells.eq(5).text().indexOf('Yes');
    var thu   = -1 !== cells.eq(6).text().indexOf('Yes');
    var fri   = -1 !== cells.eq(7).text().indexOf('Yes');
    var sat   = -1 !== cells.eq(8).text().indexOf('Yes');
    var sun   = -1 !== cells.eq(9).text().indexOf('Yes');

    $('#dialog_activity input[name="id"]').val(id);
    $('#dialog_activity input[type="text"]').val(name);
    $('#dialog_activity input[name="starttime"]').val(start);
    $('#dialog_activity input[name="endtime"]').val(end);

    var cb = $('#dialog_activity input[name="mon"]'); mon ? cb.prop('checked','checked') : cb.removeAttr('checked');
    var cb = $('#dialog_activity input[name="tue"]'); tue ? cb.prop('checked','checked') : cb.removeAttr('checked');
    var cb = $('#dialog_activity input[name="wed"]'); wed ? cb.prop('checked','checked') : cb.removeAttr('checked');
    var cb = $('#dialog_activity input[name="thu"]'); thu ? cb.prop('checked','checked') : cb.removeAttr('checked');
    var cb = $('#dialog_activity input[name="fri"]'); fri ? cb.prop('checked','checked') : cb.removeAttr('checked');
    var cb = $('#dialog_activity input[name="sat"]'); sat ? cb.prop('checked','checked') : cb.removeAttr('checked');
    var cb = $('#dialog_activity input[name="sun"]'); sun ? cb.prop('checked','checked') : cb.removeAttr('checked');

    // now open it
    $('#dialog_activity').dialog('option','title','Edit activity').dialog('open');
}


function saveActivityForm() {
    $('#dialog_fetching').dialog('open');
    var url    = BASE_URL + 'administration/ajax_save_placeactivity';
    var params = $('#dialog_activity form').serialize();
    $.post(url, params, function (reply) {
        $('#dialog_fetching').dialog('close');
        if (reply != 'ok') return alert(reply);

        $('#dialog_activity').dialog('close');
        document.location.href = document.location.href;
    });
}
