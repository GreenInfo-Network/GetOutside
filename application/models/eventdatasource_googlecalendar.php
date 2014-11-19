<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource_GoogleCalendar extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"Calendar XML URL", 'help'=>"The URL should be the XML version of your calendar. See <a class=\"link\" target=\"_blank\" href=\"https://support.google.com/calendar/answer/37103?hl=en\">Google's web site</a> for instructions on finding the URL of your calendar."),
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

// Google Calendar content has a Location field, but this isn't exposed via the API
// so I was told to look for there "Where:" strings in the XML and just make do
// real-world performance of this will be quite poor, and will make the datasource loads take a long time, likely even time out, ... but I was told to just do it and we'll figure that out later
var $supports_location = TRUE;


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
 *
 * Big problem with Google's XML feeds:
 *  the Basic feed has a summary with a Where: field and the location string which could hypothetically be geocoded,
 *  while the Full feed is the one with the starttime and endtime and the descriptions
 *  naturally we want both, so we hit the service twice then try to crosswalk the missing <summary> field onto the Full feed output
 */
public function reloadContent() {
    // make sure no shenanigans: all Google feed URLs start with https and www.google.com
    // strip off any query params, and replace the default /basic "projection" component with a /full-noattendees so we get full info
    // but also take one that does end in /basic/ so we can get at the precious <summary> tags
    $url = $this->url;
    if (strpos($url,'https://www.google.com/calendar/') !== 0) throw new EventDataSourceErrorException( array('Not a valid HTTPS URL at Google.') );
    $url = preg_replace('/\?.*/', '', $url);
    $url = preg_replace('/basic$/', '', $url);
    $url = preg_replace('/full$/', '', $url);
    $url = preg_replace('/full-noattendees$/', '', $url);
    $url = preg_replace('/\/$/', '', $url);

    // expand upon the base URL, adding parameters to fetch only events today and foreard, to a maximum of 6 months
    // note that we "round" the current date to midnight, so we will show events today which have already started
    // also note that we must specify a max-results, so we also give an orderby
    $month = (integer) date('m');
    $date  = (integer) date('d');
    $year  = (integer) date('Y');
    $params = array(
        'singleevents' => 'true',
        'start-min' => date(DATE_ATOM, mktime(0, 0, 0, $month, 1, $year) ),
        'start-max' => date(DATE_ATOM, mktime(0, 0, 0, $month+6, $date, $year) ),
        'prettyprint' => 'true',
        'max-results' => 250,
        'orderby' => 'starttime',
    );
    $url = sprintf("%s/%s?%s", $url, 'basic', http_build_query($params) );
    $xml = @file_get_contents($url);
    if (substr($xml,0,6) != '<?xml ') throw new EventDataSourceErrorException( array('Non-XML response from the given URL (basic). Not a calendar feed?') );

    // replace the $xml variable with the parsed XML document... or die trying
    try {
        $xml = @new SimpleXMLElement($xml);
    } catch (Exception $e) {
        throw new EventDataSourceErrorException( array('Could not parse response from the given URL (basic). Not a calendar feed?') );
    }
    if (!$xml->entry) throw new EventDataSourceErrorException( array('Got XML but no content.') );

    $details = array();
    $details[] = "Successfully parsed $url";
    $details[] = sprintf("Found %d events to process", sizeof($xml->entry) );

    // finally got here, so we're good, dang that's a lot of validation; it'll pay off in the long run  ;)
    // take a moment and delete all of the old Events from this data source
    $howmany_old = $this->event->count();
    foreach ($this->event as $old) $old->delete();
    $details[] = "Clearing out: $howmany_old old Event records";

    // iterate over entries and use them to construct our records
    // splicing onto them the $summaries entry from the Basic feed   so we can extract the Where: info from them
    // WARNING: as of now, the driver uses intimate knowledge of the framework context (siteconfig) which hypothetically should be in the Controller...
    //          but how is that gonna happen, without the Controller introspecting the driver details in ways which also violate MVC...?
    // Tip: as we go through and geocode, we cache the lat/lng by address, so that a second geocode on the same address will not require the geocoder service
    //      this helps runtimes if the events tend to repeat at the same locations a lot, which is quite often true for park-n-rec things
    $bing_key   = $this->siteconfig->get('bing_api_key');
    $geo_cache  = array();

    $howmany     = 0;
    $no_geocode  = 0;
    $no_location = 0;
    foreach ($xml->entry as $entry) {
        $event = new Event();
        $event->eventdatasource_id  = $this->id;
        $event->remoteid            = basename( (string) $entry->id );
        $event->name                = substr( (string) $entry->title, 0, 100);
        $event->description         = (string) $entry->content;

        // find the last link that's a HTML link, that's our event's more info page (well, Google's rendition of it)
        foreach ($entry->link as $link) {
            if ((string) $link['type'] == 'text/html') $event->url = (string) $link['href'];
        }

        // parse the date & time into a Unix timestamp
        // these are in the text as the When: string and requires some parsing of the "to" text to determine the ending time
        // Examples:
        //      When: Thu Jan 22, 2015
        //      When: Tue Oct 28, 2014 6pm to Tue Oct 28, 2014 7:30pm
        //      When: Wed Oct 29, 2014 3:30pm to 4:30pm 

        $timestring = null;
        preg_match('/When: ([\w\s\,\:]+)/', $entry->summary, $timestring);
        $timestring = $timestring[1];
        if ( strpos($timestring, ' to ') === FALSE) {
            // All Day event -- only a date was given and no times
            $event->starts = strtotime($timestring);
            $event->ends   = $event->starts + 86399;
            $event->allday = 1;
        } else {
            // "to" was found so it's not All Day
            // split on the "to" string, and perhaps prepend the date component to the second half if it's missing  (see note above, re formats)
            $from = explode(' to ',$timestring);
            $to   = $from[1];
            $from = $from[0];

            // ending time may or may not have date prepended; inconsistent behavior in the RSS output
            if ( preg_match('/^\d/',$to) ) $to = implode(' ',array_slice(explode(' ',$from),0,4)) . " $to";

            $event->starts = strtotime($from);
            $event->ends   = strtotime($to);
        }

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
        $howmany++;

        // afterthought
        // the point of splicing on the <summary> tags was so we can parse them for probable location information via the Where: strings
        // if the <summary> tag can't be found, or is empty, or just can't be geocoded, then skip it and increment that warning count
        // also, bail condition: if we don't have a Bing API key configured, we must skip this
        // WARNING: this does mean that the driver has intimate knowledge of the framework context (siteconfig) which violates MVC principles
        //          but I'm not coming up with another way to do it, without the Controller getting into the Model and violating MVC anyway...
        if (!$bing_key) {
            $no_geocode++;
            $details[] = "Bing API key not set; skipping address lookup for {$event->name}";
            continue;
        }

        $where = null;
        preg_match('/[\r\n]+(<br>|<br \/>)Where: (.+?)[\r\n]+/', $entry->summary, $where);
        $where = @$where[2];
        if (! $where) {
            $no_location++;
            $details[] = "No 'Where:' text found: {$event->name}";
            continue;
        }

        if (array_key_exists($where,$geo_cache)) {
            // this address has previously been cached, so just load it from there
            // the check for 0 takes place below, so the cache can in fact contain 0,0 points for known fails
            $lat = $geo_cache[$where]['lat'];
            $lng = $geo_cache[$where]['lng'];
        } else {
            // not in the cache, so geocode it...
            $geocode = sprintf("http://dev.virtualearth.net/REST/v1/Locations?key=%s&output=json&query=%s",
                $bing_key, urlencode($where)
            );
            $geocode = @json_decode(file_get_contents($geocode));
            $lat = (float) @$geocode->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[0];
            $lng = (float) @$geocode->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[1];

            // catch: if the $lat $lng are null, geocode failed
            // see if we can trim off the first comma-joined element of the address and try again
            // this (sorta) addresses a common use case of prepending the location name:  Cathedral of Saint Paul, 239 Selby Ave, St Paul, MN 55102, United States
            if (! $lat and ! $lng) {
                $whereagain = implode(",", array_slice(explode(",",$where),1) );
                $geocode = sprintf("http://dev.virtualearth.net/REST/v1/Locations?key=%s&output=json&query=%s",
                    $bing_key, urlencode($whereagain)
                );
                $geocode = @json_decode(file_get_contents($geocode));
                $lat = (float) @$geocode->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[0];
                $lng = (float) @$geocode->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[1];
            }

            // ... then put it into the cache
            // note that this may have failed, but the casting will turn them into 0s if so
            // a 0,0 result is valid and specifically indicates that the address failed
            $geo_cache[$where] = array( 'lat'=>$lat, 'lng'=>$lng );
        }
        if (!$lat or !$lng) {
            $no_geocode++;
            $details[] = "Address could not be found: {$where}";
            continue;
        }

        $loc = new EventLocation();
        $loc->event_id      = $event->id;
        $loc->latitude      = $lat;
        $loc->longitude     = $lng;
        $loc->title         = $event->name;
        $loc->subtitle      = $where;
        $loc->save();
    }

    // update our last_fetch date
    $this->last_fetch = time();
    $this->save();

    // guess we're done and happy; throw an error  (ha ha)
    $messages = array();
    $messages[] = "Successfully loaded $howmany events.";
    if ($no_location) $messages[] = "$no_location events had no location given.";
    if ($no_geocode)  $messages[] = "$no_geocode events had a location which could not be found. Check that complete addresses are used.";
    $info = array(
        'success'    => $howmany,
        'malformed'  => $no_location,
        'badgeocode' => $no_geocode,
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
