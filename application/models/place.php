<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Place extends DataMapper {

var $table            = 'places';
var $default_order_by = array('name');
var $has_one          = array(
                            'placedatasource',
                            'placedatasource_googlespreadsheet' => array(
                                'join_other_as' => 'placedatasource',
                                'join_table' => 'placedatasources'
                            ),
                            'placedatasource_csv' => array(
                                'join_other_as' => 'placedatasource',
                                'join_table' => 'placedatasources'
                            ),
                            'placedatasource_shapefile' => array(
                                'join_other_as' => 'placedatasource',
                                'join_table' => 'placedatasources'
                            ),
                            'placedatasource_arcgisrest' => array(
                                'join_other_as' => 'placedatasource',
                                'join_table' => 'placedatasources'
                            ),
                            'placedatasource_cartodb' => array(
                                'join_other_as' => 'placedatasource',
                                'join_table' => 'placedatasources'
                            ),
                        );
var $has_many         = array('placecategory',);



} // end of Model
