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

namespace assignsubmission_videoevidence\task;

use assignsubmission_videoevidence\local\manager;

/**
 * Safety net: catches passes the event observers missed, retries failures, and un-sticks old queue entries.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sweep extends \core\task\scheduled_task {
    /**
     * Name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_sweep', manager::COMPONENT);
    }

    /**
     * Run.
     */
    public function execute() {
        global $DB;

        $now = time();
        $submissionids = [];

        // Pending videos whose grade changed since we last looked.
        $sql = "SELECT DISTINCT v.submission
                  FROM {" . manager::TABLE . "} v
                  JOIN {assign_submission} s ON s.id = v.submission
                  JOIN {assign_grades} g ON g.assignment = s.assignment
                                        AND g.userid = s.userid
                                        AND g.attemptnumber = s.attemptnumber
                 WHERE v.status = :pending
                   AND g.grade IS NOT NULL
                   AND g.timemodified > v.timemodified";
        $params = ['pending' => manager::STATUS_PENDING];
        foreach (array_keys($DB->get_records_sql($sql, $params, 0, 500)) as $id) {
            $submissionids[(int)$id] = true;
        }

        // Team submissions (userid = 0): re-check pending ones not looked at in the last day.
        $sql = "SELECT DISTINCT v.submission
                  FROM {" . manager::TABLE . "} v
                  JOIN {assign_submission} s ON s.id = v.submission
                 WHERE v.status = :pending AND s.userid = 0 AND v.timemodified < :since";
        $params = ['pending' => manager::STATUS_PENDING, 'since' => $now - DAYSECS];
        foreach (array_keys($DB->get_records_sql($sql, $params, 0, 200)) as $id) {
            $submissionids[(int)$id] = true;
        }

        // Test mode: every waiting video, regardless of grade.
        if (manager::testmode()) {
            $sql = "SELECT DISTINCT submission FROM {" . manager::TABLE . "} WHERE status = :pending";
            $params = ['pending' => manager::STATUS_PENDING];
            foreach (array_keys($DB->get_records_sql($sql, $params, 0, 200)) as $id) {
                $submissionids[(int)$id] = true;
            }
        }

        // Failures with retries left (at most one retry per hour).
        $sql = "SELECT DISTINCT submission FROM {" . manager::TABLE . "}
                 WHERE status = :failed AND attempts < :max AND timemodified < :since";
        $params = ['failed' => manager::STATUS_FAILED, 'max' => manager::MAX_ATTEMPTS, 'since' => $now - HOURSECS];
        foreach (array_keys($DB->get_records_sql($sql, $params, 0, 200)) as $id) {
            $submissionids[(int)$id] = true;
        }

        // Queue entries or crashed runs that never finished.
        $delay = max(0, (int)manager::config('delaydays', 30)) * DAYSECS;
        $select = "(status = :queued AND timemodified < :qsince) OR (status = :processing AND timemodified < :psince)";
        $params = [
            'queued' => manager::STATUS_QUEUED,
            'qsince' => $now - $delay - DAYSECS,
            'processing' => manager::STATUS_PROCESSING,
            'psince' => $now - 6 * HOURSECS,
        ];
        $stuck = $DB->get_records_select(manager::TABLE, $select, $params, '', 'id, submission, status');
        foreach ($stuck as $row) {
            $DB->update_record(manager::TABLE, (object)[
                'id' => $row->id,
                'status' => $row->status === manager::STATUS_PROCESSING ? manager::STATUS_FAILED : manager::STATUS_PENDING,
                'timemodified' => $now - HOURSECS - 1,
            ]);
            $submissionids[(int)$row->submission] = true;
        }

        foreach (array_keys($submissionids) as $id) {
            try {
                if (manager::check_submission($id)) {
                    mtrace("videoevidence: queued submission $id");
                }
            } catch (\Throwable $e) {
                mtrace("videoevidence: submission $id check failed: " . $e->getMessage());
            }
        }
    }
}
