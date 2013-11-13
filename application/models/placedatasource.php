<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceDataSource extends DataMapper {

var $table            = 'placedatasources';
var $default_order_by = array('name','type');
var $has_one          = array();
var $has_many         = array('place',);

} // end of Model
