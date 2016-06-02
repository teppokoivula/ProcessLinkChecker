<?php

/**
 * Checkable Value
 * 
 * Used as a return value for isCheckable* methods in Link Crawler.
 *
 * @author Teppo Koivula <teppo.koivula@gmail.com>
 * @copyright Copyright (c) 2015-2016, Teppo Koivula
 * @license http://www.gnu.org/licenses/gpl-2.0.txt GNU General Public License, version 2
 * @version 0.7.1
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
     * Uniqueness of the value ("is this value being checked for the first time?")
     *
     */
    public $unique = true;

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
