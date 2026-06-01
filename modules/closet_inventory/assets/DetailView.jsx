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

function PhotosPanel({ photos, code, onOpenPhoto, onAdd }) {
  return React.createElement("div", { className: "panel" },
    React.createElement("div", { className: "panel__head" },
      React.createElement("div", { className: "panel-icon", style: { background: "var(--teal-soft)", color: "var(--teal)" } }, React.createElement(Ic.Photo, null)),
      React.createElement("h3", null, "Photos"),
      React.createElement("span", { className: "count" }, photos.length)
    ),
    React.createElement("div", { className: "panel__body" },
      React.createElement("div", { className: "photo-grid" },
        photos.map((p, i) =>
          React.createElement("div", { className: "photo", key: i, onClick: () => onOpenPhoto(p) },
            React.createElement("div", { className: "photo__lbl" }, "▦"),
            React.createElement("div", { className: "photo__cap" }, p))),
        React.createElement("div", { className: "photo photo--add", onClick: onAdd },
          React.createElement(Ic.Plus, null), "Add photo")
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

function DetailView({ closet, onFlag, onResolve, onEdit, onAddSwitch, onAddMaint, onEditPower, onDelete, onMove, onInspect }) {
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
        React.createElement(PhotosPanel, { photos: c.photos, code: c.code, onOpenPhoto: setPhoto, onAdd: () => onAddMaint && setPhoto(c.photos[0] || "Rack — front") })
      )
    ),

    photo && React.createElement("div", { className: "lightbox", onClick: () => setPhoto(null) },
      React.createElement("div", { className: "lightbox__frame" },
        React.createElement("span", null, `${c.code} — ${photo}`))
    )
  );
}

Object.assign(window, { DetailView, SwitchRow, PowerPanel, PhotosPanel, ProblemsBlock });
