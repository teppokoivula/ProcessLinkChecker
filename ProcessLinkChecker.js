$(function() {

    // translations etc. are defined in ProcessLinkChecker.module
    var moduleConfig = config.ProcessLinkChecker;

    // prevent collapsing inputfields with "empty" headers
    $('.InputfieldHeader')
        .filter(function() {
            return $(this).html() === "&nbsp;";
        })
        .on('click', function() {
            return false;
        });

    // run Link Crawler on button click in Admin
    $('button[name=check-now]').on('click', function() {
        $(this)
            .next('iframe')
                .addClass('loading')
                .attr('src', $(this).next('iframe').data('src'))
                .end()
            .remove();
        // display Link Crawler results when finished
        $('p.description').on('finished', function(event, summary) {
            $(this).text(summary);
            $.get('./', function(data) {
                $data = $(data).find('[id^=link-checker-]:not(:last)');
                $data.each(function() {
                    $('#' + $(this).attr('id')).html($(this).html());
                });
                updateLinkTablePlaceholders();
            });
        });
    });

    $('.AdminDataTable')
        .on('click', '.edit-comment', function() {
            // edit comment
            var $container = $(this).parent('td').prev('td');
            var link = $(this).data('link');
            var comment = prompt(moduleConfig.i18n.commentPrompt, $container.text());
            if (comment !== null) {
                $.post(moduleConfig.processPage+'comment', { links_id: link, comment: comment }, function(data) {
                    $container.text(data).effect("highlight", {}, 500);
                });
            }
            return false;
        })
        .on('click', '.remove-link', function() {
            // remove link
            var $container = $(this).parents('tr:first');
            var link = $(this).data('link');
            $.post(moduleConfig.processPage+'remove', { links_id: link }, function(data) {
                $container.fadeOut('fast', function() {
                    $container.remove();
                    updateLinkTablePlaceholders();
                });
            });
            return false;
        })
        .on('change', 'input[name=skip]', function() {
            // mark link skipped
            var $container = $(this).parents('tr:first');
            var link = $(this).val();
            var skip = $(this).is(':checked') ? 1 : 0;
            $.post(moduleConfig.processPage+'skip', { links_id: link, skip: skip }, function() {
                if (skip) {
                    $container.fadeOut('fast', function() {
                        $container.show().appendTo($('table.skipped-links')); 
                        updateLinkTablePlaceholders();
                    });
                } else {
                    $container.fadeOut('fast', function() {
                        var table = $container.find('.status-4xx').length ? 'broken-links' : 'redirects';
                        $container.show().appendTo($('table.' + table));
                        updateLinkTablePlaceholders();
                    });
                }
            });
        });
    
    function updateLinkTablePlaceholders() {
        $('table.AdminDataTable').each(function() {
            if ($(this).find('> tbody > tr').length == 1) {
                $(this).hide();
                $(this).prev('.description').show();
                $(this).find('> tbody > tr:first').show();
            } else {
                $(this).show();
                $(this).prev('.description').hide();
                $(this).find('> tbody > tr:first').hide();
            }
        });
    }

    updateLinkTablePlaceholders();
    
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
                    shadow: false,
                },
                seriesColors: [
                    '#DDDDDD',
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

    // instantiate WireTabs
    if ($('ul.Inputfields:not(ul.Inputfields ul.Inputfields)').length) {
        $('ul.Inputfields:not(ul.Inputfields ul.Inputfields)').WireTabs({
            items: $("ul.Inputfields:not(ul.Inputfields ul.Inputfields) > .Inputfield"),
            id: 'ProcessLinkCheckerTabs',
        });
    }
    
});
