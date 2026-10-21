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

namespace assignsubmission_videoevidence\event;

/**
 * Logged when an original video is replaced with its evidence pack and deleted.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class evidence_created extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'assignsubmission_videoevid';
    }

    /**
     * Name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_evidence_created', 'assignsubmission_videoevidence');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        return "The video '{$this->other['filename']}' (SHA-256 {$this->other['sha256']}) uploaded by user " .
            "'{$this->relateduserid}' was replaced with an evidence pack of {$this->other['frames']} stills and audio, " .
            "and the original was deleted, in the assignment with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Link to the grading page.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/mod/assign/view.php', ['id' => $this->contextinstanceid, 'action' => 'grading']);
    }

    /**
     * Validation.
     */
    protected function validate_data() {
        parent::validate_data();
        foreach (['filename', 'sha256', 'frames'] as $key) {
            if (!isset($this->other[$key])) {
                throw new \coding_exception("The '$key' value must be set in other.");
            }
        }
    }

    /**
     * Object id mapping for restore.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'assignsubmission_videoevid', 'restore' => \core\event\base::NOT_MAPPED];
    }

    /**
     * Other mapping.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return ['submissionid' => ['db' => 'assign_submission', 'restore' => 'submission']];
    }
}
