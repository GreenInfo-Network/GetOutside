<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceCategory extends DataMapper {

var $table            = 'placecategories';
var $default_order_by = array('name');
var $has_one          = array();
var $has_many         = array('place',);





public function howManyPlaces() {
    return $this->place->count();
}



} // end of Model
