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
        // only one data source: our own feed
        // we don't use the Google Calendar functionality since we need to merge in events from other sources too, so it makes sense to have one unified feed
        eventSources: [
            BASE_URL + 'site/ajax_calendar_events'
        ],
        // override the viewDisplay to show only 6 months into the futgure and none of the past; thank you, Joel Correa on StackOverflow
        viewDisplay   : function(view) {
            var now = new Date(); 
            var end = new Date();
            end.setMonth(now.getMonth() + 6); //Adjust as needed

            var cal_date_string = view.start.getMonth()+'/'+view.start.getFullYear();
            var cur_date_string = now.getMonth()+'/'+now.getFullYear();
            var end_date_string = end.getMonth()+'/'+end.getFullYear();

            if(cal_date_string == cur_date_string) { jQuery('.fc-button-prev').addClass("fc-state-disabled"); }
            else { jQuery('.fc-button-prev').removeClass("fc-state-disabled"); }

            if(end_date_string == cal_date_string) { jQuery('.fc-button-next').addClass("fc-state-disabled"); }
            else { jQuery('.fc-button-next').removeClass("fc-state-disabled"); }
        }
    });
});


