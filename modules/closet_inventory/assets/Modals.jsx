/* Modals: closet form, flag, add switch, log maintenance. */

function ClosetFormModal({ closet, onClose, onSave }) {
  const editing = !!closet;
  const [f, setF] = React.useState(() => closet ? { ...closet } : {
    schoolId: window.SeedData.schools[0].id, type: "IDF", floor: 1, building: "Main", room: "",
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
  });
  const set = (k, v) => setF((p) => ({ ...p, [k]: v }));
  return React.createElement(Modal, {
    title: "Add switch",
    sub: "to " + closet.code,
    icon: React.createElement(Ic.Switch, null),
    onClose,
    footer: React.createElement(React.Fragment, null,
      React.createElement("button", { className: "btn", onClick: onClose }, "Cancel"),
      React.createElement("button", { className: "btn btn--primary", onClick: () => {
        const [vendor, ...rest] = f.model.split(" ");
        onSave({ ...f, vendor, model: rest.join(" "), ports: +f.ports, used: +f.used, uplinks: +f.uplinks, stack: +f.stack });
      } }, React.createElement(Ic.Check, null), "Add switch")
    ),
  },
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
