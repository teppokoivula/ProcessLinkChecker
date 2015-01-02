<?php

/**
 * Render one Page. This file is only intended to be accessed via LinkCrawler.
 *
 */

// disable error reporting
error_reporting(0);

// override PHP time limit and disable user abort
set_time_limit(0);
ignore_user_abort(true);

// bootstrap ProcessWire
$root = $argv[1];
if (is_null($root)) $root = substr(__DIR__, 0, strrpos(__DIR__, "/modules")) . "/..";
require rtrim($root, "/") . "/index.php";

// load and render Page
$page_id = (int) $argv[2];
if ($page_id) {
    $page = wire('pages')->get($page_id);
    try {
        echo @$page->render();
    } catch (Exception $e) {
        // cool story, bro!
    }
}