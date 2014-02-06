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
}


/***************************************************************************************
 * OTHER WEB PAGES
 ***************************************************************************************/

public function index() {
    $this->load->view('site/index.phtml');
}

public function about() {
    $this->load->view('site/about.phtml');
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

    // done with the easy filters, apply them now
    $places->distinct()->get();
    //$places->check_last_query();

    // a simple JSON structure here
    // no specific standard here, just as compact and purpose-specific as we can make it
    $output = array();
    foreach ($places as $place) {
        if (! (float) $place->longitude or ! (float) $place->latitude) continue;

        $thisone = array();
        $thisone['id']      = (integer) $place->id;
        $thisone['name']    = $place->name;
        $thisone['desc']    = $place->description;
        $thisone['lat']     = (float) $place->latitude;
        $thisone['lng']     = (float) $place->longitude;

        $thisone['category_names'] = $place->listCategoryNames();

        $output[] = $thisone;
    }

    // whoa there! we're not done yet
    // if they asked for EventLocations then append markers for the EventLocations,
    // generating their descriptions and all from the parent event
    if (@$_POST['event_locations']) {
        $elcats = array('Event');

        $els = new EventLocation();
        $els->get();

        foreach ($els as $el) {
            // see whether this EventLocation fits the keyword filter: check the parent Event's title and description fields
            if (@$_POST['keywords']) {
                if (! preg_match("/\b{$_POST['keywords']}\b/i", $el->event->name)  and ! preg_match("/{$_POST['keywords']}/i", strip_tags($el->event->description)) ) continue;
            }

            $thisone = array();
            $thisone['id']      = (integer) $el->id;
            $thisone['name']    = $el->event->name;
            $thisone['lat']     = (float) $el->latitude;
            $thisone['lng']     = (float) $el->longitude;

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



/***************************************************************************************
 * CALENDAR AND SUPPORTING AJAX ENDPOINTS
 ***************************************************************************************/

public function calendar() {
    $data = array();

    // assocarray of Event Data Sources: ID -> Name
    // used to generate the checkbox list, which is used to toggle the data source
    $data['sources'] = array();
    $dsx = new EventDataSource();
    $dsx->where('enabled',1)->get();
    foreach ($dsx as $ds) $data['sources'][ $ds->id ] = array('id'=>$ds->id, 'name'=>$ds->name, 'color'=>$ds->color, 'checked'=>(boolean) (integer) $ds->on_by_default );

    $this->load->view('site/calendar.phtml',$data);
}

public function ajax_calendar_events($id=0) {
    $events = new Event();
    $events->where('eventdatasource_id',$id)->where('starts >=',$_GET['startdate'])->where('ends <',$_GET['enddate'])->get();

    // the output format here is specific to fullCalendar, the chosen client-side calendar renderer
    $output = array();
    foreach ($events as $event) {
        $thisone = array();
        $thisone['id']      = $event->id;
        $thisone['title']   = $event->name;
        $thisone['allDay']  = (boolean) $event->allday;
        $thisone['start']   = $event->starts;
        $thisone['end']     = $event->ends;
        $thisone['url']     = $event->url;
        $thisone['color']   = $event->eventdatasource->color;

        $output[] = $thisone;
    }

    header('Content-type: application/json');
    print json_encode($output);
}



} // end of Controller