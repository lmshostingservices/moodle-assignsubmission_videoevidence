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

namespace assignsubmission_videoevidence\privacy;

use assignsubmission_videoevidence\local\manager;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;
use mod_assign\privacy\assign_plugin_request_data;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Privacy provider tests.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_videoevidence\privacy\provider
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Assignment with one uploaded (unassessed) video and one assessor viewing record.
     *
     * @return array [assign, submission, student, teacher]
     */
    protected function setup_data(): array {
        global $DB;
        $this->resetAfterTest();
        set_config('unlockstate', json_encode(['status' => 'unlocked']), manager::COMPONENT);
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $student = $gen->create_and_enrol($course, 'student');
        $teacher = $gen->create_and_enrol($course, 'editingteacher');
        $instance = $gen->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_videoevidence_enabled' => 1,
            'assignsubmission_videoevidence_maxfiles' => 1,
        ]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $course);

        $this->setUser($student);
        $submission = $assign->get_user_submission($student->id, true);
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_string([
            'contextid' => \context_user::instance($student->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'clip.mp4',
        ], 'not really a video');
        $assign->get_submission_plugin_by_type('videoevidence')->save(
            $submission,
            (object)['videoevidence_filemanager' => $draftid]
        );
        $row = $DB->get_record(manager::TABLE, ['submission' => $submission->id], '*', MUST_EXIST);
        $DB->insert_record(manager::TABLE_VIEWS, (object)[
            'videoid' => $row->id,
            'userid' => $teacher->id,
            'intervals' => '[[0,5]]',
            'percent' => 50,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        return [$assign, $submission, $student, $teacher];
    }

    /**
     * Metadata is declared.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection(manager::COMPONENT));
        $this->assertCount(3, $collection->get_collection());
    }

    /**
     * Student export includes the video; assessor export includes their viewing record.
     */
    public function test_export(): void {
        [$assign, $submission, $student, $teacher] = $this->setup_data();
        $context = $assign->get_context();

        provider::export_submission_user_data(new assign_plugin_request_data($context, $assign, $submission, [], null));
        $this->assertNotEmpty(writer::with_context($context)->get_files([]));

        writer::reset();
        $subcontext = ['assessor'];
        provider::export_submission_user_data(
            new assign_plugin_request_data($context, $assign, $submission, $subcontext, $teacher)
        );
        $data = writer::with_context($context)->get_data(array_merge(
            $subcontext,
            [get_string('privacy:path', manager::COMPONENT)]
        ));
        $this->assertNotEmpty($data->views);
    }

    /**
     * Assessors with viewing records are found in the context.
     */
    public function test_get_userids_from_context(): void {
        [$assign, , , $teacher] = $this->setup_data();
        $userlist = new \core_privacy\local\request\userlist($assign->get_context(), 'assign');
        provider::get_userids_from_context($userlist);
        $this->assertContains((int)$teacher->id, $userlist->get_userids());
    }

    /**
     * Deleting a student's data removes files, rows and viewing records.
     */
    public function test_delete_for_user(): void {
        global $DB;
        [$assign, $submission, $student] = $this->setup_data();
        $context = $assign->get_context();
        provider::delete_submission_for_userid(
            new assign_plugin_request_data($context, $assign, $submission, [], $student)
        );
        $this->assertFalse($DB->record_exists(manager::TABLE, ['submission' => $submission->id]));
        $this->assertEquals(0, $DB->count_records(manager::TABLE_VIEWS));
        $this->assertTrue(get_file_storage()->is_area_empty(
            $context->id,
            manager::COMPONENT,
            manager::AREA_VIDEOS,
            $submission->id
        ));
    }

    /**
     * Deleting a whole context.
     */
    public function test_delete_for_context(): void {
        global $DB;
        [$assign, $submission] = $this->setup_data();
        provider::delete_submission_for_context(
            new assign_plugin_request_data($assign->get_context(), $assign, $submission, [], null)
        );
        $this->assertEquals(0, $DB->count_records(manager::TABLE));
        $this->assertEquals(0, $DB->count_records(manager::TABLE_VIEWS));
    }

    /**
     * Assessor viewing records are found, exported and erased through the core privacy API.
     */
    public function test_assessor_data_core_api(): void {
        global $DB;
        [$assign, , , $teacher] = $this->setup_data();
        $context = $assign->get_context();

        $contexts = provider::get_contexts_for_userid((int)$teacher->id);
        $this->assertContains((int)$context->id, array_map('intval', $contexts->get_contextids()));

        $approved = new \core_privacy\local\request\approved_contextlist($teacher, manager::COMPONENT, [$context->id]);
        provider::export_user_data($approved);
        $data = writer::with_context($context)->get_data([
            get_string('privacy:path', manager::COMPONENT),
            get_string('privacy:assessorpath', manager::COMPONENT),
        ]);
        $this->assertCount(1, $data->views);

        provider::delete_data_for_user($approved);
        $this->assertEquals(0, $DB->count_records(manager::TABLE_VIEWS, ['userid' => $teacher->id]));
        // The student's video record is untouched by an assessor erasure.
        $this->assertEquals(1, $DB->count_records(manager::TABLE));
    }

    /**
     * Userlist deletion and whole-context deletion of viewing records.
     */
    public function test_assessor_data_userlist(): void {
        global $DB;
        [$assign, , , $teacher] = $this->setup_data();
        $context = $assign->get_context();

        $userlist = new \core_privacy\local\request\userlist($context, manager::COMPONENT);
        provider::get_users_in_context($userlist);
        $this->assertContains((int)$teacher->id, array_map('intval', $userlist->get_userids()));

        provider::delete_data_for_users(
            new \core_privacy\local\request\approved_userlist($context, manager::COMPONENT, [$teacher->id])
        );
        $this->assertEquals(0, $DB->count_records(manager::TABLE_VIEWS));

        $DB->insert_record(manager::TABLE_VIEWS, (object)[
            'videoid' => $DB->get_field(manager::TABLE, 'id', []),
            'userid' => $teacher->id,
            'intervals' => '[]',
            'percent' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        provider::delete_data_for_all_users_in_context($context);
        $this->assertEquals(0, $DB->count_records(manager::TABLE_VIEWS));
    }
}
