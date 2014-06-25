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
        L.DomEvent.on(subcontainer, 'click', L.DomEvent.stopPropagation).on(subcontainer, 'click', L.DomEvent.preventDefault)
            .on(subcontainer, 'click', function (event) {
                // go to the Search page, which is also the Home page
                $.mobile.changePage('#page-search');
        }, this);

        // sub-control 2
        // List: switches to your search results listing
        var subcontainer  = L.DomUtil.create('div', 'leaflet-custombutton leaflet-custombutton-list', container);
        L.DomEvent.on(subcontainer, 'click', L.DomEvent.stopPropagation).on(subcontainer, 'click', L.DomEvent.preventDefault)
            .on(subcontainer, 'click', function (event) {
                // go to the Search Results page
                $.mobile.changePage('#page-search-results');
        }, this);

        // sub-control 3
        // GPS: toggle the Auto-Center behavior from whatever it currently is
        var subcontainer  = L.DomUtil.create('div', 'leaflet-custombutton leaflet-custombutton-gps', container);
        L.DomEvent.on(subcontainer, 'click', L.DomEvent.stopPropagation).on(subcontainer, 'click', L.DomEvent.preventDefault)
            .on(subcontainer, 'click', function (event) {
            autoCenterToggle();
        }, this);

        // sub-control 4
        // Settings: open the slide-in panel with Map settings such as basemap
        var subcontainer  = L.DomUtil.create('div', 'leaflet-custombutton leaflet-custombutton-settings', container);
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

