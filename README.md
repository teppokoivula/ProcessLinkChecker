ProcessLinkChecker
==================

Link Checking module for ProcessWire CMF/CMS
Copyright (c) 2014, Teppo Koivula

WARNING: THIS IS AN ALPHA RELEASE AND SHOULD NOT BE INSTALLED OR USED ON A PRODUCTION SITE. THIS NOTICE WILL BE REMOVED ONCE THE MODULE IS CONSIDERED STABLE ENOUGH FOR USE IN SUCH CONTEXT. UNTIL THEN, CONTINUE AT YOUR OWN RISK.

This module adds a link checker tool for ProcessWire. When the module is installed, it should add a new page under Admin > Setup > Link Checker. From this page you can review all broken and/or otherwise problematic links (server issues, unknown domains etc.) found during periodic crawls.

## LinkCrawler

Crawling the links is done by a tool called LinkCrawler. LinkCrawler is built as an external PHP class, but (at least for the time being) it requires ProcessWire to function. When started, it finds and renders all pages matching given selector (defined by settings, more about that later), renders them, uses simple regexp to find all links (any value given for href or src attributes of any element on the page) and then checks each link individually to see which headers it returns.

In addition to basic status checking, LinkCrawler is capable of recursively tracking destination of 301 and 302 redirects and storing that information for later use. Note that, at the moment, only method LinkCrawler uses for getting this information is PHP's native get_headers(). The way it's implemented, adding more methods later isn't an issue.

### Running the LinkCrawler

LinkCrawler can be started directly from Process Link Checkers UI, but this can be very slow and resource-intensive task, which is why it really should be triggered periodically as a Cron task. For this purpose there's a file called LinkCrawlerInit.php included with Process Link Checker. This file is very simple and mostly just includes LinkCrawler.php and executes it, thus providing a simple way for Cron to init LinkCrawler:

`0 0 * * * /usr/bin/php /path/to/site/modules/ProcessLinkChecker/LinkCrawlerInit.php >/dev/null 2>&1`

(Note: your PHP path may vary, you might want to do something else with errors than redirect them to /dev/null etc. Consult your hosting company if youre unsure about setting up Cron tasks.)

## Installing and configuring the module

This module is installed just like any other ProcessWire module, so the common how-to guide (http://modules.processwire.com/install-uninstall/) explains most of it already.

One notable thing is that when installed, the module will add permission 'link-checker'. If you want users (other than superusers) to see Link Checker page under Admin > Setup, you'll have to give their roles this permission.

## Options

Note: at the moment all available options relate to the LinkCrawler class, but later there will be options that apply to Process Link Checker module itself too.

LinkCrawler can read options from multiple sources:

* LinkCrawler defaults
* ProcessLinkChecker default settings
* ProcessLinkChecker user-configured settings
* Run-time settings (`$linkCrawler = new LinkCrawler(array('cache_max_age' => '1 SECOND'));`)

Run-time settings override everything else, ProcessLinkChecker user-configured settings override ProcessLinkChecker default settings etc. This mechanism is intended to a) provide settings in all cases and b) allow you to run LinkCrawler with custom settings if/when needed.

Possible settings for LinkCrawler (at the moment) are:

* skipped_links (array of links to skip on each crawl, defaults to none)
* cache_max_age (maximum time to cache links for, defaults to 1 day)
* selector (selector used to find pages, defaults to `status!=trash, has_parent!=2`)
* http_host (HTTP host prepended to relative URLs, required by PHP's get_headers(), defaults to null)
* log_level (how much gets logged; 0 logs nothing, 1 logs only start and end etc; 5 is current maximum, default is 1.)
* log_on_screen (mostly for command-line use; should log messages be displayed during crawl?)
* max_recursion_depth (how deep should redirects be tracked, defaults to 1)
* sleep_between_requests (the time to wait between each individual request, defaults to 1 second)

## License

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.

(See included LICENSE file for full license text.)
