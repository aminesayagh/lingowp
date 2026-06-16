/******/ (() => { // webpackBootstrap
/******/ 	"use strict";
/******/ 	var __webpack_modules__ = ({

/***/ "./admin-ui/App.jsx"
/*!**************************!*\
  !*** ./admin-ui/App.jsx ***!
  \**************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ App)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./api */ "./admin-ui/api.js");
/* harmony import */ var _components_Shell__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./components/Shell */ "./admin-ui/components/Shell.jsx");
/* harmony import */ var _components_ContentList__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./components/ContentList */ "./admin-ui/components/ContentList.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);





// Active area + filters live in the URL query so a refresh restores them.
// ponytail: native URLSearchParams instead of nuqs — no dep/provider for 4 keys.

const readUrl = () => {
  const p = new URLSearchParams(window.location.search);
  return {
    area: p.get('area') || 'page',
    filters: {
      q_original: p.get('q_original') || '',
      q_translated: p.get('q_translated') || '',
      origin: p.get('origin') || 'all'
    }
  };
};
const writeUrl = (area, filters) => {
  const p = new URLSearchParams(window.location.search);
  const setOrDel = (k, v, empty) => v && v !== empty ? p.set(k, v) : p.delete(k);
  setOrDel('area', area, 'page');
  setOrDel('q_original', filters.q_original, '');
  setOrDel('q_translated', filters.q_translated, '');
  setOrDel('origin', filters.origin, 'all');
  window.history.replaceState(null, '', `${window.location.pathname}?${p.toString()}`);
};
function App() {
  const initial = readUrl();
  const [config, setConfig] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [area, setArea] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(initial.area);
  const [lang, setLang] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('');
  const [filters, setFilters] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(initial.filters);
  const [areaCounts, setAreaCounts] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)({});
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.getConfig)().then(cfg => {
      setConfig(cfg);
      setLang(cfg.languages[0] ? cfg.languages[0].code : '');
    }).catch(e => setError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e)));
  }, []);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    writeUrl(area, filters);
  }, [area, filters]);
  const refreshAreaCounts = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(() => {
    if (!lang) {
      return;
    }
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.getAllCounts)({
      lang
    }).then(setAreaCounts).catch(() => {});
  }, [lang]);

  // Refresh sidebar badges whenever the language changes.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    refreshAreaCounts();
  }, [refreshAreaCounts]);
  if (error) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "tl-error",
      children: error
    });
  }
  if (!config) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "tl-loading",
      children: "Loading\u2026"
    });
  }
  if (config.languages.length === 0) {
    return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
      className: "tl-empty",
      children: "Add a target language in Settings to start translating."
    });
  }
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_components_Shell__WEBPACK_IMPORTED_MODULE_2__["default"], {
    config: config,
    area: area,
    onArea: setArea,
    lang: lang,
    onLang: setLang,
    filters: filters,
    onFilters: setFilters,
    areaCounts: areaCounts,
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_components_ContentList__WEBPACK_IMPORTED_MODULE_3__["default"], {
      config: config,
      area: area,
      lang: lang,
      filters: filters,
      onCountsChanged: refreshAreaCounts
    })
  });
}

/***/ },

/***/ "./admin-ui/api.js"
/*!*************************!*\
  !*** ./admin-ui/api.js ***!
  \*************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   applyEverywhere: () => (/* binding */ applyEverywhere),
/* harmony export */   deleteTranslation: () => (/* binding */ deleteTranslation),
/* harmony export */   errorMessage: () => (/* binding */ errorMessage),
/* harmony export */   getAllCounts: () => (/* binding */ getAllCounts),
/* harmony export */   getConfig: () => (/* binding */ getConfig),
/* harmony export */   getCounts: () => (/* binding */ getCounts),
/* harmony export */   getDuplicates: () => (/* binding */ getDuplicates),
/* harmony export */   getItems: () => (/* binding */ getItems),
/* harmony export */   getPreview: () => (/* binding */ getPreview),
/* harmony export */   getString: () => (/* binding */ getString),
/* harmony export */   ignoreSource: () => (/* binding */ ignoreSource),
/* harmony export */   runScan: () => (/* binding */ runScan),
/* harmony export */   saveAllTranslations: () => (/* binding */ saveAllTranslations),
/* harmony export */   saveTranslation: () => (/* binding */ saveTranslation)
/* harmony export */ });
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/api-fetch */ "@wordpress/api-fetch");
/* harmony import */ var _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0__);

const NS = 'translangs/v1';
function qs(params) {
  const parts = Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '').map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`);
  return parts.length ? `?${parts.join('&')}` : '';
}
const getConfig = () => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/config`
});
const getItems = params => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/items${qs(params)}`
});
const getCounts = params => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/counts${qs(params)}`
});
const getAllCounts = params => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/counts-all${qs(params)}`
});
const getString = (key, lang) => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/string/${key}${qs({
    lang
  })}`
});
const saveTranslation = (key, lang, body) => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/string/${key}/translation`,
  method: 'PUT',
  data: {
    lang,
    ...body
  }
});
const deleteTranslation = (key, lang) => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/string/${key}/translation${qs({
    lang
  })}`,
  method: 'DELETE'
});
const runScan = area => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/scan`,
  method: 'POST',
  data: {
    area,
    scope: 'all'
  }
});

/** Bulk-save several languages at once; each lands as "needs review". */
const saveAllTranslations = (key, translations) => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/string/${key}/translations`,
  method: 'PUT',
  data: {
    translations
  }
});

/** Other sources sharing this string's original text (apply-everywhere candidates). */
const getDuplicates = (key, lang) => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/string/${key}/duplicates${qs({
    lang
  })}`
});
const applyEverywhere = (key, body) => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/string/${key}/apply-everywhere`,
  method: 'POST',
  data: body
});

/** Mark a source as "don't translate" (drops its translation rows). */
const ignoreSource = key => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/string/${key}/ignore`,
  method: 'POST'
});

/** Where this string appears on the live site (permalink, or selector for HTML). */
const getPreview = (key, lang) => _wordpress_api_fetch__WEBPACK_IMPORTED_MODULE_0___default()({
  path: `${NS}/string/${key}/preview${qs({
    lang
  })}`
});

/** Pull a human message out of an apiFetch/WP_Error rejection. */
const errorMessage = e => e && (e.message || e.data && e.data.message) || 'Something went wrong.';

/***/ },

/***/ "./admin-ui/components/AllLanguages.jsx"
/*!**********************************************!*\
  !*** ./admin-ui/components/AllLanguages.jsx ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ AllLanguages)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../api */ "./admin-ui/api.js");
/* harmony import */ var _Placeholders__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./Placeholders */ "./admin-ui/components/Placeholders.jsx");
/* harmony import */ var _AutoTextarea__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./AutoTextarea */ "./admin-ui/components/AutoTextarea.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__);





/**
 * Per-record "translate into all languages" modal (wireframe A5). Loads the
 * source's original + every language's current translation, lets the user fill
 * any languages, and saves them all as "needs review". Ignores the top language
 * filter by design. Empty rows are left untranslated.
 */

function AllLanguages({
  config,
  sourceKey,
  lang,
  onClose,
  onSaved
}) {
  const [data, setData] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [values, setValues] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)({});
  const [busy, setBusy] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [loadError, setLoadError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.getString)(sourceKey, lang).then(res => {
      setData(res);
      const init = {};
      config.languages.forEach(l => {
        const sib = res.siblings.find(s => s.lang_code === l.code);
        init[l.code] = sib ? sib.translated_text || '' : '';
      });
      setValues(init);
    }).catch(e => setLoadError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e)));
  }, [sourceKey, lang, config]);
  const set = (code, text) => setValues(v => ({
    ...v,
    [code]: text
  }));
  const save = () => {
    const translations = config.languages.map(l => ({
      lang: l.code,
      text: values[l.code]
    })).filter(t => t.text.trim() !== '');
    if (translations.length === 0) {
      setError('Fill in at least one language.');
      return;
    }
    setBusy(true);
    setError(null);
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.saveAllTranslations)(sourceKey, translations).then(() => {
      setBusy(false);
      if (onSaved) {
        onSaved();
      }
      onClose();
    }).catch(e => {
      setError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e));
      setBusy(false);
    });
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
    className: "tl-modal-backdrop",
    onClick: onClose,
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
      className: "tl-modal",
      onClick: e => e.stopPropagation(),
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "tl-modal-head",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("h2", {
          children: "Translate into all languages"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          type: "button",
          className: "tl-modal-close",
          onClick: onClose,
          "aria-label": "Close",
          children: "\xD7"
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "tl-modal-body",
        children: [loadError && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
          className: "tl-error",
          children: loadError
        }), !data && !loadError && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
          className: "tl-loading",
          children: "Loading\u2026"
        }), data && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("label", {
            className: "tl-label",
            children: "Original"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "tl-original",
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_Placeholders__WEBPACK_IMPORTED_MODULE_2__["default"], {
              text: data.string.original
            })
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("table", {
            className: "tl-lang-table",
            children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("tbody", {
              children: config.languages.map(l => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("tr", {
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("th", {
                  scope: "row",
                  children: l.native || l.name
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("td", {
                  children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)(_AutoTextarea__WEBPACK_IMPORTED_MODULE_3__["default"], {
                    className: "tl-textarea",
                    rows: 1,
                    dir: l.dir || 'ltr',
                    value: values[l.code] || '',
                    onChange: e => set(l.code, e.target.value)
                  })
                })]
              }, l.code))
            })
          }), error && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("div", {
            className: "tl-error",
            children: error
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("p", {
            className: "tl-modal-intro",
            children: "Empty languages stay untranslated. Saved \u2192 \u201Cto review\u201D."
          })]
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsxs)("div", {
        className: "tl-modal-foot",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          type: "button",
          className: "button",
          onClick: onClose,
          children: "Cancel"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_4__.jsx)("button", {
          type: "button",
          className: "button button-primary",
          disabled: busy || !data,
          onClick: save,
          children: "Save all"
        })]
      })]
    })
  });
}

/***/ },

/***/ "./admin-ui/components/ApplyEverywhere.jsx"
/*!*************************************************!*\
  !*** ./admin-ui/components/ApplyEverywhere.jsx ***!
  \*************************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ ApplyEverywhere)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../api */ "./admin-ui/api.js");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__);



/**
 * Reuse the current translation across other sources that share the same
 * original text. Loads /duplicates, lets the user pick targets + whether to
 * overwrite translations that already exist, then POSTs /apply-everywhere.
 */

function ApplyEverywhere({
  sourceKey,
  lang,
  text,
  onClose,
  onApplied
}) {
  const [dups, setDups] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [selected, setSelected] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)({});
  const [overwrite, setOverwrite] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [busy, setBusy] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.getDuplicates)(sourceKey, lang).then(rows => {
      setDups(rows);
      const pre = {};
      rows.forEach(r => {
        // Default-check only the ones with no translation yet.
        pre[r.source_key] = !r.existing_translation;
      });
      setSelected(pre);
    }).catch(e => setError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e)));
  }, [sourceKey, lang]);
  const toggle = key => setSelected(s => ({
    ...s,
    [key]: !s[key]
  }));
  const targets = dups ? dups.filter(r => selected[r.source_key]).map(r => r.source_key) : [];
  const apply = () => {
    setBusy(true);
    setError(null);
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.applyEverywhere)(sourceKey, {
      lang,
      text,
      targets,
      overwrite
    }).then(res => {
      setBusy(false);
      if (onApplied) {
        onApplied(res.applied);
      }
      onClose();
    }).catch(e => {
      setError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e));
      setBusy(false);
    });
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("div", {
    className: "tl-modal-backdrop",
    onClick: onClose,
    children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("div", {
      className: "tl-modal",
      onClick: e => e.stopPropagation(),
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("div", {
        className: "tl-modal-head",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("h2", {
          children: "Use this translation elsewhere"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("button", {
          type: "button",
          className: "tl-modal-close",
          onClick: onClose,
          "aria-label": "Close",
          children: "\xD7"
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("div", {
        className: "tl-modal-body",
        children: [error && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("div", {
          className: "tl-error",
          children: error
        }), dups === null && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("div", {
          className: "tl-loading",
          children: "Loading\u2026"
        }), dups !== null && dups.length === 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("div", {
          className: "tl-empty",
          children: "No other places use this exact text."
        }), dups !== null && dups.length > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.Fragment, {
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("p", {
            className: "tl-modal-intro",
            children: ["Apply ", /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("code", {
              children: lang
            }), " translation to these matching strings:"]
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("ul", {
            className: "tl-dup-list",
            children: dups.map(r => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("li", {
              children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("label", {
                children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("input", {
                  type: "checkbox",
                  checked: !!selected[r.source_key],
                  onChange: () => toggle(r.source_key)
                }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("span", {
                  className: "tl-dup-field",
                  children: r.ref1 || '(string)'
                }), r.existing_translation && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("span", {
                  className: "tl-dup-existing",
                  children: ["already: \u201C", r.existing_translation, "\u201D"]
                })]
              })
            }, r.source_key))
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("label", {
            className: "tl-overwrite",
            children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("input", {
              type: "checkbox",
              checked: overwrite,
              onChange: e => setOverwrite(e.target.checked)
            }), "Overwrite translations that already exist"]
          })]
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("div", {
        className: "tl-modal-foot",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsx)("button", {
          type: "button",
          className: "button",
          onClick: onClose,
          children: "Cancel"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_2__.jsxs)("button", {
          type: "button",
          className: "button button-primary",
          disabled: busy || targets.length === 0,
          onClick: apply,
          children: ["Apply to ", targets.length, " place", targets.length === 1 ? '' : 's']
        })]
      })]
    })
  });
}

/***/ },

/***/ "./admin-ui/components/AutoTextarea.jsx"
/*!**********************************************!*\
  !*** ./admin-ui/components/AutoTextarea.jsx ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ AutoTextarea)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__);


/**
 * Textarea that grows to fit its content (no inner scrollbar). Height is
 * recomputed whenever the value changes — including programmatic updates
 * (copy-from-language, row patches) — not just on keystrokes.
 */

function AutoTextarea({
  value,
  onChange,
  ...rest
}) {
  const ref = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useRef)(null);
  const resize = () => {
    const el = ref.current;
    if (!el) {
      return;
    }
    el.style.height = 'auto';
    el.style.height = `${el.scrollHeight}px`;
  };
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useLayoutEffect)(resize, [value]);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("textarea", {
    ref: ref,
    value: value,
    onChange: e => {
      if (onChange) {
        onChange(e);
      }
      resize();
    },
    ...rest
  });
}

/***/ },

/***/ "./admin-ui/components/ContentList.jsx"
/*!*********************************************!*\
  !*** ./admin-ui/components/ContentList.jsx ***!
  \*********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ ContentList)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _api__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ../api */ "./admin-ui/api.js");
/* harmony import */ var _Placeholders__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./Placeholders */ "./admin-ui/components/Placeholders.jsx");
/* harmony import */ var _AutoTextarea__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! ./AutoTextarea */ "./admin-ui/components/AutoTextarea.jsx");
/* harmony import */ var _AllLanguages__WEBPACK_IMPORTED_MODULE_4__ = __webpack_require__(/*! ./AllLanguages */ "./admin-ui/components/AllLanguages.jsx");
/* harmony import */ var _ApplyEverywhere__WEBPACK_IMPORTED_MODULE_5__ = __webpack_require__(/*! ./ApplyEverywhere */ "./admin-ui/components/ApplyEverywhere.jsx");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__);







const TABS = [['to_translate', 'To translate'], ['to_review', 'To review'], ['done', 'Done']];
const FIELD_LABELS = {
  post_title: 'Title',
  post_content: 'Content',
  post_excerpt: 'Excerpt',
  _wp_attachment_image_alt: 'Alt text'
};
const fieldLabel = field => {
  if (!field) {
    return '';
  }
  if (FIELD_LABELS[field]) {
    return FIELD_LABELS[field];
  }
  const words = field.replace(/^_+/, '').replace(/_/g, ' ');
  return words.charAt(0).toUpperCase() + words.slice(1);
};
const STATE_CHIP = {
  to_translate: {
    cls: 'new',
    label: '● new'
  },
  done: {
    cls: 'done',
    label: '✓ done'
  },
  review_changed: {
    cls: 'changed',
    label: '⚠ changed'
  },
  review_flagged: {
    cls: 'flagged',
    label: '👤 flagged'
  }
};
function ContentList({
  config,
  area,
  lang,
  filters,
  onCountsChanged
}) {
  const [tab, setTab] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)('to_translate');
  const [data, setData] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [error, setError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [loading, setLoading] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [scanning, setScanning] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [cursor, setCursor] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const [stack, setStack] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)([]);
  const [modal, setModal] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);
  const dir = (config.languages.find(l => l.code === lang) || {}).dir || 'ltr';
  const load = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useCallback)(cur => {
    setLoading(true);
    setError(null);
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.getItems)({
      area,
      lang,
      tab,
      q_original: filters.q_original,
      q_translated: filters.q_translated,
      origin: filters.origin,
      cursor: cur || undefined,
      limit: 20
    }).then(res => {
      setData(res);
      setLoading(false);
    }).catch(e => {
      setError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e));
      setLoading(false);
    });
  }, [area, lang, tab, filters]);

  // Reset paging and reload whenever the area, language, tab, or filters change.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    setCursor(null);
    setStack([]);
    load(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [area, lang, tab, filters]);
  const refreshCounts = () => {
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.getCounts)({
      area,
      lang
    }).then(counts => setData(d => d ? {
      ...d,
      counts
    } : d)).catch(() => {});
    if (onCountsChanged) {
      onCountsChanged();
    }
  };

  /** Patch one item in place (keeps other rows' unsaved edits intact). */
  const patchItem = (key, patch) => {
    setData(d => {
      if (!d) {
        return d;
      }
      const groups = d.groups.map(g => ({
        ...g,
        items: g.items.map(it => it.key === key ? {
          ...it,
          ...patch
        } : it)
      }));
      return {
        ...d,
        groups
      };
    });
  };
  const removeItem = key => {
    setData(d => {
      if (!d) {
        return d;
      }
      const groups = d.groups.map(g => ({
        ...g,
        items: g.items.filter(it => it.key !== key)
      })).filter(g => g.items.length > 0);
      return {
        ...d,
        groups
      };
    });
  };
  const next = () => {
    if (data && data.next_cursor) {
      setStack(s => [...s, cursor]);
      setCursor(data.next_cursor);
      load(data.next_cursor);
    }
  };
  const prev = () => {
    const s = [...stack];
    const p = s.pop() || null;
    setStack(s);
    setCursor(p);
    load(p);
  };
  const check = () => {
    setScanning(true);
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.runScan)(area).then(() => {
      setScanning(false);
      load(cursor);
    }).catch(() => setScanning(false));
  };
  const counts = data && data.counts || {
    to_translate: 0,
    to_review: 0,
    done: 0,
    total: 0
  };
  const areaLabel = (config.content_areas.find(a => a.key === area) || {}).label || area;
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
    className: "tl-list",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "tl-list-head",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("h2", {
        children: areaLabel
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
        type: "button",
        className: "button",
        disabled: scanning,
        onClick: check,
        children: scanning ? 'Checking…' : 'Check for new text'
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "tl-tabs",
      children: TABS.map(([k, label]) => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("button", {
        type: "button",
        className: 'tl-tab' + (tab === k ? ' is-active' : ''),
        onClick: () => setTab(k),
        children: [label, " (", counts[k] || 0, ")"]
      }, k))
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "tl-progress",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
        className: "tl-progress-bar",
        style: {
          '--tl-pct': counts.total ? Math.round(counts.done / counts.total * 100) : 0
        }
      }), counts.done, " of ", counts.total, " done \xB7 ", counts.to_review, " to review"]
    }), error && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "tl-error",
      children: error
    }), loading && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "tl-loading",
      children: "Loading\u2026"
    }), !loading && data && data.groups.length === 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "tl-empty",
      children: counts.total === 0 ? `We haven’t found any text in ${areaLabel} yet. Use “Check for new text”.` : `Everything in ${areaLabel} is translated. 🎉`
    }), !loading && data && data.groups.map(g => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "tl-card",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "tl-card-title",
        children: [g.owner.label, " ", /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("span", {
          className: "tl-kind",
          children: ["(", g.owner.kind, ")"]
        })]
      }), g.items.map(it => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(InlineRow, {
        item: it,
        lang: lang,
        dir: dir,
        multiLang: config.languages.length > 1,
        onSaved: patch => {
          patchItem(it.key, patch);
          refreshCounts();
        },
        onRemoved: () => {
          removeItem(it.key);
          refreshCounts();
        },
        onModal: setModal
      }, it.key))]
    }, g.owner.kind + ':' + g.owner.id)), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "tl-pager",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
        type: "button",
        className: "button",
        disabled: stack.length === 0,
        onClick: prev,
        children: "\u25C0 Prev"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
        type: "button",
        className: "button",
        disabled: !(data && data.next_cursor),
        onClick: next,
        children: "Next \u25B6"
      })]
    }), modal && modal.type === 'all' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_AllLanguages__WEBPACK_IMPORTED_MODULE_4__["default"], {
      config: config,
      sourceKey: modal.key,
      lang: lang,
      onClose: () => setModal(null),
      onSaved: () => load(cursor)
    }), modal && modal.type === 'apply' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_ApplyEverywhere__WEBPACK_IMPORTED_MODULE_5__["default"], {
      sourceKey: modal.key,
      lang: lang,
      text: modal.text,
      onClose: () => setModal(null),
      onApplied: () => load(cursor)
    })]
  });
}
function InlineRow({
  item,
  lang,
  dir,
  multiLang,
  onSaved,
  onRemoved,
  onModal
}) {
  const [text, setText] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(item.translation || '');
  const [busy, setBusy] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const [rowError, setRowError] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(null);

  // Keep the field in sync if the row is patched from elsewhere.
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useEffect)(() => {
    setText(item.translation || '');
  }, [item.translation]);
  const chip = STATE_CHIP[item.state];
  const dirty = text !== (item.translation || '');
  const save = value => {
    setBusy(true);
    setRowError(null);
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.saveTranslation)(item.key, lang, {
      text: value,
      mark: 'reviewed',
      updated_at: item.updated_at || ''
    }).then(res => {
      setBusy(false);
      const s = res.string;
      onSaved({
        translation: s ? s.translation : value,
        state: s ? s.state : 'done',
        updated_at: s ? s.updated_at : null,
        origin: 'human'
      });
      // Same text elsewhere? Offer to reuse it.
      (0,_api__WEBPACK_IMPORTED_MODULE_1__.getDuplicates)(item.key, lang).then(dups => {
        if (dups.length > 0) {
          onModal({
            type: 'apply',
            key: item.key,
            text: value
          });
        }
      }).catch(() => {});
    }).catch(e => {
      setBusy(false);
      setRowError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e));
    });
  };
  const clear = () => {
    setBusy(true);
    setRowError(null);
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.deleteTranslation)(item.key, lang).then(() => {
      setBusy(false);
      onSaved({
        translation: '',
        state: 'to_translate',
        updated_at: null,
        origin: null
      });
    }).catch(e => {
      setBusy(false);
      setRowError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e));
    });
  };
  const dontTranslate = () => {
    setBusy(true);
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.ignoreSource)(item.key).then(() => onRemoved()).catch(e => {
      setBusy(false);
      setRowError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e));
    });
  };
  const preview = () => {
    (0,_api__WEBPACK_IMPORTED_MODULE_1__.getPreview)(item.key, lang).then(res => {
      if (res.url) {
        window.open(res.url, '_blank', 'noopener');
      } else {
        setRowError('No preview available for this string.');
      }
    }).catch(e => setRowError((0,_api__WEBPACK_IMPORTED_MODULE_1__.errorMessage)(e)));
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
    className: 'tl-pair' + (item.field ? '' : ' tl-pair-nofield'),
    children: [item.field && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
      className: "tl-field",
      title: item.field,
      children: fieldLabel(item.field)
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
      className: "tl-pair-row",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "tl-pair-cols",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
          className: "tl-col tl-col-original",
          title: item.original,
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_Placeholders__WEBPACK_IMPORTED_MODULE_2__["default"], {
            text: item.original
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
          className: "tl-col tl-col-translation",
          children: /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(_AutoTextarea__WEBPACK_IMPORTED_MODULE_3__["default"], {
            className: "tl-textarea",
            rows: 1,
            dir: dir,
            value: text,
            disabled: busy,
            placeholder: "Add translation\u2026",
            onChange: e => setText(e.target.value)
          })
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "tl-pair-actions",
        children: [chip && chip.cls !== 'new' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("span", {
          className: 'tl-state tl-state-' + chip.cls,
          children: chip.label
        }), item.state === 'review_changed' && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
          type: "button",
          className: "button",
          disabled: busy,
          title: "Accept the current translation for the new wording",
          onClick: () => save(item.translation || text),
          children: "Keep & approve"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
          type: "button",
          className: "button button-primary",
          disabled: busy || text.trim() === '' || !dirty && item.state === 'done',
          onClick: () => save(text),
          children: "Valid"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)(RowMenu, {
          canPreview: item.preview !== false,
          canAllLanguages: multiLang,
          onPreview: preview,
          onAllLanguages: () => onModal({
            type: 'all',
            key: item.key
          }),
          onClear: clear,
          onDontTranslate: dontTranslate,
          hasTranslation: (item.translation || '') !== ''
        })]
      })]
    }), rowError && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
      className: "tl-error tl-row-error",
      children: rowError
    })]
  });
}
function RowMenu({
  canPreview,
  canAllLanguages,
  onPreview,
  onAllLanguages,
  onClear,
  onDontTranslate,
  hasTranslation
}) {
  const [open, setOpen] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(false);
  const run = fn => () => {
    setOpen(false);
    fn();
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("span", {
    className: "tl-menu",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
      type: "button",
      className: "button tl-menu-toggle",
      "aria-haspopup": "true",
      "aria-expanded": open,
      onClick: () => setOpen(v => !v),
      children: "\u22EF"
    }), open && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.Fragment, {
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("div", {
        className: "tl-menu-overlay",
        onClick: () => setOpen(false)
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsxs)("div", {
        className: "tl-menu-list",
        role: "menu",
        children: [canPreview && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
          type: "button",
          role: "menuitem",
          onClick: run(onPreview),
          children: "Preview on site"
        }), canAllLanguages && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
          type: "button",
          role: "menuitem",
          onClick: run(onAllLanguages),
          children: "Translate all languages"
        }), hasTranslation && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
          type: "button",
          role: "menuitem",
          onClick: run(onClear),
          children: "Clear translation"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_6__.jsx)("button", {
          type: "button",
          role: "menuitem",
          className: "tl-menu-danger",
          onClick: run(onDontTranslate),
          children: "Don\u2019t translate"
        })]
      })]
    })]
  });
}

/***/ },

/***/ "./admin-ui/components/Placeholders.jsx"
/*!**********************************************!*\
  !*** ./admin-ui/components/Placeholders.jsx ***!
  \**********************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ Placeholders)
/* harmony export */ });
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__);

const TOKEN = /(\{\/?\d+\/?\})/g;
const IS_TOKEN = /^\{\/?\d+\/?\}$/;

/**
 * Render text with {n}/{/n}/{n/} markers shown as locked chips (UC-AD14). The
 * chips are visual only; the API validates their presence on save.
 */
function Placeholders({
  text
}) {
  const parts = String(text || '').split(TOKEN);
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.Fragment, {
    children: parts.map((part, i) => IS_TOKEN.test(part) ? /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("span", {
      className: "tl-chip",
      children: part
    }, i) : /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_0__.jsx)("span", {
      children: part
    }, i))
  });
}

/***/ },

/***/ "./admin-ui/components/Shell.jsx"
/*!***************************************!*\
  !*** ./admin-ui/components/Shell.jsx ***!
  \***************************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
/* harmony export */ __webpack_require__.d(__webpack_exports__, {
/* harmony export */   "default": () => (/* binding */ Shell)
/* harmony export */ });
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__);


const settingsUrl = () => typeof window !== 'undefined' && window.TranslangsBoard && window.TranslangsBoard.settingsUrl || '#';
function Shell({
  config,
  area,
  onArea,
  lang,
  onLang,
  filters,
  onFilters,
  areaCounts,
  children
}) {
  const [draft, setDraft] = (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.useState)(filters);
  const set = patch => setDraft(d => ({
    ...d,
    ...patch
  }));
  const changed = draft.q_original !== filters.q_original || draft.q_translated !== filters.q_translated || draft.origin !== filters.origin;
  const apply = e => {
    e.preventDefault();
    onFilters(draft);
  };
  return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("div", {
    className: "tl-shell",
    children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("header", {
      className: "tl-header",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("strong", {
        className: "tl-brand",
        children: "TransLangs"
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("form", {
        className: "tl-header-filters",
        onSubmit: apply,
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("input", {
          placeholder: "Search original",
          value: draft.q_original,
          onChange: e => set({
            q_original: e.target.value
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("input", {
          placeholder: "Search translated",
          value: draft.q_translated,
          onChange: e => set({
            q_translated: e.target.value
          })
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("select", {
          value: draft.origin,
          onChange: e => set({
            origin: e.target.value
          }),
          children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("option", {
            value: "all",
            children: "All"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("option", {
            value: "human",
            children: "Manually edited"
          }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("option", {
            value: "machine",
            children: "Auto-translated"
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("button", {
          type: "submit",
          className: "button",
          disabled: !changed,
          children: "Filter"
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("div", {
        className: "tl-header-right",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("label", {
          className: "tl-lang",
          children: ["Translate to:", ' ', /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("select", {
            value: lang,
            onChange: e => onLang(e.target.value),
            children: config.languages.map(l => /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("option", {
              value: l.code,
              children: l.native || l.name
            }, l.code))
          })]
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("a", {
          className: "button",
          href: settingsUrl(),
          children: "Settings"
        })]
      })]
    }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("div", {
      className: "tl-body",
      children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("nav", {
        className: "tl-sidebar",
        children: [/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("div", {
          className: "tl-nav-group",
          children: "Content"
        }), config.content_areas.map(a => {
          const c = areaCounts && areaCounts[a.key];
          return /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("button", {
            type: "button",
            className: 'tl-nav-item' + (a.key === area ? ' is-active' : ''),
            onClick: () => onArea(a.key),
            children: [a.label, c && c.total > 0 && /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsxs)("span", {
              className: "tl-nav-count",
              children: ["(", c.to_translate, "/", c.total, ")"]
            })]
          }, a.key);
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("div", {
          className: "tl-nav-group",
          children: "Site"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("button", {
          type: "button",
          className: "tl-nav-item",
          disabled: true,
          children: "Menus & Branding"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("div", {
          className: "tl-nav-group",
          children: "Manage"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("button", {
          type: "button",
          className: "tl-nav-item",
          disabled: true,
          children: "Don\u2019t-translate"
        }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("button", {
          type: "button",
          className: "tl-nav-item",
          disabled: true,
          children: "Import / Export"
        })]
      }), /*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_1__.jsx)("main", {
        className: "tl-main",
        children: children
      })]
    })]
  });
}

/***/ },

/***/ "./admin-ui/board.css"
/*!****************************!*\
  !*** ./admin-ui/board.css ***!
  \****************************/
(__unused_webpack_module, __webpack_exports__, __webpack_require__) {

__webpack_require__.r(__webpack_exports__);
// extracted by mini-css-extract-plugin


/***/ },

/***/ "react/jsx-runtime"
/*!**********************************!*\
  !*** external "ReactJSXRuntime" ***!
  \**********************************/
(module) {

module.exports = window["ReactJSXRuntime"];

/***/ },

/***/ "@wordpress/api-fetch"
/*!**********************************!*\
  !*** external ["wp","apiFetch"] ***!
  \**********************************/
(module) {

module.exports = window["wp"]["apiFetch"];

/***/ },

/***/ "@wordpress/element"
/*!*********************************!*\
  !*** external ["wp","element"] ***!
  \*********************************/
(module) {

module.exports = window["wp"]["element"];

/***/ }

/******/ 	});
/************************************************************************/
/******/ 	// The module cache
/******/ 	var __webpack_module_cache__ = {};
/******/ 	
/******/ 	// The require function
/******/ 	function __webpack_require__(moduleId) {
/******/ 		// Check if module is in cache
/******/ 		var cachedModule = __webpack_module_cache__[moduleId];
/******/ 		if (cachedModule !== undefined) {
/******/ 			return cachedModule.exports;
/******/ 		}
/******/ 		// Create a new module (and put it into the cache)
/******/ 		var module = __webpack_module_cache__[moduleId] = {
/******/ 			// no module.id needed
/******/ 			// no module.loaded needed
/******/ 			exports: {}
/******/ 		};
/******/ 	
/******/ 		// Execute the module function
/******/ 		if (!(moduleId in __webpack_modules__)) {
/******/ 			delete __webpack_module_cache__[moduleId];
/******/ 			var e = new Error("Cannot find module '" + moduleId + "'");
/******/ 			e.code = 'MODULE_NOT_FOUND';
/******/ 			throw e;
/******/ 		}
/******/ 		__webpack_modules__[moduleId](module, module.exports, __webpack_require__);
/******/ 	
/******/ 		// Return the exports of the module
/******/ 		return module.exports;
/******/ 	}
/******/ 	
/************************************************************************/
/******/ 	/* webpack/runtime/compat get default export */
/******/ 	(() => {
/******/ 		// getDefaultExport function for compatibility with non-harmony modules
/******/ 		__webpack_require__.n = (module) => {
/******/ 			var getter = module && module.__esModule ?
/******/ 				() => (module['default']) :
/******/ 				() => (module);
/******/ 			__webpack_require__.d(getter, { a: getter });
/******/ 			return getter;
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/define property getters */
/******/ 	(() => {
/******/ 		// define getter functions for harmony exports
/******/ 		__webpack_require__.d = (exports, definition) => {
/******/ 			for(var key in definition) {
/******/ 				if(__webpack_require__.o(definition, key) && !__webpack_require__.o(exports, key)) {
/******/ 					Object.defineProperty(exports, key, { enumerable: true, get: definition[key] });
/******/ 				}
/******/ 			}
/******/ 		};
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/hasOwnProperty shorthand */
/******/ 	(() => {
/******/ 		__webpack_require__.o = (obj, prop) => (Object.prototype.hasOwnProperty.call(obj, prop))
/******/ 	})();
/******/ 	
/******/ 	/* webpack/runtime/make namespace object */
/******/ 	(() => {
/******/ 		// define __esModule on exports
/******/ 		__webpack_require__.r = (exports) => {
/******/ 			if(typeof Symbol !== 'undefined' && Symbol.toStringTag) {
/******/ 				Object.defineProperty(exports, Symbol.toStringTag, { value: 'Module' });
/******/ 			}
/******/ 			Object.defineProperty(exports, '__esModule', { value: true });
/******/ 		};
/******/ 	})();
/******/ 	
/************************************************************************/
var __webpack_exports__ = {};
// This entry needs to be wrapped in an IIFE because it needs to be isolated against other modules in the chunk.
(() => {
/*!***************************!*\
  !*** ./admin-ui/index.js ***!
  \***************************/
__webpack_require__.r(__webpack_exports__);
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0__ = __webpack_require__(/*! @wordpress/element */ "@wordpress/element");
/* harmony import */ var _wordpress_element__WEBPACK_IMPORTED_MODULE_0___default = /*#__PURE__*/__webpack_require__.n(_wordpress_element__WEBPACK_IMPORTED_MODULE_0__);
/* harmony import */ var _App__WEBPACK_IMPORTED_MODULE_1__ = __webpack_require__(/*! ./App */ "./admin-ui/App.jsx");
/* harmony import */ var _board_css__WEBPACK_IMPORTED_MODULE_2__ = __webpack_require__(/*! ./board.css */ "./admin-ui/board.css");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__ = __webpack_require__(/*! react/jsx-runtime */ "react/jsx-runtime");
/* harmony import */ var react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3___default = /*#__PURE__*/__webpack_require__.n(react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__);




const mount = document.getElementById('translangs-board');
if (mount) {
  (0,_wordpress_element__WEBPACK_IMPORTED_MODULE_0__.createRoot)(mount).render(/*#__PURE__*/(0,react_jsx_runtime__WEBPACK_IMPORTED_MODULE_3__.jsx)(_App__WEBPACK_IMPORTED_MODULE_1__["default"], {}));
}
})();

/******/ })()
;
//# sourceMappingURL=index.js.map