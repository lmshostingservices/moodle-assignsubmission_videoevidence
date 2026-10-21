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
 * Backup: videos, evidence packs, tracking rows and assessor viewing records.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_assignsubmission_videoevidence_subplugin extends backup_subplugin {
    /**
     * Structure attached to each submission.
     *
     * @return backup_subplugin_element
     */
    protected function define_submission_subplugin_structure() {
        $subplugin = $this->get_subplugin_element();
        $wrapper = new backup_nested_element($this->get_recommended_name());

        $root = new backup_nested_element('submission_videoevidence', ['id'], ['submission']);
        $videos = new backup_nested_element('videos');
        $video = new backup_nested_element('video', ['id'], [
            'submission', 'filename', 'filesize', 'mimetype', 'uploadedby', 'duration', 'durationverified', 'status',
            'packkey', 'framecount', 'hasaudio', 'packbytes', 'sha256', 'graderid', 'grade', 'timegraded',
            'viewpercent', 'attempts', 'error', 'timeprocessed', 'timedeleted', 'timecreated', 'timemodified',
        ]);
        $views = new backup_nested_element('views');
        $view = new backup_nested_element('view', ['id'], [
            'videoid', 'userid', 'intervals', 'percent', 'timecreated', 'timemodified',
        ]);

        $subplugin->add_child($wrapper);
        $wrapper->add_child($root);
        $root->add_child($videos);
        $videos->add_child($video);
        $video->add_child($views);
        $views->add_child($view);

        $root->set_source_sql(
            'SELECT id, id AS submission FROM {assign_submission} WHERE id = ?',
            [backup::VAR_PARENTID]
        );
        $video->set_source_table('assignsubmission_videoevid', ['submission' => backup::VAR_PARENTID], 'id ASC');
        $view->set_source_table('assignsubmission_videoev_vw', ['videoid' => backup::VAR_PARENTID], 'id ASC');

        $video->annotate_ids('user', 'uploadedby');
        $video->annotate_ids('user', 'graderid');
        $view->annotate_ids('user', 'userid');

        $root->annotate_files('assignsubmission_videoevidence', 'submission_videos', 'submission');
        $root->annotate_files('assignsubmission_videoevidence', 'evidence', 'submission');

        return $subplugin;
    }
}
