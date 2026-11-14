/*
 * Terminbuchung: Tag → Uhrzeit → Angaben → Bestätigung.
 *
 * Rechenkern und Ablauf übernommen aus core (apps/website/src/lib/booking.ts und
 * apps/website/src/components/Booking.astro, Stand 13.11.2026), ohne Framework und ohne
 * Build-Schritt. Ein Tag ist überall der Schlüssel `YYYY-MM-DD` in der Zeitzone des Betriebs,
 * nie ein Date: So entscheidet nicht die Zeitzone des Browsers, an welchem Tag ein Slot liegt.
 * Texte kommen aus dem Template (Übersetzungen), nicht aus diesem Skript.
 */
(() => {
  "use strict";

  // ─── Rechenkern (booking.ts) ──────────────────────────────────────────────

  const pad = (value) => String(value).padStart(2, "0");

  function isValidTimezone(timezone) {
    try {
      new Intl.DateTimeFormat("en-US", { timeZone: timezone });
      return true;
    } catch {
      return false;
    }
  }

  function wallClock(ms, timezone) {
    const parts = new Intl.DateTimeFormat("en-US", {
      timeZone: timezone,
      hourCycle: "h23",
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    }).formatToParts(new Date(ms));
    const read = (type) => Number(parts.find((part) => part.type === type)?.value ?? 0);
    return {
      year: read("year"),
      month: read("month"),
      day: read("day"),
      hour: read("hour"),
      minute: read("minute"),
    };
  }

  function dayKeyOf(ms, timezone) {
    const clock = wallClock(ms, timezone);
    return `${clock.year}-${pad(clock.month)}-${pad(clock.day)}`;
  }

  const dayKey = (year, month, day) => `${year}-${pad(month)}-${pad(day)}`;

  function monthOfKey(key) {
    const [year, month] = key.split("-").map(Number);
    return { year, month };
  }

  function shiftMonth(value, delta) {
    const index = value.year * 12 + (value.month - 1) + delta;
    return { year: Math.floor(index / 12), month: (index % 12) + 1 };
  }

  const compareMonths = (a, b) => a.year * 12 + a.month - (b.year * 12 + b.month);

  /** Raster eines Monats, Woche ab Montag, immer volle Wochen; null für leere Felder. */
  function monthCells(value) {
    const first = new Date(Date.UTC(value.year, value.month - 1, 1));
    const lead = (first.getUTCDay() + 6) % 7;
    const days = new Date(Date.UTC(value.year, value.month, 0)).getUTCDate();
    const cells = Array.from({ length: lead }, () => null);
    for (let day = 1; day <= days; day++) cells.push(dayKey(value.year, value.month, day));
    while (cells.length % 7 !== 0) cells.push(null);
    return cells;
  }

  function groupByDay(slots, timezone) {
    const days = new Map();
    for (const start of [...slots].sort((a, b) => a - b)) {
      const key = dayKeyOf(start, timezone);
      const list = days.get(key);
      if (list) list.push(start);
      else days.set(key, [start]);
    }
    return days;
  }

  function parseSlotAnswer(value) {
    if (!value || typeof value !== "object") return null;
    const { timezone, slotMinutes, slots } = value;
    if (typeof timezone !== "string" || !isValidTimezone(timezone)) return null;
    if (typeof slotMinutes !== "number" || !Number.isFinite(slotMinutes) || slotMinutes <= 0)
      return null;
    if (!Array.isArray(slots)) return null;
    return {
      timezone,
      slotMinutes,
      slots: slots.filter((slot) => typeof slot === "number" && Number.isFinite(slot)),
    };
  }

  // ─── Ablauf (Booking.astro) ───────────────────────────────────────────────

  function mount(root) {
    const texts = JSON.parse(root.querySelector("[data-booking-texts]")?.textContent || "{}");
    const q = (selector) => root.querySelector(selector);
    const grid = q("[data-grid]");
    const times = q("[data-times]");
    const status = q("[data-status]");
    const form = q('[data-step="details"]');
    const submit = q("[data-submit]");
    const steps = [...root.querySelectorAll("[data-step]")];
    const locale = texts.locale === "en" ? "en-GB" : "de-DE";
    const fallback = texts.fallback ? ` ${texts.fallback}` : "";

    let timezone = "Europe/Berlin";
    let slotMinutes = 30;
    let slots = [];
    let days = new Map();
    let month = null;
    let day = null;
    let slot = null;

    const format = (options) => new Intl.DateTimeFormat(locale, options);
    const keyToUtc = (key) => {
      const [year, m, d] = key.split("-").map(Number);
      return Date.UTC(year, m - 1, d);
    };
    const dayLabel = (key) =>
      format({ weekday: "long", day: "numeric", month: "long", timeZone: "UTC" }).format(
        keyToUtc(key),
      );
    const timeText = (ms) =>
      format({ hour: "2-digit", minute: "2-digit", timeZone: timezone }).format(ms);
    const chosenText = (start) =>
      (texts.range || "%day%, %start% – %end%")
        .replace("%day%", dayLabel(dayKeyOf(start, timezone)))
        .replace("%start%", timeText(start))
        .replace("%end%", timeText(start + slotMinutes * 60000));
    const show = (selector, visible) => {
      q(selector).hidden = !visible;
    };
    const go = (name) =>
      steps.forEach((el) => {
        el.hidden = el.dataset.step !== name;
      });

    function render() {
      days = groupByDay(slots, timezone);
      const keys = [...days.keys()];
      const first = keys.length ? monthOfKey(keys[0]) : month;
      const last = keys.length ? monthOfKey(keys[keys.length - 1]) : month;
      q("[data-month-label]").textContent = month
        ? format({ month: "long", year: "numeric", timeZone: "UTC" }).format(
            Date.UTC(month.year, month.month - 1, 1),
          )
        : "";
      q('[data-month="-1"]').disabled = !month || !first || compareMonths(month, first) <= 0;
      q('[data-month="1"]').disabled = !month || !last || compareMonths(month, last) >= 0;

      grid.querySelectorAll("[data-cell]").forEach((el) => el.remove());
      for (const key of month ? monthCells(month) : []) {
        const free = key !== null && days.has(key);
        const cell = document.createElement(free ? "button" : "span");
        cell.setAttribute("data-cell", "");
        cell.className = free ? "booking__daybtn" : "booking__dayoff";
        if (key) cell.textContent = String(Number(key.slice(8)));
        if (free) {
          cell.type = "button";
          cell.dataset.day = key;
          cell.setAttribute("aria-label", dayLabel(key));
          cell.setAttribute("aria-pressed", String(key === day));
        }
        grid.append(cell);
      }

      times.replaceChildren();
      const label = q("[data-day-label]");
      label.hidden = !day;
      if (day) {
        label.textContent = dayLabel(day);
        for (const start of days.get(day) ?? []) {
          const button = document.createElement("button");
          button.type = "button";
          button.className = "booking__time";
          button.dataset.slot = String(start);
          button.textContent = timeText(start);
          times.append(button);
        }
      }
    }

    fetch(root.dataset.slots, { headers: { Accept: "application/json" } })
      .then((response) => (response.ok ? response.json() : null))
      .then((body) => {
        const answer = parseSlotAnswer(body);
        if (!answer) throw new Error("slots");
        timezone = answer.timezone;
        slotMinutes = answer.slotMinutes;
        const now = Date.now();
        slots = answer.slots.filter((start) => start > now).sort((a, b) => a - b);
        const firstDay = slots.length ? dayKeyOf(slots[0], timezone) : dayKeyOf(now, timezone);
        month = monthOfKey(firstDay);
        day = slots.length ? firstDay : null;
        status.hidden = slots.length > 0;
        status.textContent = `${texts.none}${fallback}`;
        const zone = q("[data-zone]");
        zone.hidden = Intl.DateTimeFormat().resolvedOptions().timeZone === timezone;
        zone.textContent = (texts.zone || "%zone%").replace("%zone%", timezone);
        root.classList.add("is-ready");
        render();
      })
      .catch(() => {
        status.textContent = `${texts.failed}${fallback}`;
      });

    root.addEventListener("click", (event) => {
      const target = event.target.closest("[data-day], [data-slot], [data-month], [data-change]");
      if (!target || !root.contains(target)) return;
      if (target.dataset.day) {
        day = target.dataset.day;
        show("[data-taken]", false);
        render();
        times.querySelector("button")?.focus({ preventScroll: true });
      } else if (target.dataset.slot) {
        slot = Number(target.dataset.slot);
        q("[data-chosen]").textContent = chosenText(slot);
        ["[data-error]", "[data-limit]", "[data-invalid]"].forEach((selector) =>
          show(selector, false),
        );
        go("details");
        form.querySelector('input[name="name"]').focus();
      } else if (target.dataset.month && month) {
        month = shiftMonth(month, Number(target.dataset.month));
        day = [...days.keys()].find((key) => compareMonths(monthOfKey(key), month) === 0) ?? null;
        render();
      } else if (target.hasAttribute("data-change")) {
        go("pick");
      }
    });

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (slot === null || submit.disabled) return;
      const data = new FormData(form);
      const text = (name) => String(data.get(name) ?? "").trim();
      submit.disabled = true;
      submit.textContent = texts.loading;
      ["[data-error]", "[data-limit]", "[data-invalid]"].forEach((selector) =>
        show(selector, false),
      );
      let outcome = "error";
      try {
        const response = await fetch(root.dataset.action, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json" },
          body: JSON.stringify({
            start: slot,
            name: text("name"),
            email: text("email"),
            phone: text("phone"),
            note: text("note"),
            privacy: data.get("privacy") !== null,
            locale: texts.locale,
            _hp: text("_hp"),
          }),
        });
        const body = await response.json().catch(() => null);
        outcome = response.ok && body?.ok ? "ok" : (body?.reason ?? "error");
      } catch {
        outcome = "error";
      }
      submit.disabled = false;
      submit.textContent = texts.submit;
      if (outcome === "ok") {
        q("[data-booked]").textContent = chosenText(slot);
        go("done");
        q('[data-step="done"]').focus();
      } else if (outcome === "taken") {
        slots = slots.filter((start) => start !== slot);
        slot = null;
        render();
        show("[data-taken]", true);
        go("pick");
      } else if (outcome === "limit") {
        show("[data-limit]", true);
      } else if (outcome === "invalid" || outcome === "consent") {
        show("[data-invalid]", true);
      } else {
        show("[data-error]", true);
      }
    });
  }

  const start = () => document.querySelectorAll("[data-booking]").forEach(mount);
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", start);
  else start();
})();
