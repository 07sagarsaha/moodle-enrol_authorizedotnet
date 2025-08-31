<?php

function xmldb_enrol_authorizedotnet_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025083100) {

        // Define table enrol_authorizedotnet to be modified.
        $table = new xmldb_table('enrol_authorizedotnet');

        // Define field payment_status to be modified.
        $field = new xmldb_field('payment_status', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'duty');

        // Launch change of field payment_status.
        $dbman->change_field_type($table, $field);

        // Update plugin version.
        upgrade_plugin_savepoint(true, 2025083100, 'enrol', 'authorizedotnet');
    }

    return true;
}
