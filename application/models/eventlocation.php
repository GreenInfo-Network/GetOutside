<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class EventLocation extends DataMapper {
/************************************************
 * This class describes the spatial location(s) of Events.
 * An Event may have any number of EventLocations (most have zero).
 * This is entirely separtate and conceptually different from Places, which are more about amenities which persist over time.
 * The EventLocation is more of the spatial location(s) of Events which will vanish tomorrow.
 * 
 * The majority of EventDataSource drivers do not support location.
 * Those which do can be identified by their $supports_location instance attribute.
 * 
 * If you intend to write a location-aware EventDataSource driver, see also the WritingDrivers.txt document.
 * FIELDS:
 * event_id     The unique ID# of the Event to which this is linked.
 * latitude     The latitude of the point location.
 * longitude    The longitude of the point location.
 * title        (optional) A title for this EventLocation. Driver-specific, but perhaps useful for the location name/address.
 * subtitle     (optional) A subtitle for this EventLocation. Driver-specific, but perhaps useful for the location name/address.
 *****************************************************************************/


var $table            = 'eventlocations';
var $default_order_by = array();
var $has_one          = array('event',);
var $has_many         = array();



/*****************************************************************************
 * INSTANCE METHODS
 *****************************************************************************/






} // end of Model
