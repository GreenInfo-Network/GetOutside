#Get Outside!

Get Outside! is a framework and application template, enabling communities to post events and ongoing services and amenities.

Key components of a Get Outside! installation include:

* Lists and Open Street Map (OSM)-based maps of these events and facilities, and mechanisms to search and filter to find those of interest.
* An administrative interface by which events are loaded from pre-existing remote data sources such as Facebook events, Google Drive spreadsheets, and ActiveNet's REST API v2.
* An administrative interface by which facility locations are uploaded, accepting a variety of formats including shapefiles, Excel spreadsheets, and Google Drive spreadsheets.

The intended audience is parks and recreation departments, community centers, and other local organizations who have a collection of event and/or facility data, but who don't necessarily have the budget or personnel to deploy as custom-designed interactive GIS mapping website.


#Requirements

Get Outside! is designed to use standard PHP extensions and the MySQL database server, so it may be deployed on common, low-cost web hosting services.

* PHP 5.2 or later
* PHP PDO and PDO-MySQL extensions
* MySQL database 4.x or later
* Apache with mod_rewrite enabled
* A Bing Maps API key (for address searches and directions)


#Getting Started

1. Download the ZIP file of the Get Outside! source code, and unpack it on your PC.
2. Edit application/config/config.php, following the instructions in the file.
3. Edit application/config/database.php, following the instructions in the file.
4. Upload the source code to your website hosting service.
5. Point your web browser at the folder you just uploaded. You will be walked through the setup process.

Additional documentation is in the _docs_ subfolder.


##Credits & Thanks

Thanks to [http://www.knightfoundation.org/](The Knight Foundation) for funding this project.

As with most open source software, this builds from many other packages. These packages have been bundled with Get Outside! so the exact versions are available, and so we can include the individual licenses.

These packages include:

* _CodeIgniter_ -- PHP framework
* _Twitter Bootstrap_ -- Responsive grid design, so the page behaves nicely when you resize or view on mobile screens
* _jQuery and jQuery UI_ -- Toolkit/framework for AJAX, DOM/DHTML work, popup widgets, date pickers, and other such elements
* _fullCalendar_ -- jQuery plugin for generating AJAX-savvy calendar grids
* _Leaflet_ -- Map framework
* _TinyMCE_ -- Turns <textarea> boxes into WYSIWYG HTML editors
* _ics-parser_ -- by John Grogg, for parsing iCal event feeds.
(if we forgot to list you, please mention it!)


