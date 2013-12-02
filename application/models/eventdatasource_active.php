<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource_Active extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

var $option_fields = array(
    'url'     => NULL,
    'option1' => array('required'=>TRUE, 'name'=>"API Key", 'help'=>"Your Active.com API key for the Event Search API v2"),
    'option2' => array('required'=>FALSE, 'name'=>"Org ID", 'help'=>"Optional: Enter your Organization GUID to filter the listings, showing only your events from Active.com. To find your Organization GUID, contact Active.com technical support."),
    'option3' => NULL,
    'option4' => NULL,
);

public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','Active.com API');
}



/**********************************************************************************************
 * INSTANCE METHODS
 **********************************************************************************************/

/*
 * reloadContent()
 * Connect to the data source and grab all the events listed, and save them as local Events.
 * By design this is destructive: all existing Events for this EventDataSource are deleted in favof of these new ones.
 */
public function reloadContent() {
    // no API key, that's a problem; even if it's given we check below anyway cuz it may be revoked, expired, or just plain wrong
    $apikey = $this->option1;
    $orgid  = $this->option2;
    if (! preg_match('/^\w+$/', $apikey)) throw new EventDataSourceErrorException('Active.com Event Search API v2 requires an API key.');
    if ($orgid and !preg_match('/^[\w\-]+$/', $orgid)) throw new EventDataSourceErrorException('The specified Organization ID is not valid.');

    // make up the API call: events starting between today and 6 months into the future
    // note that the &bbpx= param does not work (Nov 2013) and we use &lat_lon= and &radius= instead
    $month = (integer) date('m');
    $date  = (integer) date('d');
    $year  = (integer) date('Y');
    $now   = date('Y-m-d', mktime(0, 0, 0, $month, $date, $year) );
    $then  = date('Y-m-d', mktime(0, 0, 0, $month+6, $date, $year) );

    $params = array();
    $lat = 0.5 * ( $this->siteconfig->get('bbox_s') + $this->siteconfig->get('bbox_n') );
    $lon = 0.5 * ( $this->siteconfig->get('bbox_w') + $this->siteconfig->get('bbox_e') );
    $params['lat_lon']      = sprintf("%.5f,%.5f", $lat, $lon );
    $params['radius']       = 40;
    $params['start_date']   = sprintf('%s..%s', $now, $then);
    $params['end_date']     = sprintf('%s..%s', $now, $then); // specify both, or else it includes events 6 years in the future
    $params['per_page']     = 100;
    $params['sort']         = 'date_asc';
    $params['category']     = 'event';
    if ($orgid) $params['org_id'] = $orgid;
    $url = sprintf('http://api.amp.active.com/v2/search?api_key=%s&%s', $apikey, http_build_query($params) );

    // make the request, get some JSON
    $content = @json_decode(@file_get_contents($url));
    if (!$content or !$content->results) throw new EventDataSourceSuccessException("No results. Check that this API key is active for the Activity Search API v2");

    // guess we're good! delete the existing ones...
    foreach ($this->event as $old) $old->delete();

    // ... then load the new ones
    $success = 0;
    $failed  = 0;
    foreach ($content->results as $entry) {
        // find an URL or else give up
        $url = @$entry->assetLegacyData->seoUrl; if (! $url) $url = @$entry->registrationUrlAdr;

        $event = new Event();
        $event->eventdatasource_id  = $this->id;
        $event->remoteid            = $entry->assetGuid;
        $event->starts              = strtotime($entry->activityStartDate); // Unix timestamp
        $event->ends                = strtotime($entry->activityEndDate); // Unix timestamp
        $event->name                = substr($entry->assetName,0,50);
        $event->url                 = $url;
        $event->description         = $entry->assetDescriptions[0]->description;

        // name and URL are required
        if (!$url or !$event->name) { $failed++; continue; }

        $event->save();
        $success++;
    }

    // update our last_fetch date
    $this->last_fetch = time();
    $this->save();

    // guess we're done and happy; throw an error  (ha ha)
    $message = "Successfully loaded $success events.";
    if ($failed) $message .= " Failed to load $failed events due to missing date.";
    throw new EventDataSourceSuccessException($message);
}


/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
