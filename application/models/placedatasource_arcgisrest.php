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
    'option3' => array('required'=>FALSE, 'isfield'=>FALSE, 'name'=>"Filter Clause", 'help'=>"A filter clause using standard ArcGIS REST syntax, e.g. <i>STATE_FID=16</i> or <i>LocCategor='Water Fountain'</i>"),
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
    if (! preg_match('!^https?://[^\/]+/arcgis/rest/services/[\w\-\.]+/[\w\-\.]+/MapServer/\d+$!i',$url)) throw new PlaceDataSourceErrorException('That URL does not fit the format for a REST endpoint.');

    $namefield = $this->option1;
    $descfield = $this->option2;
    if (! preg_match('!^\w+$!', $namefield)) throw new PlaceDataSourceErrorException('Blank or invalid field: Name field');
    if (! preg_match('!^\w+$!', $descfield)) throw new PlaceDataSourceErrorException('Blank or invalid field: Description field');
    $fields = $this->listFields(TRUE);
    if (! @$fields[$namefield]) throw new PlaceDataSourceErrorException('Chosen Name field  does not exist in the ArcGIS service.');
    if (! @$fields[$descfield]) throw new PlaceDataSourceErrorException('Chosen Description field does not exist in the ArcGIS service.');

    // the filter clause; kinda free-form here, and high potential for them to mess it up
    $filterclause = $this->option3;
    if (! $filterclause) $filterclause = "1>0";

    // expand upon the base URL, adding parameters to make a query for expected JSON content
    $params = array(
        'where'          => $filterclause,
        'outFields'      => implode(',',array('OBJECTID',$namefield,$descfield)),
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

    // great; clear out existing Places from the database, so we can load the new ones
    foreach ($this->place as $old) $old->delete();

    // load 'em up!
    $success = 0;
    $warn_noname = 0;
    $warn_nodesc = 0;
    foreach ($structure->features as $feature) {
        $name        = $feature->attributes->{$namefield};
        $description = $feature->attributes->{$descfield};
        if (! $name)        { $name= ' ';        $warn_noname++; }
        if (! $description) { $description = ''; $warn_nodesc++; }

        list($longitude,$latitude) = call_user_func_array(array($this, $geom_extractor), array($feature->geometry));

        $place = new Place();
        $place->placedatasource_id  = $this->id;
        $place->remoteid            = (integer) $feature->attributes->OBJECTID;
        $place->name                = substr($name,0,50);
        $place->description         = $description;
        $place->latitude            = $latitude;
        $place->longitude           = $longitude;
        $place->save();
        $success++;
    }

    // success! update our last_fetch date then throw an exception
    $this->last_fetch = time();
    $this->save();
    $message = array("Loaded $success locations.",);
    if ($warn_noname) $message[] = "$warn_noname had a blank name.";
    if ($warn_nodesc) $message[] = "$warn_nodesc had a blank description.";
    if ($warn_geom)   $message[] = $warn_geom;
    $message = implode("\n",$message);
    throw new PlaceDataSourceSuccessException($message);
}



/*
 * listFields()
 * Connect to the data source and grab a list of field names. Return an array of string field names.
 */
public function listFields($assoc=FALSE) {
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
