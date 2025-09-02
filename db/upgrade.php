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
 * Upgrade script for the authorizedotnet enrolment plugin.
 *
 * @package    enrol_authorizedotnet
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2021 DualCube (https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

function xmldb_enrol_authorizedotnet_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // The version number should be greater than the last version in install.xml
    // and any previous upgrade scripts. We will use a version after the one you provided.
    if ($oldversion < 2025083109) {

        // Define table enrol_authorizedotnet to be modified.
        $table = new xmldb_table('enrol_authorizedotnet');

        // Drop redundant fields that are not used with the new Accept.js API.
        $fields_to_drop = [
            'tax',
            'duty',
            'method',
            'account_number',
            'card_type',
            'fax',
            'state',
        ];

        foreach ($fields_to_drop as $fieldname) {
            if ($dbman->field_exists($table, $fieldname)) {
                $dbman->drop_field($table, new xmldb_field($fieldname));
            }
        }

        $field = new xmldb_field('response_code', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        // 2. Widen response_reason_code.
        $field = new xmldb_field('response_reason_code', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }

        // 3. Widen auth_code.
        $field = new xmldb_field('auth_code', XMLDB_TYPE_CHAR, '30', null, null, null, null);
        if ($dbman->field_exists($table, $field)) {
            $dbman->change_field_precision($table, $field);
        }


        // Update plugin version.
        upgrade_plugin_savepoint(true, 2025083109, 'enrol', 'authorizedotnet');
    }

    return true;
}
