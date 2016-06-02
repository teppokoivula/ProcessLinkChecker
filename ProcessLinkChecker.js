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

    // build the dashboard tab
    if (typeof processLinkCheckerDashboard == 'function') {
        processLinkCheckerDashboard();
    }

    // run Link Crawler on button click in Admin
    $('button[name=check-now]').on('click', function() {
        $(this).attr('disabled', 'disabled').find('i').addClass('fa-spin');
        $('#link-checker-check-now-icon').addClass('fa-spin');
        var $iframe = $('iframe.link-crawler-container');
        $.cookie('link_checker_selector', $('#link-checker-check-now .Inputfield_selector code').text());
        $iframe.addClass('loading').attr('src', $iframe.data('src'));
    });
    $('#link-checker-check-now').on('finished', '.link-crawler-summary', function(event, summary) {
        // display Link Crawler results when finished
        $(this).text(summary);
        $.get('./', function(data) {
            $data = $(data).find('[id^=link-checker-]:not(:last)');
            $data.each(function() {
                $('#' + $(this).attr('id')).html($(this).html());
            });
            updateLinkTablePlaceholders();
            parent.$(window).off('resize.jqplot');
            parent.$(document).off('wiretabclick.jqplot');
            parent.processLinkCheckerDashboard();
            $('button[name=check-now]').removeAttr('disabled').find('i').removeClass('fa-spin');
            $('#link-checker-check-now-icon').removeClass('fa-spin');
        });
    });

    $('body')
        .on('click', '.AdminDataTable .edit-comment', function() {
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
        .on('click', '.AdminDataTable .remove-link', function() {
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
        .on('change', 'form.link-checker-filters select', function() {
            var val = $(this).val();
            var name = $(this).attr('name');
            var $tbody = $(this).parents('form:first').next('table').find('tbody');
            $tbody.find('tr').show();
            if (val) {
                $tbody.find('tr:not(:has(.' + name + '-' + val + '))').hide();
                window.location.hash = name + '-' + val;
            } else {
                window.location.hash = '';
            }
        })
        .on('change', '.AdminDataTable input[name=skip]', function() {
            // mark link skipped
            var $container = $(this).parents('tr:first');
            var link = $(this).val();
            var skip = $(this).is(':checked') ? 1 : 0;
            $.post(moduleConfig.processPage+'skip', { links_id: link, skip: skip }, function() {
                var $table = $('table.' + (skip ? 'skipped-links' : ($container.find('.status-4xx').length ? 'broken-links' : 'redirects')));
                $container.fadeOut('fast', function() {
                    if ($table.length) $container.show().appendTo($table);
                    updateLinkTablePlaceholders();
                });
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
    
    // instantiate WireTabs
    if ($('ul.Inputfields:not(ul.Inputfields ul.Inputfields)').length) {
        $('ul.Inputfields:not(ul.Inputfields ul.Inputfields)').WireTabs({
            items: $("ul.Inputfields:not(ul.Inputfields ul.Inputfields) > .Inputfield"),
            id: 'ProcessLinkCheckerTabs',
        });
    }

    // asynchronous tab content
    var tables = [];
    var $loader = $('<i class="fa fa-spin fa-refresh"></i>');
    $('a[href^=#link-checker]:not(:first):not(a[href^=#link-checker-check-now])').on('click', function() {
        var table = $(this).attr('href').substr(14);
        if (!tables[table]) {
            var $description = $('#link-checker-' + table).find('.description:first');
            $description.hide().before($loader);
            $.get(location.pathname + 'table', { id: table }, function(data) {
                tables[table] = 1;
                $loader.remove();
                if (data) {
                    var $filters = $('<form class="link-checker-filters" data-table="' + table + '">');
                    var $filters_status = $('<select name="status">');
                    $filters_status.append($('<option>').attr('value', '').text(moduleConfig.i18n.filterStatus));
                    var statuses = [];
                    $.each($(data).find('.status'), function() {
                        var status = $(this).text();
                        statuses[status] = statuses[status] ? statuses[status] + 1 : 1;
                    });
                    for (var status in statuses) {
                        var $option = $('<option>').attr('value', status).text(status + ' (' + statuses[status] + ')');
                        $filters_status.append($option);
                        if (window.location.hash == '#status-' + status) {
                            $option.attr('selected', 'selected');
                        }
                    }
                    $filters.append($filters_status);
                    // note: filters are currently hidden from the GUI; making
                    // them visible is an option in the future, but currently
                    // they don't seem to add much value
                    $filters.hide();
                    $description.after(data);
                    $description.after($filters);
                    if ($filters.find('option[selected=selected]')) {
                        $filters.find('option[selected=selected]').parent().trigger('change');
                    }
                    $('table.' + table).tablesorter();
                } else {
                    $description.show();
                }
            });
        }
    });

    // enable opening tabs via links
    $('a[data-table]').on('click', function() {
        var table = $(this).data('table');
        var filter = $(this).data('filter');
        if (filter) {
            var $filters = $('form.link-checker-filters[data-table=' + table + ']');
            if ($filters.length) {
                $filters.find('[name=' + filter + ']').val($(this).text()).trigger('change');
            }
        }
        window.location.hash = $(this).attr('href');
        $('a[href=#link-checker-' + table + ']').trigger('click');
        return false;
    });
    
});
