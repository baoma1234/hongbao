define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var Controller = {
        index: function () {
            Form.api.bindevent($("#csreply-form"));
        }
    };
    return Controller;
});
