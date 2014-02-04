<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceDataSource_GoogleSpreadsheet extends PlaceDataSource {

var $table            = 'placedatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('place',);

var $option_fields = array(
    'url'     => array('required'=>TRUE, 'name'=>"Spreadsheet URL", 'help'=>"The URL of the Google Drive spreadsheet.<br/>Example: https://docs.google.com/spreadsheet/ccc?key=ABCDEFG<br/>The spreadsheet must be <i>Published to the web</i>. Note that &quot;Published to the web&quot; and &quot;Public on the web&quot; are not the same thing."),
    'option1' => array('required'=>TRUE, 'isfield'=>TRUE, 'name'=>"Name/Title Field", 'help'=>"Which field contains the name/title for these locations?"),
    'option2' => array('required'=>TRUE, 'isfield'=>TRUE, 'name'=>"Description Field", 'help'=>"Which field contains the description for these locations?"),
    'option3' => array('required'=>TRUE, 'isfield'=>TRUE, 'name'=>"Latitude Field", 'help'=>"Which field has the latitude of this location?"),
    'option4' => array('required'=>TRUE, 'isfield'=>TRUE, 'name'=>"Longitude Field", 'help'=>"Which field has the longitude of this location?"),
);


public function __construct() {
    parent::__construct();

    // assign a default WHERE clause; this is effectively chained to any other activerecord clause that is later added
    // and thus acts as an implicit filter
    $this->where('type','Google Spreadsheet');
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
    // grok the table key from the URL given; be strict about the URL, making sure it's really over at Google Docs
    $tablekey = null;
    preg_match('!https://docs.google.com/spreadsheet/ccc/?\?key=([\w\_\-]+)!i', $this->url, $tablekey );
    $tablekey = @$tablekey[1];
    if (! $tablekey) throw new PlaceDataSourceErrorException("That URL does not appear to point to a Google Drive Spreadsheet.");


    // check that the Name and Description and Lat & Lon fields, are all represented
    // why check when they had to pick from a list? cuz the spreadsheet may have changed since they set those options, or maybe they "hacked" and submitted some invalid field name
    $namefield = $this->option1;
    $descfield = $this->option2;
    $latfield  = $this->option3;
    $lonfield  = $this->option4;
    if (! preg_match('!^\w+$!', $namefield)) throw new PlaceDataSourceErrorException('Blank or invalid field: Name field');
    if (! preg_match('!^\w+$!', $latfield))  throw new PlaceDataSourceErrorException('Blank or invalid field: Latitude field');
    if (! preg_match('!^\w+$!', $lonfield))  throw new PlaceDataSourceErrorException('Blank or invalid field: Longitude field');
    if ($descfield and ! preg_match('!^\w+$!', $descfield)) throw new PlaceDataSourceErrorException('Blank or invalid field: Description field');
    $fields = $this->listFields(TRUE);
    if (!in_array($namefield,$fields)) throw new PlaceDataSourceErrorException('Chosen Name field ($namefield) does not exist in the spreadsheet.');
    if (!in_array($latfield,$fields))  throw new PlaceDataSourceErrorException('Chosen Latitude field ($latfield) does not exist in the spreadsheet.');
    if (!in_array($lonfield,$fields))  throw new PlaceDataSourceErrorException('Chosen Longitude field ($lonfield) does not exist in the spreadsheet.');
    if ($descfield and !in_array($descfield,$fields)) throw new PlaceDataSourceErrorException('Chosen Description field ($descfield) does not exist in the spreadsheet.');

    // compose the URL and fetch the spreadsheet content
    // then check for nonsense: no data, non-XML data
    $url = sprintf('https://spreadsheets.google.com/feeds/cells/%s/%d/public/basic', $tablekey, 1 );
    $xml = @file_get_contents($url);
    if (! $xml) throw new PlaceDataSourceErrorException("No data found. Check the URL, and make sure that it is \"Published to the web\".");
    if (substr($xml,0,5) != '<?xml') throw new PlaceDataSourceErrorException("No data found. Check the URL, and make sure that it is \"Published to the web\".");

    // replace $xml from the XML string to a XML parser, or die trying
    try {
        $xml = @new SimpleXMLElement($xml);
    } catch (Exception $e) {
        throw new PlaceDataSourceErrorException('Invalid data found. Identifies as XML, but could not be processed.');
    }

    // guess we got it; final checks
    if (! sizeof($xml->entry) ) throw new PlaceDataSourceErrorException('No rows found in the spreadsheet.');

    // crunch it
    // load the spreadsheet and create several side effects:
    // $column_name et al   These are culled from Row 1, since we're iterating anyway; ultimately we should have all required fields defined with $column indexes
    // $cells               Mapping of cell=>value, e.g. "B12"=>"Green Trees Park"   Used for quick access once we figure out our target fields
    // $maxrow              The highest row number found; then we can iterate 2..$maxrow and know that we're covering a range of rows
    // $colnames            Assoc of column letters onto name, e.g. "B"=>"Park Name"
    $maxrow   = 0;
    $cells    = array();
    $colnames = array();
    $column_name = null;
    $column_desc = null;
    $column_lat  = null;
    $column_lon  = null;

    foreach ($xml->entry as $cell) {
        $cellid    = (string) $cell->title;
        preg_match('/^([A-Z]+)(\d+)$/', $cellid, $cellinfo);
        $colletter = (string)  $cellinfo[1];
        $rownumber = (integer) $cellinfo[2];
        $value     = (string)  $cell->content;

        // if the row# is higher than our current max, increment it
        if ($rownumber > $maxrow) $maxrow = $rownumber;

        // if this cell is in row 1 *and* its name matches one of our target fields, then we have found a column ID   (e.g. the Name field by whatever name, is column E)
        if ($rownumber==1 and $value == $namefield) $column_name = $colletter;
        if ($rownumber==1 and $value == $descfield) $column_desc = $colletter;
        if ($rownumber==1 and $value == $latfield)  $column_lat  = $colletter;
        if ($rownumber==1 and $value == $lonfield)  $column_lon  = $colletter;

        // if this cell is in row 1 then we have found a column label, e.g. B=>Park Name
        if ($rownumber==1) $colnames[$colletter] = $value;

        // load the cells registry; a mdoest accomplishment, but one which will pay off in a moment
        $cells[$cellid] = $value;
    }
    if (! $column_name) throw new PlaceDataSourceErrorException("Parsing error: Couldn't figure out column letter for Name");
    if (! $column_desc) throw new PlaceDataSourceErrorException("Parsing error: Couldn't figure out column letter for Desc");
    if (! $column_lat ) throw new PlaceDataSourceErrorException("Parsing error: Couldn't figure out column letter for Latitude");
    if (! $column_lon ) throw new PlaceDataSourceErrorException("Parsing error: Couldn't figure out column letter for Longitude");

    // prep work: deletions
    // compose a list of all Remote-ID currently in the database within this data source
    // as we go over the records we'll remove them from this list
    // anything still remaining at the end of this process, is no longer in the remote data source and therefore should be deleted from the local database to match
    $deletions = array();
    foreach ($this->place as $old) $deletions[$old->remoteid] = $old->id;

    // pass 2
    // go from row 2 to row $maxrow and fetch specifically the columns we want to form this record
    $records_new       = 0;
    $records_updated   = 0;
    $records_badcoords = 0;
    $records_noname    = 0;

    for ($i=2; $i<=$maxrow; $i++) {
        $remoteid = $cells["{$column_name}{$i}"]; // no real remote ID so we use the name, I am certain that we'll regret this some day but since the Spreadsheet API lacks a true "row ID" separate from the row number, it's what we have
        $name     = $cells["{$column_name}{$i}"];
        $desc     = $cells["{$column_desc}{$i}"];
        $lon      = (float) $cells["{$column_lon}{$i}"];
        $lat      = (float) $cells["{$column_lat}{$i}"];

        // all attributes including and excluding those key ones targeted above; use the list of $colnames and make a simple assoc
        $attributes = array();
        foreach ($colnames as $acol=>$albl) $attributes[$albl] = $cells["{$acol}{$i}"];

        // missing a name? give a blank one but increment the warning count
        if (! $name) {
            $records_noname++;
            $name = '';
        }
        // check for obviously bad coordinates
        if (!$lon or !$lat or $lat>90 or $lat<-90 or $lon<-180 or $lon>180) {
            $records_badcoords++;
            continue;
        }

        // guess we're good
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
        $place->description      = $desc;
        $place->latitude         = $lat;
        $place->longitude        = $lon;
        $place->attributes_json  = json_encode($attributes);
        $place->save();
    }

    // delete any "leftover" records in $deletions
    // any that are in the remote data source, would have been removed based on their 'remoteid' field
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
    if ($records_noname)    $message[] = "$records_noname places had a blank name.";
    if ($records_badcoords) $message[] = "$records_badcoords places skipped due to bad coordinates.";
    $message = implode("\n",$message);
    throw new PlaceDataSourceSuccessException($message);
}


/*
 * listFields()
 * Connect to the data source and grab a list of field names. Return an array of string field names.
 */
public function listFields() {
    // grok the table key from the URL given; be strict about the URL, making sure it's really over at Google Docs
    $tablekey = null;
    preg_match('!https://docs.google.com/spreadsheet/ccc/?\?key=([\w\_\-]+)!i', $this->url, $tablekey );
    $tablekey = @$tablekey[1];
    if (! $tablekey) throw new PlaceDataSourceErrorException("That URL does not appear to point to a Google Drive Spreadsheet.");

    // compose the URL and fetch the spreadsheet content
    // then check for nonsense: no data, non-XML data
    $url = sprintf('https://spreadsheets.google.com/feeds/cells/%s/%d/public/basic', $tablekey, 1 );
    $xml = @file_get_contents($url);
    if (! $xml) throw new PlaceDataSourceErrorException("No data found. Check the URL, and make sure that it is \"Published to the web\".");
    if (substr($xml,0,5) != '<?xml') throw new PlaceDataSourceErrorException("No data found. Check the URL, and make sure that it is \"Published to the web\".");

    // replace $xml from the XML string to a XML parser, or die trying
    try {
        $xml = @new SimpleXMLElement($xml);
    } catch (Exception $e) {
        throw new PlaceDataSourceErrorException('Invalid data found. Identifies as XML, but could not be processed.');
    }

    // guess we got it; final checks
    if (! sizeof($xml->entry) ) throw new PlaceDataSourceErrorException('No rows found in the spreadsheet.');

    // generate the output, a flat list
    // a prior version accepted an $assoc=TRUE param to generate assoc arrays, but this got into "what would the caller want?" guesswork, and is best left to the caller
    $output = array();
    foreach ($xml->entry as $cell) {
        $cellname = (string) $cell->title;
        $colname  = (string) $cell->content;
        if (! preg_match('/^[A-Z]+1$/',$cellname)) continue;

        $output[] = $colname;
    }
    natcasesort($output);
    return $output;
}


/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/



} // end of Model
