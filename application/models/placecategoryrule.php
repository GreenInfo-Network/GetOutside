<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class PlaceCategoryRule extends DataMapper {

/*
 * This table is the intersection between a PlaceDataSource and a PlaceCategory
 * A datasource ID plus a placecategory ID equals a text string (possibly nonexistent/blank)
 * specifying criteria for automatically categorizing Places as they are being fetched from the data source.
 *
 * Example: When loading from a specific ArcGIS REST Service, 
 * a Place would be tagged as category "Park" according to the rule "Place_Type='Park'"
 * Example: When loading from a specific ArcGIS REST Service, 
 * a Place would be tagged with category "Basketball" according to the rule "BsktBalCrt=1"
 *
 * This relation using DataMapper ORM can be accessed from either direction. The most typical use is
 * when editing a PlaceDataSource, in which one would iterate over $source->placecategoryrule to populate a set of
 * fields showing what rules apply to each category, or when processing an incoming Place during reloadContent()
 * to test each rule and determine whether a given Place should be tagged in a given PlaceCategory
 */


var $table            = 'placecategoryrules';
var $default_order_by = array();
var $has_one          = array('placecategory','placedatasource');
var $has_many         = array();



} // end of Model
