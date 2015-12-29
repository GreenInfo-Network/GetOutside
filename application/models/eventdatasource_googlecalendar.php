<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class EventDataSource_GoogleCalendar extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

var $option_fields = array(
    // the URL is for the email address but it's not in fact used; we use all those other fields instead to construct an OAuth2 service call
    // but since we kinda need the field, best use for it is to note who they would log in with to manage the account, since the API key strings won't make that clear
    'url'     => array('required'=>TRUE, 'name'=>"Google Account", 'help'=>"The email address of the Google Calendar you'll be loading, e.g. yourname@example.com<br/><br/>Step 1: Set up an API Service Account as follows:<ul><li>Use <a href=\"https://console.developers.google.com/start/api?id=calendar\" target=\"_blank\">Google's console</a> to create or select a project in the Google Developers Console and automatically turn on the API.</li><li>Select the Credentials tab. At the top of the page, under Credentials, select the OAuth consent screen tab. Select an Email address, enter &quot;My Calendar&quot; for the product name, leave the other fields blank, and click the Save button.</li><li>Select the Credentials tab. At the top of the page, under Credentials, click New Credentials and select Service Account. Create a new Service Account, enter a name for it and note the Service Account ID.</li><li>This will download to your computer, a file containing the pieces you will need for the rest of this process. Copy and paste the settings into each box.</li><li><b style=\"color:red;\">Google does not keep a copy of this file after you download it. Keep a copy in a safe place in case you need to refer to it later.</b></li></ul>Step 2: Use the Google Calendar website to share the calendar with the email address identified in the <b style=\"color:red;\">client_email</b> box below.<br/><br/>For more information on setting up API access, see <a href=\"https://support.google.com/cloud/answer/6158849?hl=en#serviceaccounts\" target=\"_blank\">Google's instructions on setting up OAuth2</a> and <a href=\"https://developers.google.com/api-client-library/php/auth/service-accounts\" target=\"_blank\">Google's Server-to-Server OAuth2 API documentation</a>."),
    'option1' => array('required'=>TRUE, 'name'=>"project_id",              'help'=>"Copy and paste from the downloaded authorization file from your Google console."),
    'option2' => array('required'=>TRUE, 'name'=>"private_key_id",          'help'=>"Copy and paste from the downloaded authorization file from your Google console."),
    'option3' => array('required'=>TRUE, 'name'=>"private_key",             'help'=>"Copy and paste from the downloaded authorization file from your Google console."),
    'option4' => array('required'=>TRUE, 'name'=>"client_email",            'help'=>"Copy and paste from the downloaded authorization file from your Google console.<br/><b style=\"color:red;\">Important: You must share the calendar with this email address.</b>"),
    'option5' => array('required'=>TRUE, 'name'=>"client_id",               'help'=>"Copy and paste from the downloaded authorization file from your Google console."),
    'option6' => NULL,
    'option7' => NULL,
    'option8' => array('required'=>FALSE, 'name'=>"Calendar ID", 'help'=>"The Calendar ID of the calendar to load.<br/>If you're not sure, leave this blank to use the Google Account's main calendar."),
    'option9' => NULL,
);

// hypothetically there's GeoRSS but that never really caught on, so we assume that events don't have a location
var $supports_location = FALSE;


public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','Google Calendar API');
}


/**********************************************************************************************
 * INSTANCE METHODS
 **********************************************************************************************/

/*
 * reloadContent()
 * Connect to the data source and grab all the events listed, and save them as local Events.
 * By design this is destructive: all existing Events for this EventDataSource are deleted in favor of this comprehensive list of events.
 *
 * This method throws an Exception in any case, either a EventDataSourceErrorException or EventDataSourceSuccessException
 * This allows for more complex communication than a simple true/false return, e.g. the name of the data source and an error code (number of events loaded)
 */
public function reloadContent() {
    // tease apart the config items, or die trying
// 
    $project_id             = $this->option1; if (! $project_id)     throw new EventDataSourceErrorException( array('All fields are required.') );
    $private_key_id         = $this->option2; if (! $private_key_id) throw new EventDataSourceErrorException( array('All fields are required.') );
    $private_key            = $this->option3; if (! $private_key)    throw new EventDataSourceErrorException( array('All fields are required.') );
    $client_email           = $this->option4; if (! $client_email)   throw new EventDataSourceErrorException( array('All fields are required.') );
    $client_id              = $this->option5; if (! $client_id)      throw new EventDataSourceErrorException( array('All fields are required.') );
    $calendar_id            = $this->option5; // the failure here is handled below, when we check that whatever they entered is in fact a real calendar

    $private_key = str_replace('\n', "\n", $private_key);

    // make the OAuth2 login call
    // authentication phase is different depending on whether Google Apps Engine is in use
    // this is a server-to-server call since we don't want to make the user open a Google window and login to the proper account, paste back a verification code, ...
    // https://developers.google.com/api-client-library/php/auth/service-accounts

    require_once 'libs/google-api-php-client/src/Google/autoload.php';

    try {
        $credentials = new Google_Auth_AssertionCredentials( $client_email, array(Google_Service_Calendar::CALENDAR_READONLY), $private_key ); // client email, scopes list, private key

        $client = new Google_Client();
        $client->setAssertionCredentials($credentials);
        if ($client->getAuth()->isAccessTokenExpired()) $client->getAuth()->refreshTokenWithAssertion();
    } catch (Google_Auth_Exception $e) {
        throw new EventDataSourceErrorException( array('The key could not be decoded. Check the fields, and check that these credentials are allowed access to the Google Calendar API, and try again.') );
    } catch (Exception $e) {
        throw new EventDataSourceErrorException( array('Unknown error accessing the Calendar API.') );
    }

    // connect to the Calendar service...
    $details = array();
    $calendar = new Google_Service_Calendar($client);
    if (! $calendar) throw new EventDataSourceErrorException( array('These credentials are not allowed access to the Google Calendar API. Check your credentials for the Calendar API for this project and try again.') );
    $details[] = "Successfully connected to Calendar API";

    // check the calendar list and make sure the one they asked for is on the list
    // if it is not, then bail with an error that will list what calendars do exist
    $calendar_id = $this->option8;
    if (! $calendar_id) $calendar_id = $this->url;

    $known_calendars = array();
    foreach ($calendar->calendarList->listCalendarList() as $knowncalendar) $known_calendars[] = $knowncalendar['id'];
    if (! in_array($calendar_id,$known_calendars)) throw new EventDataSourceErrorException(
        array(
            "Could not find the specified calendar: {$calendar_id}\n",
            "Maybe you spelled it incorrectly,\nor maybe is not shared to\n{$client_email}",
            "\n",
            "Available calendars are:",
            implode("\n",$known_calendars)
        )
    );

    // fetch future events
    // don't iterate over them yet; this makes sure they exist so we're clear to proceed
    $oneyear = time() + 86400 * 365;
    $params = array(
        'orderBy'      => 'startTime',          // sort the list; combined with maxResults means a zillion events will at least prefer the ones in the nearer future over those in the far future
        'singleEvents' => TRUE,                 // recurring events should be expanded into single entries
        'timeMin'      => date('c'),            // no events whose end time is in the past
        'timeMax'      => date('c', $oneyear),  // skip events which don't start within a year from today
        'maxResults'   => 2000,                 // should be plenty, just don't want to run afoul of someone with 1 million events crashing the thing
    );
    try {
        $events = $calendar->events->listEvents($calendar_id, $params);
    } catch (Google_Service_Exception $e) {
        throw new EventDataSourceErrorException( array('Could not fetch events for that calendar. Check the settings above and try again.') );
    }

    // clear to proceed
    // delete all of the old Events from this data source
    $howmany_old = $this->event->count();
    foreach ($this->event as $old) $old->delete();
    $details[] = "Clearing out: $howmany_old old Event records";

    // wait a second... to properly cope with the possibility of an All-Day event, which does require that we fabricate a timezone string,
    // look over events and find the first event that has a real time, and that's our target timezone   (well, presumably?)
    // if we don't find one, use the configured timezone from the database
    $timezone = new DateTimeZone( $this->siteconfig->get('timezone') );
    $timezone = ( $timezone->getOffset(new DateTime) / 3600 );
    $timezone = sprintf("%s%02d:%02d", $timezone > 0 ? '+' : '-', abs($timezone), 60 * ($timezone % 1) );
    $details[] = "System timezone: {$timezone}";

    foreach ($events->getItems() as $entry) {
        if (@$entry->start->timeZone) {
            $timezone = new DateTimeZone( $entry->start->timeZone );
            $timezone = ( $timezone->getOffset(new DateTime) / 3600 );
            $timezone = sprintf("%s%02d:%02d", $timezone > 0 ? '+' : '-', abs($timezone), 60 * ($timezone % 1) );

            $details[] = "Calendar timezone: {$timezone}";
            break;
        }
    }

    // load up the new Event records
    $success = 0;
    $failed  = 0;
    $nogeo   = 0;
    foreach ($events->getItems() as $entry) {
        // an event without a title is silly, don't do that
        if (! @$entry->summary) { $failed++; continue; }

        $event = new Event();
        $event->eventdatasource_id  = $this->id;
        $event->remoteid            = substr($entry->id, 0, 250);
        $event->name                = strip_tags(substr($entry->summary, 0, 100));
        $event->description         = @$entry->description ? $entry->description : '';
        $event->address             = @$entry->location ? $entry->location : '';
        $event->url                 = @$entry->htmlLink ? $entry->htmlLink : '';

        // Google Calendar has a concept of events being All-Day and represents dates slightly differrently when in use
        // we need a Unix timestamp for start and stop, even if that's 00:00:00 - 23:59:59
        if (! @$entry->start->dateTime or ! @$entry->end->dateTime) {
            // All Day, so fabricate the range 00:00:00 - 23:59:59 for today's start and stop times
            // AND ALSO tag it as an allday event
            $event->allday = 1;
            $event->starts = strtotime( sprintf("%sT00:00:00%s", $entry->start->date, $timezone) );
            $event->ends   = strtotime( sprintf("%sT23:59:59%s", $entry->end->date, $timezone) );
        } else {
            // it has a finite start and stop time, so just convert to Unix and we're done
            $event->starts = strtotime($entry->start->dateTime);
            $event->ends   = strtotime($entry->end->dateTime);
        }

        // now, figure out what weekdays intersect this event's duration; sat  sun  mon  ...
        // these are used to quickly search for "events on a Saturday"
        // since events can be days long, iterate from start time to end time in 86400-second increments
        $event->mon = $event->tue = $event->wed = $event->thu = $event->fri = $event->sat = $event->sun = 0;
        for ($thistime=$event->starts; $thistime<$event->ends; $thistime+=86400) {
            $wday = strtolower(date('D',$thistime));
            $event->{$wday} = 1;

            // tip: if all 7 days are a Yes by now, just skip the rest
            if ($event->mon and $event->tue and $event->wed and $event->thu and $event->fri and $event->sat and $event->sun) break;
        }

        //GDA TO-DO
        // attempt to geocode the Location if we have one
        // tip: cache the location strings onto lat-lng pairs, so we can skip geocoding the same address multiple times


        // we're done with this one!
        //$details[] = "Loaded OK: {$event->name}";
        $event->save();
        $success++;
    }

    // update our last_fetch date
    $this->last_fetch = time();
    $this->save();

    // guess we're done and happy; throw an error  (ha ha)
    $messages = array("Successfully loaded $success events.");
    if ($failed) $messages[] = " Failed to load $failed events due to missing date.";
    $info = array(
        'success'    => $success,
        'malformed'  => $failed,
        'badgeocode' => $nogeo,
        'nocategory' => 0,
        'details'    => $details
    );
    throw new EventDataSourceSuccessException($messages,$info);
}



/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
