/* global snapbookData */
(function () {
  "use strict";

  /* -------------------------------------------------
     Config (localized as snapbookData by shortcode.php)
  ------------------------------------------------- */
  const D = window.snapbookData || {};
  const I18N = D.i18n || {};
  const LOC = D.locale || {};
  const MONEY = D.money || {};

  // Customer-facing text: the translated string from PHP, else English.
  function t(key, fallback) {
    const v = I18N[key];
    return typeof v === "string" && v !== "" ? v : fallback;
  }

  // "{name}" placeholders → values; unknown placeholders are left alone.
  function fill(str, vars) {
    return String(str).replace(/\{(\w+)\}/g, (m, k) =>
      Object.prototype.hasOwnProperty.call(vars, k) ? String(vars[k]) : m,
    );
  }

  function list(arr, len, fallback) {
    return Array.isArray(arr) && arr.length === len ? arr : fallback;
  }
  const MONTHS = list(LOC.months, 12, [
    "January", "February", "March", "April", "May", "June",
    "July", "August", "September", "October", "November", "December",
  ]);
  const MONTHS_SHORT = list(LOC.monthsShort, 12, MONTHS.map((m) => m.slice(0, 3)));
  const WEEKDAYS = list(LOC.weekdays, 7, [
    "Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday",
  ]);
  const WEEKDAYS_SHORT = list(LOC.weekdaysShort, 7, WEEKDAYS.map((d) => d.slice(0, 3)));

  /* -------------------------------------------------
     State
  ------------------------------------------------- */
  let sessions = [];
  let packages = [];
  let addons = [];
  let paymentGateways = [];

  // Live availability (snapbook_get_availability). Until it loads, no date
  // can be picked — a failed request must never read as "every day is free".
  let availability = {
    loaded: false,
    failed: false,
    unavailable: {}, // "YYYY-MM-DD" => "booked" | "blocked"
    rules: null, // today, minDate, maxDate, closedWeekdays, weekStart
    slots: { enabled: false, times: [], taken: {} },
  };
  // Dates/times the server refused since the page loaded (taken meanwhile);
  // kept across availability reloads.
  const localUnavailable = {};
  const localTaken = {};

  let activeSessionId = null;
  let selectedPkgId = null;
  let selectedPkg = null;
  let chosenAddons = [];
  let chosenDate = null;
  let chosenTime = ""; // "HH:MM" when the studio offers start times
  let couponCode = ""; // validated promo code ('' = none)

  // ?package={slug-or-id} share link — resolved to a package row after the
  // booking data loads, applied once when the package grid first renders.
  // The param stays in the URL, so a refresh keeps the pre-selection.
  let preselectPkg = null;

  const checkoutMode = D.checkoutMode === "redirect" ? "redirect" : "direct";
  let partialPaymentEnabled = !!D.partialPaymentEnabled;
  let partialBlockDays = parseInt(D.partialBlockDays || 0, 10) || 0;
  let partialOptionLabel =
    D.partialOptionLabel || "Book your slot with a {deposit_pct}% deposit";
  let usePartialPayment = partialPaymentEnabled;

  // Server quote for the current selection (snapbook_preview_payment).
  let serverQuote = null; // { key, q }
  let previewSeq = 0;
  let previewTimer = null;

  // The Contract step (SnapBook → Booking Form) sits between Details and Payment
  // when it's on, so the wizard is 4 steps instead of 3. Every step number in
  // this file is derived from these constants.
  const contractEnabled = !!D.contractEnabled;
  const contractInfo = D.contract || {};
  const signatureRequired = contractEnabled && !!contractInfo.signature;
  const PAY_STEP = contractEnabled ? 4 : 3;
  const TOTAL_STEPS = PAY_STEP;

  let currentStep = 1;
  let bookingLocked = false; // set once an order is placed/confirmed
  let embedOrder = null; // { id, key, snapshot } — order behind the embedded payment
  let placeInFlight = false;
  let placeQueued = false;

  // Progress kept across reloads / a trip to the login page.
  let userTouched = false; // a real click/keypress since load
  let restoring = false;
  let progressReady = false; // saving starts once the stored copy was read

  /* -------------------------------------------------
     AJAX
  ------------------------------------------------- */
  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", D.nonce || "");
    Object.entries(data || {}).forEach(([k, v]) =>
      fd.append(k, v === null || v === undefined ? "" : v),
    );
    return fetch(D.ajaxUrl, { method: "POST", body: fd })
      .then((r) => r.text())
      .then((text) => {
        // WordPress answers an expired security token with a bare "-1" (or
        // "0"), e.g. on a page served from a page cache. Say so instead of
        // failing silently.
        const tx = String(text).trim();
        if (tx === "-1" || tx === "0") {
          return {
            success: false,
            data: {
              message: t(
                "expired",
                "This page has expired. Please refresh the page and try again.",
              ),
            },
          };
        }
        return JSON.parse(text);
      });
  }

  // Ids of the chosen add-ons, as the server prices bookings from ids only.
  function addonIdsCsv() {
    return chosenAddons.map((a) => parseInt(a.id, 10)).join(",");
  }

  /* -------------------------------------------------
     Hold token — one random id per browser tab, so the
     customer's own unpaid order never blocks their date.
  ------------------------------------------------- */
  const HOLD_KEY = "snapbook_hold_token";
  let holdTokenCache = "";

  function randomToken(len) {
    const chars =
      "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
    let out = "";
    const c = window.crypto;
    if (c && c.getRandomValues) {
      const buf = new Uint32Array(len);
      c.getRandomValues(buf);
      for (let i = 0; i < len; i++) out += chars[buf[i] % chars.length];
    } else {
      for (let i = 0; i < len; i++) {
        out += chars[Math.floor(Math.random() * chars.length)];
      }
    }
    return out;
  }

  function holdToken() {
    if (holdTokenCache) return holdTokenCache;
    let tok = "";
    try {
      tok = window.sessionStorage.getItem(HOLD_KEY) || "";
    } catch (_e) {
      tok = "";
    }
    if (!/^[A-Za-z0-9]{24}$/.test(tok)) {
      tok = randomToken(24);
      try {
        window.sessionStorage.setItem(HOLD_KEY, tok);
      } catch (_e) {
        /* private mode — the token still lives for this page */
      }
    }
    holdTokenCache = tok;
    return tok;
  }

  /* -------------------------------------------------
     Money, dates, times — in the shop's / site's format
  ------------------------------------------------- */
  function formatMoney(amount) {
    const n = Number(amount);
    const value = isFinite(n) ? n : 0;
    const dec = parseInt(MONEY.decimals, 10);
    const decimals = isNaN(dec) ? 2 : Math.max(0, Math.min(6, dec));
    const dsep = typeof MONEY.decimalSep === "string" ? MONEY.decimalSep : ".";
    const tsep =
      typeof MONEY.thousandSep === "string" ? MONEY.thousandSep : ",";
    const fixed = Math.abs(value).toFixed(decimals);
    const parts = fixed.split(".");
    const int = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, tsep);
    const num = parts.length > 1 ? int + dsep + parts[1] : int;
    const sym = typeof MONEY.symbol === "string" ? MONEY.symbol : "";
    const nb = " ";
    let out;
    switch (MONEY.position) {
      case "right":
        out = num + sym;
        break;
      case "left_space":
        out = sym + nb + num;
        break;
      case "right_space":
        out = num + nb + sym;
        break;
      default:
        out = sym + num;
    }
    return (value < 0 && Number(fixed) !== 0 ? "−" : "") + out;
  }

  function fmtPct(p) {
    return String(parseFloat(Number(p || 0).toFixed(2)));
  }

  function round2(n) {
    return Math.round((Number(n) || 0) * 100) / 100;
  }

  function pad2(n) {
    return String(n).padStart(2, "0");
  }

  function parseYmd(ds) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(ds || "");
    return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
  }

  function ymd(d) {
    return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate());
  }

  // PHP date() subset used by WordPress date formats, with the site's
  // month/weekday names.
  function phpDate(fmt, d) {
    let out = "";
    for (let i = 0; i < fmt.length; i++) {
      const c = fmt[i];
      if (c === "\\") {
        i++;
        if (i < fmt.length) out += fmt[i];
        continue;
      }
      const day = d.getDate();
      switch (c) {
        case "d": out += pad2(day); break;
        case "j": out += day; break;
        case "S":
          out +=
            day % 10 === 1 && day !== 11 ? "st"
              : day % 10 === 2 && day !== 12 ? "nd"
                : day % 10 === 3 && day !== 13 ? "rd"
                  : "th";
          break;
        case "l": out += WEEKDAYS[d.getDay()]; break;
        case "D": out += WEEKDAYS_SHORT[d.getDay()]; break;
        case "N": out += d.getDay() || 7; break;
        case "w": out += d.getDay(); break;
        case "F": out += MONTHS[d.getMonth()]; break;
        case "M": out += MONTHS_SHORT[d.getMonth()]; break;
        case "m": out += pad2(d.getMonth() + 1); break;
        case "n": out += d.getMonth() + 1; break;
        case "Y": out += d.getFullYear(); break;
        case "y": out += String(d.getFullYear()).slice(-2); break;
        default: out += c;
      }
    }
    return out;
  }

  function formatDate(ds, withWeekday) {
    const d = parseYmd(ds);
    if (!d) return ds || "—";
    const fmt = LOC.dateFormat || "F j, Y";
    let out = phpDate(fmt, d);
    if (withWeekday && !/(^|[^\\])[lD]/.test(fmt)) {
      out = WEEKDAYS[d.getDay()] + ", " + out;
    }
    return out;
  }

  function formatTime(hm) {
    const m = /^(\d{1,2}):(\d{2})$/.exec(hm || "");
    if (!m) return hm || "";
    const h = parseInt(m[1], 10);
    const fmt = LOC.timeFormat || "g:i a";
    let out = "";
    for (let i = 0; i < fmt.length; i++) {
      const c = fmt[i];
      if (c === "\\") {
        i++;
        if (i < fmt.length) out += fmt[i];
        continue;
      }
      switch (c) {
        case "g": out += h % 12 || 12; break;
        case "G": out += h; break;
        case "h": out += pad2(h % 12 || 12); break;
        case "H": out += pad2(h); break;
        case "i": out += m[2]; break;
        case "a": out += h < 12 ? LOC.am || "am" : LOC.pm || "pm"; break;
        case "A": out += h < 12 ? LOC.AM || "AM" : LOC.PM || "PM"; break;
        default: out += c;
      }
    }
    return out;
  }

  function prefersReducedMotion() {
    return !!(
      window.matchMedia &&
      window.matchMedia("(prefers-reduced-motion: reduce)").matches
    );
  }

  function scrollBehavior() {
    return prefersReducedMotion() ? "auto" : "smooth";
  }

  /* -------------------------------------------------
     Shareable package deep-link (?package=slug-or-id)
  ------------------------------------------------- */
  function getUrlPackageParam() {
    // A pre-selected package can come from the shortcode's package
    // attribute (data-package on the wrapper) or a ?package= share link.
    // The explicit shortcode choice wins over the URL.
    try {
      const wrap = document.querySelector(".fpb-wrap[data-package]");
      const fromWrap = wrap
        ? (wrap.getAttribute("data-package") || "").trim()
        : "";
      if (fromWrap) return fromWrap;
    } catch (_e) {
      /* fall through to URL */
    }
    try {
      return (
        new URLSearchParams(window.location.search).get("package") || ""
      ).trim();
    } catch (_e) {
      return "";
    }
  }

  // Resolve the ?package= value against the loaded data. Only active
  // packages (under an active session type) are returned by the server,
  // so anything unknown here is unavailable → show the notice.
  function resolvePreselectPackage() {
    const param = getUrlPackageParam();
    if (!param) return;

    const wanted = param.toLowerCase();
    const found =
      packages.find((p) => String(p.slug || "").toLowerCase() === wanted) ||
      packages.find((p) => String(p.id) === wanted);
    const sessionOk =
      found &&
      sessions.some(
        (s) => parseInt(s.id, 10) === parseInt(found.session_id, 10),
      );

    if (found && sessionOk) {
      preselectPkg = found;
    } else {
      const notice = byId("fpb-pkgNotice");
      if (notice) notice.style.display = "";
    }
  }

  /* -------------------------------------------------
     Loading skeletons
  ------------------------------------------------- */
  // Placeholder shapes shown while data is in flight, so the form has its
  // final dimensions from the first paint and nothing jumps when data lands.
  // Disabled from the admin via Frontend → "Loading placeholders".
  // The placeholders are injected as direct children of the existing grids
  // (#fpb-calGrid, #fpb-pkgGrid), so they inherit those grid tracks. Wrapping
  // them in a container of their own would nest a grid inside a grid and
  // collapse the whole skeleton into the first column.
  function skeletonHtml(kind) {
    if (kind === "calendar") {
      return '<span class="fpb-skel fpb-skel-cell" aria-hidden="true"></span>'.repeat(
        35,
      );
    }
    return (
      '<div class="fpb-skel-card" aria-hidden="true">' +
      '<span class="fpb-skel fpb-skel-line fpb-skel-w60"></span>' +
      '<span class="fpb-skel fpb-skel-line fpb-skel-w40"></span>' +
      '<span class="fpb-skel fpb-skel-line"></span>' +
      "</div>"
    ).repeat(3);
  }

  function showSkeleton(kind) {
    if (!D.showLoader) return;
    const target = byId(kind === "calendar" ? "fpb-calGrid" : "fpb-pkgGrid");
    if (!target || target.dataset.fpbSkel === "1") return;
    target.dataset.fpbSkel = "1";
    target.setAttribute("aria-busy", "true");
    target.innerHTML = skeletonHtml(kind);
  }

  function clearSkeleton() {
    ["fpb-calGrid", "fpb-pkgGrid"].forEach((id) => {
      const el = byId(id);
      if (!el || el.dataset.fpbSkel !== "1") return;
      delete el.dataset.fpbSkel;
      el.removeAttribute("aria-busy");
      el.innerHTML = "";
    });
  }

  /* -------------------------------------------------
     Init
  ------------------------------------------------- */
  // The catalog ships inline with the page, so packages paint immediately.
  // Only availability needs a live request (a cached page must never offer
  // a date that has since been booked).
  function init() {
    const inline = D.catalog;
    const catalogReady = !!(inline && inline.packages);

    if (catalogReady) {
      applyCatalog(inline);
      resolvePreselectPackage();
      renderSessionTabs();
      updatePkgNextState();
    } else {
      showSkeleton("packages");
    }
    showSkeleton("calendar");
    renderWeekdayHeader();
    renderSlots();

    const catalogReq = catalogReady
      ? Promise.resolve(null)
      : post("snapbook_get_data", {});

    Promise.all([catalogReq, loadAvailability()])
      .then((responses) => {
        const catRes = responses[0];
        // Clear placeholders before rendering, or the render would be
        // overwritten by the teardown.
        clearSkeleton();
        if (!catalogReady) {
          if (catRes && catRes.success) {
            applyCatalog(catRes.data);
            resolvePreselectPackage();
            renderSessionTabs();
            updatePkgNextState();
          } else {
            showCatalogError();
          }
        }
        initCalendar();
        if (availability.loaded) restoreProgress();
      })
      .catch(() => {
        clearSkeleton();
        showCatalogError();
        initCalendar();
      });
  }

  function showCatalogError() {
    const el = byId("fpb-typeTabs");
    if (el) {
      el.innerHTML =
        '<p class="fpb-load-error" role="alert">' +
        escHtml(t("loadError", "Could not load booking data. Please refresh.")) +
        "</p>";
    }
  }

  // Copy a catalog payload (inline or AJAX — same shape) into module state.
  function applyCatalog(data) {
    sessions = data.sessions || [];
    packages = data.packages || [];
    addons = data.addons || [];
    if (typeof data.partialPaymentEnabled !== "undefined") {
      partialPaymentEnabled = !!data.partialPaymentEnabled;
      partialBlockDays = parseInt(data.partialBlockDays || 0, 10) || 0;
      partialOptionLabel = data.partialOptionLabel || partialOptionLabel;
      if (!partialPaymentEnabled) usePartialPayment = false;
    }
  }

  /* -------------------------------------------------
     Availability
  ------------------------------------------------- */
  function loadAvailability() {
    return post("snapbook_get_availability", { hold_token: holdToken() })
      .then((res) => {
        if (res && res.success && res.data) applyAvailability(res.data);
        else availabilityFailed();
      })
      .catch(() => availabilityFailed());
  }

  function applyAvailability(data) {
    // PHP sends an empty map as [], so normalise.
    const obj = (v) => (v && typeof v === "object" && !Array.isArray(v) ? v : {});
    const rules = obj(data.rules);
    const slots = obj(data.slots);
    const times = Array.isArray(slots.times)
      ? slots.times.map(String).filter((x) => /^\d{2}:\d{2}$/.test(x))
      : [];
    const taken = {};
    Object.entries(obj(slots.taken)).forEach(([ds, arr]) => {
      taken[ds] = Array.isArray(arr) ? arr.map(String) : [];
    });
    Object.entries(localTaken).forEach(([ds, arr]) => {
      taken[ds] = (taken[ds] || []).concat(arr);
    });

    availability = {
      loaded: true,
      failed: false,
      unavailable: Object.assign({}, obj(data.unavailable), localUnavailable),
      rules: {
        today: String(rules.today || ymd(new Date())),
        minDate: String(rules.minDate || ""),
        maxDate: String(rules.maxDate || ""),
        closedWeekdays: (Array.isArray(rules.closedWeekdays)
          ? rules.closedWeekdays
          : []
        )
          .map((n) => parseInt(n, 10))
          .filter((n) => n >= 0 && n <= 6),
        weekStart: parseInt(rules.weekStart, 10) || 0,
      },
      slots: { enabled: !!slots.enabled && times.length > 0, times, taken },
    };
    hideCalErr();
    renderWeekdayHeader();
  }

  function availabilityFailed() {
    availability.loaded = false;
    availability.failed = true;
    showCalErr(t("availabilityError", "Availability couldn't be loaded."), true);
  }

  function retryAvailability() {
    hideCalErr();
    showSkeleton("calendar");
    loadAvailability().then(() => {
      clearSkeleton();
      if (availability.loaded) {
        startMonth();
        renderCalendar();
        renderSlots();
        restoreProgress();
      } else {
        renderCalendar();
      }
    });
  }

  function slotsOn() {
    return availability.loaded
      ? !!availability.slots.enabled
      : !!D.slotsEnabled;
  }

  function todayStr() {
    return (availability.rules && availability.rules.today) || ymd(new Date());
  }

  function takenTimes(ds) {
    const v = availability.slots.taken[ds];
    return Array.isArray(v) ? v : [];
  }

  function freeTimes(ds) {
    const taken = takenTimes(ds);
    return availability.slots.times.filter((x) => taken.indexOf(x) === -1);
  }

  // '' when the date can be booked, else why not.
  function dateStatus(ds) {
    const r = availability.rules || {};
    if (ds < todayStr()) return "past";
    if (r.minDate && ds < r.minDate) return "notice";
    if (r.maxDate && ds > r.maxDate) return "window";
    const d = parseYmd(ds);
    if (d && (r.closedWeekdays || []).indexOf(d.getDay()) !== -1) return "closed";
    if (!availability.loaded) return "unknown";
    const u = availability.unavailable[ds];
    if (u) return String(u);
    if (slotsOn() && freeTimes(ds).length === 0) return "full";
    return "";
  }

  /* -------------------------------------------------
     Payment gateways (fallback list / redirect mode)
  ------------------------------------------------- */
  // Payment gateways are only needed on the payment step, so they are
  // fetched when the customer commits to a package rather than on page
  // load — one less request blocking the first render.
  let gatewaysPromise = null;
  let gatewaysLoaded = false;
  function prefetchGateways() {
    if (gatewaysPromise || !D.hasWC) return gatewaysPromise;
    gatewaysPromise = post("snapbook_get_payment_gateways", {})
      .then((res) => {
        if (res && res.success) paymentGateways = res.data.gateways || [];
        gatewaysLoaded = true;
      })
      .catch(() => {
        // Settled either way: the payment step falls back to the embedded
        // checkout, and the list must not sit on "loading" forever.
        gatewaysLoaded = true;
      });
    return gatewaysPromise;
  }

  /* -------------------------------------------------
     Session types
  ------------------------------------------------- */
  function renderSessionTabs() {
    const wrap = byId("fpb-typeTabs");
    if (!wrap) return;
    if (!sessions.length) {
      wrap.innerHTML =
        '<span class="fpb-stype-loading">' +
        escHtml(t("noSessions", "No session types configured.")) +
        "</span>";
      return;
    }

    wrap.innerHTML = sessions
      .map(
        (s) =>
          '<button class="fpb-stype-btn" data-id="' +
          parseInt(s.id, 10) +
          '" type="button" aria-pressed="false">' +
          (s.emoji
            ? '<span class="fpb-stype-em" aria-hidden="true">' +
              iconHtml(s.emoji) +
              "</span>"
            : "") +
          '<span class="fpb-stype-name">' +
          escHtml(s.name) +
          "</span>" +
          "</button>",
      )
      .join("");

    wrap.querySelectorAll(".fpb-stype-btn").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        if (e.isTrusted) userTouched = true;
        activateSession(btn);
      });
    });

    // Auto-select first — or the session type owning the ?package= link
    let startBtn = wrap.querySelector(".fpb-stype-btn");
    if (preselectPkg) {
      const target = wrap.querySelector(
        '.fpb-stype-btn[data-id="' + parseInt(preselectPkg.session_id, 10) + '"]',
      );
      if (target) startBtn = target;
    }
    if (startBtn) activateSession(startBtn);
  }

  function activateSession(btn) {
    const wrap = byId("fpb-typeTabs");
    if (!wrap) return;
    wrap.querySelectorAll(".fpb-stype-btn").forEach((b) => {
      b.classList.remove("fpb-act");
      b.setAttribute("aria-pressed", "false");
    });
    btn.classList.add("fpb-act");
    btn.setAttribute("aria-pressed", "true");
    activeSessionId = parseInt(btn.dataset.id, 10);
    selectedPkgId = null;
    selectedPkg = null;
    showAddons(false);
    renderPackages();
    renderPartialPaymentOption();
    onSelectionChanged();
  }

  /* -------------------------------------------------
     Package cards
  ------------------------------------------------- */
  function renderPackages() {
    const grid = byId("fpb-pkgGrid");
    if (!grid) return;
    const pkgs = packages.filter(
      (p) => parseInt(p.session_id, 10) === activeSessionId,
    );
    if (!pkgs.length) {
      grid.innerHTML =
        '<p class="fpb-no-pkgs">' +
        escHtml(t("noPackages", "No packages for this session type yet.")) +
        "</p>";
      return;
    }
    grid.innerHTML = pkgs
      .map(
        (p) =>
          '<div class="fpb-pkg' +
          (p.featured == "1" ? " fpb-feat" : "") +
          '" data-id="' +
          parseInt(p.id, 10) +
          '" role="button" tabindex="0" aria-pressed="false">' +
          (p.featured == "1"
            ? '<span class="fpb-pkg-tag">&#9733; ' +
              escHtml(t("popular", "Popular")) +
              "</span>"
            : "") +
          '<div class="fpb-pkg-name">' +
          escHtml(p.name) +
          "</div>" +
          '<div class="fpb-pkg-price">' +
          escHtml(formatMoney(p.price)) +
          "</div>" +
          (p.duration
            ? '<div class="fpb-pkg-dur">' + escHtml(p.duration) + "</div>"
            : "") +
          // Description is rich text sanitized server-side (wp_kses_post)
          (p.description
            ? '<div class="fpb-pkg-desc">' + p.description + "</div>"
            : "") +
          "</div>",
      )
      .join("");
    grid.querySelectorAll(".fpb-pkg").forEach((card) => {
      card.addEventListener("click", (e) => {
        if (e.isTrusted) userTouched = true;
        selectCard(card);
      });
      card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          userTouched = true;
          selectCard(card);
        }
      });
    });

    // Apply the ?package= pre-selection once. Goes through the same path
    // as a manual choice, so add-ons render visible but unchecked.
    if (
      preselectPkg &&
      parseInt(preselectPkg.session_id, 10) === activeSessionId
    ) {
      const card = grid.querySelector(
        '.fpb-pkg[data-id="' + parseInt(preselectPkg.id, 10) + '"]',
      );
      preselectPkg = null;
      if (card) selectCard(card);
    }
  }

  function selectCard(card) {
    const grid = byId("fpb-pkgGrid");
    if (!grid) return;
    grid.querySelectorAll(".fpb-pkg").forEach((c) => {
      c.classList.remove("fpb-sel");
      c.setAttribute("aria-pressed", "false");
    });
    card.classList.add("fpb-sel");
    card.setAttribute("aria-pressed", "true");
    selectedPkgId = parseInt(card.dataset.id, 10);
    selectedPkg =
      packages.find((p) => parseInt(p.id, 10) === selectedPkgId) || null;
    clearErr("fpb-s1err");
    renderAddons();
    renderPartialPaymentOption();
    onSelectionChanged();
  }

  /* -------------------------------------------------
     Add-ons
  ------------------------------------------------- */
  function addonPackageIds(a) {
    const csv = String(a.package_ids || "").trim();
    if (csv) {
      return csv
        .split(",")
        .map((x) => parseInt(x, 10))
        .filter((x) => x > 0);
    }
    const single = parseInt(a.package_id, 10) || 0; // legacy rows
    return single > 0 ? [single] : [];
  }

  function renderAddons() {
    const grid = byId("fpb-addonsGrid");
    const wrap = byId("fpb-addonsWrap");
    if (!grid) return;

    // Show add-ons that are global (no package list) OR assigned to the
    // selected package. package_ids is a CSV; package_id covers legacy rows.
    const visible = addons.filter((a) => {
      const ids = addonPackageIds(a);
      return !ids.length || ids.indexOf(selectedPkgId) !== -1;
    });

    if (!visible.length) {
      if (wrap) wrap.style.display = "none";
      grid.innerHTML = "";
      return;
    }

    grid.innerHTML = visible
      .map((a) => {
        const id = parseInt(a.id, 10);
        const pkgOnly = addonPackageIds(a).length > 0;
        const icon = iconHtml(a.emoji);
        return (
          '<label class="fpb-addon-card" for="fpb-addon-' +
          id +
          '">' +
          '<input class="fpb-ac" type="checkbox" value="' +
          id +
          '" id="fpb-addon-' +
          id +
          '">' +
          (icon
            ? '<span class="fpb-addon-em" aria-hidden="true">' + icon + "</span>"
            : "") +
          '<span class="fpb-addon-info">' +
          '<span class="fpb-addon-name">' +
          escHtml(a.name) +
          "</span>" +
          // Description is rich text sanitized server-side (wp_kses_post)
          (a.description
            ? '<span class="fpb-addon-desc">' + a.description + "</span>"
            : "") +
          (pkgOnly
            ? '<span class="fpb-addon-badge">' +
              escHtml(t("packageOnly", "This package only")) +
              "</span>"
            : "") +
          "</span>" +
          '<span class="fpb-addon-price">+' +
          escHtml(formatMoney(a.price)) +
          "</span>" +
          "</label>"
        );
      })
      .join("");

    grid.querySelectorAll(".fpb-ac").forEach((cb) => {
      cb.addEventListener("change", (e) => {
        if (e.isTrusted) userTouched = true;
        onSelectionChanged();
      });
    });

    if (wrap) wrap.style.display = "";
  }

  function collectAddons() {
    chosenAddons = [];
    document
      .querySelectorAll("#fpb-addonsGrid .fpb-ac:checked")
      .forEach((cb) => {
        const a = addons.find((x) => String(x.id) === cb.value);
        if (a) chosenAddons.push(a);
      });
  }

  function showAddons(show) {
    // show/hide is now managed by renderAddons; only reset checkboxes on hide
    if (!show) {
      const w = byId("fpb-addonsWrap");
      if (w) w.style.display = "none";
      document
        .querySelectorAll("#fpb-addonsGrid input")
        .forEach((cb) => (cb.checked = false));
      chosenAddons = [];
    }
  }

  // Anything that changes the price or the selection.
  function onSelectionChanged() {
    collectAddons();
    renderQuote(currentQuote());
    updatePkgNextState();
    schedulePreview();
    saveProgress();
    updateLoginLinks();
  }

  /* -------------------------------------------------
     Deposit
  ------------------------------------------------- */
  // A package's own deposit % (catalog) when set, else the global setting.
  function pkgDepositPct(pkg) {
    const own = pkg ? parseInt(pkg.deposit_pct, 10) || 0 : 0;
    if (own > 0 && own < 100) return own;
    const global = parseInt(D.depositPct, 10) || 50;
    return Math.max(1, Math.min(99, global));
  }

  function daysUntil(ds) {
    const a = parseYmd(todayStr());
    const b = parseYmd(ds);
    if (!a || !b) return null;
    return Math.round(
      (Date.UTC(b.getFullYear(), b.getMonth(), b.getDate()) -
        Date.UTC(a.getFullYear(), a.getMonth(), a.getDate())) /
        86400000,
    );
  }

  function canUsePartialForSelectedDate() {
    if (!partialPaymentEnabled) return false;
    if (!chosenDate) return false;
    if (partialBlockDays <= 0) return true;
    const days = daysUntil(chosenDate);
    return days !== null && days >= partialBlockDays;
  }

  // 1 when the customer pays a deposit now (the server then charges the
  // package's deposit %), else 0.
  function useDepositFlag() {
    return partialPaymentEnabled &&
      usePartialPayment &&
      canUsePartialForSelectedDate()
      ? 1
      : 0;
  }

  function getEffectiveDepositPct() {
    return useDepositFlag() ? pkgDepositPct(selectedPkg) : 100;
  }

  function partialNoteText(on) {
    const pct = fmtPct(pkgDepositPct(selectedPkg));
    return on
      ? fill(t("partialOn", "Pay {pct}% now and settle the rest later."), { pct })
      : fill(
          t(
            "partialOff",
            "You'll pay the full amount now. Switch on to pay a {pct}% deposit instead.",
          ),
          { pct },
        );
  }

  function initPartialPaymentOption() {
    const toggle = byId("fpb-partialToggle");
    if (!toggle) return;

    toggle.addEventListener("change", (e) => {
      if (e.isTrusted) userTouched = true;
      toggle.dataset.touched = "1";
      usePartialPayment = toggle.checked;
      setTxt("fpb-partialNote", partialNoteText(toggle.checked));
      onSelectionChanged();
      if (onPayStep()) updateSummary();
    });
  }

  function renderPartialPaymentOption() {
    const wrap = byId("fpb-partialWrap");
    const label = byId("fpb-partialLabel");
    const note = byId("fpb-partialNote");
    const toggle = byId("fpb-partialToggle");
    if (!wrap || !label || !note || !toggle) return;

    if (!partialPaymentEnabled) {
      wrap.style.display = "none";
      usePartialPayment = false;
      return;
    }

    const pct = pkgDepositPct(selectedPkg);
    label.textContent = fill(partialOptionLabel, { deposit_pct: pct });
    setTxt("fpb-partialEm", pct + "%");
    wrap.style.display = "none";

    if (!chosenDate) {
      toggle.checked = false;
      toggle.disabled = true;
      usePartialPayment = false;
      note.textContent = "";
      return;
    }

    if (canUsePartialForSelectedDate()) {
      wrap.style.display = "";
      toggle.disabled = false;
      if (!toggle.dataset.touched) {
        toggle.checked = true;
      }
      usePartialPayment = toggle.checked;
      note.textContent = partialNoteText(toggle.checked);
      return;
    }

    wrap.style.display = "none";
    toggle.checked = false;
    toggle.disabled = true;
    usePartialPayment = false;
    note.textContent = "";
  }

  /* -------------------------------------------------
     Pricing — local estimate first, then the server's
     quote (snapbook_preview_payment), which is what the
     order will actually charge.
  ------------------------------------------------- */
  function feePctLocal() {
    if (!D.hasWC) return 0;
    const p = parseFloat(D.paymentFeePct || 0);
    return isNaN(p) || p <= 0 ? 0 : Math.min(100, p);
  }

  function feeLabelDefault() {
    return D.paymentFeeLabel || t("paymentFee", "Payment fee");
  }

  function selectionBase() {
    return [
      selectedPkg ? parseInt(selectedPkg.id, 10) : 0,
      addonIdsCsv(),
      chosenDate || "",
      useDepositFlag(),
    ].join("|");
  }

  function localQuote() {
    const subtotal = round2(
      (selectedPkg ? parseFloat(selectedPkg.price || 0) : 0) +
        chosenAddons.reduce((s, a) => s + parseFloat(a.price || 0), 0),
    );
    const feePct = feePctLocal();
    const fee = round2((subtotal * feePct) / 100);
    const payable = round2(subtotal + fee);
    const payPct = payable > 0 ? getEffectiveDepositPct() : 100;
    const dueNow = round2((payable * payPct) / 100);
    return {
      subtotal,
      discount: 0,
      couponCode: "",
      total: subtotal,
      feePct,
      feeLabel: feeLabelDefault(),
      feeAmount: fee,
      payable,
      payPct,
      depositPct: pkgDepositPct(selectedPkg),
      dueNow,
      balance: Math.max(0, round2(payable - dueNow)),
      balanceDueDate: "",
      server: false,
    };
  }

  function quoteFromPreview(d) {
    const num = (v) => parseFloat(v || 0) || 0;
    const payable = num(d.payable || d.total);
    return {
      subtotal: num(d.subtotal !== undefined ? d.subtotal : d.total),
      discount: num(d.discount),
      couponCode: String(d.couponCode || ""),
      total: num(d.total),
      feePct: num(d.feePct),
      feeLabel: d.feeLabel || feeLabelDefault(),
      feeAmount: num(d.feeAmount),
      payable,
      payPct: parseInt(d.payPct || 100, 10) || 100,
      depositPct: parseInt(d.depositPct || 0, 10) || pkgDepositPct(selectedPkg),
      dueNow: num(d.dueToday),
      balance: num(d.balanceDue),
      balanceDueDate: String(d.balanceDueDate || ""),
      server: true,
    };
  }

  function currentQuote() {
    if (serverQuote && serverQuote.key === selectionBase() + "|" + couponCode) {
      return serverQuote.q;
    }
    return localQuote();
  }

  function schedulePreview(delay) {
    if (!D.hasWC || !selectedPkg) return;
    clearTimeout(previewTimer);
    previewTimer = setTimeout(
      () => {
        runPreview(couponCode).catch(() => {
          /* keep the local estimate */
        });
      },
      delay === undefined ? 200 : delay,
    );
  }

  // Resolves with the raw response (the promo code form reads couponError).
  function runPreview(code) {
    if (!selectedPkg) return Promise.resolve(null);
    const base = selectionBase();
    const seq = ++previewSeq;
    const data = {
      package_id: parseInt(selectedPkg.id, 10),
      addon_ids: addonIdsCsv(),
      session_date: chosenDate || "",
      use_deposit: useDepositFlag(),
      coupon_code: code || "",
      total_raw: localQuote().subtotal,
    };
    const email = detailValue("email");
    if (code && email) data["details[email]"] = email;

    return post("snapbook_preview_payment", data).then((res) => {
      if (res && res.success && res.data) {
        const q = quoteFromPreview(res.data);
        // An invalid code is priced without it: key on what was priced.
        const key = base + "|" + (res.data.couponCode ? q.couponCode : "");
        if (seq === previewSeq) {
          serverQuote = { key, q };
          renderQuote(currentQuote());
        }
      }
      return res;
    });
  }

  function priceNoteText(q) {
    const parts = [];
    if (q.discount > 0 && q.couponCode) {
      parts.push(
        fill(t("promoIncluded", "Promo code {code} applied: {amount}"), {
          code: q.couponCode.toUpperCase(),
          amount: "−" + formatMoney(q.discount),
        }),
      );
    }
    if (q.feePct > 0) {
      let s = fill(t("feeIncluded", "{label} of {pct}% included"), {
        label: q.feeLabel || feeLabelDefault(),
        pct: fmtPct(q.feePct),
      });
      if (D.feeExemptMethods) {
        s +=
          " " +
          fill(t("feeExempt", "(no fee for {methods})"), {
            methods: D.feeExemptMethods,
          });
      }
      parts.push(s);
    }
    return parts.join(" · ");
  }

  // Paint a quote into the Package-step price strip and the payment summary.
  function renderQuote(q) {
    const strip = byId("fpb-s2Price");
    if (!selectedPkg) {
      if (strip) strip.style.display = "none";
      setNote("fpb-s2PriceNote", "");
      return;
    }

    // Package step — the total already includes the payment fee, so the
    // amount doesn't jump on the payment step.
    if (strip) {
      setTxt("fpb-s2Total", formatMoney(q.payable));
      setTxt(
        "fpb-s2DueLabel",
        q.payPct < 100
          ? fill(t("payNowPct", "Pay now ({pct}%)"), { pct: fmtPct(q.payPct) })
          : t("payNow", "Pay now"),
      );
      setTxt("fpb-s2Due", formatMoney(q.dueNow));
      const laterCell = byId("fpb-s2LaterCell");
      if (laterCell) laterCell.style.display = q.balance > 0.01 ? "" : "none";
      setTxt("fpb-s2Later", formatMoney(q.balance));
      strip.style.display = "";
    }
    setNote("fpb-s2PriceNote", priceNoteText(q));

    // Payment step summary.
    const showFee = q.feePct > 0;
    const showDiscount = q.discount > 0 && !!q.couponCode;
    setTxt("fpb-sum-price", formatMoney(q.subtotal));
    const priceLabel = byId("fpb-sum-price-label");
    if (priceLabel) {
      if (!priceLabel.dataset.orig) {
        priceLabel.dataset.orig = priceLabel.textContent;
      }
      // One total only: with a fee or discount the first row becomes
      // "Subtotal" and "Total payable" is the single total.
      priceLabel.textContent =
        showFee || showDiscount
          ? D.subtotalLabel || "Subtotal"
          : priceLabel.dataset.orig;
    }
    const discRow = byId("fpb-sum-discount-row");
    if (discRow) {
      discRow.style.display = showDiscount ? "" : "none";
      if (showDiscount) {
        setTxt("fpb-sum-discount-code", q.couponCode.toUpperCase());
        setTxt("fpb-sum-discount", "−" + formatMoney(q.discount));
      }
    }
    const feeRow = byId("fpb-sum-fee-row");
    if (feeRow) feeRow.style.display = showFee ? "" : "none";
    if (showFee) {
      setTxt(
        "fpb-sum-fee-label",
        (q.feeLabel || feeLabelDefault()) + " (" + fmtPct(q.feePct) + "%)",
      );
      setTxt("fpb-sum-fee", "+" + formatMoney(q.feeAmount));
    }
    const payRow = byId("fpb-sum-payable-row");
    if (payRow) payRow.style.display = showFee || showDiscount ? "" : "none";
    setTxt("fpb-sum-payable", formatMoney(q.payable));
    setTxt("fpb-sum-total", formatMoney(q.dueNow));
    setTxt(
      "fpb-sum-dep",
      q.payPct < 100
        ? fill(t("depositLabel", "{pct}% booking deposit"), {
            pct: fmtPct(q.payPct),
          })
        : t("fullPayment", "Full payment"),
    );

    const balRow = byId("fpb-sum-balance-row");
    if (balRow) {
      if (q.balance > 0.01) {
        const tpl = q.balanceDueDate
          ? t(
              "balanceNoteDate",
              "Remaining balance {amount} is due by {date} — we will send you a payment link.",
            )
          : t(
              "balanceNote",
              "Remaining balance {amount} is due later — we will send you a payment link.",
            );
        balRow.innerHTML = fill(escHtml(tpl), {
          amount:
            '<strong id="fpb-sum-balance">' +
            escHtml(formatMoney(q.balance)) +
            "</strong>",
          date: escHtml(formatDate(q.balanceDueDate, false)),
        });
        balRow.style.display = "";
      } else {
        balRow.style.display = "none";
      }
    }

    const feeNote = byId("fpb-sum-fee-note");
    if (feeNote) {
      if (showFee && D.feeExemptMethods) {
        feeNote.textContent = fill(
          t("feeExemptPay", "{label}: not charged when you pay by {methods}."),
          { label: q.feeLabel || feeLabelDefault(), methods: D.feeExemptMethods },
        );
        feeNote.style.display = "";
      } else {
        feeNote.style.display = "none";
      }
    }
    renderPromoUi();
  }

  /* -------------------------------------------------
     Calendar
  ------------------------------------------------- */
  let calDate = new Date();
  calDate.setDate(1);
  let calendarBound = false;

  function weekStartDay() {
    if (availability.rules) return availability.rules.weekStart;
    return parseInt(LOC.weekStart, 10) || 0;
  }

  function renderWeekdayHeader() {
    const el = byId("fpb-calDays");
    if (!el) return;
    const ws = weekStartDay();
    let html = "";
    for (let i = 0; i < 7; i++) {
      const wd = (ws + i) % 7;
      html +=
        '<span title="' +
        escHtml(WEEKDAYS[wd]) +
        '">' +
        escHtml(WEEKDAYS_SHORT[wd]) +
        "</span>";
    }
    el.innerHTML = html;
  }

  // Open on the month of the chosen date, else the first bookable month.
  function startMonth() {
    const r = availability.rules || {};
    const from = parseYmd(chosenDate || "") || parseYmd(r.minDate || "") || parseYmd(todayStr());
    if (from) calDate = new Date(from.getFullYear(), from.getMonth(), 1);
  }

  function initCalendar() {
    if (!calendarBound) {
      calendarBound = true;
      byId("fpb-calPrev")?.addEventListener("click", () => {
        calDate.setMonth(calDate.getMonth() - 1);
        renderCalendar();
      });
      byId("fpb-calNext")?.addEventListener("click", () => {
        calDate.setMonth(calDate.getMonth() + 1);
        renderCalendar();
      });
      const grid = byId("fpb-calGrid");
      if (grid) {
        grid.addEventListener("click", (e) => {
          const cell = e.target.closest(".fpb-cell[data-date]");
          if (!cell || cell.getAttribute("aria-disabled") === "true") return;
          chooseDate(cell.dataset.date, true);
        });
        grid.addEventListener("keydown", (e) => {
          const cell = e.target.closest(".fpb-cell[data-date]");
          if (!cell) return;
          if (e.key === "Enter" || e.key === " ") {
            e.preventDefault();
            if (cell.getAttribute("aria-disabled") !== "true") {
              chooseDate(cell.dataset.date, true);
            }
          }
        });
      }
      const slots = byId("fpb-slots");
      if (slots) {
        slots.addEventListener("click", (e) => {
          const btn = e.target.closest(".fpb-slot[data-time]");
          if (!btn || btn.disabled) return;
          chooseTime(btn.dataset.time, true);
        });
      }
      const err = byId("fpb-calErr");
      if (err) {
        err.addEventListener("click", (e) => {
          if (e.target.closest("[data-fpb-retry]")) retryAvailability();
        });
      }
    }
    if (availability.loaded) startMonth();
    renderCalendar();
    renderSlots();
  }

  function renderCalendar() {
    const grid = byId("fpb-calGrid");
    const ml = byId("fpb-calMonth");
    if (!grid || !ml || grid.dataset.fpbSkel === "1") return;
    const yr = calDate.getFullYear();
    const mo = calDate.getMonth();
    const days = new Date(yr, mo + 1, 0).getDate();
    const lead = (new Date(yr, mo, 1).getDay() - weekStartDay() + 7) % 7;
    const today = todayStr();
    ml.textContent = MONTHS[mo] + " " + yr;

    let html = "";
    for (let i = 0; i < lead; i++) html += '<span aria-hidden="true"></span>';
    for (let d = 1; d <= days; d++) {
      const ds = yr + "-" + pad2(mo + 1) + "-" + pad2(d);
      const st = dateStatus(ds);
      const label = formatDate(ds, true);
      let cls = "fpb-cell";
      if (st === "past" || st === "notice" || st === "window" || st === "closed" || st === "unknown") {
        cls += " fpb-past";
      } else if (st) {
        cls += " fpb-bkd";
      }
      if (ds === today) cls += " fpb-today";
      if (!st && ds === chosenDate) cls += " fpb-sel";
      html +=
        '<span class="' +
        cls +
        '" data-date="' +
        ds +
        '" role="button"' +
        (st
          ? ' aria-disabled="true" aria-label="' +
            escHtml(fill(t("dateUnavailable", "{date} (unavailable)"), { date: label })) +
            '"'
          : ' tabindex="0" aria-pressed="' +
            (ds === chosenDate ? "true" : "false") +
            '" aria-label="' +
            escHtml(label) +
            '"') +
        ">" +
        d +
        "</span>";
    }
    grid.innerHTML = html;

    // Don't page before the current month or past the booking window.
    const prev = byId("fpb-calPrev");
    const next = byId("fpb-calNext");
    const t0 = parseYmd(today);
    if (prev && t0) {
      prev.disabled = yr < t0.getFullYear() || (yr === t0.getFullYear() && mo <= t0.getMonth());
    }
    const maxD = availability.rules && availability.rules.maxDate ? parseYmd(availability.rules.maxDate) : null;
    if (next) {
      next.disabled = !!maxD && (yr > maxD.getFullYear() || (yr === maxD.getFullYear() && mo >= maxD.getMonth()));
    }
  }

  // Update the selected cell in place (keeps keyboard focus where it is).
  function markSelectedCell() {
    const grid = byId("fpb-calGrid");
    if (!grid) return;
    grid.querySelectorAll(".fpb-cell[data-date]").forEach((c) => {
      const on =
        c.dataset.date === chosenDate &&
        c.getAttribute("aria-disabled") !== "true";
      c.classList.toggle("fpb-sel", on);
      if (c.hasAttribute("aria-pressed")) {
        c.setAttribute("aria-pressed", on ? "true" : "false");
      }
    });
  }

  function chooseDate(ds, byUser) {
    // The booking is placed; its date can't change any more.
    if (bookingLocked) return;
    if (dateStatus(ds) !== "") return;
    if (byUser) userTouched = true;
    chosenDate = ds;
    // Keep the start time only if it's still free on the new date.
    if (chosenTime && (!slotsOn() || takenTimes(ds).indexOf(chosenTime) !== -1)) {
      chosenTime = "";
    }
    markSelectedCell();
    renderSlots();
    updateSelDateText();
    hideCalErr();
    clearErr("fpb-s1err");
    renderPartialPaymentOption();
    onSelectionChanged();
    // Picked a new date while already on the payment step: rebuild the
    // summary and the pending order, or the customer would pay for the
    // date the order was created with.
    if (onPayStep()) populatePaymentStep();
  }

  /* -------------------------------------------------
     Start times (when the studio offers them)
  ------------------------------------------------- */
  function renderSlots() {
    const wrap = byId("fpb-slots");
    if (!wrap) return;
    if (!slotsOn() || !availability.loaded) {
      wrap.hidden = true;
      wrap.innerHTML = "";
      return;
    }
    wrap.hidden = false;
    if (!chosenDate) {
      wrap.innerHTML =
        '<p class="fpb-slots-hint">' +
        escHtml(t("pickDateForTimes", "Pick a date to see the available start times.")) +
        "</p>";
      return;
    }
    const taken = takenTimes(chosenDate);
    wrap.innerHTML =
      '<div class="fpb-slots-title" id="fpb-slotsTitle">' +
      escHtml(t("chooseTime", "Choose a start time")) +
      "</div>" +
      '<div class="fpb-slot-grid" role="group" aria-labelledby="fpb-slotsTitle">' +
      availability.slots.times
        .map((tm) => {
          const busy = taken.indexOf(tm) !== -1;
          const sel = !busy && tm === chosenTime;
          const label = formatTime(tm);
          return (
            '<button type="button" class="fpb-slot' +
            (sel ? " fpb-sel" : "") +
            '" data-time="' +
            escHtml(tm) +
            '"' +
            (busy
              ? ' disabled aria-disabled="true" aria-label="' +
                escHtml(fill(t("timeUnavailable", "{time} (unavailable)"), { time: label })) +
                '"'
              : ' aria-pressed="' + (sel ? "true" : "false") + '"') +
            ">" +
            escHtml(label) +
            "</button>"
          );
        })
        .join("") +
      "</div>";
  }

  function chooseTime(tm, byUser) {
    if (bookingLocked || !chosenDate) return;
    if (takenTimes(chosenDate).indexOf(tm) !== -1) return;
    if (byUser) userTouched = true;
    chosenTime = tm;
    const wrap = byId("fpb-slots");
    if (wrap) {
      wrap.querySelectorAll(".fpb-slot[data-time]").forEach((b) => {
        const on = b.dataset.time === tm && !b.disabled;
        b.classList.toggle("fpb-sel", on);
        if (!b.disabled) b.setAttribute("aria-pressed", on ? "true" : "false");
      });
    }
    updateSelDateText();
    hideCalErr();
    clearErr("fpb-s1err");
    saveProgress();
    if (onPayStep()) populatePaymentStep();
  }

  function updateSelDateText() {
    if (!chosenDate) {
      setTxt("fpb-selDate", "");
      return;
    }
    setTxt(
      "fpb-selDate",
      chosenTime && slotsOn()
        ? fill(t("selectedDateTime", "Selected: {date} at {time}"), {
            date: formatDate(chosenDate, true),
            time: formatTime(chosenTime),
          })
        : fill(t("selectedDate", "Selected: {date}"), {
            date: formatDate(chosenDate, true),
          }),
    );
  }

  function showCalErr(msg, withRetry) {
    const el = byId("fpb-calErr");
    if (!el) return;
    el.innerHTML =
      escHtml(msg) +
      (withRetry
        ? ' <button type="button" class="fpb-link-btn" data-fpb-retry="1">' +
          escHtml(t("tryAgain", "Try again")) +
          "</button>"
        : "");
    el.hidden = false;
  }

  function hideCalErr() {
    const el = byId("fpb-calErr");
    if (el) {
      el.hidden = true;
      el.textContent = "";
    }
  }

  // Missing date/time: bring the calendar into view (it sits above the form
  // on phones), flash it and move focus there.
  function flagCalendar(msg) {
    const card = byId("fpb-calCard") || document.querySelector(".fpb-side-cal");
    if (!card) return;
    if (msg) showCalErr(msg, false);
    attention(card);
  }

  function attention(el) {
    try {
      el.focus({ preventScroll: true });
    } catch (_e) {
      el.focus();
    }
    el.scrollIntoView({ behavior: scrollBehavior(), block: "center" });
    el.classList.remove("fpb-attn");
    void el.offsetWidth; // restart the animation
    el.classList.add("fpb-attn");
    clearTimeout(el.fpbAttnTimer);
    el.fpbAttnTimer = setTimeout(() => el.classList.remove("fpb-attn"), 2000);
  }

  // The server refused the date/time (taken meanwhile, or a rule): mark it
  // unavailable here too and clear the selection.
  function markDateTaken(status) {
    if (!chosenDate) return;
    const ds = chosenDate;
    localUnavailable[ds] = status || "booked";
    availability.unavailable[ds] = localUnavailable[ds];
    chosenDate = null;
    chosenTime = "";
    afterSelectionCleared();
  }

  function markTimeTaken() {
    if (!chosenDate || !chosenTime) return;
    const ds = chosenDate;
    localTaken[ds] = (localTaken[ds] || []).concat([chosenTime]);
    availability.slots.taken[ds] = takenTimes(ds).concat([chosenTime]);
    chosenTime = "";
    // The whole day may now be full.
    if (dateStatus(ds) !== "") chosenDate = null;
    afterSelectionCleared();
  }

  function afterSelectionCleared() {
    renderCalendar();
    renderSlots();
    updateSelDateText();
    renderPartialPaymentOption();
    onSelectionChanged();
  }

  // What still has to be picked in the calendar card ('' = nothing).
  function selectionProblem() {
    if (!chosenDate) {
      return t("errPickDate", "Please pick your session date from the calendar.");
    }
    if (slotsOn() && !chosenTime) {
      return t("errPickTime", "Please choose a start time under the calendar.");
    }
    return "";
  }

  // The Package step's Continue button. The session date is chosen in the
  // sidebar calendar, so it's validated on click (s1Next) rather than
  // gating the button — a package selection is enough to enable it.
  function updatePkgNextState() {
    const btn = byId("fpb-s1NextBtn");
    if (!btn) return;
    const ready = !!selectedPkg;
    btn.disabled = !ready;
    btn.title = ready ? "" : t("selectPackageTitle", "Select a package to continue");
  }

  /* -------------------------------------------------
     Details step (checkout form fields)
  ------------------------------------------------- */
  function collectDetails() {
    const out = {};
    document.querySelectorAll("[data-fpb-cf]").forEach((el) => {
      out[el.getAttribute("data-fpb-cf")] = String(el.value || "").trim();
    });
    return out;
  }

  function detailValue(key) {
    const el = document.querySelector('[data-fpb-cf="' + key + '"]');
    return el ? String(el.value || "").trim() : "";
  }

  function markInvalid(el, errId) {
    el.setAttribute("aria-invalid", "true");
    el.setAttribute("aria-describedby", errId);
  }

  function clearInvalid(el) {
    el.removeAttribute("aria-invalid");
    if (el.getAttribute("aria-describedby") === "fpb-s2err") {
      el.removeAttribute("aria-describedby");
    }
  }

  function validateDetails() {
    const els = document.querySelectorAll("[data-fpb-cf]");
    els.forEach(clearInvalid);
    const fail = (el, msg) => {
      showErr("fpb-s2err", msg);
      markInvalid(el, "fpb-s2err");
      el.focus();
      return false;
    };
    for (const el of els) {
      const key = el.getAttribute("data-fpb-cf");
      const label = el.getAttribute("data-label") || key;
      const required = el.getAttribute("data-required") === "1";
      const value = String(el.value || "").trim();

      if (required && value === "") {
        return fail(el, fill(t("errRequired", "{label} is required."), { label }));
      }
      if (key === "email" && value !== "" && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
        return fail(el, t("errEmail", "Please enter a valid email."));
      }
      if (key === "phone" && value !== "" && !/^[+]?[0-9 \-()]{7,20}$/.test(value)) {
        return fail(
          el,
          t("errPhone", "Please enter a valid phone number (digits, +, spaces, dashes only)."),
        );
      }
      if (key === "participants" && (required || value !== "")) {
        if (parseInt(value, 10) < 1 || isNaN(parseInt(value, 10))) {
          return fail(el, fill(t("errMin1", "{label} must be at least 1."), { label }));
        }
      }
    }
    clearErr("fpb-s2err");
    return true;
  }

  function initDetailsWatch() {
    const grid = byId("fpb-detailsGrid");
    if (!grid) return;
    let timer = null;
    const onEdit = (e) => {
      const el = e.target.closest("[data-fpb-cf]");
      if (!el) return;
      if (e.isTrusted) userTouched = true;
      if (el.getAttribute("aria-invalid") === "true") clearInvalid(el);
      clearTimeout(timer);
      timer = setTimeout(saveProgress, 300);
    };
    grid.addEventListener("input", onEdit);
    grid.addEventListener("change", onEdit);
  }

  /* -------------------------------------------------
     Step navigation
  ------------------------------------------------- */
  function onPayStep() {
    const el = byId("fpb-s" + PAY_STEP);
    return !!(el && el.classList.contains("fpb-act"));
  }

  function updateStepIndicator(target) {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
      const sp = byId("fpb-sp" + i);
      if (!sp) continue;
      sp.classList.remove("fpb-active", "fpb-done");
      if (i === target) sp.classList.add("fpb-active");
      else if (i < target) sp.classList.add("fpb-done");

      if (i === target) sp.setAttribute("aria-current", "step");
      else sp.removeAttribute("aria-current");

      // Completed steps are reachable by keyboard too.
      if (i < target && !bookingLocked) {
        sp.setAttribute("role", "button");
        sp.setAttribute("tabindex", "0");
        sp.setAttribute(
          "aria-label",
          fill(t("stepGoBack", "Go back to step {n}: {label}"), {
            n: i,
            label: sp.getAttribute("data-label") || sp.textContent.trim(),
          }),
        );
      } else {
        sp.removeAttribute("role");
        sp.removeAttribute("tabindex");
        sp.removeAttribute("aria-label");
      }
    }
  }

  function bkGo(step, opts) {
    opts = opts || {};
    const target = Math.max(1, Math.min(TOTAL_STEPS, parseInt(step, 10) || 1));
    document
      .querySelectorAll(".fpb-wrap .fpb-step")
      .forEach((el) => el.classList.remove("fpb-act"));
    updateStepIndicator(target);
    currentStep = target;
    const el = byId("fpb-s" + target);
    if (el) {
      el.classList.add("fpb-act");
      if (opts.focus !== false) focusStep(el);
    }
    saveProgress();
  }

  // Move focus to the new step's heading; scroll only when the form's top
  // is out of view, so short hops don't jump the page.
  function focusStep(stepEl) {
    const card = stepEl.closest(".fpb-card") || stepEl;
    const top = card.getBoundingClientRect().top;
    if (top < 0 || top > window.innerHeight * 0.6) {
      stepEl.scrollIntoView({ behavior: scrollBehavior(), block: "start" });
    }
    const h = stepEl.querySelector(".fpb-title");
    if (h) {
      if (!h.hasAttribute("tabindex")) h.setAttribute("tabindex", "-1");
      try {
        h.focus({ preventScroll: true });
      } catch (_e) {
        h.focus();
      }
    }
  }

  // Completed steps in the indicator are clickable (and keyboard-operable)
  // to go back.
  function initStepIndicator() {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
      const sp = byId("fpb-sp" + i);
      if (!sp) continue;
      const go = () => {
        if (bookingLocked) return;
        if (sp.classList.contains("fpb-done")) bkGo(i);
      };
      sp.addEventListener("click", go);
      sp.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          go();
        }
      });
    }
    updateStepIndicator(1);
  }

  // Step 1 — Package. The date (and start time) comes from the sidebar
  // calendar, so both are required before moving on to the Details step.
  function s1Next() {
    if (!selectedPkg) {
      showErr("fpb-s1err", t("errSelectPackage", "Please select a package."));
      return;
    }
    const missing = selectionProblem();
    if (missing) {
      showErr("fpb-s1err", missing);
      flagCalendar(missing);
      return;
    }
    // Browsing is open to everyone; booking needs an account when the
    // studio requires one.
    if (D.loginRequired) {
      showLoginPrompt();
      return;
    }
    clearErr("fpb-s1err");
    collectAddons();
    renderPartialPaymentOption();
    renderQuote(currentQuote());
    // Warm the gateway list while the customer fills in their details, so
    // the payment step has it ready without delaying the initial page load.
    prefetchGateways();
    bkGo(2);
  }

  // Step 2 — Details → Contract (if enabled) or straight to Payment.
  function s2Next() {
    if (!validateDetails()) {
      return;
    }
    collectAddons();
    saveProgress();
    if (contractEnabled) {
      bkGo(3);
      return;
    }
    populatePaymentStep();
    bkGo(3);
  }

  // Step 3 — Contract. The terms must be accepted (and signed, when the
  // studio asks for a signature) before the payment step is built — in
  // direct mode arriving there already places the order.
  function s3Next() {
    const box = byId("fpb-contractAccept");
    const sig = byId("fpb-contractSignature");
    if (!box || !box.checked) {
      showErr(
        "fpb-s3err",
        D.contractRequiredMsg || "Please accept the Terms & Conditions to continue.",
      );
      if (box) box.focus();
      return;
    }
    if (signatureRequired && contractSignatureLength() < 2) {
      showErr("fpb-s3err", t("signatureRequired", "Please type your full name to sign."));
      if (sig) {
        markInvalid(sig, "fpb-s3err");
        sig.focus();
      }
      return;
    }
    clearErr("fpb-s3err");
    populatePaymentStep();
    bkGo(PAY_STEP);
  }

  function contractSignature() {
    const el = byId("fpb-contractSignature");
    return el ? String(el.value || "").trim() : "";
  }

  function contractSignatureLength() {
    return Array.from(contractSignature()).length;
  }

  function contractAccepted() {
    if (!contractEnabled) return true;
    const box = byId("fpb-contractAccept");
    if (!box || !box.checked) return false;
    return !signatureRequired || contractSignatureLength() >= 2;
  }

  // Keep the Continue button in step with the acceptance checkbox (and the
  // typed signature), so the requirement reads as a state rather than only
  // as an error after a click.
  function initContractStep() {
    if (!contractEnabled) return;
    const box = byId("fpb-contractAccept");
    const btn = byId("fpb-s3NextBtn");
    const sig = byId("fpb-contractSignature");
    if (!box || !btn) return;
    const sync = () => {
      const ok = contractAccepted();
      btn.disabled = !ok;
      btn.title = ok
        ? ""
        : signatureRequired
          ? t("acceptAndSign", "Accept the terms and type your full name to continue")
          : t("acceptTerms", "Accept the terms to continue");
      if (ok) clearErr("fpb-s3err");
      if (sig && contractSignatureLength() >= 2) clearInvalidAny(sig);
    };
    box.addEventListener("change", sync);
    if (sig) sig.addEventListener("input", sync);
    sync();
  }

  function clearInvalidAny(el) {
    el.removeAttribute("aria-invalid");
    if (el.id === "fpb-contractSignature") {
      el.setAttribute("aria-describedby", "fpb-contractSignHint");
    }
  }

  /* -------------------------------------------------
     Log-in prompt (bookings that need an account)
  ------------------------------------------------- */
  // Return here after logging in, with the chosen package in the URL (the
  // rest of the selection comes back from sessionStorage).
  function returnUrl() {
    try {
      const u = new URL(window.location.href);
      u.hash = "";
      if (selectedPkg && !document.querySelector(".fpb-wrap[data-package]")) {
        u.searchParams.set("package", selectedPkg.slug || String(selectedPkg.id));
      }
      return u.toString();
    } catch (_e) {
      return window.location.href;
    }
  }

  function updateLoginLinks() {
    const login = D.login || {};
    const param = login.param || "redirect";
    document.querySelectorAll("[data-fpb-login]").forEach((a) => {
      const base =
        a.getAttribute("data-fpb-login") === "register"
          ? login.registerUrl
          : login.url;
      if (!base) return;
      a.href =
        base +
        (base.indexOf("?") === -1 ? "?" : "&") +
        param +
        "=" +
        encodeURIComponent(returnUrl());
    });
  }

  function showLoginPrompt(msg) {
    const text =
      msg ||
      t("loginRequired", "Please log in or create an account to continue with your booking.");
    showErr(stepErrId(), text);
    saveProgress();
    const card = byId("fpb-loginCard");
    if (!card) return;
    card.hidden = false;
    updateLoginLinks();
    attention(card);
  }

  /* -------------------------------------------------
     Server error codes → what the customer sees
  ------------------------------------------------- */
  function stepErrId() {
    if (currentStep === 1) return "fpb-s1err";
    if (currentStep === 2) return "fpb-s2err";
    if (contractEnabled && currentStep === 3) return "fpb-s3err";
    return "fpb-payErr";
  }

  function hideEmbed() {
    const box = byId("fpb-embedPay");
    if (box) box.style.display = "none";
  }

  function setCheckoutMsg(text, isErr) {
    const msg = byId("fpb-checkoutMsg");
    if (!msg) return;
    msg.textContent = text || "";
    msg.className = "fpb-checkout-msg" + (isErr ? " fpb-err" : "");
  }

  // Returns true when the error was dealt with (no generic fallback needed).
  function handleServerError(data) {
    const code = data && data.code ? String(data.code) : "";
    const msg = data && data.message ? String(data.message) : "";
    if (!code) return false;

    // Taken meanwhile: mark it, clear it, ask for another date/time.
    if (code === "snapbook_date_taken" || code === "snapbook_slot_taken" || code === "snapbook_slot_invalid" || code === "snapbook_slot_required") {
      if (code === "snapbook_date_taken") markDateTaken("booked");
      else if (code === "snapbook_slot_taken") markTimeTaken();
      else {
        chosenTime = "";
        afterSelectionCleared();
      }
      hideEmbed();
      if (onPayStep()) setCheckoutMsg(msg, true);
      else showErr(stepErrId(), msg);
      flagCalendar(msg);
      return true;
    }

    // A booking-window rule (past, notice, window, closed weekday): back to
    // the calendar.
    if (code.indexOf("snapbook_date") === 0) {
      if (code !== "snapbook_date") markDateTaken("closed");
      hideEmbed();
      setCheckoutMsg("", false);
      bkGo(1, { focus: false });
      showErr("fpb-s1err", msg);
      flagCalendar(msg);
      return true;
    }

    if (code === "snapbook_contract_changed") {
      hideEmbed();
      setCheckoutMsg("", false);
      const el = byId(stepErrId());
      if (el) {
        el.innerHTML =
          escHtml(msg) +
          ' <button type="button" class="fpb-link-btn" data-fpb-reload="1">' +
          escHtml(t("refreshPage", "Refresh page")) +
          "</button>";
        const btn = el.querySelector("[data-fpb-reload]");
        if (btn) {
          btn.addEventListener("click", () => {
            saveProgress();
            window.location.reload();
          });
        }
      }
      return true;
    }

    if ((code === "snapbook_contract_required" || code === "snapbook_signature_required") && contractEnabled) {
      hideEmbed();
      setCheckoutMsg("", false);
      bkGo(3);
      showErr("fpb-s3err", msg);
      return true;
    }

    if (code === "snapbook_login_required") {
      D.loginRequired = true;
      hideEmbed();
      setCheckoutMsg("", false);
      showLoginPrompt(msg);
      return true;
    }

    if (code === "snapbook_coupon_invalid") {
      couponCode = "";
      showPromoMsg(msg, true);
      renderQuote(currentQuote());
      schedulePreview(0);
      if (onPayStep() && D.hasWC && checkoutMode === "direct") {
        autoLoadPayment();
      }
      return true;
    }

    if (code === "snapbook_package" || code === "snapbook_addon") {
      hideEmbed();
      setCheckoutMsg("", false);
      bkGo(1);
      showErr("fpb-s1err", msg);
      return true;
    }

    return false;
  }

  /* -------------------------------------------------
     Promo code
  ------------------------------------------------- */
  function initPromo() {
    if (!D.couponsEnabled) return;
    const toggle = byId("fpb-promoToggle");
    const form = byId("fpb-promoForm");
    const input = byId("fpb-promoInput");
    const apply = byId("fpb-promoApply");
    const remove = byId("fpb-promoRemove");
    if (!toggle || !form || !input || !apply) return;

    toggle.addEventListener("click", () => {
      const open = form.hidden;
      form.hidden = !open;
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
      if (open) input.focus();
    });
    apply.addEventListener("click", applyCoupon);
    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") {
        e.preventDefault();
        applyCoupon();
      }
    });
    input.addEventListener("input", () => {
      input.removeAttribute("aria-invalid");
      showPromoMsg("", false);
    });
    if (remove) remove.addEventListener("click", removeCoupon);
  }

  function showPromoMsg(text, isErr) {
    const el = byId("fpb-promoMsg");
    if (!el) return;
    el.textContent = text || "";
    el.classList.toggle("fpb-ok", !!text && !isErr);
    const input = byId("fpb-promoInput");
    if (input) {
      if (text && isErr) {
        input.setAttribute("aria-invalid", "true");
        input.setAttribute("aria-describedby", "fpb-promoMsg");
      } else {
        input.removeAttribute("aria-invalid");
      }
    }
  }

  function renderPromoUi() {
    const toggle = byId("fpb-promoToggle");
    const form = byId("fpb-promoForm");
    if (!toggle || !form) return;
    // One code at a time: while one is applied, only "Remove" is offered.
    toggle.hidden = !!couponCode;
    if (couponCode) {
      form.hidden = true;
      toggle.setAttribute("aria-expanded", "false");
    }
  }

  function applyCoupon() {
    if (!D.couponsEnabled || !selectedPkg) return;
    const input = byId("fpb-promoInput");
    const btn = byId("fpb-promoApply");
    if (!input || !btn) return;
    const code = String(input.value || "").trim();
    if (!code) {
      showPromoMsg(t("promoEmpty", "Please enter a promo code."), true);
      input.focus();
      return;
    }
    showPromoMsg("", false);
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = t("promoChecking", "Checking…");
    clearTimeout(previewTimer);

    runPreview(code)
      .then((res) => {
        if (!res || !res.success || !res.data) {
          showPromoMsg(
            (res && res.data && res.data.message) ||
              t("somethingWrong", "Something went wrong. Please try again."),
            true,
          );
          return;
        }
        if (res.data.couponError) {
          // Priced without the code — the total stays as it was.
          showPromoMsg(String(res.data.couponError), true);
          input.focus();
          return;
        }
        couponCode = String(res.data.couponCode || code);
        input.value = "";
        showPromoMsg(t("promoApplied", "Promo code applied."), false);
        renderQuote(currentQuote());
        if (!(serverQuote && serverQuote.key === selectionBase() + "|" + couponCode)) {
          schedulePreview(0);
        }
        // The code is part of the order: replace the auto-placed one.
        if (onPayStep() && D.hasWC && checkoutMode === "direct") {
          autoLoadPayment();
        }
      })
      .catch(() => {
        showPromoMsg(t("networkError", "Network error. Check your connection and try again."), true);
      })
      .then(() => {
        btn.disabled = false;
        btn.textContent = orig;
      });
  }

  function removeCoupon() {
    if (!couponCode) return;
    couponCode = "";
    showPromoMsg(t("promoRemoved", "Promo code removed."), false);
    renderQuote(currentQuote());
    schedulePreview(0);
    const toggle = byId("fpb-promoToggle");
    if (toggle) toggle.focus();
    if (onPayStep() && D.hasWC && checkoutMode === "direct") {
      autoLoadPayment();
    }
  }

  /* -------------------------------------------------
     Payment step
  ------------------------------------------------- */
  function updateSummary() {
    if (!selectedPkg) return;
    collectAddons();
    const activeSess = sessions.find(
      (s) => parseInt(s.id, 10) === activeSessionId,
    );

    // Emoji values arrive HTML-encoded (wp_encode_emoji), so these rows are
    // built as HTML with the text parts escaped — never plain textContent.
    setHtml(
      "fpb-sum-session",
      activeSess
        ? (activeSess.emoji ? iconHtml(activeSess.emoji) + " " : "") +
            escHtml(activeSess.name)
        : "—",
    );
    setHtml(
      "fpb-sum-pkg",
      escHtml(selectedPkg.name) +
        (selectedPkg.duration
          ? ' <span class="fpb-sum-dur">(' +
            escHtml(selectedPkg.duration) +
            ")</span>"
          : "") +
        " — " +
        escHtml(formatMoney(selectedPkg.price)),
    );
    setTxt("fpb-sum-date", formatDate(chosenDate, true));
    const timeRow = byId("fpb-sum-time-row");
    if (timeRow) timeRow.style.display = slotsOn() && chosenTime ? "" : "none";
    setTxt("fpb-sum-time", chosenTime ? formatTime(chosenTime) : "—");
    setHtml(
      "fpb-sum-addons",
      chosenAddons.length
        ? chosenAddons
            .map(
              (a) =>
                (a.emoji ? iconHtml(a.emoji) + " " : "") +
                escHtml(a.name) +
                ' <span class="fpb-sum-addon-price">(+' +
                escHtml(formatMoney(a.price)) +
                ")</span>",
            )
            .join(", ")
        : escHtml(t("none", "None")),
    );
    renderQuote(currentQuote());
    schedulePreview(0);
  }

  function populatePaymentStep() {
    if (!selectedPkg) return;
    updateSummary();
    if (D.hasWC && checkoutMode === "direct") {
      // The WooCommerce payment section loads automatically on arrival —
      // no duplicate method list and no "place booking" button click.
      autoLoadPayment();
      return;
    }
    renderPaymentGateways();
    // Classic layout on (re)entry — the embedded payment section only
    // appears after "Place Booking & Pay" is clicked.
    hideEmbed();
    const btn = byId("fpb-checkoutBtn");
    if (btn) {
      btn.disabled = false;
      btn.style.display = "";
    }
  }

  /* -------------------------------------------------
     Direct mode — create/refresh the pending order as
     soon as the customer arrives on the payment step and
     embed the WooCommerce payment section, so the native
     gateway list (PayPal buttons, card fields, Pay
     button) shows without any extra click.
  ------------------------------------------------- */
  function autoLoadPayment() {
    if (bookingLocked) return;
    const btn = byId("fpb-checkoutBtn");
    if (btn) btn.style.display = "none";
    const gatewayBox = byId("fpb-gatewayBox");
    if (gatewayBox) gatewayBox.style.display = "none";
    clearErr("fpb-payErr");

    // No date/time (e.g. it was just taken): nothing to order yet.
    const missing = selectionProblem();
    if (missing) {
      hideEmbed();
      setCheckoutMsg(missing, true);
      flagCalendar(missing);
      return;
    }

    // One order request at a time; a change made meanwhile re-runs this
    // once the current one settles (it then supersedes that order).
    if (placeInFlight) {
      placeQueued = true;
      return;
    }

    const payload = buildOrderPayload("");
    const snapshot = JSON.stringify(payload);

    // Booking unchanged since its order was created — just show it again
    // instead of superseding the order with an identical one.
    if (
      embedOrder &&
      embedOrder.snapshot === snapshot &&
      byId("fpb-embedPayFrame")
    ) {
      applyEmbedLayout();
      setCheckoutMsg("", false);
      return;
    }

    const msg = byId("fpb-checkoutMsg");
    if (msg) {
      msg.textContent = t("loadingPayment", "Loading payment options…");
      msg.className = "fpb-checkout-msg fpb-is-loading-pay";
    }

    if (embedOrder) {
      // The booking was edited — supersede the previous pending order, and
      // take its payment form away meanwhile so it can't be paid by mistake.
      payload.previous_order_id = embedOrder.id;
      payload.previous_order_key = embedOrder.key;
      hideEmbed();
    }

    placeInFlight = true;
    post("snapbook_place_order", payload)
      .then((r) => {
        const d = (r && r.data) || {};
        if (r && r.success && d.order_id) {
          embedOrder = {
            id: d.order_id,
            key: d.order_key || "",
            snapshot: snapshot,
          };
        }
        if (placeQueued) return; // superseded before it was shown
        if (r && r.success && d.embed_url) {
          setCheckoutMsg("", false);
          showEmbeddedPayment(d, true);
        } else if (r && r.success && d.redirect_url) {
          window.location.href = d.redirect_url;
        } else if (r && r.success && d.order_id && d.payment_processed) {
          // Nothing to pay here: a free booking, or a method that settles
          // offline (bank transfer, cheque, cash).
          setCheckoutMsg("", false);
          showBookingConfirmation(d);
        } else if (!(r && r.success) && handleServerError(d)) {
          /* shown by handleServerError */
        } else {
          paymentAutoLoadFailed(
            d.message ||
              t("paymentLoadFailed", "Could not load the payment options. Please try again."),
          );
        }
      })
      .catch(() => {
        if (placeQueued) return;
        paymentAutoLoadFailed(
          t("networkError", "Network error. Check your connection and try again."),
        );
      })
      .then(() => {
        placeInFlight = false;
        if (placeQueued) {
          placeQueued = false;
          if (onPayStep()) autoLoadPayment();
        }
      });
  }

  // Fall back to the manual method list + button so the customer is
  // never stuck on the payment step if the automatic order creation fails.
  function paymentAutoLoadFailed(text) {
    // An order for an earlier version of this booking may still be embedded.
    hideEmbed();
    setCheckoutMsg(text, true);
    renderPaymentGateways();
    const btn = byId("fpb-checkoutBtn");
    if (btn) {
      btn.disabled = false;
      btn.style.display = "";
    }
  }

  function getSelectedGateway() {
    const checked = document.querySelector('input[name="fpb-gateway"]:checked');
    return checked ? checked.value : "";
  }

  function renderPaymentGateways(loadFailed) {
    const wrap = byId("fpb-gatewayList");
    const box = byId("fpb-gatewayBox");
    if (!wrap || !box) return;

    if (!D.hasWC) {
      box.style.display = "none";
      return;
    }

    box.style.display = "";

    const note = (text) =>
      '<p class="fpb-gateway-loading">' + escHtml(text) + "</p>";

    if (loadFailed) {
      wrap.innerHTML = note(
        t(
          "gatewaysLoadFailed",
          "Could not load payment methods. You can still continue — payment options will be shown on the payment page.",
        ),
      );
      return;
    }

    // Gateways load lazily (see prefetchGateways). While the request is in
    // flight, show the loading note and paint the list once it settles.
    if (!gatewaysLoaded) {
      wrap.innerHTML = note(t("gatewaysLoading", "Loading payment methods…"));
      prefetchGateways().then(() => renderPaymentGateways(loadFailed));
      return;
    }

    if (!paymentGateways.length) {
      wrap.innerHTML = note(
        t(
          "noGateways",
          "No payment methods are enabled in WooCommerce yet. Enable one under WooCommerce → Settings → Payments.",
        ),
      );
      return;
    }

    // Selectable in direct mode; informational in redirect mode (the gateway
    // is picked again on the WooCommerce checkout page).
    const selectable = checkoutMode !== "redirect";

    // Same markup WooCommerce prints on its checkout page
    // (ul.wc_payment_methods > li.wc_payment_method + div.payment_box), so
    // gateway icons and descriptions render exactly like the native checkout.
    wrap.innerHTML =
      '<ul class="fpb-wc-methods wc_payment_methods payment_methods methods">' +
      paymentGateways
        .map((gateway, i) => {
          const id = escHtml(gateway.id);
          const icon = gateway.icon || "";
          const desc = gateway.description
            ? "<p>" + gateway.description + "</p>"
            : "";
          // Gateways with their own secure payment fields (cards, PayPal
          // buttons) can only render them on the WooCommerce payment page,
          // so tell the customer where the card form will appear.
          const payNote =
            selectable && gateway.needs_payment_page
              ? '<p class="fpb-pay-next-note">' +
                escHtml(
                  t(
                    "payNextNote",
                    "The secure payment form will open below once you place the booking.",
                  ),
                ) +
                "</p>"
              : "";
          const descBox =
            desc || payNote
              ? '<div class="payment_box payment_method_' +
                id +
                '"' +
                (selectable && i !== 0 ? ' style="display:none"' : "") +
                ">" +
                desc +
                payNote +
                "</div>"
              : "";
          const input = selectable
            ? '<input type="radio" class="input-radio" name="fpb-gateway" id="fpb-gw-' +
              id +
              '" value="' +
              id +
              '"' +
              (i === 0 ? " checked" : "") +
              ">"
            : "";
          return (
            '<li class="fpb-wc-method wc_payment_method payment_method_' +
            id +
            '">' +
            input +
            "<label" +
            (selectable ? ' for="fpb-gw-' + id + '"' : "") +
            ">" +
            gateway.title +
            icon +
            "</label>" +
            descBox +
            "</li>"
          );
        })
        .join("") +
      "</ul>";

    if (selectable) {
      // WooCommerce checkout behavior: only the selected method's
      // description box is open.
      wrap.querySelectorAll('input[name="fpb-gateway"]').forEach((input) => {
        input.addEventListener("change", () => {
          wrap.querySelectorAll(".payment_box").forEach((b) => {
            b.style.display = "none";
          });
          const li = input.closest("li");
          const own = li ? li.querySelector(".payment_box") : null;
          if (own) own.style.display = "";
        });
      });
    }
  }

  function buildOrderPayload(paymentMethod) {
    collectAddons();
    const addonsTotal = chosenAddons.reduce(
      (s, a) => s + parseFloat(a.price || 0),
      0,
    );
    const session = sessions.find(
      (s) => parseInt(s.id, 10) === activeSessionId,
    );
    const payload = {
      session_type: session ? session.name : "",
      package_name: selectedPkg ? selectedPkg.name : "",
      package_id: selectedPkg ? selectedPkg.id : 0,
      addon_ids: addonIdsCsv(),
      addons_label: chosenAddons.length
        ? chosenAddons.map((a) => a.name).join(", ")
        : "",
      addons_total: addonsTotal,
      total_raw:
        parseFloat(selectedPkg ? selectedPkg.price || 0 : 0) + addonsTotal,
      use_deposit: useDepositFlag(),
      session_date: chosenDate || "",
      session_time: slotsOn() ? chosenTime : "",
      hold_token: holdToken(),
      coupon_code: couponCode,
      contract_accepted: contractAccepted() ? 1 : 0,
      contract_version: contractInfo.version || "",
      contract_signature: signatureRequired ? contractSignature() : "",
      payment_method: paymentMethod || "",
    };
    Object.entries(collectDetails()).forEach(([k, v]) => {
      payload["details[" + k + "]"] = v;
    });
    return payload;
  }

  /* -------------------------------------------------
     Place order / proceed to checkout
  ------------------------------------------------- */
  function proceedToCheckout() {
    const btn = byId("fpb-checkoutBtn");
    const msg = byId("fpb-checkoutMsg");
    if (!selectedPkg) {
      setCheckoutMsg(
        t("noPackageSelected", "No package selected. Please go back and choose a package."),
        true,
      );
      return;
    }
    const missing = selectionProblem();
    if (missing) {
      setCheckoutMsg(missing, true);
      flagCalendar(missing);
      return;
    }
    // Safety net for the classic/redirect path — the contract step already
    // gates the way in, but the terms must hold for every route to checkout.
    if (!contractAccepted()) {
      bkGo(3);
      showErr(
        "fpb-s3err",
        signatureRequired && byId("fpb-contractAccept") && byId("fpb-contractAccept").checked
          ? t("signatureRequired", "Please type your full name to sign.")
          : D.contractRequiredMsg || "Please accept the Terms & Conditions to continue.",
      );
      return;
    }
    if (!btn || !msg) return;
    if (!btn.dataset.orig) btn.dataset.orig = btn.textContent;
    btn.disabled = true;
    btn.classList.add("fpb-is-loading");
    btn.textContent = t("pleaseWait", "Please wait…");
    setCheckoutMsg(t("preparing", "Preparing your booking…"), false);
    clearErr("fpb-payErr");

    function restoreBtn() {
      btn.disabled = false;
      btn.classList.remove("fpb-is-loading");
      btn.textContent = btn.dataset.orig;
    }
    function fail(r, fallback) {
      restoreBtn();
      const d = (r && r.data) || {};
      if (handleServerError(d)) {
        setCheckoutMsg("", false);
        return;
      }
      setCheckoutMsg(d.message || fallback, true);
    }
    const netFail = () => {
      restoreBtn();
      setCheckoutMsg(
        t("networkError", "Network error. Check your connection and try again."),
        true,
      );
    };

    collectAddons();
    const addonsTotal = chosenAddons.reduce(
      (s, a) => s + parseFloat(a.price || 0),
      0,
    );
    const total = parseFloat(selectedPkg.price || 0) + addonsTotal;
    const session = sessions.find(
      (s) => parseInt(s.id, 10) === activeSessionId,
    );
    const addonsLabel = chosenAddons.length
      ? chosenAddons.map((a) => a.name).join(", ")
      : "";
    const details = collectDetails();
    const sessionTime = slotsOn() ? chosenTime : details.event_time || "";

    if (D.hasWC && checkoutMode === "direct") {
      const payload = buildOrderPayload(getSelectedGateway());
      if (embedOrder) {
        payload.previous_order_id = embedOrder.id;
        payload.previous_order_key = embedOrder.key;
      }

      post("snapbook_place_order", payload)
        .then((r) => {
          const d = (r && r.data) || {};
          if (r && r.success && d.embed_url) {
            // Gateway renders its secure fields on the order-pay page —
            // embed that page right here so the customer never leaves.
            restoreBtn();
            embedOrder = {
              id: d.order_id,
              key: d.order_key || "",
              snapshot: null, // method-specific order — recreate after edits
            };
            showEmbeddedPayment(d);
          } else if (r && r.success && d.redirect_url) {
            // External processor — payment must finish there.
            window.location.href = d.redirect_url;
          } else if (r && r.success && d.order_id) {
            embedOrder = { id: d.order_id, key: d.order_key || "", snapshot: null };
            showBookingConfirmation(d);
          } else {
            fail(r, t("somethingWrong", "Something went wrong. Please try again."));
          }
        })
        .catch(netFail);
    } else if (D.hasWC) {
      // Classic mode: add to cart, then WooCommerce checkout page.
      post("snapbook_add_to_cart", {
        session_type: session ? session.name : "",
        package_name: selectedPkg.name,
        package_id: selectedPkg.id,
        addon_ids: addonIdsCsv(),
        addons_label: addonsLabel,
        addons_total: addonsTotal,
        total_raw: total,
        use_deposit: useDepositFlag(),
        session_date: chosenDate || "",
        session_time: sessionTime,
        hold_token: holdToken(),
        coupon_code: couponCode,
        contract_accepted: contractAccepted() ? 1 : 0,
        contract_version: contractInfo.version || "",
        contract_signature: signatureRequired ? contractSignature() : "",
        signer_name: signatureRequired ? contractSignature() : "",
        location_pref: details.hotel_place || "",
        notes: details.notes || "",
        client_name: (
          (details.first_name || "") +
          " " +
          (details.last_name || "")
        ).trim(),
        client_email: details.email || "",
        client_phone: details.phone || "",
        client_country: details.country || "",
        address_1: details.address_1 || "",
        city: details.city || "",
        postcode: details.postcode || "",
        participants: details.participants || "",
        room_number: details.room_number || "",
        stay_period: details.stay_period || "",
      })
        .then((r) => {
          if (r && r.success && r.data && r.data.checkout_url) {
            window.location.href = r.data.checkout_url;
          } else {
            fail(r, t("somethingWrong", "Something went wrong. Please try again."));
          }
        })
        .catch(netFail);
    } else {
      post("snapbook_submit", {
        name: (
          (details.first_name || "") +
          " " +
          (details.last_name || "")
        ).trim(),
        email: details.email || "",
        phone: details.phone || "",
        pkg: selectedPkg.name,
        total: formatMoney(total),
        date: chosenDate || "",
        time: sessionTime,
        location: details.hotel_place || "",
        notes: details.notes || "",
        signer: signatureRequired ? contractSignature() : "",
      })
        .then((r) => {
          if (r && r.success) {
            bookingLocked = true;
            clearProgress();
            byId("fpb-payWrap").style.display = "none";
            const suc = byId("fpb-sucWrap");
            if (suc) {
              suc.style.display = "block";
              suc.classList.add("fpb-show");
            }
            setTxt("fpb-sucEmail", details.email || "");
            const wa = byId("fpb-waLink");
            if (wa && D.whatsapp) {
              wa.href = "https://wa.me/" + String(D.whatsapp).replace(/\D/g, "");
            }
            const h = byId("fpb-sucTitle");
            if (h) h.focus({ preventScroll: true });
          } else {
            fail(r, t("genericError", "Error. Please try again."));
          }
        })
        .catch(netFail);
    }
  }

  /* -------------------------------------------------
     Embedded payment — load the WooCommerce order-pay
     page (chrome-less) inside the payment step so card
     fields / PayPal buttons render like on checkout.
  ------------------------------------------------- */
  function applyEmbedLayout() {
    // The embedded WooCommerce payment section replaces the duplicate
    // method list and pay button. Back stays available — editing the
    // booking supersedes the order with a fresh one.
    const gatewayBox = byId("fpb-gatewayBox");
    if (gatewayBox) gatewayBox.style.display = "none";
    const checkoutBtn = byId("fpb-checkoutBtn");
    if (checkoutBtn) checkoutBtn.style.display = "none";
    const box = byId("fpb-embedPay");
    if (box) box.style.display = "";
  }

  function showEmbeddedPayment(d, skipScroll) {
    const payWrap = byId("fpb-payWrap");
    if (!payWrap) {
      window.location.href = d.redirect_url || d.pay_url;
      return;
    }

    applyEmbedLayout();
    setCheckoutMsg("", false);

    let box = byId("fpb-embedPay");
    if (!box) {
      box = document.createElement("div");
      box.id = "fpb-embedPay";
      box.className = "fpb-embed-pay";
      box.innerHTML =
        '<div class="fpb-gateway-title">' +
        '<span class="dashicons dashicons-lock" aria-hidden="true"></span>' +
        escHtml(t("securePayment", "Secure Payment")) +
        "</div>" +
        '<div class="fpb-embed-pay-loading" aria-hidden="true">' +
        '<span class="fpb-embed-spinner"></span>' +
        '<span id="fpb-embedPayLoadingText">' +
        escHtml(t("loadingSecurePay", "Loading secure payment…")) +
        "</span></div>" +
        '<iframe id="fpb-embedPayFrame" title="' +
        escHtml(t("securePaymentFrame", "Secure payment")) +
        '" allow="payment"></iframe>' +
        '<p class="fpb-embed-pay-alt">' +
        escHtml(t("havingTrouble", "Having trouble paying?")) +
        ' <a id="fpb-embedPayLink" href="#">' +
        escHtml(t("openPayPage", "Open the secure payment page")) +
        "</a>.</p>";
      payWrap.appendChild(box);

      const frame = byId("fpb-embedPayFrame");
      frame.addEventListener("load", () => {
        let href = "";
        try {
          href = frame.contentWindow.location.href;
        } catch (e) {
          href = ""; // cross-origin page (external gateway step)
        }

        if (href && href.indexOf("order-received") !== -1) {
          // Payment finished — go straight to the in-form success
          // message. The frame stays hidden behind the spinner, so the
          // themed order-details page never flashes inside the box.
          onEmbeddedPaymentComplete(href);
          return;
        }

        // Payment form (or a same-origin retry page, or an external page
        // that needs interaction) — reveal the frame.
        box.classList.remove("fpb-embed-loading");

        if (href) {
          try {
            syncEmbedHeight(frame);
            // The moment this page navigates away (Pay clicked, gateway
            // redirect), hide the frame again so no interim page shows.
            frame.contentWindow.addEventListener("pagehide", () => {
              setTxt(
                "fpb-embedPayLoadingText",
                t("processingPayment", "Processing your payment…"),
              );
              box.classList.add("fpb-embed-loading");
            });
          } catch (e) {
            // Frame navigated away already — ignore.
          }
        }
      });
    }

    const link = byId("fpb-embedPayLink");
    if (link) link.href = d.redirect_url || d.pay_url || "#";
    setTxt("fpb-embedPayLoadingText", t("loadingSecurePay", "Loading secure payment…"));
    box.classList.add("fpb-embed-loading");
    byId("fpb-embedPayFrame").src = d.embed_url;
    box.style.display = "";

    // The Back button belongs below the payment form.
    const nav = payWrap.querySelector(".fpb-nav");
    if (nav) payWrap.appendChild(nav);

    if (!skipScroll) {
      box.scrollIntoView({ behavior: scrollBehavior(), block: "start" });
    }
  }

  /* -------------------------------------------------
     Payment finished inside the embedded frame — show
     the in-form confirmation panel (success message)
     with the order's fresh status instead of leaving
     the booking form for the order-received page.
  ------------------------------------------------- */
  function onEmbeddedPaymentComplete(receivedUrl) {
    if (!embedOrder) {
      window.location.href = receivedUrl;
      return;
    }
    post("snapbook_order_confirmation", {
      order_id: embedOrder.id,
      order_key: embedOrder.key,
    })
      .then((r) => {
        if (r && r.success && r.data && r.data.order_id) {
          showBookingConfirmation(r.data);
        } else {
          window.location.href = receivedUrl;
        }
      })
      .catch(() => {
        window.location.href = receivedUrl;
      });
  }

  /* -------------------------------------------------
     Keep the payment iframe as tall as its content —
     no dead white space, and it grows when the card
     form expands. Same-origin, so we can measure it.
  ------------------------------------------------- */
  function syncEmbedHeight(frame) {
    try {
      const doc = frame.contentWindow.document;
      if (!doc || !doc.body) return;

      const apply = () => {
        try {
          const h = Math.ceil(doc.body.getBoundingClientRect().height) + 4;
          frame.style.height = Math.max(h, 260) + "px";
        } catch (e) {
          /* frame navigated away — stop adjusting */
        }
      };
      apply();

      if (frame.fpbResizeObserver) frame.fpbResizeObserver.disconnect();
      if (frame.fpbResizeTimer) clearInterval(frame.fpbResizeTimer);
      if (typeof ResizeObserver !== "undefined") {
        frame.fpbResizeObserver = new ResizeObserver(apply);
        frame.fpbResizeObserver.observe(doc.body);
      } else {
        frame.fpbResizeTimer = setInterval(apply, 800);
      }
    } catch (e) {
      /* cross-origin page in the frame — leave the CSS height */
    }
  }

  /* -------------------------------------------------
     In-place booking confirmation (no page change)
  ------------------------------------------------- */
  function showBookingConfirmation(d) {
    const wrap = byId("fpb-confirmWrap");
    if (!wrap) {
      // Older template without the confirmation panel — fall back to the pay page.
      if (d.pay_url) window.location.href = d.pay_url;
      return;
    }

    bookingLocked = true; // freeze the stepper — the order exists now
    updateStepIndicator(PAY_STEP);
    clearProgress();

    const payWrap = byId("fpb-payWrap");
    if (payWrap) payWrap.style.display = "none";

    // Placed but unpaid (bank transfer, cheque, cash on delivery): the
    // "received" wording plus the gateway's own instructions.
    const awaiting = !!d.awaiting_payment;
    const processed = !!d.payment_processed && !awaiting;

    // Titles and messages are editable in SnapBook → Settings → Checkout → Messages after booking.
    setTxt(
      "fpb-confirmTitle",
      processed
        ? D.confirmTitle || "Booking Confirmed!"
        : D.confirmPendingTitle || "Booking Received!",
    );
    const noteTemplate = processed
      ? D.confirmMsg ||
        "Thank you for your booking! A confirmation email has been sent to {email}."
      : D.confirmPendingMsg ||
        "Thank you for your booking! Complete the payment below to confirm your slot.";
    setTxt(
      "fpb-confirmNote",
      fill(noteTemplate, {
        email: d.client_email || t("yourEmail", "your email address"),
      }),
    );
    const instr = byId("fpb-confirmInstructions");
    if (instr) {
      if (awaiting && d.instructions_html) {
        // Gateway instructions (bank details), sanitized server-side.
        instr.innerHTML = d.instructions_html;
        instr.hidden = false;
      } else {
        instr.innerHTML = "";
        instr.hidden = true;
      }
    }
    setTxt("fpb-confirmOrder", "#" + (d.order_number || d.order_id));
    setTxt("fpb-confirmMethod", d.gateway_title || "—");
    setTxt("fpb-confirmAmount", formatMoney(d.due_now || 0));
    setTxt("fpb-confirmStatus", d.status_label || d.status || "—");

    const payBtn = byId("fpb-confirmPayBtn");
    if (payBtn) {
      if (!processed && !awaiting && d.pay_url) {
        payBtn.href = d.pay_url;
        payBtn.style.display = "";
      } else {
        payBtn.style.display = "none";
      }
    }
    const viewBtn = byId("fpb-confirmViewBtn");
    if (viewBtn) {
      if ((processed || awaiting) && d.received_url) {
        viewBtn.href = d.received_url;
        viewBtn.style.display = "";
      } else {
        viewBtn.style.display = "none";
      }
    }
    const waBtn = byId("fpb-confirmWaBtn");
    if (waBtn) {
      if (D.whatsapp) {
        waBtn.href = "https://wa.me/" + String(D.whatsapp).replace(/\D/g, "");
        waBtn.style.display = "";
      } else {
        waBtn.style.display = "none";
      }
    }

    // .suc is display:none by CSS class; the inline block + .show class
    // (fade-in) are both needed to actually reveal the panel.
    wrap.style.display = "block";
    wrap.classList.add("fpb-show");
    wrap.scrollIntoView({ behavior: scrollBehavior(), block: "start" });
    const h = byId("fpb-confirmTitle");
    if (h) {
      try {
        h.focus({ preventScroll: true });
      } catch (_e) {
        h.focus();
      }
    }
  }

  /* -------------------------------------------------
     Keep progress across reloads and the login round trip
     (sessionStorage: this tab only). Saved: package,
     add-ons, date, time, deposit choice, detail fields
     and the step (up to Details). Never the promo code.
  ------------------------------------------------- */
  const PROGRESS_KEY = "snapbook_progress:" + window.location.pathname;

  function saveProgress() {
    if (!progressReady || restoring || bookingLocked) return;
    const toggle = byId("fpb-partialToggle");
    const data = {
      v: 1,
      pkg: selectedPkg ? parseInt(selectedPkg.id, 10) : 0,
      addons: Array.from(
        document.querySelectorAll("#fpb-addonsGrid .fpb-ac:checked"),
      ).map((cb) => parseInt(cb.value, 10)),
      date: chosenDate || "",
      time: chosenTime || "",
      deposit:
        toggle && toggle.dataset.touched === "1" ? !!toggle.checked : null,
      details: collectDetails(),
      step: Math.min(currentStep, 2),
      ts: Date.now(),
    };
    try {
      window.sessionStorage.setItem(PROGRESS_KEY, JSON.stringify(data));
    } catch (_e) {
      /* storage full / disabled */
    }
  }

  function clearProgress() {
    try {
      window.sessionStorage.removeItem(PROGRESS_KEY);
    } catch (_e) {
      /* ignore */
    }
  }

  // After the catalog and availability load: put back what's still valid
  // (an inactive package or a now-unavailable date is skipped).
  function restoreProgress() {
    if (progressReady) return;
    let data = null;
    try {
      data = JSON.parse(window.sessionStorage.getItem(PROGRESS_KEY) || "null");
    } catch (_e) {
      data = null;
    }
    if (!data || data.v !== 1 || userTouched || bookingLocked) {
      progressReady = true;
      return;
    }

    restoring = true;
    try {
      const pkg = packages.find(
        (p) => parseInt(p.id, 10) === parseInt(data.pkg, 10),
      );
      const sessionOk =
        pkg &&
        sessions.some((s) => parseInt(s.id, 10) === parseInt(pkg.session_id, 10));
      if (pkg && sessionOk) {
        if (activeSessionId !== parseInt(pkg.session_id, 10)) {
          const tab = document.querySelector(
            '#fpb-typeTabs .fpb-stype-btn[data-id="' + parseInt(pkg.session_id, 10) + '"]',
          );
          if (tab) activateSession(tab);
        }
        const card = document.querySelector(
          '#fpb-pkgGrid .fpb-pkg[data-id="' + parseInt(pkg.id, 10) + '"]',
        );
        if (card) selectCard(card);
        (Array.isArray(data.addons) ? data.addons : []).forEach((id) => {
          const cb = document.querySelector(
            '#fpb-addonsGrid .fpb-ac[value="' + parseInt(id, 10) + '"]',
          );
          if (cb) cb.checked = true;
        });
      }

      if (data.date && /^\d{4}-\d{2}-\d{2}$/.test(data.date) && dateStatus(data.date) === "") {
        chosenDate = data.date;
        if (data.time && slotsOn() && freeTimes(data.date).indexOf(data.time) !== -1) {
          chosenTime = data.time;
        }
        startMonth();
        renderCalendar();
        renderSlots();
        updateSelDateText();
      }

      renderPartialPaymentOption();
      const toggle = byId("fpb-partialToggle");
      if (toggle && !toggle.disabled && typeof data.deposit === "boolean") {
        toggle.checked = data.deposit;
        toggle.dataset.touched = "1";
        usePartialPayment = data.deposit;
        setTxt("fpb-partialNote", partialNoteText(data.deposit));
      }

      if (data.details && typeof data.details === "object") {
        Object.entries(data.details).forEach(([k, v]) => {
          if (!/^[a-z0-9_]+$/i.test(k) || typeof v !== "string") return;
          const el = document.querySelector('[data-fpb-cf="' + k + '"]');
          if (el && !el.value) el.value = v;
        });
      }
    } finally {
      restoring = false;
      progressReady = true;
    }

    onSelectionChanged();
    if (
      parseInt(data.step, 10) >= 2 &&
      selectedPkg &&
      !selectionProblem() &&
      !D.loginRequired
    ) {
      collectAddons();
      prefetchGateways();
      bkGo(2, { focus: false });
    }
    saveProgress();
  }

  /* -------------------------------------------------
     DOM helpers
  ------------------------------------------------- */
  function byId(id) {
    return document.getElementById(id);
  }
  function setTxt(id, text) {
    const el = byId(id);
    if (el) el.textContent = text;
  }
  function setHtml(id, html) {
    const el = byId(id);
    if (el) el.innerHTML = html;
  }
  function setNote(id, text) {
    const el = byId(id);
    if (!el) return;
    el.textContent = text || "";
    el.hidden = !text;
  }
  function showErr(id, text) {
    const el = byId(id);
    if (el) {
      el.textContent = text;
      el.style.display = "";
    }
  }
  function clearErr(id) {
    const el = byId(id);
    if (el) el.textContent = "";
  }
  function escHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  // Icon value may be an emoji or an icon-font class such as
  // "fa-solid fa-camera" — mirrors snapbook_icon_html() on the server.
  function iconHtml(v) {
    v = String(v || "").trim();
    if (!v) return "";
    if (/^[a-z0-9 _-]+$/i.test(v) && /(^|\s)(fa-|dashicons)/.test(v)) {
      return '<i class="' + v + '" aria-hidden="true"></i>';
    }
    return v; // emoji arrives HTML-encoded (wp_encode_emoji)
  }

  /* -------------------------------------------------
     Public API
  ------------------------------------------------- */
  window.snapbook = {
    bkGo,
    s1Next,
    s2Next,
    s3Next,
    proceedToCheckout,
    formatMoney,
  };

  /* -------------------------------------------------
     Boot
  ------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", () => {
    if (!byId("fpb-s1")) return;
    ["fpb-s1err", "fpb-s2err", "fpb-s3err", "fpb-payErr"].forEach((id) => {
      const el = byId(id);
      if (el) el.setAttribute("role", "alert");
    });

    holdToken();
    init();
    initPartialPaymentOption();
    initStepIndicator();
    initContractStep();
    initPromo();
    initDetailsWatch();
    updateLoginLinks();

    // Restrict phone field to valid phone characters only
    const phoneInput = document.querySelector('[data-fpb-cf="phone"]');
    if (phoneInput) {
      phoneInput.addEventListener("input", () => {
        phoneInput.value = phoneInput.value.replace(/[^0-9+\-() ]/g, "");
      });
    }
  });
})();
