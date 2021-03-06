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

    // set the timezone
    // particularly important for Events, both when loading from non-timezone-aware sources but also for rendering those dates both in admin and to public
    date_default_timezone_set( $this->siteconfig->get('timezone') );
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

    // all existing blobal site configs, cuz we use the large majority of them
    $data['siteconfig'] = $this->siteconfig->all();

    // build a list of timezone options
    // but override some of the USA time zones which have much-more-familiar names, and put them at the top
    // it sounds Amerocentric, but the USA time zones are the ones most likely to be used by our clients thus far
    $data['timezones'] = array(
        'America/New_York'      => 'USA Eastern',
        'America/Chicago'       => 'USA Central',
        'America/Denver'        => 'USA Mountain',
        'America/Phoenix'       => 'USA Mountain no DST',
        'America/Los_Angeles'   => 'USA Pacific',
        'America/Anchorage'     => 'USA Alaska',
        'America/Adak'          => 'Hawaii',
        'Pacific/Honolulu'      => 'USA Hawaii no DST',
    );
    foreach (timezone_identifiers_list() as $e) {
        if ( array_key_exists($e,$data['timezones'])) continue;
        $data['timezones'][$e] = $e;
    }

    // ready!
    $this->load->view('administration/settings.phtml', $data);
}


public function ajax_save_settings() {
    // check for missing or bad fields
    if (! @$_POST['jquitheme'])     return print "The Web Site Theme must be filled in.";
    if (! @$_POST['title'])         return print "The Web Site Title must be filled in.";

    // file uploads: markers and logos
    // make sure these are PNG files, and load them in as base64-encoded data same as we'll be storing in the DB later
    $image_uploads = array(
        'mobile_logo'   => array(),
        'event_marker'  => array(),
        'place_marker'  => array(),
        'both_marker'   => array(),
        'marker_gps'    => array(),
    );
    foreach ( array_keys($image_uploads) as $which_image) {
        if (! is_uploaded_file(@$_FILES[$which_image]['tmp_name'])) continue; // not an upload, skip it

        // try to open it as a PNG or else skip out; the errmsg can be a bit brusque here, as this should never happen
        $ok = imagecreatefrompng($_FILES[$which_image]['tmp_name']);
        if (! $ok) return print "Bad image upload: $which_image  Are you sure this is a PNG file?";

        // we're good; populate some additional data for the siteconfig, including the base64-encoded content and the width & height
        $image_uploads[$which_image]['content'] = base64_encode(file_get_contents($_FILES[$which_image]['tmp_name']));
        $image_uploads[$which_image]['width']   = imagesx($ok);
        $image_uploads[$which_image]['height']  = imagesy($ok);
    }

    // guess we're golden
    $this->siteconfig->set('jquitheme', $_POST['jquitheme']);
    $this->siteconfig->set('title', $_POST['title']);
    $this->siteconfig->set('feedback_url', $_POST['feedback_url']);
    $this->siteconfig->set('timezone', $_POST['timezone']);
    $this->siteconfig->set('company_name', $_POST['company_name']);
    $this->siteconfig->set('company_url', $_POST['company_url']);

    $this->siteconfig->set('bbox_w', $_POST['bbox_w']);
    $this->siteconfig->set('bbox_s', $_POST['bbox_s']);
    $this->siteconfig->set('bbox_e', $_POST['bbox_e']);
    $this->siteconfig->set('bbox_n', $_POST['bbox_n']);
    $this->siteconfig->set('basemap_type',   $_POST['basemap_type']);
    $this->siteconfig->set('basemap_xyzurl', $_POST['basemap_xyzurl']);

    $this->siteconfig->set('bing_api_key', $_POST['bing_api_key']);
    $this->siteconfig->set('google_api_key', $_POST['google_api_key']);

    $this->siteconfig->set('preferred_geocoder', $_POST['preferred_geocoder']);

    $this->siteconfig->set('metric_units', $_POST['metric_units']);

    $this->siteconfig->set('mobile_bgcolor',         $_POST['mobile_bgcolor']);
    $this->siteconfig->set('mobile_fgcolor',         $_POST['mobile_fgcolor']);
    $this->siteconfig->set('mobile_buttonbgcolor1',  $_POST['mobile_buttonbgcolor1']);
    $this->siteconfig->set('mobile_buttonfgcolor1',  $_POST['mobile_buttonfgcolor1']);
    $this->siteconfig->set('mobile_buttonbgcolor2',  $_POST['mobile_buttonbgcolor2']);
    $this->siteconfig->set('mobile_buttonfgcolor2',  $_POST['mobile_buttonfgcolor2']);
    $this->siteconfig->set('mobile_alertbgcolor',    $_POST['mobile_alertbgcolor']);
    $this->siteconfig->set('mobile_alertfgcolor',    $_POST['mobile_alertfgcolor']);
    $this->siteconfig->set('place_markerglowcolor',  $_POST['place_markerglowcolor']);
    $this->siteconfig->set('event_markerglowcolor',  $_POST['event_markerglowcolor']);

    // now the upload content, which we vetted above but didn't actually update the siteconfig yet
    foreach ( array_keys($image_uploads) as $which_image) {
        if (! @$image_uploads[$which_image]['content']) continue; // no upload for this one, skip it

        // save the content and size to the contrived-format keys
        $this->siteconfig->set($which_image, $image_uploads[$which_image]['content'] );
        $this->siteconfig->set("{$which_image}_width",  $image_uploads[$which_image]['width'] );
        $this->siteconfig->set("{$which_image}_height", $image_uploads[$which_image]['height'] );
    }

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
    // hack: all users are Admin level; at this time we do not want to move forward with Website and Manager level distinctions
    $data['user']->username = $_POST['username'];
    //$data['user']->level    = (integer) $_POST['level'];
    $data['user']->level    = USER_LEVEL_ADMIN;
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
    $data['source'] = new EventDataSource();
    $data['source']->where('id',$id)->get();
    $data['source'] = $data['source']->convertToDriver();
    if (! $data['source']->id) return redirect(site_url('administration/event_sources'));

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
        $output = array(
            'status'    => 'ok',
            'text'      => "SUCCESS\n" . $e->getMessage(),
            'info'      => $e->extrainfo,
        );
    } catch (EventDataSourceErrorException $e) {
        $output = array(
            'status'    => 'error',
            'text'      => "ERROR\n" . $e->getMessage(),
            'info'      => $e->extrainfo,
        );
    }

    // hack: for some reason events are being left behind with event_id 0
    // thank you, MySQL for not being able to handle foreign key constraints and cascading deletes...
    $delete = new EventLocation();
    $delete->where('event_id',0)->get();
    foreach ($delete as $d) $d->delete();

    print json_encode($output);
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
    $_POST['option5']   = trim(@$_POST['option5']);
    $_POST['option6']   = trim(@$_POST['option6']);
    $_POST['option7']   = trim(@$_POST['option7']);
    $_POST['option8']   = trim(@$_POST['option8']);
    $_POST['option9']   = trim(@$_POST['option9']);
    if (! $_POST['name']) return print "The name is required.";
    if ($source->option_fields['url']     and $source->option_fields['url']['required']     and !$_POST['url'])      return print "Missing required field: {$source->option_fields['url']['name']}";
    if ($source->option_fields['option1'] and $source->option_fields['option1']['required'] and !$_POST['option1'])  return print "Missing required field: {$source->option_fields['option1']['name']}";
    if ($source->option_fields['option2'] and $source->option_fields['option2']['required'] and !$_POST['option2'])  return print "Missing required field: {$source->option_fields['option2']['name']}";
    if ($source->option_fields['option3'] and $source->option_fields['option3']['required'] and !$_POST['option3'])  return print "Missing required field: {$source->option_fields['option3']['name']}";
    if ($source->option_fields['option4'] and $source->option_fields['option4']['required'] and !$_POST['option4'])  return print "Missing required field: {$source->option_fields['option4']['name']}";
    if ($source->option_fields['option5'] and $source->option_fields['option5']['required'] and !$_POST['option5'])  return print "Missing required field: {$source->option_fields['option5']['name']}";
    if ($source->option_fields['option6'] and $source->option_fields['option6']['required'] and !$_POST['option6'])  return print "Missing required field: {$source->option_fields['option6']['name']}";
    if ($source->option_fields['option7'] and $source->option_fields['option7']['required'] and !$_POST['option7'])  return print "Missing required field: {$source->option_fields['option7']['name']}";
    if ($source->option_fields['option8'] and $source->option_fields['option8']['required'] and !$_POST['option8'])  return print "Missing required field: {$source->option_fields['option8']['name']}";
    if ($source->option_fields['option9'] and $source->option_fields['option9']['required'] and !$_POST['option9'])  return print "Missing required field: {$source->option_fields['option9']['name']}";

    // save it
    $source->name          = $_POST['name'];
    $source->url           = $_POST['url'];
    $source->option1       = $_POST['option1'];
    $source->option2       = $_POST['option2'];
    $source->option3       = $_POST['option3'];
    $source->option4       = $_POST['option4'];
    $source->option5       = $_POST['option5'];
    $source->option6       = $_POST['option6'];
    $source->option7       = $_POST['option7'];
    $source->option8       = $_POST['option8'];
    $source->option9       = $_POST['option9'];
    $source->enabled       = $_POST['enabled'];
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

    // delete it, then clean up orphans; see clearOrphanedRecords() for explanation
    $data['source']->delete();
    EventDataSource::clearOrphanedRecords();

    // send the user home
    redirect(site_url('administration/event_sources'));
}



/*******************************************************************************************************
 * MANAGEMENT OF PLACE DATA SOURCES
 *******************************************************************************************************/

public function place_sources() {
    $data = array();

    // the lists: Sources and Categories
    // Places list is below; uses filters...
    $data['sources'] = new PlaceDataSource();
    $data['sources']->get();

    $data['categories'] = new PlaceCategory();
    $data['categories']->get();

    // the list of Places, all of them; the filters in the admin page are done in-browser and we don't filter here
    $data['places'] = new Place();
    $data['places']->get();

    // some filter assocs for the filters in the Places section
    // these aren't used server side, they're done in-browser
    $data['places_filter_source_options'] = array();
    $data['places_filter_source_options'][''] = '(filter by source)';
    foreach ($data['sources'] as $s) $data['places_filter_source_options'][ $s->id ] = $s->name;

    $data['places_filter_category_options'] = array();
    $data['places_filter_category_options']['-1'] = '(filter by category)';
    $data['places_filter_category_options'][''] = 'UNCATEGORIZED';
    foreach ($data['categories'] as $s) $data['places_filter_category_options'][ $s->id ] = $s->name;

    // list of data source types, for the New popup
    $data['types'] = array();
    foreach (PlaceDataSource::$SOURCE_TYPES as $t) $data['types'][$t] = $t;

    // finally ready
    $this->load->view('administration/place_sources.phtml', $data);
}

public function place_source($id) {
    $data = array();

    $data['source'] = new PlaceDataSource();
    $data['source']->where('id',$id)->get();
    $data['source'] = $data['source']->convertToDriver();
    if (! $data['source']->id) return redirect(site_url('administration/place_sources#tab_sources'));

    // list of categories, for the list of auto-categorization rules
    $data['categories'] = new PlaceCategory();
    $data['categories']->get();

    // get the list of fields too, and convert to an assocarray, so they can pick from the list for any options that are 'isfield'
    // or so they can specify filters for the placedatasource's association to placecategories
    $data['required_fields'] = array();
    $data['optional_fields'] = array(''=>'(none)');
    $data['rule_fields']     = array(''=>'', '__ALLRECORDS'=>'ALL RECORDS');
    try {
        // get the list of fields, and express it in two different but similar ways:
        $fields = $data['source']->listFields();

        // A. an assoc of the fields, used to generate SELECT elements for fields that are required
        // B. that same assoc of fields but with a blank option prepended so they can select Nothing
        foreach ($fields as $f) $data['required_fields'][$f] = $f;
        foreach ($fields as $f) $data['optional_fields'][$f] = $f;

        // C. that same assoc of field names, but with a blank option and ALL RECORDS option
        // used for defining categorization rules
        foreach ($fields as $f) $data['rule_fields'][$f] = $f;
    } catch (PlaceDataSourceErrorException $e) {
        $data['warning'] = $e->getMessage();
    }

    // load an assoc of the rules for each category for this data source
    // e.g. category 17 has the rule "allowBike=1"
    $data['rules'] = array();
    $rules = new PlaceCategoryRule();
    $rules->where('placedatasource_id',$data['source']->id)->get();
    foreach ($rules as $r) $data['rules'][$r->placecategory_id] = array( 'field'=>$r->field, 'value'=>$r->value );

    // done!
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

    // look up the driver class for this new data source, and look through its options
    // any that have a default setting, go ahead and set them right now
    $driver = $source->convertToDriver();
    foreach ($driver->option_fields as $optname=>$optinfo) {
        if (! $optinfo['isfield'] and @$optinfo['default']) {
            $source->{$optname} = $optinfo['default'];
            $source->save();
        }
    }

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
        $driver = $source->convertToDriver();
        $driver->siteconfig = $this->siteconfig;
        $driver->reloadContent();
    } catch (PlaceDataSourceSuccessException $e) {
        $output = array(
            'status'    => 'ok',
            'text'      => "SUCCESS\n" . $e->getMessage(),
            'info'      => $e->extrainfo,
        );
    } catch (PlaceDataSourceErrorException $e) {
        $output = array(
            'status'    => 'error',
            'text'      => "ERROR\n" . $e->getMessage(),
            'info'      => $e->extrainfo,
        );
    }

    // if we got here then it was successful and we already have a message to hand back to the client
    // but first... go over the Places and have them re-categorized (silently)
    try {
        $source->recategorizeAllPlaces();
    } catch (PlaceDataSourceErrorException $e) {
        $output['status'] = 'error';
        $output['text'] .= "\n\n" . $e->getMessage();
    } catch (PlaceDataSourceSuccessException $e) {
        $output['text'] .= "\n\n" . $e->getMessage();
    }

    // send it off: all of the exception resultsd concatenated into one
    print json_encode($output);
}

public function ajax_save_place_source() {
    // fetch the data source by its ID# or die trying
    $source = new PlaceDataSource();
    $source->where('id',$_POST['id'])->get();
    $source = $source->convertToDriver();
    if (! $source->id) return print "Could not find that data source.";

    // any remaining errors (below) are non-fatal,
    // so save it now and then report the errors below
    $source->name          = trim(strip_tags(@$_POST['name']));
    $source->url           = @$_POST['url'];
    $source->enabled       = $_POST['enabled'];
    $source->option1       = trim(@$_POST['option1']);
    $source->option2       = trim(@$_POST['option2']);
    $source->option3       = trim(@$_POST['option3']);
    $source->option4       = trim(@$_POST['option4']);
    $source->option5       = trim(@$_POST['option5']);
    $source->option6       = trim(@$_POST['option6']);
    $source->option7       = trim(@$_POST['option7']);
    $source->option8       = trim(@$_POST['option8']);
    $source->option9       = trim(@$_POST['option9']);
    $source->save();

    // more stuff to be saved EVEN IF we're about to encounter an error
    // this is the "rules" for this data source as pertaining to each PlaceCategory
    if (is_array(@$_POST['categorization'])) {
        foreach ($_POST['categorization'] as $categoryid=>$field_and_value) {
            $rule = new PlaceCategoryRule();
            $rule->where('placecategory_id',$categoryid)->where('placedatasource_id',$source->id)->get();
            $rule->placecategory_id   = $categoryid;
            $rule->placedatasource_id = $source->id;
            $rule->field              = $field_and_value['field'];
            $rule->value              = $field_and_value['value'];
            $rule->save();
        }
    }

    // AJAX endpoint, just say OK if we get that far
    // these fields are saved after the main attributes are saved, to solver a chicken-and-egg problem of having a bad URL or field name,
    // preventing us from finding valid field names, preventing this from being saved so we can find the right field names on the next load
    /*
    if ($source->option_fields['url']     and $source->option_fields['url']['required']     and !@$_POST['url'])      return print "Missing required field: {$source->option_fields['url']['name']}";
    if ($source->option_fields['option1'] and $source->option_fields['option1']['required'] and !@$_POST['option1'])  return print "Missing required field: {$source->option_fields['option1']['name']}";
    if ($source->option_fields['option2'] and $source->option_fields['option2']['required'] and !@$_POST['option2'])  return print "Missing required field: {$source->option_fields['option2']['name']}";
    if ($source->option_fields['option3'] and $source->option_fields['option3']['required'] and !@$_POST['option3'])  return print "Missing required field: {$source->option_fields['option3']['name']}";
    if ($source->option_fields['option4'] and $source->option_fields['option4']['required'] and !@$_POST['option4'])  return print "Missing required field: {$source->option_fields['option4']['name']}";
    if ($source->option_fields['option5'] and $source->option_fields['option5']['required'] and !@$_POST['option5'])  return print "Missing required field: {$source->option_fields['option5']['name']}";
    if ($source->option_fields['option6'] and $source->option_fields['option6']['required'] and !@$_POST['option6'])  return print "Missing required field: {$source->option_fields['option6']['name']}";
    if ($source->option_fields['option7'] and $source->option_fields['option7']['required'] and !@$_POST['option7'])  return print "Missing required field: {$source->option_fields['option7']['name']}";
    if ($source->option_fields['option8'] and $source->option_fields['option8']['required'] and !@$_POST['option8'])  return print "Missing required field: {$source->option_fields['option8']['name']}";
    if ($source->option_fields['option9'] and $source->option_fields['option9']['required'] and !@$_POST['option9'])  return print "Missing required field: {$source->option_fields['option9']['name']}";
    */

    print 'ok';
}

public function place_source_delete() {
    // fetch the specified data source or die trying
    $data = array();
    $data['source'] = new PlaceDataSource();
    $data['source']->where('id',$_POST['id'])->get();
    if (! $data['source']->id) return redirect(site_url('administration/place_sources#tab_sources'));

    // if they're not POSTing a confirmation, bail
    if (! @$_POST['ok']) return $this->load->view('administration/place_source_delete.phtml', $data);

    // delete it, then clean up orphans; see clearOrphanedRecords() for explanation
    $data['source']->delete();
    PlaceDataSource::clearOrphanedRecords();

    // send the user home
    redirect(site_url('administration/place_sources#tab_sources'));
}

public function ajax_create_place_category() {
    // validation: the type must be valid, and the name must be given
    $_POST['name'] = trim(strip_tags(@$_POST['name']));
    if (! $_POST['name']) return print "The category name is required.";

    // check that this name isn't already in use
    // a weak precaution, spaces and simply adding a comma can "fool" it, but prevents a common user error
    $already = new PlaceCategory();
    $already->where('name',$_POST['name'])->get();
    if ($already->id) return print "There is already a category with that name.";

    // save the category
    $category = new PlaceCategory();
    $category->name = $_POST['name'];
    $category->save();

    // AJAX endpoint: just say OK
    print $category->id;
}

public function place_category($id=null) {
    $data = array();

    $data['category'] = new PlaceCategory();
    $data['category']->where('id',$id)->get();
    if (! $data['category']->id) return redirect(site_url('administration/place_sources#tab_categories'));

    $this->load->view('administration/place_category.phtml', $data);
}

public function ajax_save_place_category() {
    // fetch the data source by its ID# or die trying
    $category = new PlaceCategory();
    $category->where('id',$_POST['id'])->get();
    if (! $category->id) return print "Could not find that data category.";

    // any remaining errors (below) are non-fatal,
    // so save it now and then report the errors below
    $category->name = trim(strip_tags(@$_POST['name']));
    $category->save();

    // AJAX endpoint, just say OK
    print 'ok';
}

public function place_category_delete() {
    // fetch the specified data source or die trying
    $data = array();
    $data['category'] = new PlaceCategory();
    $data['category']->where('id',$_POST['id'])->get();
    if (! $data['category']->id) return redirect(site_url('administration/place_sources#tab_categories'));

    // if they're not POSTing a confirmation, bail
    if (! @$_POST['ok']) return $this->load->view('administration/place_category_delete.phtml', $data);

    // delete it, send the user home
    $data['category']->delete();
    redirect(site_url('administration/place_sources#tab_categories'));
}



/*******************************************************************************************************
 * MANAGEMENT OF PLACES   (yeah, the actual points!)
 *******************************************************************************************************/

public function place($id) {
    $data = array();
    $data['place'] = new Place();
    $data['place']->where('id',$id)->get();
    if (! $data['place']->id) return redirect(site_url('administration/place_sources#tab_places'));

    // simply print out the Place as a form; saving etc. is done va AJAX
    $this->load->view('administration/place.phtml',$data);
}

public function ajax_save_placeactivity() {
    // accept a POST with the content of a PlaceActivity: perhaps brand new, perhaps pre-existing
    // the difference is the 'id' datum, indicating a pre-existing PlaceActivity
    $act = new PlaceActivity();
    if (@$_POST['id']) {
        // a pre-existing: fetch it, make sure it exists
        // cross-check with the Place ID just as a double-check
        $act->where('id',$_POST['id'])->where('place_id',@$_POST['place_id'])->get();
        if (! $act->id) return print "Could not find that activity. How did this happen?";
    } else {
        // a new one: use the Place ID and make sure it really exists, assign the Place to the new PlaceActivity
        $place = new Place();
        $place->where('id',@$_POST['place_id'])->get();
        if (! $place->id) return print "Could not find the place that you are editing. How did this happen?";
        $act->place_id = $place->id;
    }

    // the submitted times from the picker are unsuitable for the database, e.g. 3:00pm
    // convert to a real time, then into hh:mm format
    $starttime = date('H:i', strtotime($_POST['starttime']) );
    $endtime   = date('H:i', strtotime($_POST['endtime']) );

    // however we got here, go ahead and save the rest of the attribs
    // note that the weekdays may be missing entirely, being checkboxes
    $act->name      = $_POST['name'];
    $act->starttime = $starttime;
    $act->endtime   = $endtime;
    $act->mon       = @$_POST['mon'] ? 1 : 0;
    $act->tue       = @$_POST['tue'] ? 1 : 0;
    $act->wed       = @$_POST['wed'] ? 1 : 0;
    $act->thu       = @$_POST['thu'] ? 1 : 0;
    $act->fri       = @$_POST['fri'] ? 1 : 0;
    $act->sat       = @$_POST['sat'] ? 1 : 0;
    $act->sun       = @$_POST['sun'] ? 1 : 0;
    $act->save();

    // AJAX endpoint, just say OK and let the caller figure it out
    print 'ok';
}


public function ajax_delete_placeactivity() {
    // accept a POST with the ID# of a PlaceActivity
    $act = new PlaceActivity();
    $act->where('id',@$_POST['id'])->get();
    if (! $act->id) return print "Could not find that activity. Maybe it was deleted already?";

    // AJAX endpoint, just say OK and let the caller figure it out
    $act->delete();
    print 'ok';
}


} // end of Controller