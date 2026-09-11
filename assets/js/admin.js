(function () {
  "use strict";

  const cfg = window.PEYVAST_AUTH_ADMIN || {};
  const state = { activeTab: "", saving: false, dirty: false };
  const $ = (selector, root = document) =>
    root?.querySelector?.(selector) || null;
  const $$ = (selector, root = document) =>
    root?.querySelectorAll ? Array.from(root.querySelectorAll(selector)) : [];
  const attr = (el, name) => el?.getAttribute?.(`data-${name}`) || "";

  const escapeSelector = (value) =>
    window.CSS?.escape
      ? CSS.escape(String(value))
      : String(value).replace(/([:\[\].,#>+~*='\s])/g, "\\$1");

  function setDirty(value) {
    state.dirty = !!value;
    const root = $("[data-peyvast-auth-admin]");
    root?.classList.toggle("has-unsaved", state.dirty);
    const stateEl = $("[data-peyvast-auth-save-state]");
    if (stateEl && !state.saving) {
      stateEl.textContent = state.dirty ? cfg.unsaved || "Unsaved changes" : "";
      stateEl.classList.toggle("is-unsaved", state.dirty);
      stateEl.classList.remove("is-success", "is-error");
    }
  }

  function setSaving(value) {
    state.saving = !!value;
    const root = $("[data-peyvast-auth-admin]");
    const button = $("[data-peyvast-auth-save]");
    const label = $("[data-peyvast-auth-save-label]");
    root?.classList.toggle("is-saving", state.saving);
    if (button) {
      button.disabled = state.saving;
      button.setAttribute("aria-busy", state.saving ? "true" : "false");
    }
    if (label)
      label.textContent = state.saving
        ? cfg.saving || "Saving changes…"
        : cfg.saveLabel || "Save changes";
  }

  function setSaveResult(ok, message) {
    const stateEl = $("[data-peyvast-auth-save-state]");
    if (!stateEl) return;
    stateEl.textContent = message || "";
    stateEl.classList.remove("is-unsaved", "is-error", "is-success");
    if (message) stateEl.classList.add(ok ? "is-success" : "is-error");
  }

  function tabId(button) {
    return button?.getAttribute("data-peyvast-auth-tab") || "";
  }
  function panelId(panel) {
    return panel?.getAttribute("data-peyvast-auth-panel") || "";
  }

  function setTab(tab, push = true) {
    const button = $(`[data-peyvast-auth-tab="${escapeSelector(tab)}"]`);
    const panel = $(`[data-peyvast-auth-panel="${escapeSelector(tab)}"]`);
    if (!button || !panel) return false;
    state.activeTab = tab;
    $$("[data-peyvast-auth-tab]").forEach((item) => {
      const active = item === button;
      item.classList.toggle("is-active", active);
      item.setAttribute("aria-selected", active ? "true" : "false");
      item.setAttribute("tabindex", active ? "0" : "-1");
    });
    $$("[data-peyvast-auth-panel]").forEach((item) => {
      const active = item === panel;
      item.classList.toggle("is-active", active);
      item.hidden = !active;
      item.setAttribute("aria-hidden", active ? "false" : "true");
    });
    const title = $("[data-peyvast-auth-current-title]");
    const description = $("[data-peyvast-auth-current-description]");
    if (title)
      title.textContent =
        button.querySelector(".peyvast-auth-nav-item__copy strong")?.textContent ||
        "";
    if (description)
      description.textContent = button.getAttribute("data-description") || "";
    if (push) {
      const url = new URL(window.location.href);
      url.searchParams.set("tab", tab);
      window.history.pushState({ peyvastAuthTab: tab }, "", url.toString());
    }
    return true;
  }

  function initTabs() {
    const buttons = $$("[data-peyvast-auth-tab]");
    const panels = $$("[data-peyvast-auth-panel]");
    if (!buttons.length || !panels.length) return;
    const url = new URL(window.location.href);
    const requested =
      url.searchParams.get("tab") ||
      tabId(
        buttons.find((b) => b.classList.contains("is-active")) || buttons[0],
      );
    setTab(requested, false) || setTab(tabId(buttons[0]), false);
    buttons.forEach((button) =>
      button.addEventListener("click", () => setTab(tabId(button))),
    );
    window.addEventListener("popstate", () => {
      const current =
        new URL(window.location.href).searchParams.get("tab") ||
        tabId(buttons[0]);
      setTab(current, false);
    });
    buttons.forEach((button, index) =>
      button.addEventListener("keydown", (event) => {
        if (!["ArrowDown", "ArrowUp", "Home", "End"].includes(event.key))
          return;
        let next = index;
        if (event.key === "ArrowDown")
          next = Math.min(index + 1, buttons.length - 1);
        if (event.key === "ArrowUp") next = Math.max(index - 1, 0);
        if (event.key === "Home") next = 0;
        if (event.key === "End") next = buttons.length - 1;
        event.preventDefault();
        buttons[next].focus();
        setTab(tabId(buttons[next]));
      }),
    );
  }

  function readControlValue(control) {
    if (!control) return "";
    if (control.type === "checkbox")
      return control.checked ? control.value || "1" : "0";
    return control.value || "";
  }

  function initConditions() {
    const update = () => {
      $$("[data-peyvast-auth-condition-field]").forEach((wrapper) => {
        if (wrapper.hasAttribute("data-peyvast-auth-phone-source-field")) return;
        const field = attr(wrapper, "peyvast-auth-condition-field");
        const expected = attr(wrapper, "peyvast-auth-condition-value");
        const control = $(`[name="${escapeSelector(field)}"]`);
        wrapper.hidden = readControlValue(control) !== expected;
      });
    };
    $$('[name^="peyvast_auth_settings["]').forEach((control) => {
      control.addEventListener("change", update);
      control.addEventListener("input", update);
    });
    update();
  }

  function initProviderPicker() {
    const buttons = $$("[data-peyvast-auth-provider-select]");
    const panels = $$("[data-peyvast-auth-provider-panel]");
    const value = $("[data-peyvast-auth-provider-value]");
    if (!buttons.length || !value) return;
    const activate = (provider, markDirty = true) => {
      value.value = provider || "none";
      buttons.forEach((button) => {
        const active =
          attr(button, "peyvast-auth-provider-select") === value.value;
        button.classList.toggle("is-active", active);
        button.setAttribute("aria-pressed", active ? "true" : "false");
      });
      panels.forEach((panel) => {
        const active = attr(panel, "peyvast-auth-provider-panel") === value.value;
        panel.classList.toggle("is-active", active);
        panel.hidden = !active;
        $$("input,select,textarea", panel).forEach((control) => {
          control.disabled = !active;
        });
      });
      if (markDirty) setDirty(true);
    };
    buttons.forEach((button) =>
      button.addEventListener("click", () =>
        activate(attr(button, "peyvast-auth-provider-select")),
      ),
    );
    activate(value.value || "none", false);
  }

  function markFormDirty() {
    const form = $("[data-peyvast-auth-settings-form]");
    if (!form) return;
    $$("input,select,textarea", form).forEach((control) => {
      control.addEventListener(
        control.type === "checkbox" ? "change" : "input",
        () => setDirty(true),
      );
      if (control.type !== "checkbox")
        control.addEventListener("change", () => setDirty(true));
    });
  }

  function serializeForm(form) {
    const payload = {};
    $$("input,select,textarea", form).forEach((control) => {
      if (
        !control.name ||
        control.disabled ||
        control.type === "submit" ||
        control.type === "button"
      )
        return;
      if (!control.name.startsWith("peyvast_auth_settings")) return;
      const parts = [];
      control.name.replace(/\[([^\]]+)\]/g, (_, key) => {
        parts.push(key);
        return "";
      });
      if (!parts.length) return;
      let target = payload;
      parts.forEach((key, index) => {
        if (index === parts.length - 1) {
          target[key] = readControlValue(control);
          return;
        }
        if (!target[key] || typeof target[key] !== "object") target[key] = {};
        target = target[key];
      });
    });
    return { peyvast_auth_settings: payload };
  }

  async function saveAll() {
    if (state.saving) return;
    const form = $("[data-peyvast-auth-settings-form]");
    if (!form) return;
    setSaving(true);
    setSaveResult(false, "");
    try {
      const response = await fetch(
        `${String(cfg.rest || "").replace(/\/$/, "")}/settings`,
        {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-WP-Nonce": cfg.rest_nonce || "",
          },
          body: JSON.stringify(
            Object.assign({}, serializeForm(form), { nonce: cfg.nonce || "" }),
          ),
        },
      );
      const data = await response.json();
      if (!data.success)
        throw new Error(
          data.data?.message || cfg.error || "Could not save settings.",
        );
      state.dirty = false;
      setDirty(false);
      setSaveResult(
        true,
        data.data?.message || cfg.saved || "Settings saved successfully.",
      );
      window.dispatchEvent(
        new CustomEvent("peyvast-auth-settings-saved", {
          detail: data.data || {},
        }),
      );
    } catch (error) {
      state.dirty = true;
      setDirty(true);
      setSaveResult(
        false,
        error.message || cfg.error || "Could not save settings.",
      );
    } finally {
      setSaving(false);
    }
  }

  function initSave() {
    const form = $("[data-peyvast-auth-settings-form]");
    const button = $("[data-peyvast-auth-save]");
    if (!form || !button) return;
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      saveAll();
    });
    button.addEventListener("click", (event) => {
      event.preventDefault();
      saveAll();
    });
    markFormDirty();
  }
  function canonicalPhoneForAdminValidation(value) {
    const fa = "۰۱۲۳۴۵۶۷۸۹";
    const ar = "٠١٢٣٤٥٦٧٨٩";
    let v = String(value || "").trim();
    [...fa].forEach((c, i) => {
      v = v.replaceAll(c, String(i));
    });
    [...ar].forEach((c, i) => {
      v = v.replaceAll(c, String(i));
    });
    return v;
  }

  function initProviderTest() {
    const smsButton = $("[data-peyvast-auth-test-provider]");
    const phone = $("[data-peyvast-auth-test-phone]");
    const smsResult = $("[data-peyvast-auth-test-result]");
    const emailButton = $("[data-peyvast-auth-test-email-provider]");
    const email = $("[data-peyvast-auth-test-email]");
    const emailResult = $("[data-peyvast-auth-test-email-result]");

    if (smsButton && phone) {
      smsButton.addEventListener("click", async () => {
        const digits = canonicalPhoneForAdminValidation(phone.value);
        if (!/^(?:09\d{9}|98(?:9\d{9})|0098(?:9\d{9})|\+98(?:9\d{9}))$/.test(digits)) {
          if (smsResult) smsResult.textContent = cfg.invalidPhone || "Please enter a valid Iranian mobile number.";
          return;
        }
        smsButton.disabled = true;
        if (smsResult) smsResult.textContent = cfg.testing || "Sending…";
        try {
          const response = await fetch(`${String(cfg.rest || "").replace(/\/$/, "")}/test-provider`, {
            method: "POST", credentials: "same-origin",
            headers: { Accept: "application/json", "Content-Type": "application/json", "X-WP-Nonce": cfg.rest_nonce || "" },
            body: JSON.stringify({ channel: "sms", phone: digits, provider: $("[data-peyvast-auth-provider-value]")?.value || "none", nonce: cfg.nonce || "" }),
          });
          const data = await response.json();
          if (!data.success) throw new Error(data.data?.message || cfg.testError || "Provider test failed.");
          if (smsResult) smsResult.textContent = data.data?.message || cfg.testSuccess || "Test message sent successfully.";
        } catch (error) {
          if (smsResult) smsResult.textContent = error.message || cfg.testError || "Provider test failed.";
        } finally { smsButton.disabled = false; }
      });
    }

    if (emailButton && email) {
      emailButton.addEventListener("click", async () => {
        const address = String(email.value || "").trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(address)) {
          if (emailResult) emailResult.textContent = cfg.invalidEmail || "Please enter a valid email address.";
          return;
        }
        emailButton.disabled = true;
        if (emailResult) emailResult.textContent = cfg.testing || "Sending…";
        try {
          const response = await fetch(`${String(cfg.rest || "").replace(/\/$/, "")}/test-provider`, {
            method: "POST", credentials: "same-origin",
            headers: { Accept: "application/json", "Content-Type": "application/json", "X-WP-Nonce": cfg.rest_nonce || "" },
            body: JSON.stringify({ channel: "email", email: address, nonce: cfg.nonce || "" }),
          });
          const data = await response.json();
          if (!data.success) throw new Error(data.data?.message || cfg.testError || "Provider test failed.");
          if (emailResult) emailResult.textContent = data.data?.message || cfg.testSuccess || "Test email sent successfully.";
        } catch (error) {
          if (emailResult) emailResult.textContent = error.message || cfg.testError || "Provider test failed.";
        } finally { emailButton.disabled = false; }
      });
    }
  }

  function initLogViewer() {
    const modal = $("[data-peyvast-auth-log-modal]");
    const body = $("[data-peyvast-auth-log-modal-body]");
    if (!modal || !body) return;
    const labels = cfg.logLabels || {};
    const close = () => {
      modal.hidden = true;
      document.body.classList.remove("peyvast-auth-log-modal-open");
    };
    $$("[data-peyvast-auth-log-close]", modal).forEach((button) =>
      button.addEventListener("click", close),
    );
    document.addEventListener("keydown", (event) => {
      if (event.key === "Escape" && !modal.hidden) close();
    });
    $$("[data-peyvast-auth-log-view]").forEach((button) =>
      button.addEventListener("click", () => {
        let data = {};
        try {
          data = JSON.parse(button.getAttribute("data-log") || "{}");
        } catch (e) {
          data = {};
        }
        body.replaceChildren();
        const grid = document.createElement("div");
        grid.className = "peyvast-auth-log-modal__grid";
        [
          ["id", labels.id],
          ["time", labels.time],
          ["level", labels.level],
          ["channel", labels.channel],
          ["event", labels.event],
          ["provider", labels.provider],
          ["user_id", labels.user],
          ["request_id", labels.request],
        ].forEach(([key, label]) => {
          const item = document.createElement("div");
          item.className = "peyvast-auth-log-modal__item";
          const l = document.createElement("span");
          l.className = "peyvast-auth-log-modal__label";
          l.textContent = label || key;
          const v = document.createElement("span");
          v.className = "peyvast-auth-log-modal__value";
          v.textContent = String(data[key] || "—");
          item.append(l, v);
          grid.appendChild(item);
        });
        const message = document.createElement("div");
        message.className = "peyvast-auth-log-modal__message";
        message.textContent = String(data.message || "—");
        body.appendChild(grid);
        body.appendChild(message);

        const events = Array.isArray(data.context?.events)
          ? data.context.events
          : [];
        if (events.length) {
          const title = document.createElement("h3");
          title.className = "peyvast-auth-log-modal__section-title";
          title.textContent = cfg.logTimelineTitle || "Request timeline";
          body.appendChild(title);
          const timeline = document.createElement("ol");
          timeline.className = "peyvast-auth-log-timeline";
          events.forEach((event) => {
            const item = document.createElement("li");
            const head = document.createElement("div");
            head.className = "peyvast-auth-log-timeline__head";
            const meta = document.createElement("span");
            meta.textContent = [
              event.at,
              event.level,
              event.channel,
              event.event,
            ]
              .filter(Boolean)
              .join(" · ");
            const provider = document.createElement("span");
            provider.textContent = event.provider ? String(event.provider) : "";
            head.append(meta, provider);
            const itemMessage = document.createElement("div");
            itemMessage.className = "peyvast-auth-log-timeline__message";
            itemMessage.textContent = String(event.message || "—");
            item.appendChild(head);
            item.appendChild(itemMessage);
            if (
              event.context &&
              typeof event.context === "object" &&
              Object.keys(event.context).length
            ) {
              const eventPre = document.createElement("pre");
              eventPre.className = "peyvast-auth-log-timeline__context";
              eventPre.textContent = JSON.stringify(event.context, null, 2);
              item.appendChild(eventPre);
            }
            timeline.appendChild(item);
          });
          body.appendChild(timeline);
        }

        const contextTitle = document.createElement("h3");
        contextTitle.className = "peyvast-auth-log-modal__section-title";
        contextTitle.textContent =
          cfg.logContextTitle || "Full request context";
        body.appendChild(contextTitle);
        const safeContext = JSON.parse(JSON.stringify(data.context || {}));
        const markOmitted = (value, key) => {
          if (!value || typeof value !== "object") return;
          Object.keys(value).forEach((childKey) => {
            if (
              ["body", "request_body", "raw_body"].includes(
                childKey.toLowerCase().replace(/[ -]/g, "_"),
              )
            )
              value[childKey] = "[omitted]";
            else if (
              ["response_body", "raw_response_body"].includes(
                childKey.toLowerCase().replace(/[ -]/g, "_"),
              )
            )
              value[childKey] = "[omitted]";
            else markOmitted(value[childKey], childKey);
          });
        };
        markOmitted(safeContext, "context");
        const pre = document.createElement("pre");
        pre.className = "peyvast-auth-log-modal__context";
        pre.textContent = JSON.stringify(safeContext, null, 2);
        body.appendChild(pre);
        modal.hidden = false;
        document.body.classList.add("peyvast-auth-log-modal-open");
        $("[data-peyvast-auth-log-close]", modal)?.focus();
      }),
    );
  }

  async function clearSecurityBlocks(button, result) {
    if (!button || button.disabled) return;
    if (
      !window.confirm(
        cfg.securityBlockConfirm ||
          "Clear all active security blocks? Blocks and rate-limit counters will be reset.",
      )
    )
      return;
    button.disabled = true;
    if (result) {
      result.textContent = cfg.securityBlocking || "Clearing blocks…";
      result.classList.remove("is-success", "is-error");
      result.classList.add("is-loading");
    }
    try {
      const response = await fetch(
        `${String(cfg.rest || "").replace(/\/$/, "")}/security-blocks`,
        {
          method: "POST",
          credentials: "same-origin",
          headers: {
            Accept: "application/json",
            "Content-Type": "application/json",
            "X-WP-Nonce": cfg.rest_nonce || "",
          },
          body: JSON.stringify({ nonce: cfg.nonce || "" }),
        },
      );
      const data = await response.json();
      if (!data.success)
        throw new Error(
          data.data?.message ||
            cfg.securityBlockError ||
            "Security blocks could not be cleared.",
        );
      if (result) {
        result.textContent =
          cfg.securityBlockedCleared ||
          "All security blocks and rate-limit counters were cleared.";
        result.classList.remove("is-loading");
        result.classList.add("is-success");
      }
    } catch (error) {
      if (result) {
        result.textContent =
          error.message ||
          cfg.securityBlockError ||
          "Security blocks could not be cleared.";
        result.classList.remove("is-loading");
        result.classList.add("is-error");
      }
    } finally {
      button.disabled = false;
    }
  }

  function initAdminSecurity() {
    const button = $("[data-peyvast-auth-clear-security]");
    if (!button) return;
    button.addEventListener("click", () =>
      clearSecurityBlocks(button, $("[data-peyvast-auth-clear-security-result]")),
    );
  }

  function init() {
    initTabs();
    initConditions();
    initProviderPicker();
    initSave();
    initAdminSecurity();
    initProviderTest();
    initLogViewer();
    initPhoneMetaSource();
  }

  function initPhoneMetaSource() {
    const type = $("[data-peyvast-auth-phone-source-type]");
    const existing = $("[data-peyvast-auth-phone-meta-existing]");
    const manual = $("[data-peyvast-auth-phone-meta-manual]");
    const validate = $("[data-peyvast-auth-validate-phone-meta]");
    const output = $("[data-peyvast-auth-phone-meta-result]");
    if (!type || (!existing && !manual)) return;

    const sourceFields = $$("[data-peyvast-auth-phone-source-field]");
    const sync = () => {
      const mode = ["off", "existing_meta", "manual_meta"].includes(type.value)
        ? type.value
        : "off";
      sourceFields.forEach((field) => {
        const active =
          mode !== "off" &&
          attr(field, "peyvast-auth-phone-source-field") === mode;
        field.hidden = !active;
        field.setAttribute("aria-hidden", active ? "false" : "true");
      });
      if (existing) existing.disabled = mode !== "existing_meta";
      if (manual) manual.disabled = mode !== "manual_meta";
    };
    type.addEventListener("change", sync);
    sync();

    if (validate) {
      validate.addEventListener("click", async () => {
        const active = type.value === "manual_meta" ? manual : existing;
        const key = String(active?.value || "").trim();
        if (!key) {
          if (output) {
            output.textContent =
              cfg.phoneMetaEmpty ||
              "Select an existing user meta key or enter a manual key first.";
            output.className = "peyvast-auth-inline-result is-error";
          }
          return;
        }
        validate.disabled = true;
        if (output) {
          output.textContent =
            cfg.phoneMetaChecking || "Checking sample values…";
          output.className = "peyvast-auth-inline-result is-loading";
        }
        try {
          const response = await fetch(
            `${String(cfg.rest || "").replace(/\/$/, "")}/validate-phone-meta`,
            {
              method: "POST",
              credentials: "same-origin",
              headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-WP-Nonce": cfg.rest_nonce || "",
              },
              body: JSON.stringify({ meta_key: key, nonce: cfg.nonce || "" }),
            },
          );
          const data = await response.json();
          if (!data.success)
            throw new Error(
              data.data?.message || cfg.phoneMetaError || "Validation failed.",
            );
          const d = data.data || {};
          let msg;
          if (d.status === "valid")
            msg =
              cfg.phoneMetaValid ||
              "Valid — %1$d/%2$d values recognized as valid phone numbers.";
          else if (d.status === "partial")
            msg =
              cfg.phoneMetaPartial ||
              "Partial — %1$d/%2$d values were recognized as valid phone numbers.";
          else
            msg =
              cfg.phoneMetaInvalid ||
              "Invalid — %3$d/%2$d values could not be recognized as valid phone numbers.";
          msg = msg
            .replace("%1$d", d.valid_count)
            .replace("%2$d", d.sample_count)
            .replace("%3$d", d.invalid_count);
          if (output) {
            output.textContent = msg;
            output.className = `peyvast-auth-inline-result is-${d.status}`;
          }
        } catch (error) {
          if (output) {
            output.textContent =
              error.message || cfg.phoneMetaError || "Validation failed.";
            output.className = "peyvast-auth-inline-result is-error";
          }
        } finally {
          validate.disabled = false;
        }
      });
    }
  }

  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", init, { once: true });
  else init();
})();
