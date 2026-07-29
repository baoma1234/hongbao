define(['jquery', 'bootstrap', 'backend', 'form'], function ($, undefined, Backend, Form) {
    var Controller = {
        index: function () {
            Form.api.bindevent($('#memberlevel-form'));

            function nextLevelNo() {
                var max = 0;
                $('#levels-table tbody .level-no').each(function () {
                    var n = parseInt($(this).val(), 10) || 0;
                    if (n > max) {
                        max = n;
                    }
                });
                return max + 1;
            }

            function reindexLevelRows() {
                $('#levels-table tbody tr.level-row').each(function () {
                    var level = parseInt($(this).find('.level-no').val(), 10) || 0;
                    if (level <= 0) {
                        return;
                    }
                    $(this).find('input[name]').each(function () {
                        var field = $(this).attr('name').replace(/^levels\[\d+\]/, '');
                        $(this).attr('name', 'levels[' + level + ']' + field);
                    });
                });
            }

            $(document).on('click', '#btn-add-level', function () {
                var level = nextLevelNo();
                var tpl = $('#level-row-tpl').html()
                    .replace(/__LEVEL__/g, String(level));
                $('#levels-table tbody').append(tpl);
            });

            $(document).on('click', '.btn-remove-level', function () {
                if ($('#levels-table tbody tr.level-row').length <= 1) {
                    Toastr.error('至少保留一个会员等级');
                    return;
                }
                $(this).closest('tr').remove();
            });

            $(document).on('change', '.level-no', function () {
                reindexLevelRows();
            });

            $('#memberlevel-form').on('submit', function () {
                reindexLevelRows();
                var levels = {};
                var duplicate = false;
                $('#levels-table tbody tr.level-row').each(function () {
                    var level = parseInt($(this).find('.level-no').val(), 10) || 0;
                    if (level <= 0) {
                        return;
                    }
                    if (levels[level]) {
                        duplicate = true;
                    }
                    levels[level] = true;
                });
                if (duplicate) {
                    Toastr.error('等级编号不能重复');
                    return false;
                }
                var defaultLevel = parseInt($('#default-member-level').val(), 10) || 0;
                if (!levels[defaultLevel]) {
                    Toastr.error('新用户默认等级必须在等级列表中存在');
                    return false;
                }
                return true;
            });
        }
    };
    return Controller;
});
