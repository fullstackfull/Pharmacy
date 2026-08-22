/******/ (function(modules) { // webpackBootstrap
/******/ 	// The module cache
/******/ 	var installedModules = {};
/******/
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/
/******/ 		// Check if module is in cache
/******/ 		if(installedModules[moduleId]) {
/******/ 			return installedModules[moduleId].exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = installedModules[moduleId] = {
/******/ 			i: moduleId,
/******/ 			l: false,
/******/ 			exports: {}
/******/ 		};
/******/
/******/ 		// Execute the module function
/******/ 		modules[moduleId].call(module.exports, module, module.exports, __webpack_require__);
/******/
/******/ 		// Flag the module as loaded
/******/ 		module.l = true;
/******/
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/
/******/
/******/ 	// expose the modules object (__webpack_modules__)
/******/ 	__webpack_require__.m = modules;
/******/
/******/ 	// expose the module cache
/******/ 	__webpack_require__.c = installedModules;
/******/
/******/ 	// define getter function for harmony exports
/******/ 	__webpack_require__.d = function(exports, name, getter) {
/******/ 		if(!__webpack_require__.o(exports, name)) {
/******/ 			Object.defineProperty(exports, name, { enumerable: true, get: getter });
/******/ 		}
/******/ 	};
/******/
/******/ 	// define __esModule on exports
/******/ 	__webpack_require__.r = function(exports) {
/******/ 		if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 			Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 		}
/******/ 		Object.defineProperty(exports, '__esModule', { value: true });
/******/ 	};
/******/
/******/ 	// create a fake namespace object
/******/ 	// mode & 1: value is a module id, require it
/******/ 	// mode & 2: merge all properties of value into the ns
/******/ 	// mode & 4: return value when already ns object
/******/ 	// mode & 8|1: behave like require
/******/ 	__webpack_require__.t = function(value, mode) {
/******/ 		if(mode & 1) value = __webpack_require__(value);
/******/ 		if(mode & 8) return value;
/******/ 		if((mode & 4) && typeof value === 'object' && value && value.__esModule) return value;
/******/ 		var ns = Object.create(null);
/******/ 		__webpack_require__.r(ns);
/******/ 		Object.defineProperty(ns, 'default', { enumerable: true, value: value });
/******/ 		if(mode & 2 && typeof value != 'string') for(var key in value) __webpack_require__.d(ns, key, function(key) { return value[key]; }.bind(null, key));
/******/ 		return ns;
/******/ 	};
/******/
/******/ 	// getDefaultExport function for compatibility with non-harmony modules
/******/ 	__webpack_require__.n = function(module) {
/******/ 		var getter = module && module.__esModule ?
/******/ 			function getDefault() { return module['default']; } :
/******/ 			function getModuleExports() { return module; };
/******/ 		__webpack_require__.d(getter, 'a', getter);
/******/ 		return getter;
/******/ 	};
/******/
/******/ 	// Object.prototype.hasOwnProperty.call
/******/ 	__webpack_require__.o = function(object, property) { return Object.prototype.hasOwnProperty.call(object, property); };
/******/
/******/ 	// __webpack_public_path__
/******/ 	__webpack_require__.p = "/";
/******/
/******/
/******/ 	// Load entry module and return exports
/******/ 	return __webpack_require__(__webpack_require__.s = 1);
/******/ })
/************************************************************************/
/******/ ({

/***/ "./resources/js/kohl/monitoring.js":
/*!*****************************************!*\
  !*** ./resources/js/kohl/monitoring.js ***!
  \*****************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

"use strict";
/*
 | Monitoring — the page's own behaviour.
 |
 | Three jobs, and a rule. The jobs: keep the status header current, draw the small time-series
 | charts, and let the section rail collapse on a phone. The rule: polling must never cost more
 | than the thing it is watching — so the header refreshes on a modest interval, pauses entirely
 | while the tab is hidden, and backs off when a request fails rather than hammering a struggling
 | server with a health check every few seconds.
 */


(function () {
  var root = document.getElementById('monitoring-root');

  if (!root) {
    return;
  }

  var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  /* ── Status header ─────────────────────────────────────────────────────── */

  var statusBox = document.getElementById('mon-status');
  var pulseUrl = root.dataset.pulseUrl;
  var BASE_INTERVAL = 15000;
  var MAX_INTERVAL = 120000;
  var interval = BASE_INTERVAL;
  var timer = null;

  function text(name, value) {
    var node = root.querySelector('[data-mon="' + name + '"]');

    if (node) {
      node.textContent = value;
    }
  }

  function chip(name, label, state) {
    var node = root.querySelector('[data-mon="' + name + '"]');

    if (!node) {
      return;
    }

    node.textContent = label;
    node.setAttribute('data-state', state);
  }

  function describeAge(reading) {
    if (!reading || reading.age_seconds === null || reading.age_seconds === undefined) {
      return 'no data';
    }

    var seconds = reading.age_seconds;

    if (seconds < 90) {
      return seconds + 's ago';
    }

    if (seconds < 5400) {
      return Math.round(seconds / 60) + 'm ago';
    }

    return Math.round(seconds / 3600) + 'h ago';
  }

  function applyPulse(payload) {
    if (statusBox) {
      statusBox.setAttribute('data-state', payload.status || 'unknown');
    }

    text('status', payload.status || 'unknown');
    text('score', payload.score === null || payload.score === undefined ? '—' : payload.score);

    if (payload.coverage) {
      text('coverage', 'from ' + payload.coverage.measured + '/' + payload.coverage.total + ' measured signals');
    }

    var self = payload.self || {};
    chip('self-collection', self.collection_enabled ? 'collecting' : 'collection off', self.collection_enabled ? 'ok' : 'off');
    chip('self-gauges', 'gauges ' + describeAge(self.gauges), self.gauges && self.gauges.state || 'no_data');
    chip('self-requests', 'requests ' + describeAge(self.requests), self.requests && self.requests.state || 'no_data');
    var bufferNode = root.querySelector('[data-mon="self-buffer"]');

    if (bufferNode && self.buffer) {
      bufferNode.textContent = self.buffer.description;
    } // A stale header is the one thing that must never be quiet: everything below it is being
    // read from a system that has stopped reporting.


    if (payload.stale) {
      text('status-detail', 'Monitoring is not receiving data — nothing below is current.');
    } else if (payload.status === 'healthy') {
      text('status-detail', 'across all measured signals');
    }
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(poll, interval);
  }

  function poll() {
    if (document.hidden) {
      // Nobody is looking. Come back when they are, rather than polling a background tab.
      schedule();
      return;
    }

    fetch(pulseUrl, {
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    }).then(function (response) {
      if (!response.ok) {
        throw new Error('pulse ' + response.status);
      }

      return response.json();
    }).then(function (payload) {
      interval = BASE_INTERVAL;
      applyPulse(payload);
    })["catch"](function () {
      // Exponential back-off. A server in trouble does not need a health check every
      // fifteen seconds from every open dashboard.
      interval = Math.min(MAX_INTERVAL, interval * 2);
      chip('self-collection', 'dashboard offline', 'off');
    })["finally"](schedule);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) {
      clearTimeout(timer);
      poll();
    }
  });

  if (pulseUrl) {
    schedule();
  }
  /* ── Section rail ──────────────────────────────────────────────────────── */


  var railToggle = root.querySelector('[data-mon-rail-toggle]');

  if (railToggle) {
    railToggle.addEventListener('click', function () {
      var rail = railToggle.closest('.mon-rail');
      var open = rail.classList.toggle('is-open');
      railToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  /* ── Charts ────────────────────────────────────────────────────────────── */

  /*
   | Drawn as inline SVG rather than with a charting library.
   |
   | The series is already aggregated server-side to a few dozen points, so there is nothing left
   | for a library to do that costs less than loading it — and this page is heavy enough already.
   | Requests are an area, errors a separate line on the same axis, because an error count is
   | meaningless without the traffic it happened in.
   */


  function drawChart(host) {
    var payload;

    try {
      payload = JSON.parse(host.dataset.monChart);
    } catch (error) {
      return;
    }

    var points = (payload.points || []).filter(function (point) {
      return point && typeof point.hits === 'number';
    });

    if (points.length < 2) {
      host.innerHTML = '<p class="mon-note">Not enough points to draw a line yet.</p>';
      return;
    }

    var width = 1000;
    var height = 200;
    var padding = {
      top: 8,
      right: 8,
      bottom: 18,
      left: 34
    };
    var plotWidth = width - padding.left - padding.right;
    var plotHeight = height - padding.top - padding.bottom;
    var maxHits = Math.max.apply(null, points.map(function (point) {
      return point.hits;
    })) || 1;

    var x = function x(index) {
      return padding.left + index / (points.length - 1) * plotWidth;
    };

    var y = function y(value) {
      return padding.top + plotHeight - value / maxHits * plotHeight;
    };

    var line = points.map(function (point, index) {
      return (index === 0 ? 'M' : 'L') + x(index).toFixed(1) + ' ' + y(point.hits).toFixed(1);
    }).join(' ');
    var area = line + ' L' + x(points.length - 1).toFixed(1) + ' ' + (padding.top + plotHeight) + ' L' + x(0).toFixed(1) + ' ' + (padding.top + plotHeight) + ' Z';
    var errorTotal = points.reduce(function (sum, point) {
      return sum + (point.errors || 0);
    }, 0);
    var errorLine = errorTotal > 0 ? points.map(function (point, index) {
      return (index === 0 ? 'M' : 'L') + x(index).toFixed(1) + ' ' + y(point.errors || 0).toFixed(1);
    }).join(' ') : null;

    var label = function label(value, atY) {
      return '<text class="mon-chart__label" x="4" y="' + (atY + 3).toFixed(1) + '">' + value + '</text>';
    };

    var first = new Date(points[0].t);
    var last = new Date(points[points.length - 1].t);

    var timeLabel = function timeLabel(date) {
      return date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit'
      });
    };

    host.innerHTML = '<svg viewBox="0 0 ' + width + ' ' + height + '" preserveAspectRatio="none" role="img" ' + 'aria-label="Requests over time">' + '<path class="mon-chart__area" d="' + area + '"></path>' + '<path class="mon-chart__line" d="' + line + '"></path>' + (errorLine ? '<path class="mon-chart__errors" d="' + errorLine + '"></path>' : '') + '<line class="mon-chart__axis" x1="' + padding.left + '" y1="' + (padding.top + plotHeight) + '" x2="' + (width - padding.right) + '" y2="' + (padding.top + plotHeight) + '"></line>' + label(maxHits, padding.top) + label(0, padding.top + plotHeight) + '<text class="mon-chart__label" x="' + padding.left + '" y="' + (height - 4) + '">' + timeLabel(first) + '</text>' + '<text class="mon-chart__label" x="' + (width - padding.right - 30) + '" y="' + (height - 4) + '">' + timeLabel(last) + '</text>' + '</svg>';
  }

  root.querySelectorAll('[data-mon-chart]').forEach(drawChart);
})();

/***/ }),

/***/ 1:
/*!***********************************************!*\
  !*** multi ./resources/js/kohl/monitoring.js ***!
  \***********************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

module.exports = __webpack_require__(/*! /home/user/Pharmacy/resources/js/kohl/monitoring.js */"./resources/js/kohl/monitoring.js");


/***/ })

/******/ });