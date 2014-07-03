<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceActivity extends DataMapper {

var $table            = 'placeactivities';
var $default_order_by = array('name', 'starttime', 'mon DESC','tue DESC','wed DESC','thu DESC','fri DESC','sat DESC','sun DESC');
var $has_one          = array('place',);
var $has_many         = array();


/**********************************************************************************
 * STATIC UTILITY FUCNTIONS
 **********************************************************************************/

public static function roundTime($hhmmss) {
    return substr($hhmmss, 0, 5);
}


} // end of Model
