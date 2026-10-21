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
 * Evidence pack viewer: the still shown follows the audio position; clicking a still seeks the audio.
 *
 * @module     assignsubmission_videoevidence/evidence
 * @copyright  2026 LMS Hosting Services
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/str'], function(Str) {

    var fmt = function(t) {
        t = Math.round(t);
        var h = Math.floor(t / 3600);
        var m = Math.floor((t % 3600) / 60);
        var s = t % 60;
        var pad = function(n) {
            return (n < 10 ? '0' : '') + n;
        };
        return h ? h + ':' + pad(m) + ':' + pad(s) : m + ':' + pad(s);
    };

    return {
        /**
         * Init.
         *
         * @param {String} rootid
         * @param {Array} frames [{u: url, t: seconds}]
         */
        init: function(rootid, frames) {
            var root = document.getElementById(rootid);
            if (!root || root.dataset.veReady || !frames.length) {
                return;
            }
            root.dataset.veReady = '1';
            var img = root.querySelector('.ve-still');
            var caption = root.querySelector('.ve-caption');
            var audio = root.querySelector('.ve-audio');
            var thumbs = root.querySelectorAll('.ve-thumb');
            var current = 0;

            var show = function(index, scroll) {
                index = Math.max(0, Math.min(frames.length - 1, index));
                if (index === current && img.getAttribute('src') === frames[index].u) {
                    return;
                }
                current = index;
                img.src = frames[index].u;
                Str.get_string('frameof', 'assignsubmission_videoevidence', {
                    n: index + 1, total: frames.length, time: fmt(frames[index].t)
                }).then(function(text) {
                    caption.textContent = text;
                    img.alt = text;
                    return text;
                }).catch(function() {
                    return null;
                });
                for (var i = 0; i < thumbs.length; i++) {
                    thumbs[i].classList.toggle('active', i === index);
                }
                if (scroll && thumbs[index] && thumbs[index].scrollIntoView) {
                    thumbs[index].scrollIntoView({block: 'nearest', inline: 'center'});
                }
            };

            var indexAt = function(t) {
                var idx = 0;
                for (var i = 0; i < frames.length; i++) {
                    if (frames[i].t <= t + 0.05) {
                        idx = i;
                    } else {
                        break;
                    }
                }
                return idx;
            };

            var go = function(index) {
                index = Math.max(0, Math.min(frames.length - 1, index));
                if (audio) {
                    audio.currentTime = frames[index].t;
                }
                show(index, true);
            };

            if (audio) {
                audio.addEventListener('timeupdate', function() {
                    show(indexAt(audio.currentTime), !audio.paused);
                });
                audio.addEventListener('seeked', function() {
                    show(indexAt(audio.currentTime), true);
                });
            }
            for (var i = 0; i < thumbs.length; i++) {
                thumbs[i].addEventListener('click', function(e) {
                    go(parseInt(e.currentTarget.dataset.index, 10));
                });
            }
            var prev = root.querySelector('.ve-prev');
            var next = root.querySelector('.ve-next');
            if (prev) {
                prev.addEventListener('click', function() {
                    go(current - 1);
                });
            }
            if (next) {
                next.addEventListener('click', function() {
                    go(current + 1);
                });
            }
        }
    };
});
