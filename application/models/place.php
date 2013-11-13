<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceDataSource extends DataMapper {

var $table            = 'places';
var $default_order_by = array('name');
var $has_one          = array('placedatasource',);
var $has_many         = array();

} // end of Model
