<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceDataSource_WFS extends PlaceDataSource {

var $table            = 'placedatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array(
                                'place' => array(    // explicitly state the name of this class, cuz the "inflection helper" trims off the S at the end.  sigh
                                    'join_other_as' => 'placedatasource_wfs',
                                    'other_field' => 'placedatasource',
                                    'join_table' => 'placedatasources'
                                )
                            );

var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"Service URL", 'help'=>"The URL of the WFS endpoint.<br/>Example: http://your.server.com/geoserver/wfs<br/>NOTE: Only Point and Polygon layers are supported."),
    'option1' => array('required'=>TRUE, 'isfield'=>FALSE, 'name'=>"WFS Layer", 'help'=>"The WFS layer (aka FeatureType), e.g. <i>parks</i> or <i>websvcs:parks</i> Check the WFS server's GetCapabilities document for info."),
    'option2' => array('required'=>TRUE, 'isfield'=>TRUE, 'name'=>"Name/Title Field", 'help'=>"Which field contains the name/title for these locations?"),
    'option3' => array('required'=>FALSE, 'isfield'=>TRUE, 'name'=>"Description Field", 'help'=>"Which field contains the description for these locations?"),
    'option4' => array('required'=>FALSE, 'isfield'=>FALSE, 'name'=>"Additional URL Parameters", 'help'=>"Additional URL params to be included in the WFS request, in standard URL syntax. Example: <i>&srsName=EPSG:4326</i> to output the content in WGS84 coordinates, or <i>BBOX=-95.7,42.2,-92.6,43.5</i> to apply a spatial filter."),
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
    $this->where('type','OGC WFS');
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
    // any URL allowed and no hard standard on the term "wfs" being in the URL, so we can't do a lot of validation beyond it being HTTP/HTTPS
    $url = $this->url;
    if (! preg_match('!^https?://[\w\-\.]+?/!i',$url)) throw new PlaceDataSourceErrorException( array('Check the WFS service URL. Only HTTP and HTTPS URLs are supported.') );

    // layer names must be \w and : for namespaces
    $layer = $this->option1;
    if (! $layer ) throw new PlaceDataSourceErrorException( array('No WFS layer specified.') );
    if (! preg_match('/^[\w\-\:\.]+$/', $layer) ) throw new PlaceDataSourceErrorException( array('Invalid layer name: invalid characters. Only letters and numbers and : are allowed.') );

    // name field (required) and description field (optionsl) should be simple alphanumeric, then make sure they're on the list
    $namefield = $this->option2;
    $descfield = $this->option3;
    if (! preg_match('!^\w+$!', $namefield)) throw new PlaceDataSourceErrorException( array('Blank or invalid field: Name field') );
    if ($descfield and ! preg_match('!^\w+$!', $descfield)) throw new PlaceDataSourceErrorException( array('Blank or invalid field: Description field') );
    $fields = $this->listFields();
    if (!$namefield or !in_array($namefield,$fields)) throw new PlaceDataSourceErrorException( array("Chosen Name field ($namefield) does not exist in this FeatureType.") );
    if ($descfield and !in_array($descfield,$fields)) throw new PlaceDataSourceErrorException( array("Chosen Description field ($descfield) does not exist in this FeatureType.") );

    // Other URL Params isn't something we can sanitize, really   Just arbitrary text to add to the URL string
    $moreparams = $this->option4;

    // compose the URL
    // go ahead and use GET here since queries tend to be short (nowhere near 4k or 8k limits) and not all WFS services support POST

    // compose the URL for a GetFeature call, which fetches all attributes for all features
    // return will be XML, and hooray for SimpleXML
    $params = array(
        'SERVICE'   => 'WFS',
        'VERSION'   => '1.1.0',
        'REQUEST'   => 'GetFeature',
        'OUTPUTFORMAT'   => 'GML2',
        'TYPENAME'  => $layer,
    );
    $url = sprintf("%s?%s&%s", $url, http_build_query($params), $moreparams );
    //throw new PlaceDataSourceErrorException( array($url) );

    // despite hours of StackOverflow and reading PHP bug reports, the XML parser just isn't coping with real-world GML output and namespaces
    // so we strip 'em out and treat it like normal XML
    $xml = @file_get_contents($url);
    $xml = str_replace('<gml:', '<', $xml);
    $xml = str_replace('</gml:', '</', $xml);
    $xml = str_replace('<wfs:', '<', $xml);
    $xml = str_replace('</wfs:', '</', $xml);
    $xml = str_replace('<ms:', '<', $xml);
    $xml = str_replace('</ms:', '</', $xml);
    $xml = @simplexml_load_string($xml);

    // did we get any features? great, we're ready to rock! no, then we're ready to bail!
    $features = @$xml->featureMember;
    if (! sizeof($features) ) throw new PlaceDataSourceErrorException( array('Got back no features.') );

    // start the verbose output
    $details = array();
    $details[] = "Loaded $url";

    // deletions prep work: a list of all Remote-ID currently in the database within this data source
    // as we go over the records we'll remove them from this list
    // anything still remaining at the end of this process, is no longer in the remote data source and therefore should be deleted from the local database to match
    $howmany_old = $this->place->count();
    $details[] = sprintf("Cataloging %d old Place entries for possible removal", $howmany_old );
    $deletions = array();
    foreach ($this->place as $old) $deletions[$old->remoteid] = $old->id;

    // go over the returned Features
    $records_new     = 0;
    $records_updated = 0;
    $warn_noname     = 0;
    $warn_badcoords  = 0;
    $warn_nounique   = 0;

    foreach ($features as $feature) {
        // fetch these first; the coming attribute thrashing effectively destroys the ability to do xpath on the element; don't know why...
        $point_coords = $feature->xpath('.//Point/coordinates');
        $poly_coords  = $feature->xpath('.//LinearRing/coordinates');

        // each xml member has one child, a <tag> named the same as the layer name  (why, OGC, why?)
        // fetch the attributes of the record, trim off the @attributes and any other complex attributes
        $realfeat = $feature->{$layer};
        $attributes = get_object_vars($realfeat);
        unset($attributes['@attributes']);
        foreach (array_keys($attributes) as $k) {
            if (gettype($attributes[$k]) == 'object') unset($attributes[$k]);
        }
        //error_log( print_r($attributes,TRUE) );

        // for the unique ID, aka remoteid use the FID, if this WFS supplies one as an attribute
        // otherwise try to use "id" and "gid" elements; if we still can't find it, use the name as a last ditch (and likely not unique)
        $remoteid = (string) @$realfeat['fid'];
        if (! $remoteid) $remoteid = @$attributes['gid'];
        if (! $remoteid) $remoteid = @$attributes['id'];
        if (! $remoteid) {
            $remoteid = sha1(mt_rand() . microtime());
            $warn_nounique++;
        }

        // the simple attributes: name and description
        $name     = @$attributes[$namefield];
        $desc     = $descfield ? @$attributes[$descfield] : '';
        if (! $name) { $name= ''; $warn_noname++; $details[] = "Record $remoteid lacks a name"; }
        if (! $desc) { $desc= ''; }

        // geometry may be a gml:LinearRing (polygon, multipolygon) or a gml:Point (point) but they both have "gml:coordinates" so we lock on to that
        // hand off to handlers to find the centroid if it's a polygon, or to extract the coordinates if it's a point
        if (sizeof($point_coords)) {
            $lonlat = $this->extractGeometryFromPoint((string) $point_coords[0]);
        } else if (sizeof($poly_coords)) {
            // yeah we use only the first ring; moral of the story is not to use polygons when you intend points  :)
            $lonlat = $this->extractGeometryFromPolygon((string) $poly_coords[0]);
        } else {
            $lonlat = NULL;
        }
        //error_log("LonLat: " . print_r($lonlat,TRUE) );

        // check for obviously bad coordinates, or none at all
        if (!$lonlat) {
            $warn_badcoords++;
            $details[] = "Record $remoteid has no coordinate information.";
            continue;
        }
        $lat = $lonlat[0];
        $lon = $lonlat[1];
        if (!$lon or !$lat or $lat>90 or $lat<-90 or $lon<-180 or $lon>180) {
            $warn_badcoords++;
            $details[] = "Record $remoteid coordinates do not look right: $lat $lon";
            continue;
        }

        // guess we're golden: a real centroid, and all the required fields
        // either update the existing Place or create a new one
        $place = new Place();
        $place->where('placedatasource_id',$this->id)->where('remoteid',$remoteid)->get();
        if ($place->id) {
            // update of an existing Place; remove this record from the "to be deleted cuz it's not in the remote source" list
            unset($deletions[$remoteid]);

            $details[] = "Updating record {$remoteid}";
            $records_updated++;
        } else {
            // a new Place; set the DSID, and also Remote ID so we can identify it on future runs
            $place->placedatasource_id  = $this->id;
            $place->remoteid            = $remoteid;

            $details[] = "Creating new record {$remoteid} -- $name";
            $records_new++;
        }
        $place->name             = substr($name,0,50);
        $place->description      = $desc;
        $place->latitude         = $lat;
        $place->longitude        = $lon;
        $place->attributes_json  = json_encode($attributes);
        $place->save();

        // done with this feature
    }

    // deletions
    // delete any "leftover" records in $deletions
    // any entry left behind in $deletions is no longer in the source, so we shouldn't have it either
    if (sizeof($deletions)) {
        // do the delete...
        $delete = new Place();
        $delete->where('placedatasource_id',$this->id)->where_in('id', array_values($deletions) )->get();
        foreach ($delete as $d) {
            $d->delete();
            $details[] = "Deleting outdated record: {$d->remoteid} : $d->name";
        }

        // then make $deletions simply the number of records deleted
        $deletions = sizeof($deletions);
    } else {
        $deletions = false;
    }

    // save our last update and throw a success exception
    // success! update our last_fetch date then throw an exception
    $this->last_fetch = time();
    $this->save();
    $messages = array();
    $messages[] = "$records_new new locations added to database.";
    $messages[] = "$records_updated locations updated.";
    if ($deletions)         $messages[] = "$deletions outdated locations deleted.";
    if ($warn_nounique)     $messages[] = "$warn_nounique places had no unique ID field (tried fid, gid, and id) so random IDs were assigned.";
    if ($warn_noname)       $messages[] = "$warn_noname places had a blank name.";
    if ($warn_badcoords)    $messages[] = "$warn_badcoords skipped due to invalid location.";
    $info = array(
        'added'   => $records_new,
        'updated' => $records_updated,
        'deleted' => $deletions,
        'nogeom'  => $warn_badcoords,
        'details' => $details,
    );
    throw new PlaceDataSourceSuccessException($messages,$info);
}



/*
 * listFields()
 * Connect to the data source and grab a list of field names. Return an array of string field names.
 */
public function listFields() {
    // any URL allowed and no hard standard on the term "wfs" being in the URL, so we can't do a lot of validation beyond it being HTTP/HTTPS
    $url = $this->url;
    if (! preg_match('!^https?://[\w\-\.]+?/!i',$url)) throw new PlaceDataSourceErrorException( array('Check the WFS service URL. Only HTTP and HTTPS URLs are supported.') );

    // layer names must be \w and : for namespaces
    $layer = $this->option1;
    if (! $layer ) throw new PlaceDataSourceErrorException( array('No WFS layer specified.') );
    if (! preg_match('/^[\w\-\:\.]+$/', $layer) ) throw new PlaceDataSourceErrorException( array('Invalid layer name: invalid characters. Only letters and numbers and : are allowed.') );

    // Other URL Params isn't something we can sanitize, really   Just arbitrary text to add to the URL string
    $moreparams = $this->option4;

    // make a DescribeFeatureType call, which gives the list of fields
    // return will be XML, and hooray for SimpleXML
    $params = array(
        'SERVICE'   => 'WFS',
        'VERSION'   => '1.1.0',
        'REQUEST'   => 'DescribeFeatureType',
        'TYPENAME'  => $layer,
    );
    $url = sprintf("%s?%s&%s", $url, http_build_query($params), $moreparams );
    //throw new PlaceDataSourceErrorException( array($url) );

    $xml = @file_get_contents($url);
    $xml = @simplexml_load_string($xml);
    if (! $xml) throw new PlaceDataSourceErrorException( array("Did not get an XML response from this data source. $url") );

    // go over the xsd:element tags and that's our field list
    // if it's not there, then we must have gotten non-XML, an error, or something other than good
    $elements = @$xml->xpath('//xsd:element');
    if (! $elements) throw new PlaceDataSourceErrorException( array("Did not get a field list back for this data source. Check the layer name and WFS service URL. $url") );

    $fields = array();
    foreach ($elements as $element) {
        $fields[] = (string) $element['name'];
    }
    natcasesort($fields);

    // that's it!
    return $fields;
}


// WFS suports all major geometry types, but we need a point
// thus, handlers to extract the point coords from a Point, or to assemble a polygon and then figure its centroid so we still get a point

function extractGeometryFromPoint($point) {
    // the point content is fairly straightforward:   lon,lat   maybe extra spacing and maybe a Z
    $coords = $point;
    $coords = preg_replace('/\s/', '', $coords); // super-trim!
    if (! $coords) return NULL;
    $coords = explode(',', $coords);
    if (sizeof($coords) < 2) return NULL;

    // take only first 2 elements, in case there's a 3rd element for Z
    // and force them to be numeric
    // tip: WFS 1.1.0 is lat,lon and not lon,lat
    $coords = array( (float) $coords[0] , (float) $coords[1] );

    // that was it! let the caller decide whether the coordinate is valid in whatever SRS
    return $coords;
}

function extractGeometryFromPolygon($ring) {
    $vertices = $this->extractGMLRingToVertices($ring);
    $centroid = $this->getCentroidOfPolygon($vertices);
    //error_log("Ring:" .  print_r($ring,TRUE) );
    //error_log("Centroid:" .  print_r($centroid,TRUE) );
    return $centroid;
}

function extractGMLRingToVertices($ring) {
    // trim extra whitespace, then split on whitespace to get vertices
    // split vertices on comma to get X and Y ordinates
    // explicitly cast to float "just in case"
    $vertices = array();
    $vx = preg_split('/\s+/', trim($ring) );
    foreach ($vx as $v) { $xy = explode(',',$v); $vertices[] = array( (float) $xy[0], (float) $xy[1]); } // don't assume there're only 2 ordinates; it may have Z that we don't want
    return $vertices;
}

// calculate the centroid of a single-ring polygon, as given by $vertices
// rings don't overlap, non-self-intersecting and no donuts holes
// the design goal of this project, is not requiring GEOS or PostGIS so we gotta keep it simple
public function getCentroidOfPolygon($vertices) {
    // first off get the area: it may come back 0 due to rounding in the WFS server  (rounding to 2 decimals, in degrees? ouch)
    // in which case we must confess ignorance
    $area = $this->getAreaOfPolygon($vertices);
    if (! $area) return NULL;

    // these will be the X and Y ordinates of the centroid
    $cx = 0;
    $cy = 0;

    for ($vi=0, $vl=sizeof($vertices); $vi<$vl; $vi++) {
        $thisx = $vertices[ $vi ][0];
        $thisy = $vertices[ $vi ][1];
        $nextx = $vertices[ ($vi+1) % $vl ][0];
        $nexty = $vertices[ ($vi+1) % $vl ][1];

        $p = ($thisx * $nexty) - ($thisy * $nextx);
        $cx += ($thisx + $nextx) * $p;
        $cy += ($thisy + $nexty) * $p;
    }

    // last step of centroid: divide by 6*A
    $cx = -$cx / ( 6 * $area);
    $cy = -$cy / ( 6 * $area);

    // done!
    return array($cx,$cy);
}

// calculate the area of a single-ring polygon, with some assumptions: not self-intersecting, convex and no donut holes, and that multiple rings don't overlap
// this should closely fit the use case for realistic areas for park boundaries and the like
// the design goal of this project, is not requiring GEOS or PostGIS
public function getAreaOfPolygon($vertices) {
    $area = 0;

    for ($vi=0, $vl=sizeof($vertices); $vi<$vl; $vi++) {
        $thisx = $vertices[ $vi ][0];
        $thisy = $vertices[ $vi ][1];
        $nextx = $vertices[ ($vi+1) % $vl ][0];
        $nexty = $vertices[ ($vi+1) % $vl ][1];
        $area += ($thisx * $nexty) - ($thisy * $nextx);
    }

    // done with the rings: "sign" the area and return it
    $area = abs(($area / 2));
    return $area;
}



/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
