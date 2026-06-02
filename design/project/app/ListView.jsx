/* List view: stats + filters + sortable table / card grid. */

function StatStrip({ closets }) {
  const total = closets.length;
  const flagged = closets.filter((c) => c.flagged).length;
  const switches = closets.reduce((a, c) => a + c.switches.length, 0);
  const portsUsed = closets.reduce((a, c) => a + c.portsUsed, 0);
  const portsTotal = closets.reduce((a, c) => a + c.portsTotal, 0);
  const util = portsTotal ? Math.round((portsUsed / portsTotal) * 100) : 0;
  const schools = new Set(closets.map((c) => c.schoolId)).size;

  const items = [
    { k: "Switch closets", v: total, sub: `${schools} sites`, icon: React.createElement(Ic.Server, null), tone: "var(--primary)" },
    { k: "Managed switches", v: switches, sub: `${portsTotal.toLocaleString()} ports`, icon: React.createElement(Ic.Switch, null), tone: "var(--violet)" },
    { k: "Port utilization", v: util + "%", sub: `${portsUsed.toLocaleString()} in use`, icon: React.createElement(Ic.Network, null), tone: "var(--teal)" },
    { k: "Flagged for service", v: flagged, sub: flagged ? "needs attention" : "all clear", icon: React.createElement(Ic.Flag, null), tone: "var(--amber)" },
  ];
  return React.createElement("div", { className: "stat-strip" },
    items.map((it, i) =>
      React.createElement("div", { className: "stat", key: i },
        React.createElement("div", { className: "stat__k" },
          React.createElement("span", { style: { color: it.tone, display: "inline-flex" } }, it.icon),
          it.k),
        React.createElement("div", { className: "stat__v" }, it.v),
        React.createElement("div", { className: "page-desc", style: { margin: "4px 0 0", fontSize: 12 } }, it.sub)
      )
    )
  );
}

function SortHead({ label, col, sort, setSort, alignRight }) {
  const active = sort.col === col;
  return React.createElement("th", {
    className: "sortable", style: alignRight ? { textAlign: "right" } : null,
    onClick: () => setSort(active ? { col, dir: sort.dir === "asc" ? "desc" : "asc" } : { col, dir: "asc" }),
  },
    React.createElement("span", { className: "th-in", style: alignRight ? { flexDirection: "row-reverse" } : null },
      label,
      active
        ? React.createElement(Ic.ChevronDown, { style: { opacity: 1, transform: sort.dir === "asc" ? "rotate(180deg)" : "none" } })
        : React.createElement(Ic.Sort, null)
    )
  );
}

function ListView({ closets, query, onOpen, onAddCloset, onFlag }) {
  const [school, setSchool] = React.useState("all");
  const [type, setType] = React.useState("all");
  const [flaggedOnly, setFlaggedOnly] = React.useState(false);
  const [sort, setSort] = React.useState({ col: "code", dir: "asc" });
  const [view, setView] = React.useState("table");

  const filtered = React.useMemo(() => {
    const q = query.trim().toLowerCase();
    let list = closets.filter((c) => {
      if (school !== "all" && c.schoolId !== school) return false;
      if (type !== "all" && c.type !== type) return false;
      if (flaggedOnly && !c.flagged) return false;
      if (q) {
        const hay = [c.code, c.room, c.building, schoolOf(c.schoolId).name,
          ...c.switches.map((s) => s.model + " " + s.mgmtIp + " " + s.name)].join(" ").toLowerCase();
        if (!hay.includes(q)) return false;
      }
      return true;
    });
    const dir = sort.dir === "asc" ? 1 : -1;
    list = [...list].sort((a, b) => {
      let av, bv;
      switch (sort.col) {
        case "school": av = a.schoolId; bv = b.schoolId; break;
        case "type": av = a.type; bv = b.type; break;
        case "switches": av = a.switches.length; bv = b.switches.length; break;
        case "ports": av = a.portsUsed / a.portsTotal; bv = b.portsUsed / b.portsTotal; break;
        case "updated": av = a.updated; bv = b.updated; break;
        default: av = a.code; bv = b.code;
      }
      if (av < bv) return -1 * dir;
      if (av > bv) return 1 * dir;
      return a.code < b.code ? -1 : 1;
    });
    return list;
  }, [closets, query, school, type, flaggedOnly, sort]);

  const schoolOpts = window.SeedData.schools;
  const flaggedCount = closets.filter((c) => c.flagged).length;

  return React.createElement(React.Fragment, null,
    React.createElement("div", { className: "page-head" },
      React.createElement("div", null,
        React.createElement("h1", { className: "page-title" }, "Switch Closets"),
        React.createElement("p", { className: "page-desc" }, "Every IDF and MDF across the district — switches, power, photos and service history.")
      ),
      React.createElement("div", { className: "page-head__actions" },
        React.createElement("button", { className: "btn" }, React.createElement(Ic.Download, null), "Export"),
        React.createElement("button", { className: "btn btn--primary", onClick: onAddCloset }, React.createElement(Ic.Plus, null), "Add closet")
      )
    ),

    React.createElement(StatStrip, { closets }),

    React.createElement("div", { className: "toolbar" },
      React.createElement("div", { className: "filter-chips" },
        React.createElement("button", {
          className: "chip" + (type === "all" && !flaggedOnly ? " is-active" : ""),
          onClick: () => { setType("all"); setFlaggedOnly(false); },
        }, "All"),
        ["MDF", "IDF"].map((t) =>
          React.createElement("button", { key: t, className: "chip" + (type === t ? " is-active" : ""), onClick: () => setType(type === t ? "all" : t) }, t)
        ),
        React.createElement("button", {
          className: "chip" + (flaggedOnly ? " is-active" : ""),
          onClick: () => setFlaggedOnly(!flaggedOnly),
          style: flaggedOnly ? { background: "var(--amber-soft)", borderColor: "color-mix(in oklch, var(--amber) 30%, transparent)", color: "var(--amber)" } : null,
        },
          React.createElement("span", { className: "chip__dot", style: { background: "var(--amber)" } }),
          "Flagged",
          React.createElement("span", { style: { fontFamily: "var(--font-mono)", opacity: .7 } }, flaggedCount)
        )
      ),
      React.createElement("select", { className: "select", value: school, onChange: (e) => setSchool(e.target.value) },
        React.createElement("option", { value: "all" }, "All schools"),
        schoolOpts.map((s) => React.createElement("option", { key: s.id, value: s.id }, s.name))
      ),
      React.createElement("div", { className: "result-count" }, React.createElement("b", null, filtered.length), " of ", closets.length, " closets"),
      React.createElement("div", { className: "seg" },
        React.createElement("button", { className: view === "table" ? "is-active" : "", onClick: () => setView("table"), title: "Table" }, React.createElement(Ic.List, null)),
        React.createElement("button", { className: view === "cards" ? "is-active" : "", onClick: () => setView("cards"), title: "Cards" }, React.createElement(Ic.Grid, null))
      )
    ),

    filtered.length === 0
      ? React.createElement("div", { className: "empty" },
          React.createElement("div", { className: "empty__icon" }, React.createElement(Ic.Search, null)),
          React.createElement("h3", null, "No closets match"),
          React.createElement("p", null, "Try clearing filters or searching a different term."))
      : React.createElement(React.Fragment, null,
          // table (desktop)
          view === "table" && React.createElement("div", { className: "table-wrap desktop-only-table" },
            React.createElement("table", { className: "tbl" },
              React.createElement("thead", null,
                React.createElement("tr", null,
                  React.createElement(SortHead, { label: "Closet", col: "code", sort, setSort }),
                  React.createElement(SortHead, { label: "School", col: "school", sort, setSort }),
                  React.createElement(SortHead, { label: "Type", col: "type", sort, setSort }),
                  React.createElement("th", null, "Location"),
                  React.createElement(SortHead, { label: "Switches", col: "switches", sort, setSort, alignRight: true }),
                  React.createElement(SortHead, { label: "Port use", col: "ports", sort, setSort }),
                  React.createElement(SortHead, { label: "Updated", col: "updated", sort, setSort }),
                  React.createElement("th", null, "")
                )
              ),
              React.createElement("tbody", null,
                filtered.map((c) =>
                  React.createElement("tr", { key: c.uid, onClick: () => onOpen(c) },
                    React.createElement("td", null,
                      React.createElement("div", { className: "cell-id" },
                        c.flagged && React.createElement(FlagDot, null),
                        c.code)),
                    React.createElement("td", null,
                      React.createElement("div", { className: "cell-school" }, React.createElement(SchoolTag, { id: c.schoolId }),
                        React.createElement("span", { className: "muted", style: { fontSize: 12.5, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", maxWidth: 150 } }, schoolOf(c.schoolId).name))),
                    React.createElement("td", null, React.createElement(TypeBadge, { type: c.type })),
                    React.createElement("td", { className: "muted", style: { fontSize: 13 } }, `${c.building} · ${c.room}`),
                    React.createElement("td", { style: { textAlign: "right" }, className: "tabnum mono" }, c.switches.length),
                    React.createElement("td", null, React.createElement(PortBar, { used: c.portsUsed, total: c.portsTotal })),
                    React.createElement("td", { className: "muted", style: { fontSize: 12.5, whiteSpace: "nowrap" } }, relDate(c.updated)),
                    React.createElement("td", { style: { textAlign: "right" } },
                      React.createElement("span", { style: { color: "var(--faint)", display: "inline-flex" } }, React.createElement(Ic.Chevron, null)))
                  )
                )
              )
            )
          ),
          // cards (desktop alt view OR forced on mobile)
          React.createElement("div", { className: view === "cards" ? "card-grid" : "card-grid mobile-cards", style: view === "cards" ? null : { marginTop: 0 } },
            filtered.map((c) => React.createElement(ClosetCard, { key: c.uid, c, onOpen }))
          )
        )
  );
}

function ClosetCard({ c, onOpen }) {
  const pct = Math.round((c.portsUsed / c.portsTotal) * 100);
  return React.createElement("div", { className: "closet-card", onClick: () => onOpen(c) },
    React.createElement("div", { className: "closet-card__top" },
      React.createElement("div", { style: { flex: 1, minWidth: 0 } },
        React.createElement("div", { className: "closet-card__id" },
          c.flagged && React.createElement(FlagDot, null), c.code),
        React.createElement("div", { className: "closet-card__loc" }, `${schoolOf(c.schoolId).name}`),
        React.createElement("div", { className: "closet-card__loc", style: { marginTop: 1 } }, `${c.building} · ${c.room}`)
      ),
      React.createElement(TypeBadge, { type: c.type })
    ),
    React.createElement("div", { className: "closet-card__stats" },
      React.createElement("div", { className: "mini-stat" },
        React.createElement("div", { className: "mini-stat__k" }, "Switches"),
        React.createElement("div", { className: "mini-stat__v" }, c.switches.length)),
      React.createElement("div", { className: "mini-stat" },
        React.createElement("div", { className: "mini-stat__k" }, "Ports"),
        React.createElement("div", { className: "mini-stat__v" }, c.portsUsed, React.createElement("small", null, ` / ${c.portsTotal}`))),
      React.createElement("div", { style: { gridColumn: "1 / -1" } },
        React.createElement(PortBar, { used: c.portsUsed, total: c.portsTotal, width: "100%" }))
    )
  );
}

Object.assign(window, { ListView, StatStrip, ClosetCard });
