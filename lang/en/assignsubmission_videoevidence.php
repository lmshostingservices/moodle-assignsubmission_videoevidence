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
 * Language strings.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['assessornotice'] = 'Once this video is graded as a pass, it is kept for {$a} days as a safety period, then replaced by its audio and stills and the video file deleted.';
$string['audiobitrate'] = 'Audio bitrate';
$string['audiobitrate_desc'] = 'Mono AAC. 64 kbps is clear for speech.';
$string['audit_audio'] = 'full audio';
$string['audit_deleted'] = 'Original video deleted';
$string['audit_duration'] = 'Duration';
$string['audit_file'] = 'Original file';
$string['audit_grade'] = 'Grade';
$string['audit_graded'] = 'Graded';
$string['audit_grader'] = 'Assessed by';
$string['audit_noaudio'] = 'no audio in original';
$string['audit_pack'] = 'Evidence pack';
$string['audit_packsummary'] = '{$a->frames} stills (at least one every {$a->interval}s), {$a->audio}; {$a->size}';
$string['audit_sha256'] = 'SHA-256 of original';
$string['audit_size'] = 'Original size';
$string['audit_uploaded'] = 'Uploaded';
$string['audit_viewed'] = 'Portion of video watched by assessor';
$string['audittrail'] = 'Audit record';
$string['captureheading'] = 'Evidence capture';
$string['configmaxbytes'] = 'Default maximum size for each video.';
$string['default'] = 'Enabled by default';
$string['default_help'] = 'If set, this submission method will be enabled by default for all new assignments.';
$string['delaydays'] = 'Days to wait before deleting';
$string['delaydays_desc'] = 'Safety period: the original video is kept for this many days after it is passed and watched, so a grade can be corrected or the video reviewed (validation, moderation, appeals). The grade is re-checked when the period ends; if it is no longer a pass the video is kept. 0 = delete on the next cron run.';
$string['deletionheading'] = 'Video deletion rules';
$string['deletionheading_desc'] = 'The original video is deleted only when ALL of these are true: the latest grade is a pass (at or above the assignment\'s "Grade to pass", or the top scale item such as "Competent" when no grade to pass is set), the grade is released (if marking workflow is used), the assessor has watched the required share of the video, and the evidence pack has been built and verified.';
$string['enabled'] = 'Video evidence';
$string['enabled_help'] = 'Students upload a video. The assessor watches the original video in a tracked player. Once the assessor has watched it and recorded a passing grade, the original video is replaced by an evidence pack (the full audio track plus a still image at regular intervals) and the video file is deleted.';
$string['error_audioduration'] = 'The extracted audio ({$a->audio}s) does not match the video length ({$a->video}s).';
$string['error_ffmpeg'] = 'ffmpeg failed: {$a}';
$string['error_framecount'] = 'Only {$a->got} stills were captured; at least {$a->expected} were expected for a {$a->duration}s video.';
$string['error_missingoriginal'] = 'The original video file could not be found.';
$string['error_noffmpeg'] = 'Waiting for ffmpeg to be installed on the server. The video is kept and will be processed automatically once a site administrator installs ffmpeg (see the Video evidence plugin settings).';
$string['error_noframes'] = 'No still images could be captured from the video.';
$string['error_notpassed'] = 'The grade is no longer a pass. The video has been kept.';
$string['error_viewcoverage'] = 'The assessor has watched {$a->percent}% of the video\'s true length ({$a->duration}s); {$a->required}% is required. The video has been kept.';
$string['event_evidence_created'] = 'Video replaced with evidence pack';
$string['evidencepack'] = 'Evidence pack';
$string['failedreason'] = 'Reason: {$a}';
$string['ffmpegpath'] = 'Path to ffmpeg';
$string['ffmpegpath_desc'] = 'Full path to ffmpeg. Default /usr/bin/ffmpeg. Find it with: which ffmpeg';
$string['ffprobepath'] = 'Path to ffprobe';
$string['ffprobepath_desc'] = 'Full path to ffprobe (installed with ffmpeg). Default /usr/bin/ffprobe. Find it with: which ffprobe';
$string['frameof'] = 'Still {$a->n} of {$a->total} at {$a->time}';
$string['framesize'] = 'Still image size (longest edge)';
$string['framesize_desc'] = 'Stills are scaled so the longest edge is no larger than this. Smaller videos are not upscaled.';
$string['interval'] = 'Seconds between stills';
$string['interval_desc'] = 'A still image is captured at least this often (default 10 seconds).';
$string['jpegquality'] = 'Still image quality';
$string['maxbytessetting'] = 'Maximum video size';
$string['maxfiles'] = 'Maximum videos per submission';
$string['maxfiles_desc'] = 'Default number of videos a student can upload.';
$string['maxfilessubmission'] = 'Maximum number of videos';
$string['maximumsubmissionsize'] = 'Maximum video size';
$string['next'] = 'Next still';
$string['noaudio'] = 'The original video had no audio track.';
$string['nofile'] = 'No video uploaded.';
$string['nopassmark'] = 'Video evidence: this assignment uses points with no "Grade to pass". Videos will not be converted or deleted until a grade to pass is set (Gradebook setup or the Grade section of these settings).';
$string['originalvideo'] = 'Original video';
$string['pluginname'] = 'Video evidence';

$string['previous'] = 'Previous still';
$string['privacy:metadata:filepurpose'] = 'Uploaded videos and the evidence packs (audio and stills) generated from them.';
$string['privacy:metadata:videoevid'] = 'Lifecycle of each uploaded video, including the hash of the original and when it was deleted.';
$string['privacy:metadata:videoevid:graderid'] = 'The assessor whose passing grade triggered the evidence pack.';
$string['privacy:metadata:videoevid:sha256'] = 'SHA-256 hash of the original video.';
$string['privacy:metadata:videoevid:uploadedby'] = 'The user who uploaded the video.';
$string['privacy:metadata:views'] = 'Which parts of each video each assessor watched.';
$string['privacy:metadata:views:intervals'] = 'The time ranges of the video the assessor played.';
$string['privacy:metadata:views:percent'] = 'The share of the video the assessor played.';
$string['privacy:metadata:views:userid'] = 'The assessor who watched the video.';
$string['privacy:assessorpath'] = 'Assessor viewing';
$string['privacy:path'] = 'Video evidence';
$string['qualityhigh'] = 'High';
$string['qualitylow'] = 'Low';
$string['qualitymedium'] = 'Medium';
$string['scenedetect'] = 'Also capture on scene change';
$string['scenedetect_desc'] = 'Capture an extra still when the picture changes significantly between intervals (at most one every 2 seconds), so short actions between stills are not missed.';
$string['status_complete'] = 'Evidence pack (original video removed)';
$string['status_failed'] = 'Evidence pack failed — original video kept';
$string['status_pending'] = 'Original video — awaiting assessment';
$string['status_processing'] = 'Building evidence pack';
$string['status_queued'] = 'Passed — video kept until {$a}, then replaced by evidence pack';
$string['status_queued_soon'] = 'Passed — evidence pack scheduled';
$string['status_waitingffmpeg'] = 'Passed — waiting for ffmpeg on the server, video kept';
$string['studentnotice'] = 'After your assessor watches your video and marks it satisfactory, the video is kept for a short safety period and then replaced by its audio track and still images, which are kept as your assessment record.';
$string['task_sweep'] = 'Video evidence: process passed submissions and retry failures';
$string['testheading'] = 'Test mode';
$string['testmode'] = 'Test mode (convert immediately)';
$string['testmode_banner'] = '⚠ Test mode: this video is converted to audio and stills and deleted immediately on upload, without assessment.';
$string['testmode_desc'] = '<strong>For testing only. Never leave this on for real students.</strong> When ticked, every uploaded video is converted the moment it is uploaded: the audio is extracted, the stills are taken and the original video is deleted. All safety checks are skipped: no assessor viewing, no passing grade and no safety period. Videos already waiting are converted within about an hour. Evidence packs made in test mode are marked "Test mode — not assessed". Requires ffmpeg (see below).';
$string['testmode_grade'] = 'Test mode — not assessed';
$string['testmode_ondesc'] = 'Every uploaded video is converted and the original deleted straight away, with no assessment, no viewing check and no safety period. Untick "Test mode" below and save to return to normal.';
$string['testmode_ontitle'] = '⚠ Test mode is on — videos are deleted immediately on upload';
$string['toolfound'] = '✔ {$a->tool} found at {$a->path}';
$string['toolmissing'] = '✘ {$a->tool} NOT found at {$a->path}';
$string['toolsheading'] = 'Server requirement: ffmpeg';
$string['toolsheading_desc'] = '<p><strong>This plugin needs ffmpeg installed on the server.</strong> ffmpeg (and ffprobe, which is included with it) builds the evidence pack: it extracts the audio and takes the still images. It is not part of Moodle and must be installed by whoever manages the server.</p>
<p><strong>To install on Ubuntu or Debian</strong>, run:</p>
<pre>sudo apt update
sudo apt install ffmpeg</pre>
<p><strong>To check where it is installed</strong>, run:</p>
<pre>which ffmpeg ffprobe</pre>
<p>If the paths shown are not <code>/usr/bin/ffmpeg</code> and <code>/usr/bin/ffprobe</code>, enter the paths shown in the two settings below. Both lines above must show a green tick.</p>
<p><strong>If you run Moodle on more than one server</strong>, install ffmpeg on the server that runs Moodle cron (it does the processing). Installing it on every web server too is harmless and avoids surprises.</p>
<p><strong>What happens if ffmpeg is missing:</strong> nothing is lost. Students can still upload, and assessors can still watch and grade. Videos are simply <em>never converted or deleted</em> while ffmpeg is missing; assessors see "Waiting for ffmpeg to be installed on the server". As soon as ffmpeg is installed, waiting videos are processed automatically within about an hour. No other action is needed.</p>';
$string['toolsinstructions'] = 'Installation instructions';
$string['toolsmissingtitle'] = '⚠ ffmpeg is not installed — videos will NOT be converted or deleted';
$string['toolsok'] = 'ffmpeg is installed. Videos will be converted and deleted as configured.';


$string['uploadheading'] = 'Upload limits';
$string['videos'] = 'Video';
$string['videos_help'] = 'Upload your video. After your assessor has watched it and marked it satisfactory, the video is replaced by its audio and a series of still images, which are kept as your assessment record.';
$string['viewerrule'] = 'Who must have watched';
$string['viewerrule_any'] = 'Any assessor on the course';
$string['viewerrule_desc'] = 'Whether the assessor who recorded the passing grade must be the one who watched the video.';
$string['viewerrule_grader'] = 'The assessor who graded it';
$string['viewprogress'] = 'You have watched {$a->percent}% of this video. {$a->required}% is required before it can be replaced with its evidence pack.';
$string['viewprogressok'] = 'You have watched {$a->percent}% of this video. Viewing requirement met.';
$string['viewthreshold'] = 'Required viewing (%)';
$string['viewthreshold_desc'] = 'Share of the video\'s length the assessor must actually have played. Skipped sections do not count.';

$string['act_access'] = 'Access';
$string['act_balance'] = 'Balance';
$string['act_balance_unknown'] = 'Unknown (check access to update)';
$string['act_balance_unlimited'] = 'Unlimited';
$string['act_blocked_nocredentials'] = 'Add a complete Site ID and API key first.';
$string['act_blocked_pending'] = 'An earlier unlock request has an uncertain outcome. Check access before trying again.';
$string['act_blocked_pendingstorage'] = 'The pending request could not be saved. Nothing was sent; contact your administrator.';
$string['act_blocked_release'] = 'LMS Labs has not confirmed the release and price.';
$string['act_blocked_unlocked'] = 'This site is already unlocked.';
$string['act_blocked_unverified'] = 'Access could not be verified. Check access first.';
$string['act_check'] = 'Check access';
$string['act_checkedat'] = 'checked {$a}';
$string['act_confirm'] = 'Unlock Video Evidence for this site for {$a->price} LMS Labs credits? This spends credits once and cannot be undone. Balance now: {$a->balance}. Release: {$a->release}.';
$string['act_confirmbutton'] = 'Unlock for {$a} credits';
$string['act_credits'] = '{$a} credits';
$string['act_entitlementsource'] = 'entitlement: {$a}';
$string['act_err_network'] = 'no response from LMS Labs';
$string['act_err_nocredentials'] = 'no complete Site ID and API key';
$string['act_intro'] = 'Video Evidence needs one-time activation for this site. Checking access is free. Unlocking spends LMS Labs credits only after you confirm the live price; a verified Moodle Marketplace purchase is not charged again.';
$string['act_msg_already'] = 'This site was already unlocked.';
$string['act_msg_ambiguous'] = 'Not unlocked: LMS Labs could not match this site to a single Marketplace purchase ({$a}). Contact LMS Labs support; trying again will not fix this.';
$string['act_msg_balance'] = 'Balance: {$a}.';
$string['act_msg_blocked'] = 'Nothing was sent to LMS Labs. {$a}';
$string['act_msg_changed'] = 'Not unlocked: the price or release changed after confirmation. Review the new price and try again.';
$string['act_msg_check_locked'] = 'Access checked: this site is locked.';
$string['act_msg_check_unknown'] = 'Access could not be verified ({$a}).';
$string['act_msg_check_unlocked'] = 'Access checked: this site is unlocked.';
$string['act_msg_conflict'] = 'Not unlocked: LMS Labs reported a conflict ({$a}). If the message does not say what to do, contact LMS Labs support.';
$string['act_msg_consumed'] = 'LMS Labs recorded {$a} credits for this unlock.';
$string['act_msg_historic'] = 'Credits used when the unlock was first bought: {$a} (not a new charge).';
$string['act_msg_insufficient'] = 'Not unlocked: not enough credits ({$a}).';
$string['act_msg_notreported'] = 'LMS Labs did not report the credits for this request; check the balance below.';
$string['act_msg_refused'] = 'Not unlocked: LMS Labs refused the request ({$a}).';
$string['act_msg_resolved_locked'] = 'The earlier unlock request did not unlock this site. You can review the price and try again.';
$string['act_msg_resolved_unlocked'] = 'The earlier unlock request did complete.';
$string['act_msg_restoredpurchase'] = 'It was activated from your existing {$a} purchase, so this unlock used 0 credits.';
$string['act_msg_servermessage'] = 'LMS Labs: {$a}';
$string['act_msg_stale'] = 'Not unlocked: the credit price at LMS Labs changed ({$a}). Review the new price and confirm again.';
$string['act_msg_uncertain'] = 'The unlock request did not return a clear answer ({$a}). Check access before trying again; unlocking stays disabled until then.';
$string['act_msg_unlocked'] = 'Video Evidence is unlocked for this site.';
$string['act_notproof'] = 'Having a Site ID and API key does not mean this site has unlocked Video Evidence. Use Check access.';
$string['act_pendingnote'] = 'An unlock request sent {$a} did not return a clear answer.';
$string['act_price'] = 'One-time activation price';
$string['act_price_onreview'] = 'Checked live with LMS Labs when you press Unlock, and shown for you to confirm before anything is spent.';
$string['act_price_unavailable'] = 'Unavailable: {$a}.';
$string['act_reason_mode'] = 'the acquisition mode ({$a}) does not allow unlocking with credits';
$string['act_reason_noprice'] = 'the catalogue entry has no valid credit price';
$string['act_reason_nosha'] = 'the catalogue entry has no valid SHA-256';
$string['act_reason_notavailable'] = 'the release is not available (availability: {$a})';
$string['act_reason_notlisted'] = 'assignsubmission_videoevidence is not in the LMS Labs release catalogue';
$string['act_reason_nozip'] = 'the release package is not available at LMS Labs';
$string['act_reason_unreachable'] = 'the LMS Labs release catalogue could not be read';
$string['act_source'] = 'Credential source';
$string['act_source_central'] = 'Central Config (local_aiconfig)';
$string['act_source_local'] = 'This plugin\'s own Site ID and API key (complete pair)';
$string['act_source_missing'] = 'Not configured';
$string['act_status_locked'] = 'Locked';
$string['act_status_notchecked'] = 'Not checked';
$string['act_status_unknown'] = 'Unable to verify';
$string['act_status_unlocked'] = 'Unlocked';
$string['act_unlock'] = 'Unlock…';
$string['act_unlockedat'] = 'unlocked {$a}';
$string['act_unsaved'] = 'Save any changes below first: these buttons leave this page.';
$string['act_warn_insufficient'] = 'The balance ({$a->balance}) is below the price ({$a->price} credits). LMS Labs will refuse the unlock unless it recognises an existing purchase for this site.';
$string['activation'] = 'Video Evidence activation';
$string['lmslabsapikey'] = 'Standalone LMS Labs API key';
$string['lmslabsapikey_desc'] = 'Used only with the standalone Site ID above. It stays on the server and is sent only to lms-labs.com, in request headers.';
$string['lmslabscredentialsmissing'] = 'Configure this site\'s LMS Labs Site ID and API key in Central Config, or provide both in the Video Evidence settings.';
$string['lmslabssiteid'] = 'Standalone LMS Labs Site ID';
$string['lmslabssiteid_desc'] = 'Leave empty when LMS Labs Central Config (local_aiconfig) is installed: its Site ID and API key are used automatically. Used only when Central Config does not have both, and only together with the standalone API key.';
$string['notactivated'] = 'Video Evidence is not activated on this site yet.';
$string['notactivated_admin'] = 'Video Evidence is not activated on this site yet. Open the Video Evidence settings, press Check access, and review the one-time unlock. Verified Marketplace purchases are recognised without another charge. Existing submissions and evidence remain readable.';
$string['notactivated_learner'] = 'This activity is not available yet. Please try again later.';
$string['notactivated_open'] = 'Open the Video Evidence settings';
$string['notactivated_teacher'] = 'New video uploads require site activation. Ask your site administrator to activate Video Evidence. Existing submissions and evidence remain readable.';
