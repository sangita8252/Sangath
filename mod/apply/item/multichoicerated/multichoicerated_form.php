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

require_once($CFG->dirroot.'/mod/apply/item/apply_item_form_class.php');

class apply_multichoicerated_form extends apply_item_form
{
    protected $type = "multichoicerated";

    public function definition()
	{
		global $OUTPUT;

        $item = $this->_customdata['item'];
        $common = $this->_customdata['common'];
        $positionlist = $this->_customdata['positionlist'];
        $position = $this->_customdata['position'];

        $mform =& $this->_form;

        $mform->addElement('header', 'general', get_string($this->type, 'apply'));
        $mform->addElement('advcheckbox', 'required', get_string('required', 'apply'), '' , null , array(0, 1));
        $mform->addElement('text', 'name',  get_string('item_name',  'apply'), array('size'=>APPLY_ITEM_NAME_TEXTBOX_SIZE,  'maxlength'=>255));
		$label_help = ' '.$OUTPUT->help_icon('item_label', 'apply');
        $mform->addElement('text', 'label', get_string('item_label', 'apply').$label_help, array('size'=>APPLY_ITEM_LABEL_TEXTBOX_SIZE, 'maxlength'=>255));
        $params = array(0=>get_string('vertical', 'apply'), 1=>get_string('horizontal', 'apply'));
        $mform->addElement('select', 'horizontal', get_string('adjustment', 'apply').'&nbsp;', $params);
        $params = array('r'=>get_string('radio', 'apply'), 'd'=>get_string('dropdown', 'apply'));
        $mform->addElement('select', 'subtype', get_string('multichoicetype', 'apply').'&nbsp;', $params);
        $mform->addElement('selectyesno', 'ignoreempty', get_string('do_not_analyse_empty_submits', 'apply'));
        $mform->addElement('selectyesno', 'hidenoselect', get_string('hide_no_select_option', 'apply'));
        $mform->addElement('static', 'hint', get_string('multichoice_values', 'apply'), get_string('use_one_line_for_each_value', 'apply'));

        $this->values = $mform->addElement('textarea', 'values', '', 'wrap="virtual" rows="10" cols="65"');

        parent::definition();
        $this->set_data($item);

    }

    public function set_data($item)
	{
        $info = $this->_customdata['info'];

        $item->horizontal = $info->horizontal;
        $item->subtype = $info->subtype;
        $item->values = $info->values;

        return parent::set_data($item);
    }

    public function get_data()
	{
        if (!$item = parent::get_data()) {
            return false;
        }

        $itemobj = new apply_item_multichoicerated();

        $presentation = $itemobj->prepare_presentation_values_save(trim($item->values), APPLY_MULTICHOICERATED_VALUE_SEP2, APPLY_MULTICHOICERATED_VALUE_SEP);
        if (!isset($item->subtype)) {
            $subtype = 'r';
        }
		else {
            $subtype = substr($item->subtype, 0, 1);
        }
        if (isset($item->horizontal) AND $item->horizontal == 1 AND $subtype != 'd') {
            $presentation .= APPLY_MULTICHOICERATED_ADJUST_SEP.'1';
        }
        $item->presentation = $subtype.APPLY_MULTICHOICERATED_TYPE_SEP.$presentation;
        return $item;
    }
}
