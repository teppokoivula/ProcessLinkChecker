<?php

/**
 * Link Crawler
 * 
 * This class is intended for use with ProcessWire CMS/CMF. When instantiated
 * and started, Link Crawler starts up ProcessWire, finds pages matching its
 * configuration settings, and finds links from the contents of said pages.
 * 
 * Link Crawler stores data about the links it finds to the database. Further
 * analysis and GUI related features are delegated to the accompanied Process
 * module, Process Link Checker.
 * 
 * @todo consider storing link texts to pages table (SEO and/or accessibility)
 * @todo consider adding separate domain-based throttling mechanism
 * 
 * @author Teppo Koivula <teppo.koivula@gmail.com>
 * @copyright Copyright (c) 2014-2016, Teppo Koivula
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License, version 2
 * @version 0.9.5
 *
 */
class LinkCrawler {
    
    /**
     * Basic run-time statistics
     *
     */
    protected $stats = array(
        'time_start' => null,
        'time_total' => 'less than a second',
        'pages' => 0,
        'pages_checked' => 0,
        'links' => 0,
        'links_checked' => 0,
        'unique_links' => 0,
        'status' => array(),
    );

    /**
     * Placeholder for config object (stdClass)
     *
     */
    protected $config = null;

    /**
     * ProcessWire root directory
     *
     */
    protected $root = null;

    /**
     * Additional settings for the 'exec' render method
     * 
     * Render file is the path of the PHP file used to render pages, while PHP
     * binary is the path of the PHP binary itself.
     * 
     * Please note that these settings are *not* configurable via GUI because
     * that would potentially result in serious vulnerabilities. If either of
     * these needs to be changed, you'll have to change it here.
     *
     */
    protected $render_file = null;
    protected $php_binary = null;
    
    /**
     * Array of checked links (required for run-time caching)
     * 
     */
    protected $checked_links = array();

    /**
     * Array of skipped links (required for run-time caching)
     * 
     */
    protected $skipped_links = array();

    /**
     * Array of skipped links (required for caching)
     * 
     */
    protected $skipped_links_cache = array();

    /**
     * Placeholders for prepared PDO statements
     *
     */
    protected $stmt_select_id = null;
    protected $stmt_insert_links = null;
    protected $stmt_insert_links_pages = null;
    protected $stmt_insert_history = null;

    /**
     * Instance of ProcessWire for internal use
     * 
     */
    protected $wire = null;
    
    /**
     * ProcessWire namespace (required for 3.x support)
     *
     */
    protected $wire_namespace = null;

    /**
     * Constants containing names of used database tables
     * 
     */
    const TABLE_LINKS = 'link_checker_links';
    const TABLE_LINKS_PAGES = 'link_checker_links_pages';
    const TABLE_HISTORY = 'link_checker_history';

    /**
     * Constant containing the name of the log file
     *
     */
    const LOG_FILE = 'link_checker';
    
    /**
     * Fetch config settings from ProcessWire module and bootstrap ProcessWire
     * 
     * This class gets it's config and it's data from ProcessWire, so a working
     * instance of ProcessWire really is required here. Whether such ties are a
     * necessity in the long run remains to be seen.
     * 
     * @param array $options for overwriting defaults and/or module settings
     * @param string $root ProcessWire root path
     * @throws Exception if ProcessWire can't be bootstrapped
     * @throws Exception if link_regex isn't set
     * @throws Exception if link_regex is set but invalid
     * @throws Exception if skipped_links_regex is set but invalid
     */
    public function __construct($options = array(), $root = null) {
        // unless ProcessWire is already available, bootstrap from a pre-defined
        // root path or dynamically built one (based on current directory)
        if (!defined("PROCESSWIRE")) {
            if (is_null($root)) $root = substr(__DIR__, 0, strrpos(__DIR__, "/modules")) . "/..";
            require rtrim($root, "/") . "/index.php";
            if (!defined("PROCESSWIRE")) {
                throw new Exception("Unable to bootstrap ProcessWire");
            }
        }
        $this->wire = $wire ?: wire();
        $this->root = $root;
        $this->wire_namespace = class_exists("\ProcessWire\Wire") ? '\\ProcessWire\\' : '';
        // setup config object
        $this->wire->modules->getModule('ProcessLinkChecker', array('noPermissionCheck' => true));
        $default_data = ProcessLinkChecker::getDefaultData();
        $data = $this->wire->modules->getModuleConfigData('ProcessLinkChecker');
        $this->config = (object) array_merge($default_data, $data, $options);
        // link regex is required
        if (!$this->config->link_regex) {
            throw new Exception("link_regex is required");
        }
        // validate regex settings with more regex
        if (!preg_match('/(^[^\w\s\\\]|_).*\1([imsxADSUXJu]*)$/', $this->link_regex)) {
            throw new Exception("invalid link_regex");
        }
        if ($this->skipped_links_regex && !preg_match('/^(?:([^\w\s\\\]|_).*\1(?:[imsxADSUXJu]*)(?:\r\n|\n|\r|$))+$/', implode("\n", $this->skipped_links_regex))) {
            throw new Exception("invalid skipped_links_regex");
        }
        // build skipped links cache from config and database
        $this->buildLinkCache();
        // support fractions of seconds in request throttling
        if ($this->config->sleep_between_requests) {
            $this->config->sleep_between_requests = round($this->config->sleep_between_requests*1000000);
        }
        if ($this->config->sleep_between_pages) {
            $this->config->sleep_between_pages = round($this->config->sleep_between_pages*1000000);
        }
        // HTTP host configuration
        if ($this->config->http_host_source == "config") {
            $this->config->http_host = $this->config->http_host_protocol . "://" . $this->wire->config->httpHost;
        }
        if ($this->config->http_host) {
            $this->config->http_host = rtrim($this->config->http_host, "/");
        }
        // set default stream context options for get_headers()
        stream_context_set_default(array(
            'http' => array(
                'follow_location' => 0,
                'max_redirects' => 0,
                'user_agent' => $this->config->http_user_agent,
            ),
        ));
        // prepare PDO statements for later use
        $this->stmt_select_id = $this->wire->database->prepare("SELECT id FROM " . self::TABLE_LINKS . " WHERE url = :url LIMIT 1");
        $this->stmt_insert_links = $this->wire->database->prepare("INSERT INTO " . self::TABLE_LINKS . " (url, status, location) VALUES (:url, :status, :location) ON DUPLICATE KEY UPDATE url = VALUES(url), status = VALUES(status), location = VALUES(location), checked = NOW()");
        $this->stmt_insert_links_pages = $this->wire->database->prepare("INSERT INTO " . self::TABLE_LINKS_PAGES . " (links_id, pages_id) VALUES (:links_id, :pages_id) ON DUPLICATE KEY UPDATE links_id = VALUES(links_id), pages_id = VALUES(pages_id)");
        $this->stmt_insert_history = $this->wire->database->prepare("INSERT INTO " . self::TABLE_HISTORY . " (time_start, pages, pages_checked, links, links_checked, unique_links, status) VALUES (:time_start, :pages, :pages_checked, :links, :links_checked, :unique_links, :status)");
        // load other required files
        require dirname(__FILE__) . '/CheckableValue.php';
    }

    /**
     * Iterate and check all pages matching given selector
     * 
     * This method stores various details, including start and end times and
     * number of checked pages and links, in $this->stats array.
     * 
     */
    public function start() {
        $this->stats['time_start'] = time();
        // prepare for logging
        if ($this->config->log_level) {
            $this->logRotate();
            $this->log("START: {$this->config->selector}");
        }
        // cleanup any expired data
        $this->flushLinkCache(true);
        // find and check pages matching selector
        $start = 0;
        $start_selector = null;
        $selectors = $this->getWireClass("Selectors", array($this->config->selector));
        $this->stats['pages'] = $this->wire->pages->count((string) $selectors);
        $limit = $this->stats['pages'];
        foreach ($selectors as $selector) {
            if ($selector->field == "limit") {
                $limit = $selector->value;
                $selectors->remove($selector);
            } else if ($selector->field == "start") {
                $start = $selector->value;
                $selectors->remove($selector);
            }
        }
        if ($start + $limit < $this->stats['pages']) $this->stats['pages'] = $start + $limit;
        if ($limit > $this->stats['pages'] - $start) $limit = $this->stats['pages'] - $start;
        $this->batch_size = $limit;
        if ($this->config->batch_size && $this->config->batch_size < $this->batch_size) {
            $this->batch_size = $this->config->batch_size;
        }
        $start_original = $start;
        $limit_selector = $this->getWireClass("SelectorEqual", array("limit", $this->batch_size));
        $selectors->add($limit_selector);
        $batches = ceil($limit / $this->batch_size);
        for ($batch = 0; $batch < $batches; ++$batch) {
            // throttle requests to avoid unnecessary (local and external) load
            if ($batch && $this->config->sleep_between_batches) {
                usleep($this->config->sleep_between_batches);
            }
            if ($batch) $start += $this->batch_size;
            if (($batch_pages = $start + $this->batch_size - $start_original) > $limit) {
                $selectors->remove($limit_selector);
                $limit_selector = $this->getWireClass("SelectorEqual", array("limit", $this->batch_size + ($limit - $batch_pages)));
                $selectors->add($limit_selector);
            }
            if ($start_selector) $selectors->remove($start_selector);
            $start_selector = $this->getWireClass("SelectorEqual", array("start", $start));
            $selectors->add($start_selector);
            $this->log(sprintf(
                "BATCH: %d/%d (pages %d-%d/%d)",
                $batch+1,
                $batches,
                $start_selector->value + 1,
                $start_selector->value + $limit_selector->value,
                $limit
            ));
            $pages = $this->wire->pages->find((string) $selectors);
            foreach ($pages as $page) {
                $this->log("FOUND Page: {$page->url}", 2);
                $this->wire->pages->uncacheAll();
                if ($this->checkPage($page)) {
                    ++$this->stats['pages_checked'];
                }
            }
        }
        $status_breakdown = array();
        if ($this->stats['links_checked']) {
            foreach ($this->stats['status'] as $status => $count) {
                $status_breakdown[] = "$status " . round(($count/$this->stats['links_checked'])*100, 2) . "% ($count)";
            }
        }
        $time = call_user_func($this->wire_namespace . "wireRelativeTimeStr", $this->stats['time_start']);
        if ((int) $time) $this->stats['time_total'] = substr($time, 0, strpos($time, " ", strpos($time, " ")+1));
        $this->log(sprintf(
            "END: %d/%d Pages and %d/%d links checked in %s. Status breakdown: %s.",
            $this->stats['pages_checked'],
            $this->stats['pages'],
            $this->stats['links_checked'],
            $this->stats['links'],
            $this->stats['time_total'],
            count($status_breakdown) ? implode(", ", $status_breakdown) : "unavailable (not enough data)"
        ));
        $this->stmt_insert_history->bindValue(':time_start', $this->stats['time_start'], PDO::PARAM_INT);
        $this->stmt_insert_history->bindValue(':pages', $this->stats['pages'], PDO::PARAM_INT);
        $this->stmt_insert_history->bindValue(':pages_checked', $this->stats['pages_checked'], PDO::PARAM_INT);
        $this->stmt_insert_history->bindValue(':links', $this->stats['links'], PDO::PARAM_INT);
        $this->stmt_insert_history->bindValue(':links_checked', $this->stats['links_checked'], PDO::PARAM_INT);
        $this->stmt_insert_history->bindValue(':unique_links', $this->stats['unique_links'], PDO::PARAM_INT);
        $this->stmt_insert_history->bindValue(':status', json_encode($this->stats['status']), PDO::PARAM_STR);
        $this->stmt_insert_history->execute();
    }

    /**
     * Check links found from rendered markup of given Page
     * 
     * This method receives an instance of ProcessWire Page, renders it and
     * attempts to capture and check all checkable links (URLs) in output.
     * 
     * @param Page|\ProcessWire\Page $page
     * @return bool whether or not a Page was checked
     * @throws Exception if render method is exec but exec() is not allowed
     * @throws Exception if render method is exec but render file isn't found
     * @throws Exception if render method is exec but PHP binary isn't executable
     * @throws Exception if render method is unrecognized
     */
    protected function checkPage($page) {
        // skip admin pages and non-viewable pages
        $isCheckablePage = $this->isCheckablePage($page);
        if (!$isCheckablePage->status) {
            $this->log($isCheckablePage->message, 4);
            return false;
        }
        // throttle requests to avoid unnecessary (local and external) load
        if ($this->stats['pages_checked'] && $this->config->sleep_between_pages) {
            usleep($this->config->sleep_between_pages);
        }
        // capture, iterate and check all links on page
        $data = "";
        switch ($this->render_method) {
            case 'render_page':
                $data = $page->render();
                break;
            case 'render_fields':
                foreach ($page->template->fields as $field) {
                    $field_value = $page->get($field->name);
                    if (is_object($field_value)) {
                        try {
                            $field_value = $field_value->render();
                        } catch (Exception $e) {
                            $field_value = (string) $field_value;
                        }
                    }
                    $data .= $field_value;
                }
                break;
            case 'exec':
                if (!function_exists('exec')) {
                    // note: this may not work as expected for PHP < 5.3
                    throw new Exception("Exec is not allowed");
                }
                if (!$this->php_binary) {
                    if (version_compare(PHP_VERSION, "5.4.0") >= 0 && defined("PHP_BINARY")) {
                        // use current PHP binary
                        $this->php_binary = PHP_BINARY;
                    } else {
                        // PHP_BINARY not defined, use default value
                        $this->php_binary = '/usr/bin/php';
                    }
                }
                if (!is_executable($this->php_binary)) {
                    throw new Exception("PHP binary is not executable");
                }
                if (!$this->render_file) {
                    $this->render_file = __DIR__ . '/Render.php';
                }
                if (!is_file($this->render_file)) {
                    throw new Exception("Render file not found");
                }
                $command = escapeshellcmd(sprintf(
                    '%s %s %s %s',
                    // note: str_replace below was added for extra safety on PHP
                    // 5.4 < 5.4.43, PHP 5.5 < 5.5.27, and PHP 5.6 < 5.6.11
                    escapeshellarg(str_replace("!", " ", $this->php_binary)),
                    escapeshellarg($this->render_file),
                    escapeshellarg($this->root),
                    escapeshellarg((int) $page->id)
                ));
                exec($command, $output, $return_var);
                $output_string = implode($output);
                if (!$return_var) {
                    $data = $output_string;
                } else {
                    $this->log("ERROR: {$output_string}\t{$page->url}", 3, true);
                }
                break;
            default:
                throw new Exception("Unrecognized render method");
        }
        if ($data) {
            preg_match_all($this->link_regex, $data, $matches);
            if (count($matches)) {
                foreach (array_unique($matches[2]) as $url) {
                    ++$this->stats['links'];
                    if (($status = $this->checkURL($url, $page)) !== false) {
                        ++$this->stats['links_checked'];
                        if (isset($this->stats['status'][$status])) {
                            $this->stats['status'][$status] += 1;
                        } else {
                            $this->stats['status'][$status] = 1;
                        }
                    }
                }
            }
        }
        return true;
    }

    /**
     * Check single link (URL) if it's deemed checkable
     * 
     * @param string $url URL to check
     * @param Page|\ProcessWire\Page $page Page containing URL, required for database logging
     * @return bool|string|null false if URL can't be checked, otherwise status code (can be null)
     */
    protected function checkURL($url, $page) {
        // make sure that URL is valid, not already checked etc.
        $checkable = $this->isCheckableURL($url, $page);
        if ($checkable->unique) ++$this->stats['unique_links'];
        if ($checkable == "") {
            $this->log("SKIPPED URL: {$url}" . ($checkable->value != $url ? " [{$checkable->value}]" : "") . " ({$checkable->message})", 3);
            return false;
        }
        // new, checkable link found; grab status code, store link info
        // to database and cache link URL locally as a checked link
        $headers = $this->getHeaders($checkable->value);
        $log_queue = array();
        $location = null;
        if (($headers['status'] == 301 || $headers['status'] == 302) && $headers['location'] && $this->config->max_recursion_depth) {
            // if a redirect (temporary or permanent) was identified, attempt to
            // find out current (real) location of target document recursively
            $rec_depth = 0;
            $rec_headers = $headers;
            $location = $headers['location'];
            while ($rec_depth < $this->config->max_recursion_depth && $rec_headers['location']) {
                ++$rec_depth;
                $rec_url = $rec_headers['location'];
                $rec_headers = $this->getHeaders($rec_url);
                if ($rec_headers['status'] != 301 && $rec_headers['status'] != 302) {
                    // update location only if non-redirect location was found
                    $headers['location'] = $rec_url;
                    $log_queue[] = "RECURSIVE CHECK: {$rec_url} ({$rec_headers['status']})";
                    break;
                }
                $log_queue[] = "RECURSIVE CHECK: {$rec_url} ({$rec_headers['status']} => {$rec_headers['location']})";
            }
        }
        if ($headers['status'] == "") $headers['status'] = null;
        $this->stmt_insert_links->bindValue(':url', $url, PDO::PARAM_STR);
        $this->stmt_insert_links->bindValue(':status', $headers['status'], PDO::PARAM_STR);
        $this->stmt_insert_links->bindValue(':location', $headers['location'], PDO::PARAM_STR);
        $this->stmt_insert_links->execute();
        $links_id = $this->wire->database->lastInsertId();
        $this->stmt_insert_links_pages->bindValue(':links_id', $links_id, PDO::PARAM_INT);
        $this->stmt_insert_links_pages->bindValue(':pages_id', $page->id, PDO::PARAM_INT);
        $this->stmt_insert_links_pages->execute();
        $this->checked_links[$checkable->value] = $links_id;
        $this->log("CHECKED URL: {$url}" . ($checkable->value != $url ? " [{$checkable->value}]" : "") . " ({$headers['status']}" . ($location ? " => {$location}" : "") . ")", 3);
        $this->log($log_queue, 4);
        return $headers['status'];
    }

    /**
     * Check if a Page can/should be checked
     * 
     * This method is currently very simple, but exists partly to support
     * addition of more complex rules for identifying checkable pages.
     * 
     * @param Page|\ProcessWire\Page $page
     * @return CheckableValue
     */
    protected function isCheckablePage($page) {
        $return = new CheckableValue($page);
        if ($page->template == "admin") {
            $return->message = "NON-CHECKABLE Page: {$page->url} (template=admin)";
            return $return;
        }
        if (!$page->viewable()) {
            $return->message = "NON-CHECKABLE Page: {$page->url} (not viewable)";
            return $return;
        }
        $return->status = true;
        return $return;
    }

    /**
     * Check if an URL can/should be checked, taking current state into account
     * 
     * Factors taken into consideration include already checked links, possible
     * run-time configuration changes etc. In addition to these checks, storing
     * Page-link-relations to junction table TABLE_LINKS_PAGES happens here.
     * 
     * Note: this method returns either false or the link in question. Latter is
     * done so that a link can be modified here, for an example by prefixing it
     * with the default HTTP host!
     *
     * @param string $url
     * @param Page|\ProcessWire\Page $page
     * @return CheckableValue
     */
    protected function isCheckableURL($url, $page) {
        $matches = null;
        if ($url[0] == "." || !preg_match("/^((?:[a-z][a-z0-9+-\.]*)\:(?:\/\/)?|\/\/)([^\/\?\#]*)?/i", $url, $matches)) {
            if ($url[0] != "/") {
                // URL is relative to current page's path, expand before processing
                $url = $page->url . $url;
            }
        }
        $return = new CheckableValue($url);
        if ($matches && $matches[1] != "//" && !in_array(strtolower(rtrim($matches[1], ":/")), array("http", "https"))) {
            // skip unsupported schemes
            $return->message = "unsupported scheme: " . rtrim($matches[1], ":/");
            return $return;
        }
        // convert relative URLs to absolute ones
        $temp_url = strtok($url, "?#");
        $suffix = $temp_url == $url ? "" : mb_substr($url, mb_strlen($temp_url));
        $prefix = $matches ? $matches[1] . (isset($matches[2]) ? $matches[2] : "") : "";
        $temp_url = $prefix ? mb_substr($temp_url, mb_strlen($prefix)) : $temp_url;
        if (strpos($url, "./") !== false) {
            $temp_url = ($temp_url[0] == "." ? $page->url : "") . $temp_url;
            $temp_url = str_replace("/./", "/", $temp_url);
            if (strpos($temp_url, "/../") !== false) {
                $temp_url = preg_replace("/\/[^\/]+\/\.\./", "", $temp_url);
            }
            $temp_url = str_replace("/../", "/", $temp_url);
        }
        $temp_url = substr($temp_url, -2) == "/." ? rtrim($temp_url, ".") : $temp_url;
        $return->value = $url = $prefix . $temp_url . $suffix;
        if (isset($this->skipped_links[$url])) {
            // link has already been checked and found non-checkable
            $return->message = "found from run-time skipped links";
            $return->unique = false;
            return $return;
        }
        $this->skipped_links[$url] = true;
        if (strpos($url, $this->wire->config->urls->admin) === 0) {
            // admin URLs should always be skipped automatically
            $return->message = "admin URL";
            return $return;
        }
        if ($url == "." || $url == "./" || $url == $page->url || ($this->config->http_host && ($url == $this->config->http_host . $page->url))) {
            // skip links pointing to current page
            $return->message = "link points to current page";
            return $return;
        }
        // minimal sanitization for URLs
        $clean_url = str_replace("&amp;", "&", $url);
        // handling for hash/hashbang URLs
        if (($hash_pos = strpos($clean_url, "#")) !== false) {
            $fragment = substr($clean_url, $hash_pos);
            $clean_url = substr($clean_url, 0, $hash_pos);
            if ($fragment[0] == "!") {
                // hashbang URL (https://developers.google.com/webmasters/ajax-crawling/docs/getting-started)
                $escaped_fragment = "_escaped_fragment_=" . urlencode(substr($fragment, 1));
                $clean_url .= (strpos($clean_url, "?") ? "&" : "?") . $escaped_fragment;
            }
        }
        $return->value = $clean_url;
        if (in_array($clean_url, array_keys($this->skipped_links_cache))) {
            // compare URL to local skip list and continue if match is
            // found (but store a row to junction table nevertheless!)
            if (is_null($this->skipped_links_cache[$clean_url])) {
                $this->stmt_select_id->bindValue(':url', $clean_url, PDO::PARAM_STR);
                $this->stmt_select_id->execute();
                $links_id = (int) $this->stmt_select_id->fetchColumn();
                $this->skipped_links_cache[$clean_url] = $links_id ? $links_id : 0;
            }
            if ($this->skipped_links_cache[$clean_url]) {
                $this->stmt_insert_links_pages->bindValue(':links_id', $this->skipped_links_cache[$clean_url], PDO::PARAM_INT);
                $this->stmt_insert_links_pages->bindValue(':pages_id', $page->id, PDO::PARAM_INT);
                $this->stmt_insert_links_pages->execute();
            }
            $return->message = "found from skipped links";
            return $return;
        }
        if ($this->skipped_links_regex) {
            foreach ($this->skipped_links_regex as $skipped_links_regex) {
                if (preg_match($skipped_links_regex, $clean_url)) {
                    $return->message = "matches skipped links regex";
                    return $return;
                }
            }
        }
        if (strpos($clean_url, "//") === 0) {
            // protocol-relative URL; prepend with https: for get_headers()
            $clean_url = "https:" . $clean_url;
        } else if (!preg_match("/^http[s]?:\/\//i", $clean_url)) {
            // attempt to prefix relative URL with default HTTP host
            if (!$this->config->http_host) {
                $return->message = "local URL and no http_host specified";
                return $return;
            }
            return $this->isCheckableURL($this->config->http_host . "/" . ltrim($clean_url, "/"), $page);
        }
        if (!filter_var(str_replace("-", "", $clean_url), FILTER_VALIDATE_URL)) {
            // note: here we attempt to circumvent a PHP 5.3.2 bug affecting
            // domains with dashes (https://bugs.php.net/bug.php?id=51192)
            $return->message = "URL didn't pass FILTER_VALIDATE_URL";
            return $return;
        }
        if (in_array($clean_url, array_keys($this->checked_links))) {
            // compare URL to list of links already checked, continue
            // (and store a row to junction table) if match is found
            $this->stmt_insert_links_pages->bindValue(':links_id', $this->checked_links[$clean_url], PDO::PARAM_INT);
            $this->stmt_insert_links_pages->bindValue(':pages_id', $page->id, PDO::PARAM_INT);
            $this->stmt_insert_links_pages->execute();
            $return->message = "already checked";
            $return->unique = false;
            return $return;
        }
        unset($this->skipped_links[$url]);
        $return->value = $clean_url;
        $return->status = true;
        return $return;
    }

    /**
     * Get HTTP headers for an URL
     * 
     * Convenience method and wrapper; grabs HTTP headers for given URL and
     * returns them modified to better suit the needs of this class.
     * 
     * @param string $url
     * @return array array of headers
     */
    protected function getHeaders($url) {
        // throttle requests to avoid unnecessary (local and external) load
        if (count($this->checked_links) && $this->config->sleep_between_requests) {
            usleep($this->config->sleep_between_requests);
        }
        // fetch headers for an URL using PHP's native get_headers() method
        $headers = @get_headers($url, 1);
        // get_headers() returns false on unsuccesful request, such as for
        // nonexisting domain, in which case we'll return placeholder array
        if ($headers === false) return array(
            'status' => null,
            'location' => null,
        );
        // headers were succesfully retrieved; normalize key cases, parse status
        // code part from status header and make sure that key 'location' exists
        $headers = array_change_key_case($headers);
        $headers['status'] = substr($headers[0], 9, 3);
        if (!isset($headers['location'])) {
            $headers['location'] = null;
        } else if (!is_null($headers['location']) && !preg_match("/^http[s]?:\/\//i", $headers['location'])) {
            // prefix relative location URLs with hostname
            $parts = parse_url($url);
            $prefix = (isset($parts['scheme']) ? $parts['scheme'] : "http") . "://";
            if (isset($parts['host'])) $prefix .= $parts['host'];
            else if (isset($parts['path'])) $prefix .= $parts['path']; // PHP < 5.4.7
            $headers['location'] = $prefix . $headers['location'];
        }
        return $headers;
    }

    /**
     * Write message to log file
     * 
     * This is a wrapper for ProcessWire's $log->save() function, mainly added
     * to keep code clean and provide varying logging levels etc.
     * 
     * @param string|array $message
     * @param int $log_level
     * @param bool $log_always
     * @return string log filename
     */
    protected function log($message = null, $log_level = 1, $log_always = false) {
        if ($message && ($log_always || $this->config->log_level >= $log_level)) {
            if ($log_always && $log_level > $this->config->log_level) {
                $log_level = $this->config->log_level ? $this->config->log_level+1 : 1;
            }
            if (is_array($message)) {
                // recursive logging
                foreach ($message as $part) {
                    $this->log($part, $log_level, $log_always);
                }
            } else {
                $padding = str_repeat(" ", 4 * (int) $log_level);
                @$this->wire->log->save(self::LOG_FILE, $padding . $message);
                if ($this->config->log_on_screen && ($this->config->log_on_screen !== 'log_always' || $log_always)) {
                    // log messages on screen
                    echo date("Y-m-d H:i:s") . "\t" . $this->wire->user->name . "\t" . $padding . $message . "\n";
                    flush();
                    ob_flush();
                }
            }
        }
        return $this->wire->log->getFilename(self::LOG_FILE);
    }

    /**
     * Rotate and/or remove old log files
     *
     */
    protected function logRotate() {
        $log_rotate = $this->config->log_rotate;
        $log_file = $this->log();
        if ($log_rotate) {
            $i = $log_rotate;
            while (file_exists($log_file . ".$i")) {
                @unlink($log_file . ".$i");
                ++$i;
            }
            for ($i = $log_rotate-1; $i > -1; --$i) {
                $old_log_file = $log_file . ($i > 0 ? ".$i" : "");
                $new_log_file = $log_file . "." . ($i+1);
                if (file_exists($old_log_file)) {
                    @rename($old_log_file, $new_log_file);
                }
            }
        } else {
            @unlink($log_file);
            $i = 1;
            while (file_exists($log_file . ".$i")) {
                @unlink($log_file . ".$i");
                ++$i;
            }
        }
    }

    /**
     * Get value of property or config setting (or null if neither exists)
     * 
     * @param string $name property or config setting name
     * @return mixed property or config setting value (or null)
     */
    public function __get($name) {
        if (isset($this->{$name})) return $this->{$name};
        if (isset($this->config->{$name})) return $this->config->{$name};
        return false;
    }

    /**
     * Set value of config setting
     * 
     * @param string|array $name config setting name or array of key/value pairs
     * @param mixed $value config setting value
     * @throws Exception if $name param is invalid
     */
    public function setConfig($name, $value = null) {
        if (is_string($name)) {
            $this->config->{$name} = $value;
        } else if (is_array($name) && count($name)) {
            foreach ($name as $key => $value) {
                $this->setConfig($key, $value);
            }
        } else {
            throw new Exception("Invalid 'name' param");
        }
    }

    /**
     * Flush skipped links cache
     * 
     * @param bool $expired_only Flush expired rows only?
     * @return bool|PDOStatement False on failure, PDOStatement on success
     */
    public function flushLinkCache($expired_only = false) {
        $interval = "";
        if ($expired_only) {
            $cache_interval = $this->wire->database->escapeStr($this->config->cache_max_age);
            $interval = "AND links.checked < DATE_SUB(NOW(), INTERVAL $cache_interval) ";
        }
        $return = $this->wire->database->query("
            DELETE links, links_pages 
            FROM " . self::TABLE_LINKS . " links, " . self::TABLE_LINKS_PAGES . " links_pages 
            WHERE links.skip = 0 " . $interval . "AND links_pages.links_id = links.id
        ");
        $this->buildLinkCache();
        return $return;
    }
    
    /**
     * Build skipped links cache
     * 
     * Merge skipped/cached links from database with defaults from config. This
     * method is automatically called during construct and after flushing cache.
     * 
     */
    protected function buildLinkCache() {
        $this->skipped_links_cache = $this->config->skipped_links ?: array();
        $this->skipped_links_cache = array_fill_keys($this->skipped_links_cache, null);
        $interval = $this->wire->database->escapeStr($this->config->cache_max_age);
        $query = $this->wire->database->query("SELECT url FROM " . self::TABLE_LINKS . " WHERE skip = 1 OR checked > DATE_SUB(NOW(), INTERVAL $interval)");
        $links = $query->fetchAll(PDO::FETCH_COLUMN);
        if (count($links)) {
            $this->skipped_links_cache = array_merge($this->skipped_links_cache, array_fill_keys($links, null));
        }
    }

    /**
     * Return ProcessWire class (helper method for 2.x and 3.x support)
     * 
     * @param string $name
     * @param array $arguments
     * @return mixed
     * @throws Exception if requested çlass doesn't exist
     */
    protected function getWireClass($name, $arguments = array()) {
        $class = $this->wire_namespace . basename($name);
        if (class_exists($class)) {
            $reflection_class = new ReflectionClass($class);
            return $reflection_class->newInstanceArgs($arguments);
        } else {
            throw new Exception("Class doesn't exist");
        }
    }

}
