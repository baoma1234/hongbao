define([], function () {
    var pidList = Config.platformList || {
        "1": "03",
        "2": "656",
        "3": "887",
        "4": "776"
    };

    return {
        list: pidList,
        formatter: function (value, row, index) {
            var label = pidList[value] || value;
            var colors = {1: 'primary', 2: 'success', 3: 'warning', 4: 'info'};
            var color = colors[value] || 'default';
            return '<span class="label label-' + color + '" style="display:inline-block;min-width:42px;font-size:13px;">' + label + '</span>';
        },
        searchList: pidList
    };
});
