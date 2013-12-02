<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Administration extends CI_Controller {
/* The /administration pages form the administration facility */

// constructor: to access ANYTHING under this panel, they must have a management account
// and already be logged in. if not, then cancel whatever they're doing and send them to login
public function __construct() {
    parent::__construct();

    $this->load->library('Session');
    $session = @$this->session->userdata('loggedin');
    if (! $session or ! $session->level) die(redirect(site_url('site/login')));

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
    $this->siteconfig->set('company_name', $_POST['company_name']);
    $this->siteconfig->set('company_url', $_POST['company_url']);

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

    $data['levels'] = $this->config->item('user_levels');

    $this->load->view('administration/users.phtml', $data);
}

public function user($id) {
    // fetch the user or die trying
    $data['user'] = new User();
    $data['user']->where('id',$id)->get();
    if (! $data['user']->id) return redirect(site_url('administration/users'));

    // load the list of user level options
    $data['levels'] = $this->config->item('user_levels');

    // then bail to a form
    return $this->load->view('administration/user.phtml', $data);
}

public function ajax_save_user() {
    // fetch the user or die trying
    $data['user'] = new User();
    $data['user']->where('id',$_POST['id'])->get();
    if (! $data['user']->id) return print "Not a valid user. How did you get here?";

    // validation: special case, user #1 must be a admin
    // anyone else still must be on the list
    if ($data['user']->isSuper()) $_POST['level'] = USER_LEVEL_ADMIN;
    if (! array_key_exists( (integer) @$_POST['level'] , $this->config->item('user_levels') )) return print "The account level is not a valid selection.";

    // validation: check that the username is valid
    $_POST['username'] = strtolower($_POST['username']);
    if (! User::validateUsername($_POST['username'])) return print "The username must be an email address.";

    // validation: check that the username isn't in use
    $already = new User();
    $already->where('username',$_POST['username'])->where('id !=',$data['user']->id)->get();
    if ($already->id) return print "That username is already in use.";

    // validation: if they gave a password, encrypt it
    if (@$_POST['password']) $_POST['password'] = User::encryptPassword($_POST['password']);

    // save it!
    $data['user']->username = $_POST['username'];
    $data['user']->level    = (integer) $_POST['level'];
    if (@$_POST['password']) $data['user']->password = $_POST['password'];
    $data['user']->save();

    // AJAX endpoint: just say OK
    print 'ok';
}

public function ajax_create_user() {
    // cast the username (email) to lowercase; MySQL is case-insensitive, but I hate relying on that behavior, e.g. if a future revision uses a different DB
    // make sure all elements are provided
    if (! @$_POST['username'] or ! @$_POST['password']) return print "All fields are required.";
    $_POST['username'] = strtolower($_POST['username']);
    if (! User::validateUsername(@$_POST['username']) ) return print "The username must be an email address.";
    if (! array_key_exists( (integer) @$_POST['level'], $this->config->item('user_levels') )) return print "The account level is not a valid selection.";

    // is this account already in use?
    $user = new User();
    $user->where('username',$_POST['username'])->get();
    if ($user->username) return print "That username is already in use.";

    // guess we're clear; go ahead and save it
    $user->username = $_POST['username'];
    $user->password = User::encryptPassword($_POST['password']);
    $user->level    = $_POST['level'];
    $user->save();

    // AJAX endpoint: just say OK
    print $user->id;
}

public function user_delete() {
    // fetch the user or die trying
    $data = array();
    $data['user'] = new User();
    $data['user']->where('id',$_POST['id'])->get();
    if (! $data['user']->id) return redirect(site_url('administration/users'));

    // if this is the super-admin, don't even think about it
    if ($data['user']->isSuper()) return redirect(site_url('administration/users'));

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
    $data['sources'] = new EventDataSource();
    $data['sources']->get();

    $data['types'] = array();
    foreach (EventDataSource::$SOURCE_TYPES as $t) $data['types'][$t] = $t;

    $this->load->view('administration/event_sources.phtml', $data);
}

public function event_source($id) {
    $data = array();
    if ($id == 'new') {
        $data['source'] = null;
    } else {
        $data['source'] = new EventDataSource();
        $data['source']->where('id',$id)->get();
        $data['source'] = $data['source']->convertToDriver();
        if (! $data['source']->id) return redirect(site_url('administration/event_sources'));
    }
    $this->load->view('administration/event_source.phtml', $data);
}

public function ajax_create_event_source() {
    // validation: the type must be valid, and the name must be given
    if (! @$_POST['name']) return print "All fields are required.";
    if (! array_key_exists( (integer) @$_POST['type'],EventDataSource::$SOURCE_TYPES)) return print "The type is not a valid selection.";

    // save the data source
    $source = new EventDataSource();
    $source->type = $_POST['type'];
    $source->name = trim(strip_tags($_POST['name']));
    $source->url  = '';
    $source->save();

    // AJAX endpoint: just say OK
    print $source->id;
}

public function ajax_load_event_source() {
    // fetch the data source by its ID# or die trying
    $source = new EventDataSource();
    $source->where('id',$_POST['id'])->get();
    if (! $source->id) return print "Could not find that data source.";

    // swap out this EventDataSource with the appropriate subclass, e.g. a EventDataSource_GoogleCalendar instance
    // so the driver-specific methods will be used, e.g. reloadContent()
    // NOTE: we're violating models by stuffing our SiteConfig item into the instance; not seeing any other clean way to get siteconfig into the instance
    // for some site-specific things, e.g. loading the bounding box for a spatial filter
    try {
        $source = $source->convertToDriver();
        $source->siteconfig = $this->siteconfig;
        $source->reloadContent();
    } catch (EventDataSourceSuccessException $e) {
        return print "SUCCESS: " . $e->getMessage();
    } catch (EventDataSourceErrorException $e) {
        return print "ERROR: " . $e->getMessage();
    }
}


public function ajax_save_event_source() {
    // fetch the data source by its ID# or die trying
    $source = new EventDataSource();
    $source->where('id',$_POST['id'])->get();
    $source = $source->convertToDriver();
    if (! $source->id) return print "Could not find that data source.";

    // validation: name and URL are required
    $_POST['name']      = trim(strip_tags(@$_POST['name']));
    $_POST['url']       = trim(@$_POST['url']);
    $_POST['option1']   = trim(@$_POST['option1']);
    $_POST['option2']   = trim(@$_POST['option2']);
    $_POST['option3']   = trim(@$_POST['option3']);
    $_POST['option4']   = trim(@$_POST['option4']);
    if (! $_POST['name']) return print "The name is required.";
    if ($source->option_fields['url']     and $source->option_fields['url']['required']     and !$_POST['url'])      return print "Missing required field: {$source->option_fields['url']['name']}";
    if ($source->option_fields['option1'] and $source->option_fields['option1']['required'] and !$_POST['option1'])  return print "Missing required field: {$source->option_fields['option1']['name']}";
    if ($source->option_fields['option2'] and $source->option_fields['option2']['required'] and !$_POST['option2'])  return print "Missing required field: {$source->option_fields['option2']['name']}";
    if ($source->option_fields['option3'] and $source->option_fields['option3']['required'] and !$_POST['option3'])  return print "Missing required field: {$source->option_fields['option3']['name']}";
    if ($source->option_fields['option4'] and $source->option_fields['option4']['required'] and !$_POST['option4'])  return print "Missing required field: {$source->option_fields['option4']['name']}";

    // validation: color must be #XXXXXX
    if (! preg_match('/^\#[1234567890ABCDEFabcdef]{6}/', $_POST['color'])) return print "Select a valid color.";

    // save it
    $source->name    = $_POST['name'];
    $source->color   = $_POST['color'];
    $source->url     = $_POST['url'];
    $source->option1 = $_POST['option1'];
    $source->option2 = $_POST['option2'];
    $source->option3 = $_POST['option3'];
    $source->option4 = $_POST['option4'];
    $source->save();

    // AJAX endpoint, just say OK
    print 'ok';
}

public function event_source_delete() {
    // fetch the specified data source or die trying
    $data = array();
    $data['source'] = new EventDataSource();
    $data['source']->where('id',$_POST['id'])->get();
    if (! $data['source']->id) return redirect(site_url('administration/event_sources'));

    // if they're not POSTing a confirmation, bail
    if (! @$_POST['ok']) return $this->load->view('administration/event_source_delete.phtml', $data);

    // delete it, send the user home
    $data['source']->delete();
    redirect(site_url('administration/event_sources'));
}



/*******************************************************************************************************
 * MANAGEMENT OF PLACE DATA SOURCES
 *******************************************************************************************************/

public function place_sources() {
    $data = array();
    $data['sources'] = new PlaceDataSource();
    $data['sources']->get();

    $data['types'] = array();
    foreach (PlaceDataSource::$SOURCE_TYPES as $t) $data['types'][$t] = $t;

    $this->load->view('administration/place_sources.phtml', $data);
}

public function place_source($id) {
    $data = array();
    if ($id == 'new') {
        $data['source'] = null;
    } else {
        $data['source'] = new PlaceDataSource();
        $data['source']->where('id',$id)->get();
        $data['source'] = $data['source']->convertToDriver();
        if (! $data['source']->id) return redirect(site_url('administration/place_sources'));

        // get the list of fields too, and convert to an assocarray, so they can pick from the list for any options that are 'isfield'
        // this is only necessary if any options are 'isfield', so check that and only ping the datasource if necessary
        $needs_fields = @$data['source']->option1['isfield'] or @$data['source']->option2['isfield'] or @$data['source']->option3['isfield'] or @$data['source']->option4['isfield'];
        if ($needs_fields) {
            try {
                $data['fields'] = $data['source']->listFields(TRUE);
            } catch (PlaceDataSourceErrorException $e) {
                $data['fields'] = array();
            }
        }
    }
    $this->load->view('administration/place_source.phtml', $data);
}

public function ajax_create_place_source() {
    // validation: the type must be valid, and the name must be given
    if (! @$_POST['name']) return print "All fields are required.";
    if (! array_key_exists( (integer) @$_POST['type'],PlaceDataSource::$SOURCE_TYPES)) return print "The type is not a valid selection.";

    // save the data source
    $source = new PlaceDataSource();
    $source->type = $_POST['type'];
    $source->name = trim(strip_tags($_POST['name']));
    $source->url  = '';
    $source->save();

    // AJAX endpoint: just say OK
    print $source->id;
}

public function ajax_load_place_source() {
    // fetch the data source by its ID# or die trying
    $source = new PlaceDataSource();
    $source->where('id',$_POST['id'])->get();
    if (! $source->id) return print "Could not find that data source.";

    // swap out this PlaceDataSource with the appropriate subclass, e.g. a PlaceDataSource_GoogleSpreadsheet instance
    // so the driver-specific methods will be used, e.g. reloadContent()
    // NOTE: we're violating models by stuffing our SiteConfig item into the instance; not seeing any other clean way to get siteconfig into the instance
    // for some site-specific things, e.g. loading the bounding box for a spatial filter
    try {
        $source = $source->convertToDriver();
        $source->siteconfig = $this->siteconfig;
        $source->reloadContent();
    } catch (PlaceDataSourceSuccessException $e) {
        return print "SUCCESS: " . $e->getMessage();
    } catch (PlaceDataSourceErrorException $e) {
        return print "ERROR: " . $e->getMessage();
    }
}


public function ajax_save_place_source() {
    // fetch the data source by its ID# or die trying
    $source = new PlaceDataSource();
    $source->where('id',$_POST['id'])->get();
    $source = $source->convertToDriver();
    if (! $source->id) return print "Could not find that data source.";

    // validation: name and URL are required
    $_POST['name']      = trim(strip_tags(@$_POST['name']));
    $_POST['url']       = trim(@$_POST['url']);
    $_POST['option1']   = trim(@$_POST['option1']);
    $_POST['option2']   = trim(@$_POST['option2']);
    $_POST['option3']   = trim(@$_POST['option3']);
    $_POST['option4']   = trim(@$_POST['option4']);
    if (! $_POST['name']) return print "The name is required.";
    if ($source->option_fields['url']     and $source->option_fields['url']['required']     and !$_POST['url'])      return print "Missing required field: {$source->option_fields['url']['name']}";
    if ($source->option_fields['option1'] and $source->option_fields['option1']['required'] and !$_POST['option1'])  return print "Missing required field: {$source->option_fields['option1']['name']}";
    if ($source->option_fields['option2'] and $source->option_fields['option2']['required'] and !$_POST['option2'])  return print "Missing required field: {$source->option_fields['option2']['name']}";
    if ($source->option_fields['option3'] and $source->option_fields['option3']['required'] and !$_POST['option3'])  return print "Missing required field: {$source->option_fields['option3']['name']}";
    if ($source->option_fields['option4'] and $source->option_fields['option4']['required'] and !$_POST['option4'])  return print "Missing required field: {$source->option_fields['option4']['name']}";

    // save it
    $source->name    = $_POST['name'];
    $source->url     = $_POST['url'];
    $source->option1 = $_POST['option1'];
    $source->option2 = $_POST['option2'];
    $source->option3 = $_POST['option3'];
    $source->option4 = $_POST['option4'];
    $source->save();

    // AJAX endpoint, just say OK
    print 'ok';
}

public function place_source_delete() {
    // fetch the specified data source or die trying
    $data = array();
    $data['source'] = new PlaceDataSource();
    $data['source']->where('id',$_POST['id'])->get();
    if (! $data['source']->id) return redirect(site_url('administration/place_sources'));

    // if they're not POSTing a confirmation, bail
    if (! @$_POST['ok']) return $this->load->view('administration/place_source_delete.phtml', $data);

    // delete it, send the user home
    $data['source']->delete();
    redirect(site_url('administration/place_sources'));
}


} // end of Controller