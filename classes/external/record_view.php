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

namespace assignsubmission_videoevidence\external;

use assignsubmission_videoevidence\local\manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Records which parts of a video an assessor has played.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class record_view extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'videoid' => new external_value(PARAM_INT, 'Video id'),
            'ranges' => new external_value(PARAM_TEXT, 'JSON array of [start, end] played ranges in seconds'),
            'duration' => new external_value(PARAM_FLOAT, 'Duration reported by the browser', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Execute.
     *
     * @param int $videoid
     * @param string $ranges
     * @param float $duration
     * @return array
     */
    public static function execute(int $videoid, string $ranges, float $duration = 0): array {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::execute_parameters(),
            ['videoid' => $videoid, 'ranges' => $ranges, 'duration' => $duration]
        );

        $video = $DB->get_record(manager::TABLE, ['id' => $params['videoid']], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('assign', $video->assignment, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/assign:grade', $context);

        if ($video->status === manager::STATUS_COMPLETE) {
            return ['percent' => (float)$video->viewpercent, 'required' => manager::threshold(), 'met' => true,
                'queued' => false];
        }

        $decoded = json_decode($params['ranges'], true);
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $decoded = array_slice($decoded, 0, 500);

        $result = manager::record_view($video, (int)$USER->id, $decoded, (float)$params['duration']);
        $queued = false;
        if ($result->met) {
            $queued = manager::check_submission((int)$video->submission);
        }
        return ['percent' => $result->percent, 'required' => $result->required, 'met' => $result->met,
            'queued' => $queued];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'percent' => new external_value(PARAM_FLOAT, 'Share watched by this user'),
            'required' => new external_value(PARAM_FLOAT, 'Share required'),
            'met' => new external_value(PARAM_BOOL, 'Requirement met'),
            'queued' => new external_value(PARAM_BOOL, 'Evidence capture was queued by this call'),
        ]);
    }
}
