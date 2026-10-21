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
use assignsubmission_videoevidence\local\processor;

/**
 * Builds evidence packs for a submission's queued videos and deletes the originals.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class build_evidence extends \core\task\adhoc_task {
    /**
     * Run the task.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $submissionid = (int)($data->submissionid ?? 0);
        $submission = $DB->get_record('assign_submission', ['id' => $submissionid]);
        if (!$submission) {
            return;
        }
        $rows = $DB->get_records(manager::TABLE, ['submission' => $submissionid, 'status' => manager::STATUS_QUEUED]);
        if (!$rows) {
            return;
        }

        $assign = manager::get_assign((int)$submission->assignment);

        // The grade may have changed since the task was queued (for example during a deletion delay).
        $state = manager::testmode() ? manager::test_state() : manager::assessment_state($assign, $submission);
        if (!$state->passed) {
            foreach ($rows as $row) {
                $DB->update_record(manager::TABLE, (object)[
                    'id' => $row->id,
                    'status' => manager::STATUS_PENDING,
                    'error' => get_string('error_notpassed', manager::COMPONENT),
                    'timemodified' => time(),
                ]);
            }
            mtrace("videoevidence: submission $submissionid is no longer a pass; videos kept.");
            return;
        }

        foreach ($rows as $row) {
            mtrace("videoevidence: building evidence pack for video {$row->id} ({$row->filename})");
            $status = (new processor($assign, $submission, $row, $state))->run();
            mtrace("videoevidence: video {$row->id} -> $status");
        }
    }
}
