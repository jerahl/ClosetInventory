/* Modals: closet form, flag, add switch, log maintenance. */

function ClosetFormModal({ closet, onClose, onSave }) {
  const editing = !!closet;
  const [f, setF] = React.useState(() => closet ? { ...closet } : {
    schoolId: (window.SeedData && window.SeedData.schools[0] && window.SeedData.schools[0].id) || "", type: "IDF", floor: 1, building: "Main", room: "",
  });
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));
  const valid = f.room.trim().length > 0;

  return React.createElement(Modal, {
    title: editing ? "Edit closet" : "Add switch closet",
    sub: editing ? closet.code : "Create a new IDF / MDF record",
    icon: React.createElement(Ic.Server, null),
    onClose,
    footer: React.createElement(React.Fragment, null,
      React.createElement("button", { className: "btn", onClick: onClose }, "Cancel"),
      React.createElement("button", { className: "btn btn--primary", disabled: !valid, style: !valid ? { opacity: .5, pointerEvents: "none" } : null, onClick: () => onSave(f) },
        React.createElement(Ic.Check, null), editing ? "Save changes" : "Create closet")
    ),
  },
    React.createElement(Field, { label: "Type", req: true },
      React.createElement("div", { className: "radio-row" },
        [{ t: "IDF", d: "Intermediate frame" }, { t: "MDF", d: "Main distribution" }].map((o) =>
          React.createElement("div", { key: o.t, className: "radio-card" + (f.type === o.t ? " is-on" : ""), onClick: () => set("type", o.t) },
            React.createElement("div", { className: "radio-card__t" }, o.t),
            React.createElement("div", { className: "radio-card__d" }, o.d)))
      )
    ),
    React.createElement(Field, { label: "School / site", req: true },
      React.createElement("select", { value: f.schoolId, onChange: (e) => set("schoolId", e.target.value) },
        window.SeedData.schools.map((s) => React.createElement("option", { key: s.id, value: s.id }, s.name)))
    ),
    React.createElement("div", { className: "field-row" },
      React.createElement(Field, { label: "Building" },
        React.createElement("input", { className: "input", value: f.building, onChange: (e) => set("building", e.target.value), placeholder: "Main" })),
      React.createElement(Field, { label: "Floor" },
        React.createElement("input", { className: "input", type: "number", min: 1, max: 6, value: f.floor, onChange: (e) => set("floor", +e.target.value) }))
    ),
    React.createElement(Field, { label: "Room", req: true, hint: "e.g. \u201cData Closet 204\u201d or \u201cTelecom Rm 1A\u201d" },
      React.createElement("input", { className: "input", value: f.room, onChange: (e) => set("room", e.target.value), placeholder: "Data Closet 204", autoFocus: true })),
    !editing && React.createElement(Field, { label: "Closet ID", hint: "Auto-generated from site + type. Editable after creation." },
      React.createElement("input", { className: "input mono", disabled: true, value: f.type === "MDF" ? `${f.schoolId}-MDF` : `${f.schoolId}-IDF-${f.floor}xx`, style: { color: "var(--muted)", background: "var(--surface-2)" } }))
  );
}

function FlagModal({ closet, onClose, onSave }) {
  const [reason, setReason] = React.useState("");
  const [tech, setTech] = React.useState("J. Whitfield");
  const presets = [
    "UPS battery health degraded — schedule replacement.",
    "Port capacity exhausted — needs additional access switch.",
    "Closet running hot — HVAC follow-up required.",
    "Intermittent uplink errors — inspect fiber.",
  ];
  return React.createElement(Modal, {
    title: "Flag for service",
    sub: closet.code + " — " + schoolOf(closet.schoolId).name,
    icon: React.createElement("span", { style: { color: "var(--amber)" } }, React.createElement(Ic.Flag, null)),
    onClose,
    footer: React.createElement(React.Fragment, null,
      React.createElement("button", { className: "btn", onClick: onClose }, "Cancel"),
      React.createElement("button", { className: "btn btn--primary", disabled: !reason.trim(), style: !reason.trim() ? { opacity: .5, pointerEvents: "none" } : { background: "var(--amber)", borderColor: "var(--amber)" }, onClick: () => onSave({ reason: reason.trim(), tech }) },
        React.createElement(Ic.Flag, null), "Raise flag")
    ),
  },
    React.createElement(Field, { label: "What needs attention?", req: true },
      React.createElement("textarea", { className: "textarea", value: reason, onChange: (e) => setReason(e.target.value), placeholder: "Describe the issue\u2026", autoFocus: true })),
    React.createElement("div", { style: { display: "flex", gap: 7, flexWrap: "wrap", margin: "-6px 0 16px" } },
      presets.map((p, i) => React.createElement("button", { key: i, className: "chip", style: { height: 28, fontSize: 11.5, fontWeight: 500 }, onClick: () => setReason(p) }, p.split(" ").slice(0, 3).join(" ") + "\u2026"))),
    React.createElement(Field, { label: "Raised by" },
      React.createElement("select", { value: tech, onChange: (e) => setTech(e.target.value) },
        ["J. Whitfield", "M. Alvarez", "D. Carter", "S. Nguyen", "R. Patel", "T. Brooks"].map((t) => React.createElement("option", { key: t }, t))))
  );
}

function SwitchFormModal({ closet, onClose, onSave }) {
  const models = [
    "Cisco Catalyst 9300-48P", "Cisco Catalyst 9300-24P", "Cisco Catalyst 9200-48P",
    "Aruba CX 6300M 48G", "Juniper EX4300-48P", "Meraki MS225-48LP", "Meraki MS210-24P",
  ];
  const [f, setF] = React.useState({
    name: `${closet.schoolId}-${closet.type}-SW${closet.switches.length + 1}`,
    model: models[0], ports: 48, used: 0, uplinks: 1, uplinkSpeed: "10G SFP+", mgmtIp: "", serial: "", stack: 1, poe: true,
    xiqDeviceId: "", zabbixHostid: "", rconfigDeviceId: "",
  });
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));

  // Lookup section state — three chips, one per source.
  const [lookup, setLookup] = React.useState("");
  const [chips, setChips]   = React.useState(null); // { zabbix:{kind,text}, xiq:{kind,text}, rconfig:{kind,text} }
  const [busy, setBusy]     = React.useState(false);

  const CHIP_STYLES = {
    ok:           { background: "var(--teal-soft)",  color: "var(--teal)",  borderColor: "color-mix(in oklch, var(--teal) 25%, transparent)" },
    not_found:    { background: "var(--surface-2)",  color: "var(--muted)", borderColor: "var(--border)" },
    unconfigured: { background: "var(--surface-2)",  color: "var(--muted)", borderColor: "var(--border)" },
    rate_limited: { background: "var(--amber-soft)", color: "var(--amber)", borderColor: "color-mix(in oklch, var(--amber) 25%, transparent)" },
    ambiguous:    { background: "var(--amber-soft)", color: "var(--amber)", borderColor: "color-mix(in oklch, var(--amber) 25%, transparent)" },
    down:         { background: "var(--surface-2)",  color: "var(--red, #c44)", borderColor: "color-mix(in oklch, var(--red, #c44) 25%, transparent)" },
    err:          { background: "var(--surface-2)",  color: "var(--red, #c44)", borderColor: "color-mix(in oklch, var(--red, #c44) 25%, transparent)" }
  };

  const doFind = async () => {
    const q = lookup.trim();
    const xiqTyped = f.xiqDeviceId && +f.xiqDeviceId > 0 ? +f.xiqDeviceId : 0;
    const zbxTyped = f.zabbixHostid && +f.zabbixHostid > 0 ? +f.zabbixHostid : 0;
    if (!q && !xiqTyped && !zbxTyped) {
      setChips({ zabbix: { kind: "err", text: "Enter a hostname, IP, MAC, serial, or numeric id first." } });
      return;
    }
    if (!window.apiGet) {
      setChips({ zabbix: { kind: "err", text: "Lookup not yet ready — try again." } });
      return;
    }

    setBusy(true);
    const localChips = { zabbix: null, xiq: null, rconfig: null };
    const updates = {}; // gathered field updates, applied once at the end.

    // Classify the free-text input.
    const looksLikeIp   = /^\d{1,3}(\.\d{1,3}){3}$/.test(q);
    const looksLikeInt  = /^\d+$/.test(q);
    const looksLikeMac  = /^[0-9A-Fa-f:.\-]{12,}$/.test(q) && (q.replace(/[^0-9A-Fa-f]/g, "").length === 12);

    // ---------- 1) Zabbix ----------
    let zbxHost = null;
    try {
      let zbxParams = null;
      if (zbxTyped > 0) zbxParams = { hostid: String(zbxTyped) };
      else if (looksLikeIp) zbxParams = { ip: q };
      else if (q && !looksLikeMac) zbxParams = { hostname: q };

      if (zbxParams) {
        const body = await window.apiGet("closet.zabbix.host.data", zbxParams);
        if (!body || !body.ok) {
          localChips.zabbix = { kind: "err", text: "Zabbix: lookup failed" };
        } else if (body.source === "down") {
          localChips.zabbix = { kind: "down", text: "Zabbix: unreachable" };
        } else if (body.source === "not_found" || !body.host) {
          localChips.zabbix = { kind: "not_found", text: "Zabbix: no match" };
        } else {
          zbxHost = body.host;
          if (zbxHost.hostid) updates.zabbixHostid = zbxHost.hostid;
          if (zbxHost.mgmtIp) updates.mgmtIp = zbxHost.mgmtIp;
          localChips.zabbix = {
            kind: "ok",
            text: "Zabbix: " + (zbxHost.hostname || zbxHost.visibleName || zbxHost.hostid)
          };
        }
      } else {
        localChips.zabbix = { kind: "not_found", text: "Zabbix: skipped" };
      }
    } catch (e) {
      localChips.zabbix = { kind: "err", text: "Zabbix: " + (e.message || "error") };
    }
    setChips({ ...localChips });

    // ---------- 2) XIQ ----------
    // Skip XIQ if Zabbix already gave us model+serial+id — we keep the contract
    // that XIQ runs second and fills blanks. But here we have no model/serial
    // from Zabbix, so always run unless the input is unusable.
    try {
      let xiqParams = null;
      if (xiqTyped > 0) xiqParams = { id: xiqTyped };
      else if (looksLikeInt) xiqParams = { id: +q };
      else if (looksLikeMac) xiqParams = { mac: q };
      else if (q) xiqParams = { hostname: q };

      if (xiqParams) {
        let body = await window.apiGet("closet.xiq.device.data", xiqParams);
        if (body && body.ok && !body.device && xiqParams.hostname) {
          body = await window.apiGet("closet.xiq.device.data", { serial: q });
        }
        if (!body || !body.ok) {
          localChips.xiq = { kind: "err", text: "XIQ: lookup failed" };
        } else if (body.source === "unconfigured") {
          localChips.xiq = { kind: "unconfigured", text: "XIQ: not configured" };
        } else if (body.source === "rate_limited") {
          localChips.xiq = { kind: "rate_limited", text: "XIQ: rate-limited" };
        } else if (body.source === "down") {
          localChips.xiq = { kind: "down", text: "XIQ: unreachable" };
        } else if (!body.device) {
          localChips.xiq = { kind: "not_found", text: "XIQ: no match" };
        } else {
          const d = body.device;
          if (d.model  && !updates.model)  updates.model  = d.model;
          if (d.serial && !updates.serial) updates.serial = d.serial;
          if (d.mgmtIp && !updates.mgmtIp) updates.mgmtIp = d.mgmtIp;
          if (d.id) updates.xiqDeviceId = d.id;
          // Only adopt XIQ's zabbix-hostid guess when Zabbix step didn't match.
          if (!zbxHost && d.zabbixHostidGuess) updates.zabbixHostid = d.zabbixHostidGuess;
          localChips.xiq = { kind: "ok", text: "XIQ: " + (d.hostname || ("id " + d.id)) };
        }
      } else {
        localChips.xiq = { kind: "not_found", text: "XIQ: skipped" };
      }
    } catch (e) {
      localChips.xiq = { kind: "err", text: "XIQ: " + (e.message || "error") };
    }
    setChips({ ...localChips });

    // ---------- 3) rConfig ----------
    // Needs a Zabbix hostid or a hostname to resolve the device.
    try {
      const rcHostid   = updates.zabbixHostid || zbxTyped || (zbxHost && zbxHost.hostid) || null;
      const rcHostname = (!rcHostid && q && !looksLikeIp && !looksLikeInt && !looksLikeMac) ? q : null;

      if (!rcHostid && !rcHostname) {
        localChips.rconfig = { kind: "not_found", text: "rConfig: skipped" };
      } else {
        const rcParams = rcHostid ? { zabbixHostid: String(rcHostid) } : { hostname: rcHostname };
        const body = await window.apiGet("closet.rconfig.device.data", rcParams);
        if (!body || !body.ok) {
          localChips.rconfig = { kind: "err", text: "rConfig: lookup failed" };
        } else if (body.source === "unconfigured") {
          localChips.rconfig = { kind: "unconfigured", text: "rConfig: not configured" };
        } else if (body.source === "down") {
          localChips.rconfig = { kind: "down", text: "rConfig: unreachable" };
        } else if (body.source === "ambiguous") {
          localChips.rconfig = { kind: "ambiguous", text: "rConfig: ambiguous match" };
        } else if (body.source === "not_found" || !body.device) {
          localChips.rconfig = { kind: "not_found", text: "rConfig: no match" };
        } else {
          const d = body.device;
          if (d.id) updates.rconfigDeviceId = d.id;
          const ageTxt = (d.lastBackupAgeDays == null)
            ? "backup age unknown"
            : `backup ${d.lastBackupAgeDays}d ago`;
          localChips.rconfig = { kind: "ok", text: `rConfig: device ${d.id}, ${ageTxt}` };
        }
      }
    } catch (e) {
      localChips.rconfig = { kind: "err", text: "rConfig: " + (e.message || "error") };
    }
    setChips({ ...localChips });

    // Apply field updates (fill-blanks only for plain fields; always adopt external ids).
    setF((prev) => {
      const next = { ...prev };
      const fillIfBlank = (k, v) => {
        if (v && (next[k] === undefined || next[k] === null || next[k] === "" || next[k] === 0)) next[k] = v;
      };
      if (updates.model)  fillIfBlank("model",  updates.model);
      if (updates.serial) fillIfBlank("serial", updates.serial);
      if (updates.mgmtIp) fillIfBlank("mgmtIp", updates.mgmtIp);
      if (updates.zabbixHostid && !next.zabbixHostid) next.zabbixHostid = updates.zabbixHostid;
      if (updates.xiqDeviceId)     next.xiqDeviceId     = updates.xiqDeviceId;
      if (updates.rconfigDeviceId) next.rconfigDeviceId = updates.rconfigDeviceId;
      return next;
    });

    setBusy(false);
  };

  return React.createElement(Modal, {
    title: "Add switch",
    sub: "to " + closet.code,
    icon: React.createElement(Ic.Switch, null),
    onClose,
    footer: React.createElement(React.Fragment, null,
      React.createElement("button", { className: "btn", onClick: onClose }, "Cancel"),
      React.createElement("button", { className: "btn btn--primary", onClick: () => {
        const [vendor, ...rest] = f.model.split(" ");
        onSave({
          ...f,
          vendor, model: rest.join(" "),
          ports: +f.ports, used: +f.used, uplinks: +f.uplinks, stack: +f.stack,
          xiqDeviceId: +f.xiqDeviceId || 0,
          zabbixHostid: f.zabbixHostid || "",
          rconfigDeviceId: +f.rconfigDeviceId || 0
        });
      } }, React.createElement(Ic.Check, null), "Add switch")
    ),
  },
    // -------- Lookup subsection --------
    React.createElement("div", {
      className: "field__label",
      style: { fontSize: 12, textTransform: "uppercase", letterSpacing: ".05em", color: "var(--faint)", margin: "4px 0 8px" }
    }, "Lookup (optional autofill)"),
    React.createElement("div", { style: { display: "grid", gridTemplateColumns: "1fr auto", gap: 10, alignItems: "end" } },
      React.createElement(Field, { label: "Hostname / IP / MAC / serial / id", hint: "Searches Zabbix, then XIQ, then rConfig." },
        React.createElement("input", { className: "input mono", value: lookup, onChange: (e) => setLookup(e.target.value), placeholder: "RHS-IDF-SW1 or 10.10.0.2", onKeyDown: (e) => { if (e.key === "Enter") { e.preventDefault(); doFind(); } } })),
      React.createElement("button", { className: "btn", disabled: busy, style: { height: 38 }, onClick: doFind },
        React.createElement(Ic.Search || Ic.Check, null), busy ? "Searching…" : "Find")
    ),
    React.createElement("div", { className: "field-row" },
      React.createElement(Field, { label: "Zabbix host id", hint: "Numeric Zabbix hostid (autofilled when one matches)." },
        React.createElement("input", { className: "input mono", value: f.zabbixHostid, onChange: (e) => set("zabbixHostid", e.target.value.replace(/[^0-9]/g, "")), placeholder: "12345" })),
      React.createElement(Field, { label: "XIQ device ID", hint: "Numeric XIQ id (autofilled by Find)." },
        React.createElement("input", { className: "input mono", value: f.xiqDeviceId, onChange: (e) => set("xiqDeviceId", e.target.value.replace(/[^0-9]/g, "")), placeholder: "1234567" }))
    ),
    React.createElement(Field, { label: "rConfig device ID", hint: "Numeric rConfig device id (autofilled by Find)." },
      React.createElement("input", { className: "input mono", value: f.rconfigDeviceId, onChange: (e) => set("rconfigDeviceId", e.target.value.replace(/[^0-9]/g, "")), placeholder: "88" })),
    chips && React.createElement("div", { style: { display: "flex", flexWrap: "wrap", gap: 6, margin: "-2px 0 12px" } },
      ["zabbix", "xiq", "rconfig"].map((src) => {
        const c = chips[src];
        if (!c) return null;
        const st = CHIP_STYLES[c.kind] || CHIP_STYLES.err;
        return React.createElement("span", { key: src, className: "uplink-tag", style: st }, c.text);
      })
    ),
    // -------- Standard fields --------
    React.createElement("div", { className: "field-row" },
      React.createElement(Field, { label: "Name / hostname", req: true },
        React.createElement("input", { className: "input mono", value: f.name, onChange: (e) => set("name", e.target.value) })),
      React.createElement(Field, { label: "Mgmt IP" },
        React.createElement("input", { className: "input mono", value: f.mgmtIp, onChange: (e) => set("mgmtIp", e.target.value), placeholder: "10.10.0.2" }))
    ),
    React.createElement(Field, { label: "Model" },
      React.createElement("select", { value: f.model, onChange: (e) => { const m = e.target.value; set("model", m); set("ports", m.includes("24") ? 24 : 48); } },
        models.map((m) => React.createElement("option", { key: m }, m)))),
    React.createElement("div", { className: "field-row--3", style: { display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 14 } },
      React.createElement(Field, { label: "Total ports" },
        React.createElement("input", { className: "input", type: "number", value: f.ports, onChange: (e) => set("ports", e.target.value) })),
      React.createElement(Field, { label: "Ports in use" },
        React.createElement("input", { className: "input", type: "number", value: f.used, onChange: (e) => set("used", e.target.value) })),
      React.createElement(Field, { label: "Uplinks" },
        React.createElement("input", { className: "input", type: "number", value: f.uplinks, onChange: (e) => set("uplinks", e.target.value) }))
    ),
    React.createElement(Field, { label: "Serial number" },
      React.createElement("input", { className: "input mono", value: f.serial, onChange: (e) => set("serial", e.target.value), placeholder: "FOC2340ABC12" }))
  );
}

function MaintFormModal({ closet, onClose, onSave }) {
  const types = ["Quarterly inspection", "Switch replacement", "IOS / firmware upgrade", "UPS battery service", "Patch / cable cleanup", "Added access switch", "Cooling / thermal check", "Uplink fiber repair"];
  const toneOf = (t) => /UPS|replacement|fiber/.test(t) ? "amber" : /upgrade|thermal|Cooling/.test(t) ? "teal" : "default";
  const [f, setF] = React.useState({ type: types[0], date: "2026-05-28", tech: "J. Whitfield", notes: "" });
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));
  return React.createElement(Modal, {
    title: "Log maintenance entry",
    sub: closet.code,
    icon: React.createElement(Ic.Wrench, null),
    onClose,
    footer: React.createElement(React.Fragment, null,
      React.createElement("button", { className: "btn", onClick: onClose }, "Cancel"),
      React.createElement("button", { className: "btn btn--primary", disabled: !f.notes.trim(), style: !f.notes.trim() ? { opacity: .5, pointerEvents: "none" } : null, onClick: () => onSave({ ...f, tone: toneOf(f.type) }) },
        React.createElement(Ic.Check, null), "Save entry")
    ),
  },
    React.createElement("div", { className: "field-row" },
      React.createElement(Field, { label: "Type", req: true },
        React.createElement("select", { value: f.type, onChange: (e) => set("type", e.target.value) },
          types.map((t) => React.createElement("option", { key: t }, t)))),
      React.createElement(Field, { label: "Date" },
        React.createElement("input", { className: "input", type: "date", value: f.date, onChange: (e) => set("date", e.target.value) }))
    ),
    React.createElement(Field, { label: "Notes", req: true },
      React.createElement("textarea", { className: "textarea", value: f.notes, onChange: (e) => set("notes", e.target.value), placeholder: "What was done\u2026", autoFocus: true })),
    React.createElement(Field, { label: "Technician" },
      React.createElement("select", { value: f.tech, onChange: (e) => set("tech", e.target.value) },
        ["J. Whitfield", "M. Alvarez", "D. Carter", "S. Nguyen", "R. Patel", "T. Brooks", "K. Owens", "L. Sanders"].map((t) => React.createElement("option", { key: t }, t))))
  );
}

function PowerFormModal({ closet, onClose, onSave }) {
  const p = window.normalizePower(closet.power);
  const UPS_MODELS = [
    "APC Smart-UPS SRT 3000VA", "APC Smart-UPS 2200VA", "APC Smart-UPS 1500VA",
    "Eaton 9PX 3000VA", "Eaton 5PX 2200VA", "CyberPower OR1500LCDRT",
  ];
  const PDU_MODELS = ["APC AP8941 Switched", "APC AP7900B Metered", "Eaton EMAB10 Managed", "Tripp Lite PDUMH20HVNET"];
  const [upsList, setUps] = React.useState(() => p.upsList.map((u) => ({ ...u })));
  const [pduList, setPdu] = React.useState(() => p.pduList.map((d) => ({ ...d })));
  const [circuits, setCircuits] = React.useState(() => (p.circuits.length ? [...p.circuits] : []));

  const newUps = { model: "APC Smart-UPS 1500VA", va: 1500, loadPct: 0, batteryHealthPct: 100, runtimeMin: 0 };
  const newPdu = { model: PDU_MODELS[0], outlets: 16, loadAmps: 0 };

  const setU = (i, k, v) => setUps((l) => l.map((u, j) => (j === i ? { ...u, [k]: v } : u)));
  const setD = (i, k, v) => setPdu((l) => l.map((d, j) => (j === i ? { ...d, [k]: v } : d)));
  const setCircuit = (i, v) => setCircuits((l) => l.map((c, j) => (j === i ? v : c)));

  const save = () => onSave({
    upsList: upsList.map((u) => ({ model: u.model, va: +u.va, loadPct: +u.loadPct, batteryHealthPct: +u.batteryHealthPct, runtimeMin: +u.runtimeMin })),
    pduList: pduList.map((d) => ({ model: d.model, outlets: +d.outlets, loadAmps: d.loadAmps === "" ? null : +d.loadAmps })),
    circuits: circuits.map((c) => c.trim()).filter(Boolean),
  });

  const subLabel = (txt, onAdd, addLabel) =>
    React.createElement("div", { className: "field__label", style: { fontSize: 12, textTransform: "uppercase", letterSpacing: ".05em", color: "var(--faint)", margin: "4px 0 12px", display: "flex", alignItems: "center" } },
      txt,
      React.createElement("button", { className: "btn btn--sm btn--ghost", style: { marginLeft: "auto", height: 26 }, onClick: onAdd }, React.createElement(Ic.Plus, null), addLabel));

  return React.createElement(Modal, {
    title: "Edit power",
    sub: closet.code + " \u2014 UPS, PDUs & circuits",
    icon: React.createElement("span", { style: { color: "var(--amber)" } }, React.createElement(Ic.Bolt, null)),
    wide: true,
    onClose,
    footer: React.createElement(React.Fragment, null,
      React.createElement("button", { className: "btn", onClick: onClose }, "Cancel"),
      React.createElement("button", { className: "btn btn--primary", onClick: save }, React.createElement(Ic.Check, null), "Save power")
    ),
  },
    // ---- UPS units ----
    subLabel(`UPS units (${upsList.length})`, () => setUps((l) => [...l, { ...newUps }]), "Add UPS"),
    upsList.length === 0 && React.createElement("div", { className: "muted", style: { fontSize: 13, margin: "-4px 0 14px" } }, "No UPS units. Click \u201cAdd UPS\u201d to add one."),
    upsList.map((u, i) => {
      const amps = (+u.va ? ((+u.loadPct) / 100 * (+u.va / 120)).toFixed(1) : "0.0");
      return React.createElement("div", { className: "pf-card", key: i },
        React.createElement("div", { className: "pf-card__head" },
          React.createElement("span", { style: { color: "var(--amber)", display: "inline-flex" } }, React.createElement(Ic.Bolt, null)),
          React.createElement("h4", null, "UPS " + (i + 1)),
          React.createElement("button", { className: "btn btn--sm btn--danger rm", onClick: () => setUps((l) => l.filter((_, j) => j !== i)) }, React.createElement(Ic.Trash, null), "Remove")),
        React.createElement(Field, { label: "Model" },
          React.createElement("input", { className: "input", value: u.model, list: "ups-models", onChange: (e) => setU(i, "model", e.target.value) })),
        React.createElement("div", { className: "field-row--3", style: { display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 14 } },
          React.createElement(Field, { label: "Capacity (VA)" },
            React.createElement("input", { className: "input", type: "number", min: 0, value: u.va, onChange: (e) => setU(i, "va", e.target.value) })),
          React.createElement(Field, { label: "Load (%)" },
            React.createElement("input", { className: "input", type: "number", min: 0, max: 100, value: u.loadPct, onChange: (e) => setU(i, "loadPct", e.target.value) })),
          React.createElement(Field, { label: "Battery (%)" },
            React.createElement("input", { className: "input", type: "number", min: 0, max: 100, value: u.batteryHealthPct, onChange: (e) => setU(i, "batteryHealthPct", e.target.value) }))),
        React.createElement("div", { className: "field-row" },
          React.createElement(Field, { label: "Runtime (min)" },
            React.createElement("input", { className: "input", type: "number", min: 0, value: u.runtimeMin, onChange: (e) => setU(i, "runtimeMin", e.target.value) })),
          React.createElement(Field, { label: "Est. draw", hint: "Auto from load \u00d7 capacity" },
            React.createElement("input", { className: "input mono", disabled: true, value: amps + " A", style: { color: "var(--muted)", background: "var(--surface-2)" } })))
      );
    }),
    React.createElement("datalist", { id: "ups-models" }, UPS_MODELS.map((m) => React.createElement("option", { key: m, value: m }))),

    // ---- PDUs ----
    React.createElement("div", { style: { height: 6 } }),
    subLabel(`PDUs (${pduList.length})`, () => setPdu((l) => [...l, { ...newPdu }]), "Add PDU"),
    pduList.length === 0 && React.createElement("div", { className: "muted", style: { fontSize: 13, margin: "-4px 0 14px" } }, "No PDUs."),
    pduList.map((d, i) =>
      React.createElement("div", { className: "pf-card", key: i },
        React.createElement("div", { className: "pf-card__head" },
          React.createElement("span", { style: { color: "var(--text-2)", display: "inline-flex" } }, React.createElement(Ic.Bolt, null)),
          React.createElement("h4", null, "PDU " + (i + 1)),
          React.createElement("button", { className: "btn btn--sm btn--danger rm", onClick: () => setPdu((l) => l.filter((_, j) => j !== i)) }, React.createElement(Ic.Trash, null), "Remove")),
        React.createElement(Field, { label: "Model" },
          React.createElement("select", { value: d.model, onChange: (e) => setD(i, "model", e.target.value) },
            PDU_MODELS.concat(PDU_MODELS.includes(d.model) ? [] : [d.model]).map((m) => React.createElement("option", { key: m }, m)))),
        React.createElement("div", { className: "field-row" },
          React.createElement(Field, { label: "Outlets" },
            React.createElement("input", { className: "input", type: "number", min: 0, value: d.outlets, onChange: (e) => setD(i, "outlets", e.target.value) })),
          React.createElement(Field, { label: "Load (A)" },
            React.createElement("input", { className: "input", type: "number", min: 0, step: "0.1", value: d.loadAmps == null ? "" : d.loadAmps, onChange: (e) => setD(i, "loadAmps", e.target.value) })))
      )
    ),

    // ---- Circuits ----
    React.createElement("div", { style: { height: 6 } }),
    subLabel(`Circuits (${circuits.length})`, () => setCircuits((l) => [...l, "\u2014 20A / 120V"]), "Add circuit"),
    circuits.length === 0 && React.createElement("div", { className: "muted", style: { fontSize: 13, margin: "-4px 0 4px" } }, "No circuits."),
    React.createElement("div", { style: { display: "flex", flexDirection: "column", gap: 8 } },
      circuits.map((c, i) =>
        React.createElement("div", { key: i, style: { display: "flex", gap: 8 } },
          React.createElement("input", { className: "input mono", value: c, onChange: (e) => setCircuit(i, e.target.value), placeholder: "A \u2014 20A / 120V" }),
          React.createElement("button", { className: "btn btn--icon btn--danger", style: { flex: "0 0 auto" }, onClick: () => setCircuits((l) => l.filter((_, j) => j !== i)), title: "Remove" }, React.createElement(Ic.Trash, null))
        )
      )
    )
  );
}

Object.assign(window, { ClosetFormModal, FlagModal, SwitchFormModal, MaintFormModal, PowerFormModal });
