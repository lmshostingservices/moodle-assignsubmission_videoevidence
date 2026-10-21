# Changelog

## 1.1.2 (2026-10-20)
- Add the owner-approved 50-credit one-time LMS Labs unlock and verified Marketplace purchase recognition.
- Site administrators review the live price before confirming; uncertain purchases cannot be repeated.
- New uploads require activation. Existing submissions and evidence remain readable.

## 1.1.1 (2026-10-20)
- Privacy: assessors' own viewing records can now be exported and erased through Moodle's standard privacy requests, not only as part of a student's data.
- Web service: viewing ranges are validated as text (PARAM_TEXT) instead of raw input.
- Added LICENSE (GPL v3) and an empty thirdpartylibs.xml (no third-party libraries).
- Coding style: multi-line calls reformatted; test mode labels no longer in capitals.

## 1.1.0 (2026-10-20)
- Supports Moodle 4.4 to 5.3, tested on PHP 8.3, 8.4 and 8.5.
- Grading notification emails now summarise the videos (Moodle 5.0+ API).
- Removed `format_for_log()`, which Moodle 5.x no longer has.
- Added a PHPUnit suite: lifecycle, safety period, test mode, missing ffmpeg, permissions, privacy, backup/restore.

## 1.0.3 (2026-10-20)
- Supports Moodle 4.5 LTS to 5.3.
- Passes the Moodle coding standard (moodle and moodle-extra), ESLint and Stylelint. JavaScript is built with Moodle's grunt.
- Fixed the hourly sweep ignoring its batch limits.

## 1.0.2
- Test mode: convert and delete immediately on upload, for demonstrations and testing.

## 1.0.1
- 30-day safety period before deletion (configurable).
- Settings page checks for ffmpeg and shows install steps. Videos wait safely, without using up retries, until ffmpeg is installed.

## 1.0.0
- First release.
