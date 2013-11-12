$(document).ready(function () {
    // pick the navbar entry to show where we are
    $('#navbar_users').addClass('ui-state-focus');

    // handle the editing form as AJAX, so we can get some nicer errror handling
    // rather than ditching them at an error message
    $('#editform').submit(function () {
        var url    = BASE_URL + 'administration/ajax_save_user';
        var params = $(this).serialize();
        $.post(url, params, function (reply) {
            if (reply != 'ok') return alert(reply);
            document.location.href = BASE_URL + 'administration/users';
        });
    });

});

