/* global fpbAdmin, sbAdmin, fpbBookings, sbBookings */
(function () {
  "use strict";

  const adminData = window.fpbAdmin || window.sbAdmin || {};
  const ajaxUrl = adminData.ajaxUrl || "";
  const nonce = adminData.nonce || "";

  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", nonce);
    Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));

    return fetch(ajaxUrl, { method: "POST", body: fd })
      .then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.text();
      })
      .then((text) => {
        try {
          return JSON.parse(text);
        } catch (_e) {
          throw new Error("Invalid JSON response");
        }
      });
  }

  function escHtml(s) {
    return String(s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  function setMsg(id, text, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text || "";
    el.className = "fpb-form-msg " + (ok ? "ok" : "err");
  }

  function formToObject(form) {
    const fd = new FormData(form);
    const data = {};
    fd.forEach((value, key) => {
      data[key] = value;
    });
    return data;
  }

  function bindCrudForm(formId, saveAction, msgId, checkboxNames) {
    const form = document.getElementById(formId);
    if (!form) return;

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      const data = formToObject(form);

      (checkboxNames || []).forEach((name) => {
        const cb = form.querySelector('[name="' + name + '"]');
        data[name] = cb && cb.checked ? "1" : "0";
      });

      post(saveAction, data)
        .then((res) => {
          if (!res.success) {
            setMsg(
              msgId,
              (res.data && res.data.message) || "Save failed.",
              false,
            );
            return;
          }
          setMsg(msgId, "Saved.", true);
          window.location.reload();
        })
        .catch(() => {
          setMsg(msgId, "Network or server error. Please try again.", false);
        });
    });
  }

  function bindDeleteButtons(selector, action) {
    document.querySelectorAll(selector).forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.dataset.id || "0";
        const name = btn.dataset.name || "item";
        if (!window.confirm('Delete "' + name + '"? This cannot be undone.')) {
          return;
        }

        post(action, { id })
          .then((res) => {
            if (!res.success) {
              window.alert(
                (res.data && res.data.message) || "Could not delete.",
              );
              return;
            }
            const row = btn.closest("tr");
            if (row) row.remove();
          })
          .catch(() => {
            window.alert("Network error. Could not delete.");
          });
      });
    });
  }

  function bindBookingStatusUpdate() {
    document
      .querySelectorAll(".fpb-status-select, .sb-status-select")
      .forEach((sel) => {
        sel.addEventListener("change", function () {
          const id = this.dataset.id;
          const status = this.value;

          post("fpb_admin_update_booking_status", { id, status })
            .then((res) => {
              if (!res.success) {
                window.alert("Could not update status.");
                return;
              }
              const badge =
                this.closest("tr")?.querySelector(".fpb-badge") ||
                this.closest("tr")?.querySelector(".sb-badge");
              if (badge) {
                const base =
                  badge.className.indexOf("sb-badge") !== -1
                    ? "sb-badge"
                    : "fpb-badge";
                badge.className = base + " " + base + "-" + status;
                badge.textContent =
                  status.charAt(0).toUpperCase() + status.slice(1);
              }
            })
            .catch(() => {
              window.alert("Network error. Could not update status.");
            });
        });
      });
  }

  function bindBookingModal() {
    const modal =
      document.getElementById("fpb-booking-modal") ||
      document.getElementById("sb-booking-modal");
    if (!modal) return;

    const sourceBookings = window.fpbBookings || window.sbBookings;
    const data = Array.isArray(sourceBookings) ? sourceBookings : [];
    const modalTitle = modal.querySelector(".fpb-modal-head span");
    const modalBody = modal.querySelector(".fpb-modal-body");
    const closeBtn = modal.querySelector(".fpb-modal-close");

    function closeModal() {
      modal.style.display = "none";
    }

    closeBtn?.addEventListener("click", closeModal);
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeModal();
    });

    document.querySelectorAll(".fpb-btn-view").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.dataset.id;
        const b = data.find((x) => String(x.id) === String(id));
        if (!b || !modalTitle || !modalBody) return;

        const rows = [
          ["Client", b.client_name],
          ["Email", b.client_email],
          ["Phone", b.client_phone],
          ["Country", b.client_country],
          ["Session", b.session_type],
          ["Package", b.package_name],
          ["Add-ons", b.addons_json || "-"],
          ["Date", b.session_date || "-"],
          ["Time", b.session_time || "-"],
          ["Location", b.location_pref || "-"],
          ["Total", b.total || "0"],
          ["Deposit", b.deposit || "0"],
          ["Status", b.status || "pending"],
          ["WC Order", b.order_id ? "#" + b.order_id : "-"],
          ["Notes", b.notes || "-"],
          ["Signed by", b.signer_name || "-"],
        ];

        modalBody.innerHTML = rows
          .map(
            ([k, v]) =>
              '<div class="fpb-modal-row"><span class="fpb-modal-label">' +
              escHtml(k) +
              '</span><span class="fpb-modal-val">' +
              escHtml(String(v)) +
              "</span></div>",
          )
          .join("");

        modalTitle.textContent = "Booking #" + id;
        modal.style.display = "flex";
      });
    });
  }

  function bindSessionSlugHelper() {
    const nameInput = document.getElementById("fpb-session-name");
    const slugInput = document.getElementById("fpb-session-slug");
    if (!nameInput || !slugInput) return;

    let manualSlug = Boolean(slugInput.value && slugInput.value.trim());

    slugInput.addEventListener("input", () => {
      manualSlug = true;
    });

    nameInput.addEventListener("input", () => {
      if (manualSlug) return;
      slugInput.value = String(nameInput.value || "")
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "-")
        .replace(/^-|-$/g, "");
    });
  }

  function bindDateSlotsCalendar() {
    const grid = document.getElementById("fpb-admin-calGrid");
    const label = document.getElementById("fpb-month-label");
    const prevBtn = document.getElementById("fpb-prev-month");
    const nextBtn = document.getElementById("fpb-next-month");
    const msg = document.getElementById("fpb-dates-msg");

    if (!grid || !label || !prevBtn || !nextBtn) return;

    let dateMap = {};
    const view = new Date();
    view.setDate(1);

    function toDateStr(y, m, d) {
      return (
        y + "-" + String(m).padStart(2, "0") + "-" + String(d).padStart(2, "0")
      );
    }

    function renderCalendar() {
      const year = view.getFullYear();
      const month = view.getMonth();
      const days = new Date(year, month + 1, 0).getDate();
      const startDow = new Date(year, month, 1).getDay();
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      label.textContent = view.toLocaleDateString(undefined, {
        month: "long",
        year: "numeric",
      });

      let html = "";
      for (let i = 0; i < startDow; i++) {
        html += '<div class="fpb-cal-admin-day empty"></div>';
      }

      for (let d = 1; d <= days; d++) {
        const ds = toDateStr(year, month + 1, d);
        const dt = new Date(year, month, d);
        const isPast = dt < today;
        const status = dateMap[ds] || "available";

        let cls = "fpb-cal-admin-day " + status;
        if (isPast) cls += " past";
        if (dt.getTime() === today.getTime()) cls += " today";

        html +=
          '<div class="' + cls + '" data-date="' + ds + '">' + d + "</div>";
      }

      grid.innerHTML = html;

      grid.querySelectorAll(".fpb-cal-admin-day").forEach((cell) => {
        if (cell.classList.contains("empty") || cell.classList.contains("past"))
          return;

        cell.addEventListener("click", () => {
          const ds = cell.dataset.date;
          if (!ds) return;

          post("fpb_admin_toggle_date", { date: ds })
            .then((res) => {
              if (!res.success) {
                if (msg)
                  msg.textContent =
                    (res.data && res.data.message) || "Could not update date.";
                return;
              }

              if (res.data.status === "available") {
                delete dateMap[ds];
              } else {
                dateMap[ds] = res.data.status;
              }

              if (msg) msg.textContent = ds + " -> " + res.data.status;
              renderCalendar();
            })
            .catch(() => {
              if (msg) msg.textContent = "Network error. Please try again.";
            });
        });
      });
    }

    prevBtn.addEventListener("click", () => {
      view.setMonth(view.getMonth() - 1);
      renderCalendar();
    });

    nextBtn.addEventListener("click", () => {
      view.setMonth(view.getMonth() + 1);
      renderCalendar();
    });

    post("fpb_admin_get_dates", {})
      .then((res) => {
        if (res.success) {
          dateMap = res.data || {};
        } else if (msg) {
          msg.textContent =
            (res.data && res.data.message) || "Could not load dates.";
        }
        renderCalendar();
      })
      .catch(() => {
        if (msg) msg.textContent = "Could not load dates.";
        renderCalendar();
      });
  }

  window.fpbAdminCreateProduct = function () {
    window.alert("WooCommerce product auto-create is not configured yet.");
  };

  bindBookingStatusUpdate();
  bindBookingModal();
  bindSessionSlugHelper();
  bindCrudForm(
    "fpb-session-form",
    "fpb_admin_save_session",
    "fpb-session-msg",
    ["active"],
  );
  bindCrudForm(
    "fpb-package-form",
    "fpb_admin_save_package",
    "fpb-package-msg",
    ["featured", "active"],
  );
  bindCrudForm("fpb-addon-form", "fpb_admin_save_addon", "fpb-addon-msg", [
    "active",
  ]);
  bindDeleteButtons(".fpb-del-session", "fpb_admin_delete_session");
  bindDeleteButtons(".fpb-del-package", "fpb_admin_delete_package");
  bindDeleteButtons(".fpb-del-addon", "fpb_admin_delete_addon");
  bindDateSlotsCalendar();
})();
