<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceDataSource_ArcGISREST extends PlaceDataSource {

var $table            = 'placedatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('place',);

var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"REST URL with Layer ID", 'help'=>"The URL of the REST endpoint, including the layer ID.<br/>Example: http://your.server.com/arcgis/rest/services/Places/Minneapolis/MapServer/5<br/>NOTE: Only Point and Polygon layers are supported."),
    'option1' => array('required'=>TRUE, 'isfield'=>TRUE, 'name'=>"Name/Title Field", 'help'=>"Which field contains the name/title for these locations?"),
    'option2' => array('required'=>FALSE, 'isfield'=>TRUE, 'name'=>"Description Field", 'help'=>"Which field contains the description for these locations?"),
    'option3' => array('required'=>FALSE, 'isfield'=>FALSE, 'name'=>"Filter Clause", 'help'=>"A filter clause using standard ArcGIS REST syntax, e.g. <i>STATE_FID=16</i> or OPENPUBLIC='Yes'<br/>This is used to filter the features, e.g. to remove those that are closed or non-public, or to narrow down results if only a few features are relevant."),
    'option4' => NULL,
    'option5' => NULL,
    'option6' => NULL,
    'option7' => NULL,
    'option8' => NULL,
    'option9' => NULL,
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
    if (! preg_match('!^https?://[^\/]+/arcgis/rest/services/[\w\-\.]+/[\w\-\.]+/MapServer/\d+$!i',$url)) throw new PlaceDataSourceErrorException('That URL does not fit the format for a REST endpoint.');

    $namefield = $this->option1;
    $descfield = $this->option2;
    if (! preg_match('!^\w+$!', $namefield)) throw new PlaceDataSourceErrorException('Blank or invalid field: Name field');
    if ($descfield and ! preg_match('!^\w+$!', $descfield)) throw new PlaceDataSourceErrorException('Blank or invalid field: Description field');
    $fields = $this->listFields();
    if (!$namefield or !in_array($namefield,$fields)) throw new PlaceDataSourceErrorException("Chosen Name field ($namefield) does not exist in the ArcGIS service.");
    if ($descfield and !in_array($descfield,$fields)) throw new PlaceDataSourceErrorException("Chosen Description field ($descfield) does not exist in the ArcGIS service.");

    // the filter clause; kinda free-form here, and high potential for them to mess it up
    // that's why we're so thorough on catching possible exceptions such as missing field names
    $filterclause = $this->option3;
    if (! $filterclause) $filterclause = "1>0";

    // expand upon the base URL, adding parameters to make a query for expected JSON content
    // grab ALL fields, not simply the 2 we care about; we need to do categorization so we likely need fields beyond those specified
    $params = array(
        'where'          => $filterclause,
        //'outFields'      => implode(',',array('OBJECTID',$namefield,$descfield)),
        'outFields'      => implode(',',$fields),
        'returnGeometry' => 'true',
        'outSR'          => '4326',
        'f'              => 'pjson',
    );
    $url = sprintf("%s/query?%s", $url, http_build_query($params) );

    // try to fetch and decode it; check for some fields that should definitely be there: name, geometry type, and of course features
    $structure = @json_decode(file_get_contents($url));
    if (@$structure->error->message) throw new PlaceDataSourceErrorException("ArcGIS server gave an error: {$structure->error->message}\nCommon cause is that a name or description field, or a filter, is not entered correctly.");
    if (! @$structure->geometryType) throw new PlaceDataSourceErrorException('No data or invalid data received from server. No geometryType found.');
    if (! sizeof(@$structure->features)) throw new PlaceDataSourceErrorException("ArcGIS server contacted, but no features were found.");

    // we need points, so we need to come up with a mangling method based on the actual data type
    switch ($structure->geometryType) {
        case 'esriGeometryPoint':
            $warn_geom      = null;
            $geom_extractor = 'extractPointFromPoint';
            break;
        case 'esriGeometryPolygon':
            $warn_geom      = "Note: Layer is polygon data, and points were approximated.";
            $geom_extractor = 'extractPointFromPolygon';
            break;
        default:
            throw new PlaceDataSourceErrorException("Layer data type is {$structure->geometryType}, which is not a supported geometry type.");
    }

    // one last thing: the REST service accepts case-insensitive field names, e.g. ObjectID, but always returns them in proper case, e.g. OBJECTID
    // look over the "fields" substructure and standardize $namefield and $descfield to match whatever the server gave back
    foreach ($structure->fields as $field) {
        if ( strcasecmp($field->name,$namefield) == 0) $namefield = $field->name;
        if ( strcasecmp($field->name,$descfield) == 0) $descfield = $field->name;
    }

    // compose a list of all Remote-ID currently in the database within this data source
    // as we go over the records we'll remove them from this list
    // anything still remaining at the end of this process, is no longer in the remote data source and therefore should be deleted from the local database to match
    $deletions = array();
    foreach ($this->place as $old) $deletions[$old->remoteid] = $old->id;

    // load 'em up!
    // iterate over the features in the web service output, figure out the name and unique ID, then either update or create the Place entry
    $records_new     = 0;
    $records_updated = 0;
    $warn_noname     = 0;
    $warn_nodesc     = 0;
    foreach ($structure->features as $feature) {
        $remoteid    = (integer) $feature->attributes->OBJECTID;
        $name        = $feature->attributes->{$namefield};
        $description = $descfield ? $feature->attributes->{$descfield} : '';
        if (! $name)        { $name= ' ';        $warn_noname++; }
        if (! $description) { $description = ''; $warn_nodesc++; }

        list($longitude,$latitude) = call_user_func_array(array($this, $geom_extractor), array($feature->geometry));

        // either update the existing Place or create a new one
        $place = new Place();
        $place->where('placedatasource_id',$this->id)->where('remoteid',$remoteid)->get();
        if ($place->id) {
            // update of an existing Place; remove this record from the "to be deleted cuz it's not in the remote source" list
            unset($deletions[$remoteid]);

            $records_updated++;
        } else {
            // a new Place; set the DSID, and also Remote ID so we can identify it on future runs
            $place->placedatasource_id  = $this->id;
            $place->remoteid            = $remoteid;

            $records_new++;
        }
        $place->name             = substr($name,0,50);
        $place->description      = $description;
        $place->latitude         = $latitude;
        $place->longitude        = $longitude;
        $place->attributes_json  = json_encode($feature->attributes);
        $place->save();
    }

    // delete any "leftover" records in $deletions
    // any entry left behind in $deletions is no longer in the source, so we shouldn't have it either
    if (sizeof($deletions)) {
        // do the delete...
        $delete = new Place();
        $delete->where('placedatasource_id',$this->id)->where_in('id', array_values($deletions) )->get();
        foreach ($delete as $d) $d->delete();
        // then make $deletions simply the number of records deleted
        $deletions = sizeof($deletions);
    } else {
        $deletions = false;
    }

    // success! update our last_fetch date then throw an exception
    $this->last_fetch = time();
    $this->save();
    $message = array();
    $message[] = "$records_new new locations added to database.";
    $message[] = "$records_updated locations updated.";
    if ($deletions)   $message[] = "$deletions outdated locations deleted.";
    if ($warn_noname) $message[] = "$warn_noname places had a blank name.";
    if ($warn_nodesc) $message[] = "$warn_nodesc places had a blank description.";
    if ($warn_geom)   $message[] = $warn_geom;
    $message = implode("\n",$message);
    throw new PlaceDataSourceSuccessException($message);
}



/*
 * listFields()
 * Connect to the data source and grab a list of field names. Return an array of string field names.
 */
public function listFields() {
    // make sure no shenanigans: ArcGIS REST services fit a pattern
    $url = $this->url;
    if (! preg_match('!^https?://[^\/]+/arcgis/rest/services/[\w\-\.]+/[\w\-\.]+/MapServer/\d+$!i',$url)) throw new PlaceDataSourceErrorException('That URL does not fit the format for a REST endpoint.');

    // the base URL plus only one param asking for JSON output
    $params = array(
        'f' => 'json',
    );
    $url = sprintf("%s?%s", $url, http_build_query($params) );

    // fetch it, see if it looks right
    $fields = @json_decode(file_get_contents($url));
    if (! @is_array($fields->fields) or ! @sizeof($fields->fields)) throw new PlaceDataSourceErrorException('Did not get a field list back for this data source.');

    // generate the output, a flat list
    // a prior version accepted an $assoc=TRUE param to generate assoc arrays, but this got into "what would the caller want?" guesswork, and is best left to the caller
    $output = array();
    foreach ($fields->fields as $f) $output[] = $f->name;
    sort($output);
    return $output;
}



/**********************************************************************************************
 * UTILITY FUNCTIONS FOR CONVERTING GEOMETRIES
 * we need points, but need to be able to massage lines and polygons into points
 **********************************************************************************************/

public function extractPointFromPoint($geometry) {
    // the geometry is a point, so Lat & Lon are dead simple
    $lon = $geometry->x;
    $lat = $geometry->y;
    return array($lon,$lat);
}

public function extractPointFromPolygon($geometry) {
    // geometry is a polygon or multipolygon, find the centroid
    $center = $this->getCentroidOfPolygon($geometry);
    return $center; // already a 2-tiple
}

// calculate the area of a polygon, with some assumptions: not self-intersecting, convex and no donut holes, and that multiple rings don't overlap
// this should closely fit the use case for realistic areas for park boundaries and the like
// the design goal of this project, is not requiring GEOS or PostGIS
public function getAreaOfPolygon($geometry) {
    $area = 0;
    for ($ri=0, $rl=sizeof($geometry->rings); $ri<$rl; $ri++) {
        $ring = $geometry->rings[$ri];

        for ($vi=0, $vl=sizeof($ring); $vi<$vl; $vi++) {
            $thisx = $ring[ $vi ][0];
            $thisy = $ring[ $vi ][1];
            $nextx = $ring[ ($vi+1) % $vl ][0];
            $nexty = $ring[ ($vi+1) % $vl ][1];
            $area += ($thisx * $nexty) - ($thisy * $nextx);
        }
    }

    // done with the rings: "sign" the area and return it
    $area = abs(($area / 2));
    return $area;
}

// calculate the centroid of a polygon, with the same assumptions as above for the area:
// rings don't overlap, non-self-intersecting and no donuts holes
// the design goal of this project, is not requiring GEOS or PostGIS
public function getCentroidOfPolygon($geometry) {
    $cx = 0;
    $cy = 0;

    for ($ri=0, $rl=sizeof($geometry->rings); $ri<$rl; $ri++) {
        $ring = $geometry->rings[$ri];

        for ($vi=0, $vl=sizeof($ring); $vi<$vl; $vi++) {
            $thisx = $ring[ $vi ][0];
            $thisy = $ring[ $vi ][1];
            $nextx = $ring[ ($vi+1) % $vl ][0];
            $nexty = $ring[ ($vi+1) % $vl ][1];

            $p = ($thisx * $nexty) - ($thisy * $nextx);
            $cx += ($thisx + $nextx) * $p;
            $cy += ($thisy + $nexty) * $p;
        }
    }

    // last step of centroid: divide by 6*A
    $area = $this->getAreaOfPolygon($geometry);
    $cx = -$cx / ( 6 * $area);
    $cy = -$cy / ( 6 * $area);

    // done!
    return array($cx,$cy);
}


} // end of Model
