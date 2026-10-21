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

namespace assignsubmission_videoevidence\local;

use assign;
use moodle_exception;
use stdClass;

/**
 * Builds the evidence pack (audio + stills + manifest), verifies it, then deletes the original video.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class processor {
    /** @var assign */
    protected $assign;
    /** @var stdClass */
    protected $submission;
    /** @var stdClass */
    protected $row;
    /** @var stdClass */
    protected $state;
    /** @var string */
    protected $ffmpeg;
    /** @var string */
    protected $ffprobe;

    /**
     * Constructor.
     *
     * @param assign $assign
     * @param stdClass $submission
     * @param stdClass $row
     * @param stdClass $state from manager::assessment_state()
     */
    public function __construct(assign $assign, stdClass $submission, stdClass $row, stdClass $state) {
        $this->assign = $assign;
        $this->submission = $submission;
        $this->row = $row;
        $this->state = $state;
        $this->ffmpeg = (string)manager::config('ffmpegpath', '/usr/bin/ffmpeg');
        $this->ffprobe = (string)manager::config('ffprobepath', '/usr/bin/ffprobe');
    }

    /**
     * Run the whole pipeline for one video. Never throws: failures are recorded on the row.
     *
     * @return string resulting status
     */
    public function run(): string {
        global $DB;

        $row = $this->row;

        // Missing ffmpeg is a server setup issue, not a bad video: wait without using up a retry.
        if (!is_executable($this->ffmpeg) || !is_executable($this->ffprobe)) {
            $DB->update_record(manager::TABLE, (object)[
                'id' => $row->id,
                'status' => manager::STATUS_FAILED,
                'error' => get_string('error_noffmpeg', manager::COMPONENT),
                'timemodified' => time(),
            ]);
            if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
                $paths = $this->ffmpeg . ' / ' . $this->ffprobe;
                mtrace('  videoevidence: ffmpeg/ffprobe not found at ' . $paths . '; video ' . $row->id . ' kept, will retry.');
            }
            return manager::STATUS_FAILED;
        }

        $DB->update_record(manager::TABLE, (object)[
            'id' => $row->id,
            'status' => manager::STATUS_PROCESSING,
            'attempts' => (int)$row->attempts + 1,
            'timemodified' => time(),
        ]);

        $workdir = make_request_directory();
        $src = null;
        $packkey = null;
        try {
            $fs = get_file_storage();
            $original = $fs->get_file_by_hash($row->pathnamehash);
            if (!$original || $original->is_directory()) {
                throw new moodle_exception('error_missingoriginal', manager::COMPONENT);
            }

            // Works for local filedir and object storage alike.
            $src = $original->copy_content_to_temp('assignsubmission_videoevidence', 'src_');
            $sha256 = hash_file('sha256', $src);

            $probe = $this->probe($src);
            $duration = $probe->duration;

            // The viewing requirement is now measured against the true length, not the browser's figure.
            $row->duration = $duration;
            $row->durationverified = 1;
            $DB->update_record(manager::TABLE, (object)[
                'id' => $row->id,
                'duration' => $duration,
                'durationverified' => 1,
            ]);
            $testmode = !empty($this->state->testmode);
            [$ok, $percent] = $testmode ? [true, 0.0] : manager::viewing_check($row, $this->state);
            if (!$ok) {
                $DB->update_record(manager::TABLE, (object)[
                    'id' => $row->id,
                    'status' => manager::STATUS_PENDING,
                    'attempts' => 0,
                    'viewpercent' => $percent,
                    'error' => get_string('error_viewcoverage', manager::COMPONENT, (object)[
                        'percent' => $percent, 'duration' => round($duration), 'required' => manager::threshold()]),
                    'timemodified' => time(),
                ]);
                return manager::STATUS_PENDING;
            }

            // Audio.
            $audiofile = null;
            if ($probe->hasaudio) {
                $audiofile = $workdir . '/audio.m4a';
                $bitrate = (int)manager::config('audiobitrate', 64);
                $this->exec([
                    $this->ffmpeg, '-nostdin', '-hide_banner', '-y', '-i', $src,
                    '-map', '0:a:0', '-vn', '-ac', '1', '-c:a', 'aac', '-b:a', $bitrate . 'k',
                    '-movflags', '+faststart', $audiofile,
                ]);
                $audioduration = $this->probe($audiofile)->duration;
                $tolerance = max(2.0, $duration * 0.02);
                if (abs($audioduration - $duration) > $tolerance) {
                    throw new moodle_exception('error_audioduration', manager::COMPONENT, '', (object)[
                        'audio' => round($audioduration, 1), 'video' => round($duration, 1)]);
                }
            }

            // Stills.
            $frames = $this->extract_frames($src, $workdir . '/frames');
            $interval = manager::interval();
            if (!$frames) {
                throw new moodle_exception('error_noframes', manager::COMPONENT);
            }
            $expected = max(1, (int)floor($duration / $interval));
            if (count($frames) < $expected) {
                throw new moodle_exception('error_framecount', manager::COMPONENT, '', (object)[
                    'got' => count($frames), 'expected' => $expected, 'duration' => round($duration)]);
            }

            // Store the pack.
            $packkey = random_string(12);
            $context = $this->assign->get_context();
            $record = [
                'contextid' => $context->id,
                'component' => manager::COMPONENT,
                'filearea' => manager::AREA_EVIDENCE,
                'itemid' => $this->submission->id,
                'filepath' => '/' . $packkey . '/',
                'userid' => $row->uploadedby ?: null,
            ];
            $packbytes = 0;
            if ($audiofile) {
                $f = $fs->create_file_from_pathname($record + ['filename' => 'audio.m4a'], $audiofile);
                $packbytes += $f->get_filesize();
            }
            $framemeta = [];
            foreach ($frames as $i => $frame) {
                $name = sprintf('still_%05d.jpg', $i + 1);
                $f = $fs->create_file_from_pathname($record + ['filename' => $name], $frame['path']);
                $packbytes += $f->get_filesize();
                $framemeta[] = ['file' => $name, 't' => $frame['t']];
            }

            $now = time();
            $grader = $this->state->graderid ? \core_user::get_user($this->state->graderid) : null;
            $views = [];
            foreach ($DB->get_records(manager::TABLE_VIEWS, ['videoid' => $row->id]) as $view) {
                $intervals = json_decode($view->intervals ?? '[]', true) ?: [];
                $views[] = [
                    'userid' => (int)$view->userid,
                    'percent' => manager::coverage($intervals, $duration),
                    'intervals' => $intervals,
                    'lastviewed' => date('c', (int)$view->timemodified),
                ];
            }
            $manifest = [
                'schema' => 1,
                'testmode' => $testmode,
                'generated' => date('c', $now),
                'original' => [
                    'filename' => $row->filename,
                    'filesize' => (int)$original->get_filesize(),
                    'mimetype' => $original->get_mimetype(),
                    'duration' => $duration,
                    'width' => $probe->width,
                    'height' => $probe->height,
                    'sha256' => $sha256,
                    'uploaded' => date('c', (int)$original->get_timecreated()),
                    'uploadedby' => (int)$row->uploadedby,
                ],
                'assessment' => [
                    'graderid' => (int)$this->state->graderid,
                    'grader' => $grader ? fullname($grader) : '',
                    'grade' => $this->state->grade,
                    'graded' => $this->state->timegraded ? date('c', $this->state->timegraded) : null,
                    'required_view_percent' => manager::threshold(),
                    'viewer_rule' => manager::config('viewerrule', 'grader'),
                    'views' => $views,
                ],
                'capture' => [
                    'interval' => $interval,
                    'scenedetect' => (bool)manager::config('scenedetect', 1),
                    'audio' => $audiofile ? 'audio.m4a' : null,
                    'audio_bitrate_kbps' => $audiofile ? (int)manager::config('audiobitrate', 64) : null,
                    'stills' => $framemeta,
                ],
                'original_deleted' => date('c', $now),
            ];
            $mf = $fs->create_file_from_string(
                $record + ['filename' => 'manifest.json'],
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
            $packbytes += $mf->get_filesize();

            // Everything is stored and verified: remove the original.
            $originalsize = (int)$original->get_filesize();
            $original->delete();

            $DB->update_record(manager::TABLE, (object)[
                'id' => $row->id,
                'status' => manager::STATUS_COMPLETE,
                'packkey' => $packkey,
                'graderid' => (int)$this->state->graderid,
                'grade' => \core_text::substr((string)$this->state->grade, 0, 100),
                'timegraded' => (int)$this->state->timegraded,
                'framecount' => count($framemeta),
                'hasaudio' => $audiofile ? 1 : 0,
                'packbytes' => $packbytes,
                'sha256' => $sha256,
                'filesize' => $originalsize,
                'viewpercent' => $percent,
                'error' => null,
                'timeprocessed' => $now,
                'timedeleted' => $now,
                'timemodified' => $now,
            ]);

            \assignsubmission_videoevidence\event\evidence_created::create([
                'context' => $context,
                'objectid' => $row->id,
                'relateduserid' => $row->uploadedby ?: null,
                'other' => [
                    'filename' => $row->filename,
                    'sha256' => $sha256,
                    'frames' => count($framemeta),
                    'submissionid' => (int)$this->submission->id,
                ],
            ])->trigger();

            return manager::STATUS_COMPLETE;
        } catch (\Throwable $e) {
            if ($packkey) {
                $fs = get_file_storage();
                foreach (
                    $fs->get_directory_files(
                        $this->assign->get_context()->id,
                        manager::COMPONENT,
                        manager::AREA_EVIDENCE,
                        $this->submission->id,
                        '/' . $packkey . '/',
                        true,
                        true
                    ) as $f
                ) {
                    $f->delete();
                }
            }
            $DB->update_record(manager::TABLE, (object)[
                'id' => $row->id,
                'status' => manager::STATUS_FAILED,
                'error' => \core_text::substr($e->getMessage(), 0, 2000),
                'timemodified' => time(),
            ]);
            if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
                mtrace('  videoevidence: video ' . $row->id . ' failed: ' . $e->getMessage());
            }
            return manager::STATUS_FAILED;
        } finally {
            if ($src && file_exists($src)) {
                @unlink($src);
            }
        }
    }

    /**
     * ffprobe a media file.
     *
     * @param string $path
     * @return stdClass duration, hasaudio, hasvideo, width, height
     */
    public function probe(string $path): stdClass {
        $out = $this->exec([
            $this->ffprobe, '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ]);
        $info = json_decode($out->stdout, true);
        if (!is_array($info)) {
            throw new moodle_exception('error_ffmpeg', manager::COMPONENT, '', 'ffprobe returned no data');
        }
        $result = (object)['duration' => 0.0, 'hasaudio' => false, 'hasvideo' => false, 'width' => 0, 'height' => 0];
        $result->duration = (float)($info['format']['duration'] ?? 0);
        foreach ($info['streams'] ?? [] as $stream) {
            $type = $stream['codec_type'] ?? '';
            if ($type === 'audio') {
                $result->hasaudio = true;
            } else if ($type === 'video' && empty($stream['disposition']['attached_pic'])) {
                $result->hasvideo = true;
                $result->width = (int)($stream['width'] ?? 0);
                $result->height = (int)($stream['height'] ?? 0);
                if ($result->duration <= 0 && !empty($stream['duration'])) {
                    $result->duration = (float)$stream['duration'];
                }
            }
        }
        $result->duration = round($result->duration, 2);
        return $result;
    }

    /**
     * Capture stills: one at least every interval, plus scene changes if enabled.
     *
     * @param string $src
     * @param string $dir
     * @return array list of ['path' => string, 't' => float]
     */
    public function extract_frames(string $src, string $dir): array {
        check_dir_exists($dir);
        $interval = manager::interval();
        $size = max(160, (int)manager::config('framesize', 854));
        $quality = (int)manager::config('jpegquality', 5);

        $select = "isnan(prev_selected_t)+gte(t-prev_selected_t,{$interval})";
        if (manager::config('scenedetect', 1)) {
            $select .= "+gt(scene,0.35)*gte(t-prev_selected_t,2)";
        }
        $scale = "scale='if(gt(iw,ih),min({$size},iw),-2)':'if(gt(iw,ih),-2,min({$size},ih))'";
        $filter = "select='{$select}',showinfo,{$scale}";

        $base = [$this->ffmpeg, '-nostdin', '-hide_banner', '-y', '-i', $src, '-an', '-vf', $filter];
        $tail = ['-q:v', (string)$quality, $dir . '/f_%05d.jpg'];
        try {
            $out = $this->exec(array_merge($base, ['-fps_mode', 'vfr'], $tail));
        } catch (moodle_exception $e) {
            // Older ffmpeg (before 5.1) does not know -fps_mode.
            $out = $this->exec(array_merge($base, ['-vsync', 'vfr'], $tail));
        }

        preg_match_all('/Parsed_showinfo.*?\bn:\s*\d+\s+pts:\s*-?\d+\s+pts_time:\s*(-?[0-9.]+)/', $out->stderr, $m);
        $times = $m[1] ?? [];
        $files = glob($dir . '/f_*.jpg') ?: [];
        sort($files);

        $frames = [];
        foreach ($files as $i => $path) {
            $t = isset($times[$i]) ? (float)$times[$i] : (float)($i * $interval);
            $frames[] = ['path' => $path, 't' => round(max(0, $t), 2)];
        }
        return $frames;
    }

    /**
     * Run a command without a shell.
     *
     * @param array $cmd
     * @return stdClass stdout, stderr
     */
    protected function exec(array $cmd): stdClass {
        $dir = make_request_directory();
        $outfile = $dir . '/stdout';
        $errfile = $dir . '/stderr';
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $outfile, 'w'],
            2 => ['file', $errfile, 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new moodle_exception('error_ffmpeg', manager::COMPONENT, '', 'could not start ' . $cmd[0]);
        }
        $code = proc_close($proc);
        $result = (object)[
            'stdout' => (string)@file_get_contents($outfile),
            'stderr' => (string)@file_get_contents($errfile),
        ];
        if ($code !== 0) {
            $lines = array_filter(explode("\n", trim($result->stderr)));
            $last = implode(' | ', array_slice($lines, -3));
            throw new moodle_exception('error_ffmpeg', manager::COMPONENT, '', "exit $code: $last");
        }
        return $result;
    }
}
