<?php

/**
 * Load, instantiate and run Link Crawler. This file can, for an example, be
 * accessed via cron to schedule link crawling and run it as a background task.
 *
 */

// override PHP time limit and disable user abort
set_time_limit(0);
ignore_user_abort(true);

// load, instantiate and run Link Crawler
require __DIR__ . '/LinkCrawler.php';
$crawler = new LinkCrawler();
$crawler->start();