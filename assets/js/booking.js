/* global snapbookData */
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

  const cur = snapbookData.currency || "e";
  const checkoutMode =
    snapbookData.checkoutMode === "redirect" ? "redirect" : "direct";
  let partialPaymentEnabled = !!snapbookData.partialPaymentEnabled;
  let partialBlockDays = parseInt(snapbookData.partialBlockDays || 0, 10) || 0;
  let partialOptionLabel =
    snapbookData.partialOptionLabel || "Book a slot to 50% Pay";
  let usePartialPayment = partialPaymentEnabled;
  let paymentPreview = null;
  let previewRequestSeq = 0;

  // The Contract step (SnapBook → Frontend) sits between Details and Payment
  // when it's on, so the wizard is 4 steps instead of 3. Every step number in
  // this file is derived from these two constants.
  const contractEnabled = !!snapbookData.contractEnabled;
  const PAY_STEP = contractEnabled ? 4 : 3;
  const TOTAL_STEPS = PAY_STEP;

  /* -------------------------------------------------
     AJAX
  ------------------------------------------------- */
  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", snapbookData.nonce);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    return fetch(snapbookData.ajaxUrl, { method: "POST", body: fd }).then((r) =>
      r.json(),
    );
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
  // so anything unknown here is unavailable → show the step-2 notice.
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
      const notice = document.getElementById("fpb-pkgNotice");
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
    if (!snapbookData.showLoader) return;
    const target = document.getElementById(
      kind === "calendar" ? "fpb-calGrid" : "fpb-pkgGrid",
    );
    if (!target || target.dataset.fpbSkel === "1") return;
    target.dataset.fpbSkel = "1";
    target.setAttribute("aria-busy", "true");
    target.innerHTML = skeletonHtml(kind);
  }

  function clearSkeleton() {
    ["fpb-calGrid", "fpb-pkgGrid"].forEach((id) => {
      const el = document.getElementById(id);
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
  // Only availability dates need a live request (a cached page must never
  // offer a date that has since been booked).
  function init() {
    const inline = snapbookData.catalog;
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

    const requests = catalogReady ? [] : [post("snapbook_get_data", {})];
    requests.push(post("snapbook_get_dates", {}));

    Promise.all(requests)
      .then((responses) => {
        const datesRes = responses[responses.length - 1];
        // Clear placeholders before rendering, or the render would be
        // overwritten by the teardown.
        clearSkeleton();
        if (!catalogReady && responses[0] && responses[0].success) {
          applyCatalog(responses[0].data);
          resolvePreselectPackage();
          renderSessionTabs();
          updatePkgNextState();
        }
        if (datesRes.success) {
          bookedDates = datesRes.data || {};
        }
        initCalendar();
      })
      .catch(() => {
        clearSkeleton();
        const t = document.getElementById("fpb-typeTabs");
        if (t)
          t.innerHTML =
            '<p class="fpb-load-error">Could not load booking data. Please refresh.</p>';
      });
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

  // Payment gateways are only needed on the payment step, so they are
  // fetched when the customer commits to a package rather than on page
  // load — one less request blocking the first render.
  let gatewaysPromise = null;
  let gatewaysLoaded = false;
  function prefetchGateways() {
    if (gatewaysPromise || !snapbookData.hasWC) return gatewaysPromise;
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
        .forEach((b) => b.classList.remove("fpb-act"));
      btn.classList.add("fpb-act");
      activeSessionId = parseInt(btn.dataset.id, 10);
      selectedPkgId = null;
      selectedPkg = null;
      showAddons(false);
      renderPackages();
      updateStep2Price();
      updatePkgNextState();
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
          '<div class="fpb-pkg' +
          (p.featured == "1" ? " fpb-feat" : "") +
          '" data-id="' +
          p.id +
          '" role="button" tabindex="0">' +
          (p.featured == "1"
            ? '<span class="fpb-pkg-tag">&#9733; Popular</span>'
            : "") +
          '<div class="fpb-pkg-name">' +
          escHtml(p.name) +
          "</div>" +
          '<div class="fpb-pkg-price">' +
          cur +
          parseFloat(p.price || 0).toLocaleString(undefined, {
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
          }) +
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
      function selectCard() {
        grid
          .querySelectorAll(".fpb-pkg")
          .forEach((c) => c.classList.remove("fpb-sel"));
        card.classList.add("fpb-sel");
        selectedPkgId = parseInt(card.dataset.id, 10);
        selectedPkg =
          pkgs.find((p) => parseInt(p.id, 10) === selectedPkgId) || null;
        clearErr("fpb-s1err");
        renderAddons();
        updateStep2Price();
        updatePkgNextState();
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
        '.fpb-pkg[data-id="' + parseInt(preselectPkg.id, 10) + '"]',
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
          '<label class="fpb-addon-card" for="fpb-addon-' +
          a.id +
          '">' +
          '<input class="fpb-ac" type="checkbox" value="' +
          a.id +
          '" id="fpb-addon-' +
          a.id +
          '">' +
          '<span class="fpb-addon-em">' +
          (iconHtml(a.emoji) || "•") +
          "</span>" +
          '<span class="fpb-addon-info">' +
          '<span class="fpb-addon-name">' +
          escHtml(a.name) +
          "</span>" +
          // Description is rich text sanitized server-side (wp_kses_post)
          (a.description
            ? '<span class="fpb-addon-desc">' + a.description + "</span>"
            : "") +
          (pkgOnly
            ? '<span class="fpb-addon-badge">This package only</span>'
            : "") +
          "</span>" +
          '<span class="fpb-addon-price">+' +
          cur +
          parseFloat(a.price || 0).toFixed(0) +
          "</span>" +
          "</label>"
        );
      })
      .join("");

    grid.querySelectorAll(".fpb-ac").forEach((cb) => {
      cb.addEventListener("change", updateStep2Price);
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
    document
      .querySelectorAll("#fpb-addonsGrid .fpb-ac:checked")
      .forEach((cb) => {
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
    const feePct = getPaymentFeePct();
    const fee = Math.round(total * feePct) / 100;
    const payable = Math.round((total + fee) * 100) / 100;
    const effectiveDepPct = getEffectiveDepositPct();
    const dueNow = Math.round(payable * effectiveDepPct) / 100;
    const balance = Math.max(0, payable - dueNow);
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
    updateFeeRows(fee, payable, feePct);
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

  function getPaymentFeePct() {
    const p = parseFloat(snapbookData.paymentFeePct || 0);
    return isNaN(p) || p <= 0 ? 0 : Math.min(100, p);
  }

  // Fee + total-payable rows sit right above the Due Now total; both stay
  // hidden while no fee percentage is configured in the settings.
  function updateFeeRows(fee, payable, feePct) {
    const feeRow = document.getElementById("fpb-sum-fee-row");
    const payableRow = document.getElementById("fpb-sum-payable-row");
    const show = feePct > 0;
    if (feeRow) feeRow.style.display = show ? "" : "none";
    if (payableRow) payableRow.style.display = show ? "" : "none";
    // One total only: with the fee breakdown visible the first row becomes
    // "Subtotal" and "Total payable" is the single total.
    const priceLabel = document.getElementById("fpb-sum-price-label");
    if (priceLabel) {
      if (!priceLabel.dataset.orig)
        priceLabel.dataset.orig = priceLabel.textContent;
      priceLabel.textContent = show
        ? snapbookData.subtotalLabel || "Subtotal"
        : priceLabel.dataset.orig;
    }
    if (!show) return;
    setTxt(
      "fpb-sum-fee-label",
      (snapbookData.paymentFeeLabel || "PayPal fee") +
        " (" +
        parseFloat(feePct.toFixed(2)) +
        "%)",
    );
    setTxt("fpb-sum-fee", "+" + cur + fee.toFixed(2));
    setTxt("fpb-sum-payable", cur + payable.toFixed(2));
  }

  function refreshPaymentPreview(total) {
    if (!snapbookData.hasWC) return;

    const reqId = ++previewRequestSeq;
    post("snapbook_preview_payment", {
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

        // Server-computed fee numbers are authoritative for the summary.
        updateFeeRows(
          parseFloat(paymentPreview.feeAmount || 0),
          parseFloat(paymentPreview.payable || paymentPreview.total || 0),
          parseFloat(paymentPreview.feePct || 0),
        );
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
      let cls = "fpb-cell";
      if (past) cls += " fpb-past";
      else if (st) cls += " fpb-bkd";
      else if (ds === chosenDate) cls += " fpb-sel";
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
    grid
      .querySelectorAll(".fpb-cell:not(.fpb-past):not(.fpb-bkd)")
      .forEach((cell) => {
        function chooseDate() {
          grid
            .querySelectorAll(".fpb-cell")
            .forEach((c) => c.classList.remove("fpb-sel"));
          cell.classList.add("fpb-sel");
          const d = cell.textContent.padStart(2, "0"),
            m = String(mo + 1).padStart(2, "0");
          chosenDate = yr + "-" + m + "-" + d;
          setTxt("fpb-selDate", "Selected: " + formatDateHuman(chosenDate));
          clearErr("fpb-s1err");
          renderPartialPaymentOption();
          updateStep2Price();
          updatePkgNextState();
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

  // The Package step's Continue button. The session date is chosen in the
  // sidebar calendar, so it's validated on click (s1Next) rather than
  // gating the button — a package selection is enough to enable it.
  function updatePkgNextState() {
    const btn = document.getElementById("fpb-s1NextBtn");
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
        showErr("fpb-s2err", label + " is required.");
        el.focus();
        return false;
      }

      if (key === "email" && value !== "") {
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
          showErr("fpb-s2err", "Please enter a valid email.");
          el.focus();
          return false;
        }
      }

      if (key === "phone" && value !== "") {
        if (!/^[+]?[0-9 \-()]{7,20}$/.test(value)) {
          showErr(
            "fpb-s2err",
            "Please enter a valid phone number (digits, +, spaces, dashes only).",
          );
          el.focus();
          return false;
        }
      }

      if (key === "participants" && (required || value !== "")) {
        if (parseInt(value, 10) < 1 || isNaN(parseInt(value, 10))) {
          showErr("fpb-s2err", label + " must be at least 1.");
          el.focus();
          return false;
        }
      }
    }
    clearErr("fpb-s2err");
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
      .querySelectorAll(".fpb-step")
      .forEach((el) => el.classList.remove("fpb-act"));
    for (let i = 1; i <= TOTAL_STEPS; i++) {
      const sp = document.getElementById("fpb-sp" + i);
      if (!sp) continue;
      sp.classList.remove("fpb-active", "fpb-done");
      if (i === target) sp.classList.add("fpb-active");
      else if (i < target) sp.classList.add("fpb-done");
    }
    const el = document.getElementById("fpb-s" + target);
    if (el) {
      el.classList.add("fpb-act");
      el.scrollIntoView({ behavior: "smooth", block: "start" });
    }
  }

  // Completed steps in the indicator are clickable to go back.
  function initStepIndicator() {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
      const sp = document.getElementById("fpb-sp" + i);
      if (!sp) continue;
      sp.addEventListener("click", () => {
        if (bookingLocked) return;
        if (sp.classList.contains("fpb-done")) bkGo(i);
      });
    }
  }

  // Step 1 — Package. The date comes from the sidebar calendar, so both a
  // package and a date are required before moving on to the Details step.
  function s1Next() {
    if (!selectedPkg) {
      showErr("fpb-s1err", "Please select a package.");
      return;
    }
    if (!chosenDate) {
      showErr("fpb-s1err", "Please pick your session date from the calendar.");
      return;
    }
    clearErr("fpb-s1err");
    collectAddons();
    renderPartialPaymentOption();
    updateStep2Price();
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
    if (contractEnabled) {
      bkGo(3);
      return;
    }
    populatePaymentStep();
    bkGo(3);
  }

  // Step 3 — Contract. The terms must be accepted before the payment step
  // is built (in direct mode arriving there already places the order).
  function s3Next() {
    if (!contractAccepted()) {
      showErr(
        "fpb-s3err",
        snapbookData.contractRequiredMsg ||
          "Please accept the Terms & Conditions to continue.",
      );
      const box = document.getElementById("fpb-contractAccept");
      if (box) box.focus();
      return;
    }
    clearErr("fpb-s3err");
    populatePaymentStep();
    bkGo(PAY_STEP);
  }

  function contractAccepted() {
    if (!contractEnabled) return true;
    const box = document.getElementById("fpb-contractAccept");
    return !!(box && box.checked);
  }

  // Keep the Continue button in step with the acceptance checkbox, so the
  // requirement reads as a state rather than only as an error after a click.
  function initContractStep() {
    if (!contractEnabled) return;
    const box = document.getElementById("fpb-contractAccept");
    const btn = document.getElementById("fpb-s3NextBtn");
    if (!box || !btn) return;
    const sync = () => {
      btn.disabled = !box.checked;
      btn.title = box.checked ? "" : "Accept the terms to continue";
      if (box.checked) clearErr("fpb-s3err");
    };
    box.addEventListener("change", sync);
    sync();
  }

  /* -------------------------------------------------
     Step 4 — Payment
  ------------------------------------------------- */
  function populatePaymentStep() {
    if (!selectedPkg) return;
    updateSummary();
    if (snapbookData.hasWC && checkoutMode === "direct") {
      // The WooCommerce payment section loads automatically on arrival —
      // no duplicate method list and no "place booking" button click.
      autoLoadPayment();
      return;
    }
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

  /* -------------------------------------------------
     Direct mode — create/refresh the pending order as
     soon as the customer arrives on step 4 and embed
     the WooCommerce payment section, so the native
     gateway list (PayPal buttons, card fields, Pay
     button) shows without any extra click.
  ------------------------------------------------- */
  function autoLoadPayment() {
    const btn = document.getElementById("fpb-checkoutBtn");
    if (btn) btn.style.display = "none";
    const gatewayBox = document.getElementById("fpb-gatewayBox");
    if (gatewayBox) gatewayBox.style.display = "none";

    const payload = buildOrderPayload("");
    const snapshot = JSON.stringify(payload);

    // Booking unchanged since its order was created — just show it again
    // instead of superseding the order with an identical one.
    if (
      embedOrder &&
      embedOrder.snapshot === snapshot &&
      document.getElementById("fpb-embedPayFrame")
    ) {
      applyEmbedLayout();
      return;
    }

    const msg = document.getElementById("fpb-checkoutMsg");
    if (msg) {
      msg.textContent = "Loading payment options…";
      msg.className = "fpb-checkout-msg fpb-is-loading-pay";
    }

    if (embedOrder) {
      // The booking was edited — supersede the previous pending order.
      payload.previous_order_id = embedOrder.id;
      payload.previous_order_key = embedOrder.key;
    }

    post("snapbook_place_order", payload)
      .then((r) => {
        if (r.success && r.data && r.data.embed_url) {
          embedOrder = {
            id: r.data.order_id,
            key: r.data.order_key || "",
            snapshot: snapshot,
          };
          if (msg) {
            msg.textContent = "";
            msg.className = "fpb-checkout-msg";
          }
          showEmbeddedPayment(r.data, true);
        } else if (r.success && r.data && r.data.redirect_url) {
          window.location.href = r.data.redirect_url;
        } else {
          paymentAutoLoadFailed(
            r.data && r.data.message
              ? r.data.message
              : "Could not load the payment options. Please try again.",
          );
        }
      })
      .catch(() => {
        paymentAutoLoadFailed(
          "Network error. Check your connection and try again.",
        );
      });
  }

  // Fall back to the manual method list + button so the customer is
  // never stuck on step 4 if the automatic order creation fails.
  function paymentAutoLoadFailed(text) {
    const msg = document.getElementById("fpb-checkoutMsg");
    if (msg) {
      msg.textContent = text;
      msg.className = "fpb-checkout-msg fpb-err";
    }
    renderPaymentGateways();
    const btn = document.getElementById("fpb-checkoutBtn");
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
    const wrap = document.getElementById("fpb-gatewayList");
    const box = document.getElementById("fpb-gatewayBox");
    if (!wrap || !box) return;

    if (!snapbookData.hasWC) {
      box.style.display = "none";
      return;
    }

    box.style.display = "";

    if (loadFailed) {
      wrap.innerHTML =
        '<p class="fpb-gateway-loading">Could not load payment methods. You can still continue — payment options will be shown on the payment page.</p>';
      return;
    }

    // Gateways load lazily (see prefetchGateways). While the request is in
    // flight, show the loading note and paint the list once it settles.
    if (!gatewaysLoaded) {
      wrap.innerHTML =
        '<p class="fpb-gateway-loading">Loading payment methods…</p>';
      prefetchGateways().then(() => renderPaymentGateways(loadFailed));
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
        msg.className = "fpb-checkout-msg fpb-err";
      }
      return;
    }
    // Safety net for the classic/redirect path — the contract step already
    // gates the way in, but the terms must hold for every route to checkout.
    if (!contractAccepted()) {
      bkGo(3);
      showErr(
        "fpb-s3err",
        snapbookData.contractRequiredMsg ||
          "Please accept the Terms & Conditions to continue.",
      );
      return;
    }
    const btn = document.getElementById("fpb-checkoutBtn");
    const msg = document.getElementById("fpb-checkoutMsg");
    if (!btn || !msg) return;
    if (!btn.dataset.orig) btn.dataset.orig = btn.textContent;
    btn.disabled = true;
    btn.classList.add("fpb-is-loading");
    btn.textContent = "Please wait…";
    msg.textContent = "Preparing your booking…";
    msg.className = "fpb-checkout-msg";

    function restoreBtn() {
      btn.disabled = false;
      btn.classList.remove("fpb-is-loading");
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

    if (snapbookData.hasWC && checkoutMode === "direct") {
      const payload = buildOrderPayload(getSelectedGateway());
      if (embedOrder) {
        payload.previous_order_id = embedOrder.id;
        payload.previous_order_key = embedOrder.key;
      }

      post("snapbook_place_order", payload)
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
            msg.className = "fpb-checkout-msg fpb-err";
          }
        })
        .catch(() => {
          restoreBtn();
          msg.textContent =
            "Network error. Check your connection and try again.";
          msg.className = "fpb-checkout-msg fpb-err";
        });
    } else if (snapbookData.hasWC) {
      // Classic mode: add to cart, then WooCommerce checkout page.
      post("snapbook_add_to_cart", {
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
          if (r.success) {
            window.location.href = r.data.checkout_url;
          } else {
            restoreBtn();
            msg.textContent =
              r.data && r.data.message
                ? r.data.message
                : "Something went wrong. Please try again.";
            msg.className = "fpb-checkout-msg fpb-err";
          }
        })
        .catch(() => {
          restoreBtn();
          msg.textContent =
            "Network error. Check your connection and try again.";
          msg.className = "fpb-checkout-msg fpb-err";
        });
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
              suc.classList.add("fpb-show");
            }
            setTxt("fpb-sucEmail", details.email || "");
            const wa = document.getElementById("fpb-waLink");
            if (wa && snapbookData.whatsapp)
              wa.href =
                "https://wa.me/" + snapbookData.whatsapp.replace(/\D/g, "");
          } else {
            restoreBtn();
            msg.textContent =
              r.data && r.data.message
                ? r.data.message
                : "Error. Please try again.";
            msg.className = "fpb-checkout-msg fpb-err";
          }
        })
        .catch(() => {
          restoreBtn();
          msg.textContent =
            "Network error. Check your connection and try again.";
          msg.className = "fpb-checkout-msg fpb-err";
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

  function showEmbeddedPayment(d, skipScroll) {
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
        '<div class="fpb-gateway-title">' +
        '<span class="dashicons dashicons-lock" aria-hidden="true"></span>' +
        "Secure Payment</div>" +
        '<div class="fpb-embed-pay-loading" aria-hidden="true">' +
        '<span class="fpb-embed-spinner"></span>' +
        '<span id="fpb-embedPayLoadingText">Loading secure payment…</span></div>' +
        '<iframe id="fpb-embedPayFrame" title="Secure payment" allow="payment"></iframe>' +
        '<p class="fpb-embed-pay-alt">Having trouble paying? ' +
        '<a id="fpb-embedPayLink" href="#">Open the secure payment page</a>.</p>';
      payWrap.appendChild(box);

      const frame = document.getElementById("fpb-embedPayFrame");
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
              setTxt("fpb-embedPayLoadingText", "Processing your payment…");
              box.classList.add("fpb-embed-loading");
            });
          } catch (e) {
            // Frame navigated away already — ignore.
          }
        }
      });
    }

    const link = document.getElementById("fpb-embedPayLink");
    if (link) link.href = d.redirect_url || d.pay_url || "#";
    setTxt("fpb-embedPayLoadingText", "Loading secure payment…");
    box.classList.add("fpb-embed-loading");
    document.getElementById("fpb-embedPayFrame").src = d.embed_url;
    box.style.display = "";

    // The Back button belongs below the payment form.
    const nav = payWrap.querySelector(".fpb-nav");
    if (nav) payWrap.appendChild(nav);

    if (!skipScroll) {
      box.scrollIntoView({ behavior: "smooth", block: "start" });
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
        if (r.success && r.data && r.data.order_id) {
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
    // snapbookData.currency is entity-decoded by wp_localize_script; the AJAX
    // value may still be a raw entity like &euro;, so prefer the local one.
    const currency = cur !== "e" ? cur : d.currency || cur;

    // Titles and messages are editable in SnapBook → Settings → Frontend Text.
    setTxt(
      "fpb-confirmTitle",
      processed
        ? snapbookData.confirmTitle || "Booking Confirmed!"
        : snapbookData.confirmPendingTitle || "Booking Received!",
    );
    const noteTemplate = processed
      ? snapbookData.confirmMsg ||
        "Thank you for your booking! A confirmation email has been sent to {email}."
      : snapbookData.confirmPendingMsg ||
        "Thank you for your booking! Complete the payment below to confirm your slot.";
    setTxt(
      "fpb-confirmNote",
      noteTemplate.replace(
        /\{email\}/g,
        d.client_email || "your email address",
      ),
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
      if (snapbookData.whatsapp) {
        waBtn.href =
          "https://wa.me/" + snapbookData.whatsapp.replace(/\D/g, "");
        waBtn.style.display = "";
      } else {
        waBtn.style.display = "none";
      }
    }

    // .suc is display:none by CSS class; the inline block + .show class
    // (fade-in) are both needed to actually reveal the panel.
    wrap.style.display = "block";
    wrap.classList.add("fpb-show");
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
  window.snapbook = { bkGo, s1Next, s2Next, s3Next, proceedToCheckout };

  /* -------------------------------------------------
     Boot
  ------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", () => {
    init();
    initPartialPaymentOption();
    initStepIndicator();
    initContractStep();

    [
      "fpb-checkoutMsg",
      "fpb-s1err",
      "fpb-s2err",
      "fpb-s3err",
      "fpb-payErr",
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
