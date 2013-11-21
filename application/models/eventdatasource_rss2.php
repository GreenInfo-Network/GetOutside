<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource_RSS2 extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"URL", 'help'=>"The URL of the RSS feed."),
    'option1' => NULL,
    'option2' => NULL,
    'option3' => NULL,
    'option4' => NULL,
);


public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','RSS 2.0 Feed');
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
    if (! preg_match('/^https?:\/\//i', $this->url) ) throw new EventDataSourceErrorException('Not a valid web URL.');

    // no other prep work here; the RSS URL is the RSS URL
    $url = $this->url;

    // fetch the XML, do the most basic check that a <?xml header is given
    // because some errors result in a HTML page or a brief text message
    $xml = @file_get_contents($url);
    if (strpos($xml, '<?xml ') === FALSE) throw new EventDataSourceErrorException('Non-XML response from the given URL.');
    if (strpos($xml, '<rss version="2.0"') === FALSE) throw new EventDataSourceErrorException('Feed is XML but not RSS 2.0 content. Maybe this is an Atom feed?');

    // replace $xml from the XML string to a XML parser, or die trying
    try {
        $xml = @new SimpleXMLElement($xml);
    } catch (Exception $e) {
        throw new EventDataSourceErrorException('Could not parse response from the given URL. Not a calendar feed?');
    }

    // check for known must-have headers, and bail if we don't see them
    $link    = (string) $xml->channel->link;
    $title   = (string) $xml->channel->title;
    if (!$title or !$link) throw new EventDataSourceErrorException('Feed is XML but lacks RSS 2.0 headers. Maybe this is an Atom feed?');

    // finally got here, so we're good, dang that's a lot of validation; it'll pay off in the long run  ;)
    // take a moment and delete all of the old Events from this data source
    foreach ($this->event as $old) $old->delete();

    // iterate over entries
    $success = 0;
    $failed  = 0;
    foreach ($xml->channel->item as $entry) {
        // try to find the <guid> tag, or fail over to a link, or just fail and have no unique ID
        $guid = (string) $entry->guid;
        if (! $guid) $guid = (string) $entry->link;
        if (! $guid) $guid = '';

        // try to find a <title> field, or else fail over to cropping the description (yeah, it's legal to lack a title)
        $name = (string) $entry->title;
        if (! $name) $name = (string) $entry->content;

        $event = new Event();
        $event->eventdatasource_id  = $this->id;
        $event->remoteid            = $guid;
        $event->url                 = (string) $entry->link;
        $event->name                = substr($name, 0, 50);
        $event->description         = (string) $entry->content;
        $event->allday              = 1; // RSS 2 lacks times, all we get is a pub date, so assume it's All Day

        // parse the date & time into a Unix timestamp
        $when = @date_parse( (string) $entry->pubDate );
        if (! $when) { // can't figure out the date, it must be bad so give up
            $failed++;
            continue;
        }
        $month = $when['month'];
        $day   = $when['day'];
        $year  = $when['year'];
        $event->starts = mktime( 0,  0,  0, $month, $day, $year); // Unix timestamp
        $event->ends   = mktime(23, 59, 59, $month, $day, $year); // Unix timestamp

        $event->save();
        $success++;
    }

    // update our last_fetch date
    $this->last_fetch = time();
    $this->save();

    // guess we're done and happy; throw an error  (ha ha)
    $message = "Successfully loaded $success events.";
    if ($failed) $message .= " Failed to load $failed events due to missing date.";
    throw new EventDataSourceSuccessException($message);
}



/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
