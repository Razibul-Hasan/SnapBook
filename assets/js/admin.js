/* global snapbookAdmin, snapbookBookings */
(function () {
  "use strict";

  const adminData = window.snapbookAdmin || {};
  const ajaxUrl = adminData.ajaxUrl || "";
  const nonce = adminData.nonce || "";
  // Translated from PHP; the fallbacks keep things readable if the
  // localized data is ever missing.
  const strings = Object.assign(
    {
      noFile: "No file selected.",
      mediaUnavailable: "Media library unavailable.",
      pickTitle: "Select or upload the order email attachment",
      pickButton: "Use this file",
    },
    adminData.i18n || {},
  );

  function post(action, data) {
    const fd = new FormData();
    fd.append("action", action);
    fd.append("nonce", nonce);
    Object.entries(data || {}).forEach(([k, v]) => {
      if (Array.isArray(v)) {
        v.forEach((item) => fd.append(k, item));
      } else {
        fd.append(k, v);
      }
    });

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

  // Pending auto-dismiss timers, keyed by message element id.
  const msgTimers = {};

  function setMsg(id, text, ok) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text || "";
    el.className = "fpb-form-msg " + (ok ? "fpb-ok" : "fpb-err");

    // Any previous countdown belongs to a message that is now gone.
    if (msgTimers[id]) {
      clearTimeout(msgTimers[id]);
      delete msgTimers[id];
    }
    if (!text || !ok) return;

    // A success note has done its job once it has been read, so it fades out
    // on its own. Errors stay until the next attempt — they need acting on.
    msgTimers[id] = setTimeout(function () {
      delete msgTimers[id];
      el.classList.add("fpb-msg-fade");
      setTimeout(function () {
        // Only clear if nothing new was shown in the meantime.
        if (!el.classList.contains("fpb-msg-fade")) return;
        el.textContent = "";
        el.className = "fpb-form-msg";
      }, 400);
    }, 4000);
  }

  // Copy text to the clipboard, with a fallback for plain-HTTP admin where
  // navigator.clipboard is unavailable. Calls done(true|false).
  function copyToClipboard(text, done) {
    const cb = typeof done === "function" ? done : function () {};
    function fallback() {
      const ta = document.createElement("textarea");
      ta.value = text;
      ta.style.position = "fixed";
      ta.style.top = "-1000px";
      ta.style.opacity = "0";
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      let ok = false;
      try {
        ok = document.execCommand("copy");
      } catch (_e) {
        ok = false;
      }
      document.body.removeChild(ta);
      cb(ok);
    }
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(
        () => cb(true),
        fallback,
      );
    } else {
      fallback();
    }
  }

  function formatStatusLabel(status) {
    if (String(status) === "pending") {
      return "Pending Payment";
    }
    if (String(status) === "confirmed") {
      return "Processing";
    }
    return String(status)
      .replace(/_/g, " ")
      .replace(/\b\w/g, (ch) => ch.toUpperCase());
  }

  function formToObject(form) {
    const fd = new FormData(form);
    const data = {};
    fd.forEach((value, key) => {
      if (key.endsWith("[]")) {
        // Multi-value fields (e.g. multi-selects) are kept as arrays.
        if (!Array.isArray(data[key])) data[key] = [];
        data[key].push(value);
      } else {
        data[key] = value;
      }
    });
    return data;
  }

  function bindCrudForm(formId, saveAction, msgId, checkboxNames) {
    const form = document.getElementById(formId);
    if (!form) return;

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      // Sync TinyMCE editors (visual mode) back to their textareas so
      // FormData picks up the rich-text content.
      if (window.tinymce) window.tinymce.triggerSave();
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

  // Add-on "Applies To" checklist: "All Packages" and individual package
  // ticks are mutually exclusive; unticking everything falls back to "All".
  function bindPackageChecklist() {
    const list = document.getElementById("fpb-addon-pkg-list");
    if (!list) return;
    const boxes = Array.from(list.querySelectorAll('input[type="checkbox"]'));
    const allBox = boxes.find((b) => b.value === "0");
    if (!allBox) return;

    list.addEventListener("change", (e) => {
      const target = e.target;
      if (!target || target.type !== "checkbox") return;

      if (target === allBox && allBox.checked) {
        boxes.forEach((b) => {
          if (b !== allBox) b.checked = false;
        });
      } else if (target !== allBox && target.checked) {
        allBox.checked = false;
      }

      if (!boxes.some((b) => b.checked)) {
        allBox.checked = true;
      }
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

  // Packages list: copy the shareable ?package= link and reveal it in a
  // read-only field so it can also be grabbed manually.
  function bindPackageLinkCopy() {
    document.querySelectorAll(".fpb-copy-link").forEach((btn) => {
      btn.addEventListener("click", () => {
        const url = btn.dataset.link || "";
        if (!url) return;

        const cell = btn.closest("td");
        const wrap = cell ? cell.querySelector(".fpb-share-link") : null;
        const field = wrap ? wrap.querySelector(".fpb-share-link-field") : null;
        if (wrap) wrap.hidden = false;

        function flash(text) {
          if (btn.dataset.copyTimer) {
            clearTimeout(parseInt(btn.dataset.copyTimer, 10));
          }
          if (!btn.dataset.orig) btn.dataset.orig = btn.textContent;
          btn.textContent = text;
          btn.dataset.copyTimer = String(
            setTimeout(() => {
              btn.textContent = btn.dataset.orig;
              delete btn.dataset.copyTimer;
            }, 2000),
          );
        }

        function fallbackCopy() {
          // Plain-HTTP admin has no navigator.clipboard — select the
          // read-only field and use the legacy copy command.
          let ok = false;
          if (field) {
            field.focus();
            field.select();
            try {
              ok = document.execCommand("copy");
            } catch (_e) {
              ok = false;
            }
          }
          flash(ok ? "Copied ✓" : "Press Ctrl+C to copy");
        }

        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard
            .writeText(url)
            .then(() => flash("Copied ✓"))
            .catch(fallbackCopy);
        } else {
          fallbackCopy();
        }
      });
    });
  }

  function bindRowActionMenus() {
    const toggles = document.querySelectorAll(".fpb-row-actions-toggle");
    if (!toggles.length) return;

    function positionMenu(toggle, menu) {
      if (!menu || menu.hidden) return;

      menu.classList.remove("fpb-row-actions-menu--up");
      const viewportWidth =
        window.innerWidth || document.documentElement.clientWidth;
      const viewportHeight =
        window.innerHeight || document.documentElement.clientHeight;
      const toggleRect = toggle.getBoundingClientRect();
      const menuRect = menu.getBoundingClientRect();

      let left = Math.round(toggleRect.right - menuRect.width);
      const minLeft = 8;
      const maxLeft = Math.max(minLeft, viewportWidth - menuRect.width - 8);
      if (left < minLeft) left = minLeft;
      if (left > maxLeft) left = maxLeft;

      let top = Math.round(toggleRect.bottom + 6);
      if (top + menuRect.height > viewportHeight - 8) {
        menu.classList.add("fpb-row-actions-menu--up");
        top = Math.round(toggleRect.top - menuRect.height - 6);
      }
      if (top < 8) {
        top = 8;
      }

      menu.style.left = left + "px";
      menu.style.top = top + "px";
    }

    function repositionOpenMenus() {
      document.querySelectorAll(".fpb-row-actions-toggle").forEach((btn) => {
        if (btn.getAttribute("aria-expanded") !== "true") return;
        const menuId = btn.getAttribute("aria-controls") || "";
        const menu = menuId ? document.getElementById(menuId) : null;
        if (menu) {
          positionMenu(btn, menu);
        }
      });
    }

    function closeMenu(btn) {
      const menuId = btn.getAttribute("aria-controls") || "";
      const menu = menuId ? document.getElementById(menuId) : null;
      btn.setAttribute("aria-expanded", "false");
      if (menu) {
        menu.hidden = true;
        menu.style.left = "";
        menu.style.top = "";
      }
    }

    function closeAll(exceptToggle) {
      document.querySelectorAll(".fpb-row-actions-toggle").forEach((btn) => {
        if (exceptToggle && btn === exceptToggle) return;
        closeMenu(btn);
      });
    }

    toggles.forEach((toggle) => {
      const menuId = toggle.getAttribute("aria-controls") || "";
      const menu = menuId ? document.getElementById(menuId) : null;
      if (!menu) return;

      toggle.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        const open = toggle.getAttribute("aria-expanded") === "true";
        closeAll(toggle);
        toggle.setAttribute("aria-expanded", open ? "false" : "true");
        menu.hidden = open;
        if (!open) {
          positionMenu(toggle, menu);
        }
      });

      menu.addEventListener("click", (e) => {
        e.stopPropagation();
      });
    });

    document.addEventListener("click", () => closeAll());
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        closeAll();
      }
    });

    window.addEventListener("resize", repositionOpenMenus);
    window.addEventListener("scroll", repositionOpenMenus, true);
  }

  function bindBookingStatusUpdate() {
    document
      .querySelectorAll(".sb-status-select")
      .forEach((sel) => {
        const row = sel.closest("tr");
        const hintEl = row ? row.querySelector(".fpb-status-hint") : null;
        const dueOrderId = parseInt(sel.dataset.dueOrderId || "0", 10);
        const targetOrderId = parseInt(sel.dataset.targetOrderId || "0", 10);
        const targetLabel = dueOrderId > 0 ? "Balance order" : "Main order";

        sel.addEventListener("change", function () {
          const id = this.dataset.id;
          const status = this.value;
          const previousValue = this.dataset.prevValue || this.value;

          this.disabled = true;
          if (hintEl) {
            hintEl.classList.remove("fpb-ok", "fpb-err");
            hintEl.textContent = "Saving...";
          }

          post("snapbook_admin_update_booking_status", { id, status })
            .then((res) => {
              if (!res.success) {
                this.value = previousValue;
                if (hintEl) {
                  hintEl.classList.add("fpb-err");
                  hintEl.textContent = "Could not update status.";
                }
                window.alert("Could not update status.");
                return;
              }

              this.dataset.prevValue = status;
              const badge = this.closest("tr")?.querySelector(".sb-badge");
              if (badge) {
                badge.className = "sb-badge sb-badge-" + status;
                badge.textContent = formatStatusLabel(status);
              }

              const data = res.data || {};
              const rowEl = this.closest("tr");
              if (rowEl) {
                const mainOrderId = parseInt(data.main_order_id || 0, 10);
                const dueOrderId = parseInt(data.due_order_id || 0, 10);
                const mainOrderStatus = String(data.main_order_status || "");
                const dueOrderStatus = String(data.due_order_status || "");

                if (mainOrderId > 0 && mainOrderStatus) {
                  const mainSel = rowEl.querySelector(
                    '.fpb-order-status-select[data-order-id="' +
                      mainOrderId +
                      '"]',
                  );
                  if (mainSel) {
                    mainSel.value = mainOrderStatus;
                    mainSel.dataset.prevValue = mainOrderStatus;
                  }
                }

                if (dueOrderId > 0 && dueOrderStatus) {
                  const dueSel = rowEl.querySelector(
                    '.fpb-order-status-select[data-order-id="' +
                      dueOrderId +
                      '"]',
                  );
                  if (dueSel) {
                    dueSel.value = dueOrderStatus;
                    dueSel.dataset.prevValue = dueOrderStatus;
                  }
                }
              }

              if (hintEl) {
                hintEl.classList.remove("fpb-err");
                hintEl.classList.add("fpb-ok");
                hintEl.textContent =
                  targetOrderId > 0
                    ? targetLabel + " #" + targetOrderId + " updated"
                    : "Booking status updated";
              }
            })
            .catch(() => {
              this.value = previousValue;
              if (hintEl) {
                hintEl.classList.add("fpb-err");
                hintEl.textContent = "Network error while saving.";
              }
              window.alert("Network error. Could not update status.");
            })
            .finally(() => {
              this.disabled = false;
            });
        });

        sel.dataset.prevValue = sel.value;
      });
  }

  function bindQuickPaymentActions() {
    document.querySelectorAll(".fpb-quick-status").forEach((btn) => {
      btn.addEventListener("click", () => {
        const row = btn.closest("tr");
        if (!row) return;

        const select = row.querySelector(".sb-status-select");
        if (!select) return;

        const nextStatus = btn.dataset.status || "";
        if (!nextStatus || select.value === nextStatus) return;

        select.value = nextStatus;
        select.dispatchEvent(new Event("change", { bubbles: true }));
      });
    });
  }

  function bindWooOrderStatusControls() {
    document.querySelectorAll(".fpb-order-status-select").forEach((sel) => {
      const previous = sel.value;
      sel.dataset.prevValue = previous;

      sel.addEventListener("change", function () {
        const orderId = this.dataset.orderId || "0";
        const status = this.value;
        const prevValue = this.dataset.prevValue || this.value;

        this.disabled = true;
        post("snapbook_admin_update_wc_order_status", { order_id: orderId, status })
          .then((res) => {
            if (!res.success) {
              this.value = prevValue;
              window.alert(
                (res.data && res.data.message) ||
                  "Could not update WooCommerce order status.",
              );
              return;
            }

            this.dataset.prevValue = status;

            // Update badge and hint in the same row (no reload)
            const row = this.closest("tr");
            const badge = row?.querySelector(".sb-badge");
            if (badge) {
              badge.className = "sb-badge sb-badge-" + status;
              badge.textContent = formatStatusLabel(status);
            }
            const hintEl = row ? row.querySelector(".fpb-status-hint") : null;
            if (hintEl) {
              hintEl.classList.remove("fpb-err");
              hintEl.classList.add("fpb-ok");
              hintEl.textContent = "Order #" + orderId + " updated";
            }
          })
          .catch(() => {
            this.value = prevValue;
            window.alert("Network error. Could not update WooCommerce order.");
          })
          .finally(() => {
            this.disabled = false;
          });
      });
    });
  }

  // Row-action "Copy Payment Link" — copies the balance order's pay URL.
  function bindBalancePayLinkCopy() {
    document.querySelectorAll(".fpb-copy-pay-link").forEach((btn) => {
      btn.addEventListener("click", () => {
        const link = btn.dataset.link || "";
        if (!link) return;
        if (!btn.dataset.orig) btn.dataset.orig = btn.textContent;
        if (btn.dataset.copyTimer) clearTimeout(parseInt(btn.dataset.copyTimer, 10));
        copyToClipboard(link, (ok) => {
          btn.textContent = ok ? "Link copied ✓" : "Press Ctrl+C to copy";
          btn.dataset.copyTimer = String(
            setTimeout(() => {
              btn.textContent = btn.dataset.orig;
              delete btn.dataset.copyTimer;
            }, 2000),
          );
        });
      });
    });
  }

  function bindBalanceReminderButtons() {
    document.querySelectorAll(".fpb-send-balance-reminder").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.dataset.id || "0";
        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = "Sending...";

        post("snapbook_admin_send_balance_reminder", { id })
          .then((res) => {
            if (!res.success) {
              window.alert(
                (res.data && res.data.message) || "Could not send reminder.",
              );
              btn.disabled = false;
              btn.textContent = originalText;
              return;
            }

            btn.textContent = "Reminder sent";
          })
          .catch(() => {
            window.alert("Network error. Could not send reminder.");
            btn.disabled = false;
            btn.textContent = originalText;
          });
      });
    });
  }

  // Booking View modal — payment panel for partial (deposit) bookings.
  function renderPaymentPanelHtml(pay, bookingId) {
    if (!pay || !pay.is_partial) return "";

    const cur = String(pay.currency || "");
    const money = (amount) => escHtml(cur) + Number(amount || 0).toFixed(2);
    const balancePaid = !!pay.balance_paid;
    const statusLabel = balancePaid
      ? "Paid"
      : pay.due_status_label || "Pending";
    const pct = pay.pct ? " (" + pay.pct + "%)" : "";

    let actions = "";
    if (!balancePaid && pay.pay_link) {
      actions +=
        '<button type="button" class="button button-primary fpb-modal-copy-link" data-link="' +
        escHtml(pay.pay_link) +
        '">Copy Payment Link</button>';
      actions +=
        '<button type="button" class="button fpb-modal-send-email" data-id="' +
        escHtml(String(bookingId)) +
        '">Send Balance Email</button>';
    }
    if (pay.edit_link) {
      actions +=
        '<a class="button fpb-modal-open-order" href="' +
        escHtml(pay.edit_link) +
        '" target="_blank" rel="noopener">Open Balance Order</a>';
    }

    return (
      '<div class="fpb-modal-pay">' +
      '<div class="fpb-modal-pay-head">Payment</div>' +
      '<div class="fpb-modal-pay-grid">' +
      '<div class="fpb-modal-pay-cell"><span>Total</span><strong>' +
      money(pay.total) +
      "</strong></div>" +
      '<div class="fpb-modal-pay-cell"><span>Deposit paid' +
      pct +
      "</span><strong>" +
      money(pay.deposit) +
      "</strong></div>" +
      '<div class="fpb-modal-pay-cell"><span>Balance</span><strong class="' +
      (balancePaid ? "fpb-bal-paid" : "fpb-bal-due") +
      '">' +
      money(pay.balance) +
      " · " +
      escHtml(statusLabel) +
      "</strong></div>" +
      "</div>" +
      (actions
        ? '<div class="fpb-modal-pay-actions">' + actions + "</div>"
        : "") +
      '<p class="fpb-modal-pay-msg" aria-live="polite"></p>' +
      "</div>"
    );
  }

  function bindModalPaymentActions(container) {
    if (!container) return;
    const msg = container.querySelector(".fpb-modal-pay-msg");
    function say(text, ok) {
      if (!msg) return;
      msg.textContent = text;
      msg.className = "fpb-modal-pay-msg " + (ok ? "fpb-ok" : "fpb-err");
    }

    const copyBtn = container.querySelector(".fpb-modal-copy-link");
    if (copyBtn) {
      copyBtn.addEventListener("click", () => {
        copyToClipboard(copyBtn.dataset.link || "", (ok) => {
          say(
            ok
              ? "Payment link copied to clipboard."
              : "Could not copy — use Open Balance Order to grab the link.",
            ok,
          );
        });
      });
    }

    const emailBtn = container.querySelector(".fpb-modal-send-email");
    if (emailBtn) {
      emailBtn.addEventListener("click", () => {
        const bid = emailBtn.dataset.id || "0";
        const orig = emailBtn.textContent;
        emailBtn.disabled = true;
        emailBtn.textContent = "Sending…";
        post("snapbook_admin_send_balance_reminder", { id: bid })
          .then((res) => {
            if (res.success) {
              say("Balance email sent to the customer.", true);
              emailBtn.textContent = "Email sent ✓";
            } else {
              say((res.data && res.data.message) || "Could not send email.", false);
              emailBtn.textContent = orig;
              emailBtn.disabled = false;
            }
          })
          .catch(() => {
            say("Network error. Could not send email.", false);
            emailBtn.textContent = orig;
            emailBtn.disabled = false;
          });
      });
    }
  }

  function bindBookingModal() {
    const modal = document.getElementById("sb-booking-modal");
    if (!modal) return;

    const modalTitle = modal.querySelector(".sb-modal-head span");
    const modalBody = modal.querySelector(".sb-modal-body");
    const closeBtn = modal.querySelector(".sb-modal-close");

    function closeModal() {
      modal.style.display = "none";
    }

    closeBtn?.addEventListener("click", closeModal);
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeModal();
    });

    document.querySelectorAll(".sb-btn-view").forEach((btn) => {
      btn.addEventListener("click", () => {
        const id = btn.dataset.id;
        // Look up booking data at click time, not binding time
        const sourceBookings = window.snapbookBookings;
        const data = Array.isArray(sourceBookings) ? sourceBookings : [];
        const b = data.find((x) => String(x.id) === String(id));
        if (!b || !modalTitle || !modalBody) {
          console.warn(
            "[SnapBook] View button: missing booking data, modal, or selectors",
            {
              bookingFound: !!b,
              id,
              dataLength: data.length,
              modalTitle: !!modalTitle,
              modalBody: !!modalBody,
            },
          );
          return;
        }

        const rows = [];

        const checkoutFields =
          b.checkout_fields && typeof b.checkout_fields === "object"
            ? b.checkout_fields
            : {};
        const checkoutFieldLabels = {
          billing_first_name: "First Name",
          billing_last_name: "Last Name",
          billing_company: "Company",
          billing_country: "Country Code",
          billing_country_name: "Country",
          billing_state: "State",
          billing_city: "City",
          billing_postcode: "Postcode",
          billing_address_1: "Address 1",
          billing_address_2: "Address 2",
          billing_phone: "Phone",
          billing_email: "Email",
          billing_event_date: "Event Date",
          billing_event_time: "Event Time",
          billing_hotel_place: "Hotel / Place",
          billing_participants: "Participants",
          billing_room_number: "Room Number",
          billing_stay_period: "Stay Period",
          order_customer_note: "Order Note",
        };

        const checkoutFieldOrder = [
          "billing_first_name",
          "billing_last_name",
          "billing_company",
          "billing_country_name",
          "billing_country",
          "billing_state",
          "billing_city",
          "billing_postcode",
          "billing_address_1",
          "billing_address_2",
          "billing_phone",
          "billing_email",
          "billing_event_date",
          "billing_event_time",
          "billing_hotel_place",
          "billing_participants",
          "billing_room_number",
          "billing_stay_period",
          "order_customer_note",
        ];

        checkoutFieldOrder.forEach((key) => {
          const value = checkoutFields[key];
          rows.push([checkoutFieldLabels[key] || key, value || "-"]);
        });

        Object.keys(checkoutFields)
          .filter((key) => !checkoutFieldOrder.includes(key))
          .forEach((key) => {
            const value = checkoutFields[key];
            rows.push([key, value || "-"]);
          });

        if (!rows.length) {
          rows.push(["Checkout", "No checkout field data found."]);
        }

        const paymentHtml = renderPaymentPanelHtml(b.fpb_payment, id);
        modalBody.innerHTML =
          paymentHtml +
          rows
            .map(
              ([k, v]) =>
                '<div class="fpb-modal-row"><span class="fpb-modal-label">' +
                escHtml(k) +
                '</span><span class="fpb-modal-val">' +
                escHtml(String(v)) +
                "</span></div>",
            )
            .join("");

        bindModalPaymentActions(modalBody.querySelector(".fpb-modal-pay"));

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

  function bindCheckoutFieldBuilder() {
    const addBtn = document.getElementById("fpb-ccf-add");
    const rows = document.getElementById("fpb-ccf-rows");
    const tpl = document.getElementById("fpb-ccf-row-template");
    if (!addBtn || !rows || !tpl) return;

    let counter = 0;
    addBtn.addEventListener("click", () => {
      counter++;
      const key = "new_" + Date.now() + "_" + counter;
      const holder = document.createElement("tbody");
      holder.innerHTML = tpl.innerHTML.replace(/__KEY__/g, key).trim();
      const row = holder.firstElementChild;
      if (row) {
        rows.appendChild(row);
        const labelInput = row.querySelector('input[type="text"]');
        if (labelInput) labelInput.focus();
      }
    });

    rows.addEventListener("click", (e) => {
      const btn = e.target.closest(".fpb-ccf-remove");
      if (!btn) return;
      const row = btn.closest("tr");
      if (row) row.remove();
    });
  }

  /* Order Email attachment — WP media library picker. The hidden input
     holds the attachment ID and is serialized with the settings form. */
  function bindOrderEmailAttachment() {
    const pick = document.getElementById("fpb-order-email-attachment-pick");
    const remove = document.getElementById("fpb-order-email-attachment-remove");
    const field = document.getElementById("fpb-order-email-attachment-id");
    const label = document.getElementById("fpb-order-email-attachment-name");
    if (!field) return;

    // Filenames are user data — build the node instead of using innerHTML.
    function setLabel(text) {
      if (!label) return;
      label.textContent = "";
      const strong = document.createElement("strong");
      strong.textContent = text;
      label.appendChild(strong);
    }

    // Bound before the media check below: clearing the attachment only resets
    // a hidden field, so it must keep working even when the media library
    // failed to load and picking a new file is impossible.
    if (remove) {
      remove.addEventListener("click", () => {
        field.value = "0";
        setLabel(strings.noFile);
        remove.style.display = "none";
      });
    }

    if (!pick) return;
    if (!window.wp || !window.wp.media) {
      pick.disabled = true;
      pick.title = strings.mediaUnavailable;
      return;
    }

    let frame = null;
    pick.addEventListener("click", () => {
      if (!frame) {
        frame = wp.media({
          title: strings.pickTitle,
          button: { text: strings.pickButton },
          multiple: false,
        });
        frame.on("select", () => {
          const file = frame.state().get("selection").first().toJSON();
          field.value = file.id;
          setLabel(file.filename || file.title || "");
          if (remove) remove.style.display = "";
        });
      }
      frame.open();
    });
  }

  // The Frontend page posts normally (no AJAX), but the contract editor still
  // needs an explicit sync so the visual-mode content reaches $_POST.
  function bindFrontendForm() {
    const form = document.getElementById("fpb-frontend-form");
    if (!form) return;
    form.addEventListener("submit", () => {
      if (window.tinymce) window.tinymce.triggerSave();
    });
  }

  function bindSettingsForm() {
    const form = document.getElementById("fpb-settings-form");
    if (!form) return;

    form.addEventListener("submit", (e) => {
      e.preventDefault();
      // Sync TinyMCE (the Order Email editor) back to its textarea so
      // FormData picks up the rich-text content.
      if (window.tinymce) window.tinymce.triggerSave();
      const data = formToObject(form);

      post("snapbook_admin_save_settings", data)
        .then((res) => {
          if (!res.success) {
            setMsg(
              "fpb-settings-msg",
              (res.data && res.data.message) || "Save failed.",
              false,
            );
            return;
          }

          setMsg("fpb-settings-msg", "Settings saved.", true);
        })
        .catch(() => {
          setMsg(
            "fpb-settings-msg",
            "Network or server error. Please try again.",
            false,
          );
        });
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
    // Only one toggle request in flight at a time. Each toggle reads the
    // date's current DB row and advances it (available→booked→blocked→
    // available); firing several at once (a fast triple-click) makes them
    // race on the same row, so the date can get stuck instead of cycling
    // back to available. Serializing the requests keeps the cycle correct.
    let togglePending = false;
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
        html += '<div class="fpb-cal-admin-day fpb-day-empty"></div>';
      }

      for (let d = 1; d <= days; d++) {
        const ds = toDateStr(year, month + 1, d);
        const dt = new Date(year, month, d);
        const isPast = dt < today;
        const status = dateMap[ds] || "available";

        let cls = "fpb-cal-admin-day fpb-" + status;
        if (isPast) cls += " fpb-past";
        if (dt.getTime() === today.getTime()) cls += " fpb-today";

        html +=
          '<div class="' + cls + '" data-date="' + ds + '">' + d + "</div>";
      }

      grid.innerHTML = html;

      grid.querySelectorAll(".fpb-cal-admin-day").forEach((cell) => {
        if (cell.classList.contains("fpb-day-empty") || cell.classList.contains("fpb-past"))
          return;

        cell.addEventListener("click", () => {
          const ds = cell.dataset.date;
          if (!ds || togglePending) return;

          togglePending = true;
          cell.style.opacity = "0.5";
          post("snapbook_admin_toggle_date", { date: ds })
            .then((res) => {
              togglePending = false;
              if (!res.success) {
                cell.style.opacity = "";
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
              togglePending = false;
              cell.style.opacity = "";
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

    post("snapbook_admin_get_dates", {})
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

  bindBookingStatusUpdate();
  bindRowActionMenus();
  bindQuickPaymentActions();
  bindWooOrderStatusControls();
  bindBookingModal();
  bindSessionSlugHelper();
  bindCheckoutFieldBuilder();
  bindSettingsForm();
  bindFrontendForm();
  bindOrderEmailAttachment();
  bindCrudForm(
    "fpb-session-form",
    "snapbook_admin_save_session",
    "fpb-session-msg",
    ["active"],
  );
  bindCrudForm(
    "fpb-package-form",
    "snapbook_admin_save_package",
    "fpb-package-msg",
    ["featured", "active"],
  );
  bindCrudForm("fpb-addon-form", "snapbook_admin_save_addon", "fpb-addon-msg", [
    "active",
  ]);
  bindPackageChecklist();
  bindDeleteButtons(".fpb-del-session", "snapbook_admin_delete_session");
  bindDeleteButtons(".fpb-del-package", "snapbook_admin_delete_package");
  bindPackageLinkCopy();
  bindDeleteButtons(".fpb-del-addon", "snapbook_admin_delete_addon");
  bindDateSlotsCalendar();
  bindBalanceReminderButtons();
  bindBalancePayLinkCopy();
})();
