<?php

/**
 * Render one Page. This file is only intended to be accessed via LinkCrawler.
 * 
 * @author Teppo Koivula <teppo.koivula@gmail.com>
 * @copyright Copyright (c) 2014-2016, Teppo Koivula
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License, version 2
 * @version 0.8.1
 */

// disable error reporting
error_reporting(0);

// override PHP time limit and disable user abort
set_time_limit(0);
ignore_user_abort(true);

// bootstrap ProcessWire
$root = $argv[1];
if ($root == '') $root = substr(__DIR__, 0, strrpos(__DIR__, "/modules")) . "/..";
require rtrim($root, "/") . "/index.php";

// load and render Page
$page_id = (int) $argv[2];
if ($page_id) {
    $page = $wire->pages->get($page_id);
    $status = $page->status;
    if ($page->isUnpublished) {
        $page->removeStatus('unpublished');
    }
    try {
        echo $page->render();
    } catch (Exception $e) {
        echo $e->getMessage();
        exit(1);
    }
    $page->status = $status;
}
