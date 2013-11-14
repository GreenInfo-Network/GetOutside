<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource_GoogleCalendar extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','Google Calendar');
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
    // make sure no shenanigans: all Google feed URLs start with https and www.google.com
    // strip off any query params, and replace the default /basic "projection" component with a /full-noattendees so we get full info
    $url = $this->url;
    if (strpos($url,'https://www.google.com/calendar/') !== 0) throw new EventDataSourceErrorException('Not a valid HTTPS URL at Google.');
    $url = preg_replace('/\?.*/', '', $url);
    $url = preg_replace('/basic$/', 'full-noattendees', $url);

    // expand upon the base URL, adding parameters to fetch only events today and foreard, to a maximum of 6 months
    // note that we "round" the current date to midnight, so we will show events today which have already started
    // also note that we must specify a max-results, so we also give an orderby
    $month = (integer) date('m');
    $date  = (integer) date('d');
    $year  = (integer) date('Y');
    $params = array(
        'singleevents' => 'true',
        'start-min' => date(DATE_ATOM, mktime(0, 0, 0, $month, $date, $year) ),
        'start-max' => date(DATE_ATOM, mktime(0, 0, 0, $month+6, $date, $year) ),
        //'prettyprint' => 'true',
        'max-results' => 250,
        'orderby' => 'starttime',
    );
    $url = sprintf("%s?%s", $url, http_build_query($params) );

    // fetch the XML, do the most basic check that a <?xml header is given
    // because some errors result in a HTML page or a brief text message
    $xml = @file_get_contents($url);
    if (substr($xml,0,6) != '<?xml ') throw new EventDataSourceErrorException('Non-XML response from the given URL. Not a calendar feed?');

    // replace $xml from the XML string to a XML parser, or die trying
    try {
        $xml = @new SimpleXMLElement($xml);
    } catch (Exception $e) {
        throw new EventDataSourceErrorException('Could not parse response from the given URL. Not a calendar feed?');
    }

    // check for some known headers, and bail if we don't see them
    $updated = (string) $xml->updated;
    $title   = (string) $xml->title;
    if (!$title or !$updated) throw new EventDataSourceErrorException('Got XML but no content.');

    // finally got here, so we're good, dang that's a lot of validation; it'll pay off in the long run  ;)
    // take a moment and delete all of the old Events from this data source
    foreach ($this->event as $old) $old->delete();

    // iterate over entries
    $howmany = 0;
    foreach ($xml->entry as $entry) {
        $event = new Event();
        $event->eventdatasource_id  = $this->id;
        $event->remoteid            = substr( (string) $entry->id, 0, 250);
        $event->name                = substr( (string) $entry->title, 0, 50);
        $event->description         = (string) $entry->content;

        // find the last link that's a HTML link, that's our event's more info page (well, Google's rendition of it)
        foreach ($entry->link as $link) {
            if ((string) $link['type'] == 'text/html') $event->url = (string) $link['href'];
        }

        // parse the date & time into a Unix timestamp
        $when = $entry->xpath('gd:when');
        $start = (string) $when[0]['startTime'];
        $end   = (string) $when[0]['endTime'];
        $event->starts = strtotime($start); // Unix timestamp
        $event->ends   = strtotime($end);   // Unix timestamp

        // parse the date & time and see if it's at 00:00:00
        // if so, then it's an All Day event
        $start_midnight = date_parse($start);
        $end_midnight   = date_parse($end);
        $event->allday = (!$start_midnight['hour'] and !$start_midnight['minute'] and !$end_midnight['hour'] and !$end_midnight['minute']);
        $event->save();

        $howmany++;
    }

    // update our last_fetch date
    $this->last_fetch = time();
    $this->save();

    // guess we're done and happyl; throw an error  (ha ha)
    throw new EventDataSourceSuccessException("Successfully loaded $howmany events.");
}



/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
