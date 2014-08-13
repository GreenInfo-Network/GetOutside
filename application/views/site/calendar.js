$(document).ready(function () {
    // initialize the popup where we will show event details
    $('#dialog_eventinfo').dialog({
        modal:true, closeOnEsc:true, autoOpen:false, width:'auto', height:'auto',
        title: 'Event Info',
        buttons: { }
    });

    // initialize the calendar
    $('#calendar').fullCalendar({
        startParam: 'startdate',
        endParam: 'enddate',
        // on a click, open the link in a nice dialog panel, rather than taking them away from our site
        eventClick: function(calEvent, jsEvent, view) {
            if (! calEvent.url) return;

            window.open(calEvent.url);
            return false;
        },
        // no data sources; these are added by checkbox event handlers below
        eventSources: [ ],
        // override the viewDisplay to show only 3 months into the future and none of the past; thank you, Joel Correa on StackOverflow
        viewDisplay: function(view) {
            var now = new Date(); 
            var end = new Date();
            end.setMonth(now.getMonth() + 3); //Adjust as needed

            var cal_date_string = view.start.getMonth()+'/'+view.start.getFullYear();
            var cur_date_string = now.getMonth()+'/'+now.getFullYear();
            var end_date_string = end.getMonth()+'/'+end.getFullYear();

            if(cal_date_string == cur_date_string) { jQuery('.fc-button-prev').addClass("fc-state-disabled"); }
            else { jQuery('.fc-button-prev').removeClass("fc-state-disabled"); }

            if(end_date_string == cal_date_string) { jQuery('.fc-button-next').addClass("fc-state-disabled"); }
            else { jQuery('.fc-button-next').removeClass("fc-state-disabled"); }
        }
    });

    // data source checkboxes: checking them relates them to a Data Source in DATA_SOURCES
    // and adds/removes the DS from the Calendar
    // define this event handler, then trigger it right now to load up the content
    $('input[type="checkbox"][name="sources[]"]').change(function () {
        var id = $(this).prop('value')
        var ds = BASE_URL + 'site/ajax_calendar_events/' + id;

        var showhide = $(this).is(':checked');
        if (showhide) {
            $('#calendar').fullCalendar('addEventSource',ds);
        } else {
            $('#calendar').fullCalendar('removeEventSource',ds);
        }
    }).trigger('change');
});


