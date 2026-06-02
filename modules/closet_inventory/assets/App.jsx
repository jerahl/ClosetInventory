/* App shell: sidebar, topbar, routing, API-backed persistence, modals, toasts. */
const { useState, useEffect, useMemo, useRef } = React;

const BOOT = window.CLOSET_BOOT || { csrf_token: "", initialView: "list", initialUid: null, userName: "", isAdmin: false };

// Same action prefix tcs_dashboard uses — kept relative so it survives the
// Zabbix frontend living under arbitrary URL roots.
const ACTION_URL = (a) => "zabbix.php?action=" + a;

async function apiGet(action, params) {
  const qs = params ? "&" + new URLSearchParams(params).toString() : "";
  const r = await fetch(ACTION_URL(action) + qs, { credentials: "same-origin", headers: { Accept: "application/json" } });
  if (r.status === 401) throw new Error("Session expired — reload to sign back in.");
  const text = await r.text();
  try { return JSON.parse(text); } catch (e) { throw new Error("Bad JSON from " + action); }
}

// Expose for cross-file callers (Modals.jsx etc.) once Babel evaluates this file.
window.apiGet = apiGet;

async function apiPost(action, fields) {
  const fd = new FormData();
  fd.append("_csrf_token", BOOT.csrf_token || "");
  Object.entries(fields || {}).forEach(([k, v]) => {
    if (v === undefined || v === null) return;
    fd.append(k, typeof v === "object" ? JSON.stringify(v) : String(v));
  });
  const r = await fetch(ACTION_URL(action), { method: "POST", body: fd, credentials: "same-origin" });
  const text = await r.text();
  let body;
  try { body = JSON.parse(text); } catch (e) { throw new Error("Bad JSON from " + action); }
  if (!body || body.ok !== true) throw new Error((body && body.error) || ("Save failed: " + action));
  return body;
}
window.apiPost = apiPost;

const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "dark": true,
  "accent": "Blue",
  "density": "regular"
}/*EDITMODE-END*/;

const ACCENT_HUE = { Blue: 256, Indigo: 277, Teal: 195, Violet: 305 };

function App() {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [closets, setClosets] = useState([]);
  const [schools, setSchools] = useState([]);
  const [loading, setLoading] = useState(true);
  const [route, setRoute] = useState({ view: BOOT.initialView === "detail" ? "detail" : "list", uid: BOOT.initialUid || null });
  const [query, setQuery] = useState("");
  const [modal, setModal] = useState(null);
  const [toasts, setToasts] = useState([]);
  const [navOpen, setNavOpen] = useState(false);
  const searchRef = useRef(null);

  // Populate window.SeedData.schools so components.jsx's SchoolTag and the
  // Modals' school dropdowns light up. components.jsx builds SCHOOL_MAP once
  // at script load (empty); we rebuild it whenever schools change.
  useEffect(() => {
    window.SeedData = window.SeedData || { schools: [], closets: [] };
    window.SeedData.schools = schools;
    if (window.SCHOOL_MAP) {
      Object.keys(window.SCHOOL_MAP).forEach((k) => delete window.SCHOOL_MAP[k]);
      schools.forEach((s) => { window.SCHOOL_MAP[s.id] = s; });
    }
  }, [schools]);

  // ---- API loaders ----
  const loadList = async () => {
    setLoading(true);
    try {
      const data = await apiGet("closet.list.data");
      const newSchools = data.schools || [];
      // Update SCHOOL_MAP SYNCHRONOUSLY before setClosets so the next render
      // can resolve schoolOf(c.schoolId) without waiting for the schools
      // effect to flush. Without this the table cells throw on .name access
      // for one render tick after a list refresh.
      window.SeedData = window.SeedData || { schools: [], closets: [] };
      window.SeedData.schools = newSchools;
      if (window.SCHOOL_MAP) {
        Object.keys(window.SCHOOL_MAP).forEach((k) => delete window.SCHOOL_MAP[k]);
        newSchools.forEach((s) => { window.SCHOOL_MAP[s.id] = s; });
      }
      setSchools(newSchools);
      const rows = (data.closets || []).map((c) => ({
        ...c,
        switches: c.switches || [],
        power: window.normalizePower(c.power || null),
        maintenance: c.maintenance || [],
        photos: c.photos || []
      }));
      setClosets(rows);
    } catch (e) {
      toast(e.message || "Failed to load closets.");
    } finally {
      setLoading(false);
    }
  };

  const loadDetail = async (uid) => {
    try {
      const data = await apiGet("closet.view.data", { uid });
      if (!data || !data.closet) return;
      const c = { ...data.closet, power: window.normalizePower(data.closet.power) };
      setClosets((cs) => {
        const i = cs.findIndex((x) => x.uid === uid);
        if (i < 0) return [...cs, c];
        const out = cs.slice();
        out[i] = { ...out[i], ...c };
        return out;
      });
    } catch (e) {
      toast(e.message || "Failed to load closet detail.");
    }
  };

  useEffect(() => { loadList(); }, []);
  useEffect(() => {
    if (route.view === "detail" && route.uid != null) loadDetail(route.uid);
  }, [route.view, route.uid]);

  // ---- theme/accent/density wiring (unchanged from the design) ----
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

  const toast = (msg, action, onAction) => {
    const id = Math.random();
    setToasts((t) => [...t, { id, msg, action, onAction }]);
  };
  const dropToast = (id) => setToasts((t) => t.filter((x) => x.id !== id));

  const current = route.uid != null ? closets.find((c) => c.uid === route.uid) : null;
  useEffect(() => { window.scrollTo(0, 0); }, [route.view, route.uid]);

  // XIQ rate-limit banner — show once per session when the live data reports a
  // rate-limited source or a "remaining" budget under 500. Track via a ref so a
  // re-render of the detail page doesn't re-toast.
  const xiqWarnedRef = useRef(false);
  useEffect(() => {
    if (xiqWarnedRef.current) return;
    const live = (current && current._live) || null;
    if (!live) return;
    const rateLimited = live.sources && live.sources.xiq === "rate_limited";
    const remaining = (typeof live.xiqRateLimitRemaining === "number") ? live.xiqRateLimitRemaining : null;
    const low = rateLimited || (remaining !== null && remaining < 500);
    if (!low) return;
    xiqWarnedRef.current = true;
    const id = Math.random();
    setToasts((t) => [...t, { id, msg: "ExtremeCloud IQ rate limit low — some live data may be stale." }]);
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 8000);
  }, [current]);

  // ---- handlers ----
  const openCloset = (c) => { setRoute({ view: "detail", uid: c.uid }); setNavOpen(false); };
  const goList = () => setRoute({ view: "list", uid: null });

  const saveCloset = async (f) => {
    try {
      const body = await apiPost("closet.save", {
        uid: f.uid || 0, code: f.code || "", type: f.type, schoolId: f.schoolId,
        building: f.building || "", floor: +f.floor || 1, room: f.room || ""
      });
      toast(f.uid ? "Closet updated." : "Closet created.");
      setModal(null);
      await loadList();
      if (!f.uid && body.uid) setRoute({ view: "detail", uid: body.uid });
    } catch (e) { toast(e.message); }
  };

  const doFlag = async ({ reason, tech }) => {
    const c = modal.closet;
    try {
      await apiPost("closet.flag.save", { closetUid: c.uid, flagged: 1, reason, tech, date: new Date().toISOString().slice(0, 10) });
      toast(c.code + " flagged for service.");
      setModal(null);
      await loadList();
      if (route.view === "detail") await loadDetail(c.uid);
    } catch (e) { toast(e.message); }
  };

  const resolveFlag = async (c) => {
    try {
      await apiPost("closet.flag.save", { closetUid: c.uid, flagged: 0, tech: BOOT.userName || "" });
      toast(c.code + " flag resolved.");
      await loadList();
      if (route.view === "detail") await loadDetail(c.uid);
    } catch (e) { toast(e.message); }
  };

  const saveSwitch = async (sw) => {
    const c = modal.closet;
    const editing = sw.id != null;
    try {
      const payload = {
        closetUid: c.uid,
        name: sw.name, vendor: sw.vendor, model: sw.model,
        ports: +sw.ports || 0, used: +sw.used || 0,
        poe: sw.poe ? 1 : 0, uplinks: +sw.uplinks || 0,
        uplinkSpeed: sw.uplinkSpeed || "",
        mgmtIp: sw.mgmtIp || "", serial: sw.serial || "",
        stack: +sw.stack || 1,
        xiqDeviceId: +sw.xiqDeviceId || 0,
        zabbixHostid: sw.zabbixHostid || "",
        rconfigDeviceId: +sw.rconfigDeviceId || 0
      };
      if (editing) payload.id = sw.id;
      await apiPost("closet.switch.save", payload);
      toast(editing ? ("Switch " + sw.name + " updated.") : ("Switch added to " + c.code + "."));
      setModal(null);
      await loadList();
      if (route.view === "detail") await loadDetail(c.uid);
    } catch (e) { toast(e.message); }
  };

  const addMaint = async (m) => {
    const c = modal.closet;
    try {
      await apiPost("closet.maintenance.save", {
        closetUid: c.uid,
        date: m.date, type: m.type, tone: m.tone || "default",
        tech: m.tech, notes: m.notes
      });
      toast("Maintenance logged.");
      setModal(null);
      if (route.view === "detail") await loadDetail(c.uid);
    } catch (e) { toast(e.message); }
  };

  const savePower = async (power) => {
    const c = modal.closet;
    try {
      await apiPost("closet.power.save", { closetUid: c.uid, power: JSON.stringify(power) });
      toast("Power updated for " + c.code + ".");
      setModal(null);
      if (route.view === "detail") await loadDetail(c.uid);
    } catch (e) { toast(e.message); }
  };

  const deleteCloset = async (c) => {
    if (!window.confirm("Delete " + c.code + " and all its switches, power, photos, and maintenance?")) return;
    try {
      await apiPost("closet.delete", { uid: c.uid });
      toast("Closet " + c.code + " deleted.");
      goList();
      await loadList();
    } catch (e) { toast(e.message); }
  };

  const moveSwitch = async (sw, { targetClosetUid }) => {
    try {
      await apiPost("closet.switch.move", { switchId: sw.id, targetClosetUid });
      toast("Switch moved.");
      setModal(null);
      await loadList();
      if (route.uid != null) await loadDetail(route.uid);
    } catch (e) { toast(e.message); }
  };

  // Photo upload — multipart/form-data, bypasses apiPost which assumes a
  // shallow {key: value} payload. Mobile camera capture is handled in
  // PhotosPanel by toggling the `capture` attribute on the file input.
  const uploadPhoto = async (closet, file, label) => {
    try {
      const fd = new FormData();
      fd.append("closetUid", String(closet.uid));
      fd.append("label", label || "");
      fd.append("photo", file, file.name);
      const r = await fetch("zabbix.php?action=closet.photo.upload", {
        method: "POST", body: fd, credentials: "same-origin"
      });
      const body = await r.json().catch(() => null);
      if (!body || body.ok !== true) throw new Error((body && body.error) || "Upload failed");
      toast("Photo uploaded.");
      if (route.uid != null) await loadDetail(route.uid);
    } catch (e) { toast(e.message); }
  };

  const deletePhoto = async (closet, p) => {
    if (!p || !p.id) return;
    try {
      await apiPost("closet.photo.delete", { id: p.id });
      toast("Photo deleted.");
      if (route.uid != null) await loadDetail(route.uid);
    } catch (e) { toast(e.message); }
  };

  const refreshCounters = async () => {
    try {
      const body = await apiPost("closet.counters.refresh", {});
      toast(`Counters refreshed for ${body.updated || 0} closet${body.updated === 1 ? "" : "s"}.`);
      await loadList();
    } catch (e) { toast(e.message); }
  };

  const seedStarter = async () => {
    try {
      await apiPost("closet.seed", {});
      toast("Starter data seeded.");
      await loadList();
    } catch (e) { toast(e.message); }
  };

  const populateZabbix = async () => {
    try {
      const body = await apiPost("closet.populate.zabbix", {});
      if (!body.ok) { toast("Populate failed: " + (body.error || "unknown")); return; }
      const errs = (body.errors && body.errors.length) ? ` · ${body.errors.length} warning${body.errors.length === 1 ? "" : "s"}` : "";
      toast(
        `Zabbix import: +${body.schoolsCreated || 0} schools, +${body.closetsCreated || 0} closets, +${body.switchesCreated || 0} switches (scanned ${body.hostsScanned || 0} hosts)${errs}`
      );
      await loadList();
    } catch (e) { toast(e.message); }
  };

  const flaggedCount = closets.filter((c) => c.flagged).length;

  return React.createElement("div", { className: "app" + (navOpen ? " nav-open" : "") },
    React.createElement("div", { className: "scrim-nav", onClick: () => setNavOpen(false) }),

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
          React.createElement("span", { className: "nav__count" }, closets.reduce((a, c) => a + ((c.switches && c.switches.length) || c.switchesCount || 0), 0))),
        React.createElement("button", { className: "nav__item", onClick: goList },
          React.createElement(Ic.Bolt, null), "Power"),
        React.createElement("div", { className: "nav__label" }, "Operations"),
        React.createElement("button", { className: "nav__item", onClick: () => { setRoute({ view: "list", uid: null }); } },
          React.createElement(Ic.Flag, null), "Service queue",
          flaggedCount > 0 && React.createElement("span", { className: "nav__count", style: { background: "var(--amber)", color: "#1a1206" } }, flaggedCount)),
        React.createElement("button", { className: "nav__item" }, React.createElement(Ic.Wrench, null), "Maintenance"),
        React.createElement("button", { className: "nav__item" }, React.createElement(Ic.Building, null), "Schools",
          React.createElement("span", { className: "nav__count" }, schools.length))
      ),
      React.createElement("div", { className: "sidebar__foot" },
        React.createElement("div", { className: "user-chip" },
          React.createElement("div", { className: "avatar" }, (BOOT.userName || "??").slice(0, 2).toUpperCase()),
          React.createElement("div", null,
            React.createElement("div", { className: "user-chip__name" }, BOOT.userName || "—"),
            React.createElement("div", { className: "user-chip__role" }, BOOT.isAdmin ? "Network Admin" : "User"))))
    ),

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
          React.createElement("input", { ref: searchRef, value: query, placeholder: "Search closets, IPs, models…",
            onChange: (e) => { setQuery(e.target.value); if (route.view !== "list") goList(); } }),
          React.createElement("kbd", null, "⌘K"))
      ),

      React.createElement("div", { className: "content" },
        loading
          ? React.createElement("div", { className: "muted", style: { padding: 32, textAlign: "center" } }, "Loading closets…")
          : (route.view === "detail" && current)
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
                    onDelete: deleteCloset,
                    onMove: (c, sw) => setModal({ kind: "move", closet: c, sw }),
                    onInspect: (c, sw) => setModal({ kind: "inspect", closet: c, sw }),
                    onUploadPhoto: uploadPhoto,
                    onDeletePhoto: deletePhoto,
                  }))
              : React.createElement(ListView, {
                  closets, query,
                  onOpen: openCloset,
                  onAddCloset: () => setModal({ kind: "add" }),
                  onFlag: (c) => setModal({ kind: "flag", closet: c }),
                  onRefreshCounters: refreshCounters,
                  onSeed: seedStarter,
                  onPopulateZabbix: populateZabbix,
                  isAdmin: !!BOOT.isAdmin,
                })
      )
    ),

    modal && (modal.kind === "add" || modal.kind === "edit") &&
      React.createElement(ClosetFormModal, { closet: modal.kind === "edit" ? modal.closet : null, onClose: () => setModal(null), onSave: saveCloset }),
    modal && modal.kind === "flag" &&
      React.createElement(FlagModal, { closet: modal.closet, onClose: () => setModal(null), onSave: doFlag }),
    modal && modal.kind === "switch" &&
      React.createElement(SwitchFormModal, { closet: modal.closet, sw: modal.sw || null, onClose: () => setModal(null), onSave: saveSwitch }),
    modal && modal.kind === "maint" &&
      React.createElement(MaintFormModal, { closet: modal.closet, onClose: () => setModal(null), onSave: addMaint }),
    modal && modal.kind === "power" &&
      React.createElement(PowerFormModal, { closet: modal.closet, onClose: () => setModal(null), onSave: savePower }),
    modal && modal.kind === "move" &&
      React.createElement(MoveSwitchModal, { closet: modal.closet, sw: modal.sw, closets, onClose: () => setModal(null), onSave: (payload) => moveSwitch(modal.sw, payload) }),
    modal && modal.kind === "inspect" &&
      React.createElement(SwitchInspectModal, {
        closet: modal.closet, sw: modal.sw,
        onClose: () => setModal(null),
        onEdit: (s) => setModal({ kind: "switch", closet: modal.closet, sw: s })
      }),

    React.createElement("div", { className: "toast-wrap" },
      toasts.map((t) => React.createElement(Toast, {
        key: t.id, msg: t.msg, action: t.action,
        onAction: () => { t.onAction && t.onAction(); dropToast(t.id); },
        onClose: () => dropToast(t.id),
      }))),

    React.createElement(TweaksPanel, null,
      React.createElement(TweakSection, { label: "Theme" }),
      React.createElement(TweakToggle, { label: "Dark mode", value: t.dark, onChange: (v) => setTweak("dark", v) }),
      React.createElement(TweakRadio, { label: "Accent", value: t.accent, options: ["Blue", "Indigo", "Teal", "Violet"], onChange: (v) => setTweak("accent", v) }),
      React.createElement(TweakSection, { label: "Layout" }),
      React.createElement(TweakRadio, { label: "Density", value: t.density, options: ["compact", "regular", "comfy"], onChange: (v) => setTweak("density", v) })
    )
  );
}

ReactDOM.createRoot(document.getElementById("closet-root")).render(React.createElement(App));
