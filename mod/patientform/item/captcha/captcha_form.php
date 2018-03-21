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

require_once($CFG->dirroot.'/mod/patientform/item/patientform_item_form_class.php');

class patientform_captcha_form extends patientform_item_form {
    protected $type = "captcha";

    public function definition() {

        $item = $this->_customdata['item'];
        $common = $this->_customdata['common'];
        $positionlist = $this->_customdata['positionlist'];
        $position = $this->_customdata['position'];

        $mform =& $this->_form;

        $mform->addElement('header', 'general', get_string($this->type, 'patientform'));
        $mform->addElement('advcheckbox', 'required', get_string('required', 'patientform'), '' , null , array(0, 1));
        $mform->addElement('text',
                            'name',
                            get_string('item_name', 'patientform'),
                            array('size'=>PATIENTFORM_ITEM_NAME_TEXTBOX_SIZE, 'maxlength'=>255));
        $mform->addElement('text',
                            'label',
                            get_string('item_label', 'patientform'),
                            array('size'=>PATIENTFORM_ITEM_LABEL_TEXTBOX_SIZE, 'maxlength'=>255));

        $mform->addElement('select',
                            'presentation',
                            get_string('count_of_nums', 'patientform').'&nbsp;',
                            array_slice(range(0, 10), 3, 10, true));

        parent::definition();
        $this->set_data($item);

    }
}

