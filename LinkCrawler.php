<?php

/**
 * Link Crawler
 * 
 * Link Crawler is intended to work with ProcessWire CMS/CMF and capture any
 * checkable links from rendered page content, identifying broken and/or
 * otherwise problematic links (redirects, server issues etc.)
 * 
 * Link Crawler doesn't provide any sort of API or UI itself. Instead it writes
 * it's output data to database tables, delegating such features to ProcessWire
 * module Process Link Checker.
 * 
 * @todo consider storing link texts to pages table (SEO analysis etc.)
 * @todo consider adding (separate) domain-based throttling
 * @todo consider adding support for regexp skip links
 * 
 * @author Teppo Koivula <teppo.koivula@gmail.com>
 * @copyright Copyright (c) 2014-2015, Teppo Koivula
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License, version 2
 * @version 0.4.9
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
        'status' => array(
            '1xx' => 0,
            '2xx' => 0,
            '3xx' => 0,
            '4xx' => 0,
            '5xx' => 0,
        ),
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
     * Render method
     * 
     * Render method is only configurable by editing this file at the moment,
     * for various safety and compatibility reasons. Available options:
     *     - render_page
     *     - render_fields
     *     - shell_exec
     */
    protected $render_method = 'render_page';
    
    /**
     * Additional settings for the 'shell_exec' render method
     * 
     * Render file is the path of the PHP file used to render pages, while PHP
     * binary is the path of the PHP binary itself.
     *
     */
    protected $render_file = null;
    protected $php_binary = '/usr/bin/php';
    
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
     * Placeholders for prepared PDO statements
     *
     */
    protected $stmt_select_id = null;
    protected $stmt_insert_links = null;
    protected $stmt_insert_links_pages = null;

    /**
     * Constants containing names of used database tables
     * 
     */
    const TABLE_LINKS = 'link_checker_links';
    const TABLE_LINKS_PAGES = 'link_checker_links_pages';
    
    /**
     * Fetch config settings from ProcessWire module and bootstrap ProcessWire
     * 
     * This class gets it's config and it's data from ProcessWire, so a working
     * instance of ProcessWire really is required here. Whether such ties are a
     * necessity in the long run remains to be seen.
     * 
     * @param array $options for overwriting defaults and/or module settings
     * @param string $root ProcessWire root path
     * @throws Exception if link_regex isn't set
     * @throws Exception if link_regex is set but invalid
     * @throws Exception if skip_link_regex is set but invalid
     */
    public function __construct($options = array(), $root = null) {
        // unless ProcessWire is already available, bootstrap from a pre-defined
        // root path or dynamically built one (based on current directory)
        if (!defined("PROCESSWIRE")) {
            if (is_null($root)) $root = substr(__DIR__, 0, strrpos(__DIR__, "/modules")) . "/..";
            require rtrim($root, "/") . "/index.php";
        }
        $this->root = $root;
        // setup config object
        $default_data = ProcessLinkChecker::getDefaultData();
        $data = wire('modules')->getModuleConfigData('ProcessLinkChecker');
        $this->config = (object) array_merge($default_data, $data, $options);
        // link regex is required
        if (!$this->config->link_regex) {
            throw new Exception("link_regex is required");
        }
        // validate regex settings with more regex
        $valid_regex = '/(^[^\w\s\\\]|_).*\1([imsxADSUXJu]*)$/';
        if (!preg_match($valid_regex, $this->link_regex)) {
            throw new Exception("invalid link_regex");
        }
        if ($this->skip_link_regex && !preg_match($valid_regex, $this->skip_link_regex)) {
            throw new Exception("invalid skip_link_regex");
        }
        // merge skipped and cached links from database with defaults
        if (!$this->config->skipped_links) $this->config->skipped_links = array();
        $this->config->skipped_links = array_fill_keys($this->config->skipped_links, null);
        $interval = wire('database')->escapeStr($this->config->cache_max_age);
        $query = wire('database')->query("SELECT url FROM " . self::TABLE_LINKS . " WHERE skip = 1 OR checked > DATE_SUB(NOW(), INTERVAL $interval)");
        $links = $query->fetchAll(PDO::FETCH_COLUMN);
        if (count($links)) {
            $this->config->skipped_links = array_merge($this->config->skipped_links, array_fill_keys($links, null));
        }
        // support fractions of seconds in request throttling
        if ($this->config->sleep_between_requests) {
            $this->config->sleep_between_requests = round($this->config->sleep_between_requests*1000000);
        }
        if ($this->config->sleep_between_pages) {
            $this->config->sleep_between_pages = round($this->config->sleep_between_pages*1000000);
        }
        // set default stream context options for get_headers()
        // @todo do we really want to implement custom code to follow redirects?
        stream_context_set_default(array(
            'http' => array(
                'follow_location' => 0,
                'max_redirects' => 0,
                'user_agent' => $this->config->http_user_agent,
            ),
        ));
        // prepare PDO statements for later use
        $this->stmt_select_id = wire('database')->prepare("SELECT id FROM " . self::TABLE_LINKS . " WHERE url = :url LIMIT 1");
        $this->stmt_insert_links = wire('database')->prepare("INSERT INTO " . self::TABLE_LINKS . " (url, status, location) VALUES (:url, :status, :location) ON DUPLICATE KEY UPDATE url = VALUES(url), status = VALUES(status), location = VALUES(location), checked = NOW()");
        $this->stmt_insert_links_pages = wire('database')->prepare("INSERT INTO " . self::TABLE_LINKS_PAGES . " (links_id, pages_id) VALUES (:links_id, :pages_id) ON DUPLICATE KEY UPDATE links_id = VALUES(links_id), pages_id = VALUES(pages_id)");
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
        $interval = wire('database')->escapeStr($this->config->cache_max_age);
        wire('database')->query("
            DELETE links, links_pages 
            FROM " . self::TABLE_LINKS . " links, " . self::TABLE_LINKS_PAGES . " links_pages 
            WHERE links.skip = 0 AND links.checked < DATE_SUB(NOW(), INTERVAL $interval) AND links_pages.links_id = links.id
        ");
        // find and check pages matching selector
        $start = 0;
        $start_selector = null;
        $selectors = new Selectors($this->config->selector);
        $this->stats['pages'] = wire('pages')->count($selectors);
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
        $limit_selector = new SelectorEqual('limit', $this->batch_size);
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
                $limit_selector = new SelectorEqual('limit', $this->batch_size + ($limit - $batch_pages));
                $selectors->add($limit_selector);
            }
            if ($start_selector) $selectors->remove($start_selector);
            $start_selector = new SelectorEqual('start', $start);
            $selectors->add($start_selector);
            $this->log(sprintf(
                "BATCH: %d/%d (pages %d-%d/%d)",
                $batch+1,
                $batches+1,
                $start_selector->value,
                $limit_selector->value,
                $limit
            ));
            $pages = wire('pages')->find($selectors);
            foreach ($pages as $page) {
                $this->log("FOUND Page: {$page->url}", 2);
                wire('pages')->uncacheAll();
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
        if ((int) ($time = wireRelativeTimeStr($this->stats['time_start']))) {
            $this->stats['time_total'] = substr($time, 0, strpos($time, " ", strpos($time, " ")+1));
        }
        $this->log(sprintf(
            "END: %d/%d Pages and %d/%d links checked in %s. Status breakdown: %s.",
            $this->stats['pages_checked'],
            $this->stats['pages'],
            $this->stats['links_checked'],
            $this->stats['links'],
            $this->stats['time_total'],
            count($status_breakdown) ? implode(", ", $status_breakdown) : "unavailable (not enough data)"
        ));
    }

    /**
     * Check links found from rendered markup of given Page
     * 
     * This method receives an instance of ProcessWire Page, renders it and
     * attempts to capture and check all checkable links (URLs) in output.
     * 
     * @param Page $page
     * @return bool whether or not a Page was checked
     * @throws Exception if render method is shell_exec but PHP binary isn't defined
     * @throws Exception if render method is shell_exec but rener file isn't found
     * @throws Exception if render method is unrecognized
     * @todo check if fatal errors could be logged and/or sent to admin with shell_exec method
     */
    protected function checkPage(Page $page) {
        // skip admin pages and non-viewable pages
        if ($this->isCheckablePage($page) == "") return false;
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
            case 'shell_exec':
                if (!$this->php_binary) {
                    if (version_compare(PHP_VERSION, "5.4.0") >= 0 && defined("PHP_BINARY")) {
                        $this->php_binary = PHP_BINARY;
                    } else {
                        throw new Exception("PHP binary not defined");
                    }
                }
                if (!$this->render_file) {
                    $this->render_file = __DIR__ . '/Render.php';
                    if (!is_file($this->render_file)) {
                        throw new Exception("Render file not found");
                    }
                }
                $command = escapeshellcmd(sprintf(
                    '%s %s %s %s 2>/dev/null',
                    escapeshellarg($this->php_binary),
                    escapeshellarg($this->render_file),
                    escapeshellarg($this->root),
                    escapeshellarg((int) $page->id)
                ));
                $data = shell_exec($command);
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
                        switch (substr($status, 0, 1)) {
                            case 1: ++$this->stats['status']['1xx']; break;
                            case 2: ++$this->stats['status']['2xx']; break;
                            case 3: ++$this->stats['status']['3xx']; break;
                            case 4: ++$this->stats['status']['4xx']; break;
                            case 5: ++$this->stats['status']['5xx']; break;
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
     * @param Page $page Page containing URL, required for database logging
     * @return bool|string|null false if URL can't be checked, otherwise status code (can be null)
     */
    protected function checkURL($url, Page $page) {
        // make sure that URL is valid, not already checked etc.
        $checkable = $this->isCheckableURL($url, $page);
        if ($checkable == "") {
            $this->log("SKIPPED URL: {$url} ({$checkable->message})", 3);
            return false;
        }
        $final_url = $checkable->value;
        // new, checkable link found; grab status code, store link info
        // to database and cache link URL locally as a checked link
        $headers = $this->getHeaders($final_url);
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
                // @todo if get_headers() is allowed to follow_location, something like this is required:
                // if (is_array($rec_url)) $rec_url = array_pop($rec_url);
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
        $this->stmt_insert_links->bindValue(':url', $url, PDO::PARAM_STR);
        $this->stmt_insert_links->bindValue(':status', $headers['status'], PDO::PARAM_STR);
        $this->stmt_insert_links->bindValue(':location', $headers['location'], PDO::PARAM_STR);
        $this->stmt_insert_links->execute();
        $links_id = wire('database')->lastInsertId();
        $this->stmt_insert_links_pages->bindValue(':links_id', $links_id, PDO::PARAM_INT);
        $this->stmt_insert_links_pages->bindValue(':pages_id', $page->id, PDO::PARAM_INT);
        $this->stmt_insert_links_pages->execute();
        $this->checked_links[$final_url] = $links_id;
        $this->log("CHECKED URL: {$url}" . ($final_url != $url ? " [{$final_url}]" : "") . " ({$headers['status']}" . ($location ? " => {$location}" : "") . ")", 3);
        $this->log($log_queue, 4);
        return $headers['status'];
    }

    /**
     * Check if a Page can/should be checked
     * 
     * This method is currently very simple, but exists partly to support
     * addition of more complex rules for identifying checkable pages.
     * 
     * @param Page $page
     * @return CheckableValue
     */
    protected function isCheckablePage(Page $page) {
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
     * @param Page $page
     * @return CheckableValue
     */
    protected function isCheckableURL($url, $page) {
        if (strpos($url, ".") === 0) {
            // URL is relative to current page's path, expand before processing
            // @todo consider adding an option for skipping over URLs like this
            // @todo consider adding additional handling for "/./", "/../", etc.
            $url = $page->url . $url;
        }
        $return = new CheckableValue($url);
        if (isset($this->skipped_links[$url])) {
            // link has already been checked and found non-checkable
            $return->message = "found from run-time skipped links";
            return $return;
        }
        $this->skipped_links[$url] = true;
        if (strpos($url, wire('config')->urls->admin) === 0) {
            // admin URLs should always be skipped automatically
            $return->message = "admin URL";
            return $return;
        }
        if ($url == "." || $url == "./" || ($this->config->http_host && ($url == $this->config->http_host . $page->url))) {
            // skip links pointing to current page
            $return->message = "link points to current page";
            return $return;
        }
        if (preg_match("/^((?!https?:).*):/i", $url, $matches)) {
            // skip unsupported schemes
            $return->message = "unsupported scheme: {$matches[1]}";
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
        if (in_array($clean_url, array_keys($this->config->skipped_links))) {
            // compare URL to local skip list and continue if match is
            // found (but store a row to junction table nevertheless!)
            if (is_null($this->config->skipped_links[$clean_url])) {
                $this->stmt_select_id->bindValue(':url', $clean_url, PDO::PARAM_STR);
                $this->stmt_select_id->execute();
                $links_id = (int) $this->stmt_select_id->fetchColumn();
                $this->config->skipped_links[$clean_url] = $links_id ? $links_id : 0;
            }
            if ($this->config->skipped_links[$clean_url]) {
                $this->stmt_insert_links_pages->bindValue(':links_id', $this->config->skipped_links[$clean_url], PDO::PARAM_INT);
                $this->stmt_insert_links_pages->bindValue(':pages_id', $page->id, PDO::PARAM_INT);
                $this->stmt_insert_links_pages->execute();
            }
            $return->message = "found from skipped links";
            return $return;
        }
        if ($this->skip_link_regex && preg_match($this->skip_link_regex, $clean_url)) {
            $return->message = "matches skip link regex";
            return $return;
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
            return $this->isCheckableURL($this->config->http_host . ltrim($clean_url, "/"), $page);
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
            ++$this->stats['links_checked'];
            $return->message = "already checked";
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
     * This is a wrapper for ProcessWire's wire('log') function, mainly added
     * to keep code clean and provide varying logging levels etc.
     * 
     * @param string|array $message
     * @param int $log_level
     * @return string log filename
     */
    protected function log($message = null, $log_level = 1) {
        if ($this->config->log_level >= $log_level && $message) {
            if (is_array($message)) {
                // recursive logging
                foreach ($message as $part) {
                    $this->log($part, $log_level);
                }
            } else {
                $padding = str_repeat(" ", 4 * (int) $log_level);
                @wire('log')->save(strtolower(__CLASS__), $padding . $message);
                if ($this->config->log_on_screen) {
                    // log messages on screen
                    echo date("Y-m-d H:i:s") . "\t" . wire('user')->name . "\t" . $padding . $message . "\n";
                }
            }
        }
        return wire('log')->getFilename(strtolower(__CLASS__));
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
     * @param $name property or config setting name
     * @return mixed property or config setting value (or null)
     */
    public function __get($name) {
        if (isset($this->{$name})) return $this->{$name};
        if (isset($this->config->{$name})) return $this->config->{$name};
        return false;
    }

}

/**
 * Checkable Value
 * 
 * Used as a return value for isCheckable* methods in Link Crawler.
 *
 * @author Teppo Koivula <teppo.koivula@gmail.com>
 * @copyright Copyright (c) 2015, Teppo Koivula
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License, version 2
 * @version 0.5.0
 */
class CheckableValue {
    
    /**
     * Value of this object; target of the checkable test
     * 
     */
    public $value = "";

    /**
     * Checkable status of value ("is the value checkable?")
     *
     */
    public $status = false;

    /**
     * Message related to the status ("why is/isn't the value checkable?")
     *
     */
    public $message = "";

    /**
     * Constructor method
     * 
     * @param mixed $value
     */
    public function __construct($value) {
        $this->value = $value;
    }
    
    /**
     * When treated like a string, return value or empty, depending on status
     * 
     * @return string
     */
    public function __toString() {
        return $this->status ? (string) $this->value : "";
    }
}