/* App shell: sidebar, topbar, routing, persistence, modals, toasts. */
const { useState, useEffect, useMemo, useRef } = React;
const STORE_KEY = "scinv.closets.v2";

function loadClosets() {
  let list;
  try {
    const raw = localStorage.getItem(STORE_KEY);
    list = raw ? JSON.parse(raw) : JSON.parse(JSON.stringify(window.SeedData.closets));
  } catch (e) {
    list = JSON.parse(JSON.stringify(window.SeedData.closets));
  }
  return list.map((c) => ({ ...c, power: window.normalizePower(c.power) }));
}

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "dark": false,
  "accent": "Blue",
  "density": "regular"
}/*EDITMODE-END*/;

const ACCENT_HUE = { Blue: 256, Indigo: 277, Teal: 195, Violet: 305 };

function App() {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [closets, setClosets] = useState(loadClosets);
  const [route, setRoute] = useState({ view: "list", uid: null });
  const [query, setQuery] = useState("");
  const [modal, setModal] = useState(null); // {kind, closet}
  const [toasts, setToasts] = useState([]);
  const [navOpen, setNavOpen] = useState(false);
  const searchRef = useRef(null);

  useEffect(() => {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(closets)); } catch (e) {}
  }, [closets]);

  // apply theme / accent / density to <html>
  useEffect(() => {
    const el = document.documentElement;
    el.dataset.theme = t.dark ? "dark" : "light";
    el.dataset.density = t.density;
    const h = ACCENT_HUE[t.accent] != null ? ACCENT_HUE[t.accent] : 256;
    const s = el.style;
    if (t.dark) {
      s.setProperty("--primary", `oklch(0.66 0.15 ${h})`);
      s.setProperty("--primary-700", `oklch(0.80 0.12 ${h})`);
      s.setProperty("--primary-soft", `oklch(0.32 0.07 ${h})`);
      s.setProperty("--primary-line", `oklch(0.42 0.09 ${h})`);
    } else {
      s.setProperty("--primary", `oklch(0.56 0.15 ${h})`);
      s.setProperty("--primary-700", `oklch(0.48 0.15 ${h})`);
      s.setProperty("--primary-soft", `oklch(0.95 0.03 ${h})`);
      s.setProperty("--primary-line", `oklch(0.88 0.05 ${h})`);
    }
  }, [t.dark, t.accent, t.density]);

  useEffect(() => {
    const h = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "k") { e.preventDefault(); searchRef.current && searchRef.current.focus(); }
    };
    window.addEventListener("keydown", h);
    return () => window.removeEventListener("keydown", h);
  }, []);

  // recompute derived port totals for a closet
  const recompute = (c) => {
    const portsTotal = c.switches.reduce((a, s) => a + (+s.ports || 0), 0);
    const portsUsed = c.switches.reduce((a, s) => a + (+s.used || 0), 0);
    return { ...c, portsTotal, portsUsed, updated: "2026-05-28" };
  };

  const update = (uid, fn) => setClosets((cs) => cs.map((c) => (c.uid === uid ? recompute(fn(c)) : c)));

  const toast = (msg, action, onAction) => {
    const id = Math.random();
    setToasts((t) => [...t, { id, msg, action, onAction }]);
  };
  const dropToast = (id) => setToasts((t) => t.filter((x) => x.id !== id));

  const current = route.uid != null ? closets.find((c) => c.uid === route.uid) : null;
  useEffect(() => { window.scrollTo(0, 0); }, [route.view, route.uid]);

  // ---- handlers ----
  const openCloset = (c) => { setRoute({ view: "detail", uid: c.uid }); setNavOpen(false); };
  const goList = () => setRoute({ view: "list", uid: null });

  const saveCloset = (f) => {
    if (f.uid != null) {
      update(f.uid, (c) => ({ ...c, ...f }));
      toast("Closet updated.");
    } else {
      const nextSeq = closets.filter((c) => c.schoolId === f.schoolId).length;
      const code = f.type === "MDF" ? `${f.schoolId}-MDF` : `${f.schoolId}-IDF-${f.floor}${String(nextSeq).padStart(2, "0")}`;
      const nc = recompute({
        uid: Math.max(0, ...closets.map((c) => c.uid)) + 1,
        code, type: f.type, schoolId: f.schoolId, floor: +f.floor, room: f.room, building: f.building,
        switches: [], power: { upsList: [], pduList: [], circuits: [] },
        maintenance: [], photos: [], flagged: false, flagReason: null,
      });
      setClosets((cs) => [...cs, nc]);
      toast("Closet " + code + " created.", "Open", () => { setRoute({ view: "detail", uid: nc.uid }); });
    }
    setModal(null);
  };

  const doFlag = ({ reason, tech }) => {
    const c = modal.closet;
    update(c.uid, (x) => ({ ...x, flagged: true, flagReason: reason, flagTech: tech, flagDate: "2026-05-28" }));
    toast(c.code + " flagged for service.");
    setModal(null);
  };
  const resolveFlag = (c) => {
    update(c.uid, (x) => ({
      ...x, flagged: false, flagReason: null,
      maintenance: [{ date: "2026-05-28", type: "Service flag resolved", tone: "default", tech: "J. Whitfield", notes: "Resolved: " + (x.flagReason || "issue addressed.") }, ...x.maintenance],
    }));
    toast(c.code + " flag resolved.");
  };
  const addSwitch = (sw) => {
    const c = modal.closet;
    update(c.uid, (x) => ({ ...x, switches: [...x.switches, sw] }));
    toast("Switch added to " + c.code + ".");
    setModal(null);
  };
  const addMaint = (m) => {
    const c = modal.closet;
    update(c.uid, (x) => ({ ...x, maintenance: [m, ...x.maintenance].sort((a, b) => (a.date < b.date ? 1 : -1)) }));
    toast("Maintenance logged.");
    setModal(null);
  };
  const savePower = (power) => {
    const c = modal.closet;
    update(c.uid, (x) => ({ ...x, power }));
    toast("Power updated for " + c.code + ".");
    setModal(null);
  };

  const flaggedCount = closets.filter((c) => c.flagged).length;

  return React.createElement("div", { className: "app" + (navOpen ? " nav-open" : "") },
    // scrim for mobile nav
    React.createElement("div", { className: "scrim-nav", onClick: () => setNavOpen(false) }),

    // SIDEBAR
    React.createElement("aside", { className: "sidebar" },
      React.createElement("div", { className: "sidebar__brand" },
        React.createElement("div", { className: "brand-mark" }, React.createElement(Ic.Network, null)),
        React.createElement("div", null,
          React.createElement("div", { className: "brand-name" }, "Closet Inventory"),
          React.createElement("div", { className: "brand-sub" }, "Network Infrastructure"))
      ),
      React.createElement("nav", { className: "nav" },
        React.createElement("div", { className: "nav__label" }, "Inventory"),
        React.createElement("button", { className: "nav__item is-active", onClick: goList },
          React.createElement(Ic.Server, null), "Switch closets",
          React.createElement("span", { className: "nav__count" }, closets.length)),
        React.createElement("button", { className: "nav__item", onClick: goList },
          React.createElement(Ic.Switch, null), "Switches",
          React.createElement("span", { className: "nav__count" }, closets.reduce((a, c) => a + c.switches.length, 0))),
        React.createElement("button", { className: "nav__item", onClick: goList },
          React.createElement(Ic.Bolt, null), "Power"),
        React.createElement("div", { className: "nav__label" }, "Operations"),
        React.createElement("button", { className: "nav__item", onClick: () => { setRoute({ view: "list", uid: null }); } },
          React.createElement(Ic.Flag, null), "Service queue",
          flaggedCount > 0 && React.createElement("span", { className: "nav__count", style: { background: "var(--amber)", color: "#1a1206" } }, flaggedCount)),
        React.createElement("button", { className: "nav__item" }, React.createElement(Ic.Wrench, null), "Maintenance"),
        React.createElement("button", { className: "nav__item" }, React.createElement(Ic.Building, null), "Schools",
          React.createElement("span", { className: "nav__count" }, window.SeedData.schools.length))
      ),
      React.createElement("div", { className: "sidebar__foot" },
        React.createElement("div", { className: "user-chip" },
          React.createElement("div", { className: "avatar" }, "JW"),
          React.createElement("div", null,
            React.createElement("div", { className: "user-chip__name" }, "J. Whitfield"),
            React.createElement("div", { className: "user-chip__role" }, "Network Admin"))))
    ),

    // MAIN
    React.createElement("div", { className: "main" },
      React.createElement("header", { className: "topbar" },
        React.createElement("button", { className: "btn btn--ghost btn--icon topbar__menu", onClick: () => setNavOpen(true) }, React.createElement(Ic.Menu, null)),
        React.createElement("div", { className: "crumbs" },
          React.createElement("a", { onClick: goList }, "Inventory"),
          React.createElement("span", { className: "crumbs__sep" }, React.createElement(Ic.Chevron, { width: 14, height: 14 })),
          current
            ? React.createElement(React.Fragment, null,
                React.createElement("a", { onClick: goList }, "Switch closets"),
                React.createElement("span", { className: "crumbs__sep" }, React.createElement(Ic.Chevron, { width: 14, height: 14 })),
                React.createElement("span", { className: "crumbs__here" }, current.code))
            : React.createElement("span", { className: "crumbs__here" }, "Switch closets")
        ),
        React.createElement("div", { className: "searchbox" },
          React.createElement(Ic.Search, null),
          React.createElement("input", { ref: searchRef, value: query, placeholder: "Search closets, IPs, models\u2026",
            onChange: (e) => { setQuery(e.target.value); if (route.view !== "list") goList(); } }),
          React.createElement("kbd", null, "\u2318K"))
      ),

      React.createElement("div", { className: "content" },
        route.view === "detail" && current
          ? React.createElement(React.Fragment, null,
              React.createElement("button", { className: "btn btn--ghost btn--sm", style: { marginBottom: 16, marginLeft: -6 }, onClick: goList },
                React.createElement(Ic.Chevron, { style: { transform: "rotate(180deg)" } }), "All closets"),
              React.createElement(DetailView, {
                closet: current,
                onFlag: (c) => setModal({ kind: "flag", closet: c }),
                onResolve: resolveFlag,
                onEdit: (c) => setModal({ kind: "edit", closet: c }),
                onAddSwitch: (c) => setModal({ kind: "switch", closet: c }),
                onAddMaint: (c) => setModal({ kind: "maint", closet: c }),
                onEditPower: (c) => setModal({ kind: "power", closet: c }),
              }))
          : React.createElement(ListView, {
              closets, query,
              onOpen: openCloset,
              onAddCloset: () => setModal({ kind: "add" }),
              onFlag: (c) => setModal({ kind: "flag", closet: c }),
            })
      )
    ),

    // MODALS
    modal && (modal.kind === "add" || modal.kind === "edit") &&
      React.createElement(ClosetFormModal, { closet: modal.kind === "edit" ? modal.closet : null, onClose: () => setModal(null), onSave: saveCloset }),
    modal && modal.kind === "flag" &&
      React.createElement(FlagModal, { closet: modal.closet, onClose: () => setModal(null), onSave: doFlag }),
    modal && modal.kind === "switch" &&
      React.createElement(SwitchFormModal, { closet: modal.closet, onClose: () => setModal(null), onSave: addSwitch }),
    modal && modal.kind === "maint" &&
      React.createElement(MaintFormModal, { closet: modal.closet, onClose: () => setModal(null), onSave: addMaint }),
    modal && modal.kind === "power" &&
      React.createElement(PowerFormModal, { closet: modal.closet, onClose: () => setModal(null), onSave: savePower }),

    // TOASTS
    React.createElement("div", { className: "toast-wrap" },
      toasts.map((t) => React.createElement(Toast, {
        key: t.id, msg: t.msg, action: t.action,
        onAction: () => { t.onAction && t.onAction(); dropToast(t.id); },
        onClose: () => dropToast(t.id),
      }))),

    // TWEAKS
    React.createElement(TweaksPanel, null,
      React.createElement(TweakSection, { label: "Theme" }),
      React.createElement(TweakToggle, { label: "Dark mode", value: t.dark, onChange: (v) => setTweak("dark", v) }),
      React.createElement(TweakRadio, { label: "Accent", value: t.accent, options: ["Blue", "Indigo", "Teal", "Violet"], onChange: (v) => setTweak("accent", v) }),
      React.createElement(TweakSection, { label: "Layout" }),
      React.createElement(TweakRadio, { label: "Density", value: t.density, options: ["compact", "regular", "comfy"], onChange: (v) => setTweak("density", v) })
    )
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(React.createElement(App));
