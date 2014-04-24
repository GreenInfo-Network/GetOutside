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
// lat          float, required, the latitude on which to center the search
// lng          float, required, the latitude on which to center the search
// eventdays    integer, optional, optional, for events look this many days into the future. must be one of the specific entries below
// categoryies  multiple integers, optional, for places this checks that the place has this CategoryID# assigned, for events this is a keyword filter for the event name & description
// gender       multiple integers, optional, for events check that this Gender is listed as an intended audience
// agegroup     multiple integers, optional, for events check that this Age Group is listed as an intended audience
// weekdays     multiple integers, optional, for events check that the event would fall upon this weekday (1=Monday, 7=Sunday)
public function fetchdata() {
    // validation can be somewhat terse; there's no way these params would be omitted by the app using this endpoint
    $_POST['lat'] = (float) @$_POST['lat']; if (! $_POST['lat']) return print "Missing param: lat";
    $_POST['lng'] = (float) @$_POST['lng']; if (! $_POST['lng']) return print "Missing param: lng";

    // PREP WORK
    // for event date filtering, today and the next 7 days
    $howmanydays = 30; // default to a month if they didn't say
    if (in_array(@$_POST['eventdays'],array('6','0'))) $howmanydays = (integer) $_POST['eventdays'];
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
    $places = new Place();
    $places->get();
    foreach ($places as $place) {
        // invalid coordinates, skip it
        if (! (float) $place->longitude or ! (float) $place->latitude) continue;

        // distance filter
        $distance_squared = ( ($_POST['lng']-$place->longitude) * ($_POST['lng']-$place->longitude) ) + ( ($_POST['lat']-$place->latitude) * ($_POST['lat']-$place->latitude) );
        if ($distance_squared > $max_distance_squared) continue;

//gda
        // keyword filter, implicit by the selected categories being among this Place's multiple Categories

        // guess it's a hit!
        $thisone = array();
        $thisone['id']      = 'place-' . $place->id;
        $thisone['name']    = $place->name;
        $thisone['desc']    = $place->description;
        $thisone['lat']     = (float) $place->latitude;
        $thisone['lng']     = (float) $place->longitude;
        $thisone['url']     = site_url("site/place/{$place->id}");

        // does this Place have any PlaceActivity items associated to it?
        if ($place->placeactivity->count()) {
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
    $events = new Event();
    $events->get();
    foreach ($events as $event) {
        // the time filter: if it doesn't start this week, or ended last week, it's outta here
        if ($event->starts > $filter_time_end or $event->ends < $filter_time_start) continue;

//gda
        // keyword filter

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
