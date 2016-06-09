ProcessLinkChecker
==================

Link checking module for ProcessWire CMF/CMS
Copyright (c) 2014-2016, Teppo Koivula

This module adds a link checker tool to ProcessWire. When the module is installed, it should add a new page under Admin > Setup > Link Checker. From this page you can review all broken and/or otherwise problematic links (server issues, unknown domains etc.) found during periodic crawls.

## LinkCrawler

The links are crawled by a tool called LinkCrawler. LinkCrawler is built as an external PHP class, but (at least for the time being) it requires ProcessWire to function, so it's not entirely stand-alone. When started, it starts up ProcessWire, finds pages matching its configuration settings (more about those later), and uses regular expressions to find all links. Finally found links are checked one by one and returned headers are analyzed.

In addition to basic status checking, LinkCrawler is capable of recursively tracking destinations of 301 and 302 redirects and storing that information for later use. Note that at the moment only method LinkCrawler uses for getting this information is PHP's native get_headers(). More methods may be added later.

### Running the LinkCrawler

LinkCrawler can be started directly from the ProcessLinkChecker GUI by any user with the link-checker-run permission, but this can be very slow and resource-intensive, which is why it should preferably be triggered periodically as a Cron task. For this purpose a file called Init.php is included with ProcessLinkChecker. This file simply instantiates and starts LinkCrawler it, thus providing an easy way for Cron to init LinkCrawler:

`0 0 * * * /usr/bin/php /path/to/site/modules/ProcessLinkChecker/Init.php >/dev/null 2>&1`

(Note: your PHP path may vary, you might want to do something else with errors than redirect them to /dev/null, etc. Consult your hosting company if youre unsure about setting up Cron tasks.)

## Installing and configuring the module

This module is installed just like any other ProcessWire module, so the common how-to guide (http://modules.processwire.com/install-uninstall/) explains most of it already.

One thing to keep in mind is that when installed, this module will add permissions 'link-checker' and 'link-checker-run'. If you want users (other than superusers) to see Link Checker page under Admin > Setup, you'll have to give one of their roles the 'link-checker' permission. If you also want them to be able execute Link Checker right from the ProcessWire Admin, they'll need the 'link-checker-run' permission too.

## Options

LinkCrawler can read options from multiple sources:

* ProcessLinkChecker default settings
* ProcessLinkChecker user-configured settings
* Run-time settings (`$linkCrawler = new LinkCrawler(array('cache_max_age' => '1 SECOND'));`)

Run-time settings override everything else, ProcessLinkChecker user-configured settings override ProcessLinkChecker default settings, etc. This should guarantee sensible defaults for most cases while still making it possible to run LinkCrawler with custom settings if/when needed.

Possible settings for LinkCrawler are:

* skipped_links (array of links to skip on each crawl, defaults to none)
* cache_max_age (maximum time to cache links for, defaults to 1 day)
* selector (selector used to find pages, defaults to `status<8192, id!=2, has_parent!=2`)
* http_host (HTTP host prepended to relative URLs, required by PHP's get_headers(), defaults to null)
* log_level (how much gets logged; 0 logs nothing, 1 logs only start and end etc; 5 is current maximum, default is 1.)
* log_rotate (how many log files from previous runs should be kept; by default only the latest log file is stored)
* log_on_screen (mostly for command-line use, should log messages be displayed during crawl; defaults to false)
* batch_size (how many pages should be loaded into memory simultaneously; defaults to 100)
* sleep_between_batches (For how long should the crawler sleep between each batch of pages, in seconds; defaults to 0)
* max_recursion_depth (how deep should redirects be tracked, defaults to 1)
* sleep_between_requests (the time to wait between each individual request, defaults to 1 second)
* link_regex (regular expression used to identify checkable links from page content)
* skipped_links_regex (regular expression used to identify non-checkable/skipped links)
* http_request_method (the method used to fetch page content via HTTP, defaults to get_headers())
* http_user_agent (User-Agent string to send with HTTP requests)

## License

This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.

(See included LICENSE file for full license text.)
