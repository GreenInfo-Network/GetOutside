<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Event extends DataMapper {

var $table            = 'events';
var $default_order_by = array('date', 'name');
var $has_one          = array('eventdatasource',);
var $has_many         = array();

} // end of Model
