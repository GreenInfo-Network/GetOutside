<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceDataSource_CartoDB extends PlaceDataSource {

var $table            = 'placedatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('place',);

var $option_fields = array(
    'url'     => NULL,
    'option1' => array('required'=>TRUE, 'isfield'=>FALSE, 'name'=>"CartoDB Username", 'help'=>"Your username at CartoDB."),
    'option2' => array('required'=>TRUE, 'isfield'=>FALSE, 'name'=>"CartoDB API Key", 'help'=>"The API key for your account at CartoDB. See your CartoDB account settings."),
    'option3' => array('required'=>TRUE, 'isfield'=>FALSE, 'name'=>"CartoDB Table Name", 'help'=>"The name of the CartoDB table."),
    'option4' => array('required'=>TRUE, 'isfield'=>TRUE, 'name'=>"Name/Title Field", 'help'=>"Which field contains the name/title for these locations?"),
    'option5' => array('required'=>FALSE, 'isfield'=>TRUE, 'name'=>"Description Field", 'help'=>"Which field contains the description for these locations?"),
    'option6' => array('required'=>FALSE, 'isfield'=>FALSE, 'name'=>"Filter Clause", 'help'=>"A WHERE clause using standard PostgreSQL or PostGIS syntax, e.g. <i>STATE_FID=16</i> or OPENPUBLIC='Yes'<br/>This is used to filter the features, e.g. to remove those that are closed or non-public, or to narrow down results if only a few features are relevant."),
    'option7' => array('required'=>FALSE, 'isfield'=>TRUE, 'name'=>"URL Field", 'help'=>"Which field contains a URL for more info about these locations?"),
    'option8' => NULL,
    'option9' => NULL,
);


public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','CartoDB');
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
    // make sure no shenanigans: usernames, table names, and API keys are all simple alphanumeric strings
    $username  = $this->option1;
    $apikey    = $this->option2;
    $tablename = $this->option3;
    if (! preg_match('/^\w+$/', $username) ) throw new PlaceDataSourceErrorException( array('Blank or invalid field: CartoDB Username') );
    if (! preg_match('/^\w+$/', $username) ) throw new PlaceDataSourceErrorException( array('Blank or invalid field: CartoDB API Key') );
    if (! preg_match('/^\w+$/', $username) ) throw new PlaceDataSourceErrorException( array('Blank or invalid field: CartoDB Table Name') );

    // check the selected name field and description field (if applicable) as also being simple alphanumeric, then make sure they're on the list
    $namefield = $this->option4;
    $descfield = $this->option5;
    $urlfield  = $this->option7;
    if (! preg_match('!^\w+$!', $namefield)) throw new PlaceDataSourceErrorException( array('Blank or invalid field: Name field') );
    if ($descfield and ! preg_match('!^\w+$!', $descfield)) throw new PlaceDataSourceErrorException( array('Blank or invalid field: Description field') );
    $fields = $this->listFields();
    if (!$namefield or !in_array($namefield,$fields)) throw new PlaceDataSourceErrorException( array("Chosen Name field ($namefield) does not exist in the CartoDB table.") );
    if ($descfield and !in_array($descfield,$fields)) throw new PlaceDataSourceErrorException( array("Chosen Description field ($descfield) does not exist in the CartoDB table.") );
    if ($urlfield  and !in_array($urlfield,$fields))  throw new PlaceDataSourceErrorException( array("Chosen URL field ($urlfield) does not exist in the CartoDB table.") );

    // the SQL clause, well, they can go bananas there, so no validation
    $whereclause = $this->option6;

    // done checking option fields

    // compose the POST query to fetch all rows
    // to optimize transfer volume, we leave out the geometry (we won't use it) and have CartoDB fetch the centroid (it may be lines or polygons)
    // why a POST? cuz we're fetching all fields except the specifically-ignored ones, plus a PostGIS function which makes the SQL even longer
    // remember, over 4k or so and we risk the remote server ignoring us for an over-length GET string
    $queryfields = $fields;
    $queryfields[] = "ST_AsText(ST_Centroid(the_geom)) AS the_geom";

    if ($whereclause) {
        $sql = sprintf("SELECT %s FROM %s WHERE %s", implode(',', $queryfields), $tablename, $whereclause );
    } else {
        $sql = sprintf("SELECT %s FROM %s", implode(',', $queryfields), $tablename );
    }
    $url  = sprintf("http://%s.cartodb.com/api/v2/sql", $username );
    $params = array(
        'api_key' => $apikey,
        'format'  => 'GeoJSON',
        'q'       => $sql,
    );

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, TRUE);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $params);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    $records = curl_exec($curl);
    curl_close($curl);

    $records = @json_decode($records);
    if (! $records) throw new PlaceDataSourceErrorException( array('CartoDB query failed: No content returned. Check your settings.') );
    if (@$records->error) throw new PlaceDataSourceErrorException( array('CartoDB query failed: No content returned. ' . $records->error[0] ) );
    if (! sizeof($records->features) ) throw new PlaceDataSourceErrorException( array('CartoDB query failed: Content returned but no records have geometry.') );

    // solid. we got records and they're in GeoJSON and the geometry is the centroid (in case the shapes are polygons)
    // processing can continue

    // start the verbose output
    $details = array();
    $details[] = "Loaded $url";

    // prep work: deletions
    // compose a list of all Remote-ID currently in the database within this data source
    // as we go over the records we'll remove them from this list
    // anything still remaining at the end of this process, is no longer in the remote data source and therefore should be deleted from the local database to match
    $howmany_old = $this->place->count();
    $details[] = sprintf("Cataloging %d old Place entries for possible removal", $howmany_old );
    $deletions = array();
    foreach ($this->place as $old) $deletions[$old->remoteid] = $old->id;

    // go ahead and load them, updating or inserting as we go, and keep a tally of any issues
    $records_new       = 0;
    $records_updated   = 0;
    $records_badcoords = 0;
    $records_noname    = 0;

    foreach ($records->features as $feature) {
        $remoteid = $feature->properties->cartodb_id;
        $name     = $feature->properties->{$namefield};
        $desc     = $descfield ? $feature->properties->{$descfield} : '';
        $url      = $urlfield  ? $feature->properties->{$urlfield}  : '';
        $lon      = (float) @$feature->geometry->coordinates[0];
        $lat      = (float) @$feature->geometry->coordinates[1];
        if ($url and substr($url,0,4) != 'http') $url = "http://$url";

        // no name? that's okay but increment the warning
        if (! $name) {
            $records_noname++;
            $name = '';
            $details[] = "Record $remoteid lacks a name";
        }
        // check for obviously bad coordinates
        if (!$lon or !$lat or $lat>90 or $lat<-90 or $lon<-180 or $lon>180) {
            $records_badcoords++;
            $details[] = "Record $remoteid coordinates do not look right: $lat $lon";
            continue;
        }

        // compile together all attributes; including and excluding those key ones targeted above
        // primarily used after the loading, for categorization based on attributes other than the ones captured above
        $attributes = get_object_vars($feature->properties);

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

        // save the rest of the fields, and save it to database
        $place->name             = substr($name,0,50);
        $place->description      = $desc;
        $place->latitude         = $lat;
        $place->longitude        = $lon;
        $place->url              = $url;
        $place->attributes_json  = json_encode($attributes);
        $place->save();

        // done with this Feature
    }

    // delete any "leftover" records in $deletions
    // any that are in the remote data source, would have been removed based on their 'remoteid' field
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

    // success! update our last_fetch date then throw an exception
    $this->last_fetch = time();
    $this->save();
    $messages = array();
    $messages[] = "$records_new new locations added to database.";
    $messages[] = "$records_updated locations updated.";
    if ($deletions)         $messages[] = "$deletions outdated locations deleted.";
    if ($records_noname)    $messages[] = "$records_noname places had a blank name.";
    if ($records_badcoords) $messages[] = "$records_badcoords places skipped due to bad coordinates.";
    $info = array(
        'added'   => $records_new,
        'updated' => $records_updated,
        'deleted' => $deletions,
        'nogeom'  => $records_badcoords,
        'details' => $details,
    );
    throw new PlaceDataSourceSuccessException($messages,$info);
}


/*
 * listFields()
 * Connect to the data source and grab a list of field names. Return an array of string field names.
 */
public function listFields() {
    // make sure no shenanigans: usernames, table names, and API keys are all simple alphanumeric strings
    $username  = $this->option1;
    $apikey    = $this->option2;
    $tablename = $this->option3;
    if (! $username ) throw new PlaceDataSourceErrorException( array('Start by entering your CartoDB Username, API Key, and Table Name') );
    if (! $apikey   ) throw new PlaceDataSourceErrorException( array('Start by entering your CartoDB Username, API Key, and Table Name') );
    if (! $tablename) throw new PlaceDataSourceErrorException( array('Start by entering your CartoDB Username, API Key, and Table Name') );
    if (! preg_match('/^\w+$/', $username ) ) throw new PlaceDataSourceErrorException( array('Blank or invalid field: CartoDB Username') );
    if (! preg_match('/^\w+$/', $apikey   ) ) throw new PlaceDataSourceErrorException( array('Blank or invalid field: CartoDB API Key') );
    if (! preg_match('/^\w+$/', $tablename) ) throw new PlaceDataSourceErrorException( array('Blank or invalid field: CartoDB Table Name') );

    // compose the URL to fetch 1 row; we don't care which, as long as we get its field listing
    // uncertain about failure mode: via browser a bad table name gives an object with an error attribute (array of errmsgs) but via file_get_contents() gets a Bad Request HTTP code
    $query   = sprintf("SELECT * FROM %s LIMIT 1", $tablename );
    $url     = sprintf("http://%s.cartodb.com/api/v2/sql?api_key=%s&q=%s", $username, $apikey, urlencode($query) );
    $content = @json_decode(@file_get_contents($url));
    if (! $content) throw new PlaceDataSourceErrorException( array('CartoDB query failed: No content returned. Check your settings.') );
    if (@$content->error) throw new PlaceDataSourceErrorException( array('CartoDB query failed: No content returned. ' . $content->error[0] ) );

    // the fields attribute has everything we need; simply collect them
    // it's an object, so use get_object_vars() to treat it as an array so we can iterate
    $ignore_fields = array('the_geom','the_geom_webmercator','updated_at','created_at');
    $fields = array();
    foreach( array_keys(get_object_vars($content->fields)) as $f) {
        if (in_array($f,$ignore_fields)) continue;
        $fields[] = $f;
    }
    natcasesort($fields);

    // done!
    return $fields;
}



/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
