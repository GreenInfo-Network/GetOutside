<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource extends DataMapper {

var $table            = 'eventdatasources';
var $default_order_by = array('name','type');
var $has_one          = array();
var $has_many         = array('event',);


/**********************************************************************************************
 * SUBCLASSING THIS DATA SOURCE
 * To form specific drivers such as the EventDataSource_GoogleCalendar follow these steps:
 * - define the class in a class file, same as any other DataMapper ORM class
 *      Be sure it implements the necessary methods as described in the INSTANCE METHODS section below
 *      see existing subclasses for a crib sheet
 * - list the relationship in Event, allowing an Event to have a relationship to a EventDataSource_Whatever
 *      see the existing entries in models/event.php for a crib sheet
 * - add the driver to config/config.php so that new driver appears as an option in listings
 * - edit the convertToDriver() method below, so it will detect the driver constant
 *      and instantiate the correct subclass
 **********************************************************************************************/


/**********************************************************************************************
 * FACTORY METHOD
 * query this EventDataSource and have it return an instance of the appropriate subclass, 
 * as detected by its "type" field. The alternative is that the Controller code has a bunch of
 * "if Google Calendar, else if ActiveNet, else if Google Spreadsheet" and that gets out of hand quickly!
 * Instead, use this handy method to have it return a brand-new instance of "whatever subclass this is"
 **********************************************************************************************/
/*
 * convertToDriver()
 * This instance method will return a new DataMapper ORM class instance, that being a subclass of
 * EventDataSource. The subclass used, is detected from the "type" field, e.g. if it's "Google Calendar"
 * then the returned instance will be of the EventDataSource_GoogleCalendar class.
 * 
 * Intended use is within Controller methods, where you have a EventDataSource instance fetched by ID#
 * and want to get an instance of the appropriate subclass... without writing a switch.
 */
public function convertToDriver() {
    switch ($this->type) {
        case 'Google Calendar':
            $subclass = "EventDataSource_GoogleCalendar";
            break;
        case 'Google Spreadsheet':
            $subclass = "EventDataSource_GoogleSpreadsheet";
            break;
        case 'ActiveNet API':
            $subclass = "EventDataSource_ActiveNet";
            break;
        default:
            throw new EventDataSourceErrorException('This EventDataSource is of an unknown type. How is that possible?');
    }

    // instantiate the appropriate DataMapper ORM subclass, and filter it the the one record with my same ID
    $instance = new $subclass();
    $instance->where('id',$this->id)->get();
    if (! $instance->id) throw new EventDataSourceErrorException('Could not load $subclass instance with ID {$this->id}');
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
 * Reload content for this EventDataSource. This is specific to each driver, and in this base class it's an error to try since it's not a driver.
 */
public function reloadContent() {
    throw new EventDataSourceErrorException('Cannot call reloadContent() on a root data source class. Use a driver subclass instead.');
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
public function howManyEvents() {
    return $this->event->count();
}


} // end of Model



/**********************************************************************************************
 * EXCEPTIONS
 * exceptions are used internally to communicate both success and failure, as they can return 
 * more complex messages than a simple TRUE/FALSE return
 **********************************************************************************************/

class EventDataSourceSuccessException extends Exception {
}

class EventDataSourceErrorException extends Exception {
}


