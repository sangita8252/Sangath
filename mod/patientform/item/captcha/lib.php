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

defined('MOODLE_INTERNAL') OR die('not allowed');
require_once($CFG->dirroot.'/mod/patientform/item/patientform_item_class.php');

class patientform_item_captcha extends patientform_item_base {
    protected $type = "captcha";

    public function build_editform($item, $patientform, $cm) {
        global $DB;

        $editurl = new moodle_url('/mod/patientform/edit.php', array('id'=>$cm->id));

        //ther are no settings for recaptcha
        if (isset($item->id) AND $item->id > 0) {
            notice(get_string('there_are_no_settings_for_recaptcha', 'patientform'), $editurl->out());
            exit;
        }

        //only one recaptcha can be in a patientform
        $params = array('patientform' => $patientform->id, 'typ' => $this->type);
        if ($DB->record_exists('patientform_item', $params)) {
            notice(get_string('only_one_captcha_allowed', 'patientform'), $editurl->out());
            exit;
        }

        $this->item = $item;
        $this->item_form = true; //dummy

        $lastposition = $DB->count_records('patientform_item', array('patientform'=>$patientform->id));

        $this->item->patientform = $patientform->id;
        $this->item->template = 0;
        $this->item->name = get_string('captcha', 'patientform');
        $this->item->label = '';
        $this->item->presentation = '';
        $this->item->typ = $this->type;
        $this->item->hasvalue = $this->get_hasvalue();
        $this->item->position = $lastposition + 1;
        $this->item->required = 1;
        $this->item->dependitem = 0;
        $this->item->dependvalue = '';
        $this->item->options = '';
    }

    public function show_editform() {
    }

    public function is_cancelled() {
        return false;
    }

    public function get_data() {
        return true;
    }

    public function save_item() {
        global $DB;

        if (!$this->item) {
            return false;
        }

        if (empty($this->item->id)) {
            $this->item->id = $DB->insert_record('patientform_item', $this->item);
        } else {
            $DB->update_record('patientform_item', $this->item);
        }

        return $DB->get_record('patientform_item', array('id'=>$this->item->id));
    }

    public function get_printval($item, $value) {
        return '';
    }

    public function print_analysed($item, $itemnr = '', $groupid = false, $courseid = false) {
        return $itemnr;
    }

    public function excelprint_item(&$worksheet, $row_offset,
                             $xls_formats, $item,
                             $groupid, $courseid = false) {
        return $row_offset;
    }

    /**
     * Returns the formatted name of the item for the complete form or response view
     *
     * @param stdClass $item
     * @param bool $withpostfix
     * @return string
     */
    public function get_display_name($item, $withpostfix = true) {
        return get_string('captcha', 'patientform');
    }

    /**
     * Adds an input element to the complete form
     *
     * @param stdClass $item
     * @param mod_patient_complete_form $form
     */
    public function complete_form_element($item, $form) {
        $name = $this->get_display_name($item);
        $inputname = $item->typ . '_' . $item->id;

        if ($form->get_mode() != mod_patientform_complete_form::MODE_COMPLETE) {
            // Span to hold the element id. The id is used for drag and drop reordering.
            $form->add_form_element($item,
                    ['static', $inputname, $name, html_writer::span('', '', ['id' => 'patientform_item_' . $item->id])],
                    false,
                    false);
        } else {
            // Add recaptcha element that is used during the form validation.
            $form->add_form_element($item,
                    ['recaptcha', $inputname . 'recaptcha', $name],
                    false,
                    false);
            // Add hidden element with value "1" that will be saved in the values table after completion.
            $form->add_form_element($item, ['hidden', $inputname, 1], false);
            $form->set_element_type($inputname, PARAM_INT);
        }

        // Add recaptcha validation to the form.
        $form->add_validation_rule(function($values, $files) use ($item, $form) {
            $elementname = $item->typ . '_' . $item->id . 'recaptcha';
            $recaptchaelement = $form->get_form_element($elementname);
            if (empty($values['recaptcha_response_field'])) {
                return array($elementname => get_string('required'));
            } else if (!empty($values['recaptcha_challenge_field'])) {
                $challengefield = $values['recaptcha_challenge_field'];
                $responsefield = $values['recaptcha_response_field'];
                if (true !== ($result = $recaptchaelement->verify($challengefield, $responsefield))) {
                    return array($elementname => $result);
                }
            } else {
                return array($elementname => get_string('missingrecaptchachallengefield'));
            }
            return true;
        });

    }

    public function create_value($data) {
        global $USER;
        return $USER->sesskey;
    }

    public function get_hasvalue() {
        global $CFG;

        //is recaptcha configured in moodle?
        if (empty($CFG->recaptchaprivatekey) OR empty($CFG->recaptchapublickey)) {
            return 0;
        }
        return 1;
    }

    public function can_switch_require() {
        return false;
    }

    /**
     * Returns the list of actions allowed on this item in the edit mode
     *
     * @param stdClass $item
     * @param stdClass $patientform
     * @param cm_info $cm
     * @return action_menu_link[]
     */
    public function edit_actions($item, $patientform, $cm) {
        $actions = parent::edit_actions($item, $patientform, $cm);
        unset($actions['update']);
        return $actions;
    }

    public function get_data_for_external($item) {
        global $CFG;

        if (empty($CFG->recaptchaprivatekey) || empty($CFG->recaptchapublickey)) {
            return null;
        }

        require_once($CFG->libdir . '/recaptchalib.php');
        // We return the public key, maybe we want to use the javascript api to get the image.
        $data = recaptcha_get_challenge_hash_and_urls(RECAPTCHA_API_SECURE_SERVER, $CFG->recaptchapublickey);
        $data[] = $CFG->recaptchapublickey;
        return json_encode($data);
    }

    /**
     * Return the analysis data ready for external functions.
     *
     * @param stdClass $item     the item (question) information
     * @param int      $groupid  the group id to filter data (optional)
     * @param int      $courseid the course id (optional)
     * @return array an array of data with non scalar types json encoded
     * @since  Moodle 3.3
     */
    public function get_analysed_for_external($item, $groupid = false, $courseid = false) {
        return [];
    }
}
