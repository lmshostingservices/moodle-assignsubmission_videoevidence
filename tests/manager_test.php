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

namespace assignsubmission_videoevidence;

use assignsubmission_videoevidence\local\manager;

/**
 * Unit tests for the viewing-coverage maths.
 *
 * @package    assignsubmission_videoevidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \assignsubmission_videoevidence\local\manager
 */
#[\PHPUnit\Framework\Attributes\CoversClass(manager::class)]
final class manager_test extends \basic_testcase {
    /**
     * Overlapping, touching and out-of-order ranges merge into one.
     */
    public function test_merge_intervals_merges_and_sorts(): void {
        $merged = manager::merge_intervals([[20, 30], [0, 10], [9.8, 15], [15.3, 18]]);
        $this->assertSame([[0.0, 18.0], [20.0, 30.0]], $merged);
    }

    /**
     * Junk input from a browser is ignored rather than trusted.
     */
    public function test_merge_intervals_rejects_invalid(): void {
        $merged = manager::merge_intervals([[5, 2], [-1, 3], ['x', 'y'], [1], 'bad', [INF, 4], [3, 6]]);
        $this->assertSame([[3.0, 6.0]], $merged);
    }

    /**
     * Coverage only counts what was played, clamped to the video.
     */
    public function test_coverage(): void {
        $this->assertEqualsWithDelta(57.14, manager::coverage([[0, 15], [30, 35]], 35), 0.01);
        $this->assertSame(100.0, manager::coverage([[0, 34.5]], 35));
        $this->assertSame(100.0, manager::coverage([[0, 50]], 35));
        $this->assertSame(0.0, manager::coverage([[0, 10]], 0));
        $this->assertSame(0.0, manager::coverage([], 35));
    }

    /**
     * Time formatting.
     */
    public function test_format_time(): void {
        $this->assertSame('0:07', manager::format_time(7));
        $this->assertSame('1:05', manager::format_time(65.4));
        $this->assertSame('1:01:01', manager::format_time(3661));
    }
}
