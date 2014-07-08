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
 * SOME STATIC STUFF pertaining to the "skinning" system  e.g. the MIME/base64 content of the default markers
 * THESE ARE USED EXTERNALLY by Setup Controller to bootstrap these defaults, and by Administration Controller to revert to defaults
 ***************************************************************************************/

public static $DEFAULT_MARKER = array(
    'content' => "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",
    'width'   => 25,
    'height'  => 41,
);
public static $DEFAULT_MARKER_GPS = array(
    'content' => "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",
    'width'   => 25,
    'height'  => 41,
);
public static $DEFAULT_LOGO = array(
    'content' => "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",
    'width'   => 236,
    'height'  => 119,
);
public static $DEFAULT_COLORS = array(
    'bgcolor'          => '#FFFFFF', // global background color
    'fgcolor'          => '#333333', // global background color
    'buttonfgcolor1'   => '#000000', // normal buttons
    'buttonbgcolor1'   => '#FFFFFF', // normal buttons
    'buttonfgcolor2'   => '#000000', // alternate buttons, e.g. search panel
    'buttonbgcolor2'   => '#CCCCCC', // alternate buttons, e.g. search panel
    'alertfgcolor'     => '#FFFFFF', // alert/highlight buttons, e.g. results panel selected item
    'alertbgcolor'     => '#3388cc', // alert/highlight buttons, e.g. results panel selected item
    'markerglowcolor'  => '#ff8000', // icons glow when highlighted
);


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

    // various site config stuff, e.g. metric or imperial units for distance, starting bbox, etc.
    $siteconfig = new SiteConfig();
    $data['metric']             = (integer) $siteconfig->get('metric_units');
    $data['title']              = $this->siteconfig->get('title');
    $data['bing_api_key']       = $this->siteconfig->get('bing_api_key');
    $data['bbox_w']             = $this->siteconfig->get('bbox_w');
    $data['bbox_s']             = $this->siteconfig->get('bbox_s');
    $data['bbox_e']             = $this->siteconfig->get('bbox_e');
    $data['bbox_n']             = $this->siteconfig->get('bbox_n');
    $data['start_x']            = ( $data['bbox_w'] + $data['bbox_e'] ) /2;
    $data['start_y']            = ( $data['bbox_s'] + $data['bbox_n'] ) /2;
    $data['marker_width']       = $this->siteconfig->get('mobile_marker_width');
    $data['marker_height']      = $this->siteconfig->get('mobile_marker_height');
    $data['marker_gps_width']   = $this->siteconfig->get('mobile_marker_gps_width');
    $data['marker_gps_height']  = $this->siteconfig->get('mobile_marker_gps_height');

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
        case 'marker':
            $key = 'mobile_marker';
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
