/* Shared components + helpers. Exported to window at end. */

const SCHOOL_MAP = {};
window.SeedData.schools.forEach((s) => (SCHOOL_MAP[s.id] = s));

function schoolOf(id) { return SCHOOL_MAP[id]; }

function fmtDate(iso) {
  if (!iso) return "—";
  const d = new Date(iso + "T00:00:00");
  return d.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
}
function relDate(iso) {
  if (!iso) return "";
  const days = Math.round((new Date("2026-05-28") - new Date(iso + "T00:00:00")) / 86400000);
  if (days <= 0) return "today";
  if (days === 1) return "yesterday";
  if (days < 30) return days + "d ago";
  if (days < 365) return Math.round(days / 30) + "mo ago";
  return (days / 365).toFixed(1) + "y ago";
}

function pctTone(p) {
  if (p >= 85) return "var(--rose)";
  if (p >= 65) return "var(--amber)";
  return "var(--teal)";
}

// amps for a UPS unit: load% × VA ÷ 120V
function upsAmps(u) {
  const va = +u.va || 0, load = +u.loadPct || 0;
  return va ? (load / 100 * (va / 120)).toFixed(1) : "0.0";
}

// Migrate any closet.power to the multi-unit shape { upsList, pduList, circuits }.
function normalizePower(p) {
  if (!p) return { upsList: [], pduList: [], circuits: [] };
  if (p.upsList || p.pduList) {
    return { upsList: p.upsList || [], pduList: p.pduList || [], circuits: p.circuits || [] };
  }
  const upsList = [];
  if (p.ups && (p.ups.va > 0 || (p.ups.model && p.ups.model !== "\u2014"))) {
    upsList.push({
      model: p.ups.model, va: p.ups.va, loadPct: p.ups.loadPct,
      batteryHealthPct: p.ups.batteryHealthPct, runtimeMin: p.ups.runtimeMin,
    });
  }
  const pduList = [];
  for (let i = 0; i < (p.pdus || 0); i++) pduList.push({ model: p.pduModel || "\u2014", outlets: 16, loadAmps: null });
  return { upsList, pduList, circuits: p.circuits || [] };
}

function SchoolTag({ id }) {
  const s = schoolOf(id);
  if (!s) return null;
  return React.createElement("span", { className: "school-tag", style: { background: s.color.bg, color: s.color.fg } }, id);
}

function TypeBadge({ type }) {
  return React.createElement("span", { className: "tbadge tbadge--" + type.toLowerCase() }, type);
}

function PortBar({ used, total, width = 64 }) {
  const pct = total ? Math.round((used / total) * 100) : 0;
  return React.createElement("div", { className: "portbar" },
    React.createElement("div", { className: "portbar__track", style: { width } },
      React.createElement("div", { className: "portbar__fill", style: { width: pct + "%", background: pctTone(pct) } })
    ),
    React.createElement("span", { className: "portbar__txt" }, `${used}/${total}`)
  );
}

function FlagDot() {
  return React.createElement("span", { className: "flag-dot", title: "Flagged for service" });
}

function Modal({ title, sub, icon, wide, onClose, children, footer }) {
  React.useEffect(() => {
    const h = (e) => { if (e.key === "Escape") onClose(); };
    window.addEventListener("keydown", h);
    return () => window.removeEventListener("keydown", h);
  }, [onClose]);
  return React.createElement("div", { className: "scrim", onMouseDown: (e) => { if (e.target === e.currentTarget) onClose(); } },
    React.createElement("div", { className: "modal" + (wide ? " modal--wide" : "") },
      React.createElement("div", { className: "modal__head" },
        icon && React.createElement("div", { className: "detail-icon", style: { width: 38, height: 38, borderRadius: 10 } }, icon),
        React.createElement("div", null,
          React.createElement("h2", null, title),
          sub && React.createElement("p", null, sub)
        ),
        React.createElement("button", { className: "btn btn--ghost btn--icon btn--sm modal__x", onClick: onClose },
          React.createElement(Ic.X, null))
      ),
      React.createElement("div", { className: "modal__body" }, children),
      footer && React.createElement("div", { className: "modal__foot" }, footer)
    )
  );
}

function Field({ label, req, hint, children }) {
  return React.createElement("div", { className: "field" },
    label && React.createElement("label", { className: "field__label" }, label, req && React.createElement("span", { className: "req" }, " *")),
    children,
    hint && React.createElement("div", { className: "field__hint" }, hint)
  );
}

function Toast({ msg, action, onAction, onClose }) {
  React.useEffect(() => {
    const t = setTimeout(onClose, 4200);
    return () => clearTimeout(t);
  }, []);
  return React.createElement("div", { className: "toast" },
    React.createElement(Ic.CheckCircle, null),
    React.createElement("span", null, msg),
    action && React.createElement("button", { onClick: onAction }, action)
  );
}

Object.assign(window, {
  SCHOOL_MAP, schoolOf, fmtDate, relDate, pctTone, upsAmps, normalizePower,
  SchoolTag, TypeBadge, PortBar, FlagDot, Modal, Field, Toast,
});
