<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Administration extends CI_Controller {
/* The /administration pages form the administration facility */

// constructor: to access ANYTHING under this panel, they must have a management account
// and already be logged in. if not, then cancel whatever they're doing and send them to login
public function __construct() {
    parent::__construct();

    $this->load->library('Session');
    $session = @$this->session->userdata('loggedin');
    if (! $session or ! $session->manager ) die(redirect(site_url('site/login')));

    $this->load->model('SiteConfig');
    $this->siteconfig = new SiteConfig();
}


public function index() {
    $data = array();
    $this->load->view('administration/index.phtml', $data);
}


/*******************************************************************************************************
 * MANAGEMENT OF GENERAL SETTINGS
 *******************************************************************************************************/

public function settings() {
    $data = array();

    // an assoc of theme files, for the Color Theme picker
    $data['themes'] = array();
    foreach (glob('application/views/common/jquery-ui-1.10.3/css/*') as $t) {
        if (! is_dir($t))continue;
        $t = basename($t);
        $data['themes'][$t] = $t;
    }

    // ready!
    $this->load->view('administration/settings.phtml', $data);
}


public function ajax_save_settings() {
    // check for missing or bad fields
    if (! @$_POST['jquitheme'])     return print "The Web Site Theme must be filled in.";
    if (! @$_POST['title'])         return print "The Web Site Title must be filled in.";

    // guess we're golden
    $this->siteconfig->set('jquitheme', $_POST['jquitheme']);
    $this->siteconfig->set('title', $_POST['title']);
    $this->siteconfig->set('html_about', $_POST['html_about']);
    $this->siteconfig->set('html_frontpage', $_POST['html_frontpage']);
    $this->siteconfig->set('bbox_w', $_POST['bbox_w']);
    $this->siteconfig->set('bbox_s', $_POST['bbox_s']);
    $this->siteconfig->set('bbox_e', $_POST['bbox_e']);
    $this->siteconfig->set('bbox_n', $_POST['bbox_n']);
    $this->siteconfig->set('bing_api_key', $_POST['bing_api_key']);

    // AJAX endpoint: just say OK
    print 'ok';
}


/*******************************************************************************************************
 * MANAGEMENT OF USER ACCOUNTS
 *******************************************************************************************************/

public function users() {
    $data = array();

    $data['users'] = new User();
    $data['users']->get();

    $this->load->view('administration/users.phtml', $data);
}

public function user($id) {
    // fetch the user or die trying
    $data['user'] = new User();
    $data['user']->where('id',$id)->get();
    if (! $data['user']->id) return redirect(site_url('administration/users'));

    // then bail to a form
    return $this->load->view('administration/user.phtml', $data);
}

public function ajax_save_user() {
    // fetch the user or die trying
    $data['user'] = new User();
    $data['user']->where('id',$_POST['id'])->get();
    if (! $data['user']->id) return print "Not a valid user. How did you get here?";

    // if their username is changing, validate it as being an email address
    // we only do this if it's changing, to allow for "admin" and maybe some other reason there'd be non-email usernames
    $_POST['username'] = strtolower($_POST['username']);
    if ($_POST['username'] != $data['user']->username) {
        if (! User::validateUsername($_POST['username']) ) return print "The username must be an email address.";

        $already = new User();
        $already->where('username',$_POST['username'])->where('id !=',$data['user']->id)->get();
        if ($already->id) return print "That username is already in use.";

        $data['user']->username = $_POST['username'];
        $data['user']->save();
    }

    // are they changing their password?
    if (@$_POST['password']) {
        $data['user']->password = User::encryptPassword($_POST['password']);
        $data['user']->save();
    }

    // AJAX endpoint: just say OK
    print 'ok';
}

public function ajax_create_user() {
    // make sure all elements are provided
    $username = strtolower(@$_POST['username']);
    $password = @$_POST['password'];
    $manager  = (integer) @$_POST['manager'];
    if (! $username or ! $password) return print "All fields are required.";
    if (! User::validateUsername($username) ) return print "The username must be an email address.";

    // is this account already in use?
    $user = new User();
    $user->where('username',$username)->get();
    if ($user->username) return print "That username is already in use.";

    // guess we're clear; go ahead and save it
    $user->username = $username;
    $user->password = User::encryptPassword($password);
    $user->manager  = $manager;
    $user->save();

    // AJAX endpoint: just say OK
    print $user->id;
}

public function user_delete() {
    // fetch the user or die trying
    $data['user'] = new User();
    $data['user']->where('id',$_POST['id'])->get();
    if (! $data['user']->id) return redirect(site_url('administration/users'));

    // if they're not POSTing a confirmation, bail
    if (! @$_POST['ok']) return $this->load->view('administration/user_delete.phtml', $data);

    // delete it, send the user home
    $data['user']->delete();
    redirect(site_url('administration/users'));
}



/*******************************************************************************************************
 * MANAGEMENT OF EVENT DATA SOURCES
 *******************************************************************************************************/

public function event_sources() {
    $data = array();
    $this->load->view('administration/event_sources.phtml', $data);
}


/*******************************************************************************************************
 * MANAGEMENT OF PLACE DATA SOURCES
 *******************************************************************************************************/

public function place_sources() {
    $data = array();
    $this->load->view('administration/place_sources.phtml', $data);
}



} // end of Controller