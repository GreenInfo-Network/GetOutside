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

    // the list of Categories for finding Places; in the mockups these are erroneously called Activities
    $data['place_categories'] = array();
    $data['place_categories'][''] = "Select an activity (optional)";
    $dsx = new PlaceCategory();
    $dsx->where('enabled',1)->get();
    foreach ($dsx as $ds) $data['place_categories'][ $ds->id ] = $ds->name;

//gda
    // do we have any EventLocations at all? in many cases this will be no, as most event datasources don't support location
    // if we have none, then showing the Show Event Locations checkbox makes little sense
    $data['has_event_locations'] = new EventLocation();
    $data['has_event_locations'] = $data['has_event_locations']->count();

//gda
    // directions UI: use metric or imperial?
    $siteconfig = new SiteConfig();
    $data['metric'] = (integer) $siteconfig->get('metric_units');

    // ready, display!
    $this->load->view('mobile/index.phtml',$data);
}




} // end of Controller
