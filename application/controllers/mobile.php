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

    // grab all keys whose name starts with mobile_ and stuff 'em into the o
    $data = array();
    foreach ($siteconfig->all() as $key=>$value) {
        if (substr($key,0,7) != 'mobile_') continue;
        $remainder = preg_replace('/^mobile_/', '', $key);
        $data[$remainder] = $value;
    }

    // and print out the resulting CSS stylesheet
    header('Content-type: text/css');
    $this->load->view('mobile/css.phtml', $data);
}
public function image($which='') {
    // we use a switch here so we can ensure that it's a valid SiteConfig key for a known image
    // not as seamless as simply allowing any ol' SiteConfig key, but let's prevent any possible shenanigans involved if we decode any arbitrary user-supplied data for any arbitrary config key
    switch ($which) {
        case 'marker_event':
            $key = 'mobile_event_marker';
            break;
        case 'marker_place':
            $key = 'mobile_place_marker';
            break;
        case 'marker_gps':
            $key = 'mobile_marker_gps';
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
    if (! in_array(@$_POST['eventdays'],array('30','6','0'))) return print "Missing or invalid value for param: eventdays";

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
    $max_distance_squared = 0.16 * 0.16;

    // start the output
    $output = array(
        'places' => array(),
        'events' => array(),
    );

    // PHASE 1
    // Places, with their attendant PlaceActivities (if any)
    // the categories filter is applied here in the ORM query
    $places = new Place();
    if (is_array(@$_POST['categories'])) {
        $places->where_in_related('placecategory', 'id', $_POST['categories']);
    }

    // the data source must not be disabled
    // disabling a data source should "hide" these markers from the front-facing map
    $places->where_related('placedatasource','enabled',1);

    // ready!
    $places->get();
    foreach ($places as $place) {
        // invalid coordinates, skip it
        if (! (float) $place->longitude or ! (float) $place->latitude) continue;

        // distance filter
        $distance_squared = ( ($_POST['lng']-$place->longitude) * ($_POST['lng']-$place->longitude) ) + ( ($_POST['lat']-$place->latitude) * ($_POST['lat']-$place->latitude) );
        if ($distance_squared > $max_distance_squared) continue;

        // guess it's a hit!
        $thisone = array();
        $thisone['id']      = 'place-' . $place->id;
        $thisone['name']    = $place->name;
        $thisone['desc']    = $place->description;
        $thisone['lat']     = (float) $place->latitude;
        $thisone['lng']     = (float) $place->longitude;

        // should we link to the place-info "website" ?
        // only if... there's a description and/or any PlaceActivities     otherwise it's too blank to be interesting
        $placeactivitycount = $place->placeactivity->count();
        $thisone['url'] = site_url("site/place/{$place->id}");
        if (! $place->description and ! $placeactivitycount) $thisone['url'] = "";

        // add the list of categories
        $thisone['categories'] = array();
        foreach ($place->placecategory as $cat) $thisone['categories'][] = $cat->name;

        // does this Place have any PlaceActivity items associated to it?
        // this is a flat list of all name-days-time settings, though I'm told the browser will "aggregate" them by name so as to make a compact layout; not this endpoint's problem...
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
    if (is_array(@$_POST['categories'])) {
        $events->or_group_start();

        $cats = new PlaceCategory();
        $cats->where_in('id',$_POST['categories'])->get();
        foreach ($cats as $cat) $events->like('name', $cat->name)->or_like('description', $cat->name);

        $events->group_end();
    }
    if (is_array(@$_POST['weekdays'])) {
        $events->or_group_start();
        foreach ($_POST['weekdays'] as $wday) $events->where($wday,1);
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
        if ($event->starts > $filter_time_end or $event->ends < $filter_time_start) continue;

        // guess it's a hit!
        $thisone = array();
        $thisone['id']       = 'event-' . $event->id;
        $thisone['name']     = $event->name;
        $thisone['url']      = $event->url;

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

        // any EventLocations?
        if ($event->eventlocation->count()) {
            $thisone['locations'] = array();
            foreach ($event->eventlocation as $loc) {
                $thisloc = array();
                $thisloc['id']        = 'eventlocation-' . $loc->id;
                $thisloc['title']     = $loc->title;
                $thisloc['subtitle']  = $loc->subtitle;
                $thisloc['lat']       = (float) $loc->latitude;
                $thisloc['lng']       = (float) $loc->longitude;

                $thisone['locations'][] = $thisloc;
            }
        }

        // done, stick this Event onto the list
        $output['events'][] = $thisone;
    }

    // done!
    header('Content-type: application/json');
    print json_encode($output);
}



} // end of Controller
