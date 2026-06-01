/* Closet detail view. */

function SwitchRow({ s, zabbixSource, onMove, onInspect }) {
  const pct = Math.round((s.used / s.ports) * 100);
  const hasHost = s.zabbixHostid != null && s.zabbixHostid !== "";
  const hasXiq  = s.xiqDeviceId != null && +s.xiqDeviceId > 0;
  // Live chip logic: drop the "pending" chip entirely once the closet is
  // pulling live Zabbix data for this switch. Show distinct chips for
  // "not mapped" (authored field missing) and "Zabbix unreachable" (mapped
  // but the snapshot failed) so operators can tell the cases apart.
  let liveChip = null;
  if (!hasHost) {
    const txt = hasXiq
      ? "Live data: not mapped to a Zabbix host"
      : "Live data: not mapped to Zabbix or XIQ";
    liveChip = React.createElement("span", {
      className: "uplink-tag",
      title: hasXiq
        ? "Map this switch to a Zabbix host id to enable port/PoE/problem data."
        : "Map this switch to a Zabbix host id and an XIQ device id to enable live data.",
      style: { background: "var(--surface-2)", color: "var(--muted)", borderColor: "var(--border)" }
    }, txt);
  } else if (zabbixSource === "down") {
    liveChip = React.createElement("span", {
      className: "uplink-tag",
      title: "The Zabbix Item API didn't respond; values shown are the last authored ones.",
      style: { background: "var(--amber-soft)", color: "var(--amber)", borderColor: "color-mix(in oklch, var(--amber) 25%, transparent)" }
    }, "Zabbix unreachable — showing authored values");
  }
  return React.createElement("div", {
    className: "swrow",
    role: onInspect ? "button" : null,
    tabIndex: onInspect ? 0 : null,
    onClick: onInspect ? () => onInspect(s) : null,
    onKeyDown: onInspect ? (e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); onInspect(s); } } : null,
    style: onInspect ? { cursor: "pointer" } : null,
    title: onInspect ? "Click for switch inventory details" : null
  },
    React.createElement("div", { className: "swrow__icon" }, React.createElement(Ic.Switch, null)),
    React.createElement("div", { className: "swrow__main" },
      React.createElement("div", { className: "swrow__name" }, s.name,
        s.stack > 1 && React.createElement("span", { className: "uplink-tag", style: { background: "var(--violet-soft)", color: "var(--violet)", borderColor: "color-mix(in oklch, var(--violet) 25%, transparent)" } }, `Stack ×${s.stack}`),
        s.poe && React.createElement("span", { className: "uplink-tag" }, "PoE+"),
        s.poeStatus && React.createElement("span", {
          className: "uplink-tag",
          title: "Live PoE status from Zabbix",
          style: { background: "var(--teal-soft)", color: "var(--teal)", borderColor: "color-mix(in oklch, var(--teal) 25%, transparent)" }
        }, s.poeStatus),
        (s.xiqConnected !== undefined) && React.createElement("span", {
          className: "uplink-tag",
          title: s.xiqLastSeen ? ("Last seen by XIQ: " + s.xiqLastSeen) : "Reachability from ExtremeCloud IQ",
          style: s.xiqConnected
            ? { background: "var(--teal-soft)", color: "var(--teal)", borderColor: "color-mix(in oklch, var(--teal) 25%, transparent)" }
            : { background: "var(--surface-2)", color: "var(--muted)", borderColor: "var(--border)" }
        }, s.xiqConnected ? "XIQ: online" : "XIQ: offline"),
        (s.configBackupAgeDays != null) && (function () {
          const days = +s.configBackupAgeDays;
          let style, txt;
          if (days <= 7) {
            style = { background: "var(--teal-soft)", color: "var(--teal)", borderColor: "color-mix(in oklch, var(--teal) 25%, transparent)" };
            txt = `Backup ${days}d ago`;
          } else if (days <= 30) {
            style = { background: "var(--amber-soft)", color: "var(--amber)", borderColor: "color-mix(in oklch, var(--amber) 25%, transparent)" };
            txt = `Backup ${days}d ago`;
          } else {
            style = { background: "var(--surface-2)", color: "var(--red, #c44)", borderColor: "color-mix(in oklch, var(--red, #c44) 25%, transparent)" };
            txt = `Backup ${days}d old`;
          }
          return React.createElement("span", {
            className: "uplink-tag",
            title: s.configBackupAt ? ("Last config backup: " + s.configBackupAt) : "From rConfig",
            style
          }, txt);
        })(),
        liveChip
      ),
      React.createElement("div", { className: "swrow__model" }, `${s.vendor} ${s.model}`,
        s.xiqSoftware && React.createElement("span", { className: "muted", style: { marginLeft: 8, fontSize: 11.5 } }, "· " + s.xiqSoftware)
      ),
      React.createElement("div", { className: "swrow__specs" },
        React.createElement("div", { className: "spec", style: { minWidth: 130 } },
          React.createElement("div", { className: "spec__k" }, "Port usage"),
          React.createElement("div", { style: { marginTop: 4 } }, React.createElement(PortBar, { used: s.used, total: s.ports, width: 90 }))),
        React.createElement("div", { className: "spec" },
          React.createElement("div", { className: "spec__k" }, "Mgmt IP"),
          React.createElement("div", { className: "spec__v" }, s.mgmtIp)),
        React.createElement("div", { className: "spec" },
          React.createElement("div", { className: "spec__k" }, "Uplinks"),
          React.createElement("div", { className: "spec__v" }, `${s.uplinks} × ${s.uplinkSpeed}`)),
        React.createElement("div", { className: "spec" },
          React.createElement("div", { className: "spec__k" }, "Serial"),
          React.createElement("div", { className: "spec__v" }, s.serial)),
        onMove && React.createElement("div", { className: "spec", style: { marginLeft: "auto" } },
          React.createElement("button", {
            className: "btn btn--sm",
            onClick: (e) => { e.stopPropagation(); onMove(s); },
            title: "Move this switch to another closet"
          }, React.createElement(Ic.Switch, null), "Move"))
      )
    )
  );
}

function PowerPanel({ power, onEdit }) {
  const ups = power.upsList || [];
  const pdus = power.pduList || [];
  const circuits = power.circuits || [];
  const hasPower = ups.length > 0 || pdus.length > 0;
  return React.createElement("div", { className: "panel" },
    React.createElement("div", { className: "panel__head" },
      React.createElement("div", { className: "panel-icon", style: { background: "var(--amber-soft)", color: "var(--amber)" } }, React.createElement(Ic.Bolt, null)),
      React.createElement("h3", null, "Power"),
      React.createElement("button", { className: "btn btn--sm panel-act", onClick: onEdit }, React.createElement(Ic.Edit, null), "Edit")
    ),
    hasPower
    ? React.createElement("div", { className: "panel__body" },
      ups.length > 0 && React.createElement("div", { className: "pwr-sub" }, "UPS", React.createElement("span", { className: "cnt" }, ups.length)),
      ups.map((u, i) => {
        const bat = u.batteryHealthPct;
        return React.createElement("div", { className: "pwr-unit", key: i },
          React.createElement("div", { className: "pwr-unit__top" },
            React.createElement("div", { className: "gauge gauge--sm", style: { "--p": u.loadPct, "--gc": pctTone(u.loadPct) } },
              React.createElement("div", { className: "gauge__inner" },
                React.createElement("div", { className: "gauge__pct" }, u.loadPct + "%"),
                React.createElement("div", { className: "gauge__lbl" }, "load"))),
            React.createElement("div", { style: { minWidth: 0 } },
              React.createElement("div", { className: "pwr-unit__name" }, u.model),
              React.createElement("div", { className: "pwr-unit__meta" }, (u.va ? u.va + " VA" : "") ))),
          React.createElement("div", { className: "pwr-unit__stats" },
            React.createElement("div", { className: "spec" },
              React.createElement("div", { className: "spec__k" }, "Est. draw"),
              React.createElement("div", { className: "spec__v" }, upsAmps(u) + " A")),
            React.createElement("div", { className: "spec" },
              React.createElement("div", { className: "spec__k" }, "Battery"),
              React.createElement("div", { className: "spec__v", style: { color: bat < 55 ? "var(--rose)" : bat < 75 ? "var(--amber)" : "var(--teal)" } }, bat + "%")),
            React.createElement("div", { className: "spec" },
              React.createElement("div", { className: "spec__k" }, "Runtime"),
              React.createElement("div", { className: "spec__v" }, "~" + u.runtimeMin + " min")))
        );
      }),
      pdus.length > 0 && React.createElement("div", { className: "pwr-sub" }, "PDUs", React.createElement("span", { className: "cnt" }, pdus.length)),
      pdus.map((d, i) =>
        React.createElement("div", { className: "pdu-row", key: i },
          React.createElement("div", { className: "pdu-row__icon" }, React.createElement(Ic.Bolt, null)),
          React.createElement("div", { style: { minWidth: 0 } },
            React.createElement("div", { style: { fontWeight: 600, fontSize: 12.5 } }, d.model),
            React.createElement("div", { className: "muted", style: { fontSize: 11 } }, (d.outlets ? d.outlets + " outlets" : "PDU"))),
          React.createElement("div", { className: "pdu-row__meta" }, d.loadAmps != null && d.loadAmps !== "" ? d.loadAmps + " A" : "\u2014"))
      ),
      circuits.length > 0 && React.createElement(React.Fragment, null,
        React.createElement("div", { className: "pwr-sub" }, "Circuits", React.createElement("span", { className: "cnt" }, circuits.length)),
        React.createElement("div", { className: "circuit-chips" },
          circuits.map((c, i) => React.createElement("span", { className: "circuit-chip", key: i }, c))))
    )
    : React.createElement("div", { className: "panel__body" },
        React.createElement("div", { style: { textAlign: "center", padding: "22px 10px", color: "var(--muted)" } },
          React.createElement("div", { style: { fontSize: 13, marginBottom: 12 } }, "No power equipment recorded yet."),
          React.createElement("button", { className: "btn btn--sm btn--primary", onClick: onEdit }, React.createElement(Ic.Plus, null), "Add UPS / PDU")))
  );
}

function PhotosPanel({ photos, code, onOpenPhoto, onUpload, onDelete }) {
  // Photos arrive as either an object {id,label,path} (Phase 1+) or a plain
  // string (legacy seed data). Normalise so render paths are consistent.
  const items = (photos || []).map((p) => {
    if (typeof p === "string") return { id: null, label: p, url: null };
    const id = p.id != null ? +p.id : null;
    return {
      id,
      label: p.label || "",
      url: id ? ("zabbix.php?action=closet.photo.view&id=" + id) : null
    };
  });

  const fileRef = React.useRef(null);
  const labelRef = React.useRef(null);
  const [busy, setBusy] = React.useState(false);

  const triggerPicker = (capture) => {
    if (!fileRef.current) return;
    if (capture) fileRef.current.setAttribute("capture", "environment");
    else fileRef.current.removeAttribute("capture");
    fileRef.current.click();
  };

  // Downscale a File to a JPEG Blob that fits within MAX_DIM on the long
  // edge. iOS straight-from-camera photos can easily exceed PHP's default
  // upload_max_filesize (2MB), so we shrink before sending. Files already
  // small enough are passed through untouched.
  const MAX_DIM = 2048;
  const MAX_BYTES = 1.5 * 1024 * 1024;
  const downscale = (file) => new Promise((resolve, reject) => {
    if (!/^image\//i.test(file.type) || file.size <= MAX_BYTES) return resolve(file);
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      try {
        const scale = Math.min(1, MAX_DIM / Math.max(img.naturalWidth, img.naturalHeight));
        const w = Math.round(img.naturalWidth  * scale);
        const h = Math.round(img.naturalHeight * scale);
        const canvas = document.createElement("canvas");
        canvas.width = w; canvas.height = h;
        const ctx = canvas.getContext("2d");
        ctx.drawImage(img, 0, 0, w, h);
        canvas.toBlob((blob) => {
          URL.revokeObjectURL(url);
          if (!blob) return resolve(file);
          // wrap as a File so the server still gets a filename
          const base = file.name.replace(/\.[^.]+$/, "") || "photo";
          resolve(new File([blob], base + ".jpg", { type: "image/jpeg", lastModified: Date.now() }));
        }, "image/jpeg", 0.85);
      } catch (err) {
        URL.revokeObjectURL(url);
        resolve(file); // fall back to the original on any failure
      }
    };
    img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
    img.src = url;
  });

  const handleFiles = async (e) => {
    const files = Array.from(e.target.files || []);
    e.target.value = "";
    if (files.length === 0) return;
    const labelHint = (labelRef.current && labelRef.current.value) || "";
    setBusy(true);
    try {
      for (const raw of files) {
        const file = await downscale(raw);
        await onUpload(file, labelHint || raw.name.replace(/\.[^.]+$/, ""));
      }
      if (labelRef.current) labelRef.current.value = "";
    } finally {
      setBusy(false);
    }
  };

  return React.createElement("div", { className: "panel" },
    React.createElement("div", { className: "panel__head" },
      React.createElement("div", { className: "panel-icon", style: { background: "var(--teal-soft)", color: "var(--teal)" } }, React.createElement(Ic.Photo, null)),
      React.createElement("h3", null, "Photos"),
      React.createElement("span", { className: "count" }, items.length)
    ),
    React.createElement("div", { className: "panel__body" },
      // Hidden file input drives both buttons.
      React.createElement("input", {
        ref: fileRef,
        type: "file",
        accept: "image/*",
        multiple: true,
        style: { display: "none" },
        onChange: handleFiles
      }),

      // Caption + upload controls.
      React.createElement("div", { style: { display: "flex", gap: 8, flexWrap: "wrap", marginBottom: 12, alignItems: "center" } },
        React.createElement("input", {
          ref: labelRef,
          className: "input",
          placeholder: "Caption (optional) — e.g. Rack front",
          style: { flex: "1 1 220px", minWidth: 0 }
        }),
        // Camera capture (mobile-first; on desktop it usually falls back to
        // the file picker).
        React.createElement("button", {
          className: "btn btn--primary",
          disabled: busy,
          onClick: () => triggerPicker(true),
          title: "Use the device camera (mobile)"
        }, React.createElement(Ic.Photo, null), busy ? "Uploading…" : "Take photo"),
        React.createElement("button", {
          className: "btn",
          disabled: busy,
          onClick: () => triggerPicker(false),
          title: "Pick existing image(s)"
        }, React.createElement(Ic.Plus, null), "Choose file")
      ),

      React.createElement("div", { className: "photo-grid" },
        items.map((p, i) =>
          React.createElement("div", { className: "photo", key: p.id || ("legacy-" + i), onClick: () => onOpenPhoto(p) },
            p.url
              ? React.createElement("img", {
                  src: p.url,
                  alt: p.label || code,
                  loading: "lazy",
                  style: { width: "100%", height: "100%", objectFit: "cover", display: "block" }
                })
              : React.createElement("div", { className: "photo__lbl" }, "▦"),
            React.createElement("div", { className: "photo__cap" }, p.label || "—"),
            p.id && onDelete && React.createElement("button", {
              className: "btn btn--sm",
              onClick: (e) => { e.stopPropagation(); if (window.confirm("Delete this photo?")) onDelete(p); },
              title: "Delete photo",
              style: { position: "absolute", top: 6, right: 6, padding: "2px 6px", background: "rgba(0,0,0,0.55)", color: "#fff", borderColor: "transparent" }
            }, "×")
          )),
        items.length === 0 && React.createElement("div", { className: "muted", style: { padding: 12, fontSize: 13 } }, "No photos yet — take or upload one above.")
      )
    )
  );
}

/* Compact Zabbix-problems list. Visual only — no actions. Severity colors
   mirror the dashboard's standard palette (0–1 info, 2 warning, 3 average,
   4 high, 5 disaster). */
function ProblemsBlock({ problems }) {
  if (!problems || problems.length === 0) return null;
  const SEV = [
    { c: "var(--muted)",   name: "Info" },
    { c: "var(--muted)",   name: "Info" },
    { c: "var(--amber)",   name: "Warning" },
    { c: "var(--amber)",   name: "Average" },
    { c: "var(--rose)",    name: "High" },
    { c: "var(--rose)",    name: "Disaster" }
  ];
  const top = problems.slice(0, 3);
  const more = problems.length - top.length;
  const rel = (clock) => {
    if (!clock) return "";
    const diff = Math.max(0, Math.floor(Date.now() / 1000) - clock);
    if (diff < 60)    return diff + "s";
    if (diff < 3600)  return Math.floor(diff / 60) + "m";
    if (diff < 86400) return Math.floor(diff / 3600) + "h";
    return Math.floor(diff / 86400) + "d";
  };
  return React.createElement("div", { className: "panel" },
    React.createElement("div", { className: "panel__head" },
      React.createElement("div", { className: "panel-icon", style: { background: "var(--amber-soft)", color: "var(--amber)" } }, React.createElement(Ic.Alert, null)),
      React.createElement("h3", null, "Active problems"),
      React.createElement("span", { className: "count" }, problems.length)
    ),
    React.createElement("div", { className: "panel__body" },
      top.map((p, i) => {
        const sev = SEV[Math.max(0, Math.min(5, p.severity | 0))];
        return React.createElement("div", { key: p.eventid || i, style: { display: "flex", gap: 10, alignItems: "center", padding: "6px 0", fontSize: 13, borderBottom: i < top.length - 1 ? "1px solid var(--border)" : "none" } },
          React.createElement("span", { title: sev.name, style: { width: 8, height: 8, borderRadius: "50%", background: sev.c, flex: "0 0 auto" } }),
          React.createElement("span", { style: { flex: 1, minWidth: 0, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" } }, p.name || "(unnamed problem)"),
          React.createElement("span", { className: "muted", style: { fontSize: 11, fontFamily: "var(--mono, monospace)" } }, rel(p.clock))
        );
      }),
      more > 0 && React.createElement("div", { className: "muted", style: { fontSize: 12, marginTop: 6 } }, "+" + more + " more")
    )
  );
}

function DetailView({ closet, onFlag, onResolve, onEdit, onAddSwitch, onAddMaint, onEditPower, onDelete, onMove, onInspect, onUploadPhoto, onDeletePhoto }) {
  const c = closet;
  const s = schoolOf(c.schoolId);
  const [photo, setPhoto] = React.useState(null);

  return React.createElement(React.Fragment, null,
    React.createElement("div", { className: "detail-head" },
      React.createElement("div", { className: "detail-head__top" },
        React.createElement("div", { className: "detail-icon", style: c.type === "MDF" ? { background: "var(--violet-soft)", color: "var(--violet)" } : null },
          React.createElement(Ic.Server, null)),
        React.createElement("div", { style: { minWidth: 0 } },
          React.createElement("h1", { className: "detail-title" }, c.code, React.createElement(TypeBadge, { type: c.type })),
          React.createElement("div", { className: "detail-loc" },
            React.createElement(Ic.MapPin, null),
            React.createElement(SchoolTag, { id: c.schoolId }), s.name,
            React.createElement("span", { className: "crumbs__sep" }, "·"), `${c.building} Building`,
            React.createElement("span", { className: "crumbs__sep" }, "·"), `Floor ${c.floor}`,
            React.createElement("span", { className: "crumbs__sep" }, "·"), c.room
          )
        ),
        React.createElement("div", { className: "detail-head__actions" },
          c.flagged
            ? React.createElement("button", { className: "btn btn--sm", onClick: () => onResolve(c) }, React.createElement(Ic.Check, null), "Resolve flag")
            : React.createElement("button", { className: "btn btn--sm btn--danger", onClick: () => onFlag(c) }, React.createElement(Ic.Flag, null), "Flag for service"),
          React.createElement("button", { className: "btn btn--sm btn--primary", onClick: () => onEdit(c) }, React.createElement(Ic.Edit, null), "Edit"),
          onDelete && React.createElement("button", {
            className: "btn btn--sm",
            onClick: () => onDelete(c),
            title: "Delete this closet and every dependent row",
            style: {
              color: "var(--red, #c44)",
              borderColor: "color-mix(in oklch, var(--red, #c44) 30%, transparent)"
            }
          }, React.createElement(Ic.Trash, null), "Delete closet")
        )
      ),
      React.createElement("div", { className: "detail-meta" },
        React.createElement("div", null, React.createElement("div", { className: "meta__k" }, "Switches"), React.createElement("div", { className: "meta__v" }, c.switches.length)),
        React.createElement("div", null, React.createElement("div", { className: "meta__k" }, "Ports in use"), React.createElement("div", { className: "meta__v mono" }, `${c.portsUsed} / ${c.portsTotal}`)),
        React.createElement("div", null, React.createElement("div", { className: "meta__k" }, "UPS / PDU"), React.createElement("div", { className: "meta__v mono" }, `${(c.power.upsList || []).length} / ${(c.power.pduList || []).length}`)),
        React.createElement("div", null, React.createElement("div", { className: "meta__k" }, "Last service"), React.createElement("div", { className: "meta__v" }, c.maintenance.length ? fmtDate(c.maintenance[0].date) : "—")),
        React.createElement("div", null, React.createElement("div", { className: "meta__k" }, "Record updated"), React.createElement("div", { className: "meta__v" }, fmtDate(c.updated)))
      )
    ),

    c.flagged && React.createElement("div", { className: "banner-flag" },
      React.createElement(Ic.Alert, null),
      React.createElement("div", null,
        React.createElement("b", null, "Flagged for service "),
        React.createElement("span", { className: "b-meta" }, `— ${c.flagReason}`),
        React.createElement("div", { className: "muted", style: { fontSize: 12, marginTop: 2 } }, `Raised ${fmtDate(c.flagDate)} by ${c.flagTech}`)),
      React.createElement("button", { className: "btn btn--sm b-resolve", onClick: () => onResolve(c) }, React.createElement(Ic.Check, null), "Resolve")
    ),

    React.createElement("div", { className: "detail-grid" },
      // LEFT
      React.createElement("div", null,
        React.createElement("div", { className: "panel" },
          React.createElement("div", { className: "panel__head" },
            React.createElement("div", { className: "panel-icon", style: { background: "var(--primary-soft)", color: "var(--primary-700)" } }, React.createElement(Ic.Switch, null)),
            React.createElement("h3", null, "Switches"),
            React.createElement("span", { className: "count" }, c.switches.length),
            React.createElement("button", { className: "btn btn--sm panel-act", onClick: () => onAddSwitch(c) }, React.createElement(Ic.Plus, null), "Add")
          ),
          React.createElement("div", { className: "panel__body panel__body--flush" },
            c.switches.map((sw, i) => React.createElement(SwitchRow, {
              key: i, s: sw,
              zabbixSource: (c._live && c._live.sources && c._live.sources.zabbix) || null,
              onMove: onMove ? (s) => onMove(c, s) : null,
              onInspect: onInspect ? (s) => onInspect(c, s) : null
            }))
          )
        ),
        React.createElement(ProblemsBlock, { problems: (c._live && Array.isArray(c._live.problems)) ? c._live.problems : [] }),
        React.createElement("div", { className: "panel" },
          React.createElement("div", { className: "panel__head" },
            React.createElement("div", { className: "panel-icon", style: { background: "var(--surface-2)", color: "var(--text-2)", border: "1px solid var(--border)" } }, React.createElement(Ic.Wrench, null)),
            React.createElement("h3", null, "Maintenance history"),
            React.createElement("span", { className: "count" }, c.maintenance.length),
            React.createElement("button", { className: "btn btn--sm panel-act", onClick: () => onAddMaint(c) }, React.createElement(Ic.Plus, null), "Log entry")
          ),
          React.createElement("div", { className: "panel__body" },
            c.maintenance.length === 0
              ? React.createElement("div", { className: "muted", style: { fontSize: 13, padding: "8px 0" } }, "No service history recorded yet.")
              : React.createElement("div", { className: "timeline" },
                  c.maintenance.map((m, i) =>
                    React.createElement("div", { className: "tl-item", key: i },
                      React.createElement("div", { className: "tl-dot tl-dot--" + (m.tone || "default") }),
                      React.createElement("div", { className: "tl-head" },
                        React.createElement("span", { className: "tl-type" }, m.type),
                        React.createElement("span", { className: "tl-date" }, fmtDate(m.date), " · ", relDate(m.date))),
                      React.createElement("div", { className: "tl-notes" }, m.notes),
                      React.createElement("div", { className: "tl-tech" }, "Logged by ", m.tech))
                  )
                )
          )
        )
      ),
      // RIGHT
      React.createElement("div", null,
        React.createElement(PowerPanel, { power: c.power, onEdit: () => onEditPower(c) }),
        React.createElement(PhotosPanel, {
          photos: c.photos, code: c.code,
          onOpenPhoto: setPhoto,
          onUpload: onUploadPhoto ? (file, label) => onUploadPhoto(c, file, label) : null,
          onDelete: onDeletePhoto ? (p) => onDeletePhoto(c, p) : null
        })
      )
    ),

    photo && React.createElement("div", { className: "lightbox", onClick: () => setPhoto(null) },
      React.createElement("div", { className: "lightbox__frame", onClick: (e) => e.stopPropagation() },
        photo.url
          ? React.createElement("img", { src: photo.url, alt: photo.label || c.code, style: { maxWidth: "90vw", maxHeight: "85vh", display: "block" } })
          : React.createElement("div", { style: { padding: 24, color: "var(--muted)" } }, "(no image)"),
        React.createElement("div", { style: { marginTop: 10, color: "#fff", textAlign: "center", fontSize: 13 } }, `${c.code} — ${photo.label || ""}`)
      )
    )
  );
}

Object.assign(window, { DetailView, SwitchRow, PowerPanel, PhotosPanel, ProblemsBlock });
