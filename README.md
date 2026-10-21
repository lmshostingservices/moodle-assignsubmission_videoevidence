# Video evidence (assignsubmission_videoevidence)

An assignment submission type for practical/observation tasks. Students upload a video. The assessor watches the **original video** in a tracked player and grades it. Once the video has been both **watched** and **graded as a pass**, the plugin replaces it with a compact **evidence pack** and deletes the video file.

The evidence pack is:

- the full audio track (mono AAC, 64 kbps by default)
- a still image at least every 10 seconds, plus an extra still on any major scene change (854 px longest edge by default)
- `manifest.json`: the original file name, size, duration and **SHA-256 hash**, who uploaded it, who assessed it, the grade, exactly which parts of the video the assessor watched, and when the original was deleted

Teachers and the student see the pack as a synchronised viewer: play the audio and the stills follow along; click any still to jump the audio to that point. The audit record sits underneath.

A typical phone video drops to about 5–10% of its original size.

## When is the video deleted?

All of these must be true:

1. **Pass.** The latest grade is at or above the assignment's *Grade to pass*. If no grade to pass is set and the assignment uses a scale, the top scale item counts as the pass (e.g. *Competent* on a *Not yet competent, Competent* scale). Points grading with no grade to pass never deletes, and assessors see a warning on the submission.
2. **Released.** If marking workflow is on, the grade must be *Released*.
3. **Watched.** The assessor who recorded the pass has actually played at least 90% of the video. Skipped sections don't count. This uses the browser's own record of what was played, and is re-checked against the true video length (from ffprobe) before deletion. You can change this to *any assessor on the course*.
4. **Safety period passed.** Passed videos are kept for **30 days** by default (*Days to wait before deleting*), so a grade can be corrected or the video reviewed for validation, moderation or appeals. During that time the assessor sees *Passed — video kept until [date]*. When the period ends, the grade is re-checked. If it's no longer a pass, the video is kept.
5. **Verified.** The evidence pack was built and checked: the audio length matches the video, the expected number of stills exist, and everything is stored. Only then is the original deleted. If anything fails, the video is kept, the error is shown to assessors, and the plugin retries automatically (3 attempts).

Order doesn't matter. An assessor can grade first and finish watching later, or the other way round. Evidence capture is queued as soon as both conditions are met.

A *Not yet competent* grade never deletes anything. If the grade is changed from a pass to a fail before processing runs, the video is kept.

## Requirements

New uploads require a one-time 50-credit site unlock, or recognition of a verified US$5 Moodle Marketplace purchase.
Configure credentials in LMS Labs Central Config or this plugin's settings. Use Check access, review the live
price and confirm Unlock. Nothing is purchased automatically. Existing submissions and evidence stay readable.
There are no per-video or AI usage charges. Requires the matching LMS Labs catalogue registration to be published.

- Moodle 4.4 to 5.3 (see the tested matrix below)
- **ffmpeg must be installed on the server** (`sudo apt install ffmpeg`, which includes ffprobe). The plugin's settings page shows a red warning box with install steps if it can't find ffmpeg, and a green tick when it can. While ffmpeg is missing, nothing is lost: videos are kept and assessors see "waiting for ffmpeg". Once it's installed, waiting videos are processed automatically within about an hour.
- Cron running. Processing happens in an ad-hoc task, never during the assessor's page load.
- PHP upload limits large enough for video (`upload_max_filesize`, `post_max_size`) and free temp space of roughly 2× the largest video.

## Tested compatibility

Each combination below was installed fresh, upgraded from 1.0.0, and run through the PHPUnit suite (18 tests), 53 end-to-end checks, and a real-browser test of the grader (watch → grade Competent → cron → evidence pack), with full developer debugging switched on.

| Moodle | PHP | PHPUnit | Result |
|---|---|---|---|
| 4.4.12 | 8.3 | 9.6 | Pass |
| 4.5.15 | 8.3 | 9.6 | Pass |
| 5.0.11 | 8.4 | 11.5 | Pass |
| 5.1.8 | 8.4 | 11.5 | Pass |
| 5.2.4 | 8.4 and 8.5 | 11.5 | Pass |
| 5.3 | 8.4 and 8.5 | 11.5 | Pass |

The plugin raises no PHP deprecations on 8.5. Moodle 5.2 and 5.3 core themselves still raise some on PHP 8.5, so run those with `display_errors` off, as on any production server.

The plugin uses no legacy callbacks that Moodle 5.x moved to hooks. It implements the 5.x `submission_summary_for_messages()` API, so grading notifications list the student's videos.

## Install

1. Unzip into `mod/assign/submission/videoevidence`.
2. Go to *Site administration → Notifications*.
3. Check *Site administration → Plugins → Activity modules → Assignment → Submission plugins → Video evidence*, especially the ffmpeg paths.
4. In an assignment: enable **Video evidence** under *Submission types*, then set a *Grade to pass* or use a competency scale.

## Settings

| Setting | Default |
|---|---|
| Seconds between stills | 10 |
| Also capture on scene change | On (at most one extra still every 2s) |
| Still image size | 854 px (480p) |
| Still image quality | Medium |
| Audio bitrate | 64 kbps mono |
| Required viewing | 90% |
| Who must have watched | The assessor who graded it |
| Days to wait before deleting | 30 |
| Maximum videos per submission | 1 (up to 5 per assignment) |

## Test mode

To see the whole thing working without waiting for an assessment or the 30-day safety period, tick **Test mode** at the top of the plugin settings. While it's on:

- every uploaded video is converted the moment it's uploaded (a 35-second clip takes about 2–3 seconds): audio extracted, stills taken, original deleted
- videos already waiting are converted within about an hour
- every safety check is skipped (no passing grade, no viewing, no safety period)
- a red warning shows on the settings page and on every affected submission, and packs are labelled *Test mode — not assessed*

Use it on a test site or test course only, and untick it when you're done.

## Things to know

- **Disk space is freed after Moodle's own cleanup.** Moodle moves deleted files to its trash folder and purges them on a later cron run. Course backups made **before** deletion and the course recycle bin still contain the video until they expire. Set backup retention to match your records policy.
- **Object storage** (e.g. tool_objectfs) works. The video is pulled to a local temp file for processing.
- **Backup/restore** carries videos, evidence packs, tracking records and viewing records.
- **Logs:** each conversion logs a *Video replaced with evidence pack* event with the file name and SHA-256 hash.
- **Download all submissions** includes the evidence pack (stills, audio, manifest) once the video is gone.
- **Privacy API** is implemented for both student data and assessor viewing records.
- **Formats:** anything ffmpeg reads (MP4, MOV, WebM, MKV, etc.). For in-browser playback, MP4 (H.264) is the safest choice for students. iPhone HEVC `.mov` files may not play in Chrome or Firefox, though they still convert fine.

## Status shown to assessors

| Badge | Meaning |
|---|---|
| Original video — awaiting assessment | Not yet passed and/or not yet watched |
| Passed — video kept until [date] | Inside the 30-day safety period |
| Building evidence pack | ffmpeg running |
| Evidence pack (original video removed) | Done |
| Evidence pack failed — original video kept | See the reason shown; retried automatically |
