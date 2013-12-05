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
    'Shapefile',
    'ArcGIS REST API',
    'CartoDB',
);

public function convertToDriver() {
    switch ($this->type) {
        case 'Google Spreadsheet':
            $subclass = "PlaceDataSource_GoogleSpreadsheet";
            break;
        case 'Shapefile':
            $subclass = "PlaceDataSource_Shapefile";
            break;
        case 'ArcGIS REST API':
            $subclass = "PlaceDataSource_ArcGISREST";
            break;
        case 'CartoDB':
            $subclass = "PlaceDataSource_CartoDB";
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
var $has_many         = array('place',);

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


} // end of Model



/**********************************************************************************************
 * EXCEPTIONS
 * exceptions are used internally to communicate both success and failure, as they can return 
 * more complex messages than a simple TRUE/FALSE return
 **********************************************************************************************/

class PlaceDataSourceSuccessException extends Exception {
}

class PlaceDataSourceErrorException extends Exception {
}


