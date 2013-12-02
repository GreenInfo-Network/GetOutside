<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceDataSource_ArcGISREST extends PlaceDataSource {

var $table            = 'placedatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('place',);

var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"REST URL with Layer ID", 'help'=>"The URL of the REST endpoint, including the layer ID.<br/>Example: http://your.server.com/arcgis/rest/services/Places/Minneapolis/MapServer/5<br/>NOTE: Only layers of type <i>esriGeometryPoint</i> are supported."),
    'option1' => array('required'=>TRUE, 'isfield'=>TRUE, 'name'=>"Name/Title Field", 'help'=>"Which field contains the name/title for these locations?"),
    'option2' => array('required'=>TRUE, 'isfield'=>TRUE, 'name'=>"Description Field", 'help'=>"Which field contains the description for these locations?"),
    'option3' => NULL,
    'option4' => NULL,
);


public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','ArcGIS REST API');
}


/**********************************************************************************************
 * INSTANCE METHODS
 **********************************************************************************************/

/*
 * reloadContent()
 * Connect to the data source and grab all the events listed, and save them as local Places.
 * By design this is destructive: all existing Places for this PlaceDataSource are deleted in favor of this comprehensive list of places.
 *
 * This method throws an Exception in any case, either a PlaceDataSourceErrorException or PlaceDataSourceSuccessException
 * This allows for more complex communication than a simple true/false return, e.g. the name of the data source and an error code (number of placed loaded/failed)
 */
public function reloadContent() {
    // make sure no shenanigans: ArcGIS REST services fit a pattern, and field names must be on the list
    $url = $this->url;
    if (! preg_match('!^http://[^\/]+/arcgis/rest/services/[\w\-\.]+/[\w\-\.]+/MapServer/\d+$!i',$url)) throw new PlaceDataSourceErrorException('That URL does not fit the format for a REST endpoint.');

    $namefield = $this->option1;
    $descfield = $this->option2;
    if (! preg_match('!^\w+$!', $namefield)) throw new PlaceDataSourceErrorException('Blank or invalid field: Name field');
    if (! preg_match('!^\w+$!', $descfield)) throw new PlaceDataSourceErrorException('Blank or invalid field: Description field');
    $fields = $this->listFields(TRUE);
    if (! @$fields[$namefield]) throw new PlaceDataSourceErrorException('Chosen Name field  does not exist in the ArcGIS service.');
    if (! @$fields[$descfield]) throw new PlaceDataSourceErrorException('Chosen Description field does not exist in the ArcGIS service.');

    // expand upon the base URL, adding parameters to make a query for expected JSON content
    $params = array(
        'where'          => '1>0',
        'outFields'      => implode(',',array('OBJECTID',$namefield,$descfield)),
        'returnGeometry' => 'true',
        'outSR'          => '4326',
        'f'              => 'pjson',
    );
    $url = sprintf("%s/query?%s", $url, http_build_query($params) );

    // try to fetch and decode it; check for some fields that should definitely be there: name, geometry type, and of course features
    $structure = @json_decode(file_get_contents($url));
    if (@$structure->error->message) throw new PlaceDataSourceErrorException("ArcGIS server gave an error: {$structure->error->message}\nCommon cause is that the name and/or description field is not entered correctly.");
    if (! @$structure->geometryType) throw new PlaceDataSourceErrorException('No data or invalid data received from server. No geometryType found.');
    if ($structure->geometryType != 'esriGeometryPoint') throw new PlaceDataSourceErrorException("Layer data type is {$structure->geometryType}, but only esriGeometryPoint is supported.");
    if (! sizeof(@$structure->features)) throw new PlaceDataSourceErrorException("ArcGIS server contacted, but no features were found.");

    // one last thing: the REST service accepts case-insensitive field names, e.g. ObjectID, but always returns them in proper case, e.g. OBJECTID
    // look over the "fields" substructure and standardize $namefield and $descfield to match whatever the server gave back
    foreach ($structure->fields as $field) {
        if ( strcasecmp($field->name,$namefield) == 0) $namefield = $field->name;
        if ( strcasecmp($field->name,$descfield) == 0) $descfield = $field->name;
    }

    // great; clear out existing Places from the database, so we can load the new ones
    foreach ($this->place as $old) $old->delete();

    // load 'em up!
    $success = 0;
    foreach ($structure->features as $feature) {
        $name        = $feature->attributes->{$namefield}; if (! $name) $name= ' ';
        $description = $feature->attributes->{$descfield}; if (! $description) $description = '';

        $place = new Place();
        $place->placedatasource_id  = $this->id;
        $place->remoteid            = (integer) $feature->attributes->OBJECTID;
        $place->name                = substr($name,0,50);
        $place->description         = $description;
        $place->latitude            = (float) $feature->geometry->y;
        $place->longitude           = (float) $feature->geometry->x;
        $place->save();
        $success++;
    }

    // success! update our last_fetch date then throw an exception
    $this->last_fetch = time();
    $this->save();
    throw new PlaceDataSourceSuccessException("Loaded $success points.");
}



/*
 * listFields()
 * Connect to the data source and grab a list of field names. Return an array of string field names.
 */
public function listFields($assoc=FALSE) {
    // make sure no shenanigans: ArcGIS REST services fit a pattern
    $url = $this->url;
    if (! preg_match('!^http://[^\/]+/arcgis/rest/services/[\w\-\.]+/[\w\-\.]+/MapServer/\d+$!i',$url)) throw new PlaceDataSourceErrorException('That URL does not fit the format for a REST endpoint.');

    // the base URL plus only one param asking for JSON output
    $params = array(
        'f' => 'json',
    );
    $url = sprintf("%s?%s", $url, http_build_query($params) );

    // fetch it, see if it looks right
    $fields = @json_decode(file_get_contents($url));
    if (! @is_array($fields->fields) or ! @sizeof($fields->fields)) throw new PlaceDataSourceErrorException('Did not get a field list back for this data source.');

    // generate the output, either as a straight list or an assocarray
    // assocs are for CodeIgniter or other "dropdown generators" which expect value=>label mappings
    $output = array();
    if ($assoc) {
        foreach ($fields->fields as $f) $output[$f->name] = $f->name;
        ksort($output);
    } else {
        foreach ($fields->fields as $f) $output[] = $f->name;
        sort($output);
    }
    return $output;
}



/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
