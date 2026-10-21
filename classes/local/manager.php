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

namespace assignsubmission_videoevidence\local;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/assign/locallib.php');

use assign;
use stdClass;

/**
 * Decides when a video may be replaced by its evidence pack.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {
    /** Component name. */
    public const COMPONENT = 'assignsubmission_videoevidence';
    /** File area for the student's original videos. */
    public const AREA_VIDEOS = 'submission_videos';
    /** File area for generated evidence packs. */
    public const AREA_EVIDENCE = 'evidence';
    /** Video table. */
    public const TABLE = 'assignsubmission_videoevid';
    /** View tracking table. */
    public const TABLE_VIEWS = 'assignsubmission_videoev_vw';

    /** Uploaded, waiting for a pass and a full viewing. */
    public const STATUS_PENDING = 'pending';
    /** Conditions met, adhoc task queued. */
    public const STATUS_QUEUED = 'queued';
    /** ffmpeg running. */
    public const STATUS_PROCESSING = 'processing';
    /** Evidence pack stored, original deleted. */
    public const STATUS_COMPLETE = 'complete';
    /** Processing failed, original kept. */
    public const STATUS_FAILED = 'failed';

    /** Automatic retries before an admin has to look at it. */
    public const MAX_ATTEMPTS = 3;

    /**
     * Plugin config value with default.
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public static function config(string $name, $default = null) {
        $value = get_config(self::COMPONENT, $name);
        return ($value === false || $value === '' || $value === null) ? $default : $value;
    }

    /**
     * Seconds between stills.
     *
     * @return int
     */
    public static function interval(): int {
        return max(1, (int)self::config('interval', 10));
    }

    /**
     * Test mode: convert on upload with no assessment, viewing or delay checks.
     *
     * @return bool
     */
    public static function testmode(): bool {
        return (bool)self::config('testmode', 0);
    }

    /**
     * Stand-in assessment state used in test mode.
     *
     * @return stdClass
     */
    public static function test_state(): stdClass {
        return (object)['passed' => true, 'reason' => '', 'graderid' => 0,
            'grade' => get_string('testmode_grade', self::COMPONENT), 'timegraded' => 0, 'testmode' => true];
    }

    /**
     * Required viewing percentage.
     *
     * @return float
     */
    public static function threshold(): float {
        return (float)min(100, max(0, (int)self::config('viewthreshold', 90)));
    }

    /**
     * Load the assign object for an assignment id.
     *
     * @param int $assignid
     * @return assign
     */
    public static function get_assign(int $assignid): assign {
        $cm = get_coursemodule_from_instance('assign', $assignid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $course = get_course($cm->course);
        return new assign($context, $cm, $course);
    }

    /**
     * Is the plugin enabled on this assignment?
     *
     * @param assign $assign
     * @return bool
     */
    public static function enabled(assign $assign): bool {
        $plugin = $assign->get_submission_plugin_by_type('videoevidence');
        return $plugin && $plugin->is_enabled() && $plugin->is_visible();
    }

    /**
     * Make the tracking rows match the files currently in the submission.
     *
     * @param assign $assign
     * @param stdClass $submission
     */
    public static function sync_rows(assign $assign, stdClass $submission): void {
        global $DB;

        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $assign->get_context()->id,
            self::COMPONENT,
            self::AREA_VIDEOS,
            $submission->id,
            'id',
            false
        );

        $rows = $DB->get_records_select(
            self::TABLE,
            'submission = ? AND status <> ?',
            [$submission->id, self::STATUS_COMPLETE]
        );
        $byhash = [];
        foreach ($rows as $row) {
            $byhash[$row->pathnamehash] = $row;
        }

        $now = time();
        foreach ($files as $file) {
            $hash = $file->get_pathnamehash();
            if (isset($byhash[$hash])) {
                $row = $byhash[$hash];
                unset($byhash[$hash]);
                if ((int)$row->filesize !== (int)$file->get_filesize() && $row->status !== self::STATUS_PROCESSING) {
                    // Replaced with a different file of the same name: start again.
                    $DB->delete_records(self::TABLE_VIEWS, ['videoid' => $row->id]);
                    $DB->update_record(self::TABLE, (object)[
                        'id' => $row->id,
                        'filesize' => $file->get_filesize(),
                        'mimetype' => $file->get_mimetype(),
                        'uploadedby' => (int)$file->get_userid(),
                        'duration' => null,
                        'durationverified' => 0,
                        'status' => self::STATUS_PENDING,
                        'viewpercent' => 0,
                        'attempts' => 0,
                        'error' => null,
                        'timemodified' => $now,
                    ]);
                }
                continue;
            }
            $DB->insert_record(self::TABLE, (object)[
                'assignment' => $assign->get_instance()->id,
                'submission' => $submission->id,
                'pathnamehash' => $hash,
                'filename' => $file->get_filename(),
                'filesize' => $file->get_filesize(),
                'mimetype' => $file->get_mimetype(),
                'uploadedby' => (int)$file->get_userid(),
                'status' => self::STATUS_PENDING,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        // Files the student removed before assessment.
        foreach ($byhash as $row) {
            if ($row->status === self::STATUS_PROCESSING) {
                continue;
            }
            $DB->delete_records(self::TABLE_VIEWS, ['videoid' => $row->id]);
            $DB->delete_records(self::TABLE, ['id' => $row->id]);
        }
    }

    /**
     * Merge overlapping or near-touching [start, end] ranges.
     *
     * @param array $ranges
     * @return array
     */
    public static function merge_intervals(array $ranges): array {
        $clean = [];
        foreach ($ranges as $r) {
            if (!is_array($r) || count($r) < 2) {
                continue;
            }
            $s = (float)$r[0];
            $e = (float)$r[1];
            if (!is_finite($s) || !is_finite($e) || $e <= $s || $s < 0) {
                continue;
            }
            $clean[] = [$s, $e];
        }
        usort($clean, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        $merged = [];
        foreach ($clean as $r) {
            $last = count($merged) - 1;
            // Browsers report played ranges with small gaps around seeks; 0.5s tolerance.
            if ($last >= 0 && $r[0] <= $merged[$last][1] + 0.5) {
                $merged[$last][1] = max($merged[$last][1], $r[1]);
            } else {
                $merged[] = $r;
            }
        }
        return array_map(function ($r) {
            return [round($r[0], 2), round($r[1], 2)];
        }, $merged);
    }

    /**
     * Percentage of [0, duration] covered by the ranges.
     *
     * @param array $intervals merged ranges
     * @param float $duration
     * @return float
     */
    public static function coverage(array $intervals, float $duration): float {
        if ($duration <= 0) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($intervals as $r) {
            $s = max(0.0, (float)$r[0]);
            $e = min($duration, (float)$r[1]);
            if ($e > $s) {
                $total += $e - $s;
            }
        }
        // Players often stop a fraction short of the final frame.
        if ($duration - $total < 0.75) {
            return 100.0;
        }
        return round(min(100.0, 100.0 * $total / $duration), 2);
    }

    /**
     * Store a batch of watched ranges from the tracked player.
     *
     * @param stdClass $video row from TABLE
     * @param int $userid
     * @param array $ranges
     * @param float $clientduration
     * @return stdClass percent, required, met
     */
    public static function record_view(stdClass $video, int $userid, array $ranges, float $clientduration): stdClass {
        global $DB;

        $now = time();
        if (empty($video->durationverified) && $clientduration > 0 && is_finite($clientduration)) {
            // Provisional until ffprobe confirms it during processing.
            if (empty($video->duration) || abs((float)$video->duration - $clientduration) > 0.5) {
                $video->duration = round($clientduration, 2);
                $DB->set_field(self::TABLE, 'duration', $video->duration, ['id' => $video->id]);
            }
        }
        $duration = (float)($video->duration ?? 0);
        if ($duration > 0) {
            foreach ($ranges as &$r) {
                if (is_array($r) && count($r) >= 2) {
                    $r[1] = min((float)$r[1], $duration + 1);
                }
            }
            unset($r);
        }

        $view = $DB->get_record(self::TABLE_VIEWS, ['videoid' => $video->id, 'userid' => $userid]);
        $existing = $view ? (json_decode($view->intervals ?? '[]', true) ?: []) : [];
        $merged = self::merge_intervals(array_merge($existing, $ranges));
        $percent = self::coverage($merged, $duration);

        if ($view) {
            $view->intervals = json_encode($merged);
            $view->percent = $percent;
            $view->timemodified = $now;
            $DB->update_record(self::TABLE_VIEWS, $view);
        } else {
            $DB->insert_record(self::TABLE_VIEWS, (object)[
                'videoid' => $video->id,
                'userid' => $userid,
                'intervals' => json_encode($merged),
                'percent' => $percent,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }

        $required = self::threshold();
        return (object)['percent' => $percent, 'required' => $required, 'met' => $percent >= $required];
    }

    /**
     * Work out whether the submission currently has a released pass.
     *
     * @param assign $assign
     * @param stdClass $submission
     * @return stdClass passed, reason, graderid, grade, timegraded
     */
    public static function assessment_state(assign $assign, stdClass $submission): stdClass {
        global $DB;

        $state = (object)['passed' => false, 'reason' => 'notgraded', 'graderid' => 0, 'grade' => '', 'timegraded' => 0];
        $instance = $assign->get_instance();

        if (!empty($submission->userid)) {
            $userids = [(int)$submission->userid];
        } else {
            $members = $assign->get_submission_group_members($submission->groupid, true);
            $userids = array_map(function ($u) {
                return (int)$u->id;
            }, $members);
        }
        if (empty($userids)) {
            return $state;
        }

        $passmark = null;
        $scaletop = null;
        $gradeitem = $assign->get_grade_item();
        if ($gradeitem && (float)$gradeitem->gradepass > 0) {
            $passmark = (float)$gradeitem->gradepass;
        } else if ((int)$instance->grade < 0) {
            $scale = $DB->get_field('scale', 'scale', ['id' => -(int)$instance->grade]);
            if ($scale) {
                $scaletop = count(array_filter(array_map('trim', explode(',', $scale)), 'strlen'));
            }
        }
        if ($passmark === null && $scaletop === null) {
            $state->reason = 'nopassmark';
            return $state;
        }

        $latest = null;
        foreach ($userids as $uid) {
            $grade = $DB->get_record('assign_grades', [
                'assignment' => $instance->id,
                'userid' => $uid,
                'attemptnumber' => $submission->attemptnumber,
            ]);
            if (!$grade || $grade->grade === null || (float)$grade->grade < 0) {
                $state->reason = 'notgraded';
                return $state;
            }
            $value = (float)$grade->grade;
            $pass = $passmark !== null ? ($value >= $passmark - 0.00001) : ((int)round($value) === $scaletop);
            if (!$pass) {
                $state->reason = 'notpassed';
                return $state;
            }
            if (!empty($instance->markingworkflow)) {
                $flags = $assign->get_user_flags($uid, false);
                if (!$flags || $flags->workflowstate !== ASSIGN_MARKING_WORKFLOW_STATE_RELEASED) {
                    $state->reason = 'notreleased';
                    return $state;
                }
            }
            if (!$latest || $grade->timemodified > $latest->timemodified) {
                $latest = $grade;
            }
        }

        $state->passed = true;
        $state->reason = '';
        $state->graderid = (int)$latest->grader;
        $state->timegraded = (int)$latest->timemodified;
        $state->grade = trim(html_to_text($assign->display_grade($latest->grade, false, $latest->userid), 0, false));
        return $state;
    }

    /**
     * Has the right assessor watched enough of this video?
     *
     * @param stdClass $video
     * @param stdClass $state from assessment_state()
     * @return array [bool ok, float percent]
     */
    public static function viewing_check(stdClass $video, stdClass $state): array {
        global $DB;

        $duration = (float)($video->duration ?? 0);
        if ($duration <= 0) {
            return [false, 0.0];
        }
        $conditions = ['videoid' => $video->id];
        if (self::config('viewerrule', 'grader') === 'grader') {
            if (empty($state->graderid)) {
                return [false, 0.0];
            }
            $conditions['userid'] = $state->graderid;
        }
        $best = 0.0;
        foreach ($DB->get_records(self::TABLE_VIEWS, $conditions) as $view) {
            $intervals = json_decode($view->intervals ?? '[]', true) ?: [];
            $best = max($best, self::coverage($intervals, $duration));
        }
        return [$best >= self::threshold(), $best];
    }

    /**
     * Re-evaluate a submission and queue evidence capture when everything is in place.
     *
     * Called after grading, workflow release, assessor viewing progress and by the sweep task.
     *
     * @param int $submissionid
     * @return bool whether a task was queued
     */
    public static function check_submission(int $submissionid): bool {
        global $DB;

        $submission = $DB->get_record('assign_submission', ['id' => $submissionid]);
        if (!$submission) {
            return false;
        }
        $rows = $DB->get_records_select(
            self::TABLE,
            "submission = :sid AND (status = :pending OR (status = :failed AND attempts < :max))",
            ['sid' => $submissionid, 'pending' => self::STATUS_PENDING, 'failed' => self::STATUS_FAILED,
            'max' => self::MAX_ATTEMPTS]
        );
        if (!$rows) {
            return false;
        }
        $assign = self::get_assign((int)$submission->assignment);
        if (!self::enabled($assign)) {
            return false;
        }

        $testmode = self::testmode();
        $state = $testmode ? self::test_state() : self::assessment_state($assign, $submission);
        $now = time();
        $queue = false;
        foreach ($rows as $row) {
            $update = (object)['id' => $row->id, 'timemodified' => $now];
            if ($state->passed) {
                [$ok, $percent] = $testmode ? [true, 0.0] : self::viewing_check($row, $state);
                $update->viewpercent = $percent;
                if ($ok) {
                    $update->status = self::STATUS_QUEUED;
                    $update->graderid = $state->graderid;
                    $update->grade = \core_text::substr($state->grade, 0, 100);
                    $update->timegraded = $state->timegraded;
                    $queue = true;
                }
            }
            $DB->update_record(self::TABLE, $update);
        }

        if ($queue) {
            $task = new \assignsubmission_videoevidence\task\build_evidence();
            $task->set_custom_data(['submissionid' => (int)$submissionid]);
            $delay = $testmode ? 0 : max(0, (int)self::config('delaydays', 30)) * DAYSECS;
            if ($delay) {
                $task->set_next_run_time($now + $delay);
            }
            \core\task\manager::queue_adhoc_task($task, true);
        }
        return $queue;
    }

    /**
     * Find the latest submission for a user (individual or team).
     *
     * @param assign $assign
     * @param int $userid
     * @return stdClass|null
     */
    public static function latest_submission_for_user(assign $assign, int $userid): ?stdClass {
        if (!empty($assign->get_instance()->teamsubmission)) {
            $submission = $assign->get_group_submission($userid, 0, false);
        } else {
            $submission = $assign->get_user_submission($userid, false);
        }
        return $submission ?: null;
    }

    /**
     * h:mm:ss / m:ss.
     *
     * @param float $seconds
     * @return string
     */
    public static function format_time(float $seconds): string {
        $seconds = (int)round($seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
