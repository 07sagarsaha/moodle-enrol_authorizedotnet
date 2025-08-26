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
 * Services definition for authorizedotnet.
 *
 * @package    enrol_authorizedotnet
 * @author     DualCube
 * @copyright  2024 DualCube
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'enrol_authorizedotnet_process_payment' => [
        'classname'   => 'enrol_authorizedotnet_external',
        'methodname'  => 'process_payment',
        'classpath'   => 'enrol/authorizedotnet/externallib.php',
        'description' => 'Process a payment for authorizedotnet enrolment.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'enrol_authorizedotnet_get_hosted_form_token' => [
        'classname'   => 'enrol_authorizedotnet_external',
        'methodname'  => 'get_hosted_form_token',
        'classpath'   => 'enrol/authorizedotnet/externallib.php',
        'description' => 'Generates a hosted payment form token for the client.',
        'type'        => 'write',
        'ajax'        => true,
    ],
    'enrol_authorizedotnet_finalize_enrollment' => [
        'classname'   => 'enrol_authorizedotnet_external',
        'methodname'  => 'finalize_enrollment',
        'classpath'   => 'enrol/authorizedotnet/externallib.php',
        'description' => 'Finalizes the enrollment after a successful payment.',
        'type'        => 'write',
        'ajax'        => true,
    ],
];
