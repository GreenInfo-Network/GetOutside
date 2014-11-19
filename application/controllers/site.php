<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Site extends CI_Controller {
/* Pages under /site/ are intended for the public. This comprises the majority of the site, with notable exception being the administration panel. */

public function __construct() {
    parent::__construct();

    // see whether we're bootstrapped
    if (! $this->db->table_exists('config')) die( redirect(site_url('setup')) );

    // add $this->siteconfig, a link to our configuration settings, which we use practically everywhere
    // and use it to double-check that we're already boostrapped
    $this->load->model('SiteConfig');
    $this->siteconfig = new SiteConfig();
    if (! $this->siteconfig->get('title') ) die( redirect(site_url('setup')) );

    // set the timezone; particularly for time output in Events, but potentially for other unforeseen functions such as manually-entered events
    date_default_timezone_set( $this->siteconfig->get('timezone') );
}


/***************************************************************************************
 * LOGIN AND LOGOUT
 ***************************************************************************************/

public function login() {
    // no user/pass? no problem, give 'em a form
    if (!@$_POST['username'] or !@$_POST['password']) return $this->load->view('site/login.phtml');

    // validate it
    $user = User::checkPassword($_POST['username'],$_POST['password']);
    if (! $user) return $this->load->view('site/login.phtml');

    // guess it was valid: assign their user info into their session for easy access
    // then send them on to the logged-in page
    $this->load->library('Session');
    $this->session->set_userdata('loggedin', $user->stored);
    $url = $user->level >= USER_LEVEL_MANAGER ? 'administration' : 'site';
    return redirect(site_url($url));
}

public function logout() {
    $this->session->unset_userdata('loggedin');
    redirect('/');
}


/***************************************************************************************
 * OTHER WEB PAGES
 ***************************************************************************************/

public function index() {
    // if they're using a Mobile browser, then bail on this and send them over to the Mobile site
    $this->load->library('user_agent');
    if ($this->agent->is_mobile()) die( redirect(site_url('mobile')) );

    $this->load->view('site/index.phtml');
}

public function about() {
    $this->load->view('site/about.phtml');
}



/***************************************************************************************
 * PLACE INFO
 ***************************************************************************************/

public function place($id=0) {
    $data = array();
    $data['place'] = new Place();
    $data['place']->where('id',$id)->get();
    if (! $data['place']->id) show_404();

    // do we have any PlaceActivities at all? if not, then no point in showing that useless checkbox
    $data['has_activities'] = $data['place']->placeactivity->count();

    // directions UI: use metric or imperial?
    $siteconfig = new SiteConfig();
    $data['metric'] = (integer) $siteconfig->get('metric_units');

    $this->load->view('site/place.phtml',$data);
}


/***************************************************************************************
 * MAP AND SUPPORTING AJAX ENDPOINTS
 ***************************************************************************************/

public function map() {
    $data = array();

    // assocarray of Place Data Sources: ID -> Name
    // used to generate the checkbox list, which is used to toggle the data source
    $data['categories'] = array();
    $dsx = new PlaceCategory();
    $dsx->where('enabled',1)->get();
    foreach ($dsx as $ds) $data['categories'][ $ds->id ] = array('id'=>$ds->id, 'name'=>$ds->name, 'checked'=>(boolean) (integer) $ds->on_by_default );

    // do we have any EventLocations at all? in many cases this will be no, as most event datasources don't support location
    // if we have none, then showing the Show Event Locations checkbox makes little sense
    $data['has_event_locations'] = new EventLocation();
    $data['has_event_locations'] = $data['has_event_locations']->count();

    // assign the siteconfig to the template too; we use this for JavaScript definitions: starting bbox, basemap choice, and so on
    $siteconfig = new SiteConfig();
    $data['siteconfig'] = $siteconfig->all();

    // ready!
    $this->load->view('site/map.phtml',$data);
}


public function ajax_map_points() {
    // data fix: if they gave keywords, trim and lowercase them
    if (@$_POST['keywords']) {
        $keywords = strtolower(trim($_POST['keywords']));
        $keywords = preg_split('/\s+/',$keywords);
    }

    // the search & filter system for the Map page; accept a POST full of options such as date filtering, category filtering, text searching, ...
    // start with all Places
    $places = new Place();

    // if they gave a Date for filtering, then filter by the Place having a related PlaceActivity where thisweekday=1
    if (@$_POST['date']) {
        $year     = (integer) substr($_POST['date'],0,5);
        $month    = (integer) substr($_POST['date'],5,2);
        $date     = (integer) substr($_POST['date'],8,2);
        $unixtime = mktime(0, 0, 0, $month, $date, $year);
        $weekday  = strtolower( date('D',$unixtime) ); // e.g. "sun" or "fri"

        // get all Place IDs where the PlaceActivity entries have this weekday=1
        $places->where_related('placeactivity', $weekday, 1);
    }

    // category filter is pretty simple; note the handling of an empty array: if we leave it truly blank, it matches all Places with no categories at all
    if (! @$_POST['categories']) $_POST['categories'] = array(-1);
    $_POST['categories']  = array_map('intval', $_POST['categories']);
    $places->where_related_placecategory('id',$_POST['categories']);

    // the keyword matching is complex: we need to AND the keywords (must match all keywords) but OR the clauses (name OR description OR category must match)
    // AND ( name LIKE '%keyword_1%' AND name LIKE '%keyword_2%' ... )
    // OR ( description LIKE '%keyword_1%' AND description LIKE '%keyword_2%' ... )
    // OR ( anycategoryname LIKE '%keyword_1%' AND anycategoryname LIKE '%keyword_2%' ... )
    if (@$_POST['keywords']) {
        $places->group_start();

            $places->or_group_start();
            foreach ($keywords as $word) $places->like('name',$word);
            $places->group_end();

            $places->or_group_start();
            foreach ($keywords as $word) $places->like('description',$word);
            $places->group_end();

            $places->or_group_start();
            foreach ($keywords as $word) $places->like_related('placecategory','name',$word);
            $places->group_end();

        $places->group_end();
    }

    // the data source must not be disabled
    // disabling a data source should "hide" these markers from the front-facing map
    $places->where_related('placedatasource','enabled',1);

    // done with the easy filters, apply them now
    $places->distinct()->get();
    //$places->check_last_query();

    // a simple JSON structure here
    // no specific standard here, just as compact and purpose-specific as we can make it
    $output = array();
    foreach ($places as $place) {
        if (! (float) $place->longitude or ! (float) $place->latitude) continue;

        $thisone = array();
        $thisone['type']    = 'place';
        $thisone['id']      = (integer) $place->id;
        $thisone['name']    = $place->name;
        $thisone['desc']    = $place->description;
        $thisone['lat']     = (float) $place->latitude;
        $thisone['lng']     = (float) $place->longitude;
        $thisone['url']     = site_url("site/place/{$place->id}");

        $thisone['category_names'] = $place->listCategoryNames();

        $output[] = $thisone;
    }

    // whoa there! we're not done yet
    // if they asked for EventLocations then append markers for the EventLocations, generating their descriptions and all from the parent event
    // be sure to filter by $_POST['date'] if one was given, same as we did for PlaceActivity above
    if (@$_POST['event_locations']) {
        // the categories to assign to the EventLocation points; just this one fixed set, defined here so we don't redefine the same thing repeatedly within the loop
        $elcats = array('Event');

        // for date filtering: find the min & max time for the selected day; we compare this against the Events' start and end times which are also unix timestamps
        if (@$_POST['date']) {
            $today_start = mktime(0,   0,  0, $month, $date, $year);
            $today_end   = mktime(23, 59, 59, $month, $date, $year);
        } else {
            $today_start = $today_end = NULL;
        }

        $els = new EventLocation();
        $els->where_related('event/eventdatasource','enabled',1);
        $els->get();
        foreach ($els as $el) {
            // see whether this EventLocation fits the keyword filter: check the parent Event's title and description fields
            if (@$_POST['keywords']) {
                if (! preg_match("/\b{$_POST['keywords']}\b/i", $el->event->name)  and ! preg_match("/{$_POST['keywords']}/i", strip_tags($el->event->description)) ) continue;
            }

            // see whether this EventLocation fits the date filter
            if (@$_POST['date']) {
                if ($el->event->starts > $unixtime or $el->event->ends < $unixtime) continue;
            }

            $thisone = array();
            $thisone['type']    = 'event';
            $thisone['id']      = (integer) $el->id;
            $thisone['name']    = $el->event->name;
            $thisone['lat']     = (float) $el->latitude;
            $thisone['lng']     = (float) $el->longitude;
            $thisone['url']     = $el->event->url;

            $thisone['desc']    = $el->event->description;
            if ($el->title)     $thisone['desc'] .= "<br/>\n" . $el->title;
            if ($el->subtitle)  $thisone['desc'] .= "<br/>\n" . $el->subtitle;

            $thisone['category_names'] = $elcats;

            $output[] = $thisone;
        }
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

// the directions abstrcator: look in our siteconfig and figure out which directions service to use, and hand back a known format despite whomever we're using
// this is used by desktop, but for mobile we just send them to Google Maps which may be intercepted by the native app
public function directions() {
    // check that we got all params
    if (! @$_GET['start_lat']) return print "Need start lat and lng";
    if (! @$_GET['start_lng']) return print "Need start lat and lng";
    if (! @$_GET['end_lat'])   return print "Need end lat and lng";
    if (! @$_GET['end_lng'])   return print "Need end lat and lng";

    // have a sub-method hit up the appropriate service
    switch ( $this->siteconfig->get('preferred_geocoder') ) {
        case 'bing':
            $mode   = 'driving';
            $result = $this->_directions_bing($_GET['start_lat'],$_GET['start_lng'],$_GET['end_lat'],$_GET['end_lng'],$mode);
            break;
        case 'google':
            $mode   = 'driving';
            $result = $this->_directions_google($_GET['start_lat'],$_GET['start_lng'],$_GET['end_lat'],$_GET['end_lng'],$mode);
            break;
        default:
            return print "No geocoder enabled?";
            break;
    }

    // spit it out as JSON
    header('Content-type: application/json');
    print json_encode($result);
}

private function _directions_google($start_lat,$start_lng,$end_lat,$end_lng,$mode) {
    // compose the request to the REST service
    $params = array();
    $params['key']              = $this->siteconfig->get('google_api_key');
    $params['origin']           = sprintf("%f,%f", $start_lat, $start_lng );
    $params['destination']      = sprintf("%f,%f", $end_lat, $end_lng );
    $params['mode']             = $mode;
    $params['units']            = (integer) $this->siteconfig->get('metric_units') ? 'metric' : 'imperial';
    $url = sprintf("https://maps.googleapis.com/maps/api/directions/json?%s", http_build_query($params) );

    // send it off, parse it, make sure it's valid and that we got a result
    $result = json_decode(file_get_contents($url));
    if (! @$result->routes[0]) return print "Could not find directions";

    // start building output: the bounding box
    $output['s']    = (float) $result->routes[0]->bounds->southwest->lat;
    $output['w']    = (float) $result->routes[0]->bounds->southwest->lng;
    $output['n']    = (float) $result->routes[0]->bounds->northeast->lat;
    $output['e']    = (float) $result->routes[0]->bounds->northeast->lng;

    // build output: grand totals
    // Google already has these as nice text so we don't have to convert from raw seconds as with Bing
    $output['total_distance'] = $result->routes[0]->legs[0]->distance->text;
    $output['total_time']     = $result->routes[0]->legs[0]->duration->text;

    // build output: the vertices     unlike Bing google uses a weird encoding format
    require_once 'application/third_party/googlePolylineToArray.php';
    $output['vertices'] = decodePolylineToArray($result->routes[0]->overview_polyline->points);

    // build output: the text directions
    $output['steps'] = array();
    foreach ($result->routes[0]->legs[0]->steps as $step) {
        $output['steps'][] = array(
            'distance' => $step->distance->text,
            'text' => strip_tags($step->html_instructions)
        );
    }

    // done!
    return $output;
}

private function _directions_bing($start_lat,$start_lng,$end_lat,$end_lng,$mode) {
    // compose the request to the REST service
    $params = array();
    $params['key']              = $this->siteconfig->get('bing_api_key');
    $params['output']           = 'json';
    $params['wp.0']             = sprintf("%f,%f", $start_lat, $start_lng );
    $params['wp.1']             = sprintf("%f,%f", $end_lat, $end_lng );
    $params['distanceUnit']     = (integer) $this->siteconfig->get('metric_units') ? 'km' : 'mi';
    $params['routePathOutput']  = 'Points';
    $params['travelMode']       = $mode;
    $url = sprintf("http://dev.virtualearth.net/REST/v1/Routes?%s", http_build_query($params) );

    // send it off, parse it, make sure it's valid and that we got a result
    $result = json_decode(file_get_contents($url));
    if ($result->authenticationResultCode != 'ValidCredentials') return print "Bing Maps API key is invalid";
    if (! @$result->resourceSets[0]->resources[0]) return print "Could not find that address";

    // start building output: the bounding box
    $output['s'] = $result->resourceSets[0]->resources[0]->bbox[0];
    $output['w'] = $result->resourceSets[0]->resources[0]->bbox[1];
    $output['n'] = $result->resourceSets[0]->resources[0]->bbox[2];
    $output['e'] = $result->resourceSets[0]->resources[0]->bbox[3];

    // build output: grand totals
    $distance = (float) $result->resourceSets[0]->resources[0]->routeLegs[0]->travelDistance;
    $distance = $distance >= 5 ? sprintf("%d %s", round($distance), $params['distanceUnit'] ) : sprintf("%.1f %s", $distance, $params['distanceUnit'] );

    $time = round($result->resourceSets[0]->resources[0]->routeLegs[0]->travelDuration / 60);
    if ($time % 60 == 0) {
        $time = ($time/60) . ' hours';
    } else if ($time > 60) {
        $time = floor($time/60) . ' hours, ' . ($time%60) . ' minutes';
    }
    else {
        $time = $time . ' minutes';
    }
    $output['total_distance'] = $distance;
    $output['total_time']     = $time;

    // build output: the vertices     fortunately Bing has them in the right format
    $output['vertices'] = $result->resourceSets[0]->resources[0]->routePath->line->coordinates;

    // build output: the text directions
    $output['steps'] = array();
    foreach ($result->resourceSets[0]->resources[0]->routeLegs[0]->itineraryItems as $step) {
        $text     = (string) $step->instruction->text;

        if ( (float) $step->travelDistance ) {
            $distance = sprintf("%.1f %s", (float) $step->travelDistance, $params['distanceUnit'] );
        } else {
            $distance = ' ';
        }

        $output['steps'][] = array( 'text'=>$text, 'distance'=>$distance );
    }

    // done!
    return $output;
}


/***************************************************************************************
 * CALENDAR AND SUPPORTING AJAX ENDPOINTS
 ***************************************************************************************/

public function calendar() {
    $data = array();

    // assocarray of Event Data Sources: ID -> Name\
    // used to generate the checkbox list, which is used to toggle the data source
    $data['sources'] = array();
    $dsx = new EventDataSource();
    $dsx->where('enabled',1)->get();
    foreach ($dsx as $ds) $data['sources'][ $ds->id ] = array('id'=>$ds->id, 'name'=>$ds->name, 'color'=>$ds->color, 'bgcolor'=>$ds->bgcolor, 'checked'=>(boolean) (integer) $ds->on_by_default );

    // do we have any PlaceActivity entries at all? in many cases this will be no, as most folks won't enter open hours and recurring activities
    // if we have none, then showing the Show Non-Event Activities checkbox makes little sense
    $data['has_place_activities'] = new PlaceActivity();
    $data['has_place_activities'] = $data['has_place_activities']->count();

    $this->load->view('site/calendar.phtml',$data);
}

public function ajax_calendar_events($id=0) {
    // make sure they're numbers
    $_GET['startdate'] = (integer) $_GET['startdate'];
    $_GET['enddate']   = (integer) $_GET['enddate'];
    if (!$_GET['startdate'] or !$_GET['enddate']) return print "Invalid starttime and/or endtime";

    // the big branch
    // a non-zero ID# means that it's an event datasource, and the datasource acts as the "category" so we show all events in that datasource
    // a zero ID# indicates PlaceActivity, which aren't Events at all but recurring open-hours or similar for Places
    // NOTE: the output format here is specific to fullCalendar, the chosen client-side calendar renderer
    if ($id) {
        $events = new Event();
        $events->where('eventdatasource_id',$id);
        $events->where_related('eventdatasource','enabled',1);
        $events->get();

        $output = array();
        foreach ($events as $event) {
            if ($event->starts > $_GET['enddate']) continue; // not started yet
            if ($event->ends < $_GET['startdate']) continue; // ended already

            // if the event is every weekday (implies also every single day) then skip it
            // cuz it makes the calendar look jumbled
            if ($event->mon and $event->tue and $event->wed and $event->thu and $event->fri) continue;

            $thisone = array();
            $thisone['id']      = $event->id;
            $thisone['title']   = $event->name;
            $thisone['allDay']  = (boolean) $event->allday;
            $thisone['start']   = $event->starts;
            $thisone['end']     = $event->ends;
            $thisone['url']     = $event->url;
            $thisone['textColor']       = $event->eventdatasource->color;
            $thisone['backgroundColor'] = $event->eventdatasource->bgcolor;

            $output[] = $thisone;
        }
    } else {
        // a $id of 0: PlaceActivity entries
        // they have mon=1, tue=0, wed=1, etc. to indicate weekdays and we need these rendered into a set of distinct event items like the structure above
        // e.g. activity occurs on 2014-04-23 and also on 2014-04-30 and on 2014-04-16, ...
        // the startdate and enddate are already unix timestamps, so we can iterate 24 hours at a time and see what matches
        $activities = new PlaceActivity();
        $activities->where_related('place/placedatasource','enabled',1);
        $activities->get();
        for ($time=$_GET['startdate']; $time<$_GET['enddate']; $time+=86400) {
            $day    = strtolower( date('D',$time) );
            $month  = date('n', $time);
            $date   = date('j', $time);
            $year   = date('Y', $time);

            foreach ($activities as $act) {
                if (! $act->{$day}) continue; // it's not on this weekday (mon, wed, tue, sun, ...)

                // split the start time and end time of this activity, and turn it into a timestamp on the current date
                list($shour,$sminute,$ssecond) = explode(':', $act->starttime );
                list($ehour,$eminute,$esecond) = explode(':', $act->endtime );
                $act_starttime = mktime($shour, $sminute, 0, $month, $date, $year);
                $act_endtime   = mktime($ehour, $eminute, 0, $month, $date, $year);

                $thisone = array();
                $thisone['id']      = $act->id;
                $thisone['title']   = sprintf("%s @ %s", $act->name, $act->place->name);
                $thisone['allDay']  = FALSE;
                $thisone['start']   = $act_starttime;
                $thisone['end']     = $act_endtime;
                $thisone['url']     = site_url("site/place/{$act->place->id}");
                $thisone['textColor']       = '#000000';
                $thisone['backgroundColor'] = '#FFFFFF';

                $output[] = $thisone;
            }
        }
    }

    // ready!
    header('Content-type: application/json');
    print json_encode($output);
}



} // end of Controller
