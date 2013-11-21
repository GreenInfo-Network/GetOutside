<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Event extends DataMapper {

var $table            = 'events';
var $default_order_by = array('starts','name');
var $has_one          = array(
                            'eventdatasource',
                            'eventdatasource_googlecalendar' => array(
                                'join_other_as' => 'eventdatasource',
                                'join_table' => 'eventdatasources'
                            ),
                            'eventdatasource_ical' => array(
                                'join_other_as' => 'eventdatasource',
                                'join_table' => 'eventdatasources'
                            ),
                            'eventdatasource_atom' => array(
                                'join_other_as' => 'eventdatasource',
                                'join_table' => 'eventdatasources'
                            ),
                            'eventdatasource_rss2' => array(
                                'join_other_as' => 'eventdatasource',
                                'join_table' => 'eventdatasources'
                            ),
                            'eventdatasource_active' => array(
                                'join_other_as' => 'eventdatasource',
                                'join_table' => 'eventdatasources'
                            ),
                        );
var $has_many         = array();

} // end of Model
