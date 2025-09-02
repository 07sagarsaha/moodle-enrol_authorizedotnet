<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Authorize.Net enrolment plugin external functions.
 *
 * @package    enrol_authorizedotnet
 * @author     Your Name <your@email.com>
 * @copyright  2025 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'enrol_authorizedotnet_get_config_for_js' => [
        'classname'   => 'enrol_authorizedotnet_externallib',
        'methodname'  => 'get_config_for_js',
        'description' => 'Get the configuration necessary for the client-side JavaScript.',
        'type'        => 'read',
        'ajax'        => true,
    ],
    'enrol_authorizedotnet_process_payment' => [
        'classname'   => 'enrol_authorizedotnet_externallib',
        'methodname'  => 'process_payment',
        'description' => 'Process a payment with opaque data from Authorize.net.',
        'type'        => 'write',
        'ajax'        => true,
    ],
];