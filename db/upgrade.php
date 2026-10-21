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
 * Upgrade steps.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_assignsubmission_videoevidence_upgrade($oldversion) {
    if ($oldversion < 2026102001) {
        // Default safety period: keep passed videos for 30 days before deletion.
        if ((int)get_config('assignsubmission_videoevidence', 'delaydays') === 0) {
            set_config('delaydays', 30, 'assignsubmission_videoevidence');
        }
        upgrade_plugin_savepoint(true, 2026102001, 'assignsubmission', 'videoevidence');
    }
    return true;
}
