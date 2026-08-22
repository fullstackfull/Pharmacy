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
/******/ 	return __webpack_require__(__webpack_require__.s = 0);
/******/ })
/************************************************************************/
/******/ ({

/***/ "./resources/css/kohl/console.scss":
/*!*****************************************!*\
  !*** ./resources/css/kohl/console.scss ***!
  \*****************************************/
/*! no static exports found */
/***/ (function(module, exports) {

// removed by extract-text-webpack-plugin

/***/ }),

/***/ "./resources/css/kohl/developer.scss":
/*!*******************************************!*\
  !*** ./resources/css/kohl/developer.scss ***!
  \*******************************************/
/*! no static exports found */
/***/ (function(module, exports) {

// removed by extract-text-webpack-plugin

/***/ }),

/***/ "./resources/css/kohl/monitoring.scss":
/*!********************************************!*\
  !*** ./resources/css/kohl/monitoring.scss ***!
  \********************************************/
/*! no static exports found */
/***/ (function(module, exports) {

// removed by extract-text-webpack-plugin

/***/ }),

/***/ "./resources/css/kohl/store.scss":
/*!***************************************!*\
  !*** ./resources/css/kohl/store.scss ***!
  \***************************************/
/*! no static exports found */
/***/ (function(module, exports) {

// removed by extract-text-webpack-plugin

/***/ }),

/***/ "./resources/js/kohl/index.js":
/*!************************************!*\
  !*** ./resources/js/kohl/index.js ***!
  \************************************/
/*! no static exports found */
/***/ (function(module, exports) {

function _toConsumableArray(arr) { return _arrayWithoutHoles(arr) || _iterableToArray(arr) || _unsupportedIterableToArray(arr) || _nonIterableSpread(); }

function _nonIterableSpread() { throw new TypeError("Invalid attempt to spread non-iterable instance.\nIn order to be iterable, non-array objects must have a [Symbol.iterator]() method."); }

function _unsupportedIterableToArray(o, minLen) { if (!o) return; if (typeof o === "string") return _arrayLikeToArray(o, minLen); var n = Object.prototype.toString.call(o).slice(8, -1); if (n === "Object" && o.constructor) n = o.constructor.name; if (n === "Map" || n === "Set") return Array.from(o); if (n === "Arguments" || /^(?:Ui|I)nt(?:8|16|32)(?:Clamped)?Array$/.test(n)) return _arrayLikeToArray(o, minLen); }

function _iterableToArray(iter) { if (typeof Symbol !== "undefined" && Symbol.iterator in Object(iter)) return Array.from(iter); }

function _arrayWithoutHoles(arr) { if (Array.isArray(arr)) return _arrayLikeToArray(arr); }

function _arrayLikeToArray(arr, len) { if (len == null || len > arr.length) len = arr.length; for (var i = 0, arr2 = new Array(len); i < len; i++) { arr2[i] = arr[i]; } return arr2; }

/**
 * Kohl runtime.
 *
 * Deliberately dependency-free and tiny: it runs alongside 41 existing admin
 * scripts, so it may not assume jQuery, Bootstrap or any load order, and it may
 * not touch anything it did not render itself. Every behaviour is delegated from
 * the document, so markup swapped in by PJAX works with no re-initialisation.
 */
var THEME_KEY = 'k-theme';
/* ------------------------------------------------------------------ theme */

/**
 * Apply the stored colour-scheme choice.
 *
 * Three states, matching the token layer: 'dark' and 'light' stamp the root and
 * win over the OS; no stored value leaves the attribute off so the OS preference
 * decides. Stamping 'light' by default would override a user's dark OS setting.
 */

function applyStoredTheme() {
  var stored = null;

  try {
    stored = window.localStorage.getItem(THEME_KEY);
  } catch (error) {
    stored = null; // private mode / storage disabled — fall back to light
  }

  var isConsole = document.body && document.body.classList.contains('k-console');
  var root = document.documentElement; // The console never follows the OS silently (see the pre-paint note in the admin layout):
  // anything that is not an explicit 'dark' resolves to light there. The storefront keeps the
  // three-state behaviour, where 'system' means "let the OS decide".

  var theme = isConsole ? stored === 'dark' ? 'dark' : 'light' : stored === 'dark' || stored === 'light' ? stored : null;

  if (theme) {
    root.setAttribute('data-k-theme', theme);
  } else {
    root.removeAttribute('data-k-theme');
  }

  if (!isConsole) {
    return;
  } // Dark has to be stamped on all three layers or the page comes out half-dark: Bootstrap 5.3
  // themes its own components off data-bs-theme, and the v2 shell (rail, header, context panel)
  // off its own class.


  root.setAttribute('data-bs-theme', theme);
  document.querySelectorAll('.app-v2').forEach(function (shell) {
    shell.classList.toggle('v2-theme-dark', theme === 'dark');
  });
}

function setTheme(value) {
  try {
    if (value === 'system') {
      window.localStorage.removeItem(THEME_KEY);
    } else {
      window.localStorage.setItem(THEME_KEY, value);
    }
  } catch (error) {
    /* not fatal — the choice just will not persist */
  }

  applyStoredTheme();
}

function direction() {
  return document.documentElement.getAttribute('dir') === 'rtl' ? 'rtl' : 'ltr';
}
/* ----------------------------------------------------------------- toasts */


var TOAST_DEFAULT_MS = 5000;
/**
 * Show a toast.
 *
 * `action` gives destructive operations an undo affordance, which is what makes
 * an optimistic write safe: act immediately, offer the way back for a few
 * seconds. A toast carrying an action stays until it is dismissed.
 */

function toast() {
  var _ref = arguments.length > 0 && arguments[0] !== undefined ? arguments[0] : {},
      title = _ref.title,
      _ref$text = _ref.text,
      text = _ref$text === void 0 ? null : _ref$text,
      _ref$tone = _ref.tone,
      tone = _ref$tone === void 0 ? 'neutral' : _ref$tone,
      _ref$action = _ref.action,
      action = _ref$action === void 0 ? null : _ref$action,
      _ref$duration = _ref.duration,
      duration = _ref$duration === void 0 ? TOAST_DEFAULT_MS : _ref$duration;

  var host = document.getElementById('k-toasts');
  if (!host || !title) return null;
  var node = document.createElement('div');
  node.className = 'k-toast' + (tone !== 'neutral' ? " k-toast--".concat(tone) : '');
  var body = document.createElement('div');
  body.className = 'k-toast__body';
  var heading = document.createElement('div');
  heading.className = 'k-toast__title';
  heading.textContent = title;
  body.appendChild(heading);

  if (text) {
    var detail = document.createElement('div');
    detail.className = 'k-toast__text';
    detail.textContent = text;
    body.appendChild(detail);
  }

  if (action && typeof action.onClick === 'function') {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'k-toast__action';
    button.textContent = action.label;
    button.addEventListener('click', function () {
      action.onClick();
      dismiss();
    });
    body.appendChild(button);
  }

  var close = document.createElement('button');
  close.type = 'button';
  close.className = 'k-btn k-btn--ghost k-btn--icon k-btn--sm';
  close.setAttribute('aria-label', 'Close');
  close.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>';
  close.addEventListener('click', function () {
    return dismiss();
  });
  node.appendChild(body);
  node.appendChild(close);
  host.appendChild(node);
  var timer = null;

  function dismiss() {
    if (timer) clearTimeout(timer);
    node.remove();
  } // A toast offering an action must not expire before it can be acted on.


  if (!action && duration > 0) timer = setTimeout(dismiss, duration);
  return {
    dismiss: dismiss
  };
}
/* ---------------------------------------------------------------- drawers */


var lastFocused = null;

function openDrawer(id) {
  var drawer = document.getElementById(id);
  if (!drawer) return;
  var backdrop = document.querySelector("[data-k-drawer-backdrop=\"".concat(id, "\"]"));
  lastFocused = document.activeElement;
  drawer.classList.add('is-open');
  if (backdrop) backdrop.classList.add('is-open');
  document.body.style.overflow = 'hidden'; // Move focus in, or a keyboard user stays stranded on the page behind.

  var target = drawer.querySelector('[autofocus], input, select, textarea, button');
  if (target) target.focus({
    preventScroll: true
  });
}

function closeDrawer(id) {
  var drawer = id ? document.getElementById(id) : document.querySelector('.k-drawer.is-open');
  if (!drawer) return;
  var backdrop = document.querySelector("[data-k-drawer-backdrop=\"".concat(drawer.id, "\"]"));
  drawer.classList.remove('is-open');
  if (backdrop) backdrop.classList.remove('is-open');
  if (!document.querySelector('.k-drawer.is-open')) document.body.style.overflow = '';
  if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus({
    preventScroll: true
  });
}
/* ------------------------------------------------- row selection & bulk bar */

/**
 * Keep a data-view's bulk bar in step with its checkboxes.
 *
 * The count is shown because a bulk action with an unstated count is how people
 * delete the wrong thing.
 */


function syncSelection(view) {
  var rows = view.querySelectorAll('[data-k-row-select]');

  var selected = _toConsumableArray(rows).filter(function (box) {
    return box.checked;
  });

  var bar = view.querySelector('[data-k-bulk]');
  var counter = view.querySelector('[data-k-bulk-count]');
  var master = view.querySelector('[data-k-select-all]');
  rows.forEach(function (box) {
    var row = box.closest('tr');
    if (row) row.setAttribute('aria-selected', box.checked ? 'true' : 'false');
  });
  if (counter) counter.textContent = String(selected.length);
  if (bar) bar.hidden = selected.length === 0;

  if (master) {
    master.checked = rows.length > 0 && selected.length === rows.length;
    master.indeterminate = selected.length > 0 && selected.length < rows.length;
  }
}
/** Ids currently ticked in a view — what a bulk action submits. */


function selectedIds(view) {
  return _toConsumableArray(view.querySelectorAll('[data-k-row-select]:checked')).map(function (box) {
    return box.value;
  });
}
/* ---------------------------------------------------------------- save bar */

/**
 * Snapshot a form's current values.
 *
 * Compared as a string rather than field by field: a settings form has dozens of
 * inputs, and the only question the bar asks is "does this differ from what was
 * loaded". File inputs are excluded — their value cannot be restored on discard,
 * so treating a chosen file as dirty would offer an undo that does not work.
 */


function snapshotForm(form) {
  var parts = [];
  form.querySelectorAll('input, select, textarea').forEach(function (field) {
    if (field.type === 'file' || field.disabled || !field.name) return;
    parts.push(field.name + '=' + (field.type === 'checkbox' || field.type === 'radio' ? field.checked : field.value));
  });
  return parts.join('&');
}

function initSaveBars() {
  document.querySelectorAll('form[data-k-save-bar]').forEach(function (form) {
    var _bar$querySelector, _bar$querySelector2;

    if (form.dataset.kSaveBarReady === '1') return;
    var bar = form.querySelector('[data-k-savebar]');
    if (!bar) return;
    form.dataset.kSaveBarReady = '1';
    var baseline = snapshotForm(form);

    var refresh = function refresh() {
      var dirty = snapshotForm(form) !== baseline;
      bar.classList.toggle('is-dirty', dirty);
      form.dataset.kDirty = dirty ? '1' : '0';
    };

    form.addEventListener('input', refresh);
    form.addEventListener('change', refresh);
    (_bar$querySelector = bar.querySelector('[data-k-savebar-save]')) === null || _bar$querySelector === void 0 ? void 0 : _bar$querySelector.addEventListener('click', function () {
      // Clear the dirty flag first: submitting is not abandoning, and the
      // unload guard must not challenge the navigation it just caused.
      form.dataset.kDirty = '0';

      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit(); // runs validation and submit handlers
      } else {
        form.submit();
      }
    });
    (_bar$querySelector2 = bar.querySelector('[data-k-savebar-discard]')) === null || _bar$querySelector2 === void 0 ? void 0 : _bar$querySelector2.addEventListener('click', function () {
      form.reset();
      baseline = snapshotForm(form);
      refresh();
    });
    form.addEventListener('submit', function () {
      form.dataset.kDirty = '0';
    });
  });
} // Leaving a page with unsaved settings is the loss this bar exists to prevent, so
// it is also guarded at the browser level.


window.addEventListener('beforeunload', function (event) {
  if (!document.querySelector('form[data-k-dirty="1"]')) return;
  event.preventDefault();
  event.returnValue = '';
});
document.addEventListener('DOMContentLoaded', initSaveBars);
if (document.readyState !== 'loading') initSaveBars();
/* ------------------------------------------------------ delegated bindings */

document.addEventListener('click', function (event) {
  var opener = event.target.closest('[data-k-drawer-open]');

  if (opener) {
    event.preventDefault();
    openDrawer(opener.getAttribute('data-k-drawer-open'));
    return;
  }

  if (event.target.closest('[data-k-drawer-close]')) {
    event.preventDefault();
    closeDrawer();
    return;
  }

  var backdrop = event.target.closest('[data-k-drawer-backdrop]');

  if (backdrop) {
    closeDrawer(backdrop.getAttribute('data-k-drawer-backdrop'));
  }
});
document.addEventListener('change', function (event) {
  var master = event.target.closest('[data-k-select-all]');

  if (master) {
    var view = master.closest('[data-k-selectable]');
    if (!view) return;
    view.querySelectorAll('[data-k-row-select]').forEach(function (box) {
      box.checked = master.checked;
    });
    syncSelection(view);
    return;
  }

  var box = event.target.closest('[data-k-row-select]');

  if (box) {
    var _view = box.closest('[data-k-selectable]');

    if (_view) syncSelection(_view);
  }
});
document.addEventListener('keydown', function (event) {
  if (event.key === 'Escape' && document.querySelector('.k-drawer.is-open')) {
    closeDrawer();
  }
});
applyStoredTheme();
window.Kohl = Object.assign(window.Kohl || {}, {
  version: '0.2.0',
  setTheme: setTheme,
  direction: direction,
  toast: toast,
  openDrawer: openDrawer,
  closeDrawer: closeDrawer,
  selectedIds: selectedIds,
  initSaveBars: initSaveBars
});

/***/ }),

/***/ 0:
/*!*************************************************************************************************************************************************************************************!*\
  !*** multi ./resources/js/kohl/index.js ./resources/css/kohl/console.scss ./resources/css/kohl/store.scss ./resources/css/kohl/monitoring.scss ./resources/css/kohl/developer.scss ***!
  \*************************************************************************************************************************************************************************************/
/*! no static exports found */
/***/ (function(module, exports, __webpack_require__) {

__webpack_require__(/*! /home/user/Pharmacy/resources/js/kohl/index.js */"./resources/js/kohl/index.js");
__webpack_require__(/*! /home/user/Pharmacy/resources/css/kohl/console.scss */"./resources/css/kohl/console.scss");
__webpack_require__(/*! /home/user/Pharmacy/resources/css/kohl/store.scss */"./resources/css/kohl/store.scss");
__webpack_require__(/*! /home/user/Pharmacy/resources/css/kohl/monitoring.scss */"./resources/css/kohl/monitoring.scss");
module.exports = __webpack_require__(/*! /home/user/Pharmacy/resources/css/kohl/developer.scss */"./resources/css/kohl/developer.scss");


/***/ })

/******/ });