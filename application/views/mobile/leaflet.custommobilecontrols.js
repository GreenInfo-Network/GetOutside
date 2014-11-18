/*
 * a bunch custom control panel for Leaflet
 * contains several buttons to toggle GPS mode, switch to Search page, etc.
 * original plan was to implement as 4 separate controls, but the effect is scattered and confusing, dominating all 4 corners and obscuring scale bar, ...
 */

L.controlCustomButtonPanel = L.Control.extend({
    options: {
        position: 'topright'
    },

    onAdd: function (map) {
        var container = L.DomUtil.create('div', 'leaflet-custombuttons leaflet-bar');
        this._map      = map;

        // sub-control 1
        // Search: returns to the search page (front page)
        var subcontainer  = L.DomUtil.create('div', 'leaflet-custombutton leaflet-custombutton-search', container);
        subcontainer.title = 'Search'; // tfa added tool-tip to each
        // tfa added fa-icons to each subcontainer
        var icon = L.DomUtil.create('i', 'fa fa-lg fa-search', subcontainer);

        L.DomEvent.on(subcontainer, 'click', L.DomEvent.stopPropagation).on(subcontainer, 'click', L.DomEvent.preventDefault)
            .on(subcontainer, 'click', function (event) {
                // go to the Search page, which is also the Home page
                $.mobile.changePage('#page-search');
        }, this);

        // sub-control 2
        // List: switches to your search results listing
        // hack: uses SEARCH_RESULTS_SUBTYPE to determine the last Search Result page you visited
        var subcontainer  = L.DomUtil.create('div', 'leaflet-custombutton leaflet-custombutton-list', container);
        subcontainer.title = 'Show search results';
        var icon = L.DomUtil.create('i', 'fa fa-lg fa-bars', subcontainer);

        L.DomEvent.on(subcontainer, 'click', L.DomEvent.stopPropagation).on(subcontainer, 'click', L.DomEvent.preventDefault)
            .on(subcontainer, 'click', function (event) {
                // go to the appropriate Search Results page
                $.mobile.changePage(SEARCH_RESULTS_SUBTYPE);
        }, this);

        // sub-control 3
        // GPS: toggle the Auto-Center behavior from whatever it currently is
        var subcontainer  = L.DomUtil.create('div', 'leaflet-custombutton leaflet-custombutton-gps', container);
        subcontainer.title = 'Toggle your location';
        var icon = L.DomUtil.create('i', 'fa fa-lg fa-location-arrow', subcontainer);

        L.DomEvent.on(subcontainer, 'click', L.DomEvent.stopPropagation).on(subcontainer, 'click', L.DomEvent.preventDefault)
            .on(subcontainer, 'click', function (event) {
            autoCenterToggle();
        }, this);

        // sub-control 4
        // Settings: open the slide-in panel with a legend
        var subcontainer  = L.DomUtil.create('div', 'leaflet-custombutton leaflet-custombutton-legend', container);
        subcontainer.title = 'View map legend';
        var icon = L.DomUtil.create('i', 'fa fa-lg fa-map-marker', subcontainer);

        L.DomEvent.on(subcontainer, 'click', L.DomEvent.stopPropagation).on(subcontainer, 'click', L.DomEvent.preventDefault)
            .on(subcontainer, 'click', function (event) {
                // open the Map Legend panel (not a page, a slideout)
                $('#panel-map-legend').panel('open');
        }, this);

        // sub-control 5
        // Settings: open the slide-in panel with Map settings such as basemap
        var subcontainer  = L.DomUtil.create('div', 'leaflet-custombutton leaflet-custombutton-settings', container);
        subcontainer.title = 'View map settings';
        var icon = L.DomUtil.create('i', 'fa fa-lg fa-gear', subcontainer);

        L.DomEvent.on(subcontainer, 'click', L.DomEvent.stopPropagation).on(subcontainer, 'click', L.DomEvent.preventDefault)
            .on(subcontainer, 'click', function (event) {
                // open the Map Settings panel (not a page, a slideout)
                $('#panel-map-settings').panel('open');
        }, this);

        return container;
    },

    _handleClick: function(event) {
        console.log(event);
    }
});

