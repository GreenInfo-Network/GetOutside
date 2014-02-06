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
        return print "SUCCESS\n" . $e->getMessage();
    } catch (EventDataSourceErrorException $e) {
        return print "ERROR\n" . $e->getMessage();
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
    $source->name          = $_POST['name'];
    $source->color         = $_POST['color'];
    $source->url           = $_POST['url'];
    $source->option1       = $_POST['option1'];
    $source->option2       = $_POST['option2'];
    $source->option3       = $_POST['option3'];
    $source->option4       = $_POST['option4'];
    $source->on_by_default = $_POST['on_by_default'];
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

    // delete it, send the user home
    $data['source']->delete();
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

    // list of categories, for the list of auto-categoization rules
    $data['categories'] = new PlaceCategory();
    $data['categories']->get();

    // get the list of fields too, and convert to an assocarray, so they can pick from the list for any options that are 'isfield'
    // or so they can specify filters for the placedatasource's association to placecategories
    $data['fields']      = array();
    $data['rule_fields'] = array(''=>'', '__ALLRECORDS'=>'ALL RECORDS');
    try {
        // get the list of fields, and express it in two different but similar ways:
        $fields = $data['source']->listFields();;

        // A. an assoc of the fields, used to generate SELECT elements
        foreach ($fields as $f) $data['fields'][$f] = $f;

        // B. that same assoc but with a blank option
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
    } catch (PlaceDataSourceErrorException $e) {
        return print "ERROR\n" . $e->getMessage();
    } catch (PlaceDataSourceSuccessException $e) {
        $message = "SUCCESS\n" . $e->getMessage();
    }

    // if we got here then it was successful and we already have a message to hand back to the client
    // but first... go over the Places and have them re-categorized (silently)
    try {
        $source->recategorizeAllPlaces();
    } catch (PlaceDataSourceErrorException $e) {
        return print "ERROR\n" . $e->getMessage();
    } catch (PlaceDataSourceSuccessException $e) {
        $message .= "\n\n" . $e->getMessage();
        return print $message;
    }
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
    foreach ($_POST['categorization'] as $categoryid=>$field_and_value) {
        $rule = new PlaceCategoryRule();
        $rule->where('placecategory_id',$categoryid)->where('placedatasource_id',$source->id)->get();
        $rule->placecategory_id   = $categoryid;
        $rule->placedatasource_id = $source->id;
        $rule->field              = $field_and_value['field'];
        $rule->value              = $field_and_value['value'];
        $rule->save();
    }

    // AJAX endpoint, just say OK if we get that far
    // these fields are saved after the main attributes are saved, to solver a chicken-and-egg problem of having a bad URL or field name,
    // preventing us from finding valid field names, preventing this from being saved so we can find the right field names on the next load
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

    // delete it, send the user home
    $data['source']->delete();
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
    $category->name          = trim(strip_tags(@$_POST['name']));
    $category->enabled       = $_POST['enabled'];
    $category->on_by_default = $_POST['on_by_default'];
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


} // end of Controller