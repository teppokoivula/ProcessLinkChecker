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
                    $description.after(data).hide();
                    $('table.' + table).tablesorter();
                } else {
                    $description.show();
                }
            });
        }
    });
    
});
