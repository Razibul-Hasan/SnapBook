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
        if (r.status === 403) {
          // check_ajax_referer() answers an expired/invalid nonce with a
          // bare 403 "-1" (or "0"). Hand callers a normal failure carrying
          // a useful message instead of a generic network error.
          return r.text().then((body) => {
            const trimmed = String(body).trim();
            if (trimmed === "-1" || trimmed === "0") {
              return JSON.stringify({
                success: false,
                data: {
                  message:
                    "Your session has expired. Please reload the page and try again.",
                },
              });
            }
            throw new Error("HTTP " + r.status);
          });
        }
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

  /* ── All Bookings: data, dialog, status changes ─────────────── */

  // Rendered by admin-bookings.php next to the list/calendar.
  const bookingsCtx = window.snapbookBookingsCtx || {};

  function findBooking(id) {
    const list = Array.isArray(window.snapbookBookings)
      ? window.snapbookBookings
      : [];
    return list.find((x) => String(x.id) === String(id)) || null;
  }

  // Reload the current screen (filters, page, month kept) with a notice.
  function reloadWith(params) {
    const url = new URL(window.location.href);
    ["sb_msg", "sb_bid", "sb_mail"].forEach((k) => url.searchParams.delete(k));
    Object.entries(params || {}).forEach(([k, v]) =>
      url.searchParams.set(k, String(v)),
    );
    window.location.href = url.toString();
  }

  function closeRowMenus() {
    document.dispatchEvent(new Event("click"));
  }

  function para(text, cls) {
    return (
      '<p class="' + (cls || "sb-dialog-text") + '">' + escHtml(text) + "</p>"
    );
  }

  // One shared dialog (#sb-dialog) for confirmations, refusals and the
  // Edit / Record payment forms. onClose runs when it is dismissed without
  // an action (Esc, ✕, backdrop, Cancel) — e.g. to put a select back.
  const sbDialog = (function () {
    const el = document.getElementById("sb-dialog");
    if (!el) return null;
    const titleEl = el.querySelector("#sb-dialog-title");
    const body = el.querySelector(".sb-dialog-body");
    const foot = el.querySelector(".sb-dialog-foot");
    let onClose = null;
    let lastFocus = null;

    function close(silent) {
      if (el.style.display === "none") return;
      el.style.display = "none";
      const cb = onClose;
      onClose = null;
      if (!silent && typeof cb === "function") cb();
      if (lastFocus && typeof lastFocus.focus === "function") {
        lastFocus.focus();
      }
    }

    el.querySelector(".sb-modal-close").addEventListener("click", () =>
      close(false),
    );
    el.addEventListener("click", (e) => {
      if (e.target === el) close(false);
    });
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && el.style.display !== "none") {
        e.stopPropagation();
        close(false);
      }
    });

    function open(opts) {
      lastFocus = document.activeElement;
      titleEl.textContent = opts.title || "";
      body.innerHTML = opts.html || "";
      foot.innerHTML = "";
      el.classList.toggle("is-danger", !!opts.danger);
      el.classList.toggle("is-form", !!opts.form);
      (opts.buttons || []).forEach((b) => {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className =
          "button" +
          (b.primary ? " button-primary" : "") +
          (b.danger ? " sb-dialog-danger" : "") +
          (b.cls ? " " + b.cls : "");
        btn.textContent = b.label;
        btn.addEventListener("click", () => {
          if (typeof b.onClick === "function") b.onClick(btn);
          else close(false);
        });
        foot.appendChild(btn);
      });
      onClose = opts.onClose || null;
      el.style.display = "flex";
      const first =
        body.querySelector("input:not([type=hidden]),select,textarea") ||
        foot.querySelector(".button:not(.button-primary):not(.sb-dialog-danger)") ||
        foot.querySelector(".button");
      if (first) first.focus();
    }

    function say(text, ok) {
      const m = body.querySelector(".sb-dialog-msg");
      if (!m) return;
      m.textContent = text || "";
      m.className = "sb-dialog-msg" + (text ? (ok ? " fpb-ok" : " fpb-err") : "");
    }

    return { open, close, say, body, foot };
  })();

  function statusLabel(status) {
    const labels = bookingsCtx.statuses || {};
    return labels[status] || String(status).replace(/_/g, " ");
  }

  function setHint(id, text, ok) {
    const hint = document.querySelector(
      '.fpb-status-hint[data-for-booking="' + id + '"]',
    );
    if (!hint) return;
    hint.textContent = text || "";
    hint.classList.remove("fpb-ok", "fpb-err");
    if (ok === true) hint.classList.add("fpb-ok");
    if (ok === false) hint.classList.add("fpb-err");
  }

  // Refusal dialog, with a shortcut to "Record payment" when that is the
  // right way to get there.
  function showRefusal(id, title, message, offerRecord, onClose) {
    if (!sbDialog) {
      window.alert(message);
      if (onClose) onClose();
      return;
    }
    const b = findBooking(id);
    const canRecord =
      offerRecord && b && b.fpb_view && b.fpb_view.record ? true : false;
    const buttons = [{ label: canRecord ? "Not now" : "OK" }];
    if (canRecord) {
      buttons.push({
        label: "Record payment…",
        primary: true,
        onClick: () => {
          sbDialog.close(false);
          openRecordPayment(id);
        },
      });
    }
    sbDialog.open({
      title,
      html: para(message),
      buttons,
      onClose,
    });
  }

  // Every status change goes through here: refuse what the booking can't
  // become (see snapbook_admin_status_plan), confirm anything that emails
  // the customer or can't be undone, then save and reload.
  function requestStatusChange(id, status, opts) {
    const o = opts || {};
    const b = findBooking(id);
    const plan = b && b.fpb_plan ? b.fpb_plan[status] : null;
    const label = statusLabel(status);

    if (plan && !plan.allowed) {
      showRefusal(
        id,
        "Can’t change to “" + label + "”",
        plan.refuse,
        plan.record,
        o.onCancel,
      );
      return;
    }

    const run = () => sendStatusChange(id, status, o);
    if (!plan || !plan.confirm || !sbDialog) {
      run();
      return;
    }

    const danger = status === "cancelled";
    const titles = {
      cancelled: "Cancel this booking?",
      completed: "Mark this booking as completed?",
      pending_payment: "Reopen the balance?",
      awaiting_payment: "Restore this booking?",
    };
    const actions = {
      cancelled: "Cancel booking",
      completed: "Mark complete",
      pending_payment: "Reopen balance",
      awaiting_payment: "Restore booking",
    };
    const buttons = [{ label: danger ? "Keep booking" : "Go back" }];
    if (plan.record && b && b.fpb_view && b.fpb_view.record) {
      buttons.push({
        label: "Record payment instead…",
        onClick: () => {
          sbDialog.close(false);
          openRecordPayment(id);
        },
      });
    }
    buttons.push({
      label: actions[status] || "Change to “" + label + "”",
      primary: !danger,
      danger,
      onClick: (btn) => {
        btn.disabled = true;
        sbDialog.close(true);
        run();
      },
    });
    sbDialog.open({
      title: titles[status] || "Change the booking status?",
      html: para(plan.confirm),
      buttons,
      danger,
      onClose: o.onCancel,
    });
  }

  function sendStatusChange(id, status, opts) {
    setHint(id, "Saving…", null);
    post("snapbook_admin_update_booking_status", { id, status })
      .then((res) => {
        if (!res.success) {
          const d = res.data || {};
          const msg = d.message || "Could not update the status.";
          setHint(id, msg, false);
          if (opts.onCancel) opts.onCancel();
          showRefusal(id, "Status not changed", msg, !!d.record);
          return;
        }
        setHint(id, (res.data && res.data.message) || "Saved.", true);
        reloadWith({ sb_msg: "status", sb_bid: id });
      })
      .catch(() => {
        setHint(id, "Network error while saving.", false);
        if (opts.onCancel) opts.onCancel();
      });
  }

  function bindBookingStatusControls() {
    document.querySelectorAll(".sb-status-select").forEach((sel) => {
      sel.dataset.prevValue = sel.value;
      sel.addEventListener("change", () => {
        const prev = sel.dataset.prevValue;
        const next = sel.value;
        if (next === prev) return;
        requestStatusChange(sel.dataset.id, next, {
          onCancel: () => {
            sel.value = prev;
          },
        });
      });
    });

    document.querySelectorAll(".fpb-quick-status").forEach((btn) => {
      btn.addEventListener("click", () => {
        closeRowMenus();
        requestStatusChange(btn.dataset.id, btn.dataset.status || "", {});
      });
    });

    document.querySelectorAll(".fpb-act-record").forEach((btn) => {
      btn.addEventListener("click", () => {
        closeRowMenus();
        openRecordPayment(btn.dataset.id);
      });
    });

    document.querySelectorAll(".fpb-act-edit").forEach((btn) => {
      btn.addEventListener("click", () => {
        closeRowMenus();
        openEditBooking(btn.dataset.id);
      });
    });
  }

  // "WooCommerce orders (advanced)": set an order's status directly. The
  // payment/cancel hooks may move the booking on, so the page reloads.
  function bindWooOrderStatusControls() {
    document.querySelectorAll(".fpb-order-status-select").forEach((sel) => {
      sel.dataset.prevValue = sel.value;

      sel.addEventListener("change", function () {
        const orderId = this.dataset.orderId || "0";
        const status = this.value;
        const prevValue = this.dataset.prevValue || this.value;
        const row = this.closest("tr");
        const bookingId = row ? row.dataset.id : "";

        this.disabled = true;
        setHint(bookingId, "Saving…", null);
        post("snapbook_admin_update_wc_order_status", {
          order_id: orderId,
          status,
        })
          .then((res) => {
            if (!res.success) {
              this.value = prevValue;
              this.disabled = false;
              setHint(
                bookingId,
                (res.data && res.data.message) ||
                  "Could not update the WooCommerce order.",
                false,
              );
              return;
            }
            reloadWith({ sb_msg: "status", sb_bid: bookingId });
          })
          .catch(() => {
            this.value = prevValue;
            this.disabled = false;
            setHint(bookingId, "Network error. Order not updated.", false);
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

  // Row menu "Send balance reminder": same confirm-then-send as the View window.
  function bindBalanceReminderButtons() {
    document.querySelectorAll(".fpb-send-balance-reminder").forEach((btn) => {
      btn.addEventListener("click", () => {
        confirmSendReminder(btn.dataset.id || "0", () => {
          btn.disabled = true;
          btn.lastChild.textContent = "Reminder sent ✓";
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
        '<button type="button" class="button fpb-modal-copy-link" data-link="' +
        escHtml(pay.pay_link) +
        '">Copy Payment Link</button>';
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
      renderReminderBoxHtml(pay, balancePaid, bookingId) +
      (actions
        ? '<div class="fpb-modal-pay-actions">' + actions + "</div>"
        : "") +
      '<p class="fpb-modal-pay-msg" aria-live="polite"></p>' +
      "</div>"
    );
  }

  // "Balance reminders" box under the payment grid: what has gone out, what
  // is scheduled, and a button to email the customer right now (for when it
  // can't wait for the automatic one). When a reminder can't be sent, it
  // says why instead.
  function renderReminderBoxHtml(pay, balancePaid, bookingId) {
    const parts = [];
    const count = Number(pay.reminder_count || 0);
    if (count > 0 || pay.last_reminder) {
      parts.push(
        (count > 0 ? count + " sent" : "Sent") +
          (pay.last_reminder ? ", last " + escHtml(pay.last_reminder) : ""),
      );
    } else {
      parts.push("None sent yet");
    }
    if (!balancePaid) {
      parts.push(
        pay.next_reminder
          ? "Next automatic: " + escHtml(pay.next_reminder)
          : "No automatic reminder scheduled",
      );
    }

    let side = "";
    if (pay.can_remind) {
      side =
        '<button type="button" class="button button-primary fpb-modal-send-reminder" data-id="' +
        escHtml(String(bookingId)) +
        '"><span class="dashicons dashicons-email-alt" aria-hidden="true"></span>Send reminder now</button>';
    } else if (!balancePaid && pay.remind_block) {
      side =
        '<p class="fpb-modal-rem-block"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><span>Can’t send a reminder: ' +
        escHtml(pay.remind_block) +
        "</span></p>";
    }

    return (
      '<div class="fpb-modal-rem' +
      (balancePaid ? " is-paid" : "") +
      '"><div class="fpb-modal-rem-info"><strong>Balance reminders</strong><span>' +
      parts.join(" · ") +
      "</span></div>" +
      side +
      "</div>"
    );
  }

  // Ask first (it emails the customer), then send the balance reminder
  // straight away. onSent(data) runs after a successful send; the booking's
  // payment details are refreshed from the server's reply.
  function confirmSendReminder(id, onSent) {
    const b = findBooking(id);
    const pay = (b && b.fpb_payment) || {};
    const name = (b && b.client_name) || "the customer";
    const email = pay.remind_email || (b && b.client_email) || "";
    // Store money labels can end in a space (currency after the amount).
    const amount = String(pay.balance_label || "").trim();
    let busy = false;

    function fail(message, btn) {
      busy = false;
      if (sbDialog && btn) {
        sbDialog.say(message, false);
        btn.disabled = false;
        btn.textContent = "Send reminder";
      } else {
        window.alert(message);
      }
    }

    function send(btn) {
      if (busy) return;
      busy = true;
      if (btn) {
        btn.disabled = true;
        btn.textContent = "Sending…";
      }
      post("snapbook_admin_send_balance_reminder", { id })
        .then((res) => {
          if (!res.success) {
            fail((res.data && res.data.message) || "The reminder could not be sent.", btn);
            return;
          }
          const data = res.data || {};
          if (b && data.payment) {
            b.fpb_payment = Object.assign({}, b.fpb_payment, data.payment);
          }
          if (sbDialog) sbDialog.close(true);
          if (typeof onSent === "function") onSent(data);
        })
        .catch(() => fail("Network error. The reminder was not sent — please try again.", btn));
    }

    if (!sbDialog) {
      if (window.confirm("Email a payment reminder to " + (email || name) + " now?")) send(null);
      return;
    }

    let html = para(
      "This emails " +
        name +
        (email ? " (" + email + ")" : "") +
        " a reminder to pay the remaining balance" +
        (amount ? " of " + amount : "") +
        ", with a link to pay online.",
    );
    if (pay.last_reminder_recent && pay.last_reminder_ago) {
      html += para(
        "A reminder already went out " +
          pay.last_reminder_ago +
          " ago. Send another only if it can’t wait.",
        "sb-dialog-text sb-dialog-warn",
      );
    }
    html += para(
      "It uses your reminder wording (Settings → Emails → Balance reminders). Automatic reminders count their next gap from this one.",
      "sb-dialog-text sb-dialog-note",
    );
    html += '<p class="sb-dialog-msg" aria-live="polite"></p>';

    sbDialog.open({
      title: "Send payment reminder now?",
      html,
      buttons: [
        { label: "Cancel" },
        { label: "Send reminder", primary: true, onClick: (btn) => send(btn) },
      ],
    });
  }

  // Settings → Emails → Balance reminders: dim a schedule's fields while its
  // switch is off, and run the reminder sweep on demand.
  function bindReminderSettings() {
    document.querySelectorAll(".fpb-rem-rule").forEach((rule) => {
      const toggle = rule.querySelector('.fpb-toggle input[type="checkbox"]');
      if (!toggle) return;
      const sync = () => rule.classList.toggle("is-off", !toggle.checked);
      toggle.addEventListener("change", sync);
      sync();
    });

    const runBtn = document.getElementById("fpb-rem-run");
    const runMsg = document.getElementById("fpb-rem-run-msg");
    if (!runBtn) return;
    const say = (text, ok) => {
      if (!runMsg) return;
      runMsg.textContent = text;
      runMsg.className = "fpb-rem-run-msg " + (ok ? "fpb-ok" : "fpb-err");
    };

    runBtn.addEventListener("click", () => {
      const orig = runBtn.textContent;
      runBtn.disabled = true;
      runBtn.textContent = "Checking…";
      post("snapbook_admin_run_reminders", {})
        .then((res) => {
          say(
            (res.data && res.data.message) ||
              (res.success ? "Done." : "The check could not run."),
            !!res.success,
          );
        })
        .catch(() => say("Network error. Please try again.", false))
        .then(() => {
          runBtn.disabled = false;
          runBtn.textContent = orig;
        });
    });
  }

  function bindModalPaymentActions(container, b) {
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

    // "Send reminder now": confirm, send, then redraw this panel from the
    // refreshed payment details so the count and dates are current.
    const remindBtn = container.querySelector(".fpb-modal-send-reminder");
    if (remindBtn && b) {
      remindBtn.addEventListener("click", () => {
        confirmSendReminder(b.id, (data) => {
          const holder = document.createElement("div");
          holder.innerHTML = renderPaymentPanelHtml(b.fpb_payment, b.id);
          const fresh = holder.firstElementChild;
          if (!fresh || !container.parentNode) return;
          container.replaceWith(fresh);
          bindModalPaymentActions(fresh, b);
          const note = fresh.querySelector(".fpb-modal-pay-msg");
          if (note) {
            note.textContent = data.message || "Reminder sent.";
            note.className = "fpb-modal-pay-msg fpb-ok";
          }
          const box = fresh.querySelector(".fpb-modal-rem");
          if (box) box.classList.add("is-just-sent");
        });
      });
    }
  }

  /* ── Booking View window ─────────────────────────────────────── */

  function viewSection(title, rows, extraHtml) {
    const body = rows
      .filter((r) => r && r[1] !== undefined && r[1] !== null && String(r[1]) !== "")
      .map(
        ([k, v]) =>
          '<div class="fpb-modal-row"><span class="fpb-modal-label">' +
          escHtml(k) +
          '</span><span class="fpb-modal-val">' +
          escHtml(String(v)) +
          "</span></div>",
      )
      .join("");
    if (!body && !extraHtml) return "";
    return (
      '<section class="fpb-mv-section"><h3 class="fpb-mv-title">' +
      escHtml(title) +
      "</h3>" +
      body +
      (extraHtml || "") +
      "</section>"
    );
  }

  // Unpaid booking (awaiting payment): what is due and its payment link.
  function renderAwaitingPanelHtml(b) {
    const v = b.fpb_view || {};
    const pay = b.fpb_payment || {};
    if (b.status_key !== "awaiting_payment" || !v.record) return "";
    let actions =
      '<button type="button" class="button button-primary fpb-mv-record">Record payment</button>';
    if (pay.main_pay_link) {
      actions +=
        '<button type="button" class="button fpb-modal-copy-link" data-link="' +
        escHtml(pay.main_pay_link) +
        '">Copy payment link</button>';
    }
    return (
      '<div class="fpb-modal-pay fpb-modal-pay-await">' +
      '<div class="fpb-modal-pay-head">Awaiting payment</div>' +
      '<div class="fpb-modal-pay-grid">' +
      '<div class="fpb-modal-pay-cell"><span>Booking total</span><strong>' +
      escHtml(pay.total_label || "") +
      "</strong></div>" +
      '<div class="fpb-modal-pay-cell"><span>Due now</span><strong class="fpb-bal-due">' +
      escHtml(v.record.amount_label || "") +
      "</strong></div>" +
      '<div class="fpb-modal-pay-cell"><span>Order</span><strong>#' +
      escHtml(v.record.order_number || "") +
      "</strong></div>" +
      "</div>" +
      '<div class="fpb-modal-pay-actions">' +
      actions +
      "</div>" +
      '<p class="fpb-modal-pay-msg" aria-live="polite"></p>' +
      "</div>"
    );
  }

  function renderBookingViewHtml(b) {
    const v = b.fpb_view || {};
    const pay = b.fpb_payment || {};
    const ctxWc = bookingsCtx.wc !== false;
    let html = "";

    // Summary + main actions.
    const when =
      (v.session_date_label || "No date") +
      (v.session_time_label ? " · " + v.session_time_label : "");
    const what = [b.session_type, b.package_name].filter(Boolean).join(" · ");
    let actions = "";
    if (ctxWc) {
      actions +=
        '<button type="button" class="button fpb-mv-edit"><span class="dashicons dashicons-edit" aria-hidden="true"></span>Edit / reschedule</button>';
    }
    if (v.record && b.status_key !== "awaiting_payment") {
      actions +=
        '<button type="button" class="button button-primary fpb-mv-record"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span>Record payment</button>';
    }
    if (v.order_edit_url) {
      actions +=
        '<a class="button" href="' +
        escHtml(v.order_edit_url) +
        '" target="_blank" rel="noopener">Order #' +
        escHtml(v.order_number) +
        "</a>";
    }
    html +=
      '<div class="fpb-mv-head"><div class="fpb-mv-when">' +
      '<span class="sb-badge sb-badge-' +
      escHtml(b.status_key || b.status) +
      '">' +
      escHtml(v.status_label || statusLabel(b.status)) +
      "</span>" +
      "<strong>" +
      escHtml(when) +
      "</strong>" +
      (what ? '<span class="fpb-mv-sub">' + escHtml(what) + "</span>" : "") +
      '<span class="fpb-mv-sub">' +
      escHtml(b.client_name || "") +
      "</span></div>" +
      (actions ? '<div class="fpb-mv-actions">' + actions + "</div>" : "") +
      "</div>";

    // Customer change request from My Account.
    if (v.request) {
      html +=
        '<div class="fpb-mv-request"><div class="fpb-mv-request-head"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><strong>' +
        escHtml(v.request.type_label + " requested") +
        "</strong><span>" +
        escHtml(v.request.at_label || "") +
        "</span></div>" +
        (v.request.date_label
          ? para("Preferred date: " + v.request.date_label, "fpb-mv-request-date")
          : "") +
        '<p class="fpb-mv-request-msg">' +
        escHtml(v.request.message || "") +
        "</p>" +
        '<div class="fpb-mv-request-actions">' +
        (ctxWc
          ? '<button type="button" class="button button-small fpb-mv-edit">Reschedule…</button>'
          : "") +
        '<button type="button" class="button button-small fpb-mv-resolve">Mark as handled</button>' +
        '<span class="fpb-mv-request-msgline" aria-live="polite"></span></div></div>';
    }

    // Money.
    html += renderAwaitingPanelHtml(b);
    if (b.status_key !== "awaiting_payment") {
      html += renderPaymentPanelHtml(pay, b.id);
    }

    // Booking.
    const discount = v.coupon
      ? v.coupon.toUpperCase() + (v.discount_label ? " (−" + v.discount_label + ")" : "")
      : "";
    html += viewSection("Booking", [
      ["Session date", v.session_date_label],
      ["Start time", v.session_time_label],
      ["Session type", b.session_type],
      ["Package", b.package_name],
      ["Add-ons", b.addons_json],
      ["Total", pay.total_label],
      ["Promo code", discount],
      ["Balance due by", v.balance_due_label],
      ["Booked on", v.booked_label],
      [
        "Booking order",
        v.order_number ? "#" + v.order_number + (v.order_status_label ? " · " + v.order_status_label : "") : "",
      ],
    ]);

    // Contract record.
    if (v.contract) {
      const c = v.contract;
      const terms = (bookingsCtx.contracts || {})[c.version];
      let extra = "";
      if (c.has_terms && terms) {
        extra =
          '<button type="button" class="button-link fpb-mv-terms-toggle" aria-expanded="false">View accepted terms</button>' +
          '<div class="fpb-mv-terms" hidden>' +
          (terms.title ? "<h4>" + escHtml(terms.title) + "</h4>" : "") +
          // Admin-authored wording, sanitised with wp_kses_post on the server.
          terms.html +
          "</div>";
      } else if (c.version) {
        extra = para("The wording of this version was not stored.", "fpb-muted fpb-mv-note");
      }
      html += viewSection(
        "Terms & Conditions",
        [
          ["Accepted", c.accepted_at],
          ["Terms version", c.version],
          ["Signature", c.signature || "—"],
          ["IP address", c.ip],
        ],
        extra,
      );
    }

    // Google Calendar.
    const g = v.gcal || {};
    let gState = "";
    if (!g.connected) {
      gState = "Google Calendar isn’t connected.";
    } else if (b.status_key === "cancelled") {
      gState = g.linked ? "Event still linked — sync to remove it." : "Cancelled bookings have no event.";
    } else {
      gState = g.linked ? "Event on your calendar." : "Not on your calendar yet.";
      if (g.retrying) gState += " A failed sync will be retried automatically.";
      if (!g.enabled) gState += " Sync is paused in Settings.";
    }
    let gExtra =
      '<div class="fpb-mv-gcal"><span class="fpb-gcal-dot' +
      (g.connected && g.linked ? " is-on" : "") +
      '" aria-hidden="true"></span><span class="fpb-mv-gcal-state">' +
      escHtml(gState) +
      "</span>";
    if (g.connected) {
      gExtra +=
        '<button type="button" class="button button-small fpb-mv-gcal-sync">Sync to Google Calendar</button>';
    } else if (bookingsCtx.settingsUrl) {
      gExtra +=
        '<a class="button button-small" href="' +
        escHtml(bookingsCtx.settingsUrl) +
        '">Connect</a>';
    }
    gExtra += '</div><p class="fpb-mv-gcal-msg" aria-live="polite"></p>';
    html += viewSection("Google Calendar", [], gExtra);

    // Everything the customer entered at checkout.
    const cf = b.checkout_fields && typeof b.checkout_fields === "object" ? b.checkout_fields : {};
    const labels = {
      billing_first_name: "First name",
      billing_last_name: "Last name",
      billing_company: "Company",
      billing_country_name: "Country",
      billing_state: "State",
      billing_city: "City",
      billing_postcode: "Postcode",
      billing_address_1: "Address",
      billing_address_2: "Address 2",
      billing_phone: "Phone",
      billing_email: "Email",
      billing_hotel_place: "Hotel / place",
      billing_participants: "Participants",
      billing_room_number: "Room number",
      billing_stay_period: "Stay period",
      order_customer_note: "Customer note",
    };
    const skip = ["billing_country", "billing_event_date", "billing_event_time"];
    const rows = Object.keys(labels).map((k) => [labels[k], cf[k] || ""]);
    Object.keys(cf)
      .filter((k) => !labels[k] && !skip.includes(k))
      .forEach((k) => rows.push([k, cf[k] || ""]));
    if (!Object.keys(cf).length) {
      rows.push(["Name", b.client_name], ["Email", b.client_email], ["Phone", b.client_phone], ["Country", b.client_country], ["Notes", b.notes]);
    }
    html += viewSection("Customer", rows);

    return html;
  }

  function openBookingView(id) {
    const modal = document.getElementById("sb-booking-modal");
    const b = findBooking(id);
    if (!modal || !b) return;
    const title = modal.querySelector("#sb-booking-modal-title") || modal.querySelector(".sb-modal-head span");
    const body = modal.querySelector(".sb-modal-body");
    body.innerHTML = renderBookingViewHtml(b);
    if (title) title.textContent = "Booking #" + b.id;

    body.querySelectorAll(".fpb-modal-pay").forEach((panel) => bindModalPaymentActions(panel, b));
    body.querySelectorAll(".fpb-mv-edit").forEach((btn) =>
      btn.addEventListener("click", () => openEditBooking(b.id)),
    );
    body.querySelectorAll(".fpb-mv-record").forEach((btn) =>
      btn.addEventListener("click", () => openRecordPayment(b.id)),
    );

    const termsBtn = body.querySelector(".fpb-mv-terms-toggle");
    if (termsBtn) {
      termsBtn.addEventListener("click", () => {
        const box = body.querySelector(".fpb-mv-terms");
        const open = termsBtn.getAttribute("aria-expanded") === "true";
        termsBtn.setAttribute("aria-expanded", open ? "false" : "true");
        termsBtn.textContent = open ? "View accepted terms" : "Hide accepted terms";
        if (box) box.hidden = open;
      });
    }

    const syncBtn = body.querySelector(".fpb-mv-gcal-sync");
    if (syncBtn) {
      syncBtn.addEventListener("click", () => {
        const msg = body.querySelector(".fpb-mv-gcal-msg");
        const orig = syncBtn.textContent;
        syncBtn.disabled = true;
        syncBtn.textContent = "Syncing…";
        post("snapbook_admin_gcal_sync_booking", { id: b.id })
          .then((res) => {
            const d = res.data || {};
            if (msg) {
              msg.textContent = d.message || (res.success ? "Synced." : "Sync failed.");
              msg.className = "fpb-mv-gcal-msg " + (res.success ? "fpb-ok" : "fpb-err");
            }
            if (res.success) {
              b.fpb_view.gcal.linked = !!d.linked;
              b.fpb_view.gcal.retrying = false;
              const dot = body.querySelector(".fpb-mv-gcal .fpb-gcal-dot");
              const state = body.querySelector(".fpb-mv-gcal-state");
              if (dot) dot.classList.toggle("is-on", !!d.linked);
              if (state) state.textContent = d.linked ? "Event on your calendar." : "No event on your calendar.";
            }
          })
          .catch(() => {
            if (msg) {
              msg.textContent = "Network error. Please try again.";
              msg.className = "fpb-mv-gcal-msg fpb-err";
            }
          })
          .then(() => {
            syncBtn.disabled = false;
            syncBtn.textContent = orig;
          });
      });
    }

    const resolveBtn = body.querySelector(".fpb-mv-resolve");
    if (resolveBtn) {
      resolveBtn.addEventListener("click", () => {
        const line = body.querySelector(".fpb-mv-request-msgline");
        resolveBtn.disabled = true;
        post("snapbook_admin_resolve_request", { id: b.id })
          .then((res) => {
            if (res.success) {
              b.fpb_view.request = null;
              const box = body.querySelector(".fpb-mv-request");
              if (box) box.remove();
              const pill = document.querySelector('.fpb-brow[data-id="' + b.id + '"] .fpb-req-pill');
              if (pill) pill.remove();
            } else {
              resolveBtn.disabled = false;
              if (line) line.textContent = (res.data && res.data.message) || "Could not update.";
            }
          })
          .catch(() => {
            resolveBtn.disabled = false;
            if (line) line.textContent = "Network error.";
          });
      });
    }

    modal.style.display = "flex";
    const close = modal.querySelector(".sb-modal-close");
    if (close) close.focus();
  }

  function bindBookingModal() {
    const modal = document.getElementById("sb-booking-modal");
    if (!modal) return;
    const closeModal = () => {
      modal.style.display = "none";
    };
    modal.querySelector(".sb-modal-close")?.addEventListener("click", closeModal);
    modal.addEventListener("click", (e) => {
      if (e.target === modal) closeModal();
    });
    document.addEventListener("keydown", (e) => {
      const dlg = document.getElementById("sb-dialog");
      if (e.key === "Escape" && modal.style.display !== "none" && (!dlg || dlg.style.display === "none")) {
        closeModal();
      }
    });
    // List rows and calendar entries alike.
    document.addEventListener("click", (e) => {
      const t = e.target.closest ? e.target.closest(".sb-btn-view, .fpb-bcal-bk") : null;
      if (!t || !t.dataset.id) return;
      e.preventDefault();
      openBookingView(t.dataset.id);
    });
  }

  /* ── Record payment ───────────────────────────────────────────── */
  function openRecordPayment(id) {
    const b = findBooking(id);
    if (!sbDialog || !b) return;
    const r = b.fpb_view && b.fpb_view.record;
    if (!r) {
      showRefusal(id, "Nothing to record", "Nothing is due on this booking, so there is no payment to record.", false);
      return;
    }
    const methods = bookingsCtx.methods || { cash: "Cash", bank: "Bank transfer", card: "Card terminal", other: "Other" };
    const what =
      r.kind === "balance"
        ? "Remaining balance · order #" + r.order_number
        : "Booking payment · order #" + r.order_number;
    const after =
      r.kind === "balance"
        ? "Marks the balance order paid; the booking then completes by itself."
        : "Marks the booking order paid; the booking moves on to “Deposit paid” or “Paid in full”, and a balance order is created if something is still to pay.";
    const html =
      '<div class="sb-dialog-amount"><span>Amount due</span><strong>' +
      escHtml(r.amount_label) +
      "</strong><small>" +
      escHtml(what + " · " + (b.client_name || "")) +
      "</small></div>" +
      '<div class="fpb-field"><label for="sb-rp-method">Paid by</label><select id="sb-rp-method">' +
      Object.entries(methods)
        .map(([k, l]) => '<option value="' + escHtml(k) + '">' + escHtml(l) + "</option>")
        .join("") +
      "</select></div>" +
      '<div class="fpb-field"><label for="sb-rp-note">Note <span class="fpb-muted">(optional)</span></label><input id="sb-rp-note" type="text" maxlength="200" placeholder="Receipt or transfer reference"></div>' +
      '<label class="sb-dialog-check"><input type="checkbox" id="sb-rp-notify" checked> Email the customer their confirmation / receipt</label>' +
      para(after, "sb-dialog-note") +
      '<p class="sb-dialog-msg" aria-live="polite"></p>';

    sbDialog.open({
      title: "Record a payment",
      html,
      form: true,
      buttons: [
        { label: "Cancel" },
        {
          label: "Record " + r.amount_label,
          primary: true,
          onClick: (btn) => {
            btn.disabled = true;
            sbDialog.say("Recording…", true);
            post("snapbook_admin_record_payment", {
              id: b.id,
              method: sbDialog.body.querySelector("#sb-rp-method").value,
              method_note: sbDialog.body.querySelector("#sb-rp-note").value,
              notify: sbDialog.body.querySelector("#sb-rp-notify").checked ? "1" : "0",
            })
              .then((res) => {
                if (!res.success) {
                  btn.disabled = false;
                  sbDialog.say((res.data && res.data.message) || "The payment could not be recorded.", false);
                  return;
                }
                sbDialog.close(true);
                reloadWith({ sb_msg: "payment", sb_bid: b.id });
              })
              .catch(() => {
                btn.disabled = false;
                sbDialog.say("Network error. Please try again.", false);
              });
          },
        },
      ],
    });
  }

  /* ── Edit / reschedule ────────────────────────────────────────── */
  function openEditBooking(id) {
    const b = findBooking(id);
    if (!sbDialog || !b || !b.fpb_view) return;
    const e = b.fpb_view.edit || {};
    const slots = bookingsCtx.slots || { enabled: false, times: [] };
    const field = (name, label, value, type, extra) =>
      '<div class="fpb-field' + (extra || "") + '"><label for="sb-ed-' + name + '">' + escHtml(label) + "</label>" +
      '<input id="sb-ed-' + name + '" name="' + name + '" type="' + (type || "text") + '" value="' + escHtml(value || "") + '"></div>';

    let timeField;
    if (slots.enabled) {
      const times = (slots.times || []).slice();
      if (e.time && !times.includes(e.time)) times.unshift(e.time);
      timeField =
        '<div class="fpb-field"><label for="sb-ed-session_time">Start time</label><select id="sb-ed-session_time" name="session_time"><option value="">—</option>' +
        times
          .map((t) => '<option value="' + escHtml(t) + '"' + (t === e.time ? " selected" : "") + ">" + escHtml(t) + "</option>")
          .join("") +
        "</select></div>";
    } else {
      timeField = field("session_time", "Start time", e.time, "text");
    }

    const html =
      '<form class="sb-edit-form" novalidate><div class="fpb-form-grid fpb-cols-2">' +
      field("session_date", "Session date", e.date, "date") +
      timeField +
      field("first_name", "First name", e.first_name) +
      field("last_name", "Last name", e.last_name) +
      field("email", "Email", e.email, "email") +
      field("phone", "Phone", e.phone, "tel") +
      '<div class="fpb-field fpb-field-wide"><label for="sb-ed-notes">Notes</label><textarea id="sb-ed-notes" name="notes" rows="3">' +
      escHtml(e.notes || "") +
      "</textarea></div></div>" +
      '<label class="sb-dialog-check"><input type="checkbox" name="notify"' + (e.email ? " checked" : "") + "> Email the customer about the change</label>" +
      '<label class="sb-dialog-check"><input type="checkbox" name="override"> Allow even if the new date or time is full</label>' +
      para("Moving the session frees the old date, holds the new one, updates the WooCommerce orders and the Google Calendar event.", "sb-dialog-note") +
      '<p class="sb-dialog-msg" aria-live="polite"></p></form>';

    sbDialog.open({
      title: "Edit booking #" + b.id,
      html,
      form: true,
      buttons: [
        { label: "Cancel" },
        {
          label: "Save changes",
          primary: true,
          onClick: (btn) => {
            const form = sbDialog.body.querySelector(".sb-edit-form");
            form.querySelectorAll(".fpb-invalid").forEach((el) => el.classList.remove("fpb-invalid"));
            const d = formToObject(form);
            d.id = b.id;
            d.notify = form.querySelector('[name="notify"]').checked ? "1" : "0";
            d.override = form.querySelector('[name="override"]').checked ? "1" : "0";
            btn.disabled = true;
            sbDialog.say("Saving…", true);
            post("snapbook_admin_edit_booking", d)
              .then((res) => {
                if (!res.success) {
                  const data = res.data || {};
                  btn.disabled = false;
                  sbDialog.say(data.message || "The booking could not be saved.", false);
                  const bad = data.field ? form.querySelector('[name="' + data.field + '"]') : null;
                  if (bad) {
                    bad.classList.add("fpb-invalid");
                    bad.focus();
                  }
                  return;
                }
                sbDialog.close(true);
                reloadWith({ sb_msg: "updated", sb_bid: b.id });
              })
              .catch(() => {
                btn.disabled = false;
                sbDialog.say("Network error. Please try again.", false);
              });
          },
        },
      ],
    });
  }

  /* ── Add booking (SnapBook → Bookings → Add booking) ─────────── */
  function bindAddBookingForm() {
    const form = document.getElementById("fpb-addbk-form");
    const data = window.snapbookAddBooking;
    if (!form || !data) return;

    const sessionSel = form.querySelector("#fpb-addbk-session");
    const pkgSel = form.querySelector("#fpb-addbk-package");
    const addonsBox = form.querySelector("#fpb-addbk-addons");
    const summary = form.querySelector("#fpb-addbk-summary");
    const dateMsg = form.querySelector("#fpb-addbk-datemsg");
    const couponMsg = form.querySelector("#fpb-addbk-couponmsg");
    const paidFields = form.querySelector(".fpb-addbk-paidfields");
    const unpaidFields = form.querySelector(".fpb-addbk-unpaidfields");
    const submit = form.querySelector("#fpb-addbk-submit");
    const packages = data.packages || [];
    const addons = data.addons || [];
    let timer = null;
    let seq = 0;

    function payState() {
      const r = form.querySelector('input[name="pay_state"]:checked');
      return r ? r.value : "full";
    }

    function values() {
      const d = formToObject(form);
      d.addon_ids = Array.from(addonsBox.querySelectorAll("input:checked"))
        .map((i) => i.value)
        .join(",");
      d.notify = form.querySelector('[name="notify"]').checked ? "1" : "0";
      d.override = form.querySelector('[name="override"]').checked ? "1" : "0";
      return d;
    }

    function setDepositPct(pct) {
      form.querySelectorAll(".fpb-addbk-pct").forEach((el) => {
        el.textContent = pct + "%";
      });
    }

    function fillAddons() {
      const pid = parseInt(pkgSel.value || "0", 10);
      const keep = new Set(
        Array.from(addonsBox.querySelectorAll("input:checked")).map((i) => i.value),
      );
      const list = addons.filter((a) => !a.scope.length || a.scope.includes(pid));
      addonsBox.innerHTML = list.length
        ? list
            .map(
              (a) =>
                '<label class="fpb-checklist-item"><input type="checkbox" value="' +
                a.id +
                '"' +
                (keep.has(String(a.id)) ? " checked" : "") +
                "> " +
                escHtml(a.name) +
                ' <span class="fpb-muted">+' +
                escHtml(a.priceLabel) +
                "</span></label>",
            )
            .join("")
        : '<span class="fpb-muted">No add-ons for this package.</span>';
      const pkg = packages.find((p) => p.id === pid);
      if (pkg) setDepositPct(pkg.depositPct);
      requote();
    }

    function fillPackages() {
      const sid = parseInt(sessionSel.value || "0", 10);
      const list = packages.filter((p) => p.session_id === sid);
      pkgSel.innerHTML = list.length
        ? list
            .map(
              (p) =>
                '<option value="' + p.id + '">' + escHtml(p.name + " — " + p.priceLabel) + "</option>",
            )
            .join("")
        : '<option value="">No active packages for this session type</option>';
      fillAddons();
    }

    function syncPayFields() {
      const s = payState();
      if (paidFields) paidFields.hidden = s === "unpaid";
      if (unpaidFields) unpaidFields.hidden = s !== "unpaid";
      form.querySelectorAll(".fpb-paystate-opt").forEach((opt) => {
        const input = opt.querySelector("input");
        opt.classList.toggle("is-checked", !!(input && input.checked));
      });
    }

    function renderSummary(r) {
      summary.innerHTML =
        '<table class="fpb-addbk-lines"><tbody>' +
        (r.rows || [])
          .map(
            (row) =>
              '<tr class="' +
              (row.strong ? "is-strong " : "") +
              (row.tone ? "is-" + escHtml(row.tone) : "") +
              '"><th scope="row">' +
              escHtml(row.label) +
              "</th><td>" +
              escHtml(row.value) +
              "</td></tr>",
          )
          .join("") +
        "</tbody></table>";
    }

    function requote() {
      clearTimeout(timer);
      timer = setTimeout(() => {
        const d = values();
        if (!d.package_id) {
          summary.innerHTML = '<p class="fpb-muted">Choose a package to see the price.</p>';
          return;
        }
        const mine = ++seq;
        summary.classList.add("is-loading");
        post("snapbook_admin_quote_booking", d)
          .then((res) => {
            if (mine !== seq) return;
            summary.classList.remove("is-loading");
            if (!res.success) {
              summary.innerHTML = para((res.data && res.data.message) || "Could not price this booking.", "fpb-err");
              return;
            }
            const r = res.data || {};
            renderSummary(r);
            if (r.depositPct) setDepositPct(r.depositPct);
            if (dateMsg) {
              if (!d.session_date) {
                dateMsg.textContent = "";
                dateMsg.className = "fpb-addbk-datemsg";
              } else {
                dateMsg.textContent = r.dateError ? r.dateError : "This date is free.";
                dateMsg.className = "fpb-addbk-datemsg " + (r.dateError ? "fpb-err" : "fpb-ok");
              }
            }
            if (couponMsg) {
              couponMsg.textContent = r.couponError || "";
              couponMsg.className = "fpb-addbk-couponmsg" + (r.couponError ? " fpb-err" : "");
            }
          })
          .catch(() => {
            if (mine !== seq) return;
            summary.classList.remove("is-loading");
            summary.innerHTML = para("Network error while pricing.", "fpb-err");
          });
      }, 300);
    }

    sessionSel?.addEventListener("change", fillPackages);
    pkgSel.addEventListener("change", fillAddons);
    addonsBox.addEventListener("change", requote);
    form.querySelectorAll('input[name="pay_state"]').forEach((r) =>
      r.addEventListener("change", () => {
        syncPayFields();
        requote();
      }),
    );
    ["#fpb-addbk-link", "#fpb-addbk-date", "#fpb-addbk-time"].forEach((sel) => {
      const el = form.querySelector(sel);
      if (el) el.addEventListener("change", requote);
    });
    const coupon = form.querySelector("#fpb-addbk-coupon");
    if (coupon) coupon.addEventListener("input", requote);
    const email = form.querySelector("#fpb-addbk-email");
    if (email && coupon) {
      email.addEventListener("change", () => {
        if (coupon.value.trim()) requote();
      });
    }

    form.addEventListener("submit", (ev) => {
      ev.preventDefault();
      form.querySelectorAll(".fpb-invalid").forEach((el) => el.classList.remove("fpb-invalid"));
      const d = values();
      submit.disabled = true;
      setMsg("fpb-addbk-msg", "", true);
      post("snapbook_admin_create_booking", d)
        .then((res) => {
          if (!res.success) {
            const r = res.data || {};
            submit.disabled = false;
            setMsg("fpb-addbk-msg", r.message || "The booking could not be added.", false);
            const bad = r.field ? form.querySelector('[name="' + r.field + '"]') : null;
            if (bad) {
              bad.classList.add("fpb-invalid");
              bad.focus();
            }
            return;
          }
          window.location.href = res.data.redirect;
        })
        .catch(() => {
          submit.disabled = false;
          setMsg("fpb-addbk-msg", "Network or server error. Please try again.", false);
        });
    });

    syncPayFields();
    fillPackages();
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
        field.dispatchEvent(new Event("change", { bubbles: true }));
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
          field.dispatchEvent(new Event("change", { bubbles: true }));
          setLabel(file.filename || file.title || "");
          if (remove) remove.style.display = "";
        });
      }
      frame.open();
    });
  }

  // The Booking Form page posts normally (no AJAX), but the contract editor
  // still needs an explicit sync so the visual-mode content reaches $_POST.
  function bindFrontendForm() {
    const form = document.getElementById("fpb-frontend-form");
    if (!form) return;
    const dirty = trackDirty(form);
    form.addEventListener("submit", () => {
      if (window.tinymce) window.tinymce.triggerSave();
      // The page reloads with the saved values; don't warn about leaving.
      dirty.clean();
    });

    // The step diagram at the top follows the Contract step switch.
    const toggle = form.querySelector('input[type="checkbox"][name="fpb_fe_contract_enable"]');
    const step = form.querySelector(".fpb-flow-contract");
    const payNum = form.querySelector(".fpb-flow-pay-num");
    if (toggle && step) {
      const sync = () => {
        step.classList.toggle("is-off", !toggle.checked);
        if (payNum) {
          payNum.textContent = toggle.checked ? payNum.dataset.on : payNum.dataset.off;
        }
      };
      toggle.addEventListener("change", sync);
      sync();
    }
  }

  /* ─── Help tips ────────────────────────────────────────────────
     Every "?" button (.fpb-tip, rendered by snapbook_help_tip()) shares
     one floating bubble, positioned against the viewport so card and
     table overflow never clips it. Shows on hover, keyboard focus or tap;
     Escape or a click elsewhere closes it. The text comes from the hidden
     element the button's aria-describedby points to, which is also what
     screen readers announce — the bubble itself is aria-hidden. */
  function bindHelpTips() {
    if (!document.querySelector(".fpb-tip")) return;

    const bubble = document.createElement("div");
    bubble.className = "fpb-tip-bubble";
    bubble.setAttribute("aria-hidden", "true");
    bubble.hidden = true;
    document.body.appendChild(bubble);

    let current = null;
    let pinned = false;
    let hideTimer = 0;

    const tipFrom = (node) =>
      node && node.closest ? node.closest(".fpb-tip") : null;

    function textFor(btn) {
      const id = btn.getAttribute("aria-describedby");
      const src = id ? document.getElementById(id) : null;
      return src ? src.textContent : "";
    }

    function place() {
      if (!current) return;
      const r = current.getBoundingClientRect();
      const vw = document.documentElement.clientWidth;
      const vh = window.innerHeight;
      const gap = 8;
      const edge = 8;
      // The WP admin bar covers the top 32px (46px on small screens).
      const top0 = vw <= 782 ? 54 : 40;
      bubble.style.left = "0px";
      bubble.style.top = "0px";
      const b = bubble.getBoundingClientRect();

      let below = r.top - b.height - gap < top0;
      if (below && r.bottom + gap + b.height > vh - edge) below = false;
      const top = below ? r.bottom + gap : r.top - b.height - gap;

      const center = r.left + r.width / 2;
      let left = center - b.width / 2;
      left = Math.max(edge, Math.min(left, vw - b.width - edge));

      bubble.style.left = Math.round(left) + "px";
      bubble.style.top = Math.round(top) + "px";
      bubble.style.setProperty("--fpb-tip-arrow", Math.round(center - left) + "px");
      bubble.classList.toggle("is-below", below);
    }

    function show(btn) {
      clearTimeout(hideTimer);
      if (current === btn) return;
      if (current) current.classList.remove("is-open");
      current = btn;
      pinned = false;
      btn.classList.add("is-open");
      btn.setAttribute("aria-expanded", "true");
      bubble.textContent = textFor(btn);
      bubble.hidden = false;
      bubble.classList.remove("is-visible");
      place();
      window.requestAnimationFrame(() => bubble.classList.add("is-visible"));
    }

    function hide() {
      clearTimeout(hideTimer);
      if (!current) return;
      current.classList.remove("is-open");
      current.removeAttribute("aria-expanded");
      current = null;
      pinned = false;
      bubble.classList.remove("is-visible");
      bubble.hidden = true;
    }

    function hideSoon() {
      if (pinned) return;
      clearTimeout(hideTimer);
      hideTimer = setTimeout(hide, 150);
    }

    document.addEventListener("mouseover", (e) => {
      const t = tipFrom(e.target);
      if (t) show(t);
    });
    document.addEventListener("mouseout", (e) => {
      const t = tipFrom(e.target);
      if (!t || t !== current) return;
      if (t.contains(e.relatedTarget) || e.relatedTarget === bubble) return;
      hideSoon();
    });
    // The bubble can be hovered (to read or select its text) without closing.
    bubble.addEventListener("mouseenter", () => clearTimeout(hideTimer));
    bubble.addEventListener("mouseleave", hideSoon);

    document.addEventListener("focusin", (e) => {
      const t = tipFrom(e.target);
      if (t) {
        show(t);
      } else if (current && !pinned) {
        hide();
      }
    });
    document.addEventListener("focusout", (e) => {
      if (e.target === current) hideSoon();
    });

    document.addEventListener("click", (e) => {
      const t = tipFrom(e.target);
      if (t) {
        // Never let a tip click reach a surrounding label or form.
        e.preventDefault();
        if (current === t && pinned) {
          hide();
        } else {
          show(t);
          pinned = true;
        }
        return;
      }
      if (current && !bubble.contains(e.target)) hide();
    });

    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape" && current) hide();
    });

    // Follow the button while the page (or a scrolling table) moves.
    window.addEventListener("scroll", () => current && place(), true);
    window.addEventListener("resize", () => current && place());
  }

  /* ─── Unsaved-changes tracking ─────────────────────────────────
     Marks the form's wrapper .is-dirty (the save bar then says "Unsaved
     changes") and asks before leaving the page with edits. Search boxes
     and other unnamed controls don't count. TinyMCE editors report their
     own changes once they exist (they initialise after this script). */
  function trackDirty(form) {
    let dirty = false;
    const set = (on) => {
      dirty = on;
      form.classList.toggle("is-dirty", on);
    };
    const mark = (e) => {
      if (e && e.target && !e.target.name) return;
      if (!dirty) set(true);
    };
    form.addEventListener("input", mark);
    form.addEventListener("change", mark);
    // Buttons that edit the form without an input event (Add Field, Remove,
    // the attachment picker) count as edits too.
    form.addEventListener("click", (e) => {
      if (
        e.target.closest &&
        e.target.closest("#fpb-ccf-add, .fpb-ccf-remove")
      ) {
        set(true);
      }
    });

    window.addEventListener("load", () => {
      const mce = window.tinymce;
      if (!mce) return;
      const hook = (ed) => {
        if (!ed || !ed.on || !ed.getElement || !form.contains(ed.getElement())) {
          return;
        }
        ed.on("input change undo redo", () => mark());
      };
      (mce.editors || []).forEach(hook);
      if (mce.on) mce.on("AddEditor", (ev) => hook(ev.editor));
    });

    window.addEventListener("beforeunload", (e) => {
      if (!dirty) return;
      e.preventDefault();
      e.returnValue = "";
    });

    return {
      clean: () => set(false),
      mark: () => set(true),
      isDirty: () => dirty,
    };
  }

  /* ─── Dependent rows ───────────────────────────────────────────
     [data-fpb-requires="name"] is dimmed while the named checkbox is off
     (or the named number is 0 / the text is empty). "!name" inverts it:
     dimmed while that field HAS a value. Fields stay editable. */
  function bindRequires() {
    document.querySelectorAll("[data-fpb-requires]").forEach((row) => {
      const form = row.closest("form");
      if (!form) return;
      let name = row.getAttribute("data-fpb-requires");
      const invert = name.charAt(0) === "!";
      if (invert) name = name.slice(1);

      const ctrls = Array.from(form.querySelectorAll('[name="' + name + '"]'));
      const ctrl =
        ctrls.find((c) => c.type === "checkbox") ||
        ctrls.find((c) => c.type !== "hidden");
      if (!ctrl) return;

      const isOn = () => {
        if (ctrl.type === "checkbox") return ctrl.checked;
        const v = String(ctrl.value || "").trim();
        if (ctrl.type === "number") return parseFloat(v) > 0;
        return v !== "";
      };
      const sync = () => {
        const on = invert ? !isOn() : isOn();
        row.classList.toggle("is-parked", !on);
      };
      ctrl.addEventListener("change", sync);
      ctrl.addEventListener("input", sync);
      sync();
    });
  }

  /* ─── Settings sections ────────────────────────────────────────
     One section of the Settings form shows at a time (the PHP marks the
     first as active; all show without JS). The URL hash remembers it
     (#payments), and a hash naming an element inside a section
     (#fpb-gcal) opens that section and scrolls to the element. */
  function bindSettingsSections() {
    const layout = document.querySelector(".fpb-set-layout");
    if (!layout) return null;

    const links = Array.from(layout.querySelectorAll(".fpb-set-nav-link"));
    const panels = Array.from(layout.querySelectorAll(".fpb-set-panel"));
    const panelFor = (key) => panels.find((p) => p.dataset.section === key);

    function activate(key, opts) {
      const o = opts || {};
      if (!panelFor(key)) return;
      panels.forEach((p) => p.classList.toggle("is-active", p.dataset.section === key));
      links.forEach((l) => {
        const on = l.dataset.section === key;
        l.classList.toggle("is-active", on);
        if (on) {
          l.setAttribute("aria-current", "true");
        } else {
          l.removeAttribute("aria-current");
        }
      });
      if (o.updateHash !== false && window.history && window.history.replaceState) {
        window.history.replaceState(null, "", "#" + key);
      }
      if (o.scroll) {
        const top = layout.getBoundingClientRect().top + window.scrollY - 120;
        if (window.scrollY > top) window.scrollTo({ top: Math.max(0, top) });
      }
      // TinyMCE and other widgets measure themselves; let them re-layout.
      window.dispatchEvent(new Event("resize"));
    }

    function fromHash() {
      const hash = decodeURIComponent(window.location.hash.slice(1));
      if (!hash) return;
      if (panelFor(hash)) {
        activate(hash, { updateHash: false });
        return;
      }
      const target = document.getElementById(hash);
      const panel = target ? target.closest(".fpb-set-panel") : null;
      if (panel) {
        activate(panel.dataset.section, { updateHash: false });
        window.requestAnimationFrame(() => target.scrollIntoView({ block: "start" }));
      }
    }

    links.forEach((link) => {
      link.addEventListener("click", (e) => {
        e.preventDefault();
        const search = document.getElementById("fpb-set-search");
        if (search && search.value) {
          search.value = "";
          search.dispatchEvent(new Event("input"));
        }
        activate(link.dataset.section, { scroll: true });
      });
    });
    window.addEventListener("hashchange", fromHash);
    fromHash();

    // A field that fails the browser's checks may sit in a hidden section:
    // open that section so the browser can point at the field.
    const form = layout.closest("form") || layout;
    let revealed = false;
    form.addEventListener(
      "invalid",
      (e) => {
        if (revealed) return;
        revealed = true;
        setTimeout(() => (revealed = false), 0);
        const search = document.getElementById("fpb-set-search");
        if (search && search.value) {
          search.value = "";
          search.dispatchEvent(new Event("input"));
        }
        const panel = e.target.closest(".fpb-set-panel");
        if (panel && !panel.classList.contains("is-active")) {
          activate(panel.dataset.section);
        }
      },
      true,
    );
  }

  /* ─── Settings search ──────────────────────────────────────────
     Filters setting rows across every section by their label, help
     text and descriptions. While searching, all sections show and the
     side menu dims sections with no match. */
  function bindSettingsSearch() {
    const input = document.getElementById("fpb-set-search");
    const layout = document.querySelector(".fpb-set-layout");
    if (!input || !layout) return;
    const empty = document.getElementById("fpb-set-noresults");
    const panels = Array.from(layout.querySelectorAll(".fpb-set-panel"));
    const links = Array.from(layout.querySelectorAll(".fpb-set-nav-link"));

    // Text content without <script> templates, lower-cased.
    function textOf(el) {
      let out = "";
      const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, {
        acceptNode: (n) =>
          n.parentNode && n.parentNode.closest && n.parentNode.closest("script")
            ? NodeFilter.FILTER_REJECT
            : NodeFilter.FILTER_ACCEPT,
      });
      while (walker.nextNode()) out += " " + walker.currentNode.nodeValue;
      return out.toLowerCase();
    }

    const miss = "fpb-search-miss";
    function reset() {
      layout.classList.remove("is-searching");
      layout.querySelectorAll("." + miss).forEach((el) => el.classList.remove(miss));
      links.forEach((l) => l.classList.remove("is-dim"));
      if (empty) empty.hidden = true;
    }

    input.addEventListener("keydown", (e) => {
      // Enter would submit the settings form.
      if (e.key === "Enter") e.preventDefault();
      if (e.key === "Escape" && input.value) {
        input.value = "";
        reset();
      }
    });

    input.addEventListener("input", () => {
      const q = input.value.trim().toLowerCase();
      if (!q) {
        reset();
        return;
      }
      layout.classList.add("is-searching");
      let total = 0;

      panels.forEach((panel) => {
        let panelHits = 0;
        panel.querySelectorAll(".fpb-settings-card").forEach((card) => {
          const rows = Array.from(
            card.querySelectorAll(".form-table > tbody > tr, .fpb-rem-rule"),
          );
          let cardHit;
          if (rows.length) {
            const head = card.querySelector("h3, h2");
            const intro = card.querySelector(":scope > .description");
            const headHit =
              (head && textOf(head).includes(q)) ||
              (intro && textOf(intro).includes(q));
            let rowHits = 0;
            rows.forEach((row) => {
              const hit = headHit || textOf(row).includes(q);
              row.classList.toggle(miss, !hit);
              if (hit) rowHits++;
            });
            cardHit = headHit || rowHits > 0;
          } else {
            cardHit = textOf(card).includes(q);
          }
          card.classList.toggle(miss, !cardHit);
          if (cardHit) panelHits++;
        });
        panel.classList.toggle(miss, panelHits === 0);
        const link = links.find((l) => l.dataset.section === panel.dataset.section);
        if (link) link.classList.toggle("is-dim", panelHits === 0);
        total += panelHits;
      });

      if (empty) empty.hidden = total > 0;
    });
  }

  // Copy buttons with a data-copy value (the [snapbook] shortcode).
  function bindCopyButtons() {
    document.querySelectorAll(".fpb-copy-btn[data-copy]").forEach((btn) => {
      btn.addEventListener("click", () => {
        const orig = btn.textContent;
        copyToClipboard(btn.dataset.copy, (ok) => {
          btn.textContent = ok ? "Copied!" : "Press Ctrl+C to copy";
          setTimeout(() => (btn.textContent = orig), 2000);
        });
      });
    });
  }

  // "Connect with Google" is rendered disabled until the Google app keys are
  // stored. The settings form saves over AJAX without reloading, so the link
  // has to be re-enabled here or it stays dead until the admin refreshes.
  function refreshGcalConnectState() {
    const link = document.querySelector(".fpb-gcal-connect");
    const id = document.getElementById("fpb-gcal-client-id");
    const secret = document.getElementById("fpb-gcal-client-secret");
    if (!link || !id || !secret) return;

    const ready = id.value.trim() !== "" && secret.value.trim() !== "";
    link.classList.toggle("is-disabled", !ready);
  }

  function bindSettingsForm() {
    const form = document.getElementById("fpb-settings-form");
    if (!form) return;
    const dirty = trackDirty(form);

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
          dirty.clean();
          refreshGcalConnectState();
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

    function showDatesMsg(text, isErr) {
      if (!msg) return;
      msg.textContent = text || "";
      msg.classList.toggle("fpb-err", !!isErr);
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
                // e.g. the date holds a customer booking: the server refuses
                // and says who booked it. Resync the cell to its real state.
                const data = res.data || {};
                if (data.date === ds && data.status) {
                  if (data.status === "available") {
                    delete dateMap[ds];
                  } else {
                    dateMap[ds] = data.status;
                  }
                  renderCalendar();
                }
                showDatesMsg(data.message || "Could not update date.", true);
                return;
              }

              if (res.data.status === "available") {
                delete dateMap[ds];
              } else {
                dateMap[ds] = res.data.status;
              }

              showDatesMsg(ds + " -> " + res.data.status, false);
              renderCalendar();
            })
            .catch(() => {
              togglePending = false;
              cell.style.opacity = "";
              showDatesMsg("Network error. Please try again.", true);
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

  // Settings → Google Calendar: push a sample event to the connected
  // calendar so the admin can confirm the connection end to end.
  function bindGcalTestEvent() {
    const btn = document.getElementById("fpb-gcal-test");
    const msg = document.getElementById("fpb-gcal-test-msg");
    if (!btn) return;

    btn.addEventListener("click", () => {
      btn.disabled = true;
      if (msg) {
        msg.className = "fpb-gcal-test-msg";
        msg.textContent = "Sending…";
      }

      post("snapbook_gcal_test_event", {})
        .then((res) => {
          if (!msg) return;
          const data = res.data || {};
          msg.className =
            "fpb-gcal-test-msg " + (res.success ? "is-ok" : "is-err");
          msg.textContent = data.message || (res.success ? "Done." : "Failed.");
          if (res.success && data.link) {
            const a = document.createElement("a");
            a.href = data.link;
            a.target = "_blank";
            a.rel = "noopener noreferrer";
            a.textContent = "View event";
            msg.append(" ", a);
          }
        })
        .catch((e) => {
          if (msg) {
            msg.className = "fpb-gcal-test-msg is-err";
            msg.textContent = e.message || "Request failed.";
          }
        })
        .finally(() => {
          btn.disabled = false;
        });
    });
  }

  bindBookingStatusControls();
  bindRowActionMenus();
  bindWooOrderStatusControls();
  bindBookingModal();
  bindAddBookingForm();
  bindSessionSlugHelper();
  bindCheckoutFieldBuilder();
  bindSettingsForm();
  bindSettingsSections();
  bindSettingsSearch();
  bindRequires();
  bindCopyButtons();
  bindHelpTips();
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
  bindReminderSettings();
  bindBalancePayLinkCopy();
  bindGcalTestEvent();
})();
