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

    // set the timezone
    // particularly important for Events, both when loading from non-timezone-aware sources but also for rendering those dates both in admin and to public
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
 * THERE ARE NO OTHER WEB PAGES
 * WE JUST SEND THEM ONWARD TO THE MOBILE SITE
 ***************************************************************************************/

public function index() {
    redirect(site_url('mobile'));
}



} // end of Controller
