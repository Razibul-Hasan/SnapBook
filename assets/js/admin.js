/* global fpbAdmin */
(function () {
  "use strict";

  const ajax = fpbAdmin.ajaxUrl;
  const nonce = fpbAdmin.nonce;
  const sessions = fpbAdmin.sessions || []; // seeded by PHP wp_localize_script

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Helpers
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", nonce);
    Object.entries(data).forEach(([k, v]) => fd.append(k, v));
    return fetch(ajax, { method: "POST", body: fd }).then((r) => {
      if (!r.ok) throw new Error("HTTP " + r.status);
      return r.text().then((text) => {
        try {
          return JSON.parse(text);
        } catch {
          throw new Error(
            "Invalid response from server. Check for PHP errors.",
          );
        }
      });
    });
  }

  function setMsg(id, text, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    el.className = "fpb-form-msg " + (ok ? "ok" : "err");
  }

  function confirmDel(label) {
    return window.confirm('Delete "' + label + '"? This cannot be undone.');
  }

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Filter buttons (Bookings page)
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  document.querySelectorAll(".fpb-filter-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
      document
        .querySelectorAll(".fpb-filter-btn")
        .forEach((b) => b.classList.remove("active", "current"));
      btn.classList.add("active", "current");
      const filter = btn.dataset.filter;
      document.querySelectorAll(".fpb-brow").forEach((row) => {
        if (filter === "all" || row.dataset.status === filter) {
          row.style.display = "";
        } else {
          row.style.display = "none";
        }
      });
    });
  });

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Booking: status select change
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  document.querySelectorAll(".fpb-status-select").forEach((sel) => {
    sel.addEventListener("change", function () {
      const id = this.dataset.id;
      const val = this.value;
      post("fpb_admin_update_booking_status", { id, status: val }).then((r) => {
        if (r.success) {
          const badge = this.closest("tr").querySelector(".fpb-badge");
          if (badge) {
            badge.className = "fpb-badge fpb-badge-" + val;
            badge.textContent = val.charAt(0).toUpperCase() + val.slice(1);
          }
        } else {
          alert("Could not update status. Please try again.");
        }
      });
    });
  });

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Booking modal
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const bookingModal = document.getElementById("fpb-booking-modal");
  const bookingData = typeof fpbBookings !== "undefined" ? fpbBookings : [];
  if (bookingModal) {
    document.querySelectorAll(".fpb-btn-view").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.dataset.id;
        const data = bookingData.find((b) => String(b.id) === String(id));
        if (!data) return;

        const cur = data.currency || fpbAdmin.currency || "\u20ac";
        const rows = [
          ["Client", data.client_name],
          ["Email", data.client_email],
          ["Phone", data.client_phone],
          ["Country", data.client_country],
          ["Session", data.session_type],
          ["Package", data.package_name],
          ["Add-ons", data.addons_json || "\u2014"],
          ["Date", data.session_date || "\u2014"],
          ["Time", data.session_time || "\u2014"],
          ["Location", data.location_pref || "\u2014"],
          ["Total", cur + parseFloat(data.total || 0).toFixed(2)],
          ["Deposit", cur + parseFloat(data.deposit || 0).toFixed(2)],
          ["Status", data.status],
          ["WC Order", data.order_id ? "#" + data.order_id : "\u2014"],
          ["Notes", data.notes || "\u2014"],
          ["Signed by", data.signer_name || "\u2014"],
        ];

        const html = rows
          .map(
            ([k, v]) =>
              '<div class="fpb-modal-row"><span class="fpb-modal-label">' +
              k +
              "</span>" +
              '<span class="fpb-modal-val">' +
              String(v || "\u2014").replace(/</g, "&lt;") +
              "</span></div>",
          )
          .join("");

        const modalHead = bookingModal.querySelector(".fpb-modal-head span");
        const modalBody = bookingModal.querySelector(".fpb-modal-body");
        if (modalHead) modalHead.textContent = "Booking #" + id;
        if (modalBody) modalBody.innerHTML = html;
        bookingModal.style.display = "flex";
      });
    });

    bookingModal
      .querySelector(".fpb-modal-close")
      .addEventListener("click", () => {
        bookingModal.style.display = "none";
      });
    bookingModal.addEventListener("click", (e) => {
      if (e.target === bookingModal) bookingModal.style.display = "none";
    });
  }

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Sessions CRUD
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const sessionForm = document.getElementById("fpb-session-form");
  if (sessionForm) {
    sessionForm.addEventListener("submit", (e) => {
      e.preventDefault();
      const fd = new FormData(sessionForm);
      const data = Object.fromEntries(fd.entries());
      data.active = sessionForm.querySelector("[name=active]").checked
        ? "1"
        : "0";
      post("fpb_admin_save_session", data)
        .then((r) => {
          if (r.success) {
            const savedSlug = r.data?.slug;
            const sentSlug = data.slug;
            const msg =
              savedSlug && savedSlug !== sentSlug
                ? "Saved! (Slug auto-changed to \u201c" +
                  savedSlug +
                  "\u201d to avoid conflict)"
                : "Saved!";
            setMsg("fpb-session-msg", msg, true);
            setTimeout(() => location.reload(), 1200);
          } else {
            setMsg(
              "fpb-session-msg",
              r.data?.message || "Error saving.",
              false,
            );
          }
        })
        .catch((err) => {
          setMsg(
            "fpb-session-msg",
            err.message || "Network error. Please try again.",
            false,
          );
        });
    });

    document.querySelectorAll(".fpb-edit-session").forEach((btn) => {
      btn.addEventListener("click", () => {
        const d = btn.dataset;
        sessionForm.querySelector("[name=id]").value = d.id;
        sessionForm.querySelector("[name=name]").value = d.name;
        sessionForm.querySelector("[name=emoji]").value = d.emoji;
        sessionForm.querySelector("[name=slug]").value = d.slug;
        sessionForm.querySelector("[name=sort_order]").value = d.sort || "0";
        sessionForm.querySelector("[name=active]").checked = d.active === "1";
        sessionForm.querySelector("[name=name]").focus();
        setMsg("fpb-session-msg", "", true);
      });
    });

    document.querySelectorAll(".fpb-del-session").forEach((btn) => {
      btn.addEventListener("click", () => {
        if (!confirmDel(btn.dataset.name)) return;
        post("fpb_admin_delete_session", { id: btn.dataset.id })
          .then((r) => {
            if (r.success) btn.closest("tr").remove();
            else
              alert(
                r.data?.message ||
                  "Error deleting. Session may have linked packages.",
              );
          })
          .catch(() => alert("Network error. Could not delete."));
      });
    });
  }

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Packages CRUD
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const packageForm = document.getElementById("fpb-package-form");
  if (packageForm) {
    packageForm.addEventListener("submit", (e) => {
      e.preventDefault();
      const fd = new FormData(packageForm);
      const data = Object.fromEntries(fd.entries());
      data.featured = packageForm.querySelector("[name=featured]").checked
        ? "1"
        : "0";
      data.active = packageForm.querySelector("[name=active]").checked
        ? "1"
        : "0";
      post("fpb_admin_save_package", data)
        .then((r) => {
          if (r.success) {
            setMsg("fpb-package-msg", "Saved!", true);
            setTimeout(() => location.reload(), 800);
          } else {
            setMsg(
              "fpb-package-msg",
              r.data?.message || "Error saving.",
              false,
            );
          }
        })
        .catch((err) => {
          setMsg(
            "fpb-package-msg",
            err.message || "Network error. Please try again.",
            false,
          );
        });
    });

    document.querySelectorAll(".fpb-edit-package").forEach((btn) => {
      btn.addEventListener("click", () => {
        const d = btn.dataset;
        packageForm.querySelector("[name=id]").value = d.id;
        packageForm.querySelector("[name=session_id]").value = d.session;
        packageForm.querySelector("[name=name]").value = d.name;
        packageForm.querySelector("[name=price]").value = d.price;
        packageForm.querySelector("[name=duration]").value = d.duration;
        packageForm.querySelector("[name=description]").value = d.desc;
        packageForm.querySelector("[name=sort_order]").value = d.sort || "0";
        packageForm.querySelector("[name=featured]").checked =
          d.featured === "1";
        packageForm.querySelector("[name=active]").checked = d.active === "1";
        packageForm.querySelector("[name=name]").focus();
        setMsg("fpb-package-msg", "", true);
      });
    });

    document.querySelectorAll(".fpb-del-package").forEach((btn) => {
      btn.addEventListener("click", () => {
        if (!confirmDel(btn.dataset.name)) return;
        post("fpb_admin_delete_package", { id: btn.dataset.id })
          .then((r) => {
            if (r.success) btn.closest("tr").remove();
            else alert(r.data?.message || "Error deleting.");
          })
          .catch(() => alert("Network error. Could not delete."));
      });
    });
  }

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Add-ons CRUD
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const addonForm = document.getElementById("fpb-addon-form");
  if (addonForm) {
    addonForm.addEventListener("submit", (e) => {
      e.preventDefault();
      const fd = new FormData(addonForm);
      const data = Object.fromEntries(fd.entries());
      data.active = addonForm.querySelector("[name=active]").checked
        ? "1"
        : "0";
      post("fpb_admin_save_addon", data)
        .then((r) => {
          if (r.success) {
            setMsg("fpb-addon-msg", "Saved!", true);
            setTimeout(() => location.reload(), 800);
          } else {
            setMsg("fpb-addon-msg", r.data?.message || "Error saving.", false);
          }
        })
        .catch((err) => {
          setMsg(
            "fpb-addon-msg",
            err.message || "Network error. Please try again.",
            false,
          );
        });
    });

    document.querySelectorAll(".fpb-edit-addon").forEach((btn) => {
      btn.addEventListener("click", () => {
        const d = btn.dataset;
        addonForm.querySelector("[name=id]").value = d.id;
        addonForm.querySelector("[name=name]").value = d.name;
        addonForm.querySelector("[name=price]").value = d.price;
        addonForm.querySelector("[name=emoji]").value = d.emoji;
        addonForm.querySelector("[name=description]").value = d.desc;
        addonForm.querySelector("[name=sort_order]").value = d.sort || "0";
        addonForm.querySelector("[name=active]").checked = d.active === "1";
        addonForm.querySelector("[name=name]").focus();
        setMsg("fpb-addon-msg", "", true);
      });
    });

    document.querySelectorAll(".fpb-del-addon").forEach((btn) => {
      btn.addEventListener("click", () => {
        if (!confirmDel(btn.dataset.name)) return;
        post("fpb_admin_delete_addon", { id: btn.dataset.id })
          .then((r) => {
            if (r.success) btn.closest("tr").remove();
            else alert(r.data?.message || "Error deleting.");
          })
          .catch(() => alert("Network error. Could not delete."));
      });
    });
  }

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Date Slots â€” interactive admin calendar
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const calGrid = document.getElementById("fpb-admin-calGrid");
  if (calGrid) {
    const monthLabel = document.getElementById("fpb-month-label");
    const now = new Date();
    let curYear = now.getFullYear();
    let curMonth = now.getMonth() + 1;
    let dateMap = {}; // "YYYY-MM-DD" â†’ "booked"|"blocked"

    function loadDates() {
      post("fpb_admin_get_dates", {}).then((r) => {
        if (r.success) {
          dateMap = r.data;
          renderCal();
        }
      });
    }

    function renderCal() {
      const d = new Date(curYear, curMonth - 1, 1);
      const days = new Date(curYear, curMonth, 0).getDate();
      const startDow = d.getDay(); // 0=Sun
      const today = new Date();
      today.setHours(0, 0, 0, 0);

      const months = [
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
      monthLabel.textContent = months[curMonth - 1] + " " + curYear;

      let html = "";
      for (let i = 0; i < startDow; i++)
        html += '<div class="fpb-cal-admin-day empty"></div>';

      for (let day = 1; day <= days; day++) {
        const ds =
          curYear +
          "-" +
          String(curMonth).padStart(2, "0") +
          "-" +
          String(day).padStart(2, "0");
        const dt = new Date(curYear, curMonth - 1, day);
        const past = dt < today;
        const st = dateMap[ds] || "available";
        let cls = "fpb-cal-admin-day " + st;
        if (past) cls += " past";
        html +=
          '<div class="' + cls + '" data-date="' + ds + '">' + day + "</div>";
      }
      calGrid.innerHTML = html;

      calGrid
        .querySelectorAll(".fpb-cal-admin-day:not(.empty):not(.past)")
        .forEach((cell) => {
          cell.addEventListener("click", () => toggleDate(cell));
        });
    }

    function toggleDate(cell) {
      const date = cell.dataset.date;
      post("fpb_admin_toggle_date", { date }).then((r) => {
        if (r.success) {
          const st = r.data.status;
          dateMap[date] = st === "available" ? undefined : st;
          if (st === "available") delete dateMap[date];
          cell.className = "fpb-cal-admin-day " + st;
        }
      });
    }

    document.getElementById("fpb-prev-month").addEventListener("click", () => {
      curMonth--;
      if (curMonth < 1) {
        curMonth = 12;
        curYear--;
      }
      renderCal();
    });
    document.getElementById("fpb-next-month").addEventListener("click", () => {
      curMonth++;
      if (curMonth > 12) {
        curMonth = 1;
        curYear++;
      }
      renderCal();
    });

    loadDates();
  }

  /* â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€
     Settings: auto-slug from name (Session Types)
  â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
  const nameInput = document.getElementById("fpb-session-name");
  const slugInput = document.getElementById("fpb-session-slug");
  if (nameInput && slugInput) {
    nameInput.addEventListener("input", () => {
      if (!slugInput.dataset.manual) {
        slugInput.value = nameInput.value
          .toLowerCase()
          .replace(/[^a-z0-9]+/g, "-")
          .replace(/^-|-$/g, "");
      }
    });
    slugInput.addEventListener("input", () => {
      slugInput.dataset.manual = "1";
    });
  }
})();
