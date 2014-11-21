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
    // Tip: as we go through and geocode, we cache the lat/lng by address, so that a second geocode on the same address will not hit the geocoder service
    //      this helps runtimes (and API usage counts!) if the events tend to repeat at the same locations a lot, which is quite often true for park-n-rec things
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

        // parse the <summary> tags for location information via the Where: strings
        // if the location just can't be geocoded (e.g. Meeting Room C), then skip it and increment a warning count
        // since g;eocoding can be an admin option of WHICH geocoder, as well as some vebndor-specific behavior such as retrying, that's handed off to a handler
        $where = null;
        preg_match('/[\r\n]+(<br>|<br \/>)Where: (.+?)[\r\n]+/', $entry->summary, $where);
        $where = @$where[2];
        if (! $where) {
            $no_location++;
            $details[] = "No 'Where:' text found: {$event->name}";
            continue;
        }

        // check the geocoder cache for this address; if it's not there, make it be there
        // now that we have the location, bail if it's a failure
        if ( ! array_key_exists($where,$geo_cache)) {
            $geo_cache[$where] = $this->_geocode($where);
        }
        $latlng = $geo_cache[$where];

        if (!$latlng) {
            $no_geocode++;
            $details[] = "Address could not be found: {$where}";
            continue;
        }

        // ready, set, save!
        $loc = new EventLocation();
        $loc->event_id      = $event->id;
        $loc->title         = $where;
        $loc->subtitle      = "";
        $loc->latitude      = $latlng['lat'];
        $loc->longitude     = $latlng['lng'];
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


// the geocoder
// sadly this violates MVC pretty badly: the driver must have access to the website's siteconfig in order to choose a geocoder, then load an API key
// not sure how best to get around that and keep models agnostic of each other       "this is why we can't have nice UML diagrams"  :)
private function _geocode($address) {
    switch ( $this->siteconfig->get('preferred_geocoder') ) {
        case 'bing':
            return $this->_geocode_bing($address);
            break;
        case 'google':
            return $this->_geocode_google($address);
            break;
        default:
            return print "No geocoder enabled?";
            break;
    }
}

private function _geocode_google($address) {
    // key is optional and is added to params if given; address is required
    $key = $this->siteconfig->get('google_api_key');
    if (! $address) return NULL;

    // compose the request and grab it
    $params = array();
    if ($key) $params['key'] = $key;
    $params['address']       =  $address;
    $params['bounds']        = sprintf("%f,%f|%f,%f", $this->siteconfig->get('bbox_s'), $this->siteconfig->get('bbox_w'), $this->siteconfig->get('bbox_n'), $this->siteconfig->get('bbox_e') );
    $url = sprintf("https://maps.googleapis.com/maps/api/geocode/json?%s", http_build_query($params) );
    $result = json_decode(file_get_contents($url));
    if (! @$result->results[0]) return NULL;

    // start building output
    $latlng = array();
    $latlng['lng']  = (float)  $result->results[0]->geometry->location->lng;
    $latlng['lat']  = (float)  $result->results[0]->geometry->location->lat;
    $latlng['s']    = (float)  $result->results[0]->geometry->viewport->southwest->lat;
    $latlng['w']    = (float)  $result->results[0]->geometry->viewport->southwest->lng;
    $latlng['n']    = (float)  $result->results[0]->geometry->viewport->northeast->lat;
    $latlng['e']    = (float)  $result->results[0]->geometry->viewport->northeast->lng;
    $latlng['name'] = (string) $result->results[0]->formatted_address;
    return $latlng;
}

private function _geocode_bing($address) {
    // for Bing geocoding, the API key is required, so bail if it's lacking
    $key = $this->siteconfig->get('bing_api_key');
    if (! $key) return NULL;
    if (! $address) return NULL;

    // hit the service, grok the reply
    $urltemplate = "http://dev.virtualearth.net/REST/v1/Locations?key=%s&output=json&query=%s";
    $geocode     = sprintf($urltemplate, $key, urlencode($address) );
    $geocode     = @json_decode(file_get_contents($geocode));
    $lat = (float) @$geocode->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[0];
    $lng = (float) @$geocode->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[1];

    // if we got nothing, then try again; this time, strip off the first comma-joined element
    // this (sorta) addresses a common use case of prepending the location name:  Cathedral of Saint Paul, 239 Selby Ave, St Paul, MN 55102, United States
    if (! $lat and ! $lng) {
        $address = implode(",", array_slice(explode(",",$address),1) );
        $geocode = sprintf($urltemplate, $key, urlencode($address) );
        $geocode = @json_decode(file_get_contents($geocode));
        $lat = (float) @$geocode->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[0];
        $lng = (float) @$geocode->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[1];
    }

    // done!
    if ($lat and $lng) {
        return array('lat'=>$lat, 'lng'=>$lng);
    } else {
        return NULL;
    }

}



/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
