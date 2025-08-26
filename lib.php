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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * authorize.net enrolment plugin.
 *
 * @package    enrol_authorizedotnet
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2021 DualCube (https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/enrol/authorizedotnet/classes/enrol_authorizedotnet_paymentprocess.php');

/**
 *  Plugin functions for the authorizedotnet plugin
 * @package    enrol_authorizedotnet
 * @author     DualCube <admin@dualcube.com>
 * @copyright  2021 DualCube (https://dualcube.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class enrol_authorizedotnet_plugin extends enrol_plugin {

    /**
     * Lists all currencies available for plugin.
     * @return $currencies
     */
    public function get_currencies() {
        $currencies = [
            'AUD' => new lang_string('AUD', 'core_currencies'),
            'USD' => new lang_string('USD', 'core_currencies'),
            'CAD' => new lang_string('CAD', 'core_currencies'),
            'EUR' => new lang_string('EUR', 'core_currencies'),
            'GBP' => new lang_string('GBP', 'core_currencies'),
            'NZD' => new lang_string('NZD', 'core_currencies'),
        ];
        return $currencies;
    }

    /**
     * Returns optional enrolment information icons.
     *
     * This is used in course list for quick overview of enrolment options.
     *
     * We are not using single instance parameter because sometimes
     * we might want to prevent icon repetition when multiple instances
     * of one type exist. One instance may also produce several icons.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     */
    public function get_info_icons(array $instances) {
        return [new pix_icon('icon', get_string('pluginname', 'enrol_authorizedotnet'), 'enrol_authorizedotnet')];
    }

    /**
     * Lists all protected user roles.
     * @return bool(true or false)
     */
    public function roles_protected() {
        // Users with role assign cap may tweak the roles later.
        return false;
    }

    /**
     * Defines if user can be unenrolled.
     * @param stdClass $instance of the plugin
     * @return bool(true or false)
     */
    public function allow_unenrol(stdClass $instance) {
        // Users with unenrol cap may unenrol other users manually - requires enrol/authorizedotnet:unenrol.
        return true;
    }

    /**
     * Defines if user can be managed from admin.
     * @param stdClass $instance of the plugin
     * @return bool(true or false)
     */
    public function allow_manage(stdClass $instance) {
        // Users with manage cap may tweak period and status - requires enrol/authorizedotnet:manage.
        return true;
    }

    /**
     * Defines if 'enrol me' link will be shown on course page.
     * @param stdClass $instance of the plugin
     * @return bool(true or false)
     */
    public function show_enrolme_link(stdClass $instance) {
        return ($instance->status == ENROL_INSTANCE_ENABLED);
    }

    /**
     * Adds navigation links into course admin block.
     *
     * By defaults looks for manage links only.
     *
     * @param navigation_node $instancesnode
     * @param stdClass $instance
     * @return void
     */
    public function add_course_navigation($instancesnode, stdClass $instance) {
        if ($instance->enrol !== 'authorizedotnet') {
             throw new coding_exception('Invalid enrol instance type!');
        }
        $context = context_course::instance($instance->courseid);
        if (has_capability('enrol/authorizedotnet:config', $context)) {
            $managelink = new moodle_url(
                '/enrol/editinstance.php',
                [
                    'courseid' => $instance->courseid,
                    'id' => $instance->id,
                    'type' => 'authorizedotnet'
                ]
            );
            $instancesnode->add($this->get_instance_name($instance), $managelink, navigation_node::TYPE_SETTING);
        }
    }

    /**
     * Returns edit icons for the page with list of instances
     * @param stdClass $instance
     * @return array
     */
    public function get_action_icons(stdClass $instance) {
        global $OUTPUT;
        if ($instance->enrol !== 'authorizedotnet') {
            throw new coding_exception('invalid enrol instance!');
        }
        $context = context_course::instance($instance->courseid);
        $icons = [];
        if (has_capability('enrol/authorizedotnet:config', $context)) {
            $editlink = new moodle_url(
                "/enrol/editinstance.php",
                [
                    'courseid' => $instance->courseid, 'id' => $instance->id, 'type' => 'authorizedotnet'
                ]
            );
            $icons[] = $OUTPUT->action_icon($editlink, new pix_icon('t/edit', get_string('edit'), 'core',
                    [
                        'class' => 'iconsmall'
                    ]
                )
            );
        }
        return $icons;
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     * @param int $courseid
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);
        if (!has_capability('moodle/course:enrolconfig', $context) || !has_capability('enrol/authorizedotnet:config', $context)) {
            return null;
        }
        // Multiple instances supported - different cost for different roles.
        return new moodle_url('/enrol/editinstance.php', ['courseid' => $courseid, 'type' => 'authorizedotnet']);
    }

    /**
     * Creates course enrol form, checks if form submitted
     * and enrols user if necessary. It can also redirect.
     *
     * @param stdClass $instance
     * @return string html text, usually a form in a text box
     */
    public function enrol_page_hook(stdClass $instance) {
        global $CFG, $USER, $OUTPUT, $DB, $PAGE;

        if ($DB->record_exists('user_enrolments', ['userid' => $USER->id, 'enrolid' => $instance->id])) {
            return '';
        }

        if ($instance->enrolstartdate != 0 && $instance->enrolstartdate > time()) {
            $message = get_string('canntenrolearly', 'enrol_authorizedotnet', userdate($instance->enrolstartdate));
            return $OUTPUT->notification($message, 'info');
        }

        if ($instance->enrolenddate != 0 && $instance->enrolenddate < time()) {
            $message = get_string('canntenrollate', 'enrol_authorizedotnet', userdate($instance->enrolenddate));
            return $OUTPUT->notification($message, 'error');
        }

        $course = $DB->get_record('course', ['id' => $instance->courseid]);
        $context = context_course::instance($course->id);

        if ((float) $instance->cost <= 0) {
            $cost = (float) $this->get_config('cost');
        } else {
            $cost = (float) $instance->cost;
        }

        if (abs($cost) < 0.01) {
            return $OUTPUT->notification(get_string('nocost', 'enrol_authorizedotnet'));
        }

        $localisedcost = format_float($cost, 2, true);

        if (isguestuser()) {
            $wwwroot = empty($CFG->loginhttps) ? $CFG->wwwroot : str_replace('http://', 'https://', $CFG->wwwroot);
            $output = '<div class="mdl-align"><p>'.get_string('paymentrequired').'</p>';
            $output .= '<p><b>'.get_string('cost').": $instance->currency $localisedcost".'</b></p>';
            $output .= '<p><a href="'.$wwwroot.'/login/">'.get_string('loginsite').'</a></p>';
            $output .= '</div>';
            return $OUTPUT->box($output);
        }

        $user = $DB->get_record('user', ['id' => $USER->id]);

        $templatedata = [
            'coursename' => format_string($course->fullname, true, ['context' => $context]),
            'cost' => $cost,
            'currency' => $instance->currency,
            'localisedcost' => $localisedcost,
            'user' => $user,
            'instance' => $instance,
            'wwwroot' => $CFG->wwwroot,
        ];

        $body = $OUTPUT->render_from_template('enrol_authorizedotnet/enrolment_form', $templatedata);
        
        $PAGE->requires->js_call_amd('enrol_authorizedotnet/payment', 'init',
            [
                $instance->id,
                $instance->courseid,
                $USER->id
            ]
        );

        return $OUTPUT->box($body);
    }

    /**
     * We are a good plugin and don't invent our own UI/validation code path.
     *
     * @return boolean
     */
    public function use_standard_editing_ui() {
        return true;
    }

    /**
     * Add elements to the edit instance form.
     *
     * @param stdClass $instance
     * @param MoodleQuickForm $mform
     * @param context $context
     * @return bool
     */
    public function edit_instance_form($instance, MoodleQuickForm $mform, $context) {
        $mform->addElement('text', 'name', get_string('custominstancename', 'enrol'));
        $mform->setType('name', PARAM_TEXT);

        $options = [ENROL_INSTANCE_ENABLED  => get_string('yes'),
                         ENROL_INSTANCE_DISABLED => get_string('no')];
        $mform->addElement('select', 'status', get_string('status', 'enrol_authorizedotnet'), $options);
        $mform->setDefault('status', $this->get_config('status'));

        $mform->addElement('text', 'cost', get_string('cost', 'enrol_authorizedotnet'), ['size' => 4]);
        $mform->setType('cost', PARAM_RAW); // Use unformat_float to get real value.
        $mform->setDefault('cost', format_float($this->get_config('cost'), 2, true));

        $currencies = $this->get_currencies();
        $mform->addElement('select', 'currency', get_string('currency', 'enrol_authorizedotnet'), $currencies);
        $mform->setDefault('currency', $this->get_config('currency'));

        if ($instance->id) {
            $roles = get_default_enrol_roles($context, $instance->roleid);
        } else {
            $roles = get_default_enrol_roles($context, $this->get_config('roleid'));
        }
        $mform->addElement('select', 'roleid', get_string('assignrole', 'enrol_authorizedotnet'), $roles);
        $mform->setDefault('roleid', $this->get_config('roleid'));

        $mform->addElement('duration', 'enrolperiod', get_string('enrolperiod', 'enrol_authorizedotnet'),
                           ['optional' => true, 'defaultunit' => 86400]);
        $mform->setDefault('enrolperiod', $this->get_config('enrolperiod'));
        $mform->addHelpButton('enrolperiod', 'enrolperiod', 'enrol_authorizedotnet');

        $mform->addElement('date_time_selector', 'enrolstartdate', get_string('enrolstartdate', 'enrol_authorizedotnet'),
                           ['optional' => true]);
        $mform->setDefault('enrolstartdate', 0);
        $mform->addHelpButton('enrolstartdate', 'enrolstartdate', 'enrol_authorizedotnet');

        $mform->addElement('date_time_selector', 'enrolenddate', get_string('enrolenddate', 'enrol_authorizedotnet'),
                           ['optional' => true]);
        $mform->setDefault('enrolenddate', 0);
        $mform->addHelpButton('enrolenddate', 'enrolenddate', 'enrol_authorizedotnet');

        if (enrol_accessing_via_instance($instance)) {
            $mform->addElement('static', 'selfwarn', get_string('instanceeditselfwarning', 'core_enrol'),
                                get_string('instanceeditselfwarningtext', 'core_enrol'));
        }
    }

    /**
     * Perform custom validation of the data used to edit the instance.
     *
     * @param array $data array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @return array of "element_name"=>"error_description" if there are errors,
     *         or an empty array if everything is OK.
     */
    public function edit_instance_validation($data, $files, $instance, $context) {
        $errors = [];

        if (!empty($data['enrolenddate']) && $data['enrolenddate'] < $data['enrolstartdate']) {
            $errors['enrolenddate'] = get_string('enrolenddaterror', 'enrol_authorizedotnet');
        }

        $cost = str_replace(get_string('decsep', 'langconfig'), '.', $data['cost']);
        if (!is_numeric($cost)) {
            $errors['cost'] = get_string('costerror', 'enrol_authorizedotnet');
        }
        return $errors;
    }

    /**
     * Restore instance and map settings.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $course
     * @param int $oldid
     */
    public function restore_instance(restore_enrolments_structure_step $step, stdClass $data, $course, $oldid) {
        global $DB;
        if (!$step->get_task()->get_target() == backup::TARGET_NEW_COURSE) {
            if($instances=$DB->get_records(
                    'enrol',
                    [
                        'courseid'   => $data->courseid,
                        'enrol'      => $this->get_name(),
                        'roleid'     => $data->roleid,
                        'cost'       => $data->cost,
                        'currency'   => $data->currency,
                    ],
                    'id'
                )
            ){
                $instance = reset($instances);
                $instanceid = $instance->id;
            }
        }
        $instanceid = $this->add_instance($course, (array)$data);
        $step->set_mapping('enrol', $oldid, $instanceid);
    }

    /**
     * Restore user enrolment.
     *
     * @param restore_enrolments_structure_step $step
     * @param stdClass $data
     * @param stdClass $instance
     * @param int $userid
     * @param int $oldinstancestatus
     */
    public function restore_user_enrolment(restore_enrolments_structure_step $step, $data, $instance, $userid, $oldinstancestatus) {
        $this->enrol_user($instance, $userid, null, $data->timestart, $data->timeend, $data->status);
    }

    /**
     * Gets an array of the user enrolment actions
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue A user enrolment object
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue) {
        $actions = [];
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol($instance) && has_capability("enrol/authorizedotnet:unenrol", $context)) {
            $url = new moodle_url('/enrol/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''), get_string('unenrol', 'enrol'), $url,
                                                   ['class' => 'unenrollink', 'rel' => $ue->id]);
        }
        if ($this->allow_manage($instance) && has_capability("enrol/authorizedotnet:manage", $context)) {
            $url = new moodle_url('/enrol/editenrolment.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/edit', ''), get_string('edit'), $url,
                                                   ['class' => 'editenrollink', 'rel' => $ue->id]);
        }
        return $actions;
    }

    /**
     * Set up cron for the plugin (if any).
     *
     */
    public function cron() {
        $trace = new text_progress_trace();
        $this->process_expirations($trace);
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/authorizedotnet:config', $context);
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/authorizedotnet:config', $context);
    }
}