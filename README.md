#Get Outside!

Get Outside! is a framework and application template, enabling communities to post events and ongoing services and amenities.

Key components of a Get Outside! installation include:

* Lists and Open Street Map (OSM)-based maps of these events and facilities, and mechanisms to search and filter to find those of interest.
* An administrative interface by which events are loaded from pre-existing remote data sources such as Facebook events, Google Drive spreadsheets, and ActiveNet's REST API v2.
* An administrative interface by which facility locations are uploaded, accepting a variety of formats including shapefiles, Excel spreadsheets, and Google Drive spreadsheets.

The intended audience is parks and recreation departments, community centers, and other local organizations who have a collection of event and/or facility data, but who don't necessarily have the budget or personnel to deploy as custom-designed interactive GIS mapping website.


#Requirements

Requirements are very basic:
* PHP with MySQLi extension (5.1 or later)
* MySQL database (4.x or later)
* Apache with mod_rewrite enabled
* A Bing Maps API key (for address searches and directions)

Get Outside! is designed to use standard PHP extensions and the MySQL database server, so it may be deployed on common, low-cost web hosting services.


#Getting Started

1. Download the ZIP file of the Get Outside! source code, and unpack it on your PC.
2. Edit application/config/config.php, following the instructions in the file.
3. Edit application/config/database.php, following the instructions in the file.
4. Upload the source code to your website hosting service.
5. Point your web browser at the folder you just uploaded. You will be walked through the setup process.


##Credits & Thanks

Thanks to [http://www.knightfoundation.org/](The Knight Foundation) for funding this project.

The base framework for Get Outside! was inspired by the [https://github.com/gilbitron/PIP](PIP framework) by [https://github.com/gilbitron](Gilbert Pellegrom) Little code here resembles PIP, but it was a valuable tutorial on Controller routing and a basic View system.


##To-Do, Issues, & Bugs

* Admin panel: Users: do not allow user #1 to be deleted, nor to have manager status revoked (no widget for it, check POST data anyway)

* Setup controller: will need ongoing work during development, as needs change and new config elements are added

* Data sources: abstract class general Events Data Source and for Places Data Sources

* Admin UI: data sources UI

* Front page: basic map with OSM

* Front page: add to map the collected events and places from data view
