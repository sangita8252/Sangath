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
 * Unit tests for (some of) mod/patientform/lib.php.
 *
 * @package    mod_patientform
 * @copyright  2016 Stephen Bourget
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/mod/patientform/lib.php');

/**
 * Unit tests for (some of) mod/patientform/lib.php.
 *
 * @copyright  2016 Stephen Bourget
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_patientform_lib_testcase extends advanced_testcase {

    public function test_patientform_initialise() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $params['course'] = $course->id;
        $params['timeopen'] = time() - 5 * MINSECS;
        $params['timeclose'] = time() + DAYSECS;
        $params['anonymous'] = 1;
        $params['intro'] = 'Some introduction text';
        $patientform = $this->getDataGenerator()->create_module('patientform', $params);

        // Test different ways to construct the structure object.
        $pseudocm = get_coursemodule_from_instance('patientform', $patientform->id); // Object similar to cm_info.
        $cm = get_fast_modinfo($course)->instances['patientform'][$patientform->id]; // Instance of cm_info.

        $constructorparams = [
            [$patientform, null],
            [null, $pseudocm],
            [null, $cm],
            [$patientform, $pseudocm],
            [$patientform, $cm],
        ];

        foreach ($constructorparams as $params) {
            $structure = new mod_patientform_completion($params[0], $params[1], 0);
            $this->assertTrue($structure->is_open());
            $this->assertTrue($structure->get_cm() instanceof cm_info);
            $this->assertEquals($patientform->cmid, $structure->get_cm()->id);
            $this->assertEquals($patientform->intro, $structure->get_patientform()->intro);
        }
    }

    /**
     * Tests for mod_patientform_refresh_events.
     */
    public function test_patientform_refresh_events() {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $timeopen = time();
        $timeclose = time() + 86400;

        $course = $this->getDataGenerator()->create_course();
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_patientform');
        $params['course'] = $course->id;
        $params['timeopen'] = $timeopen;
        $params['timeclose'] = $timeclose;
        $patientform = $generator->create_instance($params);
        $cm = get_coursemodule_from_instance('patientform', $patientform->id);
        $context = context_module::instance($cm->id);

        // Normal case, with existing course.
        $this->assertTrue(patientform_refresh_events($course->id));
        $eventparams = array('modulename' => 'patientform', 'instance' => $patientform->id, 'eventtype' => 'open');
        $openevent = $DB->get_record('event', $eventparams, '*', MUST_EXIST);
        $this->assertEquals($openevent->timestart, $timeopen);

        $eventparams = array('modulename' => 'patientform', 'instance' => $patientform->id, 'eventtype' => 'close');
        $closeevent = $DB->get_record('event', $eventparams, '*', MUST_EXIST);
        $this->assertEquals($closeevent->timestart, $timeclose);
        // In case the course ID is passed as a numeric string.
        $this->assertTrue(patientform_refresh_events('' . $course->id));
        // Course ID not provided.
        $this->assertTrue(patientform_refresh_events());
        $eventparams = array('modulename' => 'patientform');
        $events = $DB->get_records('event', $eventparams);
        foreach ($events as $event) {
            if ($event->modulename === 'patientform' && $event->instance === $patientform->id && $event->eventtype === 'open') {
                $this->assertEquals($event->timestart, $timeopen);
            }
            if ($event->modulename === 'patientform' && $event->instance === $patientform->id && $event->eventtype === 'close') {
                $this->assertEquals($event->timestart, $timeclose);
            }
        }
    }

    /**
     * Test check_updates_since callback.
     */
    public function test_check_updates_since() {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        // Create user.
        $student = self::getDataGenerator()->create_user();

        // User enrolment.
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($student->id, $course->id, $studentrole->id, 'manual');

        $this->setCurrentTimeStart();
        $record = array(
            'course' => $course->id,
            'custom' => 0,
            'patientform' => 1,
        );
        $patientform = $this->getDataGenerator()->create_module('patientform', $record);
        $cm = get_coursemodule_from_instance('patientform', $patientform->id, $course->id);
        $cm = cm_info::create($cm);

        $this->setUser($student);
        // Check that upon creation, the updates are only about the new configuration created.
        $onehourago = time() - HOURSECS;
        $updates = patientform_check_updates_since($cm, $onehourago);
        foreach ($updates as $el => $val) {
            if ($el == 'configuration') {
                $this->assertTrue($val->updated);
                $this->assertTimeCurrent($val->timeupdated);
            } else {
                $this->assertFalse($val->updated);
            }
        }

        $record = [
            'patientform' => $patientform->id,
            'userid' => $student->id,
            'timemodified' => time(),
            'random_response' => 0,
            'anonymous_response' => PATIENTFORM_ANONYMOUS_NO,
            'courseid' => $course->id,
        ];
        $DB->insert_record('patientform_completed', (object)$record);
        $DB->insert_record('patientform_completedtmp', (object)$record);

        // Check now for finished and unfinished attempts.
        $updates = patientform_check_updates_since($cm, $onehourago);
        $this->assertTrue($updates->attemptsunfinished->updated);
        $this->assertCount(1, $updates->attemptsunfinished->itemids);

        $this->assertTrue($updates->attemptsfinished->updated);
        $this->assertCount(1, $updates->attemptsfinished->itemids);
    }

    /**
     * Test calendar event provide action open.
     */
    public function test_patientform_core_calendar_provide_event_action_open() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $now = time();
        $course = $this->getDataGenerator()->create_course();
        $patientform = $this->getDataGenerator()->create_module('patientform', ['course' => $course->id,
                'timeopen' => $now - DAYSECS, 'timeclose' => $now + DAYSECS]);
        $event = $this->create_action_event($course->id, $patientform->id, PATIENTFORM_EVENT_TYPE_OPEN);

        $factory = new \core_calendar\action_factory();
        $actionevent = mod_patientform_core_calendar_provide_event_action($event, $factory);

        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('answerquestions', 'patientform'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(1, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    /**
     * Test calendar event provide action closed.
     */
    public function test_patientform_core_calendar_provide_event_action_closed() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $patientform = $this->getDataGenerator()->create_module('patientform', array('course' => $course->id,
                'timeclose' => time() - DAYSECS));
        $event = $this->create_action_event($course->id, $patientform->id, PATIENTFORM_EVENT_TYPE_OPEN);

        $factory = new \core_calendar\action_factory();
        $actionevent = mod_patientform_core_calendar_provide_event_action($event, $factory);

        // No event on the dashboard if patientform is closed.
        $this->assertNull($actionevent);
    }

    /**
     * Test calendar event action open in future.
     *
     * @throws coding_exception
     */
    public function test_patientform_core_calendar_provide_event_action_open_in_future() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $patientform = $this->getDataGenerator()->create_module('patientform', ['course' => $course->id,
                'timeopen' => time() + DAYSECS]);
        $event = $this->create_action_event($course->id, $patientform->id, PATIENTFORM_EVENT_TYPE_OPEN);

        $factory = new \core_calendar\action_factory();
        $actionevent = mod_patientform_core_calendar_provide_event_action($event, $factory);

        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('answerquestions', 'patientform'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(1, $actionevent->get_item_count());
        $this->assertFalse($actionevent->is_actionable());
    }

    /**
     * Test calendar event with no time specified.
     *
     * @throws coding_exception
     */
    public function test_patientform_core_calendar_provide_event_action_no_time_specified() {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $patientform = $this->getDataGenerator()->create_module('patientform', ['course' => $course->id]);
        $event = $this->create_action_event($course->id, $patientform->id, PATIENTFORM_EVENT_TYPE_OPEN);

        $factory = new \core_calendar\action_factory();
        $actionevent = mod_patientform_core_calendar_provide_event_action($event, $factory);

        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('answerquestions', 'patientform'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(1, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    /**
     * A user that can not submit patientform should not have an action.
     */
    public function test_patientform_core_calendar_provide_event_action_can_not_submit() {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $course = $this->getDataGenerator()->create_course();
        $patientform = $this->getDataGenerator()->create_module('patientform', ['course' => $course->id]);
        $event = $this->create_action_event($course->id, $patientform->id, PATIENTFORM_EVENT_TYPE_OPEN);
        $cm = get_coursemodule_from_instance('patientform', $patientform->id);
        $context = context_module::instance($cm->id);
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $studentrole->id, 'manual');

        $this->setUser($user);
        assign_capability('mod/patientform:complete', CAP_PROHIBIT, $studentrole->id, $context);
        $context->mark_dirty();

        $factory = new \core_calendar\action_factory();
        $action = mod_patientform_core_calendar_provide_event_action($event, $factory);

        $this->assertNull($action);
    }

    /**
     * A user that has already submitted patientform should not have an action.
     */
    public function test_patientform_core_calendar_provide_event_action_already_submitted() {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $user = $this->getDataGenerator()->create_user();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $course = $this->getDataGenerator()->create_course();
        $patientform = $this->getDataGenerator()->create_module('patientform', ['course' => $course->id]);
        $event = $this->create_action_event($course->id, $patientform->id, PATIENTFORM_EVENT_TYPE_OPEN);
        $cm = get_coursemodule_from_instance('patientform', $patientform->id);
        $context = context_module::instance($cm->id);

        $this->setUser($user);

        $record = [
            'patientform' => $patientform->id,
            'userid' => $user->id,
            'timemodified' => time(),
            'random_response' => 0,
            'anonymous_response' => PATIENTFORM_ANONYMOUS_NO,
            'courseid' => 0,
        ];
        $DB->insert_record('patientform_completed', (object) $record);

        $factory = new \core_calendar\action_factory();
        $action = mod_patientform_core_calendar_provide_event_action($event, $factory);

        $this->assertNull($action);
    }

    /**
     * Creates an action event.
     *
     * @param int $courseid The course id.
     * @param int $instanceid The patientform id.
     * @param string $eventtype The event type. eg. PATIENTFORM_EVENT_TYPE_OPEN.
     * @return bool|calendar_event
     */
    private function create_action_event($courseid, $instanceid, $eventtype) {
        $event = new stdClass();
        $event->name = 'Calendar event';
        $event->modulename = 'patientform';
        $event->courseid = $courseid;
        $event->instance = $instanceid;
        $event->type = CALENDAR_EVENT_TYPE_ACTION;
        $event->eventtype = $eventtype;
        $event->timestart = time();

        return calendar_event::create($event);
    }

    /**
     * Test the callback responsible for returning the completion rule descriptions.
     * This function should work given either an instance of the module (cm_info), such as when checking the active rules,
     * or if passed a stdClass of similar structure, such as when checking the the default completion settings for a mod type.
     */
    public function test_mod_patientform_completion_get_active_rule_descriptions() {
        $this->resetAfterTest();
        $this->setAdminUser();

        // Two activities, both with automatic completion. One has the 'completionsubmit' rule, one doesn't.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 2]);
        $patientform1 = $this->getDataGenerator()->create_module('patientform', [
            'course' => $course->id,
            'completion' => 2,
            'completionsubmit' => 1
        ]);
        $patientform2 = $this->getDataGenerator()->create_module('patientform', [
            'course' => $course->id,
            'completion' => 2,
            'completionsubmit' => 0
        ]);
        $cm1 = cm_info::create(get_coursemodule_from_instance('patientform', $patientform1->id));
        $cm2 = cm_info::create(get_coursemodule_from_instance('patientform', $patientform2->id));

        // Data for the stdClass input type.
        // This type of input would occur when checking the default completion rules for an activity type, where we don't have
        // any access to cm_info, rather the input is a stdClass containing completion and customdata attributes, just like cm_info.
        $moddefaults = new stdClass();
        $moddefaults->customdata = ['customcompletionrules' => ['completionsubmit' => 1]];
        $moddefaults->completion = 2;

        $activeruledescriptions = [get_string('completionsubmit', 'patientform')];
        $this->assertEquals(mod_patientform_get_completion_active_rule_descriptions($cm1), $activeruledescriptions);
        $this->assertEquals(mod_patientform_get_completion_active_rule_descriptions($cm2), []);
        $this->assertEquals(mod_patientform_get_completion_active_rule_descriptions($moddefaults), $activeruledescriptions);
        $this->assertEquals(mod_patientform_get_completion_active_rule_descriptions(new stdClass()), []);
    }

    /**
     * An unknown event should not have min or max restrictions.
     */
    public function test_get_valid_event_timestart_range_unknown_event() {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = $timeopen;
        $patientform->timeclose = $timeclose;
        $DB->update_record('patientform', $patientform);

        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => 'SOME UNKNOWN EVENT',
            'timestart' => $timeopen,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        list($min, $max) = mod_patientform_core_calendar_get_valid_event_timestart_range($event, $patientform);
        $this->assertNull($min);
        $this->assertNull($max);
    }

    /**
     * A PATIENTFORM_EVENT_TYPE_OPEN should have a max timestart equal to the activity
     * close time.
     */
    public function test_get_valid_event_timestart_range_event_type_open() {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = $timeopen;
        $patientform->timeclose = $timeclose;
        $DB->update_record('patientform', $patientform);

        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => PATIENTFORM_EVENT_TYPE_OPEN,
            'timestart' => $timeopen,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        list($min, $max) = mod_patientform_core_calendar_get_valid_event_timestart_range($event, $patientform);
        $this->assertNull($min);
        $this->assertEquals($timeclose, $max[0]);
        $this->assertNotEmpty($max[1]);
    }

    /**
     * A PATIENTFORM_EVENT_TYPE_OPEN should not have a max timestamp if the activity
     * doesn't have a close date.
     */
    public function test_get_valid_event_timestart_range_event_type_open_no_close() {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = $timeopen;
        $patientform->timeclose = 0;
        $DB->update_record('patientform', $patientform);

        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => PATIENTFORM_EVENT_TYPE_OPEN,
            'timestart' => $timeopen,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        list($min, $max) = mod_patientform_core_calendar_get_valid_event_timestart_range($event, $patientform);
        $this->assertNull($min);
        $this->assertNull($max);
    }

    /**
     * A PATIENTFORM_EVENT_TYPE_CLOSE should have a min timestart equal to the activity
     * open time.
     */
    public function test_get_valid_event_timestart_range_event_type_close() {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = $timeopen;
        $patientform->timeclose = $timeclose;
        $DB->update_record('patientform', $patientform);

        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => PATIENTFORM_EVENT_TYPE_CLOSE,
            'timestart' => $timeopen,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        list($min, $max) = mod_patientform_core_calendar_get_valid_event_timestart_range($event, $patientform);
        $this->assertEquals($timeopen, $min[0]);
        $this->assertNotEmpty($min[1]);
        $this->assertNull($max);
    }

    /**
     * A PATIENTFORM_EVENT_TYPE_CLOSE should not have a minimum timestamp if the activity
     * doesn't have an open date.
     */
    public function test_get_valid_event_timestart_range_event_type_close_no_open() {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = 0;
        $patientform->timeclose = $timeclose;
        $DB->update_record('patientform', $patientform);

        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => PATIENTFORM_EVENT_TYPE_CLOSE,
            'timestart' => $timeopen,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        list($min, $max) = mod_patientform_core_calendar_get_valid_event_timestart_range($event, $patientform);
        $this->assertNull($min);
        $this->assertNull($max);
    }

    /**
     * An unkown event type should not change the patientform instance.
     */
    public function test_mod_patientform_core_calendar_event_timestart_updated_unknown_event() {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = $timeopen;
        $patientform->timeclose = $timeclose;
        $DB->update_record('patientform', $patientform);

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => PATIENTFORM_EVENT_TYPE_OPEN . "SOMETHING ELSE",
            'timestart' => 1,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        mod_patientform_core_calendar_event_timestart_updated($event, $patientform);

        $patientform = $DB->get_record('patientform', ['id' => $patientform->id]);
        $this->assertEquals($timeopen, $patientform->timeopen);
        $this->assertEquals($timeclose, $patientform->timeclose);
    }

    /**
     * A PATIENTFORM_EVENT_TYPE_OPEN event should update the timeopen property of
     * the patientform activity.
     */
    public function test_mod_patientform_core_calendar_event_timestart_updated_open_event() {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $timemodified = 1;
        $newtimeopen = $timeopen - DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = $timeopen;
        $patientform->timeclose = $timeclose;
        $patientform->timemodified = $timemodified;
        $DB->update_record('patientform', $patientform);

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => PATIENTFORM_EVENT_TYPE_OPEN,
            'timestart' => $newtimeopen,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        mod_patientform_core_calendar_event_timestart_updated($event, $patientform);

        $patientform = $DB->get_record('patientform', ['id' => $patientform->id]);
        // Ensure the timeopen property matches the event timestart.
        $this->assertEquals($newtimeopen, $patientform->timeopen);
        // Ensure the timeclose isn't changed.
        $this->assertEquals($timeclose, $patientform->timeclose);
        // Ensure the timemodified property has been changed.
        $this->assertNotEquals($timemodified, $patientform->timemodified);
    }

    /**
     * A PATIENTFORM_EVENT_TYPE_CLOSE event should update the timeclose property of
     * the patientform activity.
     */
    public function test_mod_patientform_core_calendar_event_timestart_updated_close_event() {
        global $CFG, $DB;
        require_once($CFG->dirroot . "/calendar/lib.php");

        $this->resetAfterTest(true);
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $timemodified = 1;
        $newtimeclose = $timeclose + DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = $timeopen;
        $patientform->timeclose = $timeclose;
        $patientform->timemodified = $timemodified;
        $DB->update_record('patientform', $patientform);

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => 2,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => PATIENTFORM_EVENT_TYPE_CLOSE,
            'timestart' => $newtimeclose,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        mod_patientform_core_calendar_event_timestart_updated($event, $patientform);

        $patientform = $DB->get_record('patientform', ['id' => $patientform->id]);
        // Ensure the timeclose property matches the event timestart.
        $this->assertEquals($newtimeclose, $patientform->timeclose);
        // Ensure the timeopen isn't changed.
        $this->assertEquals($timeopen, $patientform->timeopen);
        // Ensure the timemodified property has been changed.
        $this->assertNotEquals($timemodified, $patientform->timemodified);
    }

    /**
     * If a student somehow finds a way to update the calendar event
     * then the callback should not be executed to update the activity
     * properties as well because that would be a security issue.
     */
    public function test_student_role_cant_update_time_close_event() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $context = context_course::instance($course->id);
        $roleid = $generator->create_role();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $timemodified = 1;
        $newtimeclose = $timeclose + DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = $timeopen;
        $patientform->timeclose = $timeclose;
        $patientform->timemodified = $timemodified;
        $DB->update_record('patientform', $patientform);

        $generator->enrol_user($user->id, $course->id, 'student');
        $generator->role_assign($roleid, $user->id, $context->id);

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => $user->id,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => PATIENTFORM_EVENT_TYPE_CLOSE,
            'timestart' => $newtimeclose,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        assign_capability('moodle/calendar:manageentries', CAP_ALLOW, $roleid, $context, true);
        assign_capability('moodle/course:manageactivities', CAP_PROHIBIT, $roleid, $context, true);

        $this->setUser($user);

        mod_patientform_core_calendar_event_timestart_updated($event, $patientform);

        $newpatientform = $DB->get_record('patientform', ['id' => $patientform->id]);
        // The activity shouldn't have been updated because the user
        // doesn't have permissions to do it.
        $this->assertEquals($timeclose, $newpatientform->timeclose);
    }

    /**
     * The activity should update if a teacher modifies the calendar
     * event.
     */
    public function test_teacher_role_can_update_time_close_event() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/calendar/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();

        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $context = context_course::instance($course->id);
        $roleid = $generator->create_role();
        $patientformgenerator = $generator->get_plugin_generator('mod_patientform');
        $timeopen = time();
        $timeclose = $timeopen + DAYSECS;
        $timemodified = 1;
        $newtimeclose = $timeclose + DAYSECS;
        $patientform = $patientformgenerator->create_instance(['course' => $course->id]);
        $patientform->timeopen = $timeopen;
        $patientform->timeclose = $timeclose;
        $patientform->timemodified = $timemodified;
        $DB->update_record('patientform', $patientform);

        $generator->enrol_user($user->id, $course->id, 'teacher');
        $generator->role_assign($roleid, $user->id, $context->id);

        // Create a valid event.
        $event = new \calendar_event([
            'name' => 'Test event',
            'description' => '',
            'format' => 1,
            'courseid' => $course->id,
            'groupid' => 0,
            'userid' => $user->id,
            'modulename' => 'patientform',
            'instance' => $patientform->id,
            'eventtype' => PATIENTFORM_EVENT_TYPE_CLOSE,
            'timestart' => $newtimeclose,
            'timeduration' => 86400,
            'visible' => 1
        ]);

        assign_capability('moodle/calendar:manageentries', CAP_ALLOW, $roleid, $context, true);
        assign_capability('moodle/course:manageactivities', CAP_ALLOW, $roleid, $context, true);

        $this->setUser($user);

        $sink = $this->redirectEvents();

        mod_patientform_core_calendar_event_timestart_updated($event, $patientform);

        $triggeredevents = $sink->get_events();
        $moduleupdatedevents = array_filter($triggeredevents, function($e) {
            return is_a($e, 'core\event\course_module_updated');
        });

        $newpatientform = $DB->get_record('patientform', ['id' => $patientform->id]);
        // The activity should have been updated because the user
        // has permissions to do it.
        $this->assertEquals($newtimeclose, $newpatientform->timeclose);
        // A course_module_updated event should be fired if the module
        // was successfully modified.
        $this->assertNotEmpty($moduleupdatedevents);
    }
}
