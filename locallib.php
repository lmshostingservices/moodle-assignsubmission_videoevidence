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
 * Video evidence submission plugin.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use assignsubmission_videoevidence\local\manager;

/**
 * Video upload; tracked viewing for assessors; evidence pack replaces the video after a pass.
 */
class assign_submission_videoevidence extends assign_submission_plugin {
    /**
     * Name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', manager::COMPONENT);
    }

    /**
     * Assignment-level settings.
     *
     * @param MoodleQuickForm $mform
     */
    public function get_settings(MoodleQuickForm $mform) {
        global $CFG, $COURSE;

        $defaultmaxfiles = $this->get_config('maxfilesubmissions');
        if ($defaultmaxfiles === false) {
            $defaultmaxfiles = (int)manager::config('maxfiles', 1);
        }
        $defaultmaxbytes = $this->get_config('maxsubmissionsizebytes');
        if ($defaultmaxbytes === false) {
            $defaultmaxbytes = (int)manager::config('maxbytes', 0);
        }

        $options = [];
        for ($i = 1; $i <= 5; $i++) {
            $options[$i] = $i;
        }
        $name = get_string('maxfilessubmission', manager::COMPONENT);
        $mform->addElement('select', 'assignsubmission_videoevidence_maxfiles', $name, $options);
        $mform->setDefault('assignsubmission_videoevidence_maxfiles', max(1, min(5, (int)$defaultmaxfiles)));
        $mform->hideIf('assignsubmission_videoevidence_maxfiles', 'assignsubmission_videoevidence_enabled', 'notchecked');

        $choices = get_max_upload_sizes($CFG->maxbytes, $COURSE->maxbytes, get_config(
            'assignsubmission_videoevidence',
            'maxbytes'
        ));
        $mform->addElement(
            'select',
            'assignsubmission_videoevidence_maxbytes',
            get_string('maxbytessetting', manager::COMPONENT),
            $choices
        );
        $mform->setDefault('assignsubmission_videoevidence_maxbytes', $defaultmaxbytes);
        $mform->hideIf('assignsubmission_videoevidence_maxbytes', 'assignsubmission_videoevidence_enabled', 'notchecked');
    }

    /**
     * Save assignment-level settings.
     *
     * @param stdClass $data
     * @return bool
     */
    public function save_settings(stdClass $data) {
        $this->set_config('maxfilesubmissions', (int)($data->assignsubmission_videoevidence_maxfiles ?? 1));
        $this->set_config('maxsubmissionsizebytes', (int)($data->assignsubmission_videoevidence_maxbytes ?? 0));
        return true;
    }

    /**
     * File manager options.
     *
     * @return array
     */
    protected function get_file_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => max(1, (int)$this->get_config('maxfilesubmissions')),
            'maxbytes' => (int)$this->get_config('maxsubmissionsizebytes'),
            'accepted_types' => ['video'],
            'return_types' => FILE_INTERNAL | FILE_CONTROLLED_LINK,
        ];
    }

    /**
     * Student submission form.
     *
     * @param mixed $submission
     * @param MoodleQuickForm $mform
     * @param stdClass $data
     * @return bool
     */
    public function get_form_elements($submission, MoodleQuickForm $mform, stdClass $data) {
        if (!\assignsubmission_videoevidence\local\unlock::active()) {
            $mform->addElement('static', 'videoevidence_locked', '',
                \assignsubmission_videoevidence\local\unlock::locked_notice(false));
            return true;
        }
        $options = $this->get_file_options();
        $submissionid = $submission ? $submission->id : 0;
        $data = file_prepare_standard_filemanager(
            $data,
            'videoevidence',
            $options,
            $this->assignment->get_context(),
            manager::COMPONENT,
            manager::AREA_VIDEOS,
            $submissionid
        );
        $mform->addElement('filemanager', 'videoevidence_filemanager', $this->get_name(), null, $options);
        $mform->addHelpButton('videoevidence_filemanager', 'videos', manager::COMPONENT);
        $mform->addElement(
            'static',
            'videoevidence_notice',
            '',
            manager::testmode() ?
                html_writer::div(get_string('testmode_banner', manager::COMPONENT), 'alert alert-danger fw-bold') :
            html_writer::div(get_string('studentnotice', manager::COMPONENT), 'small text-muted')
        );
        return true;
    }

    /**
     * Save the student's upload.
     *
     * @param stdClass $submission
     * @param stdClass $data
     * @return bool
     */
    public function save(stdClass $submission, stdClass $data) {
        \assignsubmission_videoevidence\local\unlock::require_active();
        file_postupdate_standard_filemanager(
            $data,
            'videoevidence',
            $this->get_file_options(),
            $this->assignment->get_context(),
            manager::COMPONENT,
            manager::AREA_VIDEOS,
            $submission->id
        );
        manager::sync_rows($this->assignment, $submission);

        if (manager::testmode()) {
            // Test mode: build the evidence pack and delete the video right now, in this request.
            global $DB;
            \core_php_time_limit::raise();
            raise_memory_limit(MEMORY_EXTRA);
            $state = manager::test_state();
            $pending = $DB->get_records(manager::TABLE, ['submission' => $submission->id, 'status' => manager::STATUS_PENDING]);
            foreach ($pending as $row) {
                (new \assignsubmission_videoevidence\local\processor($this->assignment, $submission, $row, $state))->run();
            }
        }
        return true;
    }

    /**
     * Rows for this submission, synced with the file area.
     *
     * @param stdClass $submission
     * @return array
     */
    protected function get_rows(stdClass $submission): array {
        global $DB;
        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $this->assignment->get_context()->id,
            manager::COMPONENT,
            manager::AREA_VIDEOS,
            $submission->id,
            'id',
            false
        );
        $live = $DB->count_records_select(
            manager::TABLE,
            'submission = ? AND status <> ?',
            [$submission->id, manager::STATUS_COMPLETE]
        );
        if (count($files) !== $live) {
            manager::sync_rows($this->assignment, $submission);
        }
        return $DB->get_records(manager::TABLE, ['submission' => $submission->id], 'id ASC');
    }

    /**
     * Summary in the grading table, grader and student view.
     *
     * @param stdClass $submission
     * @param bool $showviewlink
     * @return string
     */
    public function view_summary(stdClass $submission, &$showviewlink) {
        $rows = $this->get_rows($submission);
        if (!$rows) {
            $showviewlink = false;
            return '';
        }
        // The grading table lists every student, so keep it light and link to the full view.
        $compact = optional_param('action', '', PARAM_ALPHA) === 'grading';
        if ($compact) {
            $showviewlink = true;
            $items = [];
            foreach ($rows as $row) {
                $items[] = html_writer::span(s($row->filename)) . ' ' . $this->status_badge($row);
            }
            return html_writer::alist($items, ['class' => 'list-unstyled mb-0']);
        }
        $showviewlink = false;
        return $this->render_rows($submission, $rows);
    }

    /**
     * Full view.
     *
     * @param stdClass $submission
     * @return string
     */
    public function view(stdClass $submission) {
        return $this->render_rows($submission, $this->get_rows($submission));
    }

    /**
     * Render all videos / evidence packs for a submission.
     *
     * @param stdClass $submission
     * @param array $rows
     * @return string
     */
    protected function render_rows(stdClass $submission, array $rows): string {
        $context = $this->assignment->get_context();
        $cangrade = has_capability('mod/assign:grade', $context);
        $out = '';

        if (manager::testmode()) {
            $out .= html_writer::div(get_string('testmode_banner', manager::COMPONENT), 'alert alert-danger fw-bold');
        }
        if ($cangrade && $this->has_live_video($rows) && !manager::testmode()) {
            $state = manager::assessment_state($this->assignment, $submission);
            if ($state->reason === 'nopassmark') {
                $out .= html_writer::div(get_string('nopassmark', manager::COMPONENT), 'alert alert-warning small');
            }
        }
        foreach ($rows as $row) {
            if ($row->status === manager::STATUS_COMPLETE) {
                $out .= $this->render_evidence($submission, $row, $cangrade);
            } else {
                $out .= $this->render_player($submission, $row, $cangrade);
            }
        }
        return html_writer::div($out, 'assignsubmission_videoevidence');
    }

    /**
     * Any original videos still present?
     *
     * @param array $rows
     * @return bool
     */
    protected function has_live_video(array $rows): bool {
        foreach ($rows as $row) {
            if ($row->status !== manager::STATUS_COMPLETE) {
                return true;
            }
        }
        return false;
    }

    /**
     * Status badge.
     *
     * @param stdClass $row
     * @return string
     */
    protected function status_badge(stdClass $row): string {
        $classes = [
            manager::STATUS_PENDING => 'badge bg-secondary text-white',
            manager::STATUS_QUEUED => 'badge bg-info text-dark',
            manager::STATUS_PROCESSING => 'badge bg-info text-dark',
            manager::STATUS_COMPLETE => 'badge bg-success text-white',
            manager::STATUS_FAILED => 'badge bg-danger text-white',
        ];
        $label = get_string('status_' . $row->status, manager::COMPONENT);
        if ($row->status === manager::STATUS_QUEUED) {
            $delay = max(0, (int)manager::config('delaydays', 30)) * DAYSECS;
            $label = $delay ? get_string(
                'status_queued',
                manager::COMPONENT,
                userdate((int)$row->timemodified + $delay, get_string('strftimedatetimeshort', 'langconfig'))
            ) :
                get_string('status_queued_soon', manager::COMPONENT);
        }
        if ($row->status === manager::STATUS_FAILED && (int)$row->attempts === 0) {
            // Only a missing ffmpeg fails without using an attempt.
            return html_writer::span(get_string('status_waitingffmpeg', manager::COMPONENT), 'badge bg-warning text-dark');
        }
        return html_writer::span($label, $classes[$row->status] ?? 'badge bg-secondary');
    }

    /**
     * The original video, tracked for assessors.
     *
     * @param stdClass $submission
     * @param stdClass $row
     * @param bool $cangrade
     * @return string
     */
    protected function render_player(stdClass $submission, stdClass $row, bool $cangrade): string {
        global $DB, $PAGE, $USER;

        $fs = get_file_storage();
        $file = $fs->get_file_by_hash($row->pathnamehash);
        if (!$file) {
            return html_writer::div(
                s($row->filename) . ' — ' . get_string('error_missingoriginal', manager::COMPONENT),
                'alert alert-danger small'
            );
        }
        $url = moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            manager::COMPONENT,
            manager::AREA_VIDEOS,
            $submission->id,
            $file->get_filepath(),
            $file->get_filename()
        );
        $domid = 've-video-' . $row->id . '-' . random_string(6);

        $head = html_writer::div(
            html_writer::span(s($row->filename), 'fw-bold me-2') .
            html_writer::span(display_size($file->get_filesize()), 'text-muted small me-2') .
            $this->status_badge($row),
            've-head mb-1'
        );

        $video = html_writer::tag('video', '', [
            'id' => $domid,
            'src' => $url->out(false),
            'controls' => 'controls',
            'preload' => 'metadata',
            'playsinline' => 'playsinline',
            'controlsList' => 'nodownload',
            'class' => 've-video',
        ]);

        $foot = '';
        if ($cangrade) {
            $view = $DB->get_record(manager::TABLE_VIEWS, ['videoid' => $row->id, 'userid' => $USER->id]);
            $percent = $view ? manager::coverage(
                json_decode($view->intervals ?? '[]', true) ?: [],
                (float)($row->duration ?? 0)
            ) : 0;
            $required = manager::threshold();
            $key = $percent >= $required ? 'viewprogressok' : 'viewprogress';
            $foot .= html_writer::div(
                get_string(
                    $key,
                    manager::COMPONENT,
                    (object)['percent' => format_float($percent, 0), 'required' => format_float($required, 0)]
                ),
                've-progress small',
                ['id' => $domid . '-progress']
            );
            if ($row->status === manager::STATUS_PENDING) {
                $foot .= html_writer::div(get_string(
                    'assessornotice',
                    manager::COMPONENT,
                    max(0, (int)manager::config('delaydays', 30))
                ), 'small text-muted');
            }
            if (!empty($row->error) && in_array($row->status, [manager::STATUS_FAILED, manager::STATUS_PENDING])) {
                $foot .= html_writer::div(
                    get_string('failedreason', manager::COMPONENT, s($row->error)),
                    'small text-danger'
                );
            }
            $PAGE->requires->js_call_amd('assignsubmission_videoevidence/tracker', 'init', [
                $domid, (int)$row->id, $required,
            ]);
        }

        return html_writer::div($head . $video . $foot, 've-item ve-player mb-3');
    }

    /**
     * The evidence pack: audio with synchronised stills, plus audit record.
     *
     * @param stdClass $submission
     * @param stdClass $row
     * @param bool $cangrade
     * @return string
     */
    protected function render_evidence(stdClass $submission, stdClass $row, bool $cangrade): string {
        global $PAGE;

        $context = $this->assignment->get_context();
        $fs = get_file_storage();
        $files = $fs->get_directory_files(
            $context->id,
            manager::COMPONENT,
            manager::AREA_EVIDENCE,
            $submission->id,
            '/' . $row->packkey . '/',
            false,
            false,
            'filename'
        );
        $manifest = null;
        $audio = null;
        $stills = [];
        foreach ($files as $f) {
            $name = $f->get_filename();
            if ($name === 'manifest.json') {
                $manifest = $f;
            } else if ($name === 'audio.m4a') {
                $audio = $f;
            } else if (preg_match('/^still_\d+\.jpg$/', $name)) {
                $stills[$name] = $f;
            }
        }
        $meta = $manifest ? (json_decode($manifest->get_content(), true) ?: []) : [];
        $times = [];
        foreach ($meta['capture']['stills'] ?? [] as $s) {
            $times[$s['file']] = (float)$s['t'];
        }

        $mkurl = function (stored_file $f) use ($submission) {
            return moodle_url::make_pluginfile_url(
                $f->get_contextid(),
                manager::COMPONENT,
                manager::AREA_EVIDENCE,
                $submission->id,
                $f->get_filepath(),
                $f->get_filename()
            )->out(false);
        };

        $frames = [];
        $i = 0;
        foreach ($stills as $name => $f) {
            $frames[] = ['u' => $mkurl($f), 't' => $times[$name] ?? ($i * manager::interval())];
            $i++;
        }
        $domid = 've-evidence-' . $row->id . '-' . random_string(6);

        $head = html_writer::div(
            html_writer::span(s($row->filename), 'fw-bold me-2') . $this->status_badge($row),
            've-head mb-1'
        );

        $first = $frames[0] ?? null;
        $total = count($frames);
        $caption = $first ? get_string('frameof', manager::COMPONENT, (object)[
            'n' => 1,
            'total' => $total,
            'time' => manager::format_time($first['t']),
        ]) : '';
        $stage = html_writer::div(
            ($first ? html_writer::empty_tag('img', ['src' => $first['u'], 'class' => 've-still', 'alt' => $caption]) : '') .
            html_writer::div($caption, 've-caption small text-muted'),
            've-stage'
        );

        $controls = '';
        if ($audio) {
            $controls .= html_writer::tag('audio', '', [
                'src' => $mkurl($audio),
                'controls' => 'controls',
                'preload' => 'metadata',
                'class' => 've-audio',
                'controlsList' => 'nodownload',
            ]);
        } else {
            $controls .= html_writer::div(get_string('noaudio', manager::COMPONENT), 'small text-muted');
        }
        $controls .= html_writer::div(
            html_writer::tag('button', '&larr;', [
                'type' => 'button',
                'class' => 'btn btn-sm btn-secondary ve-prev',
                'aria-label' => get_string('previous', manager::COMPONENT),
            ]) .
            html_writer::tag('button', '&rarr;', [
                'type' => 'button',
                'class' => 'btn btn-sm btn-secondary ve-next',
                'aria-label' => get_string('next', manager::COMPONENT),
            ]),
            've-nav mt-1'
        );

        $strip = '';
        foreach ($frames as $n => $fr) {
            $strip .= html_writer::tag(
                'button',
                html_writer::empty_tag('img', ['src' => $fr['u'], 'loading' => 'lazy', 'alt' => '']) .
                html_writer::span(manager::format_time($fr['t']), 've-thumbtime'),
                ['type' => 'button', 'class' => 've-thumb' . ($n === 0 ? ' active' : ''), 'data-index' => $n]
            );
        }
        $strip = html_writer::div($strip, 've-strip');

        $audit = $this->render_audit($row, $meta, $manifest ? $mkurl($manifest) : null);

        $PAGE->requires->js_call_amd('assignsubmission_videoevidence/evidence', 'init', [$domid, $frames]);

        return html_writer::div(
            $head . $stage . $controls . $strip . $audit,
            've-item ve-evidence mb-3',
            ['id' => $domid]
        );
    }

    /**
     * Audit record block.
     *
     * @param stdClass $row
     * @param array $meta
     * @param string|null $manifesturl
     * @return string
     */
    protected function render_audit(stdClass $row, array $meta, ?string $manifesturl): string {
        $c = manager::COMPONENT;
        $grader = $row->graderid ? \core_user::get_user($row->graderid) : null;
        $items = [
            'audit_file' => s($row->filename),
            'audit_size' => display_size((int)$row->filesize),
            'audit_duration' => manager::format_time((float)$row->duration),
            'audit_sha256' => html_writer::tag('code', s($row->sha256), ['class' => 'text-break']),
            'audit_uploaded' => !empty($meta['original']['uploaded']) ?
                userdate(strtotime($meta['original']['uploaded'])) : '',
            'audit_grader' => $grader ? fullname($grader) : s($meta['assessment']['grader'] ?? ''),
            'audit_grade' => s($row->grade),
            'audit_graded' => $row->timegraded ? userdate($row->timegraded) : '',
            'audit_viewed' => format_float((float)$row->viewpercent, 0) . '%',
            'audit_deleted' => $row->timedeleted ? userdate($row->timedeleted) : '',
            'audit_pack' => get_string('audit_packsummary', $c, (object)[
                'frames' => (int)$row->framecount,
                'interval' => (int)($meta['capture']['interval'] ?? manager::interval()),
                'audio' => get_string($row->hasaudio ? 'audit_audio' : 'audit_noaudio', $c),
                'size' => display_size((int)$row->packbytes),
            ]),
        ];
        $dl = '';
        foreach ($items as $key => $value) {
            $dl .= html_writer::tag('dt', get_string($key, $c), ['class' => 'col-sm-4']) .
                html_writer::tag('dd', $value, ['class' => 'col-sm-8']);
        }
        $body = html_writer::tag('dl', $dl, ['class' => 'row small mb-1']);
        if ($manifesturl) {
            $body .= html_writer::link($manifesturl, 'manifest.json', ['class' => 'small']);
        }
        return html_writer::tag(
            'details',
            html_writer::tag('summary', get_string('audittrail', $c), ['class' => 'small']) . $body,
            ['class' => 've-audit mt-2']
        );
    }

    /**
     * Files for "download all submissions" and privacy export.
     *
     * @param stdClass $submission
     * @param stdClass $user
     * @return array
     */
    public function get_files(stdClass $submission, stdClass $user) {
        global $DB;
        $result = [];
        $context = $this->assignment->get_context();
        $fs = get_file_storage();
        foreach (
            $fs->get_area_files(
                $context->id,
                manager::COMPONENT,
                manager::AREA_VIDEOS,
                $submission->id,
                'timemodified',
                false
            ) as $file
        ) {
            $result[$file->get_filepath() . $file->get_filename()] = $file;
        }
        $rows = $DB->get_records(manager::TABLE, ['submission' => $submission->id, 'status' => manager::STATUS_COMPLETE]);
        foreach ($rows as $row) {
            $folder = clean_filename(pathinfo($row->filename, PATHINFO_FILENAME)) . '_evidence';
            foreach (
                $fs->get_directory_files(
                    $context->id,
                    manager::COMPONENT,
                    manager::AREA_EVIDENCE,
                    $submission->id,
                    '/' . $row->packkey . '/',
                    false,
                    false
                ) as $file
            ) {
                $result['/' . $folder . '/' . $file->get_filename()] = $file;
            }
        }
        return $result;
    }

    /**
     * Empty submission?
     *
     * @param stdClass $submission
     * @return bool
     */
    public function is_empty(stdClass $submission) {
        global $DB;
        if (empty($submission->id)) {
            return true;
        }
        $fs = get_file_storage();
        if (
            !$fs->is_area_empty(
                $this->assignment->get_context()->id,
                manager::COMPONENT,
                manager::AREA_VIDEOS,
                $submission->id
            )
        ) {
            return false;
        }
        return !$DB->record_exists(manager::TABLE, ['submission' => $submission->id, 'status' => manager::STATUS_COMPLETE]);
    }

    /**
     * Is the posted form empty?
     *
     * @param stdClass $data
     * @return bool
     */
    public function submission_is_empty(stdClass $data) {
        global $USER;
        if (empty($data->videoevidence_filemanager)) {
            return true;
        }
        $fs = get_file_storage();
        $usercontext = context_user::instance($USER->id);
        return $fs->is_area_empty($usercontext->id, 'user', 'draft', $data->videoevidence_filemanager);
    }

    /**
     * File areas.
     *
     * @return array
     */
    public function get_file_areas() {
        return [
            manager::AREA_VIDEOS => get_string('originalvideo', manager::COMPONENT),
            manager::AREA_EVIDENCE => get_string('evidencepack', manager::COMPONENT),
        ];
    }

    /**
     * New attempt based on the previous one: carry over any videos not yet converted.
     *
     * @param stdClass $oldsubmission
     * @param stdClass $submission
     * @return bool
     */
    public function copy_submission(stdClass $oldsubmission, stdClass $submission) {
        $contextid = $this->assignment->get_context()->id;
        $fs = get_file_storage();
        foreach (
            $fs->get_area_files(
                $contextid,
                manager::COMPONENT,
                manager::AREA_VIDEOS,
                $oldsubmission->id,
                'id',
                false
            ) as $file
        ) {
            $fs->create_file_from_storedfile(['itemid' => $submission->id], $file);
        }
        manager::sync_rows($this->assignment, $submission);
        return true;
    }

    /**
     * Student removed their submission: remove unassessed videos; evidence packs are assessment records and stay.
     *
     * @param stdClass $submission
     * @return bool
     */
    public function remove(stdClass $submission) {
        global $DB;
        $fs = get_file_storage();
        $fs->delete_area_files(
            $this->assignment->get_context()->id,
            manager::COMPONENT,
            manager::AREA_VIDEOS,
            $submission->id
        );
        $rows = $DB->get_records_select(
            manager::TABLE,
            'submission = ? AND status <> ?',
            [$submission->id, manager::STATUS_COMPLETE],
            '',
            'id'
        );
        if ($rows) {
            [$in, $params] = $DB->get_in_or_equal(array_keys($rows));
            $DB->delete_records_select(manager::TABLE_VIEWS, "videoid $in", $params);
            $DB->delete_records_select(manager::TABLE, "id $in", $params);
        }
        return true;
    }

    /**
     * Assignment deleted.
     *
     * @return bool
     */
    public function delete_instance() {
        global $DB;
        $assignid = $this->assignment->get_instance()->id;
        $ids = $DB->get_fieldset_select(manager::TABLE, 'id', 'assignment = ?', [$assignid]);
        if ($ids) {
            [$in, $params] = $DB->get_in_or_equal($ids);
            $DB->delete_records_select(manager::TABLE_VIEWS, "videoid $in", $params);
        }
        $DB->delete_records(manager::TABLE, ['assignment' => $assignid]);
        return true;
    }

    /**
     * Summary for grading notifications (Moodle 5.0+; ignored by older versions).
     *
     * @param stdClass $submission
     * @return string[] plain text and HTML summaries
     */
    public function submission_summary_for_messages(stdClass $submission): array {
        global $DB;
        $rows = $DB->get_records(manager::TABLE, ['submission' => $submission->id], 'id ASC');
        if (!$rows) {
            return ['', ''];
        }
        $text = '';
        $items = '';
        foreach ($rows as $row) {
            $status = $row->status === manager::STATUS_COMPLETE ?
                get_string('status_complete', manager::COMPONENT) : get_string('originalvideo', manager::COMPONENT);
            $text .= '* ' . $row->filename . ' (' . $status . ")\n";
            $items .= html_writer::tag('li', s($row->filename) . ' (' . s($status) . ')');
        }
        $heading = get_string('pluginname', manager::COMPONENT);
        return [$heading . "\n" . $text, html_writer::tag('h3', s($heading)) . html_writer::tag('ul', $items)];
    }

    /**
     * Config for external/mobile.
     *
     * @return array
     */
    public function get_config_for_external() {
        return (array)$this->get_config();
    }
}
