/* Schools page: per-site rollup of closets, switches, ports, and flags.
   Mirrors MaintenanceView / QueueView's page-head + stat-strip + card grid.
   Each tile clicks through to ListView with the school filter pre-set. */

function SchoolsView({ data, onOpenSchool, onRefresh, isAdmin }) {
  const loading = !data;
  const schools = (data && data.schools) || [];
  const totals  = (data && data.totals)  || { schools: 0, closets: 0, switches: 0, portsTotal: 0, portsUsed: 0, flagged: 0 };
  const util = totals.portsTotal ? Math.round((totals.portsUsed / totals.portsTotal) * 100) : 0;

  const stat = (k, v, sub) =>
    React.createElement("div", { className: "stat" },
      React.createElement("div", { className: "stat__k" }, k),
      React.createElement("div", { className: "stat__v" }, v),
      sub && React.createElement("div", { className: "page-desc", style: { margin: "4px 0 0", fontSize: 12 } }, sub)
    );

  return React.createElement(React.Fragment, null,
    React.createElement("div", { className: "page-head" },
      React.createElement("div", null,
        React.createElement("h1", { className: "page-title" }, "Schools"),
        React.createElement("p", { className: "page-desc" }, "Closet inventory rolled up by site.")
      ),
      isAdmin && React.createElement("div", { className: "page-head__actions" },
        React.createElement("button", { className: "btn", onClick: onRefresh, title: "Reload schools" },
          React.createElement(Ic.Network, null), "Refresh")
      )
    ),

    React.createElement("div", { className: "stat-strip" },
      stat("Schools",       totals.schools),
      stat("Closets",       totals.closets),
      stat("Switches",      totals.switches),
      stat("Ports in use",  totals.portsUsed.toLocaleString(), util + "% utilization")
    ),

    loading
      ? React.createElement("div", { className: "muted", style: { padding: 32, textAlign: "center" } }, "Loading schools…")
      : schools.length === 0
        ? React.createElement("div", { className: "empty" },
            React.createElement("div", { className: "empty__icon" }, React.createElement(Ic.Building, null)),
            React.createElement("h3", null, "No schools yet"),
            React.createElement("p", null, "Run Populate from Zabbix from the closets page."))
        : React.createElement("div", { className: "card-grid" },
            schools.map((s) => React.createElement(SchoolTile, { key: s.id, s, onOpenSchool }))
          )
  );
}

function SchoolTile({ s, onOpenSchool }) {
  const util = s.portsTotal ? Math.round((s.portsUsed / s.portsTotal) * 100) : 0;
  const flagTone = s.flaggedCount > 0
    ? { color: "var(--amber)" }
    : null;

  return React.createElement("div", {
      className: "closet-card school-card",
      onClick: () => onOpenSchool(s.id)
    },
    React.createElement("div", { className: "closet-card__top" },
      React.createElement("span", {
        className: "school-tag",
        style: { background: s.color.bg, color: s.color.fg, flex: "0 0 auto" }
      }, s.id),
      React.createElement("div", { style: { flex: 1, minWidth: 0 } },
        React.createElement("div", { className: "closet-card__id", style: { fontFamily: "inherit", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" } }, s.name),
        s.lastUpdated && React.createElement("div", { className: "closet-card__loc", style: { marginTop: 2 } }, "Updated " + (window.relDate ? window.relDate(s.lastUpdated) : s.lastUpdated))
      ),
      s.type && React.createElement("span", { className: "tbadge tbadge--idf" }, s.type)
    ),

    React.createElement("div", { className: "closet-card__stats" },
      React.createElement("div", { className: "mini-stat" },
        React.createElement("div", { className: "mini-stat__k" }, "Closets"),
        React.createElement("div", { className: "mini-stat__v" }, s.closetCount)),
      React.createElement("div", { className: "mini-stat" },
        React.createElement("div", { className: "mini-stat__k" }, "Switches"),
        React.createElement("div", { className: "mini-stat__v" }, s.switchCount)),
      React.createElement("div", { className: "mini-stat" },
        React.createElement("div", { className: "mini-stat__k" }, "Port utilization"),
        React.createElement("div", { className: "mini-stat__v" }, util + "%",
          React.createElement("small", null, ` ${s.portsUsed}/${s.portsTotal}`)),
        React.createElement("div", { style: { marginTop: 6 } },
          React.createElement(PortBar, { used: s.portsUsed, total: s.portsTotal, width: "100%" }))
      ),
      React.createElement("div", { className: "mini-stat", style: flagTone },
        React.createElement("div", { className: "mini-stat__k", style: flagTone }, "Flagged"),
        React.createElement("div", { className: "mini-stat__v", style: flagTone }, s.flaggedCount))
    ),

    React.createElement("div", {
      style: {
        display: "flex", alignItems: "center", justifyContent: "space-between",
        marginTop: 4, gap: 10
      }
    },
      React.createElement("span", {
        className: "muted",
        style: {
          fontFamily: "var(--font-mono)", fontSize: 11.5,
          whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
          minWidth: 0
        },
        title: s.zbxGroup || ""
      }, s.zbxGroup || "—"),
      React.createElement("a", {
        onClick: (e) => { e.stopPropagation(); onOpenSchool(s.id); },
        style: { cursor: "pointer", fontSize: 12.5, fontWeight: 600, color: "var(--primary)", whiteSpace: "nowrap" }
      }, "View closets →")
    )
  );
}

Object.assign(window, { SchoolsView, SchoolTile });
