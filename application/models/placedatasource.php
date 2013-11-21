<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceDataSource extends DataMapper {

var $table            = 'placedatasources';
var $default_order_by = array('name','type');
var $has_one          = array();
var $has_many         = array('place',);

    'Google Spreadsheet',

/**********************************************************************************************
 * SUBCLASSING THIS DATA SOURCE
 * To create new drivers such as the PlaceDataSource_GoogleCalendar follow these steps:
 * - define the class in a class file, same as any other DataMapper ORM class
 *      Be sure it implements the necessary methods as described in the INSTANCE METHODS section below
 *      see existing subclasses for a crib sheet
 * - list the relationship in Place, allowing a Place to have a relationship to a PlaceDataSource_Whatever
 *      see the existing entries in models/event.php for a crib sheet
 * - add the driver to the $SOURCE_TYPES list below so it appears as an option in listings
 * - edit the convertToDriver() method below, so it will detect the driver constant
 *      and instantiate the correct subclass
 **********************************************************************************************/

public static $SOURCE_TYPES = array(
    'Google Spreadsheet',
);


/**********************************************************************************************
 * FACTORY METHOD
 * query this PlaceDataSource and have it return an instance of the appropriate subclass, 
 * as detected by its "type" field. The alternative is that the Controller code has a bunch of
 * "if Google Calendar, else if ActiveNet, else if Google Spreadsheet" and that gets out of hand quickly!
 * Instead, use this handy method to have it return a brand-new instance of "whatever subclass this is"
 **********************************************************************************************/
/*
 * convertToDriver()
 * This instance method will return a new DataMapper ORM class instance, that being a subclass of
 * PlaceDataSource. The subclass used, is detected from the "type" field, e.g. if it's "Google Calendar"
 * then the returned instance will be of the PlaceDataSource_GoogleCalendar class.
 * 
 * Intended use is within Controller methods, where you have a PlaceDataSource instance fetched by ID#
 * and want to get an instance of the appropriate subclass... without writing a switch.
 */
public function convertToDriver() {
    switch ($this->type) {
        case 'Google Spreadsheet':
            $subclass = "PlaceDataSource_GoogleSpreadsheet";
            break;
        default:
            throw new PlaceDataSourceErrorException('This PlaceDataSource is of an unknown type. How is that possible?');
    }

    // instantiate the appropriate DataMapper ORM subclass, and filter it the the one record with my same ID
    $instance = new $subclass();
    $instance->where('id',$this->id)->get();
    if (! $instance->id) throw new PlaceDataSourceErrorException("Could not load $subclass instance with ID {$this->id}");
    return $instance;
}



/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



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
 * lastFetch()
 * Return a pretty, human-friendly date & time of the "last_fetch" field.
 * This natively being a Unix timestamp, it's not easy on the eyes. ;)
 */
public function lastFetch() {
    if (! $this->last_fetch) return 'Never';
    return date('M n, Y @ H:i', $this->last_fetch);
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


