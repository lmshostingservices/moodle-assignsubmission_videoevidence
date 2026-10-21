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
 * File serving for videos and evidence packs.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serve submission videos (with HTTP range support for seeking) and evidence pack files.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if not found
 */
function assignsubmission_videoevidence_pluginfile(
    $course,
    $cm,
    context $context,
    $filearea,
    $args,
    $forcedownload,
    array $options = []
) {
    global $DB, $CFG;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }
    if (!in_array($filearea, ['submission_videos', 'evidence'])) {
        return false;
    }

    require_login($course, false, $cm);
    $itemid = (int)array_shift($args);
    $record = $DB->get_record('assign_submission', ['id' => $itemid], 'userid, assignment, groupid', MUST_EXIST);

    require_once($CFG->dirroot . '/mod/assign/locallib.php');
    $assign = new assign($context, $cm, $course);
    if ($assign->get_instance()->id != $record->assignment) {
        return false;
    }
    if ($assign->get_instance()->teamsubmission && !$assign->can_view_group_submission($record->groupid)) {
        return false;
    }
    if (!$assign->get_instance()->teamsubmission && !$assign->can_view_submission($record->userid)) {
        return false;
    }

    $relativepath = implode('/', $args);
    $fullpath = "/{$context->id}/assignsubmission_videoevidence/$filearea/$itemid/$relativepath";
    $fs = get_file_storage();
    if (!($file = $fs->get_file_by_hash(sha1($fullpath))) || $file->is_directory()) {
        return false;
    }

    // No caching, so a deleted video stops being served immediately. Range requests are supported for seeking.
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
