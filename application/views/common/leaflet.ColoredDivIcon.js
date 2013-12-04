L.ColoredDivIcon = L.DivIcon.extend({
    options: {
        bgColor: '#FFFFFF',
        className: 'leaflet-div-icon',
        html: false
    },

    createIcon: function (oldIcon) {
        var div = (oldIcon && oldIcon.tagName === 'DIV') ? oldIcon : document.createElement('div'), options = this.options;

        if (options.html !== false) {
            div.innerHTML = options.html;
        } else {
            div.innerHTML = '';
        }

        if (options.bgPos) {
            div.style.backgroundPosition = (-options.bgPos.x) + 'px ' + (-options.bgPos.y) + 'px';
        }

        if (options.bgColor) {
            div.style.backgroundColor = options.bgColor;
        }

        this._setIconStyles(div, 'icon');
        return div;
    },

    createShadow: function () {
        return null;
    }
});

