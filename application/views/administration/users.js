$(document).ready(function () {
    // pick the navbar entry to show where we are
    $('#navbar_users').addClass('ui-state-focus');

    // enable a popup dialog for entering a new user, and the button to toggle it
    $('#dialog_new').dialog({
        modal:true, closeOnEsc:true, autoOpen:false, width:'auto', height:'auto',
        title: 'New User Account',
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
});

function newFromForm() {
    var url    = BASE_URL + 'administration/ajax_create_user';
    var params = $('#dialog_new form').serialize();
    $.post(url, params, function (reply) {
        // the reply should be an integer, the new user's ID#
        if (! parseInt(reply)) return alert(reply);
        document.location.href = BASE_URL + 'administration/user/' + reply;
    });
}
