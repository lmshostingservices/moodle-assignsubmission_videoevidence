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

namespace assignsubmission_videoevidence\privacy;

use assignsubmission_videoevidence\local\manager;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;
use mod_assign\privacy\assign_plugin_request_data;

/**
 * Privacy provider.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider,
    \mod_assign\privacy\assignsubmission_provider,
    \mod_assign\privacy\assignsubmission_user_provider {
    /**
     * Metadata.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->link_subsystem('core_files', 'privacy:metadata:filepurpose');
        $collection->add_database_table(manager::TABLE, [
            'uploadedby' => 'privacy:metadata:videoevid:uploadedby',
            'graderid' => 'privacy:metadata:videoevid:graderid',
            'sha256' => 'privacy:metadata:videoevid:sha256',
        ], 'privacy:metadata:videoevid');
        $collection->add_database_table(manager::TABLE_VIEWS, [
            'userid' => 'privacy:metadata:views:userid',
            'intervals' => 'privacy:metadata:views:intervals',
            'percent' => 'privacy:metadata:views:percent',
        ], 'privacy:metadata:views');
        return $collection;
    }

    /**
     * Contexts where the user is an assessor who watched videos (student data is found by mod_assign).
     *
     * @param int $userid
     * @param contextlist $contextlist
     */
    public static function get_context_for_userid_within_submission(int $userid, contextlist $contextlist) {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :ctxlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                  JOIN {" . manager::TABLE . "} v ON v.assignment = cm.instance
                  JOIN {" . manager::TABLE_VIEWS . "} w ON w.videoid = v.id
                 WHERE w.userid = :userid";
        $contextlist->add_from_sql($sql, ['ctxlevel' => CONTEXT_MODULE, 'userid' => $userid]);
    }

    /**
     * Not needed: students are found through assign_submission.
     *
     * @param \mod_assign\privacy\useridlist $useridlist
     */
    public static function get_student_user_ids(\mod_assign\privacy\useridlist $useridlist) {
    }

    /**
     * Assessors with viewing records in this context.
     *
     * @param \core_privacy\local\request\userlist $userlist
     */
    public static function get_userids_from_context(\core_privacy\local\request\userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel != CONTEXT_MODULE) {
            return;
        }
        $sql = "SELECT w.userid
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                  JOIN {" . manager::TABLE . "} v ON v.assignment = cm.instance
                  JOIN {" . manager::TABLE_VIEWS . "} w ON w.videoid = v.id
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['cmid' => $context->instanceid]);
    }

    /**
     * Export: the student's videos/evidence, or an assessor's viewing record for this submission.
     *
     * @param assign_plugin_request_data $exportdata
     */
    public static function export_submission_user_data(assign_plugin_request_data $exportdata) {
        global $DB;

        $context = $exportdata->get_context();
        $submission = $exportdata->get_pluginobject();
        $subcontext = array_merge($exportdata->get_subcontext(), [get_string('privacy:path', manager::COMPONENT)]);
        $rows = $DB->get_records(manager::TABLE, ['submission' => $submission->id]);

        if ($exportdata->get_user() != null) {
            // Assessor export: what they watched.
            $userid = $exportdata->get_user()->id;
            $views = [];
            foreach ($rows as $row) {
                $view = $DB->get_record(manager::TABLE_VIEWS, ['videoid' => $row->id, 'userid' => $userid]);
                if ($view) {
                    $views[] = (object)[
                        'video' => $row->filename,
                        'percent' => $view->percent,
                        'intervals' => $view->intervals,
                        'timemodified' => transform::datetime($view->timemodified),
                    ];
                }
            }
            if ($views) {
                writer::with_context($context)->export_data($subcontext, (object)['views' => $views]);
            }
            return;
        }

        $assign = $exportdata->get_assign();
        $plugin = $assign->get_plugin_by_type('assignsubmission', 'videoevidence');
        foreach ($plugin->get_files($submission, new \stdClass()) as $file) {
            writer::with_context($context)->export_file($exportdata->get_subcontext(), $file);
        }
        $records = [];
        foreach ($rows as $row) {
            $records[] = (object)[
                'filename' => $row->filename,
                'status' => $row->status,
                'sha256' => $row->sha256,
                'timecreated' => transform::datetime($row->timecreated),
                'timedeleted' => $row->timedeleted ? transform::datetime($row->timedeleted) : null,
            ];
        }
        if ($records) {
            writer::with_context($context)->export_data($subcontext, (object)['videos' => $records]);
        }
    }

    /**
     * Delete everything in a context.
     *
     * @param assign_plugin_request_data $requestdata
     */
    public static function delete_submission_for_context(assign_plugin_request_data $requestdata) {
        global $DB;
        $fs = get_file_storage();
        $contextid = $requestdata->get_context()->id;
        $fs->delete_area_files($contextid, manager::COMPONENT, manager::AREA_VIDEOS);
        $fs->delete_area_files($contextid, manager::COMPONENT, manager::AREA_EVIDENCE);
        $assignid = $requestdata->get_assign()->get_instance()->id;
        $ids = $DB->get_fieldset_select(manager::TABLE, 'id', 'assignment = ?', [$assignid]);
        if ($ids) {
            [$in, $params] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select(manager::TABLE_VIEWS, "videoid $in", $params);
        }
        $DB->delete_records(manager::TABLE, ['assignment' => $assignid]);
    }

    /**
     * Delete one student's submission data.
     *
     * @param assign_plugin_request_data $deletedata
     */
    public static function delete_submission_for_userid(assign_plugin_request_data $deletedata) {
        self::delete_for_submissions($deletedata, [(int)$deletedata->get_pluginobject()->id]);
    }

    /**
     * Delete several submissions.
     *
     * @param assign_plugin_request_data $deletedata
     */
    public static function delete_submissions(assign_plugin_request_data $deletedata) {
        if (empty($deletedata->get_submissionids())) {
            return;
        }
        self::delete_for_submissions($deletedata, $deletedata->get_submissionids());
    }

    /**
     * Shared deletion.
     *
     * @param assign_plugin_request_data $data
     * @param array $submissionids
     */
    protected static function delete_for_submissions(assign_plugin_request_data $data, array $submissionids): void {
        global $DB;
        $fs = get_file_storage();
        $contextid = $data->get_context()->id;
        [$sql, $params] = $DB->get_in_or_equal($submissionids, SQL_PARAMS_NAMED);
        $fs->delete_area_files_select($contextid, manager::COMPONENT, manager::AREA_VIDEOS, $sql, $params);
        $fs->delete_area_files_select($contextid, manager::COMPONENT, manager::AREA_EVIDENCE, $sql, $params);
        $ids = $DB->get_fieldset_select(manager::TABLE, 'id', "submission $sql", $params);
        if ($ids) {
            [$in, $inparams] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select(manager::TABLE_VIEWS, "videoid $in", $inparams);
            $DB->delete_records_select(manager::TABLE, "id $in", $inparams);
        }
    }

    /*
     * Assessor viewing records.
     *
     * Students' videos and evidence packs are handled through mod_assign (the methods above).
     * The methods below cover data that belongs to assessors: which parts of each video they watched.
     */

    /**
     * Assignment contexts where the user has viewing records.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        self::get_context_for_userid_within_submission($userid, $contextlist);
        return $contextlist;
    }

    /**
     * Assessors with viewing records in a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
        self::get_userids_from_context($userlist);
    }

    /**
     * Export an assessor's viewing records.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $assignid = self::assign_id_for_context($context);
            if (!$assignid) {
                continue;
            }
            $sql = "SELECT w.*, v.filename
                      FROM {" . manager::TABLE_VIEWS . "} w
                      JOIN {" . manager::TABLE . "} v ON v.id = w.videoid
                     WHERE v.assignment = :assignid AND w.userid = :userid";
            $views = [];
            foreach ($DB->get_records_sql($sql, ['assignid' => $assignid, 'userid' => $userid]) as $view) {
                $views[] = (object)[
                    'video' => $view->filename,
                    'percent' => $view->percent,
                    'intervals' => $view->intervals,
                    'timemodified' => transform::datetime($view->timemodified),
                ];
            }
            if ($views) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:path', manager::COMPONENT), get_string('privacy:assessorpath', manager::COMPONENT)],
                    (object)['views' => $views]
                );
            }
        }
    }

    /**
     * Delete all viewing records in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        $assignid = self::assign_id_for_context($context);
        if ($assignid) {
            self::delete_views($assignid, null);
        }
    }

    /**
     * Delete one assessor's viewing records.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        $userid = (int)$contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            $assignid = self::assign_id_for_context($context);
            if ($assignid) {
                self::delete_views($assignid, [$userid]);
            }
        }
    }

    /**
     * Delete viewing records for several assessors in one context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        $assignid = self::assign_id_for_context($userlist->get_context());
        if ($assignid && $userlist->get_userids()) {
            self::delete_views($assignid, $userlist->get_userids());
        }
    }

    /**
     * Assignment id for an assignment module context, or 0.
     *
     * @param \context $context
     * @return int
     */
    protected static function assign_id_for_context(\context $context): int {
        if ($context->contextlevel != CONTEXT_MODULE) {
            return 0;
        }
        $cm = get_coursemodule_from_id('assign', $context->instanceid);
        return $cm ? (int)$cm->instance : 0;
    }

    /**
     * Delete viewing records for an assignment, optionally only for some users.
     *
     * @param int $assignid
     * @param array|null $userids
     */
    protected static function delete_views(int $assignid, ?array $userids): void {
        global $DB;
        $videoids = $DB->get_fieldset_select(manager::TABLE, 'id', 'assignment = ?', [$assignid]);
        if (!$videoids) {
            return;
        }
        [$vsql, $params] = $DB->get_in_or_equal($videoids, SQL_PARAMS_NAMED, 'v');
        $select = "videoid $vsql";
        if ($userids !== null) {
            [$usql, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
            $select .= " AND userid $usql";
            $params += $uparams;
        }
        $DB->delete_records_select(manager::TABLE_VIEWS, $select, $params);
    }
}
