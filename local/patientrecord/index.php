<?php

require_once('../../config.php');
$context = context_system::instance();

require_login();
if (!is_siteadmin()) {
    return '';

  
}

//$welcome = new \local_patientrecord\message();
$PAGE->set_context($context);
$PAGE->set_url('/local/patientrecord/index.php');
$PAGE->set_heading($SITE->fullname);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('pluginname', 'local_patientrecord'));
$PAGE->navbar->add(get_string('pluginname', 'local_patientrecord'));

$tableheader = array(
    get_string('fieldname', 'local_patientrecord'),
    get_string('yourvalue', 'local_patientrecord'));
$customfields = $welcome->customfields;
$customvalues = $welcome->get_user_custom_values($USER);

// Custom profile Fields.
$tablecustom = new html_table();
$tablecustom->head = $tableheader;

foreach ($customfields as $field) {
    $tablecustom->data[] = array('[['.$field.']]', $customvalues[$field]);
}

// Moodle welcome template Fields.
$tablewelcome = new html_table();
$tablecustom->head = $tableheader;

foreach ($welcome->welcomefields as $field) {
    $tablewelcome->data[] = array('[['.$field.']]', $welcome->welcomevalues[$field]);
}

// Moodle default user template Fields.
$tabledefault = new html_table();
$tabledefault->head = $tableheader;
$userdefaultvalues = $welcome->get_user_default_values($USER);

foreach ($welcome->defaultfields as $field) {
    $tabledefault->data[] = array('[['.$field.']]', $userdefaultvalues[$field]);
}

$editurl = new moodle_url('/admin/settings.php', array('section' => 'local_patientrecord'));

echo $OUTPUT->header();

echo html_writer::tag('h2', get_string('pluginname', 'local_patientrecord'));
echo html_writer::tag('p', get_string('globalhelp', 'local_patientrecord'));
echo $OUTPUT->single_button($editurl, get_string('configure', 'local_patientrecord'));

echo html_writer::tag('h2', get_string('customprofilefields', 'local_patientrecord'));
echo html_writer::table($tablecustom);

echo html_writer::tag('h2', get_string('welcomefields', 'local_patientrecord'));
echo html_writer::table($tablewelcome);

echo html_writer::tag('h2', get_string('defaultprofilefields', 'local_patientrecord'));
echo html_writer::table($tabledefault);
echo $OUTPUT->footer();