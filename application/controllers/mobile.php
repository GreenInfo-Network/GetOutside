<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Mobile extends CI_Controller {
/* the mobile-friendly almost-an-app version of the stuff in /site/  This means search systems, events and maps, etc. but presenting to be an app */

public function __construct() {
    parent::__construct();

    // see whether we're bootstrapped
    if (! $this->db->table_exists('config')) die( redirect(site_url('setup')) );

    // add $this->siteconfig, a link to our configuration settings, which we use practically everywhere
    // and use it to double-check that we're already boostrapped
    $this->load->model('SiteConfig');
    $this->siteconfig = new SiteConfig();
    if (! $this->siteconfig->get('title') ) die( redirect(site_url('setup')) );

    // set the timezone
    // particularly important for Events, both when loading from non-timezone-aware sources but also for rendering those dates both in admin and to public
    date_default_timezone_set( $this->siteconfig->get('timezone') );
}


/***************************************************************************************
 * THE ONLY PAGE: the mobile framework
 * and also some endpoints for fetching data... and for fetching a CSS document
 ***************************************************************************************/

public function index() {
    $data = array();

    // the list of Categories for finding Places
    // in the mockups these are erroneously called Activities but in fact they're arbitrary many-to-many "tags" on Places
    $data['place_categories'] = array();
    $dsx = new PlaceCategory();
    $dsx->where('enabled',1)->get();
    foreach ($dsx as $ds) $data['place_categories'][ $ds->id ] = $ds->name;

    // assign the siteconfig to the template too; we use this for JavaScript definitions: starting bbox, basemap choice, and so on
    $siteconfig = new SiteConfig();
    $data['siteconfig'] = $siteconfig->all();

    // ready, display!
    $this->load->view('mobile/index.phtml',$data);
}


// these endpoints supply dynamic assets for the mobile app, notably the CSS stylesheet and the various images/logos
public function css() {
    $siteconfig = new SiteConfig();

    // grab the whole siteconfig and print out the resulting CSS stylesheet
    $data = $siteconfig->all();
    header('Content-type: text/css');
    $this->load->view('mobile/css.phtml', $data);
}
public function image($which='') {
    // we use a switch here so we can ensure that it's a valid SiteConfig key for a known image
    // not as seamless as simply allowing any ol' SiteConfig key, but let's prevent any possible shenanigans involved if we decode any arbitrary user-supplied data for any arbitrary config key
    switch ($which) {
        case 'marker_event':
            $key = 'event_marker';
            break;
        case 'marker_place':
            $key = 'place_marker';
            break;
        case 'marker_both':
            $key = 'both_marker';
            break;
        case 'marker_gps':
            $key = 'marker_gps';
            break;
        case 'logo':
            $key = 'mobile_logo';
            break;
        default:
            return print "Invalid image";
            break;
    }

    // whatever key we got, fetch it, base64 it, spit it out
    $siteconfig = new SiteConfig();
    $logo = $siteconfig->get($key);
    $logo = base64_decode($logo);
    header('Content-type: image/png');
    print $logo;
}


// this AJAX endpoint is used for searching, it is THE search endpoint. Params include:
// lat          float, required, the latitude on which to center the search
// lng          float, required, the latitude on which to center the search
// eventdays    integer, optional, optional, for events look this many days into the future. must be one of the specific entries below
// categories   multiple integers, optional, for places this checks that the place has this CategoryID# assigned, for events this is a keyword filter for the event name & description
// gender       multiple integers, optional, for events check that this Gender is listed as an intended audience
// agegroup     multiple integers, optional, for events check that this Age Group is listed as an intended audience
// weekdays     multiple strings, optional, for events check that the event would fall upon this weekday: sun mon tue wed thu fri sat
public function fetchdata() {
    // validation can be somewhat terse; there's no way these params would be omitted by the app using this endpoint
    $_POST['lat'] = (float) @$_POST['lat']; if (! $_POST['lat']) return print "Missing param: lat";
    $_POST['lng'] = (float) @$_POST['lng']; if (! $_POST['lng']) return print "Missing param: lng";
    if (! in_array(@$_POST['eventdays'],array('365','30','6','0'))) return print "Missing or invalid value for param: eventdays";

    // PREP WORK
    // for event date filtering, today and the next 7 days
    $howmanydays = (integer) $_POST['eventdays'];
    $year  = date('Y');
    $month = date('m');
    $day   = date('d');
    $filter_time_start = mktime(0,   0,  0, $month, $day, $year);
    $filter_time_end   = mktime(23, 59, 59, $month, $day + $howmanydays, $year);

    // how far can a thing be, to fit the location filter?
    // a crude approximation presuming 1 degree = 60 miles, so 0.16=10 miles
    // tip: distance is sqrt of dX and dY, but why take sqrt when we can compare the square? skip the extra math
    $max_distance_squared = 0.25 * 0.25;

    // start the output
    $output = array(
        'places' => array(),
        'events' => array(),
    );

    // PHASE 1
    // Places, with their attendant PlaceActivities (if any)
    // we have to do this in code cuz the ORM won't do quite what we need, so start by grabbing all enabled Places
    $places = new Place();
    $places->where_related('placedatasource','enabled',1)->get();

    // if they filter by categories, use a WHERE IN query to filtrer by a Place fitting ANY of the given categories
    // tip: using an AND version to match ALL, gives unintuitive results: more interests means fewer hits, and often gives No Results if a place doesn't have both baseball AND dancing
    if (is_array(@$_POST['categories'])) {
        $places->where_in_related('placecategory', 'id', $_POST['categories'] );
    }
    $places->distinct()->get();

    foreach ($places as $place) {
        // invalid coordinates, skip it
        if (! (float) $place->longitude or ! (float) $place->latitude) continue;

        // distance filter
        $distance_squared = ( ($_POST['lng']-$place->longitude) * ($_POST['lng']-$place->longitude) ) + ( ($_POST['lat']-$place->latitude) * ($_POST['lat']-$place->latitude) );
        if ($distance_squared > $max_distance_squared) continue;

        // guess it's a hit!
        $thisone = array();
        $thisone['id']          = 'place-' . $place->id;
        $thisone['name']        = $place->name;
        $thisone['desc']        = $place->description;
        $thisone['lat']         = (float) $place->latitude;
        $thisone['lng']         = (float) $place->longitude;
        $thisone['url']         = $place->url;
        $thisone['urltext']     = $place->url ? ($place->urltext ? $place->urltext : "Website") : "";
        $thisone['url2']        = $place->url2;
        $thisone['urltext2']    = $place->url2 ? ($place->urltext2 ? $place->urltext2 : "More Info") : "";

        // add the list of categories
        $thisone['categories'] = array();
        foreach ($place->placecategory as $cat) $thisone['categories'][] = $cat->name;

        // does this Place have any PlaceActivity items associated to it?
        // this is a flat list of all name-days-time settings, though I'm told the browser will "aggregate" them by name so as to make a compact layout; not this endpoint's problem...
        $placeactivitycount = $place->placeactivity->count();
        if ($placeactivitycount) {
            $thisone['activities'] = array();
            foreach ($place->placeactivity as $activity) {
                $thisact = array();
                $thisact['name'] = $activity->name;

                // what days does this activity happen: string list:   Mon, Wed, Fri
                // usually a list, but then some tricks at the end to make Weekdays or Daily if it fits some known lists
                $thisact['days'] = array();
                if ( (integer) $activity->mon ) $thisact['days'][] = 'Mon';
                if ( (integer) $activity->tue ) $thisact['days'][] = 'Tue';
                if ( (integer) $activity->wed ) $thisact['days'][] = 'Wed';
                if ( (integer) $activity->thu ) $thisact['days'][] = 'Thu';
                if ( (integer) $activity->fri ) $thisact['days'][] = 'Fri';
                if ( (integer) $activity->sat ) $thisact['days'][] = 'Sat';
                if ( (integer) $activity->sun ) $thisact['days'][] = 'Sun';
                $thisact['days'] = implode(", ", $thisact['days'] );
                if ($thisact['days'] == 'Sat, Sun')                             $thisact['days'] = 'Weekends';
                if ($thisact['days'] == 'Mon, Tue, Wed, Thu, Fri')              $thisact['days'] = 'Weekdays';
                if ($thisact['days'] == 'Mon, Tue, Wed, Thu, Fri, Sat, Sun')    $thisact['days'] = 'Daily';

                // make up the start time and end time, converting from the hh:mm:ss format to H:Mam format
                // trick: the timestamp we generate below, is effectively hh:mm minutes after Jan 1 1970, but we only care about the hours/min/ampm
                $starth    = substr($activity->starttime,0,2);
                $startm    = substr($activity->starttime,3,2);
                $endh      = substr($activity->endtime,0,2);
                $endm      = substr($activity->endtime,3,2);
                $starttime = $filter_time_start + 3600*$starth + 60*$startm;
                $endtime   = $filter_time_start + 3600*$endh   + 60*$endm;
                $thisact['start'] = date('g:ia', $starttime );
                $thisact['end']   = date('g:ia', $endtime   );

                // done, add activity to the list
                $thisone['activities'][] = $thisact;
            }
        }

        // done!
        $output['places'][] = $thisone;
    }

    // PHASE 2
    // Events, with their attendant EventLocations (if any)
    // the categories filter is applied here in the ORM query, but first we must resolve the list of Category-IDs onto a list of words (the cats' names)
    $events = new Event();
    if (is_array(@$_POST['weekdays'])) {
        $events->or_group_start();
        foreach ($_POST['weekdays'] as $wday) $events->or_where($wday,1);
        $events->group_end();
    }
    if (is_array(@$_POST['agegroup'])) {
        $events->where_in('audience_age',$_POST['agegroup']);
    }
    if (is_array(@$_POST['gender'])) {
        $events->where_in('audience_gender',$_POST['gender']);
    }

    // the data source must not be disabled
    // disabling a data source should "hide" these markers from the front-facing map
    $events->where_related('eventdatasource','enabled',1);

    // ready!
    $events->get();
    foreach ($events as $event) {
        // the time filter: if it doesn't start this week, or ended last week, it's outta here
        // this filter is done first as it typically eliminates the largest proportion
        if ($event->starts > $filter_time_end or $event->ends < $filter_time_start) continue;

        // no EventLocations? then it can't go onto the map and isn't "near" us; skip it
        // has locations? then collect the ones that are within range and bail if there are none (not close to us, skip)
        if (! $event->eventlocation->count()) continue;
        $locations = array();
        foreach ($event->eventlocation as $loc) {
            $distance_squared = ( ($_POST['lng']-$loc->longitude) * ($_POST['lng']-$loc->longitude) ) + ( ($_POST['lat']-$loc->latitude) * ($_POST['lat']-$loc->latitude) );
            if ($distance_squared > $max_distance_squared) continue;

            $thisloc = array();
            $thisloc['id']        = 'eventlocation-' . $loc->id;
            $thisloc['title']     = $loc->title;
            $thisloc['subtitle']  = $loc->subtitle;
            $thisloc['lat']       = (float) $loc->latitude;
            $thisloc['lng']       = (float) $loc->longitude;

            $locations[] = $thisloc;
        }
        if (! sizeof($locations) ) continue;

        // guess it's a hit!
        // that time above wasn't wasted: we did want the locations anyway
        $thisone = array();
        $thisone['id']        = 'event-' . $event->id;
        $thisone['name']      = $event->name;
        $thisone['url']       = $event->url;
        $thisone['locations'] = $locations;

        // fix some damaged URLs; we should add missing http at the driver layer, but...
        if ($thisone['url'] and substr($thisone['url'],0,4) != 'http') $thisone['url'] = 'http://' . $thisone['url'];

        // compose the "best" time string, which is rather involved: it may or may not span multiple days, may or may not have a time, may or may not span the current year
        // garbage in, garbage out, but we do our best
        if ( (integer) $event->allday ) {
            // All Day event, just list the date... or else say Today
            $today = date('D M j');
            $day   = date('D M j', $event->starts);
            if ($today == $day) {
                $thisone['datetime'] = sprintf("Today %s", 'All day');
            } else {
                $thisone['datetime'] = sprintf("%s %s", $day, 'All day');
            }
        } else if ($event->ends - $event->starts < 86400) {
            // not All Day but does fit onto a single calendar day... and it may be Today
            $today = date('D M j');
            $day   = date('D M j', $event->starts);
            $start = date('g:ia', $event->starts);
            $end   = date('g:ia', $event->ends);
            if ($today == $day) {
                $thisone['datetime'] = sprintf("Today %s - %s", $start, $end);
            } else {
                $thisone['datetime'] = sprintf("%s %s - %s", $day, $start, $end);
            }
        } else {
            // spans multiple days, which means it may or may not stay within the same year, e.g. Gorilla Exhibit At The Zoo could run from March 2012 through August 2015
            $startyear = date('Y', $event->starts);
            $endyear   = date('Y', $event->ends);

            if ($startyear == $endyear) {
                // stays within the same calendar year, so don't bother displaying the year
                $start = date('D M j', $event->starts);
                $end   = date('D M j', $event->ends);
                $thisone['datetime'] = sprintf("%s - %s", $start, $end);
            } else {
                // crosses a calendar year, so display the year component
                $start = date('M j Y', $event->starts);
                $end   = date('M j Y', $event->ends);
                $thisone['datetime'] = sprintf("%s - %s", $start, $end);
            }
        }

        // done, stick this Event onto the list
        $output['events'][] = $thisone;
    }

    // done!
    header('Content-type: application/json');
    print json_encode($output);
}


// the geocode abstrcator: look in our siteconfig and figure out which geocoder to use, and hand back a known format despite whomever we're using
// this is used by both desktop and mobile
public function geocode() {
    // check that we got all params
    $address = trim(@$_GET['address']);
    if (! $address) return print "No address given";

    // have a sub-method hit up the appropriate service
    switch ( $this->siteconfig->get('preferred_geocoder') ) {
        case 'bing':
            $result = $this->_geocode_bing($address);
            break;
        case 'google':
            $result = $this->_geocode_google($address);
            break;
        default:
            return print "No geocoder enabled?";
            break;
    }

    // spit it out as JSON
    header('Content-type: application/json');
    print json_encode($result);
}

private function _geocode_google($address) {
    // compose the request to the REST service; the inclusion of a GMAPI key is optional
    $key = $this->siteconfig->get('google_api_key');
    $params = array();
    if ($key) $params['key'] = $key;
    $params['address']       =  $address;
    $params['bounds']        = sprintf("%f,%f|%f,%f", $this->siteconfig->get('bbox_s'), $this->siteconfig->get('bbox_w'), $this->siteconfig->get('bbox_n'), $this->siteconfig->get('bbox_e') );
    $url = sprintf("https://maps.googleapis.com/maps/api/geocode/json?%s", http_build_query($params) );

    // send it off, parse it, make sure it's valid
    $result = json_decode(file_get_contents($url));
    if (! @$result->results[0]) return print "Could not find that address";

    // start building output
    $output = array();
    $output['lng']  = (float)  $result->results[0]->geometry->location->lng;
    $output['lat']  = (float)  $result->results[0]->geometry->location->lat;
    $output['s']    = (float)  $result->results[0]->geometry->viewport->southwest->lat;
    $output['w']    = (float)  $result->results[0]->geometry->viewport->southwest->lng;
    $output['n']    = (float)  $result->results[0]->geometry->viewport->northeast->lat;
    $output['e']    = (float)  $result->results[0]->geometry->viewport->northeast->lng;
    $output['name'] = (string) $result->results[0]->formatted_address;

    return $output;
}

private function _geocode_bing($address) {
    // compose the request to the REST service
    $params = array();
    $params['key']          = $this->siteconfig->get('bing_api_key');
    $params['output']       = 'json';
    $params['maxResults']   = 1;
    $params['query']        =  $address;
    $params['userMapView']  = sprintf("%f,%f,%f,%f", $this->siteconfig->get('bbox_s'), $this->siteconfig->get('bbox_w'), $this->siteconfig->get('bbox_n'), $this->siteconfig->get('bbox_e') );
    $url = sprintf("http://dev.virtualearth.net/REST/v1/Locations?%s", http_build_query($params) );

    // send it off, parse it, make sure it's valid
    $result = json_decode(file_get_contents($url));
    if ($result->authenticationResultCode != 'ValidCredentials') return print "Bing Maps API key is invalid";
    if (! @$result->resourceSets[0]->resources[0]) return print "Could not find that address";

    // start building output
    $output = array();
    $output['lng']  = (float)  $result->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[1];
    $output['lat']  = (float)  $result->resourceSets[0]->resources[0]->geocodePoints[0]->coordinates[0];
    $output['s']    = (float)  $result->resourceSets[0]->resources[0]->bbox[0];
    $output['w']    = (float)  $result->resourceSets[0]->resources[0]->bbox[1];
    $output['n']    = (float)  $result->resourceSets[0]->resources[0]->bbox[2];
    $output['e']    = (float)  $result->resourceSets[0]->resources[0]->bbox[3];
    $output['name'] = (string) $result->resourceSets[0]->resources[0]->name;

    return $output;
}


} // end of Controller
