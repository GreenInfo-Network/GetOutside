<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource_iCal extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"URL", 'help'=>"The URL of the iCal feed. This usually ends in <i>.ics</i>"),
    'option1' => NULL,
    'option2' => NULL,
    'option3' => NULL,
    'option4' => NULL,
    'option5' => NULL,
    'option6' => NULL,
    'option7' => NULL,
    'option8' => NULL,
    'option9' => NULL,
);

// hypothetically iCal has a Location field, but seeing it in the wild is quite rare
// when it's present, it's free form text e.g. Meeting Room C, not very useful even for hypothetical geocoding
var $supports_location = FALSE;


public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','iCal Feed');
}


/**********************************************************************************************
 * INSTANCE METHODS
 **********************************************************************************************/

/*
 * reloadContent()
 * Connect to the data source and grab all the events listed, and save them as local Events.
 * By design this is destructive: all existing Events for this EventDataSource are deleted in favor of this comprehensive list of events.
 *
 * This method throws an Exception in any case, either a EventDataSourceErrorException or EventDataSourceSuccessException
 * This allows for more complex communication than a simple true/false return, e.g. the name of the data source and an error code (number of events loaded)
 */
public function reloadContent() {
    // make sure no shenanigans: all RSS feeds http or https
    // no other prep work here; the RSS URL is the RSS URL
    if (! preg_match('/^https?:\/\//i', $this->url) ) throw new EventDataSourceErrorException( array('Not a valid web URL.') );
    $url = $this->url;

    require_once 'application/third_party/class.iCalReader.php';
    $ical = new ICal($url);
    if (! @$ical->cal) throw new EventDataSourceErrorException( array('Content is not an iCal/ICS feed.') );

    $details = array();
    $details[] = "Successfully parsed $url";

    // finally got here, so we're good
    // take a moment and delete all of the old Events from this data source
    $howmany_old = $this->event->count();
    foreach ($this->event as $old) $old->delete();
    $details[] = "Clearing out: $howmany_old old Event records";

    // start adding events!
    $success = 0;
    $failed  = 0;
    foreach ($ical->events() as $entry) {
        $uid    = @$entry['UID'];
        $name   = substr(@$entry['SUMMARY'], 0, 100);
        $start  = @$entry['DTSTART'];
        $end    = @$entry['DTEND']; if (! $end) $end = $start;

        // use the URL tag is available, but use the UID as the event URL if it looks like a URL
        $url = substr(@$entry['URL'], 0, 250);
        if (! $url and strpos($uid,'http')!==FALSE) $url = $uid;

        // if it's lacking a title or a time, something's not right
        if (! $name ) { $failed++; $details[] = "Skipping: Event missing a name, " . print_r($entry,TRUE); continue; }
        if (! $start) { $failed++; $details[] = "Skipping: No start time found for event: {$event->name}"; continue; }

        // remove the \ escapes which litter VCALENDAR content
        $name = str_replace('\\', '', $name);
        $description = @$entry['DESCRIPTION'];
        $description = preg_replace('/\r\n\s+/', ' ', $description);
        $description = str_replace('\\n', "\n", $description);
        $description = str_replace('\\', "", $description);

        $event = new Event();
        $event->eventdatasource_id  = $this->id;
        $event->remoteid            = $uid;
        $event->url                 = $url;
        $event->name                = $name;
        $event->description         = $description;
        $event->starts              = strtotime($start);
        $event->ends                = strtotime($end);

        // now, figure out what weekdays intersect this event's duration; sat  sun  mon  ...
        // these are used to quickly search for "events on a Saturday"
        $event->mon = $event->tue = $event->wed = $event->thu = $event->fri = $event->sat = $event->sun = 0;
        for ($thistime=$event->starts; $thistime<$event->ends; $thistime+=86400) {
            $wday = strtolower(date('D',$thistime));
            $event->{$wday} = 1;

            // tip: if all 7 days are a Yes by now, just skip the rest
            if ($event->mon and $event->tue and $event->wed and $event->thu and $event->fri and $event->sat and $event->sun) break;
        }

        // ready!
        $event->save();
        $success++;
    }

    // update our last_fetch date
    $this->last_fetch = time();
    $this->save();

    // guess we're done and happy; throw an error  (ha ha)
    $messages = array("Successfully loaded $success events.");
    if ($failed) $messages[] = " Failed to load $failed events due to missing information.";
    $info = array(
        'success'    => $success,
        'malformed'  => $failed,
        'badgeocode' => 0,
        'nocategory' => 0,
        'details'    => $details
    );
    throw new EventDataSourceSuccessException($messages,$info);
}



/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
