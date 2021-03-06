<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource_Active extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

var $option_fields = array(
    'url'     => NULL,
    'option1' => array('required'=>TRUE, 'name'=>"API Key", 'help'=>"Your Active.com API key for the Event Search API v2"),
    'option2' => array('required'=>FALSE, 'name'=>"Org ID", 'help'=>"Optional: Enter your Organization GUID to filter the listings, showing only your events from Active.com. To find your Organization GUID, contact Active.com technical support."),
    'option3' => array('required'=>FALSE, 'name'=>"Skip Place GUID", 'help'=>"Optional: Enter a Place GUID and events at this location will be skipped. This is useful when a majority of events are simply placed at the center of the city, and not at their actual address. Example: &quot;Minneapolis - St Paul&quot; is 78f2df42-a297-4635-a587-282d0578623f"),
    'option4' => NULL,
    'option5' => NULL,
    'option6' => NULL,
    'option7' => array('required'=>FALSE, 'name'=>"Keyword query", 'help'=>"Optional: A phrase used to filter results, e.g. &quot;Citywide Special Events&quot; Events will be skipped if their title does not contain this. Tip: Special characters such as (parentheses) can cause trouble with the Active.com API." ),
    'option8' => array('required'=>FALSE, 'name'=>"Include classes and workshops?", 'help'=>"When fetching events, should classes be included or excluded?", 'options'=>array('0'=>'Excluded', '1'=>'Included') ),
    'option9' => array('required'=>FALSE, 'name'=>"Include conferences and meetings?", 'help'=>"When fetching events, should meetings be included or excluded?", 'options'=>array('0'=>'Excluded', '1'=>'Included') ),
);

// one of very few event data source types, where location exists reliably and in computer-readable form
// reloadContent() will repopulate EventLocations while it updates the Events
var $supports_location = TRUE;


public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','Active.com API');
}



/**********************************************************************************************
 * INSTANCE METHODS
 **********************************************************************************************/

/*
 * reloadContent()
 * Connect to the data source and grab all the events listed, and save them as local Events.
 * By design this is destructive: all existing Events for this EventDataSource are deleted in favof of these new ones.
 */
public function reloadContent() {
    // no API key, that's a problem; even if it's given we check below anyway cuz it may be revoked, expired, or just plain wrong
    $apikey = $this->option1;
    $orgid  = $this->option2;
    if (! preg_match('/^\w+$/', $apikey)) throw new EventDataSourceErrorException( array('Active.com Event Search API v2 requires an API key.') );
    if ($orgid and !preg_match('/^[\w\-]+$/', $orgid)) throw new EventDataSourceErrorException( array('The specified Organization ID is not valid.') );

    // compose the list of what Category names are classes and meetings, and whether we should include these
    // this is used to exclude some events by these broad category areas
    // why an assoc? speedy random access, better than a linear array
    $include_classes  = (integer) $this->option8 ? TRUE : FALSE;
    $include_meetings = (integer) $this->option9 ? TRUE : FALSE;
    $categories_classes = array(
        'Workshops' => TRUE,
        'Classes' => TRUE,
        'Lessons' => TRUE,
        'Clinics' => TRUE,
    );
    $categories_meetings = array(
        'Leagues' => TRUE,
        'Memberships' => TRUE,
        'Conferences' => TRUE,
        'Meetings' => TRUE,
    );

    // is there a Place GUID for skipping events falling at a given location?
    $exclude_place_guid = $this->option3;
    $keyword_filter     = $this->option7;

    // make up the API calls
    // - first fetch is with 0 items per page; gets us a "total_results" attribute so we can begin paging over the results
    // - filter by Org ID (all org events) or else by region (bounding box of the city, 50 miles radius)
    // - the &bbox= param does not work (always empty resultset) so use &lat_lon= and &radius=    (too bad, would be great to use the selected area in the admin panel)
    // - no need to filter by start_date and end_date as expired events won't be in the feed anyway
    // - see below; the &quot= parameter seems not to work properly, doesn't actually filter by event name
    //      so the filter is also applied when we iterate over events to collect $collected_events below
    // - API has a &start_date= parameter, but filtering by start date would exclude an event which started yesterday and perhaps is ongoing into today and tomorrow
    //      so no easy out for us: accept past events in the feed and skip over them when we iterate over events to collect $collected_events below

    $today = date('Y-m-d', time() );

    $params = array();
    $params['sort']         = 'date_asc';
    $params['category']     = 'event';
    $params['per_page']     = 0;
    if ($keyword_filter) $params['query'] = urlencode($keyword_filter);
    if ($orgid) {
        $params['org_id'] = $orgid;
    } else {
        $lat = 0.5 * ( $this->siteconfig->get('bbox_s') + $this->siteconfig->get('bbox_n') );
        $lon = 0.5 * ( $this->siteconfig->get('bbox_w') + $this->siteconfig->get('bbox_e') );
        $params['lat_lon']      = sprintf("%.5f,%.5f", $lat, $lon );
        $params['radius']       = 50;
    }
    $url = sprintf('http://api.amp.active.com/v2/search?api_key=%s&%s', $apikey, http_build_query($params) );
    //throw new EventDataSourceErrorException( array($url) );

    $details = array();

    // make this first request, which is only to figure out how many records we will be fetching
    $content = @json_decode(@file_get_contents($url));
    if (!$content) throw new EventDataSourceErrorException( array("No result structure. Check that this API key is active for the Activity Search API v2") );
    if (!$content->total_results) throw new EventDataSourceErrorException( array("No results. Check that there are in fact activities in this area.") );

    // now iterate in pages of 1000, but with a hard limit of 10,000 in all cuz 10 trips to their server is plenty of waiting
    // and collect a big ol' list of all of the event entries
    $collected_events = array();
    $params['per_page'] = 1000;
    if ($content->total_results > 10*$params['per_page']) $content->total_results = 10*$params['per_page'];
    $pages = ceil($content->total_results / $params['per_page']);
    for ($page=1; $page<=$pages; $page++) {
        $params['current_page'] = $page;
        $url     = sprintf('http://api.amp.active.com/v2/search?api_key=%s&%s', $apikey, http_build_query($params) );
        //throw new EventDataSourceErrorException( array($url) );

        $details[] = sprintf("Parsing %s", $url );

        $content = @json_decode(@file_get_contents($url));
        foreach ($content->results as $entry) {
            // skip any which have child components: these are event containers and not actual events
            if (@$entry->assetComponents and sizeof($entry->assetComponents) ) continue;

            // skip this one if we gave a kqyword-query but the assetName does not match
            // this works around Active.com seeming to return results that don't match the &query= parameter  (not sure what on their end it matches against, if anything)
            if ($keyword_filter and FALSE===strpos($entry->assetName,$keyword_filter) ) continue;

            // skip this event if it's in already over
            // the Active.com API lets you sort by startDate but not endDate
            if ( substr($entry->activityEndDate,0,10) < $today) continue;

            // okay, good
            $collected_events[] = $entry;
        }
    }
    //throw new EventDataSourceErrorException(array( sprintf("Collected %d raw entries", sizeof($collected_events) ) ));
    $details[] = sprintf("Found %d Event records to process", sizeof($collected_events) );

    // preprocessing for recurring events
    // analyze the list of $collected_events and look for any which have activityRecurrences data
    // use this information to create copies of the event $entry and append them to the $collected_events as if they were brand-new events
    // just to keep things interesting, some events have a start date that's specifically invalid for their stated schedule,
    //      e.g. every Wednesday starting January 7 2015.... except for Jan 7 2015
    // tip: do not alter an array while iterating over it, unless you're careful about capping $length first
    /*
    [activityRecurrences] => Array (
            [0] => stdClass Object (
                [activityStartDate] => 2014-12-01T00:00:00
                [startTime] => 8:00:00
                [activityEndDate] => 2015-03-28T00:00:00
                [endTime] => 20:00:00
                [frequencyInterval] => 1
                [frequency] => stdClass Object (
                    [frequencyName] => Weekly
                )
                [days] => Monday, Tuesday, Wednesday, Thursday, Friday, Saturday
                [activityExclusions] => Array (
                    [0] => stdClass Object (
                        [exclusionStartDate] => 2015-01-24T08:00:00
                        [exclusionEndDate] => 2015-01-24T20:00:00
                    )
                    [1] => stdClass Object (
                        [exclusionStartDate] => 2015-01-04T08:00:00
                        [exclusionEndDate] => 2015-01-04T20:00:00
                    )
                )
            )
        )
    */
    $collected_events_after_recurrence_calculations = array();
    for ($i=0, $length=sizeof($collected_events); $i<$length; $i++) {
        // not a recurring event?
        // then stick it onto the final list, having converted its time out of UTC and into local timezone
        if (! sizeof($collected_events[$i]->activityRecurrences) ) {
            $start = explode('T',$collected_events[$i]->activityStartDate);
            $start = new DateTime("{$start[0]} {$start[1]} +00");
            $start->setTimezone(new DateTimeZone($collected_events[$i]->localTimeZoneId));
            $collected_events[$i]->activityUnixStartDate = $start->format('U');

            $end   = explode('T',$collected_events[$i]->activityEndDate);
            $end   = new DateTime("{$end[0]} {$end[1]} +00");
            $end->setTimezone(new DateTimeZone($collected_events[$i]->localTimeZoneId));
            $collected_events[$i]->activityUnixEndDate   = $end->format('U');

            $collected_events_after_recurrence_calculations[] = $collected_events[$i];
            continue;
        }

        // what a nuisance: the recurrence block has time in local, while normal events have it in GMT
        // we'll need to clip the start/end times here, then recompose them below for the recurrence dates... and add the +00 timezone to match normal events
        foreach ($collected_events[$i]->activityRecurrences as $recurinfo) {
            // fetch the first recurrence which has the date and time... as separate fields for some reason
            // paste them together BUT DO NOT simply assume yyyy-mm-ddThh:mmTZ because that would not account for daylight savings (thank goodness it's early March and we see this during testing!)
            // and DO NOT iterate adding 86400 to form each new day, since DST changeovers are 3600 seconds longer or shorter than normal days. Argh!
            // best bet is to compose a string and let strtotime() handle it since it understands DST using the given date

            // phase 0
            // collect the list of no-recurrence dates, e.g. every Monday except 2015-03-02
            $exclude_ymd = array();
            foreach ($recurinfo->activityExclusions as $ex) $exclude_ymd[ substr($ex->exclusionStartDate,0,10) ] = TRUE;
            //print_r($exclude_ymd);

            // phase 1
            // compose a list of dates on which this activity would occur, activityStartDate through activityEndDate
            // but minus any $exclude_ymd
            // and minus any which do not fit the recurrence statement, e.g. Tuesdays and Thursdays but this is a Monday
            $dates = array();
            $date_min   = substr($recurinfo->activityStartDate,0,10);
            $date_max   = substr($recurinfo->activityEndDate,0,10);

            $thisymd    = '0000-00-00';
            $dayoffset  = -1;
            while ($thisymd < $date_max) {
                $dayoffset++;
                $thisunix = mktime(0, 0, 0, substr($date_min,5,2), substr($date_min,8,2) + $dayoffset,  substr($date_min,0,4) );
                $thisymd  = date('Y-m-d', $thisunix);
                $thiswday = date('l',$thisunix);

                // if this date is specifically excluded, then skip it
                if (array_key_exists($thisymd,$exclude_ymd)) continue;

                // does this weekday fit the listed recurrences?
                $recurrence_fits_thisday = FALSE;
                switch ($recurinfo->frequency->frequencyName) {
                    case 'Daily':
                        // event is Daily so today is known to be a match; there is a "days" entry but it's not of use here
                        $recurrence_fits_thisday = TRUE;
                        break;
                    case 'Weekly':
                        // Weekly does not mean once per week, but that the same set of days recurs weekly, e.g. Weekly - Mon, Wed, Fri
                        // so the logic is: is today's Day-of-week on the list?
                        $recurrence_fits_thisday = ( FALSE !== strpos($recurinfo->days,$thiswday) );
                        break;
                }
                if (! $recurrence_fits_thisday) continue;

                // well then, I guess the event really would happen on this yyyy-mm-dd
                $dates[] = $thisymd;
            }
            //print_r($dates);

            // phase 2
            // now that we have a list of dates, concatenate each to the start time and end time
            // these are in local time, and if we stick it alongside each date we have a string that strtotime() can handle, accounting for DST on that date
            // so take a clone of this original event entry, change the time and date, and stick it onto the list as if it were a unique event
            // tip: the 'l' version is not wholly reliable, as it won't necessarily carry over the timezone and account for DST; that's why we go with Unix stamp instead
            $time_start = substr($recurinfo->startTime,0,5);
            $time_end   = substr($recurinfo->endTime  ,0,5);
            $timezone   = $collected_events[$i]->place->timezone;

            foreach ($dates as $thisymd) {
                $this_start      = sprintf("%s %s %s", $thisymd, $time_start, $timezone);
                $this_end        = sprintf("%s %s %s", $thisymd, $time_end  , $timezone);

                $copied = clone($collected_events[$i]);

                $copied->activityUnixStartDate  = strtotime($this_start);
                $copied->activityUnixEndDate    = strtotime($this_end);
                $copied->activityStartDate      = date('l', strtotime($this_start) );
                $copied->activityEndDate        = date('l', strtotime($this_end) );
                //print "Event time: $this_start -- $this_end TRANSLATES TO {$copied->activityStartDate} -- {$copied->activityEndDate}\n";

                $collected_events_after_recurrence_calculations[] = $copied;
            }
        } // end of this event's recurrence type
    } // end of this event

    // swap them into place: originals plus copies
    // why did we do this? we skipped a few along the way: originals of recurrences, should not be in the final output
    // e.g. event starts Jan 7 but Jan 7 is a recurrence exclusion!  (yes really)
    //foreach ($collected_events_after_recurrence_calculations as $entry) {
    //    print "{$entry->assetName} @ {$entry->activityStartDate}  - {$entry->activityEndDate} <br/>\n";
    //}
    $collected_events = $collected_events_after_recurrence_calculations;

    // guess we're ready! delete the existing Events in this source...
    // and also any EventLocations, cuz MySQL isn't smart enough to cacade-delete...
    $howmany_old = $this->event->count();
    foreach ($this->event as $old) {
        foreach ($old->eventlocation as $l) $l->delete();
        $old->delete();
    }
    $details[] = "Clearing out: $howmany_old old Event records";

    // ... then load the new ones
    // Tip: as we go through and geocode, we cache the lat/lng by address, so that a second geocode on the same address will not hit the geocoder service
    //      this helps runtimes (and API usage counts!) if the events tend to repeat at the same locations a lot, which is quite often true for park-n-rec things
    $geo_cache    = array();

    $success      = 0;
    $failed       = 0;
    $nolocation   = 0;
    $nocategory   = 0;
    $skiplocation = 0;
    foreach ($collected_events as $entry) {
        // if we have specified a place GUID to exclude from results, then do so
        if ($exclude_place_guid and @$entry->place->placeGuid == $exclude_place_guid) {
            $skiplocation++;
            $details[] = "Skipping event at specified Place GUID: {$entry->assetGuid}";
            continue;
        }

        // if this Event lacks a location, bail     we only want events which can be plotted onto the map
        // prefer to use the event's place-lat and place-lon when possible,
        // otherwise, try to geocode the given address
        $lat = (float) @$entry->place->geoPoint->lat;
        $lon = (float) @$entry->place->geoPoint->lon;
        if ( (!$lat or !$lon) and @$entry->place->addressLine1Txt) {
            $where = sprintf("%s, %s, %s, %s", $entry->place->addressLine1Txt, $entry->place->cityName, $entry->place->stateProvinceCode, $entry->place->countryCode );
            if ( ! array_key_exists($where,$geo_cache)) {
                $geo_cache[$where] = $this->_geocode($where);
            }
            $lat = $geo_cache[$where] ? $geo_cache[$where]['lat'] : NULL;
            $lon = $geo_cache[$where] ? $geo_cache[$where]['lng'] : NULL;
        }
        if (!$lat or !$lon) {
            // still nothing? forget it
            $nolocation++;
            $details[] = "Bad location: No lat/lon given for {$entry->assetGuid}";
            continue;
        }

        // if this event is in a subcategory not interesting to us, skip it
        // known list to date:
        // Workshops
        // Classes
        // Lessons
        // Clinics
        // Memberships
        // Conferences
        // Meetings
        // Trail heads
        // Camps
        // Clubs
        // Event
        // Leagues
        // Races
        // Tournaments

        $category = @$entry->assetCategories[0]->category->categoryName;
        if (! $include_classes  and array_key_exists($category,$categories_classes))   { $nocategory++; $details[] = "Skipping classes: {$event->name}";  continue; }
        if (! $include_meetings and array_key_exists($category,$categories_meetings))  { $nocategory++; $details[] = "Skipping meetings: {$event->name}"; continue; }

        // find an URL
        // a few iterations here, trying to find something that for the largest majority of events,
        //      will not bounce to a login or registration page
        $url = NULL;
        if ($entry->assetLegacyData->substitutionUrl) {
            preg_match('/activity_id=(\d+)/', $entry->assetLegacyData->substitutionUrl, $url);
            if ($url) $url = "https://apm.activecommunities.com/saintpaul/Activity_Search/{$url[1]}";
        }
        if (! $url) $url = @$entry->preferredUrlAdr;
        if (! $url) $url = @$entry->assetLegacyData->seoUrl;
        if (! $url) $url = @$entry->seoUrl;
        if (! $url) $url = @$entry->urlAdr;
        if (! $url) $url = @$entry->homePageUrlAdr;
        if (! $url) $url = @$entry->resultsUrlAdr;
        if (! $url) $url = @$entry->registrationUrlAdr;

        // compose a name: many events have a hierarchical name: Summer - Family - Kids - Basket Weaving
        // strip off the last 2 of these and use them as the event name
        // this may or may not be a idiosyncracy of StPaul's data; they're the target client here so real goal is to make their data look good and not worry about anyone else's
        $event_name = $entry->assetName;
        if (strpos(' - ', $event_name) != -1) {
            $event_name = explode(' - ',$event_name);
            $event_name = array_slice($event_name,-2);
            $event_name = implode(' - ', $event_name);
        }
        $event_name = substr($event_name,0,100);

        // ready!
        $event = new Event();
        $event->eventdatasource_id  = $this->id;
        $event->remoteid            = $entry->assetGuid;
        $event->starts              = $entry->activityUnixStartDate; // Unix timestamp
        $event->ends                = $entry->activityUnixEndDate; // Unix timestamp
        $event->name                = $event_name;
        $event->url                 = $url;
        $event->description         = ""; // real-world descriptions are multi-kilobyte HTML, looks awful; better to skip- it and let the event's web page tell the story
        //$event->description         = (string) @$entry->assetDescriptions[0]->description;

        // name is required
        if (!$event->name) { $failed++; $details[] = "Name missing: Event ID {$event->remoteid}"; continue; }

        // tag the event as being recurring or not
        // internally this is not used for date calculations (except for that preprocessing step where we created new $entry items anyway)
        // but can be useful for metadata purposes, e.g. notifying folks that they should read the schedule
        $event->recurs = sizeof($entry->activityRecurrences) ? 1 : 0;

        // now, on what days of the week does this event happen? these are used to quickly search for "events on a Saturday"
        $event->mon = $event->tue = $event->wed = $event->thu = $event->fri = $event->sat = $event->sun = 0;
        for ($thistime=$event->starts; $thistime<$event->ends; $thistime+=86400) {
            $wday = strtolower(date('D',$thistime));
            $event->{$wday} = 1;

            // tip: if all 7 days are a Yes by now, just skip the rest
            if ($event->mon and $event->tue and $event->wed and $event->thu and $event->fri and $event->sat and $event->sun) break;
        }

        // Gender requirements?   Active.com API has regReqGenderCd which may be M F or blank
        // blank in all cases I've ever seen to date, so this is based on asking tech support...
        // see mobile/index.phtml for the official list of coded values AND BE AWARE THAT MySQL forces these to be STRINGS AND NOT NUMBERS
        // this would be used for filtering events by gender, which may or may not be of ultimate use since no events here have that field populated
        switch ($entry->regReqGenderCd) {
            case 'M':
                $event->audience_gender = '1';
                break;
            case 'F':
                $event->audience_gender = '2';
                break;
            default:
                $event->audience_gender = '0';
                break;
        }

        // Age requirements?  Active.com has integer fields (well, integer strings) regReqMinAge and regReqMaxAge
        // and we should massage this to find the best match 0-5: 1=Infants, 2=Preschool, 3=Youth/Teens, 4=Adults, 5=Senior, 0=All Ages
        // these aren't really seen in the data feed so far, and are based on asking Active.com's tech support
        // see mobile/index.phtml for the official list of coded values AND BE AWARE THAT MySQL forces these to be STRINGS AND NOT NUMBERS
        // this would be used for filtering events by age, which may or may not be of ultimate use since no events here have that field populated
        $event->audience_age = '0'; // start with All Ages by default
        $entry->regReqMinAge = (integer) $entry->regReqMinAge;
        $entry->regReqMaxAge = (integer) $entry->regReqMaxAge;
        if ($entry->regReqMinAge >= 40) {
            $event->audience_age = '5';
        } else if ($entry->regReqMinAge >= 18) {
            $event->audience_age = '4';
        } else if ($entry->regReqMaxAge <= 25) {
            $event->audience_age = '3';
        } else if ($entry->regReqMaxAge <= 5) {
            $event->audience_age = '2';
        } else if ($entry->regReqMaxAge <= 3) {
            $event->audience_age = '1';
        }

        // on what days of the week does this event happen? these are used to quickly search for "events on a Saturday"
        for ($thistime=$event->starts; $thistime<$event->ends; $thistime+=86400) {
            $wday = strtolower(date('D',$thistime));
            $event->{$wday} = 1;
            // tip: if all 7 days are a Yes by now, just skip the rest
            if ($event->mon and $event->tue and $event->wed and $event->thu and $event->fri and $event->sat and $event->sun) break;
        }

        // ready!
        $event->save();
        $success++;

        // DONE with the Event itself; now create the EventLocation
        $loc = new EventLocation();
        $loc->event_id      = $event->id;
        $loc->latitude      = $lat;
        $loc->longitude     = $lon;
        $loc->title         = (string) $entry->place->placeName;
        $loc->subtitle      = sprintf("%s %s %s", $entry->place->addressLine1Txt, $entry->place->addressLine2Txt, $entry->place->cityName );
        $loc->save();
    }

    // update our last_fetch date
    $this->last_fetch = time();
    $this->save();

    // guess we're done and happy; throw an error  (ha ha)
    $messages = array();
    $messages[] = "Successfully loaded $success events.";
    if ($failed)        $messages[] = "$failed events skipped due to blank/missing name.";
    if ($nolocation)    $messages[] = "$nolocation events skipped due to no location.";
    if ($skiplocation)  $messages[] = "$skiplocation events skipped due to location specifically excluded by Place GUID.";
    if ($nocategory)    $messages[] = "$nocategory events excluded due to category filters.";
    $info = array(
        'success'    => $success,
        'malformed'  => $failed,
        'badgeocode' => $nolocation,
        'nocategory' => $nocategory,
        'details'    => $details
    );
    throw new EventDataSourceSuccessException($messages,$info);
}



// the geocoder
// sadly this violates MVC and model separation pretty thoroughly
// the driver needs to be passed a siteconfig, to know which geocoder to use
// "this is why we can't have nice UML diagrams"  :)
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
