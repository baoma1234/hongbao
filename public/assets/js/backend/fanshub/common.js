define(['jquery'], function ($) {
    return {
        bindExport: function (table, exportUrl) {
            $(document).on('click', '.btn-export', function () {
                var options = table.bootstrapTable('getOptions');
                var params = {
                    search: options.searchText || '',
                    sort: options.sortName || 'id',
                    order: options.sortOrder || 'desc'
                };
                if (options.filter) {
                    params.filter = typeof options.filter === 'object' ? JSON.stringify(options.filter) : options.filter;
                }
                if (options.op) {
                    params.op = typeof options.op === 'object' ? JSON.stringify(options.op) : options.op;
                }
                window.location.href = Fast.api.fixurl(exportUrl + '?' + $.param(params));
            });
        }
    };
});
