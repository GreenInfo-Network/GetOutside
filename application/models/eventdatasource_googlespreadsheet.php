<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource_GoogleSpreadsheet extends EventDataSource {

var $table            = 'eventdatasources';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('event',);

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
 * Connect to the data source and grab all the events listed, and save them as local Events.
 * By design this is destructive: all existing Events for this EventDataSource are deleted in favof of these new ones.
 */
public function reloadContent() {
    
}


/**********************************************************************************************
 * STATIC FUNCTIONS
 * utility functions
 **********************************************************************************************/


public static function testConnection() {
    
}

} // end of Model
