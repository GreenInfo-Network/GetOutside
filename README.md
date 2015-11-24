#Get Outside

_Get Outside_ is a free, open source toolkit that enables any park and recreation department to:

* Quickly launch a fully-designed, ready to use mobile park and recreation event finder.
* Allow anyone with a smart phone to find nearby parks and events.

_Get Outside_ has tools that make it easy to aggregate data from multiple sources into one application. It uses open source technology and is built to run on common, low-cost web hosting services.


#Key Components

* A mobile friendly web site for easily viewing a map and lists of parks and events, with search by location, amenity and date - the web site is already fully styled and can be customized with agency logos, and is developed with responsive programming to adapt to desktop, tablet or phone devices.
* An administrative interface for:
  * Loading parks and events from existing data sources.
  * Customizing the look and feel of the application.
* A template that can be customized to quickly create park, activity and event data, if needed.

GreenInfo Network is releasing Get Outside as a fully-functional prototype - we welcome feedback. 


#Requirements

Get Outside is designed to use standard PHP extensions and the MySQL database server, so it may be deployed on common, low-cost web hosting services.

* PHP 5.2 or later
* PHP PDO and PDO-MySQL extensions
* MySQL database 4.x or later
* Apache with mod_rewrite enabled
* A Bing Maps API key (for address searches and directions)


#Getting Started

1. Download the ZIP file of the Get Outside source code, and unpack it on your PC.
2. Edit application/config/config.php, following the instructions in the file.
3. Create a database, and make note of the database name, user name and password.
4. Edit application/config/database.php, following the instructions in the file.
5. Upload the source code to your website hosting service.
6. Point your web browser at the folder you just uploaded. You will be walked through the setup process.

Additional documentation is in the _docs_ subfolder.


##Credits & Thanks

Thanks to [http://www.knightfoundation.org/](The Knight Foundation) for funding this project.

As with most open source software, this builds from many other packages. These packages have been bundled with Get Outside so the exact versions are available, and so we can include the individual licenses.

These packages include:

* _CodeIgniter_ -- PHP framework
* _Twitter Bootstrap_ -- Responsive grid design, so the page behaves nicely when you resize or view on mobile screens
* _jQuery and jQuery UI_ -- Toolkit/framework for AJAX, DOM/DHTML work, popup widgets, date pickers, and other such elements
* _fullCalendar_ -- jQuery plugin for generating AJAX-savvy calendar grids
* _Leaflet_ -- Map framework
* _TinyMCE_ -- Turns <textarea> boxes into WYSIWYG HTML editors
* _ics-parser_ -- by John Grogg, for parsing iCal event feeds.
(if we forgot to list you, please mention it!)


