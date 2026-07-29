define(['jquery', 'bootstrap', 'backend', 'table', 'form', 'echarts'], function ($, undefined, Backend, Table, Form, Echarts) {
    var Controller = {
        index: function () {
            // 这里保留 FastAdmin 自动生成的经典表格逻辑
            Table.api.init({extend: {index_url: 'bot/record/index'}});
            var table = $("#table");
            table.bootstrapTable({
                url: $.fn.bootstrapTable.defaults.extend.index_url,
                columns: [
                [
                    {field: 'id', title: 'ID'},
                    // 原来的只有ID: {field: 'tg_user_id', title: '用户TG-ID'},
                    
                    // 【新增】直接通过 点(.) 语法读取关联表的字段
                    {field: 'user.real_name', title: '真实姓名', operate: 'LIKE'},
                    {field: 'user.username', title: 'TG账号', operate: 'LIKE'},
                    
                    {
    field: 'type', 
    title: '类型', 
    searchList: {"checkin":"上班","checkout":"下班","meal":"吃饭","smoke":"抽烟",'toilet':"厕所"},
    custom: {"checkin": "success", "checkout": "info", "meal": "warning", "smoke": "danger"}, // 配置颜色
    formatter: Table.api.formatter.label // 👉 使用标签格式化器
},
                    {field: 'status', title: '状态', searchList: {"ongoing":"进行中","completed":"已完成"}, formatter: Table.api.formatter.status},
                    {field: 'start_time', title: '开始时间'},
                    {field: 'end_time', title: '结束时间'},
                    {field: 'duration_minutes', title: '耗时(分钟)'}
                ]
            ]
            });
            Table.api.bindevent(table);
        },
        report: function () {
            var myChart = Echarts.init(document.getElementById('echart-report'), 'macarons');
            $.post("bot/record/report", {}, function (ret) {
                if (ret.code === 1) {
                    var data = ret.data;
                   var option = {
    // 鼠标悬停时的提示框：使用高级的阴影指示器
    tooltip: {
        trigger: 'axis',
        axisPointer: { type: 'shadow' },
        backgroundColor: 'rgba(255, 255, 255, 0.9)', // 提示框半透明背景
        borderColor: '#eee',
        textStyle: { color: '#333' }
    },
    // 图例：调整位置和间距
    legend: {
        data: ['上班打卡(次)', '吃饭总长(分钟)', '抽烟总长(分钟)'],
        top: '2%',
        icon: 'circle', // 图例变成小圆点，更显精致
        itemGap: 20
    },
    // 画布边距
    grid: { left: '3%', right: '4%', bottom: '5%', containLabel: true },
    xAxis: [
        {
            type: 'category',
            data: data.userNames,
            axisTick: { show: false }, // 隐藏刻度小短线
            axisLine: { lineStyle: { color: '#ddd' } }, // 柔和的坐标轴线
            axisLabel: { color: '#666', margin: 15 } // 文字颜色和间距
        }
    ],
    yAxis: [
        {
            type: 'value',
            name: '打卡次数',
            minInterval: 1, // 保证次数是整数
            splitLine: { lineStyle: { type: 'dashed', color: '#eee' } }, // 虚线网格，不抢视觉
            axisLabel: { color: '#999' }
        },
        {
            type: 'value',
            name: '时长 (分钟)',
            splitLine: { show: false }, // 右侧Y轴不显示网格，保持画面干净
            axisLabel: { color: '#999' }
        }
    ],
    series: [
        {
            name: '上班打卡(次)',
            type: 'bar',
            yAxisIndex: 0,
            barMaxWidth: 35, // 限制最大宽度，拒绝“粗柱子”
            data: data.checkinData,
            itemStyle: {
                // 【高级感核心】柱状图使用从上到下的科技蓝渐变色
                color: new Echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: '#36D1DC' },
                    { offset: 1, color: '#5B86E5' }
                ]),
                borderRadius: [6, 6, 0, 0] // 圆角柱子
            }
        },
        {
            name: '吃饭总长(分钟)',
            type: 'line',
            yAxisIndex: 1,
            data: data.mealData,
            smooth: true, // 开启平滑曲线
            symbolSize: 8, // 拐点大小
            symbol: 'circle', // 拐点实心圆
            lineStyle: {
                width: 3,
                color: '#FF9A9E', // 柔和的珊瑚粉
                shadowColor: 'rgba(255, 154, 158, 0.4)', // 线条发光阴影效果
                shadowBlur: 10,
                shadowOffsetY: 5
            },
            itemStyle: { color: '#FF9A9E', borderWidth: 2, borderColor: '#fff' }, // 拐点描边
            areaStyle: {
                // 折线下方增加半透明渐变面积填充
                color: new Echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: 'rgba(255, 154, 158, 0.6)' },
                    { offset: 1, color: 'rgba(255, 154, 158, 0.05)' }
                ])
            }
        },
        {
            name: '抽烟总长(分钟)',
            type: 'line',
            yAxisIndex: 1,
            data: data.smokeData,
            smooth: true,
            symbolSize: 8,
            symbol: 'circle',
            lineStyle: {
                width: 3,
                color: '#FBD786', // 温暖的琥珀黄
                shadowColor: 'rgba(251, 215, 134, 0.4)',
                shadowBlur: 10,
                shadowOffsetY: 5
            },
            itemStyle: { color: '#FBD786', borderWidth: 2, borderColor: '#fff' },
            areaStyle: {
                color: new Echarts.graphic.LinearGradient(0, 0, 0, 1, [
                    { offset: 0, color: 'rgba(251, 215, 134, 0.6)' },
                    { offset: 1, color: 'rgba(251, 215, 134, 0.05)' }
                ])
            }
        }
    ]
};
                    myChart.setOption(option);
                    $(window).resize(function () { myChart.resize(); });
                }
            });
        }
    };
    return Controller;
});