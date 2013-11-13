<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventDataSource extends DataMapper {

var $table            = 'eventdatasources';
var $default_order_by = array('name','type');
var $has_one          = array();
var $has_many         = array('event',);

} // end of Model
