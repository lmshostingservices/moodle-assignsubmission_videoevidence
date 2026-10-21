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

use assignsubmission_videoevidence\local\manager;

/**
 * Reacts to grading so passed videos are processed promptly.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * A grade was saved.
     *
     * @param \mod_assign\event\submission_graded $event
     */
    public static function submission_graded(\mod_assign\event\submission_graded $event): void {
        self::check($event);
    }

    /**
     * Marking workflow state changed (e.g. released).
     *
     * @param \mod_assign\event\workflow_state_updated $event
     */
    public static function workflow_state_updated(\mod_assign\event\workflow_state_updated $event): void {
        self::check($event);
    }

    /**
     * Shared handler. Cheap: only queues work, never runs ffmpeg in the request.
     *
     * @param \core\event\base $event
     */
    protected static function check(\core\event\base $event): void {
        global $DB;

        $userid = (int)$event->relateduserid;
        if (!$userid) {
            return;
        }
        try {
            $cm = get_coursemodule_from_id('assign', $event->contextinstanceid, 0, false, IGNORE_MISSING);
            if (!$cm || !$DB->record_exists(manager::TABLE, ['assignment' => $cm->instance])) {
                return;
            }
            $assign = manager::get_assign((int)$cm->instance);
            if (!manager::enabled($assign)) {
                return;
            }
            $submission = manager::latest_submission_for_user($assign, $userid);
            if ($submission) {
                manager::check_submission((int)$submission->id);
            }
        } catch (\Throwable $e) {
            // Never break grading; the sweep task will pick it up.
            debugging('assignsubmission_videoevidence observer: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }
}
