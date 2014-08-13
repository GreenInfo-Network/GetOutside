<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Cronjobs extends CI_Controller {
/* The /cronjobs pages form a facility by which scheduled tasks can be performed, e.g. find all Event sources and reload them */


public function reload_events() {
    // bail if we're not being hit via CLI; don't let the big, random Internet trigger full reloads at will!
    if (php_sapi_name() !== 'cli') return print "This can only be run from command-line interface, e.g. via cron. See Automatic-Reloading.txt for more information.\n";

    // loop over all data sources...
    $sources = new EventDataSource();
    $sources->get();
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
    // bail if we're not being hit via CLI; don't let the big, random Internet trigger full reloads at will!
    if (php_sapi_name() !== 'cli') return print "This can only be run from command-line interface, e.g. via cron. See Automatic-Reloading.txt for more information.\n";

    // loop over all data sources...
    $sources = new PlaceDataSource();
    $sources->get();
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