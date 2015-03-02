$(function() {

    // draw dashboard diagrams
    var plotName1 = 'status-breakdown-plot';
    var plotData1 = $('#' + plotName1).data('json');
    if (plotData1) {
        var plot1 = $.jqplot(plotName1, plotData1, {
            grid: {
                drawBorder: false,
                drawGridlines: false,
                background: 'transparent',
                shadow: false
            },
            shadow: false,
            seriesDefaults: {
                renderer: $.jqplot.DonutRenderer,
                rendererOptions: {
                    showDataLabels: true,
                    sliceMargin: 2,
                    shadow: false
                },
                seriesColors: [
                    '#DDDDDD',
                    '#000000',
                    '#D2E4EA',
                    '#81bf40',
                    '#FFA500',
                    '#FF0000',
                    '#C20202'
                ]
            },
            legend: {
                show: false
            }  
        });
    }
    var plotName2 = 'overview-plot';
    var plotData2 = $('#' + plotName2).data('json');
    if (plotData2) {
        var plot2 = $.jqplot(plotName2, plotData2, {
            grid: {
                drawBorder: false,
                drawGridlines: true,
                background: 'transparent',
                shadow: false
            },
            shadow: false,
            seriesColors: [
                '#2FB2EC',
                '#309BCA',
                '#81BF40',
                '#73A158'
            ],
            axes: {
                xaxis: {
                    renderer: $.jqplot.DateAxisRenderer,
                    // tickInterval: '1 day',
                    tickOptions: {
                        formatString: '%#d.%#m.%Y'
                    }
                },
                yaxis: {
                    min: 0
                }
            },
            highlighter: {
                show: true,
                sizeAdjust: 15
            },
            legend: {
                show: false
            }
        });
    }
    
    if (plotData1 || plotData2) {
        var plotTimeout;
        $(window).on('resize', function() {
            window.clearTimeout(plotTimeout);
            plotTimeout = window.setTimeout(function() {
                if (plotData1) plot1.replot();
                if (plotData2) plot2.replot();
            }, 250);
        });
    }

    // help
    $('.help').on('click', function() {
        $(this).toggleClass('open');
    });

    // data tables
    $('table.data').each(function() {
        if ($(this).find('tr').length > 1) {
            $(this).tablesorter();
        }
    });
    
});
