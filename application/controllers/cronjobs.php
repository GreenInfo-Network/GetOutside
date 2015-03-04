<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Cronjobs extends CI_Controller {
/* The /cronjobs pages form a facility by which scheduled tasks can be performed, e.g. find all Event sources and reload them */


public function __construct() {
    // bail if we're not being hit via CLI; cornjobs are CLI-only
    if (php_sapi_name() !== 'cli') die("This can only be run from command-line interface, e.g. via cron. See Automatic-Reloading.txt for more information.\n");

    // go ahead and be a constructor
    parent::__construct();

    // fetch the SiteConfig and set the timezone
    // timezone is important for Events
    $this->load->model('SiteConfig');
    $this->siteconfig = new SiteConfig();
    date_default_timezone_set( $this->siteconfig->get('timezone') );
}

public function reload_events() {
    // loop over all data sources...
    $sources = new EventDataSource();
    $sources->where('enabled','1')->get();
    printf("Found %d Event data sources\n", $sources->result_count() );

    // a link to the site config; a not-so-great MVC-violating hack, now that the data sxources need detailed knowledge of website configuration
    $this->load->model('SiteConfig');
    $siteconfig = new SiteConfig();

    foreach ($sources as $source) {
        printf("Source %d : %s\n", $source->id, $source->name );

        // try to reload
        try {
            $driver = $source->convertToDriver();
            $driver->siteconfig = $siteconfig;
            $driver->reloadContent();
        } catch (EventDataSourceErrorException $e) {
            printf("ERROR: %s", $e->getMessage() );
        } catch (EventDataSourceSuccessException $e) {
            printf("SUCCESS: %s", $e->getMessage() );
        }

    }

    print "Done\n";
}


public function reload_places() {
    // loop over all data sources...
    $sources = new PlaceDataSource();
    $sources->where('enabled','1')->get();
    printf("Found %d Place data sources\n", $sources->result_count() );

    // a link to the site config; a not-so-great MVC-violating hack, now that the data sources need detailed knowledge of website configuration
    $this->load->model('SiteConfig');
    $siteconfig = new SiteConfig();

    foreach ($sources as $source) {
        printf("Source %d : %s\n", $source->id, $source->name );

        // try to reload
        try {
            $driver = $source->convertToDriver();
            $driver->siteconfig = $siteconfig;
            $driver->reloadContent();
        } catch (PlaceDataSourceErrorException $e) {
            printf("ERROR: %s", $e->getMessage() );
        } catch (PlaceDataSourceSuccessException $e) {
            printf("SUCCESS: %s", $e->getMessage() );
        }

        // Places but not Events: recalculate the categorizations
        try {
            $source->recategorizeAllPlaces();
        } catch (PlaceDataSourceErrorException $e) {
            printf("ERROR: %s", $e->getMessage() );
        } catch (PlaceDataSourceSuccessException $e) {
            printf("SUCCESS: %s", $e->getMessage() );
        }

    }

    print "Done\n";
}



} // end of Controller