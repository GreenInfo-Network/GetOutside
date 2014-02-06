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
                            'placedatasource_arcgisrest' => array(
                                'join_other_as' => 'placedatasource',
                                'join_table' => 'placedatasources'
                            ),
                            'placedatasource_cartodb' => array(
                                'join_other_as' => 'placedatasource',
                                'join_table' => 'placedatasources'
                            ),
                            'placedatasource_wfs' => array(
                                'join_other_as' => 'placedatasource',
                                'join_table' => 'placedatasources'
                            ),
                        );
var $has_many         = array('placecategory','placeactivity',);




/*****************************************************************************
 * INSTANCE METHODS
 *****************************************************************************/

// fetch the list of categoryt names for this Place; convenience method for listings and the like
// if $join is omitted (default) return is a list/array; if $join is given, it's a join-string and the return is a joined string
public function listCategoryNames($join=null) {
    $categories = array();
    foreach ($this->placecategory as $pcat) $categories[] = $pcat->name;

    if ($join) $categories = implode($join,$categories);

    return $categories;
}
public function listCategoryIDs($join=null) {
    $categories = array();
    foreach ($this->placecategory as $pcat) $categories[] = $pcat->id;

    if ($join) $categories = implode($join,$categories);

    return $categories;
}





} // end of Model
