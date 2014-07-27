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
                        $container.show().appendTo($('table.links'));
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

    // instantiate WireTabs
    if ($('ul.Inputfields').length) {
        $('ul.Inputfields').WireTabs({
            items: $(".Inputfields > .InputfieldMarkup"),
            id: 'ProcessLinkCheckerTabs',
        });
    }
    
});