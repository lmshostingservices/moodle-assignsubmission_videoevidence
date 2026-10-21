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

namespace assignsubmission_videoevidence;

use assignsubmission_videoevidence\external\record_view;
use assignsubmission_videoevidence\local\manager;
use assignsubmission_videoevidence\local\processor;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Full lifecycle: upload, tracked viewing, pass, safety period, evidence pack, deletion.
 *
 * Needs ffmpeg and ffprobe on the test server; skipped otherwise.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_videoevidence\local\manager
 * @covers     \assignsubmission_videoevidence\local\processor
 * @covers     \assignsubmission_videoevidence\task\build_evidence
 * @covers     \assignsubmission_videoevidence\task\sweep
 * @covers     \assignsubmission_videoevidence\observer
 * @covers     \assignsubmission_videoevidence\external\record_view
 * @covers     \assign_submission_videoevidence
 */
#[\PHPUnit\Framework\Attributes\CoversClass(manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(processor::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(record_view::class)]
final class lifecycle_test extends \advanced_testcase {
    /** @var \stdClass */
    protected $course;
    /** @var \stdClass */
    protected $teacher;
    /** @var \stdClass */
    protected $teacher2;
    /** @var \assign */
    protected $assign;
    /** @var \assign_submission_videoevidence */
    protected $plugin;

    /**
     * Course with a competency-scale assignment using the plugin; no safety period unless a test sets one.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('unlockstate', json_encode(['status' => 'unlocked']), manager::COMPONENT);

        $ffmpeg = getenv('VIDEOEVIDENCE_FFMPEG') ?: '/usr/bin/ffmpeg';
        $ffprobe = getenv('VIDEOEVIDENCE_FFPROBE') ?: '/usr/bin/ffprobe';
        if (!is_executable($ffmpeg) || !is_executable($ffprobe)) {
            $this->markTestSkipped('ffmpeg/ffprobe not available');
        }
        set_config('ffmpegpath', $ffmpeg, manager::COMPONENT);
        set_config('ffprobepath', $ffprobe, manager::COMPONENT);
        set_config('delaydays', 0, manager::COMPONENT);
        set_config('testmode', 0, manager::COMPONENT);

        $gen = $this->getDataGenerator();
        $scale = $gen->create_scale(['scale' => 'Not yet competent,Competent', 'name' => 'Competency']);
        $this->course = $gen->create_course();
        $this->teacher = $gen->create_and_enrol($this->course, 'editingteacher', ['firstname' => 'Tess']);
        $this->teacher2 = $gen->create_and_enrol($this->course, 'editingteacher');
        $instance = $gen->create_module('assign', [
            'course' => $this->course->id,
            'grade' => -$scale->id,
            'assignsubmission_videoevidence_enabled' => 1,
            'assignsubmission_videoevidence_maxfiles' => 1,
            'assignsubmission_videoevidence_maxbytes' => 0,
            'assignsubmission_file_enabled' => 0,
            'assignsubmission_onlinetext_enabled' => 0,
            'submissiondrafts' => 0,
        ]);
        $cm = get_coursemodule_from_instance('assign', $instance->id);
        $this->assign = new \assign(\context_module::instance($cm->id), $cm, $this->course);
        $this->plugin = $this->assign->get_submission_plugin_by_type('videoevidence');
    }

    /**
     * A direct save cannot bypass activation even when no upload form was shown.
     */
    public function test_new_upload_requires_activation(): void {
        set_config('unlockstate', json_encode(['status' => 'locked']), manager::COMPONENT);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('notactivated', manager::COMPONENT));
        $this->plugin->save((object)['id' => 0], new \stdClass());
    }

    /**
     * A student uploads the fixture video.
     *
     * @return array [student, submission, video row]
     */
    protected function upload(): array {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $submission = $this->assign->get_user_submission($student->id, true);
        $draftid = file_get_unused_draft_itemid();
        get_file_storage()->create_file_from_pathname([
            'contextid' => \context_user::instance($student->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => 'demo.mp4',
        ], __DIR__ . '/fixtures/sample.mp4');
        $this->plugin->save($submission, (object)['videoevidence_filemanager' => $draftid]);
        $row = $this->row_for($submission);
        return [$student, $submission, $row];
    }

    /**
     * Current tracking row for a submission.
     *
     * @param \stdClass $submission
     * @return \stdClass
     */
    protected function row_for(\stdClass $submission): \stdClass {
        global $DB;
        return $DB->get_record(manager::TABLE, ['submission' => $submission->id], '*', MUST_EXIST);
    }

    /**
     * Grade a student as the given teacher (2 = Competent, 1 = Not yet competent).
     *
     * @param \stdClass $student
     * @param \stdClass $teacher
     * @param int $value
     */
    protected function grade(\stdClass $student, \stdClass $teacher, int $value): void {
        $this->setUser($teacher);
        $grade = $this->assign->get_user_grade($student->id, true);
        $grade->grade = $value;
        $grade->grader = $teacher->id;
        $this->assign->update_grade($grade);
    }

    /**
     * Record viewing ranges as the given teacher.
     *
     * @param \stdClass $row
     * @param \stdClass $teacher
     * @param array $ranges
     * @return array web service result
     */
    protected function watch(\stdClass $row, \stdClass $teacher, array $ranges): array {
        $this->setUser($teacher);
        return record_view::execute((int)$row->id, json_encode($ranges), 12.0);
    }

    /**
     * Run queued adhoc tasks quietly.
     */
    protected function run_tasks(): void {
        ob_start();
        $this->runAdhocTasks();
        ob_end_clean();
    }

    /**
     * Video is only replaced after the grading assessor has watched it and it is passed.
     */
    public function test_full_lifecycle(): void {
        [$student, $submission, $row] = $this->upload();
        $fs = get_file_storage();
        $this->assertSame(manager::STATUS_PENDING, $row->status);
        $this->assertNotFalse($fs->get_file_by_hash($row->pathnamehash));

        // Pass before watching: nothing happens.
        $this->grade($student, $this->teacher, 2);
        $this->assertSame(manager::STATUS_PENDING, $this->row_for($submission)->status);

        // Another assessor's viewing does not count by default.
        $result = $this->watch($row, $this->teacher2, [[0, 12]]);
        $this->assertTrue($result['met']);
        $this->assertFalse($result['queued']);

        // Partial viewing by the grader.
        $result = $this->watch($row, $this->teacher, [[0, 5]]);
        $this->assertFalse($result['met']);

        // Complete viewing queues the conversion.
        $result = $this->watch($row, $this->teacher, [[4.8, 12]]);
        $this->assertTrue($result['queued']);
        $this->assertSame(manager::STATUS_QUEUED, $this->row_for($submission)->status);

        $this->run_tasks();
        $row = $this->row_for($submission);
        $this->assertSame(manager::STATUS_COMPLETE, $row->status, (string)$row->error);
        $this->assertFalse($fs->get_file_by_hash($row->pathnamehash), 'Original video must be deleted');
        $this->assertSame(hash_file('sha256', __DIR__ . '/fixtures/sample.mp4'), $row->sha256);
        $this->assertSame('Competent', $row->grade);
        $this->assertEquals(1, $row->hasaudio);
        $this->assertGreaterThanOrEqual(2, (int)$row->framecount);

        $files = $fs->get_directory_files(
            $this->assign->get_context()->id,
            manager::COMPONENT,
            manager::AREA_EVIDENCE,
            $submission->id,
            '/' . $row->packkey . '/',
            false,
            false
        );
        $names = array_map(fn($f) => $f->get_filename(), array_values($files));
        $this->assertContains('audio.m4a', $names);
        $this->assertContains('manifest.json', $names);
        $this->assertContains('still_00001.jpg', $names);

        // Everyone with access now sees the evidence pack instead of a video.
        $this->setUser($student);
        $show = false;
        $html = $this->plugin->view_summary($submission, $show);
        $this->assertStringContainsString('<audio', $html);
        $this->assertStringNotContainsString('<video', $html);
        $this->assertFalse($this->plugin->is_empty($submission));
        [$text, $htmlsummary] = $this->plugin->submission_summary_for_messages($submission);
        $this->assertStringContainsString('demo.mp4', $text);
        $this->assertStringContainsString('<li>', $htmlsummary);
    }

    /**
     * Not yet competent never deletes; re-grading to a pass does.
     */
    public function test_fail_keeps_video(): void {
        [$student, $submission, $row] = $this->upload();
        $this->watch($row, $this->teacher, [[0, 12]]);
        $this->grade($student, $this->teacher, 1);
        $this->run_tasks();
        $this->assertSame(manager::STATUS_PENDING, $this->row_for($submission)->status);
        $this->assertNotFalse(get_file_storage()->get_file_by_hash($row->pathnamehash));

        $this->grade($student, $this->teacher, 2);
        $this->assertSame(manager::STATUS_QUEUED, $this->row_for($submission)->status);
        $this->run_tasks();
        $this->assertSame(manager::STATUS_COMPLETE, $this->row_for($submission)->status);
    }

    /**
     * The safety period delays deletion, and the grade is re-checked at the end of it.
     */
    public function test_safety_period(): void {
        global $DB;
        set_config('delaydays', 30, manager::COMPONENT);
        [$student, $submission, $row] = $this->upload();
        $this->watch($row, $this->teacher, [[0, 12]]);
        $this->grade($student, $this->teacher, 2);

        $task = $DB->get_record('task_adhoc', ['classname' => '\\' . task\build_evidence::class]);
        $this->assertGreaterThan(time() + 29 * DAYSECS, (int)$task->nextruntime);

        // Grade withdrawn during the period, then the period ends.
        $this->grade($student, $this->teacher, 1);
        $DB->set_field('task_adhoc', 'nextruntime', time() - 1, ['id' => $task->id]);
        $this->run_tasks();
        $this->assertSame(manager::STATUS_PENDING, $this->row_for($submission)->status);
        $this->assertNotFalse(get_file_storage()->get_file_by_hash($row->pathnamehash));
    }

    /**
     * Test mode converts during upload with no checks.
     */
    public function test_test_mode(): void {
        set_config('testmode', 1, manager::COMPONENT);
        [, $submission, $row] = $this->upload();
        $this->assertSame(manager::STATUS_COMPLETE, $row->status, (string)$row->error);
        $this->assertSame(get_string('testmode_grade', manager::COMPONENT), $row->grade);
        $this->assertFalse(get_file_storage()->get_file_by_hash($row->pathnamehash));
    }

    /**
     * Without ffmpeg, the video is kept and retries are not used up; installing ffmpeg resumes processing.
     */
    public function test_missing_ffmpeg_waits(): void {
        global $DB;
        [$student, $submission, $row] = $this->upload();
        set_config('ffmpegpath', '/nonexistent/ffmpeg', manager::COMPONENT);
        $this->watch($row, $this->teacher, [[0, 12]]);
        $this->grade($student, $this->teacher, 2);
        $this->run_tasks();
        $row = $this->row_for($submission);
        $this->assertSame(manager::STATUS_FAILED, $row->status);
        $this->assertEquals(0, $row->attempts);
        $this->assertNotFalse(get_file_storage()->get_file_by_hash($row->pathnamehash));

        set_config('ffmpegpath', getenv('VIDEOEVIDENCE_FFMPEG') ?: '/usr/bin/ffmpeg', manager::COMPONENT);
        $DB->set_field(manager::TABLE, 'timemodified', time() - 2 * HOURSECS, ['id' => $row->id]);
        ob_start();
        (new task\sweep())->execute();
        ob_end_clean();
        $this->run_tasks();
        $this->assertSame(manager::STATUS_COMPLETE, $this->row_for($submission)->status);
    }

    /**
     * Students cannot record assessor viewing.
     */
    public function test_student_cannot_record_view(): void {
        [$student, , $row] = $this->upload();
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        record_view::execute((int)$row->id, json_encode([[0, 12]]), 12.0);
    }

    /**
     * Course backup and restore carries videos, packs and viewing records.
     */
    public function test_backup_restore(): void {
        global $CFG, $DB, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        [$student, , $row] = $this->upload();
        $this->watch($row, $this->teacher, [[0, 12]]);
        $this->grade($student, $this->teacher, 2);
        $this->run_tasks();
        [, , $pending] = $this->upload();
        $this->watch($pending, $this->teacher, [[0, 6]]);

        $this->setAdminUser();
        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $this->course->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $mbz = $bc->get_results()['backup_destination'];
        $bc->destroy();
        $backupid = 'vetest' . random_string(6);
        $mbz->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($backupid));
        $newcourseid = \restore_dbops::create_new_course('Restored2', 'restored2', $this->course->category);
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_value(true);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $newassignid = $DB->get_field('assign', 'id', ['course' => $newcourseid]);
        $newctx = \context_module::instance(get_coursemodule_from_instance('assign', $newassignid)->id);
        $rows = $DB->get_records(manager::TABLE, ['assignment' => $newassignid]);
        $this->assertCount(2, $rows);
        $fs = get_file_storage();
        foreach ($rows as $r) {
            if ($r->status === manager::STATUS_COMPLETE) {
                $this->assertNotEmpty($fs->get_directory_files(
                    $newctx->id,
                    manager::COMPONENT,
                    manager::AREA_EVIDENCE,
                    $r->submission,
                    '/' . $r->packkey . '/',
                    false,
                    false
                ));
            } else {
                $this->assertNotFalse($fs->get_file_by_hash($r->pathnamehash), 'Pending video restored');
                $this->assertTrue($DB->record_exists(manager::TABLE_VIEWS, ['videoid' => $r->id]));
            }
        }
    }
}
