<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource extends DataMapper {
/**********************************************************************************************
 * This class serves three functions:
 * - A standard DataMapper ORM class, by which you may list all known EventDataSource entries
 *      in the "eventdatasources" DB table. This is ordinary usage for DataMapper ORM.
 * - A superclass, template, and documentation for creating "driver" subclasses,
 *      e.g. EventDataSource_iCal
 * - The factory method convertToDriver() which, when given a EventDataSource instance,
 *      will detect the "type" field, and will instantiate the appropriate subclass.
 *      Thus, you can iterate over a EventDataSource resultset, and if appropriate call
 *      $specific = $instance->convertToDriver() in order to get back and instance of
 *      that specific subclass. This is useful if you're starting with a list of all sources,
 *      but then want to trigger a driver-dependent behavior on an instance, such as reloading.
 **********************************************************************************************/

/**********************************************************************************************
 * SUBCLASSING THIS DATA SOURCE
 * To create new drivers such as the EventDataSource_Atom follow these steps:
 * - Define the class in a class file, same as any other DataMapper ORM class
 *      Be sure it implements the necessary interface as described in the INSTANCE METHODS and 
 *      DATABASE FIELDS sections below. See existing subclasses for a crib sheet
 * - Edit the Event model class, allowing an Event to have a relationship to your new 
 *      EventDataSource_Whatever class. See the existing entries in models/event.php for examples.
 * - Add the driver to the $SOURCE_TYPES list in the FACTORY METHOD section below
 *      so it appears as an option in listings.
 * - Edit the convertToDriver() method in the FACTORY METHOD section below, so 
 *      it will detect the driver by name and instantiate the correct subclass.
 **********************************************************************************************/

/**********************************************************************************************
 * FACTORY METHOD
 * query this EventDataSource and have it return an instance of the appropriate subclass, 
 * as detected by its "type" field. The alternative is that the Controller code has a bunch of
 * "if Atom, else if ActiveNet, else if iCal, ..." and that gets out of hand quickly!
 * Instead, use this handy method to have it return a brand-new instance of "whatever subclass this is"
 **********************************************************************************************/
/*
 * convertToDriver()
 * This instance method will return a new DataMapper ORM class instance, that being a subclass of
 * EventDataSource. The subclass used, is detected from the "type" field, e.g. if it's "iCal"
 * then the returned instance will be of the EventDataSource_iCal class.
 * 
 * Intended use is within Controller methods, where you have a EventDataSource instance fetched by ID#
 * and want to get an instance of the appropriate subclass... without writing a switch.
 */
public static $SOURCE_TYPES = array(
    'Atom Feed',
    'RSS 2.0 Feed',
    'iCal Feed',
    'Active.com API',
    'Google Calendar API',
);

public function convertToDriver() {
    switch ($this->type) {
        case 'RSS 2.0 Feed':
            $subclass = "EventDataSource_RSS2";
            break;
        case 'Atom Feed':
            $subclass = "EventDataSource_Atom";
            break;
        case 'iCal Feed':
            $subclass = "EventDataSource_iCal";
            break;
        case 'Active.com API':
            $subclass = "EventDataSource_Active";
            break;
        case 'Google Calendar API':
            $subclass = "EventDataSource_GoogleCalendar";
            break;
        default:
            throw new EventDataSourceErrorException('This EventDataSource is of an unknown type ({$this->type}). How is that possible?');
    }

    // instantiate the appropriate DataMapper ORM subclass, and filter it the the one record with my same ID
    $instance = new $subclass();
    $instance->where('id',$this->id)->get();
    if (! $instance->id) throw new EventDataSourceErrorException("Could not load $subclass instance with ID {$this->id}");
    return $instance;
}



/**********************************************************************************************
 * DATABASE FIELDS
 **********************************************************************************************/

// the standard ones for DataMapper ORM, relating this EventDataSource to Events, defining sorting, etc.
var $table            = 'eventdatasources';
var $default_order_by = array('name','type');
var $has_one          = array();
var $has_many         = array('event',);

// these defines which fields/features are appropriate to your driver, and you will override these in subclasses
// e.g. iCal/ICS feeds need an URL and that's all, while Active.com can *optionally* use the App ID to store your Org ID for filtering
// structure here: an assocarray of field names, most notably "url" and the arbitrary "option" fields
// if a field is not used by your driver, make it NULL; this causes the field not to be displayed in the event datasource editing page
// if the field is used, set it to an array and it will appear in the event datasource editing page
//      required => TRUE/FALSE      indicates whether this field MUST be filled in; use FALSE if it's okay for it to be blank (of course, make your driver smart enough to handle both its presence and absence)
//      title => text               on the editing page, the title of this field, e.g. "API Key"
//      help => text                on the editing page, this forms the text instructions for the field, e.g. "Contact your CSR for more info..."
//      options => assoc            optional; instead of a text field, create a SELECT dropdown with these value=>label options
// The text fields accept HTML and will not be escaped. Tip: If you include hyperlinks, use target=_blank so they don't lose the admin UI.
var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"URL", 'help'=>"Enter the URL of the remote feed."),
    'option1' => NULL,
    'option2' => NULL,
    'option3' => NULL,
    'option4' => NULL,
    'option5' => NULL,
    'option6' => NULL,
    'option7' => NULL,
    'option8' => NULL,
    'option9' => NULL,
);

// this flag indicates whether this data source driver supports EventLocations
// that is, whether the Events that are loaded may also have location associated to them, forming EventLocations linked to the Events
// this flag is intended as an advisory to possible callers, e.g. a hypothetical cronjob which iterates over location-aware EventDataSource instances
// your driver may or may not use this flag for its own internal purposes
var $supports_location = FALSE;


/**********************************************************************************************
 * CLEANUP
 * we can't presume that MySQL supports FK constraints and cascade deletes (they may be using MyISAM)
 * so when we delete this data source, DataMapper simply sets the events' eventdatasource_id to 0... which doesn't really get rid of them
 * so when we delete a data source,it's wise to call this function to then clean up the newly-orphaned records
 **********************************************************************************************/

public static function clearOrphanedRecords() {
    $ci = get_instance();
    $ci->db->query('DELETE FROM events WHERE eventdatasource_id=0');
    $ci->db->query('DELETE FROM eventlocations WHERE event_id NOT IN (SELECT id FROM events)');
}


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
    return date('M j, Y @ H:i', $this->last_fetch);
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
 *              details         list of strings, any arbitrary "verbose debugging" output to include into the on-disk report
 *              success         integer, number of events successfully loaded into the database
 *              malformed       integer, number of events not imported due to some malformation (empty title, no location, ...)
 *              badgeocode      integer, number of events which could not be geocoded
 *              nocategory      integer, number of events which could not be categorized
 **********************************************************************************************/

class EventDataSourceSuccessException extends Exception {
    public function __construct($messages=null,$extrainfo=array()) {
        // no messages? no way
        if (! $messages) throw new Exception("Failed to throw EventDataSourceSuccessException: no messages given");
        $this->messages = $messages;

        // go ahead and construct a regular Exception
        $message = implode("\n", $this->messages);
        $code = 0;
        parent::__construct($message,$code);

        // then add the $extrainfo   driver-specific content, but useful for any driver-specific callers
        $this->extrainfo = $extrainfo;
    }
}

class EventDataSourceErrorException extends Exception {
    public function __construct($messages=null,$extrainfo=array()) {
        // no messages? no way
        if (! $messages) throw new Exception("Failed to throw EventDataSourceErrorException: no messages given");
        $this->messages = $messages;

        // go ahead and construct a regular Exception
        $message = implode("\n", $this->messages);
        $code = 0;
        parent::__construct($message,$code);

        // then add the $extrainfo   driver-specific content, but useful for any driver-specific callers
        $this->extrainfo = $extrainfo;
    }
}
