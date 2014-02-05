<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventLocation extends DataMapper {

var $table            = 'eventlocations';
var $default_order_by = array('name');
var $has_one          = array('event',);
var $has_many         = array();




/*****************************************************************************
 * INSTANCE METHODS
 *****************************************************************************/






} // end of Model
