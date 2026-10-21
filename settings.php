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
 * Admin settings for video evidence submissions.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$c = 'assignsubmission_videoevidence';

$settings->add(new \assignsubmission_videoevidence\admin\setting_activation("$c/activation"));
$settings->add(new admin_setting_configtext(
    "$c/lmslabssiteid", get_string('lmslabssiteid', $c), get_string('lmslabssiteid_desc', $c), '', PARAM_TEXT
));
$settings->add(new admin_setting_configpasswordunmask(
    "$c/lmslabsapikey", get_string('lmslabsapikey', $c), get_string('lmslabsapikey_desc', $c), ''
));

$settings->add(new admin_setting_configcheckbox(
    "$c/default",
    new lang_string('default', $c),
    new lang_string('default_help', $c),
    0
));

// Test mode: highly visible, because it deletes videos without assessment.
$testmodeon = (bool)get_config($c, 'testmode');
$settings->add(new admin_setting_heading(
    "$c/testheading",
    new lang_string('testheading', $c),
    $testmodeon ? html_writer::div(
        html_writer::tag('h4', get_string('testmode_ontitle', $c), ['class' => 'alert-heading']) .
        get_string('testmode_ondesc', $c),
        'alert alert-danger',
        ['role' => 'alert', 'style' => 'border-width: 3px;']
    ) : ''
));
$settings->add(new admin_setting_configcheckbox(
    "$c/testmode",
    new lang_string('testmode', $c),
    new lang_string('testmode_desc', $c),
    0
));

// Live check of the configured tools, shown at the top of the page.
$toolstatus = '';
if ($ADMIN->fulltree) {
    $lines = [];
    $allok = true;
    foreach (['ffmpeg' => 'ffmpegpath', 'ffprobe' => 'ffprobepath'] as $tool => $setting) {
        $path = get_config($c, $setting) ?: '/usr/bin/' . $tool;
        $ok = is_file($path) && is_executable($path);
        $allok = $allok && $ok;
        $lines[] = html_writer::div(get_string(
            $ok ? 'toolfound' : 'toolmissing',
            $c,
            (object)['tool' => $tool, 'path' => s($path)]
        ), 'fw-bold');
    }
    $instructions = get_string('toolsheading_desc', $c);
    if ($allok) {
        // All good: stay calm, keep the instructions one click away.
        $toolstatus = html_writer::div(
            html_writer::div(get_string('toolsok', $c), 'fw-bold mb-1') . implode('', $lines) .
            html_writer::tag(
                'details',
                html_writer::tag('summary', get_string('toolsinstructions', $c)) . html_writer::div($instructions, 'mt-2'),
                ['class' => 'mt-2']
            ),
            'alert alert-success'
        );
    } else {
        $toolstatus = html_writer::div(
            html_writer::tag('h4', get_string('toolsmissingtitle', $c), ['class' => 'alert-heading']) .
            html_writer::div(implode('', $lines), 'mb-3') . $instructions,
            'alert alert-danger',
            ['role' => 'alert', 'style' => 'border-width: 3px;']
        );
    }
}
$settings->add(new admin_setting_heading("$c/toolsheading", new lang_string('toolsheading', $c), $toolstatus));

$settings->add(new admin_setting_configexecutable(
    "$c/ffmpegpath",
    new lang_string('ffmpegpath', $c),
    new lang_string('ffmpegpath_desc', $c),
    '/usr/bin/ffmpeg'
));
$settings->add(new admin_setting_configexecutable(
    "$c/ffprobepath",
    new lang_string('ffprobepath', $c),
    new lang_string('ffprobepath_desc', $c),
    '/usr/bin/ffprobe'
));

$settings->add(new admin_setting_heading("$c/captureheading", new lang_string('captureheading', $c), ''));

$settings->add(new admin_setting_configtext(
    "$c/interval",
    new lang_string('interval', $c),
    new lang_string('interval_desc', $c),
    10,
    PARAM_INT
));

$settings->add(new admin_setting_configcheckbox(
    "$c/scenedetect",
    new lang_string('scenedetect', $c),
    new lang_string('scenedetect_desc', $c),
    1
));

$settings->add(new admin_setting_configselect(
    "$c/framesize",
    new lang_string('framesize', $c),
    new lang_string('framesize_desc', $c),
    854,
    [
        640 => '640 px',
        854 => '854 px (480p)',
        1280 => '1280 px (720p)',
    ]
));

$settings->add(new admin_setting_configselect(
    "$c/jpegquality",
    new lang_string('jpegquality', $c),
    '',
    5,
    [
        3 => new lang_string('qualityhigh', $c),
        5 => new lang_string('qualitymedium', $c),
        8 => new lang_string('qualitylow', $c),
    ]
));

$settings->add(new admin_setting_configselect(
    "$c/audiobitrate",
    new lang_string('audiobitrate', $c),
    new lang_string('audiobitrate_desc', $c),
    64,
    [
        32 => '32 kbps',
        48 => '48 kbps',
        64 => '64 kbps',
        96 => '96 kbps',
    ]
));

$settings->add(new admin_setting_heading(
    "$c/deletionheading",
    new lang_string('deletionheading', $c),
    new lang_string('deletionheading_desc', $c)
));

$settings->add(new admin_setting_configtext(
    "$c/viewthreshold",
    new lang_string('viewthreshold', $c),
    new lang_string('viewthreshold_desc', $c),
    90,
    PARAM_INT
));

$settings->add(new admin_setting_configselect(
    "$c/viewerrule",
    new lang_string('viewerrule', $c),
    new lang_string('viewerrule_desc', $c),
    'grader',
    [
        'grader' => new lang_string('viewerrule_grader', $c),
        'any' => new lang_string('viewerrule_any', $c),
    ]
));

$settings->add(new admin_setting_configtext(
    "$c/delaydays",
    new lang_string('delaydays', $c),
    new lang_string('delaydays_desc', $c),
    30,
    PARAM_INT
));

$settings->add(new admin_setting_heading("$c/uploadheading", new lang_string('uploadheading', $c), ''));

$settings->add(new admin_setting_configtext(
    "$c/maxfiles",
    new lang_string('maxfiles', $c),
    new lang_string('maxfiles_desc', $c),
    1,
    PARAM_INT
));

if (isset($CFG->maxbytes)) {
    $settings->add(new admin_setting_configselect(
        "$c/maxbytes",
        new lang_string('maximumsubmissionsize', $c),
        new lang_string('configmaxbytes', $c),
        0,
        get_max_upload_sizes($CFG->maxbytes, 0, 0, 0)
    ));
}
