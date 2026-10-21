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
 * Tracks which parts of a submitted video the assessor actually plays.
 *
 * Uses the browser's own HTMLMediaElement.played ranges, so skipped sections never count.
 *
 * @module     assignsubmission_videoevidence/tracker
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/str'], function(Ajax, Str) {

    var METHOD = 'assignsubmission_videoevidence_record_view';

    /**
     * Played ranges as [[start, end], ...].
     *
     * @param {HTMLVideoElement} video
     * @returns {Array}
     */
    var playedRanges = function(video) {
        var out = [];
        for (var i = 0; i < video.played.length; i++) {
            out.push([Math.round(video.played.start(i) * 100) / 100, Math.round(video.played.end(i) * 100) / 100]);
        }
        return out;
    };

    return {
        /**
         * Start tracking.
         *
         * @param {String} domid video element id
         * @param {Number} videoid
         * @param {Number} required percent required
         */
        init: function(domid, videoid, required) {
            var video = document.getElementById(domid);
            if (!video || video.dataset.veTracked) {
                return;
            }
            video.dataset.veTracked = '1';
            var progress = document.getElementById(domid + '-progress');
            var lastSent = '';
            var busy = false;

            var payload = function() {
                var ranges = JSON.stringify(playedRanges(video));
                if (ranges === '[]' || ranges === lastSent) {
                    return null;
                }
                return {
                    videoid: videoid,
                    ranges: ranges,
                    duration: isFinite(video.duration) ? video.duration : 0
                };
            };

            var showProgress = function(result) {
                if (!progress) {
                    return;
                }
                var key = result.met ? 'viewprogressok' : 'viewprogress';
                Str.get_string(key, 'assignsubmission_videoevidence', {
                    percent: Math.floor(result.percent),
                    required: Math.round(result.required)
                }).then(function(text) {
                    progress.textContent = text;
                    progress.classList.toggle('text-success', !!result.met);
                    return text;
                }).catch(function() {
                    return null;
                });
            };

            var send = function() {
                if (busy) {
                    return;
                }
                var args = payload();
                if (!args) {
                    return;
                }
                busy = true;
                Ajax.call([{methodname: METHOD, args: args}])[0].then(function(result) {
                    lastSent = args.ranges;
                    busy = false;
                    showProgress(result);
                    return result;
                }).catch(function() {
                    // Retry on the next tick; never interrupt the assessor.
                    busy = false;
                });
            };

            // Fire-and-forget when the page is closed mid-video.
            var sendOnLeave = function() {
                var args = payload();
                if (!args || !window.fetch) {
                    return;
                }
                lastSent = args.ranges;
                var url = M.cfg.wwwroot + '/lib/ajax/service.php?sesskey=' + M.cfg.sesskey +
                    '&info=' + METHOD;
                try {
                    window.fetch(url, {
                        method: 'POST',
                        keepalive: true,
                        credentials: 'same-origin',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify([{index: 0, methodname: METHOD, args: args}])
                    });
                } catch (e) {
                    // Ignore.
                }
            };

            var timer = null;
            video.addEventListener('play', function() {
                if (!timer) {
                    timer = window.setInterval(send, 15000);
                }
            });
            ['pause', 'ended', 'seeking'].forEach(function(evt) {
                video.addEventListener(evt, send);
            });
            video.addEventListener('ended', function() {
                window.clearInterval(timer);
                timer = null;
            });
            window.addEventListener('pagehide', sendOnLeave);
            document.addEventListener('visibilitychange', function() {
                if (document.visibilityState === 'hidden') {
                    sendOnLeave();
                }
            });

            // The grader swaps students without a page load; flush when this player leaves the DOM.
            if (window.MutationObserver) {
                var observer = new MutationObserver(function() {
                    if (!document.body.contains(video)) {
                        sendOnLeave();
                        window.clearInterval(timer);
                        observer.disconnect();
                    }
                });
                observer.observe(document.body, {childList: true, subtree: true});
            }

            if (required === 0 && progress) {
                progress.classList.add('text-success');
            }
        }
    };
});
