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
 ***************************************************************************************/

public function index() {
    $data = array();

    // the list of Categories for finding Places
    // in the mockups these are erroneously called Activities but in fact they're arbitrary many-to-many "tags" on Places
    $data['place_categories'] = array();
    $data['place_categories'][''] = "Select an activity (optional)";
    $dsx = new PlaceCategory();
    $dsx->where('enabled',1)->get();
    foreach ($dsx as $ds) $data['place_categories'][ $ds->id ] = $ds->name;

    // directions UI: use metric or imperial?
    $siteconfig = new SiteConfig();
    $data['metric'] = (integer) $siteconfig->get('metric_units');

    // ready, display!
    $this->load->view('mobile/index.phtml',$data);
}


// this AJAX endpoint is used for searching, it is THE search endpoint. Params include:
// lat      float, required, the latitude on which to center the search
// lng      float, required, the latitude on which to center the search
// 
// 
// 
public function fetchdata() {
    // validation can be somewhat terse; there's no way these params would be omitted by the app using this endpoint
    $_POST['lat'] = (float) @$_POST['lat']; if (! $_POST['lat']) return print "Missing param: lat";
    $_POST['lng'] = (float) @$_POST['lng']; if (! $_POST['lng']) return print "Missing param: lng";

    // PREP WORK
    // for event date filtering, today and the next 7 days
    $year  = date('Y');
    $month = date('m');
    $day   = date('d');
    $filter_time_start = mktime(0,   0,  0, $month, $day, $year);
    $filter_time_end   = mktime(23, 59, 59, $month, $day + 7, $year);

    // how far can a thing be, to fit the location filter?
    // a crude approximation presuming 1 degree = 60 miles, so 0.16=10 miles
    // tip: distance is sqrt of dX and dY, but why take sqrt when we can compare the square? skip the extra math
    $max_distance_squared = 0.16 * 0.16;

    // start the output
    $output = array(
        'places' => array(),
        'events' => array(),
    );

    // PHASE 1a
    // Places
    $places = new Place();
    $places->get();
    foreach ($places as $place) {
        // invalid coordinates, skip it
        if (! (float) $place->longitude or ! (float) $place->latitude) continue;

        // distance filter
        $distance_squared = ( ($_POST['lng']-$place->longitude) * ($_POST['lng']-$place->longitude) ) + ( ($_POST['lat']-$place->latitude) * ($_POST['lat']-$place->latitude) );
        if ($distance_squared > $max_distance_squared) continue;

//gda
        // keyword filter

        // guess it's a hit!
        $thisone = array();
        $thisone['id']      = (integer) $place->id;
        $thisone['name']    = $place->name;
        $thisone['desc']    = $place->description;
        $thisone['lat']     = (float) $place->latitude;
        $thisone['lng']     = (float) $place->longitude;
        $thisone['url']     = site_url("site/place/{$place->id}");

        $thisone['category_names'] = $place->listCategoryNames();

        $output['places'][] = $thisone;
    }

    // PHASE 1b
    // now Events which have a location; emulate the same structure as Places as merge it right in
    $events = new EventLocation();
    $events->get();
    foreach ($events as $event) {
        // the time filter: if it doesn't start this week, or ended last week, it's outta here
        if ($event->event->starts > $filter_time_end or $event->event->ends < $filter_time_start) continue;

        // distance filter
        $distance_squared = ( ($_POST['lng']-$event->longitude) * ($_POST['lng']-$event->longitude) ) + ( ($_POST['lat']-$event->latitude) * ($_POST['lat']-$event->latitude) );
        if ($distance_squared > $max_distance_squared) continue;

//gda
        // keyword filter

        // guess it's a hit!
        $thisone = array();
        $thisone['id']       = (integer) $event->id;
        $thisone['name']     = $event->event->name;
        $thisone['url']      = $event->event->url;
        $thisone['desc']     = $event->event->description;
        $thisone['title']    = $event->title;
        $thisone['subtitle'] = $event->subtitle;
        $thisone['lat']      = (float) $event->latitude;
        $thisone['lng']      = (float) $event->longitude;

        $thisone['category_names'] = array('Event');

        $output['places'][] = $thisone;
    }

    // PHASE 2a
    // Events fitting the "next X days" filter defined above
    // these have no location, but do accept the submitted Categories (erroneously called Activities & Amenities) as a keyword filter
    $output['events'] = array();
    $events = new Event();
    $events->get();
    foreach ($events as $event) {
        // the time filter: if it doesn't start this week, or ended last week, it's outta here
        if ($event->starts > $filter_time_end or $event->ends < $filter_time_start) continue;

//gda
        // keyword filter

        // guess it's a hit!
        $thisone = array();
        $thisone['name']     = $event->name;
        $thisone['url']      = $event->url;
        $thisone['subtitle'] = '';

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

        // epimetheus: the ending unix timestamp, used for sorting after we have collected all events
        $thisone['endtime'] = $event->ends;

        // ready!
        $output['events'][] = $thisone;
    }

    // PHASE 2b
    // Place Activities, which are "open hours" for a given Place
    // this means that they clearly have a location, though calculating the dates on which they would happen is interesting cuz they're weekday recurrences
    // strategy: iterate over the next 7 days, do a PlaceActivity query for activities which have that weekday, add to the list
    $activities = new PlaceActivity();
    $year   = date('Y');
    $month  = date('m');
    $day    = date('d');

    for($i=0; $i<7; $i++) {
        $then    = mktime(0, 0, 0, $month, $day+$i, $year);
        $weekday = strtolower(date('D',$then));
        $date    = date('D M j', $then);

        $activities->where($weekday,1)->get();
        foreach ($activities as $activity) {
            $thisone = array();
            $thisone['name']        = $activity->name;
            $thisone['subtitle']    = $activity->place->name;
            $thisone['lat']         = $activity->place->latitude;
            $thisone['lng']         = $activity->place->longitude;

            // make up the start time and end time, from the xx:xx:xx format to H:Mam format
            $starth    = substr($activity->starttime,0,2);
            $startm    = substr($activity->starttime,3,2);
            $endh      = substr($activity->endtime,0,2);
            $endm      = substr($activity->endtime,3,2);
            $starttime = $then + 3600*$starth + 60*$startm;
            $endtime   = $then + 3600*$endh   + 60*$endm;
            $start     = date('g:ia', $starttime );
            $end       = date('g:ia', $endtime   );
            $thisone['datetime'] = sprintf("%s %s - %s", $i ? $date : 'Today', $start, $end );

            // epimetheus: the ending unix timestamp, used for sorting after we have collected all events
            $thisone['endtime'] = $endtime;

            // ready!
            $output['events'][] = $thisone;
        }
    }

    // CLEANUP PHASE
    // sort the Events by their ending date cuz they come from 2 separate lists
    // turns out that removing this unwanted field before sending to the browser, is slightly more intensive than just letting it download, so leave it
    usort($output['events'],create_function('$p,$q','return $p["endtime"] < $q["endtime"] ? -1 : 1;'));

    // done!
    header('Content-type: application/json');
    print json_encode($output);
}



} // end of Controller
