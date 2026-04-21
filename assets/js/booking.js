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

  let activeSessionId = null;
  let selectedPkgId = null;
  let selectedPkg = null;
  let chosenAddons = [];
  let chosenDate = null;

  const cur = fpbData.currency || "e";
  const dep = fpbData.depositPct || 50;

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
     Init
  ------------------------------------------------- */
  function init() {
    Promise.all([post("fpb_get_data", {}), post("fpb_get_dates", {})])
      .then(([dataRes, datesRes]) => {
        if (dataRes.success) {
          sessions = dataRes.data.sessions || [];
          packages = dataRes.data.packages || [];
          addons = dataRes.data.addons || [];
        }
        if (datesRes.success) {
          bookedDates = datesRes.data || {};
        }
        renderSessionTabs();
        initCalendar();
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
          (s.emoji ? '<span class="fpb-stype-em">' + s.emoji + "</span>" : "") +
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
      showDateSec(false);
      chosenDate = null;
      setTxt("fpb-selDate", "");
      renderPackages();
    }

    wrap.querySelectorAll(".fpb-stype-btn").forEach((btn) => {
      btn.addEventListener("click", () => activateBtn(btn));
    });

    // Auto-select first
    const first = wrap.querySelector(".fpb-stype-btn");
    if (first) activateBtn(first);
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
          (p.description
            ? '<div class="fpc-desc">' + escHtml(p.description) + "</div>"
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
        renderAddons();
        updateS1Summary();
      }
      card.addEventListener("click", selectCard);
      card.addEventListener("keydown", (e) => {
        if (e.key === "Enter" || e.key === " ") {
          e.preventDefault();
          selectCard();
        }
      });
    });
  }

  /* -------------------------------------------------
     Add-ons
  ------------------------------------------------- */
  function renderAddons() {
    const grid = document.getElementById("fpb-addonsGrid");
    const wrap = document.getElementById("fpb-addonsWrap");
    if (!grid) return;

    // Show add-ons that are global (package_id == 0) OR tied to the selected package
    const visible = addons.filter(
      (a) =>
        parseInt(a.package_id, 10) === 0 ||
        parseInt(a.package_id, 10) === selectedPkgId,
    );

    if (!visible.length) {
      if (wrap) wrap.style.display = "none";
      grid.innerHTML = "";
      return;
    }

    grid.innerHTML = visible
      .map((a) => {
        const pkgOnly = parseInt(a.package_id, 10) !== 0;
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
          (a.emoji || "\u2022") +
          "</span>" +
          '<span class="fadd-info">' +
          '<span class="fadd-name">' +
          escHtml(a.name) +
          "</span>" +
          (a.description
            ? '<span class="fadd-desc">' + escHtml(a.description) + "</span>"
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
      cb.addEventListener("change", updateS1Summary);
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

  function updateS1Summary() {
    const sum = document.getElementById("fpb-s1-summary");
    if (!sum) return;
    if (!selectedPkg) {
      sum.style.display = "none";
      return;
    }
    const checkedAddons = [];
    document.querySelectorAll("#fpb-addonsGrid .ac:checked").forEach((cb) => {
      const a = addons.find((x) => String(x.id) === cb.value);
      if (a) checkedAddons.push(a);
    });
    const addonsTotal = checkedAddons.reduce(
      (s, a) => s + parseFloat(a.price || 0),
      0,
    );
    const total = parseFloat(selectedPkg.price || 0) + addonsTotal;
    const deposit = Math.round(total * dep) / 100;
    const pkgEl = document.getElementById("fpb-sum-pkg");
    const addEl = document.getElementById("fpb-sum-addons");
    const totEl = document.getElementById("fpb-sum-total");
    const depEl = document.getElementById("fpb-sum-dep");
    const activeSess = sessions.find(
      (s) => parseInt(s.id, 10) === activeSessionId,
    );
    const sessPrefix = activeSess
      ? (activeSess.emoji ? activeSess.emoji + "\u00a0" : "") +
        activeSess.name +
        " \u2014 "
      : "";
    if (pkgEl)
      pkgEl.innerHTML =
        sessPrefix +
        escHtml(selectedPkg.name) +
        " \u2014 " +
        cur +
        parseFloat(selectedPkg.price || 0).toFixed(0);
    if (addEl)
      addEl.textContent =
        addonsTotal > 0
          ? cur + addonsTotal.toFixed(0)
          : checkedAddons.length
            ? cur + "0"
            : "\u2014";
    if (totEl) totEl.textContent = cur + total.toFixed(0);
    if (depEl)
      depEl.textContent =
        "Deposit " + dep + "% \u2014 " + cur + deposit.toFixed(0);
    sum.style.display = "";
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

  function showDateSec() {
    // Date section is always visible (shown first), so this is a no-op
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
      html += '<span class="' + cls + '">' + d + "</span>";
    }
    grid.innerHTML = html;
    grid.querySelectorAll(".fcell:not(.past):not(.bkd)").forEach((cell) => {
      cell.addEventListener("click", () => {
        grid
          .querySelectorAll(".fcell")
          .forEach((c) => c.classList.remove("sel"));
        cell.classList.add("sel");
        const d = cell.textContent.padStart(2, "0"),
          m = String(mo + 1).padStart(2, "0");
        chosenDate = yr + "-" + m + "-" + d;
        setTxt("fpb-selDate", "Selected: " + chosenDate);
      });
    });
  }

  /* -------------------------------------------------
     Step navigation
  ------------------------------------------------- */
  function bkGo(step) {
    document
      .querySelectorAll(".fstep")
      .forEach((el) => el.classList.remove("act"));
    document
      .querySelectorAll(".fsp")
      .forEach((el) => el.classList.remove("act"));
    const el = document.getElementById("fpb-s" + step),
      sp = document.getElementById("fpb-sp" + step);
    if (el) {
      el.classList.add("act");
      el.scrollIntoView({ behavior: "smooth", block: "start" });
    }
    if (sp) sp.classList.add("act");
  }

  function s1Next() {
    if (!selectedPkg) {
      showErr("fpb-s1err", "Please select a package.");
      return;
    }
    if (!chosenDate) {
      showErr("fpb-s1err", "Please choose a session date.");
      return;
    }
    clearErr("fpb-s1err");
    bkGo(2);
  }

  function s2Next() {
    const name = val("fpb-fname"),
      email = val("fpb-femail"),
      phone = val("fpb-fphone"),
      country = val("fpb-fcountry");
    if (!name || !email || !phone || !country) {
      showErr("fpb-s2err", "All required fields must be filled in.");
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showErr("fpb-s2err", "Please enter a valid email.");
      return;
    }
    if (!/^[+]?[0-9 \-()]{7,20}$/.test(phone)) {
      showErr(
        "fpb-s2err",
        "Please enter a valid phone number (digits, +, spaces, dashes only).",
      );
      return;
    }
    clearErr("fpb-s2err");
    bkGo(3);
  }

  function s3Next() {
    if (!val("fpb-fsigner")) {
      showErr("fpb-s3err", "Please type your full name to confirm.");
      return;
    }
    if (isSigEmpty()) {
      showErr("fpb-s3err", "Please draw your signature.");
      return;
    }
    clearErr("fpb-s3err");
    collectAddons();
    populateStep4();
    bkGo(4);
  }

  /* -------------------------------------------------
     Step 4 summary
  ------------------------------------------------- */
  function populateStep4() {
    if (!selectedPkg) return;
    const addonsTotal = chosenAddons.reduce(
      (s, a) => s + parseFloat(a.price || 0),
      0,
    );
    const total = parseFloat(selectedPkg.price || 0) + addonsTotal;
    const deposit = Math.round(total * dep) / 100;
    const balance = total - deposit;
    const addonsLabel = chosenAddons.length
      ? chosenAddons
          .map((a) => (a.emoji ? a.emoji + "\u00a0" : "") + a.name)
          .join(", ")
      : "None";
    const session = sessions.find(
      (s) => parseInt(s.id, 10) === activeSessionId,
    );
    const step4PkgLabel = document.getElementById("fpb-pPkg");
    if (step4PkgLabel) {
      const sessEmoji =
        session && session.emoji ? session.emoji + "\u00a0" : "";
      const sessName = session ? session.name + " \u2014 " : "";
      step4PkgLabel.innerHTML =
        sessEmoji + sessName + escHtml(selectedPkg.name);
    }
    setTxt("fpb-pDate", chosenDate || "--");
    setTxt("fpb-pAddons", addonsLabel);
    setTxt("fpb-pSigner", val("fpb-fsigner"));
    setTxt("fpb-pTot", cur + total.toFixed(2));
    setTxt("fpb-pDep", cur + deposit.toFixed(2));
    setTxt("fpb-pBal", cur + balance.toFixed(2));
  }

  /* -------------------------------------------------
     Proceed to WooCommerce Checkout
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
    btn.disabled = true;
    btn.textContent = "Please wait\u2026";
    msg.textContent = "Preparing your booking\u2026";
    msg.className = "fpb-checkout-msg";

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

    if (fpbData.hasWC) {
      post("fpb_add_to_cart", {
        session_type: session ? session.name : "",
        package_name: selectedPkg.name,
        package_id: selectedPkg.id,
        addons_label: addonsLabel,
        addons_total: addonsTotal,
        total_raw: total,
        client_name: val("fpb-fname"),
        client_email: val("fpb-femail"),
        client_phone: val("fpb-fphone"),
        client_country: val("fpb-fcountry"),
        session_date: chosenDate || "",
        session_time: val("fpb-ftime"),
        location_pref: val("fpb-floc"),
        notes: val("fpb-fnotes"),
        signer_name: val("fpb-fsigner"),
      })
        .then((r) => {
          if (r.success) {
            window.location.href = r.data.checkout_url;
          } else {
            btn.disabled = false;
            btn.textContent = "Proceed to Checkout";
            msg.textContent =
              r.data && r.data.message
                ? r.data.message
                : "Something went wrong. Please try again.";
            msg.className = "fpb-checkout-msg err";
          }
        })
        .catch(() => {
          btn.disabled = false;
          btn.textContent = "Proceed to Checkout";
          msg.textContent =
            "Network error. Check your connection and try again.";
          msg.className = "fpb-checkout-msg err";
        });
    } else {
      post("fpb_submit", {
        name: val("fpb-fname"),
        email: val("fpb-femail"),
        phone: val("fpb-fphone"),
        pkg: selectedPkg.name,
        total: cur + total.toFixed(2),
        date: chosenDate || "",
        time: val("fpb-ftime"),
        location: val("fpb-floc"),
        notes: val("fpb-fnotes"),
        signer: val("fpb-fsigner"),
      })
        .then((r) => {
          if (r.success) {
            document.getElementById("fpb-payWrap").style.display = "none";
            const suc = document.getElementById("fpb-sucWrap");
            if (suc) suc.style.display = "";
            setTxt("fpb-sucEmail", val("fpb-femail"));
            const wa = document.getElementById("fpb-waLink");
            if (wa && fpbData.whatsapp)
              wa.href = "https://wa.me/" + fpbData.whatsapp.replace(/\D/g, "");
          } else {
            btn.disabled = false;
            btn.textContent = "Submit Booking Request";
            msg.textContent =
              r.data && r.data.message
                ? r.data.message
                : "Error. Please try again.";
            msg.className = "fpb-checkout-msg err";
          }
        })
        .catch(() => {
          btn.disabled = false;
          btn.textContent = "Submit Booking Request";
          msg.textContent =
            "Network error. Check your connection and try again.";
          msg.className = "fpb-checkout-msg err";
        });
    }
  }

  /* -------------------------------------------------
     Signature pad
  ------------------------------------------------- */
  let sigCtx,
    sigDrawing = false;

  function initSig() {
    const canvas = document.getElementById("fpb-sigPad");
    if (!canvas) return;
    sigCtx = canvas.getContext("2d");
    sigCtx.strokeStyle = "#1a1a2e";
    sigCtx.lineWidth = 2;
    sigCtx.lineCap = "round";
    function getPos(e) {
      const r = canvas.getBoundingClientRect(),
        sx = canvas.width / r.width,
        sy = canvas.height / r.height;
      const src = e.touches ? e.touches[0] : e;
      return { x: (src.clientX - r.left) * sx, y: (src.clientY - r.top) * sy };
    }
    canvas.addEventListener("mousedown", (e) => {
      sigDrawing = true;
      const p = getPos(e);
      sigCtx.beginPath();
      sigCtx.moveTo(p.x, p.y);
    });
    canvas.addEventListener("mousemove", (e) => {
      if (!sigDrawing) return;
      const p = getPos(e);
      sigCtx.lineTo(p.x, p.y);
      sigCtx.stroke();
    });
    canvas.addEventListener("mouseup", () => (sigDrawing = false));
    canvas.addEventListener("mouseleave", () => (sigDrawing = false));
    canvas.addEventListener(
      "touchstart",
      (e) => {
        e.preventDefault();
        sigDrawing = true;
        const p = getPos(e);
        sigCtx.beginPath();
        sigCtx.moveTo(p.x, p.y);
      },
      { passive: false },
    );
    canvas.addEventListener(
      "touchmove",
      (e) => {
        e.preventDefault();
        if (!sigDrawing) return;
        const p = getPos(e);
        sigCtx.lineTo(p.x, p.y);
        sigCtx.stroke();
      },
      { passive: false },
    );
    canvas.addEventListener("touchend", () => (sigDrawing = false));
  }

  function clrSig() {
    if (sigCtx) {
      const c = document.getElementById("fpb-sigPad");
      sigCtx.clearRect(0, 0, c.width, c.height);
    }
  }

  function isSigEmpty() {
    const c = document.getElementById("fpb-sigPad");
    if (!c || !sigCtx) return true;
    const d = sigCtx.getImageData(0, 0, c.width, c.height).data;
    for (let i = 3; i < d.length; i += 4) if (d[i] > 0) return false;
    return true;
  }

  /* -------------------------------------------------
     DOM helpers
  ------------------------------------------------- */
  function val(id) {
    const el = document.getElementById(id);
    return el ? el.value.trim() : "";
  }
  function setTxt(id, t) {
    const el = document.getElementById(id);
    if (el) el.textContent = t;
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

  /* -------------------------------------------------
     Public API
  ------------------------------------------------- */
  window.fpb = { bkGo, s1Next, s2Next, s3Next, clrSig, proceedToCheckout };

  /* -------------------------------------------------
     Boot
  ------------------------------------------------- */
  document.addEventListener("DOMContentLoaded", () => {
    init();
    initSig();

    // Restrict phone field to valid phone characters only
    const phoneInput = document.getElementById("fpb-fphone");
    if (phoneInput) {
      phoneInput.addEventListener("input", () => {
        phoneInput.value = phoneInput.value.replace(/[^0-9+\-() ]/g, "");
      });
    }
  });
})();
