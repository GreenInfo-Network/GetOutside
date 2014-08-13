<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource_Atom extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"URL", 'help'=>"The URL of the Atom feed."),
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

// hypothetically there's GeoRSS but that never really caught on, so we assume that events don't have a location
var $supports_location = FALSE;


public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','Atom Feed');
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
    if (! preg_match('/^https?:\/\//i', $this->url) ) throw new EventDataSourceErrorException( array('Not a valid web URL.') );

    // no other prep work here; the RSS URL is the RSS URL
    $url = $this->url;

    // fetch the XML, do the most basic check that a <?xml header is given
    // because some errors result in a HTML page or a brief text message
    $xml = @file_get_contents($url);
    if (strpos($xml, '<?xml ') === FALSE) throw new EventDataSourceErrorException( array('Non-XML response from the given URL.') );
    if (strpos($xml, '<feed ') === FALSE) throw new EventDataSourceErrorException( array('Feed is XML but not Atom content. Maybe this is an RSS feed?') );

    // replace $xml from the XML string to a XML parser, or die trying
    try {
        $xml = @new SimpleXMLElement($xml);
    } catch (Exception $e) {
        throw new EventDataSourceErrorException( array('Could not parse response from the given URL. Not a calendar feed?') );
    }

    // check for some known headers, and bail if we don't see them
    $updated = (string) $xml->updated;
    $title   = (string) $xml->title;
    if (!$title or !$updated) throw new EventDataSourceErrorException( array('Feed is XML but lacks Atom headers. Maybe this is an RSS 2.0 feed?') );

    // finally got here, so we're good, dang that's a lot of validation; it'll pay off in the long run  ;)
    // take a moment and delete all of the old Events from this data source
    foreach ($this->event as $old) $old->delete();

    // iterate over entries
    $details = array();
    $success = 0;
    $failed  = 0;
    foreach ($xml->entry as $entry) {
        $event = new Event();
        $event->eventdatasource_id  = $this->id;
        $event->remoteid            = substr( (string) $entry->id, 0, 250);
        $event->name                = substr( (string) $entry->title, 0, 100);
        $event->description         = (string) $entry->content;
    
        // find the last link that's a HTML link, that's our event's more info page (well, Google's rendition of it)
        foreach ($entry->link as $link) {
            if ((string) $link['type'] == 'text/html') $event->url = (string) $link['href'];
        }

        // look for either a Published or Updated tag, or else skip it
        $when = (string) $entry->updated;
        if (! $when) $when = (string) $entry->published;
        if (! $when) { // didn't find any date information, so we can't process it
            $failed++;
            $details[] = "No date information found for {$event->name}";
            continue;
        }

        // the publish/update time given is (presumably) the starting time and there is no concept of an ending time
        // we just punt, call it one hour in length and let it go
        $event->starts = strtotime($when); // Unix timestamp
        $event->ends   = $event->starts + 3600;

        // now, figure out what weekdays intersect this event's duration; sat  sun  mon  ...
        // these are used to quickly search for "events on a Saturday"
        // in this case we don't have an end time, we presume it's one hour on one day, so we figure up the DoW from that one day
        $wday = strtolower(date('D',$event->starts));
        $event->{$wday} = 1;

        // ready!
        $event->save();
        $success++;
    }

    // update our last_fetch date
    $this->last_fetch = time();
    $this->save();

    // guess we're done and happy; throw an error  (ha ha)
    $messages = array("Successfully loaded $success events.");
    if ($failed) $messages[] = " Failed to load $failed events due to missing date.";
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
