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
 * Restore: videos, evidence packs, tracking rows and assessor viewing records.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_assignsubmission_videoevidence_subplugin extends restore_subplugin {
    /**
     * Paths.
     *
     * @return array
     */
    protected function define_submission_subplugin_structure() {
        return [
            new restore_path_element($this->get_namefor('submission'), $this->get_pathfor('/submission_videoevidence')),
            new restore_path_element(
                $this->get_namefor('video'),
                $this->get_pathfor('/submission_videoevidence/videos/video')
            ),
            new restore_path_element(
                $this->get_namefor('view'),
                $this->get_pathfor('/submission_videoevidence/videos/video/views/view')
            ),
        ];
    }

    /**
     * Per-submission: restore both file areas.
     *
     * @param array $data
     */
    public function process_assignsubmission_videoevidence_submission($data) {
        $data = (object)$data;
        $this->add_related_files(
            'assignsubmission_videoevidence',
            'submission_videos',
            'submission',
            null,
            $data->submission
        );
        $this->add_related_files('assignsubmission_videoevidence', 'evidence', 'submission', null, $data->submission);
    }

    /**
     * One video row.
     *
     * @param array $data
     */
    public function process_assignsubmission_videoevidence_video($data) {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->assignment = $this->get_new_parentid('assign');
        $data->submission = $this->get_mappingid('submission', $data->submission);
        if (!$data->submission) {
            return;
        }
        $data->uploadedby = $this->get_mappingid('user', $data->uploadedby, 0);
        $data->graderid = $this->get_mappingid('user', $data->graderid, 0);
        // Files land in the new module context with subdirs disabled, so the path is predictable.
        $contextid = $this->task->get_contextid();
        $path = "/{$contextid}/assignsubmission_videoevidence/submission_videos/{$data->submission}/{$data->filename}";
        $data->pathnamehash = sha1($path);
        if ($data->status === 'queued' || $data->status === 'processing') {
            $data->status = 'pending';
        }
        $newid = $DB->insert_record('assignsubmission_videoevid', $data);
        $this->set_mapping('assignsubmission_videoevid', $oldid, $newid);
    }

    /**
     * One assessor viewing record.
     *
     * @param array $data
     */
    public function process_assignsubmission_videoevidence_view($data) {
        global $DB;
        $data = (object)$data;
        $data->videoid = $this->get_mappingid('assignsubmission_videoevid', $data->videoid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->videoid || !$data->userid) {
            return;
        }
        $DB->insert_record('assignsubmission_videoev_vw', $data);
    }
}
