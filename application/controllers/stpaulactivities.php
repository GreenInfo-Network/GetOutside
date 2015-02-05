<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class StPaulActivities extends CI_Controller {
// ad-hoc program for StPaul
// load up the RSS feeds for their various types of activities, and replace all PlaceActivity entries with these
// WARNING: this is not part of the Get Outside! software but a custom bit added in specifically for this one client

public function import_spreadsheets() {
    // for starters this is CLI-only to prevent abuse
    if (php_sapi_name() != "cli") return print "CLI only.";

    //
    // PREP WORK
    //

    // the listing of what activities are found in which spreadsheet
    // this forms the base name of the PlaceActivity entries, and is supplemented by any (note) content in the schedules if provided
    // e.g. "Open Gym" combined with a cell reading 1pm-2pm (Basketball) would have Basketball extracted, and the two added to form "Open Gym (Basketball)"
    $spreadsheet_activities = array(
        "Open" => "https://spreadsheets.google.com/feeds/list/0AiAGI4BYv6TYdEZxMWUtN21lRjlBcUMzcXFRZnBrcEE/ocy/public/basic?alt=rss ",
        "Open Gym Hours" => "https://spreadsheets.google.com/feeds/list/0AiAGI4BYv6TYdDhPRGlrekRVRTVWUkRtMnh1eXlVY2c/oco/public/basic?alt=rss",
        "Tot Time" => "https://spreadsheets.google.com/feeds/list/0AnibypjLl64WdEVHZ2VFUi1Kc3VRMUd0a1JnQWFtbHc/ocy/public/basic?alt=rss",
    );

    // start by fetching an assoc of all Places, so we can efficiently link these PlaceActivities onto a Place
    // this is also used to detect nonexistent places, which would alert us to typos and differences, e.g. "Jimmy Lee/Oxford Community Center" vs "Jimmy Lee-Oxford Community Center"
    $place_to_id = array();
    $px = new Place();
    $px->get();
    foreach ($px as $p) $place_to_id[ $p->name ] = $p->id;
    //return print_r($place_to_id);

    //
    // FETCH AND PARSE
    // includes a workaround that TWO of the three spreadsheets have field aliases like _ciyn3: while ONE has day names like monday:
    // fix is to rpelace those wonky field IDs with the weekday names; the field names are really unlikely to match real text so this works great
    // - iterate over rec centers
    //      - iterate over weekday cells
    //          - collect time/name of activity
    //          - if activity not seen yet create its stub (start/end/name/place-ID)
    //          - tag the activity as occuring on this cell's weekday (e.g. "fri = 1")
    // - collect the whole set of activities from all spreadsheets into $collected_placeactivity_entries
    //
    $collected_placeactivity_entries = array();

    foreach ($spreadsheet_activities as $activity_basename=>$url) {
        print "$activity_basename\n";
        print "$url\n";
        // fetch the HTML and swap out those column aliases with the weekday names
        // the comma-separated thing makes this fairly regex-friendly
        // then exchange $xml from XML text into a SimpleXML object so we can parse and iterate
        $xml = file_get_contents($url);
        if (substr($xml,0,6) != '<?xml ') return print "Fetch failed: $url\n";

        $xml = str_replace('_cpzh4: ', 'sunday: ', $xml);
        $xml = str_replace('_cre1l: ', 'monday: ', $xml);
        $xml = str_replace('_chk2m: ', 'tuesday: ', $xml);
        $xml = str_replace('_ciyn3: ', 'wednesday: ', $xml);
        $xml = str_replace('_ckd7g: ', 'thursday: ', $xml);
        $xml = str_replace('_clrrx: ', 'friday: ', $xml);
        $xml = str_replace('_cyevm: ', 'saturday: ', $xml);

        $xml = new SimpleXMLElement($xml);
        //print_r($xml);

        // iterate over the entries, which are effectively rec centers
        // and tease apart their cells to form activities-and-times
        // and collect the big old array (flat list)  of PlaceActivity entries

        foreach ($xml->channel->item as $reccenter) {
            $place_name = trim( (string) $reccenter->title);
            if ($place_name == 'Rec Center')        continue; // fake/table-header entry; skip
            if ($place_name == 'Recreation Center') continue; // fake/table-header entry; skip

            $place_id = @$place_to_id[$place_name];
            if (! $place_id) {
                print "    WARNING: $place_name not found. Skipping.\n";
                continue;
            }
            print "    $place_name => $place_id\n";

            // parse out the comma-separated days to get the raw string for each of the days of the week
            // this presumes on their not changing the order of days, which is safe
            $sun = $mon = $tue = $wed = $thu = $fri = $sat = $sun = NULL;

            $description = (string) $reccenter->description;
            $description = trim($description);
            $description = preg_replace('/[\r\n]+/i', " ", $description);
            $description = preg_replace('/\s*\-\s*/i', '-', $description);
            preg_match('/sunday: ([^,]+),?/', $description, $sun);         $sun = @$sun[1];
            preg_match('/monday: ([^,]+),?/', $description, $mon);        $mon = @$mon[1];
            preg_match('/tuesday: ([^,]+),?/', $description, $tue);     $tue = @$tue[1];
            preg_match('/wednesday: ([^,]+),?/', $description, $wed);    $wed = @$wed[1];
            preg_match('/thursday: ([^,]+),?/', $description, $thu);       $thu = @$thu[1];
            preg_match('/friday: ([^,]+),?/', $description, $fri);       $fri = @$fri[1];
            preg_match('/saturday: ([^,]+),?/', $description, $sat);               $sat = @$sat[1];

            //print "        Sun: $sun\n";
            //print "        Mon: $mon\n";
            //print "        Tue: $tue\n";
            //print "        Wed: $wed\n";
            //print "        Thu: $thu\n";
            //print "        Fri: $fri\n";
            //print "        Sat: $sat\n";


            // WHAT WE WANT, is a unique set set of activities, which means the $activity_basename PLUS the parenthetical (note) PLUS the start/end time, e.g. Open Gym (Swimming) 1pm-2pm
            // and then we want that distinct activity-and-time tagged as happening on Sunday, Monday, Tuesday, etc.
            // WHAT WE HAVE have is these cells for each weekday, and they need to be parsed to potentially contain multiple events before we even know what's in them
            // so, collect them into a structure that keys by the time-and-title as we discover it, and "accumulate days" into that entry if we encounter a duplicate

            // the first regex searches for any X:XX-X:XX (note) entries and returns a list of lists
            //      $todaysactivities[0] is the list of strings that matched
            //      $todaysactivities[1] is a list of start times
            //      $todaysactivities[2] is a list of end times
            //      $todaysactivities[3] is a list of labels
            //      Example:
            //          [1] => Array ( [0] => 12pm [1] => 2:30 )
            //          [2] => Array ( [0] => 1pm [1] => 4:30pm )
            //          [3] => Array ( [0] => (Family) [1] => (Soccer $2) )
            // the second catches the simpler case of having no (note) component, but then adds the third list with "" as the only element,
            //      so that in all cases we have a list of trios
            // idea is that $todaysactivities[0] gives us a list, whose length indicates the number of start/end/label trios we can expect to find in the remaining elements

            $this_place_activities = array();

            foreach ( array('sat'=>$sat, 'sun'=>$sun, 'mon'=>$mon, 'tue'=>$tue, 'wed'=>$wed, 'thu'=>$thu, 'fri'=>$fri) as $weekday=>$cellcontent) {
                $todaysactivities = NULL;
                preg_match_all('/([\d\:amp]+)\-([\d\:amp]+)\s+(\(.+?\))/', $cellcontent, $todaysactivities);
                if (! @$todaysactivities[0][0]) {
                    preg_match_all('/([\d\:amp]+)\-([\d\:amp]+)/', $cellcontent, $todaysactivities);
                    if (@$todaysactivities[0]) {
                        $todaysactivities[3] = array();
                        for ($ei=0; $ei < sizeof($todaysactivities[0]); $ei++) {
                            $todaysactivities[3][] = "";
                        }
                    }
                }

                if (! @$todaysactivities[0][0]) continue; // nothing found, probably the word Closed or Click To View...

                // iterate over the list of trios: start/end/tag
                for ($ei=0; $ei < sizeof($todaysactivities[0]); $ei++) {
                    $start = $todaysactivities[1][$ei];
                    $end   = $todaysactivities[2][$ei];
                    $tag   = $todaysactivities[3][$ei];

                    // if the entry isn't in this rec center's "registry" then create its stub entry
                    $event_key = "$tag$start$end";
                    if (! array_key_exists($event_key,$this_place_activities)) {
                        $this_place_activities[$event_key] = array(
                            'place_name'=> $place_name, // not used for the PlaceActivity, but for debugging
                            'place_id'  => $place_id,
                            'name'      => $tag ? "$activity_basename $tag" : $activity_basename,
                            'starttime' => date("H:i", strtotime($start)),
                            'endtime'   => date("H:i", strtotime($end)),
                            'mon' => 0, 'tue' => 0, 'wed' => 0, 'thu' => 0, 'fri' => 0, 'sat' => 0, 'sun' => 0,
                        );
                    }

                    // in any case, update this detected activity as occuring on this weekday
                    $this_place_activities[$event_key][$weekday] = 1;
                } // done pulling apart this day-cell
            } // done iterating over day-cells for this rec-center line

            // great; done processing this rec center
            // whatever activities we collected into our registry are ready to insert
            // so stick them onto that giant list
            printf("        Found %d PlaceActivity entries for this Place\n", sizeof(array_values($this_place_activities)) );
            foreach (array_values($this_place_activities) as $placeactivity) $collected_placeactivity_entries[] = $placeactivity;
        } // done with this rec-center line

        // on to the next line/rec-center!
        // don't hammer Google and make them angry
        sleep(5);
    }

    printf("Total: %d PlaceActivity items to create\n", sizeof($collected_placeactivity_entries) );
    //print_r($collected_placeactivity_entries);

    //
    // PURGE
    //
    $deletions = new PlaceActivity();
    $deletions->get();
    foreach ($deletions as $actinfo) $actinfo->delete();

    //
    // LOAD
    //
    print "Saving to database\n";
    foreach ($collected_placeactivity_entries as $actinfo) {
        print "    {$actinfo['place_name']} ({$actinfo['place_id']}) => {$actinfo['name']} @ {$actinfo['starttime']} - {$actinfo['endtime']}\n";

        $activity = new PlaceActivity();
        $activity->place_id     = $actinfo['place_id'];
        $activity->name         = $actinfo['name'];
        $activity->starttime    = $actinfo['starttime'];
        $activity->endtime      = $actinfo['endtime'];
        $activity->sat          = $actinfo['sat'];
        $activity->sun          = $actinfo['sun'];
        $activity->mon          = $actinfo['mon'];
        $activity->tue          = $actinfo['tue'];
        $activity->wed          = $actinfo['wed'];
        $activity->thu          = $actinfo['thu'];
        $activity->fri          = $actinfo['fri'];
        $activity->sat          = $actinfo['sat'];
        $activity->save();
    }

    // that's all, folks!
    print "DONE\n";
}

} // end of controller class
