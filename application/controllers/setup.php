<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Setup extends CI_Controller {
/* The /setup pages are a one-time-use system for boostrapping the database, setting an admin username & password, and so on. */

public function index() {
    // should we even be here? bypass the SiteConfig model and make a direct query to the table,
    // cuz by definition we can't load a SiteConfig if there's no working database yet
    $already = TRUE;
    if (! $this->db->table_exists('config')) $already = FALSE;
    if ($already) {
        $already = $this->db->query('SELECT value FROM config WHERE keyword=?', array('title') );
        $already = $already->row();
    }
    if ($already) return redirect(site_url('administration'));

    /////
    ///// set up the blank tables
    /////

    $this->db->query("
        CREATE TABLE IF NOT EXISTS sessions (
            session_id varchar(40) DEFAULT '0' NOT NULL,
            ip_address varchar(45) DEFAULT '0' NOT NULL,
            user_agent varchar(120) NOT NULL,
            last_activity int(10) unsigned DEFAULT 0 NOT NULL,
            user_data text NOT NULL,
            PRIMARY KEY (session_id),
            KEY last_activity_idx (last_activity)
        )
    ");
    $this->db->query("
        CREATE TABLE IF NOT EXISTS users (
            id integer NOT NULL AUTO_INCREMENT,
            username varchar(50) NOT NULL,
            password varchar(40) NOT NULL,
            level TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY username_idx (username)
        )
    ");
    $this->db->query("
        CREATE TABLE IF NOT EXISTS config (
            keyword varchar(50) NOT NULL,
            value text NOT NULL DEFAULT '',
            PRIMARY KEY (keyword)
        )
    ");
    $this->db->query("
        CREATE TABLE IF NOT EXISTS places (
            id INTEGER AUTO_INCREMENT NOT NULL,
            placedatasource_id integer NOT NULL,
            remoteid varchar(250),
            name varchar(50) NOT NULL,
            description text NOT NULL DEFAULT '',
            latitude FLOAT NOT NULL,
            longitude FLOAT NOT NULL,
            url VARCHAR(1000),
            urltext VARCHAR(25) DEFAULT 'Website',
            url2 VARCHAR(1000),
            urltext2 VARCHAR(25) DEFAULT 'More Info',
            PRIMARY KEY (id),
            KEY datasource_id_idx (placedatasource_id)
        )
    ");

    $this->db->query("
        CREATE TABLE IF NOT EXISTS events (
            id INTEGER AUTO_INCREMENT NOT NULL,
            eventdatasource_id INTEGER UNSIGNED NOT NULL,
            remoteid varchar(100),
            name varchar(100) NOT NULL,
            address varchar(100) NOT NULL DEFAULT '',
            description text NOT NULL DEFAULT '',
            allday BOOLEAN NOT NULL DEFAULT false,
            starts INTEGER UNSIGNED NOT NULL,
            ends INTEGER UNSIGNED NOT NULL,
            url VARCHAR(500),
            mon BOOLEAN NOT NULL DEFAULT false,
            tue BOOLEAN NOT NULL DEFAULT false,
            wed BOOLEAN NOT NULL DEFAULT false,
            thu BOOLEAN NOT NULL DEFAULT false,
            fri BOOLEAN NOT NULL DEFAULT false,
            sat BOOLEAN NOT NULL DEFAULT false,
            sun BOOLEAN NOT NULL DEFAULT false,
            audience_gender ENUM('0','1','2') DEFAULT 0,
            audience_age    ENUM('0','1','2','3','4','5') DEFAULT 0,
            PRIMARY KEY (id),
            KEY datasource_id_idx (eventdatasource_id)
        )
    ");

    $this->db->query("
        CREATE TABLE IF NOT EXISTS places (
            id INTEGER AUTO_INCREMENT NOT NULL,
            placedatasource_id INTEGER UNSIGNED NOT NULL,
            remoteid varchar(100),
            latitude float,
            longitude float,
            name varchar(50) NOT NULL,
            description text NOT NULL DEFAULT '',
            attributes_json TEXT,
            PRIMARY KEY (id),
            KEY datasource_id_idx (placedatasource_id)
        )
    ");
    $this->db->query("
        CREATE TABLE IF NOT EXISTS placecategories (
            id INTEGER AUTO_INCREMENT NOT NULL,
            name varchar(50) NOT NULL,
            PRIMARY KEY (id)
        )
    ");
    $this->db->query("INSERT INTO placecategories (name,enabled) VALUES ('Parks',1)");
    $this->db->query("INSERT INTO placecategories (name,enabled) VALUES ('Swimming',1)");
    $this->db->query("INSERT INTO placecategories (name,enabled) VALUES ('Community Centers',1)");

    $this->db->query("
        CREATE TABLE IF NOT EXISTS placecategories_places (
            placecategory_id INTEGER NOT NULL,
            place_id INTEGER NOT NULL,
            KEY placecategory_id_idx (placecategory_id),
            KEY place_id_idx (place_id)
        )
    ");

    $this->db->query("
        CREATE TABLE IF NOT EXISTS placedatasources (
            id INTEGER AUTO_INCREMENT NOT NULL,
            type varchar(50) NOT NULL,
            name varchar(50) NOT NULL,
            enabled BOOLEAN NOT NULL DEFAULT false,
            last_fetch INTEGER UNSIGNED,
            url varchar(500) NOT NULL,
            option1 varchar(500),
            option2 varchar(500),
            option3 varchar(500),
            option4 varchar(500),
            option5 varchar(500),
            option6 varchar(500),
            option7 varchar(500),
            option8 varchar(500),
            option9 varchar(500),
            PRIMARY KEY (id)
        )
    ");

    $this->db->query("
        CREATE TABLE IF NOT EXISTS placecategoryrules (
            id integer NOT NULL AUTO_INCREMENT,
            placecategory_id INTEGER NOT NULL,
            placedatasource_id INTEGER NOT NULL,
            field TEXT,
            value TEXT,
            PRIMARY KEY (id),
            KEY placedatasource_id_idx (placedatasource_id),
            KEY placecategory_id_idx (placecategory_id)
        )
    ");

    $this->db->query("
        INSERT INTO placedatasources (type, name, enabled, url, option1, option2, option3) VALUES ('ArcGIS REST API', 'Brooklyn Park ArcGIS Service', 1, 'https://cityview.brooklynpark.org/arcgis/rest/services/Public/Parks_wAmenities/MapServer/0', 'NAME', 'STREETNM', '')
    ");

    $this->db->query("
        CREATE TABLE IF NOT EXISTS eventdatasources (
            id INTEGER AUTO_INCREMENT NOT NULL,
            type varchar(50) NOT NULL,
            name varchar(50) NOT NULL,
            last_fetch INTEGER UNSIGNED,
            enabled BOOLEAN NOT NULL DEFAULT false,
            url varchar(500) NOT NULL,
            option1 varchar(500),
            option2 varchar(500),
            option3 varchar(500),
            option4 varchar(500),
            option5 varchar(500),
            option6 varchar(500),
            option7 varchar(500),
            option8 varchar(500),
            option9 varchar(500),
            PRIMARY KEY (id)
        )
    ");

    $this->db->query("
        CREATE TABLE IF NOT EXISTS eventlocations (
            id INTEGER AUTO_INCREMENT NOT NULL,
            event_id INTEGER UNSIGNED NOT NULL,
            latitude FLOAT NOT NULL,
            longitude FLOAT NOT NULL,
            title text NOT NULL DEFAULT '',
            subtitle text NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY event_id_idx (event_id)
        )
    ");
    $this->db->query("
        CREATE TABLE IF NOT EXISTS placeactivities (
            id INTEGER AUTO_INCREMENT NOT NULL,
            place_id INTEGER UNSIGNED NOT NULL,
            name varchar(50) NOT NULL,
            mon BOOLEAN NOT NULL DEFAULT false,
            tue BOOLEAN NOT NULL DEFAULT false,
            wed BOOLEAN NOT NULL DEFAULT false,
            thu BOOLEAN NOT NULL DEFAULT false,
            fri BOOLEAN NOT NULL DEFAULT false,
            sat BOOLEAN NOT NULL DEFAULT false,
            sun BOOLEAN NOT NULL DEFAULT false,
            starttime TIME NOT NULL default '00:00',
            endtime   TIME NOT NULL default '17:00',
            PRIMARY KEY (id),
            KEY place_id_idx (place_id)
        )
    ");

    // now that we have tables, we can load models
    $this->load->model('User');
    $this->load->model('SiteConfig');
    $data = array();

    /////
    ///// default config loaded from config/defaultsiteconfig.php
    ///// note that the CodeIgniter "Config" class documentation is not accurate here; this is what works
    /////
    $this->load->config('defaultsiteconfig',TRUE);
    $defaults = $this->config->config['defaultsiteconfig'];

    $siteconfig = new SiteConfig();
    $siteconfig->set('title',                       $defaults['TITLE'] );
    $siteconfig->set('company_name',                $defaults['COMPANY_NAME'] );
    $siteconfig->set('company_url',                 $defaults['COMPANY_URL'] );
    $siteconfig->set('jquitheme',                   $defaults['JQUITHEME'] );
    $siteconfig->set('feedback_url',                $defaults['FEEDBACK_URL'] );
    $siteconfig->set('bbox_w',                      $defaults['BBOX_W'] );
    $siteconfig->set('bbox_s',                      $defaults['BBOX_S'] );
    $siteconfig->set('bbox_e',                      $defaults['BBOX_E'] );
    $siteconfig->set('bbox_n',                      $defaults['BBOX_N'] );
    $siteconfig->set('bing_api_key',                $defaults['BING_API_KEY']);
    $siteconfig->set('google_api_key',              $defaults['GOOGLE_API_KEY']);
    $siteconfig->set('basemap_type',                $defaults['BASEMAP_TYPE'] );
    $siteconfig->set('basemap_xyzurl',              $defaults['BASEMAP_XYZURL'] );
    $siteconfig->set('metric_units',                $defaults['METRIC'] );
    $siteconfig->set('mobile_bgcolor',              $defaults['MOBILE_COLORS']['bgcolor'] );
    $siteconfig->set('mobile_fgcolor',              $defaults['MOBILE_COLORS']['fgcolor'] );
    $siteconfig->set('mobile_buttonfgcolor1',       $defaults['MOBILE_COLORS']['buttonfgcolor1'] );
    $siteconfig->set('mobile_buttonbgcolor1',       $defaults['MOBILE_COLORS']['buttonbgcolor1'] );
    $siteconfig->set('mobile_buttonfgcolor2',       $defaults['MOBILE_COLORS']['buttonfgcolor2'] );
    $siteconfig->set('mobile_buttonbgcolor2',       $defaults['MOBILE_COLORS']['buttonbgcolor2'] );
    $siteconfig->set('mobile_alertfgcolor',         $defaults['MOBILE_COLORS']['alertfgcolor'] );
    $siteconfig->set('mobile_alertbgcolor',         $defaults['MOBILE_COLORS']['alertbgcolor'] );
    $siteconfig->set('mobile_markerglowcolor',      $defaults['MOBILE_COLORS']['markerglowcolor'] );
    $siteconfig->set('place_markerglowcolor',       $defaults['PLACE_MARKERGLOWCOLOR'] );
    $siteconfig->set('event_markerglowcolor',       $defaults['EVENT_MARKERGLOWCOLOR'] );
    $siteconfig->set('mobile_logo',                 $defaults['MOBILE_LOGO']['content'] );
    $siteconfig->set('mobile_logo_width',           $defaults['MOBILE_LOGO']['width'] );
    $siteconfig->set('mobile_logo_height',          $defaults['MOBILE_LOGO']['height'] );
    $siteconfig->set('place_marker',                $defaults['PLACE_MARKER']['content'] );
    $siteconfig->set('place_marker_width',          $defaults['PLACE_MARKER']['width'] );
    $siteconfig->set('place_marker_height',         $defaults['PLACE_MARKER']['height'] );
    $siteconfig->set('event_marker',                $defaults['EVENT_MARKER']['content'] );
    $siteconfig->set('event_marker_width',          $defaults['EVENT_MARKER']['width'] );
    $siteconfig->set('event_marker_height',         $defaults['EVENT_MARKER']['height'] );
    $siteconfig->set('both_marker',                 $defaults['BOTH_MARKER']['content'] );
    $siteconfig->set('both_marker_width',           $defaults['BOTH_MARKER']['width'] );
    $siteconfig->set('both_marker_height',          $defaults['BOTH_MARKER']['height'] );
    $siteconfig->set('marker_gps',                  $defaults['MARKER_GPS']['content'] );
    $siteconfig->set('marker_gps_width',            $defaults['MARKER_GPS']['width'] );
    $siteconfig->set('marker_gps_height',           $defaults['MARKER_GPS']['height'] );
    $siteconfig->set('timezone',                    $defaults['TIMEZONE'] );
    $siteconfig->set('preferred_geocoder',          $defaults['DEFAULT_GEOCODER']);

    /////
    ///// initial admin password
    /////

    $admin_username = 'admin@example.com';
    for ($admin_password = '', $i = 0, $z = strlen($a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789')-1; $i != 10; $x = rand(0,$z), $admin_password .= $a{$x}, $i++);
    $user = new User();
    $user->level    = USER_LEVEL_ADMIN;
    $user->username = $admin_username;
    $user->password = User::encryptPassword($admin_password);
    $user->save();
    $data['admin_username'] = $admin_username;
    $data['admin_password'] = $admin_password;


    /////
    ///// done!
    /////
    $this->load->view('setup/index.phtml',$data);
}


} // end of Controller