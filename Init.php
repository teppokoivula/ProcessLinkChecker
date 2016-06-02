<?php

/**
 * Load, instantiate and run Link Crawler. This file can, for an example, be
 * accessed via cron to schedule link crawling and run it as a background task.
 * 
 * @author Teppo Koivula <teppo.koivula@gmail.com>
 * @copyright Copyright (c) 2014-2016, Teppo Koivula
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License, version 2
 * @version 0.8.0
 */

// override PHP time limit and disable user abort
set_time_limit(0);
ignore_user_abort(true);

// load, instantiate and run Link Crawler
require __DIR__ . '/LinkCrawler.php';
$crawler = new LinkCrawler();
$crawler->start();
