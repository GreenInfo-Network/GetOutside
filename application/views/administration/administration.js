/* this is loaded into all administration pages, and is suitable for jQuery-UI stuff common to all pages, e.g. tabs and sortable tables */

$(document).ready(function () {
    // enable tabset
    // when the tabsset is created, drop the ui-widget-content class from the tabs and the panels, cuz it's hideously ugly!
    $('.tabs').tabs({
        heightStyle:'content',
        create: function (event,ui) {
            ui.panel.closest('.tabs').removeClass('ui-widget-content').children('.ui-tabs-panel').removeClass('ui-widget-content');
        }
    });

    // enable sorting on tables using tablesorter
    $('table.sortable').tablesorter();
});
