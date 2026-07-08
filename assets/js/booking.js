/* global fpbData */
(function () {
  "use strict";

  /* -------------------------------------------------
     State
  ------------------------------------------------- */
  let sessions = [];
  let packages = [];
  let addons = [];
  let bookedDates = {}; // "YYYY-MM-DD" => "booked"|"blocked"
  let paymentGateways = [];

  let activeSessionId = null;
  let selectedPkgId = null;
  let selectedPkg = null;
  let chosenAddons = [];
  let chosenDate = null;

  // ?package={slug-or-id} share link — resolved to a package row after the
  // booking data loads, applied once when the package grid first renders.
  // The param stays in the URL, so a refresh keeps the pre-selection.
  let preselectPkg = null;

  const cur = fpbData.currency || "e";
  const checkoutMode = fpbData.checkoutMode === "redirect" ? "redirect" : "direct";
  let partialPaymentEnabled = !!fpbData.partialPaymentEnabled;
  let partialBlockDays = parseInt(fpbData.partialBlockDays || 0, 10) || 0;
  let partialOptionLabel =
    fpbData.partialOptionLabel || "Book a slot to 50% Pay";
  let usePartialPayment = partialPaymentEnabled;
  let paymentPreview = null;
  let previewRequestSeq = 0;

  /* -------------------------------------------------
     AJAX
  ------------------------------------------------- */
  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", fpbData.nonce);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    return fetch(fpbData.ajaxUrl, { method: "POST", body: fd }).then((r) =>
      r.json(),
    );
  }

  /* -------------------------------------------------
     Shareable package deep-link (?package=slug-or-id)
  ------------------------------------------------- */
  function getUrlPackageParam() {
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
  // so anything unknown here is unavailable → show the step-2 notice.
  function resolvePreselectPackage() {
    const param = getUrlPackageParam();
    if (!param) return;

    const wanted = param.toLowerCase();
    const found =
      packages.find(
        (p) => String(p.slug || "").toLowerCase() === wanted,
      ) || packages.find((p) => String(p.id) === wanted);
    const sessionOk =
      found &&
      sessions.some(
        (s) => parseInt(s.id, 10) === parseInt(found.session_id, 10),
      );

    if (found && sessionOk) {
      preselectPkg = found;
    } else {
      const notice = document.getElementById("fpb-pkgNotice");
      if (notice) notice.style.display = "";
    }
  }

  /* -------------------------------------------------
     Init
  ------------------------------------------------- */
  function init() {
    const requests = [post("fpb_get_data", {}), post("fpb_get_dates", {})];
    if (fpbData.hasWC) {
      requests.push(post("fpb_get_payment_gateways", {}));
    }

    Promise.all(requests)
      .then((responses) => {
        const dataRes = responses[0];
        const datesRes = responses[1];
        const gatewaysRes = responses[2];
        if (dataRes.success) {
          sessions = dataRes.data.sessions || [];
          packages = dataRes.data.packages || [];
          addons = dataRes.data.addons || [];
          partialPaymentEnabled = !!dataRes.data.partialPaymentEnabled;
          partialBlockDays =
            parseInt(dataRes.data.partialBlockDays || 0, 10) || 0;
          partialOptionLabel =
            dataRes.data.partialOptionLabel || partialOptionLabel;
          if (!partialPaymentEnabled) {
            usePartialPayment = false;
          }
        }
        if (datesRes.success) {
          bookedDates = datesRes.data || {};
        }
        if (gatewaysRes && gatewaysRes.success) {
          paymentGateways = gatewaysRes.data.gateways || [];
        }
        resolvePreselectPackage();
        renderSessionTabs();
        initCalendar();
        updateStep1NextState();
        updateStep2NextState();
      })
      .catch(() => {
        const t = document.getElementById("fpb-typeTabs");
        if (t)
          t.innerHTML =
            '<p style="color:#c0392b;font-size:.85rem">Could not load booking data. Please refresh.</p>';
      });
  }

  /* -------------------------------------------------
     Session Dropdown
  ------------------------------------------------- */
  function renderSessionTabs() {
    const wrap = document.getElementById("fpb-typeTabs");
    if (!wrap) return;
    if (!sessions.length) {
      wrap.innerHTML =
        '<span class="fpb-stype-loading">No session types configured.</span>';
      return;
    }

    wrap.innerHTML = sessions
      .map(
        (s) =>
          '<button class="fpb-stype-btn" data-id="' +
          s.id +
          '" type="button">' +
          (s.emoji
            ? '<span class="fpb-stype-em">' + iconHtml(s.emoji) + "</span>"
            : "") +
          '<span class="fpb-stype-name">' +
          escHtml(s.name) +
          "</span>" +
          "</button>",
      )
      .join("");

    function activateBtn(btn) {
      wrap
        .querySelectorAll(".fpb-stype-btn")
        .forEach((b) => b.classList.remove("act"));
      btn.classList.add("act");
      activeSessionId = parseInt(btn.dataset.id, 10);
      selectedPkgId = null;
      selectedPkg = null;
      showAddons(false);
      renderPackages();
      updateStep2Price();
      updateStep2NextState();
    }

    wrap.querySelectorAll(".fpb-stype-btn").forEach((btn) => {
      btn.addEventListener("click", () => activateBtn(btn));
    });

    // Auto-select first — or the session type owning the ?package= link
    let startBtn = wrap.querySelector(".fpb-stype-btn");
    if (preselectPkg) {
      const target = wrap.querySelector(
        '.fpb-stype-btn[data-id="' +
          parseInt(preselectPkg.session_id, 10) +
          '"]',
      );
      if (target) startBtn = target;
    }
    if (startBtn) activateBtn(startBtn);
  }

  /* -------------------------------------------------
     Package Cards
  ------------------------------------------------- */
  function renderPackages() {
    const grid = document.getElementById("fpb-pkgGrid");
    if (!grid) return;
    const pkgs = packages.filter(
      (p) => parseInt(p.session_id, 10) === activeSessionId,
    );
    if (!pkgs.length) {
      grid.innerHTML =
        '<p class="fpb-no-pkgs">No packages for this session type yet.</p>';
      return;
    }
    grid.innerHTML = pkgs
      .map(
        (p) =>
          '<div class="fpc' +
          (p.featured == "1" ? " feat" : "") +
          '" data-id="' +
          p.id +
          '" role="button" tabindex="0">' +
          (p.featured == "1"
            ? '<span class="fpc-tag">&#9733; Popular</span>'
            : "") +
          '<div class="fpc-name">' +
          escHtml(p.name) +
          "</div>" +
          '<div class="fpc-price">' +
          cur +
          parseFloat(p.price || 0).toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
          }) +
          "</div>" +
          (p.duration
            ? '<div class="fpc-dur">' + escHtml(p.duration) + "</div>"
            : "") +
          // Description is rich text sanitized server-side (wp_kses_post)
          (p.description
            ? '<div class="fpc-desc">' + p.description + "</div>"
            : "") +
          "</div>",
      )
      .join("");
    grid.querySelectorAll(".fpc").forEach((card) => {
      function selectCard() {
        grid.querySelectorAll(".fpc").forEach((c) => c.classList.remove("sel"));
        card.classList.add("sel");
        selectedPkgId = parseInt(card.dataset.id, 10);
        selectedPkg =
          pkgs.find((p) => parseInt(p.id, 10) === selectedPkgId) || null;
        clearErr("fpb-s2err");
        renderAddons();
        updateStep2Price();
        updateStep2NextState();
      }
      card.addEventListener("click", selectCard);
      card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          selectCard();
        }
      });
    });

    // Apply the ?package= pre-selection once. Goes through the same click
    // path as a manual choice, so add-ons render visible but unchecked.
    if (
      preselectPkg &&
      parseInt(preselectPkg.session_id, 10) === activeSessionId
    ) {
      const card = grid.querySelector(
        '.fpc[data-id="' + parseInt(preselectPkg.id, 10) + '"]',
      );
      preselectPkg = null;
      if (card) card.click();
    }
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
    const grid = document.getElementById("fpb-addonsGrid");
    const wrap = document.getElementById("fpb-addonsWrap");
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
        const pkgOnly = addonPackageIds(a).length > 0;
        return (
          '<label class="fadd-card" for="fadd-' +
          a.id +
          '">' +
          '<input class="ac" type="checkbox" value="' +
          a.id +
          '" id="fadd-' +
          a.id +
          '">' +
          '<span class="fadd-em">' +
          (iconHtml(a.emoji) || "•") +
          "</span>" +
          '<span class="fadd-info">' +
          '<span class="fadd-name">' +
          escHtml(a.name) +
          "</span>" +
          // Description is rich text sanitized server-side (wp_kses_post)
          (a.description
            ? '<span class="fadd-desc">' + a.description + "</span>"
            : "") +
          (pkgOnly ? '<span class="fadd-badge">This package only</span>' : "") +
          "</span>" +
          '<span class="fadd-price">+' +
          cur +
          parseFloat(a.price || 0).toFixed(0) +
          "</span>" +
          "</label>"
        );
      })
      .join("");

    grid.querySelectorAll(".ac").forEach((cb) => {
      cb.addEventListener("change", updateStep2Price);
    });

    if (wrap) wrap.style.display = "";
  }

  function collectAddons() {
    chosenAddons = [];
    document.querySelectorAll("#fpb-addonsGrid .ac:checked").forEach((cb) => {
      const a = addons.find((x) => String(x.id) === cb.value);
      if (a) chosenAddons.push(a);
    });
  }

  /* -------------------------------------------------
     Step 2 — live price strip (Total / Pay now / Pay later)
  ------------------------------------------------- */
  function updateStep2Price() {
    const strip = document.getElementById("fpb-s2Price");
    if (!strip) return;

    if (!selectedPkg) {
      strip.style.display = "none";
      return;
    }

    const checked = [];
    document.querySelectorAll("#fpb-addonsGrid .ac:checked").forEach((cb) => {
      const a = addons.find((x) => String(x.id) === cb.value);
      if (a) checked.push(a);
    });
    const addonsTotal = checked.reduce(
      (s, a) => s + parseFloat(a.price || 0),
      0,
    );
    const total = parseFloat(selectedPkg.price || 0) + addonsTotal;
    const pct = getEffectiveDepositPct();
    const due = Math.round(total * pct) / 100;
    const later = Math.max(0, total - due);

    setTxt("fpb-s2Total", cur + total.toFixed(2));
    setTxt("fpb-s2DueLabel", pct === 50 ? "Pay now (50%)" : "Pay now");
    setTxt("fpb-s2Due", cur + due.toFixed(2));
    const laterCell = document.getElementById("fpb-s2LaterCell");
    if (laterCell) laterCell.style.display = later > 0.01 ? "" : "none";
    setTxt("fpb-s2Later", cur + later.toFixed(2));

    strip.style.display = "";
  }

  /* -------------------------------------------------
     Payment step summary
  ------------------------------------------------- */
  function formatDateHuman(ds) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(ds || "")) return ds || "—";
    const p = ds.split("-");
    const d = new Date(
      parseInt(p[0], 10),
      parseInt(p[1], 10) - 1,
      parseInt(p[2], 10),
    );
    return d.toLocaleDateString(undefined, {
      weekday: "long",
      year: "numeric",
      month: "long",
      day: "numeric",
    });
  }

  function updateSummary() {
    if (!selectedPkg) return;
    const addonsTotal = chosenAddons.reduce(
      (s, a) => s + parseFloat(a.price || 0),
      0,
    );
    const total = parseFloat(selectedPkg.price || 0) + addonsTotal;
    const effectiveDepPct = getEffectiveDepositPct();
    const dueNow = Math.round(total * effectiveDepPct) / 100;
    const balance = Math.max(0, total - dueNow);
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
        cur +
        parseFloat(selectedPkg.price || 0).toFixed(0),
    );
    setTxt("fpb-sum-date", formatDateHuman(chosenDate));
    setHtml(
      "fpb-sum-addons",
      chosenAddons.length
        ? chosenAddons
            .map(
              (a) =>
                (a.emoji ? iconHtml(a.emoji) + " " : "") +
                escHtml(a.name) +
                ' <span class="fpb-sum-addon-price">(+' +
                cur +
                parseFloat(a.price || 0).toFixed(0) +
                ")</span>",
            )
            .join(", ")
        : "None",
    );
    setTxt("fpb-sum-price", cur + total.toFixed(2));
    setTxt("fpb-sum-total", cur + dueNow.toFixed(2));
    setTxt(
      "fpb-sum-dep",
      effectiveDepPct === 50 ? "50% booking deposit" : "Full payment",
    );
    setBalanceDisplay(balance);

    refreshPaymentPreview(total);
  }

  function setBalanceDisplay(balance) {
    const row = document.getElementById("fpb-sum-balance-row");
    if (row) row.style.display = balance > 0.01 ? "" : "none";
    setTxt("fpb-sum-balance", cur + balance.toFixed(2));
  }

  function refreshPaymentPreview(total) {
    if (!fpbData.hasWC) return;

    const reqId = ++previewRequestSeq;
    post("fpb_preview_payment", {
      total_raw: total,
      session_date: chosenDate || "",
      use_deposit: usePartialPayment ? 1 : 0,
    })
      .then((res) => {
        if (!res || !res.success || reqId !== previewRequestSeq) return;
        paymentPreview = res.data || null;

        const dueToday = parseFloat(paymentPreview.dueToday || 0);
        const payPct = parseInt(paymentPreview.payPct || 100, 10);
        const balanceDue = parseFloat(paymentPreview.balanceDue || 0);

        setTxt("fpb-sum-total", cur + dueToday.toFixed(2));
        setTxt(
          "fpb-sum-dep",
          payPct === 50 ? "50% booking deposit" : "Full payment",
        );
        setBalanceDisplay(balanceDue);
      })
      .catch(() => {
        // Keep local preview as fallback when AJAX preview fails.
      });
  }

  function initPartialPaymentOption() {
    const toggle = document.getElementById("fpb-partialToggle");
    if (!toggle) return;

    toggle.addEventListener("change", () => {
      toggle.dataset.touched = "1";
      usePartialPayment = toggle.checked;
      const note = document.getElementById("fpb-partialNote");
      if (note) {
        note.textContent = toggle.checked
          ? "Pay 50% now, and settle the rest later."
          : "Switch off to pay full amount now.";
      }
      updateStep2Price();
      updateSummary();
    });
  }

  function getDaysUntilEvent(dateStr) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(dateStr || "")) return null;
    const parts = String(dateStr).split("-");
    const eventDate = new Date(
      parseInt(parts[0], 10),
      parseInt(parts[1], 10) - 1,
      parseInt(parts[2], 10),
      0,
      0,
      0,
      0,
    );
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    return Math.floor((eventDate.getTime() - today.getTime()) / 86400000);
  }

  function canUsePartialForSelectedDate() {
    if (!partialPaymentEnabled) return false;
    if (!chosenDate) return false;
    if (partialBlockDays <= 0) return true;

    const days = getDaysUntilEvent(chosenDate);
    if (days === null) return false;
    return days >= partialBlockDays;
  }

  function getEffectiveDepositPct() {
    if (!partialPaymentEnabled) return 100;
    if (!usePartialPayment) return 100;
    return canUsePartialForSelectedDate() ? 50 : 100;
  }

  function renderPartialPaymentOption() {
    const wrap = document.getElementById("fpb-partialWrap");
    const label = document.getElementById("fpb-partialLabel");
    const note = document.getElementById("fpb-partialNote");
    const toggle = document.getElementById("fpb-partialToggle");
    if (!wrap || !label || !note || !toggle) return;

    if (!partialPaymentEnabled) {
      wrap.style.display = "none";
      usePartialPayment = false;
      return;
    }

    wrap.style.display = "none";
    label.textContent = partialOptionLabel;

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
      note.textContent = toggle.checked
        ? "Pay 50% now, and settle the rest later."
        : "Switch off to pay full amount now.";
      return;
    }

    wrap.style.display = "none";
    toggle.checked = false;
    toggle.disabled = true;
    usePartialPayment = false;
    note.textContent = "";
  }

  function showAddons(show) {
    // show/hide is now managed by renderAddons; only reset checkboxes on hide
    if (!show) {
      const w = document.getElementById("fpb-addonsWrap");
      if (w) w.style.display = "none";
      document
        .querySelectorAll("#fpb-addonsGrid input")
        .forEach((cb) => (cb.checked = false));
      chosenAddons = [];
    }
  }

  /* -------------------------------------------------
     Calendar
  ------------------------------------------------- */
  let calDate = new Date();
  calDate.setDate(1);

  function initCalendar() {
    document.getElementById("fpb-calPrev")?.addEventListener("click", () => {
      calDate.setMonth(calDate.getMonth() - 1);
      renderCalendar();
    });
    document.getElementById("fpb-calNext")?.addEventListener("click", () => {
      calDate.setMonth(calDate.getMonth() + 1);
      renderCalendar();
    });
    renderCalendar();
  }

  function renderCalendar() {
    const grid = document.getElementById("fpb-calGrid");
    const ml = document.getElementById("fpb-calMonth");
    if (!grid || !ml) return;
    const yr = calDate.getFullYear(),
      mo = calDate.getMonth();
    const days = new Date(yr, mo + 1, 0).getDate(),
      startDow = new Date(yr, mo, 1).getDay();
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const monthNames = [
      "January",
      "February",
      "March",
      "April",
      "May",
      "June",
      "July",
      "August",
      "September",
      "October",
      "November",
      "December",
    ];
    ml.textContent = monthNames[mo] + " " + yr;
    let html = "";
    for (let i = 0; i < startDow; i++) html += "<span></span>";
    for (let d = 1; d <= days; d++) {
      const ds =
        yr +
        "-" +
        String(mo + 1).padStart(2, "0") +
        "-" +
        String(d).padStart(2, "0");
      const dt = new Date(yr, mo, d);
      const st = bookedDates[ds];
      const past = dt < today;
      let cls = "fcell";
      if (past) cls += " past";
      else if (st) cls += " bkd";
      else if (ds === chosenDate) cls += " sel";
      const interactive = !past && !st;
      html +=
        '<span class="' +
        cls +
        '"' +
        (interactive
          ? ' role="button" tabindex="0" aria-label="Select date ' + ds + '"'
          : "") +
        ">" +
        d +
        "</span>";
    }
    grid.innerHTML = html;
    grid.querySelectorAll(".fcell:not(.past):not(.bkd)").forEach((cell) => {
      function chooseDate() {
        grid
          .querySelectorAll(".fcell")
          .forEach((c) => c.classList.remove("sel"));
        cell.classList.add("sel");
        const d = cell.textContent.padStart(2, "0"),
          m = String(mo + 1).padStart(2, "0");
        chosenDate = yr + "-" + m + "-" + d;
        setTxt("fpb-selDate", "Selected: " + formatDateHuman(chosenDate));
        clearErr("fpb-s1err");
        renderPartialPaymentOption();
        updateStep1NextState();
      }

      cell.addEventListener("click", chooseDate);
      cell.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          chooseDate();
        }
      });
    });
  }

  function updateStep1NextState() {
    const btn = document.getElementById("fpb-s1NextBtn");
    if (!btn) return;
    const ready = !!chosenDate;
    btn.disabled = !ready;
    btn.title = ready ? "" : "Select a date to continue";
  }

  function updateStep2NextState() {
    const btn = document.getElementById("fpb-s2NextBtn");
    if (!btn) return;
    const ready = !!selectedPkg;
    btn.disabled = !ready;
    btn.title = ready ? "" : "Select a package to continue";
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

  function validateDetails() {
    const els = document.querySelectorAll("[data-fpb-cf]");
    for (const el of els) {
      const key = el.getAttribute("data-fpb-cf");
      const label = el.getAttribute("data-label") || key;
      const required = el.getAttribute("data-required") === "1";
      const value = String(el.value || "").trim();

      if (required && value === "") {
        showErr("fpb-s3err", label + " is required.");
        el.focus();
        return false;
      }

      if (key === "email" && value !== "") {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
          showErr("fpb-s3err", "Please enter a valid email.");
          el.focus();
          return false;
        }
      }

      if (key === "phone" && value !== "") {
        if (!/^[+]?[0-9 \-()]{7,20}$/.test(value)) {
          showErr(
            "fpb-s3err",
            "Please enter a valid phone number (digits, +, spaces, dashes only).",
          );
          el.focus();
          return false;
        }
      }

      if (key === "participants" && (required || value !== "")) {
        if (parseInt(value, 10) < 1 || isNaN(parseInt(value, 10))) {
          showErr("fpb-s3err", label + " must be at least 1.");
          el.focus();
          return false;
        }
      }
    }
    clearErr("fpb-s3err");
    return true;
  }

  /* -------------------------------------------------
     Step navigation
  ------------------------------------------------- */
  let bookingLocked = false; // set once an order is placed
  let embedOrder = null; // { id, key, snapshot } — order behind the embedded payment

  function bkGo(step) {
    const target = parseInt(step, 10) || 1;
    document
      .querySelectorAll(".fstep")
      .forEach((el) => el.classList.remove("act"));
    for (let i = 1; i <= 4; i++) {
      const sp = document.getElementById("fpb-sp" + i);
      if (!sp) continue;
      sp.classList.remove("active", "done");
      if (i === target) sp.classList.add("active");
      else if (i < target) sp.classList.add("done");
    }
    const el = document.getElementById("fpb-s" + target);
    if (el) {
      el.classList.add("act");
      el.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  // Completed steps in the indicator are clickable to go back.
  function initStepIndicator() {
    for (let i = 1; i <= 4; i++) {
      const sp = document.getElementById("fpb-sp" + i);
      if (!sp) continue;
      sp.addEventListener("click", () => {
        if (bookingLocked) return;
        if (sp.classList.contains("done")) bkGo(i);
      });
    }
  }

  function s1Next() {
    if (!chosenDate) {
      showErr("fpb-s1err", "Please choose a session date.");
      return;
    }
    clearErr("fpb-s1err");
    renderPartialPaymentOption();
    updateStep2Price();
    bkGo(2);
  }

  function s2Next() {
    if (!selectedPkg) {
      showErr("fpb-s2err", "Please select a package.");
      return;
    }
    clearErr("fpb-s2err");
    collectAddons();
    bkGo(3);
  }

  function s3Next() {
    if (!validateDetails()) {
      return;
    }
    collectAddons();
    populatePaymentStep();
    bkGo(4);
  }

  /* -------------------------------------------------
     Step 4 — Payment
  ------------------------------------------------- */
  function populatePaymentStep() {
    if (!selectedPkg) return;
    updateSummary();
    renderPaymentGateways();
    // Classic layout on (re)entry — the embedded payment section only
    // appears after "Place Booking & Pay" is clicked.
    const box = document.getElementById("fpb-embedPay");
    if (box) box.style.display = "none";
    const btn = document.getElementById("fpb-checkoutBtn");
    if (btn) {
      btn.disabled = false;
      btn.style.display = "";
    }
  }

  function getSelectedGateway() {
    const checked = document.querySelector(
      'input[name="fpb-gateway"]:checked',
    );
    return checked ? checked.value : "";
  }

  function renderPaymentGateways(loadFailed) {
    const wrap = document.getElementById("fpb-gatewayList");
    const box = document.getElementById("fpb-gatewayBox");
    if (!wrap || !box) return;

    if (!fpbData.hasWC) {
      box.style.display = "none";
      return;
    }

    box.style.display = "";

    if (loadFailed) {
      wrap.innerHTML =
        '<p class="fpb-gateway-loading">Could not load payment methods. You can still continue — payment options will be shown on the payment page.</p>';
      return;
    }

    if (!paymentGateways.length) {
      wrap.innerHTML =
        '<p class="fpb-gateway-loading">No payment methods are enabled in WooCommerce yet. Enable one under WooCommerce → Settings → Payments.</p>';
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
          const note =
            selectable && gateway.needs_payment_page
              ? '<p class="fpb-pay-next-note">' +
                "The secure payment form will open below once you place the booking." +
                "</p>"
              : "";
          const descBox =
            desc || note
              ? '<div class="payment_box payment_method_' +
                id +
                '"' +
                (selectable && i !== 0 ? ' style="display:none"' : "") +
                ">" +
                desc +
                note +
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
      addons_label: chosenAddons.length
        ? chosenAddons.map((a) => a.name).join(", ")
        : "",
      addons_total: addonsTotal,
      total_raw:
        parseFloat(selectedPkg ? selectedPkg.price || 0 : 0) + addonsTotal,
      use_deposit:
        paymentPreview && parseInt(paymentPreview.payPct || 100, 10) === 50
          ? 1
          : getEffectiveDepositPct() === 50
            ? 1
            : 0,
      session_date: chosenDate || "",
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
    if (!selectedPkg) {
      const msg = document.getElementById("fpb-checkoutMsg");
      if (msg) {
        msg.textContent =
          "No package selected. Please go back and choose a package.";
        msg.className = "fpb-checkout-msg err";
      }
      return;
    }
    const btn = document.getElementById("fpb-checkoutBtn");
    const msg = document.getElementById("fpb-checkoutMsg");
    if (!btn || !msg) return;
    if (!btn.dataset.orig) btn.dataset.orig = btn.textContent;
    btn.disabled = true;
    btn.classList.add("is-loading");
    btn.textContent = "Please wait…";
    msg.textContent = "Preparing your booking…";
    msg.className = "fpb-checkout-msg";

    function restoreBtn() {
      btn.disabled = false;
      btn.classList.remove("is-loading");
      btn.textContent = btn.dataset.orig;
    }

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

    const effectiveDepositFlag =
      paymentPreview && parseInt(paymentPreview.payPct || 100, 10) === 50
        ? 1
        : getEffectiveDepositPct() === 50
          ? 1
          : 0;

    if (fpbData.hasWC && checkoutMode === "direct") {
      const payload = buildOrderPayload(getSelectedGateway());
      if (embedOrder) {
        payload.previous_order_id = embedOrder.id;
        payload.previous_order_key = embedOrder.key;
      }

      post("fpb_place_order", payload)
        .then((r) => {
          if (r.success && r.data && r.data.embed_url) {
            // Gateway renders its secure fields on the order-pay page —
            // embed that page right here so the customer never leaves.
            restoreBtn();
            embedOrder = {
              id: r.data.order_id,
              key: r.data.order_key || "",
              snapshot: null, // method-specific order — recreate after edits
            };
            showEmbeddedPayment(r.data);
          } else if (r.success && r.data && r.data.redirect_url) {
            // External processor — payment must finish there.
            window.location.href = r.data.redirect_url;
          } else if (r.success && r.data && r.data.order_id) {
            showBookingConfirmation(r.data);
          } else {
            restoreBtn();
            msg.textContent =
              r.data && r.data.message
                ? r.data.message
                : "Something went wrong. Please try again.";
            msg.className = "fpb-checkout-msg err";
          }
        })
        .catch(() => {
          restoreBtn();
          msg.textContent =
            "Network error. Check your connection and try again.";
          msg.className = "fpb-checkout-msg err";
        });
    } else if (fpbData.hasWC) {
      // Classic mode: add to cart, then WooCommerce checkout page.
      post("fpb_add_to_cart", {
        session_type: session ? session.name : "",
        package_name: selectedPkg.name,
        package_id: selectedPkg.id,
        addons_label: addonsLabel,
        addons_total: addonsTotal,
        total_raw: total,
        use_deposit: effectiveDepositFlag,
        session_date: chosenDate || "",
        session_time: details.event_time || "",
        location_pref: details.hotel_place || "",
        notes: details.notes || "",
        client_name: ((details.first_name || "") + " " + (details.last_name || "")).trim(),
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
          if (r.success) {
            window.location.href = r.data.checkout_url;
          } else {
            restoreBtn();
            msg.textContent =
              r.data && r.data.message
                ? r.data.message
                : "Something went wrong. Please try again.";
            msg.className = "fpb-checkout-msg err";
          }
        })
        .catch(() => {
          restoreBtn();
          msg.textContent =
            "Network error. Check your connection and try again.";
          msg.className = "fpb-checkout-msg err";
        });
    } else {
      post("fpb_submit", {
        name: ((details.first_name || "") + " " + (details.last_name || "")).trim(),
        email: details.email || "",
        phone: details.phone || "",
        pkg: selectedPkg.name,
        total: cur + total.toFixed(2),
        date: chosenDate || "",
        time: details.event_time || "",
        location: details.hotel_place || "",
        notes: details.notes || "",
        signer: "",
      })
        .then((r) => {
          if (r.success) {
            document.getElementById("fpb-payWrap").style.display = "none";
            const suc = document.getElementById("fpb-sucWrap");
            if (suc) {
              suc.style.display = "block";
              suc.classList.add("show");
            }
            setTxt("fpb-sucEmail", details.email || "");
            const wa = document.getElementById("fpb-waLink");
            if (wa && fpbData.whatsapp)
              wa.href = "https://wa.me/" + fpbData.whatsapp.replace(/\D/g, "");
          } else {
            restoreBtn();
            msg.textContent =
              r.data && r.data.message
                ? r.data.message
                : "Error. Please try again.";
            msg.className = "fpb-checkout-msg err";
          }
        })
        .catch(() => {
          restoreBtn();
          msg.textContent =
            "Network error. Check your connection and try again.";
          msg.className = "fpb-checkout-msg err";
        });
    }
  }

  /* -------------------------------------------------
     Embedded payment — load the WooCommerce order-pay
     page (chrome-less) inside step 4 so card fields /
     PayPal buttons render like on the checkout page.
  ------------------------------------------------- */
  function applyEmbedLayout() {
    // The embedded WooCommerce payment section replaces the duplicate
    // method list and pay button. Back stays available — editing the
    // booking supersedes the order with a fresh one.
    const gatewayBox = document.getElementById("fpb-gatewayBox");
    if (gatewayBox) gatewayBox.style.display = "none";
    const checkoutBtn = document.getElementById("fpb-checkoutBtn");
    if (checkoutBtn) checkoutBtn.style.display = "none";
    const box = document.getElementById("fpb-embedPay");
    if (box) box.style.display = "";
  }

  function showEmbeddedPayment(d) {
    const payWrap = document.getElementById("fpb-payWrap");
    if (!payWrap) {
      window.location.href = d.redirect_url || d.pay_url;
      return;
    }

    applyEmbedLayout();
    const msg = document.getElementById("fpb-checkoutMsg");
    if (msg) {
      msg.textContent = "";
      msg.className = "fpb-checkout-msg";
    }

    let box = document.getElementById("fpb-embedPay");
    if (!box) {
      box = document.createElement("div");
      box.id = "fpb-embedPay";
      box.className = "fpb-embed-pay";
      box.innerHTML =
        '<div class="fpb-gateway-title">Secure Payment</div>' +
        '<iframe id="fpb-embedPayFrame" title="Secure payment" allow="payment"></iframe>' +
        '<p class="fpb-embed-pay-alt">Having trouble paying? ' +
        '<a id="fpb-embedPayLink" href="#">Open the secure payment page</a>.</p>';
      payWrap.appendChild(box);

      const frame = document.getElementById("fpb-embedPayFrame");
      frame.addEventListener("load", () => {
        try {
          const href = frame.contentWindow.location.href;
          if (href && href.indexOf("order-received") !== -1) {
            // Payment finished inside the frame — show the real
            // order-received page full screen.
            window.location.href = d.received_url || href;
            return;
          }
          syncEmbedHeight(frame);
        } catch (e) {
          // Frame navigated to an external (cross-origin) page — ignore.
        }
      });
    }

    const link = document.getElementById("fpb-embedPayLink");
    if (link) link.href = d.redirect_url || d.pay_url || "#";
    document.getElementById("fpb-embedPayFrame").src = d.embed_url;
    box.style.display = "";
    box.scrollIntoView({ behavior: "smooth", block: "start" });
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
    const wrap = document.getElementById("fpb-confirmWrap");
    if (!wrap) {
      // Older template without the confirmation panel — fall back to the pay page.
      if (d.pay_url) window.location.href = d.pay_url;
      return;
    }

    bookingLocked = true; // freeze the stepper — the order exists now

    const payWrap = document.getElementById("fpb-payWrap");
    if (payWrap) payWrap.style.display = "none";

    const processed = !!d.payment_processed;
    // fpbData.currency is entity-decoded by wp_localize_script; the AJAX
    // value may still be a raw entity like &euro;, so prefer the local one.
    const currency = cur !== "e" ? cur : d.currency || cur;

    // Titles and messages are editable in SnapBook → Settings → Frontend Text.
    setTxt(
      "fpb-confirmTitle",
      processed
        ? fpbData.confirmTitle || "Booking Confirmed!"
        : fpbData.confirmPendingTitle || "Booking Received!",
    );
    const noteTemplate = processed
      ? fpbData.confirmMsg ||
        "Thank you for your booking! A confirmation email has been sent to {email}."
      : fpbData.confirmPendingMsg ||
        "Thank you for your booking! Complete the payment below to confirm your slot.";
    setTxt(
      "fpb-confirmNote",
      noteTemplate.replace(/\{email\}/g, d.client_email || "your email address"),
    );
    setTxt("fpb-confirmOrder", "#" + (d.order_number || d.order_id));
    setTxt("fpb-confirmMethod", d.gateway_title || "—");
    setTxt(
      "fpb-confirmAmount",
      currency + parseFloat(d.due_now || 0).toFixed(2),
    );
    setTxt("fpb-confirmStatus", d.status_label || d.status || "—");

    const payBtn = document.getElementById("fpb-confirmPayBtn");
    if (payBtn) {
      if (!processed && d.pay_url) {
        payBtn.href = d.pay_url;
        payBtn.style.display = "";
      } else {
        payBtn.style.display = "none";
      }
    }
    const viewBtn = document.getElementById("fpb-confirmViewBtn");
    if (viewBtn) {
      if (processed && d.received_url) {
        viewBtn.href = d.received_url;
        viewBtn.style.display = "";
      } else {
        viewBtn.style.display = "none";
      }
    }
    const waBtn = document.getElementById("fpb-confirmWaBtn");
    if (waBtn) {
      if (fpbData.whatsapp) {
        waBtn.href = "https://wa.me/" + fpbData.whatsapp.replace(/\D/g, "");
        waBtn.style.display = "";
      } else {
        waBtn.style.display = "none";
      }
    }

    // .suc is display:none by CSS class; the inline block + .show class
    // (fade-in) are both needed to actually reveal the panel.
    wrap.style.display = "block";
    wrap.classList.add("show");
    wrap.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  /* -------------------------------------------------
     DOM helpers
  ------------------------------------------------- */
  function setTxt(id, t) {
    const el = document.getElementById(id);
    if (el) el.textContent = t;
  }
  function setHtml(id, html) {
    const el = document.getElementById(id);
    if (el) el.innerHTML = html;
  }
  function showErr(id, t) {
    const el = document.getElementById(id);
    if (el) {
      el.textContent = t;
      el.style.display = "";
    }
  }
  function clearErr(id) {
    const el = document.getElementById(id);
    if (el) el.textContent = "";
  }
  function escHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // Icon value may be an emoji or an icon-font class such as
  // "fa-solid fa-camera" — mirrors sb_icon_html() on the server.
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
  window.fpb = { bkGo, s1Next, s2Next, s3Next, proceedToCheckout };

  /* -------------------------------------------------
     Boot
  ------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", () => {
    init();
    initPartialPaymentOption();
    initStepIndicator();

    [
      "fpb-checkoutMsg",
      "fpb-s1err",
      "fpb-s2err",
      "fpb-s3err",
      "fpb-s4err",
    ].forEach((id) => {
      const el = document.getElementById(id);
      if (el) {
        el.setAttribute("aria-live", "polite");
      }
    });

    // Restrict phone field to valid phone characters only
    const phoneInput = document.querySelector('[data-fpb-cf="phone"]');
    if (phoneInput) {
      phoneInput.addEventListener("input", () => {
        phoneInput.value = phoneInput.value.replace(/[^0-9+\-() ]/g, "");
      });
    }
  });
})();
