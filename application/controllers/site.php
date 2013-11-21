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
    $this->load->view('site/map.phtml');
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
    $dsx->get();
    foreach ($dsx as $ds) $data['sources'][ $ds->id ] = array('id'=>$ds->id, 'name'=>$ds->name, 'color'=>$ds->color);

    $this->load->view('site/calendar.phtml',$data);
}

public function ajax_calendar_events($id=0) {
    $events = new Event();
    $events->where('eventdatasource_id',$id)->where('starts >=',$_GET['startdate'])->where('ends <',$_GET['enddate'])->get();

    // the output format here is specific to fullCalendar, the chosen client-side calendar renderer
    $output = array();
    foreach ($events as $event) {
        $thisone = array();
        $thisone['id'] = $event->id;
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