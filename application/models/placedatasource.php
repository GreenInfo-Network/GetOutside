<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceDataSource extends DataMapper {
/**********************************************************************************************
 * This class serves three functions:
 * - A standard DataMapper ORM class, by which you may list all known PlaceDataSource entries
 *      in the "PlaceDataSources" DB table. This is ordinary usage for DataMapper ORM.
 * - A superclass, template, and documentation for creating "driver" subclasses,
 *      e.g. PlaceDataSource_iCal
 * - The factory method convertToDriver() which, when given a PlaceDataSource instance,
 *      will detect the "type" field, and will instantiate the appropriate subclass.
 *      Thus, you can iterate over a PlaceDataSource resultset, and if appropriate call
 *      $specific = $instance->convertToDriver() in order to get back the specific subclass.
 **********************************************************************************************/

/**********************************************************************************************
 * SUBCLASSING THIS DATA SOURCE
 * To create new drivers such as the PlaceDataSource_GoogleSpreadsheet follow these steps:
 * - Define the class in a class file, same as any other DataMapper ORM class
 *      Be sure it implements the necessary interface as described in the INSTANCE METHODS and 
 *      DATABASE FIELDS sections below. See existing subclasses for a crib sheet
 * - Edit the Event model class, allowing an Event to have a relationship to your new 
 *      PlaceDataSource_Whatever class. See the existing entries in models/event.php for examples.
 * - Add the driver to the $SOURCE_TYPES list in the FACTORY METHOD section below
 *      so it appears as an option in listings.
 * - Edit the convertToDriver() method in the FACTORY METHOD section below, so 
 *      it will detect the driver by name and instantiate the correct subclass.
 **********************************************************************************************/

/**********************************************************************************************
 * FACTORY METHOD
 * query this PlaceDataSource and have it return an instance of the appropriate subclass, 
 * as detected by its "type" field. The alternative is that the Controller code has a bunch of
 * "if Google Spreadsheet, else if ActiveNet, else if Google Spreadsheet" and that gets out of hand quickly!
 * Instead, use this handy method to have it return a brand-new instance of "whatever subclass this is"
 **********************************************************************************************/
/*
 * convertToDriver()
 * This instance method will return a new DataMapper ORM class instance, that being a subclass of
 * PlaceDataSource. The subclass used, is detected from the "type" field, e.g. if it's "Google Spreadsheet"
 * then the returned instance will be of the PlaceDataSource_GoogleSpreadsheet class.
 * 
 * Intended use is within Controller methods, where you have a PlaceDataSource instance fetched by ID#
 * and want to get an instance of the appropriate subclass... without writing a switch.
 */
public static $SOURCE_TYPES = array(
    'Google Spreadsheet',
    'ArcGIS REST API',
    'CartoDB',
    'OGC WFS',
);

public function convertToDriver() {
    switch ($this->type) {
        case 'Google Spreadsheet':
            $subclass = "PlaceDataSource_GoogleSpreadsheet";
            break;
        case 'ArcGIS REST API':
            $subclass = "PlaceDataSource_ArcGISREST";
            break;
        case 'CartoDB':
            $subclass = "PlaceDataSource_CartoDB";
            break;
        case 'OGC WFS':
            $subclass = "PlaceDataSource_WFS";
            break;
        default:
            throw new PlaceDataSourceErrorException('This PlaceDataSource is of an unknown type ({$this->type}). How is that possible?');
    }

    // instantiate the appropriate DataMapper ORM subclass, and filter it the the one record with my same ID
    $instance = new $subclass();
    $instance->where('id',$this->id)->get();
    if (! $instance->id) throw new PlaceDataSourceErrorException("Could not load $subclass instance with ID {$this->id}");
    return $instance;
}



/**********************************************************************************************
 * DATABASE FIELDS
 **********************************************************************************************/

// the standard ones for DataMapper ORM, relating this PlaceDataSource to Events, defining sorting, etc.
var $table            = 'placedatasources';
var $default_order_by = array('name','type');
var $has_one          = array();
var $has_many         = array('place','placecategoryrule',);

// these defines which fields/features are appropriate to your driver, and you will override these in subclasses
// e.g. Google Spreadsheet needs an URL but does not need an App ID nor username, while Active.com can *optionally* use the App ID to store your Org ID for filtering
// structure here: an assocarray of field names, most notably "url" and the arbitrary "option" fields
// if a field is not used by your driver, make it NULL; this causes the field not to be displayed in the event datasource editing page
// if the field is used, set it to an array and it will appear in the event datasource editing page
//      required => TRUE/FALSE      indicates whether this field MUST be filled in; use FALSE if it's okay for it to be blank (of course, make your driver smart enough to handle both its presence and absence)
//      isfield => TRUE/FALSE       indicates whether this field must be a selected field from the data source, e.g. "select one of these columns"
//                                  if this is set, the UI will make this entry a selection, from a list of fields present in the data source
//      title => text               on the editing page, the title of this field, e.g. "API Key"
//      help => text                on the editing page, this forms the text instructions for the field, e.g. "Contact your CSR for more info..."
// The text fields accept HTML and will not be escaped. Tip: If you include hyperlinks, use target=_blank so they don't lose the admin UI.
var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"URL", 'help'=>"Enter the URL of the remote feed."),
    'option1' => NULL,
    'option2' => NULL,
    'option3' => NULL,
    'option4' => NULL,
);


/**********************************************************************************************
 * CLEANUP
 * we can't presume that MySQL supports FK constraints and cascade deletes (they may be using MyISAM)
 * so when we delete this data source, DataMapper simply sets the places' eventdatasource_id to 0... which doesn't really get rid of them
 * so when we delete a data source,it's wise to call this function to then clean up the newly-orphaned records
 **********************************************************************************************/

public static function clearOrphanedRecords() {
    $ci = get_instance();
    $ci->db->query('DELETE FROM places WHERE placedatasource_id=0');
    $ci->db->query('DELETE FROM placeactivities WHERE place_id NOT IN (SELECT id FROM places)');
    $ci->db->query('DELETE FROM placecategories_places WHERE place_id NOT IN (SELECT id FROM places)');
}



/**********************************************************************************************
 * INSTANCE METHODS
 **********************************************************************************************/

/*
 * reloadContent()
 * Reload content for this PlaceDataSource. This is specific to each driver, and in this base class it's an error to try since it's not a driver.
 */
public function reloadContent() {
    throw new PlaceDataSourceErrorException('Cannot call reloadContent() on a root data source class. Use a driver subclass instead.');
}


/*
 * listFields()
 * List the fields in this PlaceDataSource. This is specific to each driver, and in this base class it's an error to try since it's not a driver.
 * Primary use here is to return a list of field names, so they can be selected as "option" fields, e.g. "option3 (title) is the PROJECT_NAME field"
 */
public function listFields() {
    throw new PlaceDataSourceErrorException('Cannot call listFields() on a root data source class. Use a driver subclass instead.');
}


/*
 * lastFetch()
 * Return a pretty, human-friendly date & time of the "last_fetch" field.
 * This natively being a Unix timestamp, it's not easy on the eyes. ;)
 */
public function lastFetch() {
    if (! $this->last_fetch) return 'Never';
    return date('M j, Y @ H:i', $this->last_fetch);
}


/*
 * howManyEvents()
 * Return a count of how many events are "in" this data source.
 */
public function howManyPlaces() {
    return $this->place->count();
}


/*
 * howManyEvents()
 * Return a count of how many events are "in" this data source.
 */
public function recategorizePlace($place,$attribs) {
    return $this->place->count();
}


/*
 * recategorizeAllrecategorizeAllPlaces()
 * Look over all of our Places and assign them to zero or more PlaceCategories
 * No params, no return; it's all done in-place.
 * 
 * This makes use of the attributes_json field where we stored the complete attributes from the data source,
 * and of calculateCategoryIDsFromAttributes() which parses these attributes and compares them to the DataSource's categorization rules
 *
 * Typical use case, would be after loading a data source's locations, after which new Places won't have categories
 * and pre-existing Places may be using outdated categorizations. A calling context would be similar to this:
 */
public function recategorizeAllPlaces() {
    // initialize the counters
    $looked_good = 0;
    $had_none    = 0;

    // don't reuse the existing Place affiliations, as they're probably outdated
    // after all, we'd usually call this after refreshing
    $places = new Place();
    $places->where('placedatasource_id',$this->id)->get();
    foreach ($places as $place) {
        // fetch the stored attributes, then figure out what categories would fit it
        $attribs = @json_decode($place->attributes_json);
        $category_ids = $this->calculateCategoryIDsFromAttributes($attribs);

        // delete existing categories
        $place->placecategory->delete($place);

        // assign new categories, and increment whichever counter is appropriate
        if (sizeof($category_ids)) {
            $looked_good++;

            $cats = new PlaceCategory();
            $cats->where_in('id',$category_ids)->get();
            $place->save($cats->all);
        } else {
            $had_none++;
        }
    }

    // done, hand back an exception with a list of our message components and potentially other metadata
    $messages = array();
    if ($looked_good) $messages[] = "$looked_good places assigned to categories OK.";
    if ($had_none)    $messages[] = "$had_none places fit no categories.";
    throw new PlaceDataSourceSuccessException($messages);
}



/*
 * calculateCategoryIDsFromAttributes($attribs)
 * Given an object of attributes (not an assocarray!)
 * examine our own PlaceCategoryRule set and figure out what set of categories should apply to that hypothetical Place.
 *
 * Primarily used by recategorizeAllrecategorizeAllPlaces()
 */
public function calculateCategoryIDsFromAttributes($attribs) {
    $catids = array();

    foreach ($this->placecategoryrule as $rule) {
        if (! $rule->field) continue; // no rule for this category, skip it

        // the special __ALLRECORDS case matches all records, no matter their attributes
        // otherwise, it's a field name and value, and there must be a match
        $match = FALSE;
        if      ($rule->field == '__ALLRECORDS')                                            $match = TRUE;
        else if ($rule->value and 0 == strcasecmp(@$attribs->{$rule->field},$rule->value))  $match = TRUE;

        // okay, did we find anything?
        if (! $match) continue;
        $catids[] = $rule->placecategory_id;
    }

    return $catids;
}




} // end of Model


/**********************************************************************************************
 * EXCEPTIONS
 * exceptions are used internally to communicate both success and failure,
 * as they can return more complex messages than a simple TRUE/FALSE return
 * these deviate somewhat from SPL's normal exceptions:
 * - first param is a LIST of message strings, not a single text string
 *      this is joined with newlines to form the text for getMessage()
 *      but does allow the caller to parse out individual messages, join with newlines, whatever
 * - second param is an arbitrary assocarray, stored in the Exception's "extrainfo" attribute:  $e->extrainfo
 *      this is suitable for whatever arbitrary driver-specific information you may want to stick into the exception,
 *      be it number of successes and failures, specific codes for individual task failures, whatever
 *      BUT there is a standard, as follows:
 *          - for error exceptions, this is typically undefined (defaults to empty array)
 *              the error message list has what we needed
 *          - for success exceptions, the following attributes should be defined:
 *              details     list of strings, any arbitrary "verbose debugging" output to include into the on-disk report
 *              added       integer, number of new records created in the database
 *              updated     integer, number of records updated in the database (ID in DB and remote, an update)
 *              deleted     integer, number of records deleted (ID in DB but not in remote, so outdated)
 *              nogeom      integer, number of records skipped due to bad geometry (coordinates missing, malformed)
 **********************************************************************************************/

class PlaceDataSourceSuccessException extends Exception {
    public function __construct($messages=null,$extrainfo=array()) {
        // no messages? no way
        if (! $messages) throw new Exception("Failed to throw PlaceDataSourceSuccessException: no messages given");
        $this->messages = $messages;

        // go ahead and construct a regular Exception
        $message = implode("\n", $this->messages);
        $code = 0;
        parent::__construct($message,$code);

        // then add the $extrainfo
        $this->extrainfo = $extrainfo;
    }
}

class PlaceDataSourceErrorException extends Exception {
    public function __construct($messages=null,$extrainfo=array()) {
        // no messages? no way
        if (! $messages) throw new Exception("Failed to throw PlaceDataSourceErrorException: no messages given");
        $this->messages = $messages;

        // go ahead and construct a regular Exception
        $message = implode("\n", $this->messages);
        $code = 0;
        parent::__construct($message,$code);

        // then add the $extrainfo
        $this->extrainfo = $extrainfo;
    }
}
