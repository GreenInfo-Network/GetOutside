<?php
/**
 * This PHP-Class should only read a iCal-File (*.ics), parse it and give an 
 * array with its content.
 *
 * PHP Version 5
 *
 * @category Parser
 * @package  Ics-parser
 * @author   Martin Thoma <info@martin-thoma.de>
 * @license  http://www.opensource.org/licenses/mit-license.php  MIT License
 * @version  SVN: <svn_id>
 * @link     http://code.google.com/p/ics-parser/
 * @example  $ical = new ical('MyCal.ics');
 *           print_r( $ical->events() );
 */

// error_reporting(E_ALL);

/**
 * This is the iCal-class
 *
 * @category Parser
 * @package  Ics-parser
 * @author   Martin Thoma <info@martin-thoma.de>
 * @license  http://www.opensource.org/licenses/mit-license.php  MIT License
 * @link     http://code.google.com/p/ics-parser/
 *
 * @param {string} filename The name of the file which should be parsed
 * @constructor
 */
class ICal
{
    /* How many ToDos are in this ical? */
    public  /** @type {int} */ $todo_count = 0;

    /* How many events are in this ical? */
    public  /** @type {int} */ $event_count = 0; 

    /* The parsed calendar */
    public /** @type {Array} */ $cal;

    /* Which keyword has been added to cal at last? */
    private /** @type {string} */ $_lastKeyWord;

    /** 
     * Creates the iCal-Object
     * 
     * @param {string} $filename The path to the iCal-file
     *
     * @return Object The iCal-Object
     */ 
    public function __construct($filename) 
    {
        if (!$filename) {
            return false;
        }
        
        $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (stristr($lines[0], 'BEGIN:VCALENDAR') === false) {
            return false;
        } else {
            // TODO: Fix multiline-description problem (see http://tools.ietf.org/html/rfc2445#section-4.8.1.5)
            foreach ($lines as $line) {
                $line = trim($line);
                $add  = $this->keyValueFromString($line);
                if ($add === false) {
                    $this->addCalendarComponentWithKeyAndValue($type, false, $line);
                    continue;
                } 

                list($keyword, $value) = $add;

                switch ($line) {
                // http://www.kanzaki.com/docs/ical/vtodo.html
                case "BEGIN:VTODO": 
                    $this->todo_count++;
                    $type = "VTODO"; 
                    break; 

                // http://www.kanzaki.com/docs/ical/vevent.html
                case "BEGIN:VEVENT": 
                    $this->event_count++;
                    $type = "VEVENT"; 
                    break; 

                //all other special strings
                case "BEGIN:VCALENDAR": 
                case "BEGIN:DAYLIGHT": 
                    // http://www.kanzaki.com/docs/ical/vtimezone.html
                case "BEGIN:VTIMEZONE": 
                case "BEGIN:STANDARD": 
                    $type = $value;
                    break; 
                case "END:VTODO": // end special text - goto VCALENDAR key 
                case "END:VEVENT": 
                case "END:VCALENDAR": 
                case "END:DAYLIGHT": 
                case "END:VTIMEZONE": 
                case "END:STANDARD": 
                    $type = "VCALENDAR"; 
                    break; 
                default:
                    $this->addCalendarComponentWithKeyAndValue($type, 
                                                               $keyword, 
                                                               $value);
                    break; 
                } 
            }
            $this->process_recurrences();
            return $this->cal; 
        }
    }

    /** 
     * Add to $this->ical array one value and key.
     * 
     * @param {string} $component This could be VTODO, VEVENT, VCALENDAR, ... 
     * @param {string} $keyword   The keyword, for example DTSTART
     * @param {string} $value     The value, for example 20110105T090000Z
     *
     * @return {None}
     */ 
    public function addCalendarComponentWithKeyAndValue($component, 
                                                        $keyword, 
                                                        $value) 
    {
        if (strstr($keyword, ';')) {
          // Ignore everything in keyword after a ; (things like Language, etc)
          $keyword = substr($keyword, 0, strpos($keyword, ";"));
        }
        if ($keyword == false) { 
            $keyword = $this->last_keyword; 
            switch ($component) {
            case 'VEVENT': 
                $value = $this->cal[$component][$this->event_count - 1]
                                               [$keyword].$value;
                break;
            case 'VTODO' : 
                $value = $this->cal[$component][$this->todo_count - 1]
                                               [$keyword].$value;
                break;
            }
        }
        
        if (stristr($keyword, "DTSTART") or stristr($keyword, "DTEND")) {
            $keyword = explode(";", $keyword);
            $keyword = $keyword[0];
        }

        switch ($component) { 
        case "VTODO": 
            $this->cal[$component][$this->todo_count - 1][$keyword] = $value;
            //$this->cal[$component][$this->todo_count]['Unix'] = $unixtime;
            break; 
        case "VEVENT": 
            $this->cal[$component][$this->event_count - 1][$keyword] = $value; 
            break; 
        default: 
            $this->cal[$component][$keyword] = $value; 
            break; 
        } 
        $this->last_keyword = $keyword; 
    }

    /**
     * Get a key-value pair of a string.
     *
     * @param {string} $text which is like "VCALENDAR:Begin" or "LOCATION:"
     *
     * @return {array} array("VCALENDAR", "Begin")
     */
    public function keyValueFromString($text) 
    {
        preg_match("/([^:]+)[:]([\w\W]*)/", $text, $matches);
        if (count($matches) == 0) {
            return false;
        }
        $matches = array_splice($matches, 1, 2);
        return $matches;
    }

    /** 
     * Return Unix timestamp from ical date time format 
     * 
     * @param {string} $icalDate A Date in the format YYYYMMDD[T]HHMMSS[Z] or
     *                           YYYYMMDD[T]HHMMSS
     *
     * @return {int} 
     */ 
    public function iCalDateToUnixTimestamp($icalDate) 
    { 
        $icalDate = str_replace('T', '', $icalDate); 
        $icalDate = str_replace('Z', '', $icalDate); 

        $pattern  = '/([0-9]{4})';   // 1: YYYY
        $pattern .= '([0-9]{2})';    // 2: MM
        $pattern .= '([0-9]{2})';    // 3: DD
        $pattern .= '([0-9]{0,2})';  // 4: HH
        $pattern .= '([0-9]{0,2})';  // 5: MM
        $pattern .= '([0-9]{0,2})/'; // 6: SS
        preg_match($pattern, $icalDate, $date); 

        // Unix timestamp can't represent dates before 1970
        if ($date[1] <= 1970) {
            return false;
        } 
        // Unix timestamps after 03:14:07 UTC 2038-01-19 might cause an overflow
        // if 32 bit integers are used.
        $timestamp = mktime((int)$date[4], 
                            (int)$date[5], 
                            (int)$date[6], 
                            (int)$date[2],
                            (int)$date[3], 
                            (int)$date[1]);
        return  $timestamp;
    } 
    
    /**
     * Processes recurrences
     *
     * @author John Grogg <john.grogg@gmail.com>
     * @return {array}
     */
    public function process_recurrences() 
    {
        $array = $this->cal;
        $events = $array['VEVENT'];
        foreach ($array['VEVENT'] as $anEvent) {
          if (isset($anEvent['RRULE']) && $anEvent['RRULE'] != '') {
            // Recurring event, parse RRULE and add appropriate duplicate events
            $rrules = array();
            $rrule_strings = explode(';',$anEvent['RRULE']);
            foreach ($rrule_strings as $s) {
              list($k,$v) = explode('=', $s);
              $rrules[$k] = $v;
            }
            // Get Start timestamp
            $start_timestamp = $this->iCalDateToUnixTimestamp($anEvent['DTSTART']);
            $end_timestamp = $this->iCalDateToUnixTimestamp($anEvent['DTEND']);
            $event_timestmap_offset = $end_timestamp - $start_timestamp;
            // Get Interval
            $interval = (isset($rrules['INTERVAL']) && $rrules['INTERVAL'] != '') ? $rrules['INTERVAL'] : 1;
            // Get Until; the UNTIL is a MAY in the specification, but realistically we need it, so we set it to a default
            // pro tip: do not set a default UNTIL to something outlandish such as 29991231T235959Z   this will create a zillion recurrences, exhaust system memory, and crash
            //          instead set merely one year so it's 365 events max
            if (! isset($rrules['UNTIL']) ) {
                $rrules['UNTIL'] = date('YmdT235959Z', time() + 86400 * 365 );
            }
            // Decide how often to add events and do so
            switch ($rrules['FREQ']) {
              case 'DAILY':
                // Simply add a new event each interval of days until UNTIL is reached
                $offset = "+$interval day";
                $recurring_timestamp = strtotime($offset, $start_timestamp);
                while ($recurring_timestamp <= $until) {
                  // Add event
                  $anEvent['DTSTART'] = date('Ymd\THis',$recurring_timestamp);
                  $anEvent['DTEND'] = date('Ymd\THis',$recurring_timestamp+$event_timestmap_offset);
                  $events[] = $anEvent;
                  // Move forward
                  $recurring_timestamp = strtotime($offset,$recurring_timestamp);
                }
                break;
              case 'WEEKLY':
                // Create offset
                $offset = "+$interval week";
                // Build list of days of week to add events
                $weekdays = array('SU','MO','TU','WE','TH','FR','SA');
                $bydays = (isset($rrules['BYDAY']) && $rrules['BYDAY'] != '') ? explode(',', $rrules['BYDAY']) : array('SU','MO','TU','WE','TH','FR','SA');
                // Get timestamp of first day of start week
                $week_recurring_timestamp = (date('w', $start_timestamp) == 0) ? $start_timestamp : strtotime('last Sunday '.date('H:i:s',$start_timestamp), $start_timestamp);
                // Step through weeks
                while ($week_recurring_timestamp <= $until) {
                  // Add events for bydays
                  $day_recurring_timestamp = $week_recurring_timestamp;
                  foreach ($weekdays as $day) {
                    // Check if day should be added
                    if (in_array($day, $bydays) && $day_recurring_timestamp > $start_timestamp && $day_recurring_timestamp <= $until) {
                      // Add event to day
                      $anEvent['DTSTART'] = date('Ymd\THis',$day_recurring_timestamp);
                      $anEvent['DTEND'] = date('Ymd\THis',$day_recurring_timestamp+$event_timestmap_offset);
                      $events[] = $anEvent;
                    }
                    // Move forward a day
                    $day_recurring_timestamp = strtotime('+1 day',$day_recurring_timestamp);
                  }
                  // Move forward $interaval weeks
                  $week_recurring_timestamp = strtotime($offset,$week_recurring_timestamp);
                }
                break;
              case 'MONTHLY':
                // Create offset
                $offset = "+$interval month";
                $recurring_timestamp = strtotime($offset, $start_timestamp);
                if (isset($rrules['BYMONTHDAY']) && $rrules['BYMONTHDAY'] != '') {
                  // Deal with BYMONTHDAY
                  while ($recurring_timestamp <= $until) {
                    // Add event
                    $anEvent['DTSTART'] = date('Ym'.sprintf('%02d',$rrules['BYMONTHDAY']).'\THis',$recurring_timestamp);
                    $anEvent['DTEND'] = date('Ymd\THis',$this->iCalDateToUnixTimestamp($anEvent['DTSTART'])+$event_timestmap_offset);
                    $events[] = $anEvent;
                    // Move forward
                    $recurring_timestamp = strtotime($offset,$recurring_timestamp);
                  }
                } elseif (isset($rrules['BYDAY']) && $rrules['BYDAY'] != '') {
                  $start_time = date('His',$start_timestamp);
                  // Deal with BYDAY
                  $day_number = substr($rrules['BYDAY'], 0, 1);
                  $week_day = substr($rrules['BYDAY'], 1);
                  $day_cardinals = array(1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth');
                  $weekdays = array('SU' => 'sunday','MO' => 'monday','TU' => 'tuesday','WE' => 'wednesday','TH' => 'thursday','FR' => 'friday','SA' => 'saturday');
                  while ($recurring_timestamp <= $until) {
                    $event_start_desc = "{$day_cardinals[$day_number]} {$weekdays[$week_day]} of ".date('F',$recurring_timestamp)." ".date('Y',$recurring_timestamp)." ".date('H:i:s',$recurring_timestamp);
                    $event_start_timestamp = strtotime($event_start_desc);
                    if ($event_start_timestamp > $start_timestamp && $event_start_timestamp < $until) {
                      $anEvent['DTSTART'] = date('Ymd\T',$event_start_timestamp).$start_time;
                      $anEvent['DTEND'] = date('Ymd\THis',$this->iCalDateToUnixTimestamp($anEvent['DTSTART'])+$event_timestmap_offset);
                      $events[] = $anEvent;
                    }
                    // Move forward
                    $recurring_timestamp = strtotime($offset,$recurring_timestamp);
                  }
                }
                break;
              case 'YEARLY':
                // Create offset
                $offset = "+$interval year";
                $recurring_timestamp = strtotime($offset, $start_timestamp);
                $month_names = array(1=>"January", 2=>"Februrary", 3=>"March", 4=>"April", 5=>"May", 6=>"June", 7=>"July", 8=>"August", 9=>"September", 10=>"October", 11=>"November", 12=>"December");
                // HACK: Exchange doesn't set a correct UNTIL for yearly events, so just go 2 years out
                $until = strtotime('+2 year',$start_timestamp);
                // Check if BYDAY rule exists
                if (isset($rrules['BYDAY']) && $rrules['BYDAY'] != '') {
                  $start_time = date('His',$start_timestamp);
                  // Deal with BYDAY
                  $day_number = substr($rrules['BYDAY'], 0, 1);
                  $month_day = substr($rrules['BYDAY'], 1);
                  $day_cardinals = array(1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'fifth');
                  $weekdays = array('SU' => 'sunday','MO' => 'monday','TU' => 'tuesday','WE' => 'wednesday','TH' => 'thursday','FR' => 'friday','SA' => 'saturday');
                  while ($recurring_timestamp <= $until) {
                    $event_start_desc = "{$day_cardinals[$day_number]} {$weekdays[$month_day]} of {$month_names[$rrules['BYMONTH']]} ".date('Y',$recurring_timestamp)." ".date('H:i:s',$recurring_timestamp);
                    $event_start_timestamp = strtotime($event_start_desc);
                    if ($event_start_timestamp > $start_timestamp && $event_start_timestamp < $until) {
                      $anEvent['DTSTART'] = date('Ymd\T',$event_start_timestamp).$start_time;
                      $anEvent['DTEND'] = date('Ymd\THis',$this->iCalDateToUnixTimestamp($anEvent['DTSTART'])+$event_timestmap_offset);
                      $events[] = $anEvent;
                    }
                    // Move forward
                    $recurring_timestamp = strtotime($offset,$recurring_timestamp);
                  }
                } else {
                  $day = date('d',$start_timestamp);
                  // Step throuhg years adding specific month dates
                  while ($recurring_timestamp <= $until) {
                    $event_start_desc = "$day {$month_names[$rrules['BYMONTH']]} ".date('Y',$recurring_timestamp)." ".date('H:i:s',$recurring_timestamp);
                    $event_start_timestamp = strtotime($event_start_desc);
                    if ($event_start_timestamp > $start_timestamp && $event_start_timestamp < $until) {
                      $anEvent['DTSTART'] = date('Ymd\T',$event_start_timestamp).$start_time;
                      $anEvent['DTEND'] = date('Ymd\THis',$this->iCalDateToUnixTimestamp($anEvent['DTSTART'])+$event_timestmap_offset);
                      $events[] = $anEvent;
                    }
                    // Move forward
                    $recurring_timestamp = strtotime($offset,$recurring_timestamp);
                  }
                }
                break;
            }
          }
        }
        $this->cal['VEVENT'] = $events;
    }

    /**
     * Returns an array of arrays with all events. Every event is an associative
     * array and each property is an element it.
     *
     * @return {array}
     */
    public function events() 
    {
        $array = $this->cal;
        return $array['VEVENT'];
    }

    /**
     * Returns a boolean value whether thr current calendar has events or not
     *
     * @return {boolean}
     */
    public function hasEvents() 
    {
        return ( count($this->events()) > 0 ? true : false );
    }

    /**
     * Returns false when the current calendar has no events in range, else the
     * events.
     * 
     * Note that this function makes use of a UNIX timestamp. This might be a 
     * problem on January the 29th, 2038.
     * See http://en.wikipedia.org/wiki/Unix_time#Representing_the_number
     *
     * @param {boolean} $rangeStart Either true or false
     * @param {boolean} $rangeEnd   Either true or false
     *
     * @return {mixed}
     */
    public function eventsFromRange($rangeStart = false, $rangeEnd = false) 
    {
        $events = $this->sortEventsWithOrder($this->events(), SORT_ASC);

        if (!$events) {
            return false;
        }

        $extendedEvents = array();
        
        if ($rangeStart === false) {
            $rangeStart = new DateTime();
        } else {
            $rangeStart = new DateTime($rangeStart);
        }

        if ($rangeEnd === false or $rangeEnd <= 0) {
            $rangeEnd = new DateTime('2038/01/18');
        } else {
            $rangeEnd = new DateTime($rangeEnd);
        }

        $rangeStart = $rangeStart->format('U');
        $rangeEnd   = $rangeEnd->format('U');

        

        // loop through all events by adding two new elements
        foreach ($events as $anEvent) {
            $timestamp = $this->iCalDateToUnixTimestamp($anEvent['DTSTART']);
            if ($timestamp >= $rangeStart && $timestamp <= $rangeEnd) {
                $extendedEvents[] = $anEvent;
            }
        }

        return $extendedEvents;
    }

    /**
     * Returns a boolean value whether thr current calendar has events or not
     *
     * @param {array} $events    An array with events.
     * @param {array} $sortOrder Either SORT_ASC, SORT_DESC, SORT_REGULAR, 
     *                           SORT_NUMERIC, SORT_STRING
     *
     * @return {boolean}
     */
    public function sortEventsWithOrder($events, $sortOrder = SORT_ASC)
    {
        $extendedEvents = array();
        
        // loop through all events by adding two new elements
        foreach ($events as $anEvent) {
            if (!array_key_exists('UNIX_TIMESTAMP', $anEvent)) {
                $anEvent['UNIX_TIMESTAMP'] = 
                            $this->iCalDateToUnixTimestamp($anEvent['DTSTART']);
            }

            if (!array_key_exists('REAL_DATETIME', $anEvent)) {
                $anEvent['REAL_DATETIME'] = 
                            date("d.m.Y", $anEvent['UNIX_TIMESTAMP']);
            }
            
            $extendedEvents[] = $anEvent;
        }
        
        foreach ($extendedEvents as $key => $value) {
            $timestamp[$key] = $value['UNIX_TIMESTAMP'];
        }
        array_multisort($timestamp, $sortOrder, $extendedEvents);

        return $extendedEvents;
    }
} 
?>
