/* Service queue: authored "flagged" closets + closets with active Zabbix
   problems. Two disjoint sections fed by closet.queue.data; the endpoint
   degrades to flagged-only when Zabbix is unreachable. */

const SEV = {
  5: { name: "Disaster",  bg: "var(--rose-soft, oklch(0.95 0.05 18))", fg: "var(--rose, oklch(0.50 0.18 18))" },
  4: { name: "High",      bg: "var(--amber-soft)",                     fg: "var(--amber)" },
  3: { name: "Average",   bg: "var(--amber-soft)",                     fg: "var(--amber)" },
  2: { name: "Warning",   bg: "oklch(0.95 0.06 95)",                   fg: "oklch(0.45 0.11 95)" },
  1: { name: "Info",      bg: "var(--primary-soft)",                   fg: "var(--primary)" },
  0: { name: "—",         bg: "var(--surface-2)",                      fg: "var(--muted)" }
};

function sevChip(sev) {
  const s = SEV[Math.max(0, Math.min(5, sev | 0))];
  return React.createElement("span", {
    style: {
      display: "inline-flex", alignItems: "center",
      padding: "2px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600,
      background: s.bg, color: s.fg
    }
  }, s.name);
}

function daysOpen(dateStr) {
  if (!dateStr) return null;
  const d = new Date(dateStr + (dateStr.length === 10 ? "T00:00:00" : ""));
  if (isNaN(d.getTime())) return null;
  const diff = Math.floor((Date.now() - d.getTime()) / 86400000);
  return diff < 0 ? 0 : diff;
}

function truncate(s, n) {
  if (!s) return "";
  return s.length > n ? s.slice(0, n - 1) + "…" : s;
}

function topProblem(problems) {
  if (!problems || problems.length === 0) return null;
  let top = problems[0];
  for (let i = 1; i < problems.length; i++) {
    if ((problems[i].severity | 0) > (top.severity | 0)) top = problems[i];
  }
  return top;
}

function QueueView({ queue, isAdmin, onOpen, onResolve, onRefresh }) {
  const loading = !queue;
  const flagged = (queue && queue.flagged) || [];
  const withProblems = (queue && queue.withProblems) || [];
  const totals = (queue && queue.totals) || { flagged: 0, withProblems: 0, uniqueClosets: 0 };

  const stat = (k, v) =>
    React.createElement("div", { className: "stat" },
      React.createElement("div", { className: "stat__k" }, k),
      React.createElement("div", { className: "stat__v" }, v)
    );

  return React.createElement(React.Fragment, null,
    React.createElement("div", { className: "page-head" },
      React.createElement("div", null,
        React.createElement("h1", { className: "page-title" }, "Service Queue"),
        React.createElement("p", { className: "page-desc" }, "Closets flagged or showing active Zabbix problems.")
      ),
      isAdmin && React.createElement("div", { className: "page-head__actions" },
        React.createElement("button", { className: "btn", onClick: onRefresh, title: "Reload the queue" },
          React.createElement(Ic.Network, null), "Refresh")
      )
    ),

    queue && queue.warning &&
      React.createElement("div", {
        className: "card",
        style: {
          padding: "10px 14px", marginBottom: 16,
          background: "var(--amber-soft)", color: "var(--amber)",
          border: "1px solid var(--amber)", fontSize: 13
        }
      }, "Live problems unavailable: ", queue.warning),

    React.createElement("div", { className: "stat-strip", style: { gridTemplateColumns: "repeat(2, 1fr)" } },
      stat("Flagged", totals.flagged),
      stat("With problems", totals.withProblems)
    ),

    loading
      ? React.createElement("div", { className: "muted", style: { padding: 32, textAlign: "center" } }, "Loading queue…")
      : React.createElement(React.Fragment, null,
          React.createElement(FlaggedSection, { rows: flagged, onOpen, onResolve }),
          React.createElement(ProblemsSection, { rows: withProblems, onOpen })
        )
  );
}

function FlaggedSection({ rows, onOpen, onResolve }) {
  return React.createElement("div", { className: "card", style: { marginTop: 16, overflow: "hidden" } },
    React.createElement("div", { className: "panel__head", style: { padding: "12px 16px", borderBottom: "1px solid var(--border)" } },
      React.createElement("div", { className: "panel-icon", style: { background: "var(--amber-soft)", color: "var(--amber)" } },
        React.createElement(Ic.Flag, null)),
      React.createElement("h3", null, "Flagged for service"),
      React.createElement("span", { className: "count" }, rows.length)
    ),
    rows.length === 0
      ? React.createElement("div", { className: "muted", style: { padding: 24, textAlign: "center" } }, "Nothing flagged.")
      : React.createElement("div", { style: { overflowX: "auto" } },
          React.createElement("table", { className: "tbl queue-tbl" },
            React.createElement("thead", null,
              React.createElement("tr", null,
                React.createElement("th", null, "Closet"),
                React.createElement("th", { className: "q-col-school" }, "School"),
                React.createElement("th", { className: "q-col-mid" }, "Reason"),
                React.createElement("th", { className: "q-col-mid" }, "Raised by"),
                React.createElement("th", { className: "q-col-mid" }, "Days open"),
                React.createElement("th", { style: { textAlign: "right" } }, "Actions")
              )
            ),
            React.createElement("tbody", null,
              rows.map((c) => {
                const d = daysOpen(c.flagDate);
                return React.createElement("tr", { key: c.uid },
                  React.createElement("td", null,
                    React.createElement("a", { onClick: () => onOpen(c.uid), style: { cursor: "pointer", fontWeight: 600 } }, c.code),
                    " ",
                    React.createElement(TypeBadge, { type: c.type })
                  ),
                  React.createElement("td", { className: "q-col-school" }, React.createElement(SchoolTag, { id: c.schoolId })),
                  React.createElement("td", { className: "q-col-mid", title: c.flagReason || "" }, truncate(c.flagReason || "", 80)),
                  React.createElement("td", { className: "q-col-mid" }, c.flagTech || React.createElement("span", { className: "muted" }, "—")),
                  React.createElement("td", { className: "q-col-mid" }, d == null ? "—" : (d + "d")),
                  React.createElement("td", { style: { textAlign: "right", whiteSpace: "nowrap" } },
                    React.createElement("button", { className: "btn btn--sm", onClick: () => onOpen(c.uid) }, "Open"),
                    " ",
                    React.createElement("button", {
                      className: "btn btn--sm",
                      onClick: () => { if (window.confirm("Resolve flag on " + c.code + "?")) onResolve(c); }
                    }, "Resolve")
                  )
                );
              })
            )
          )
        )
  );
}

function ProblemsSection({ rows, onOpen }) {
  return React.createElement("div", { className: "card", style: { marginTop: 16, overflow: "hidden" } },
    React.createElement("div", { className: "panel__head", style: { padding: "12px 16px", borderBottom: "1px solid var(--border)" } },
      React.createElement("div", { className: "panel-icon", style: { background: "var(--rose-soft, oklch(0.95 0.05 18))", color: "var(--rose, oklch(0.50 0.18 18))" } },
        React.createElement(Ic.Alert, null)),
      React.createElement("h3", null, "Active problems (from Zabbix)"),
      React.createElement("span", { className: "count" }, rows.length)
    ),
    rows.length === 0
      ? React.createElement("div", { className: "muted", style: { padding: 24, textAlign: "center" } }, "No active Zabbix problems on mapped switches.")
      : React.createElement("div", { style: { overflowX: "auto" } },
          React.createElement("table", { className: "tbl queue-tbl" },
            React.createElement("thead", null,
              React.createElement("tr", null,
                React.createElement("th", null, "Closet"),
                React.createElement("th", { className: "q-col-school" }, "School"),
                React.createElement("th", { className: "q-col-mid" }, "Top severity"),
                React.createElement("th", { className: "q-col-mid" }, "Problem"),
                React.createElement("th", { className: "q-col-mid" }, "Count"),
                React.createElement("th", { style: { textAlign: "right" } }, "Actions")
              )
            ),
            React.createElement("tbody", null,
              rows.map((c) => {
                const top = topProblem(c.problems);
                return React.createElement("tr", { key: c.uid },
                  React.createElement("td", null,
                    React.createElement("a", { onClick: () => onOpen(c.uid), style: { cursor: "pointer", fontWeight: 600 } }, c.code),
                    " ",
                    React.createElement(TypeBadge, { type: c.type })
                  ),
                  React.createElement("td", { className: "q-col-school" }, React.createElement(SchoolTag, { id: c.schoolId })),
                  React.createElement("td", { className: "q-col-mid" }, top ? sevChip(top.severity) : sevChip(0)),
                  React.createElement("td", { className: "q-col-mid", title: top ? (top.name || "") : "" },
                    truncate(top ? (top.name || "(unnamed problem)") : "—", 80)),
                  React.createElement("td", { className: "q-col-mid" }, c.problemCount),
                  React.createElement("td", { style: { textAlign: "right", whiteSpace: "nowrap" } },
                    React.createElement("button", { className: "btn btn--sm", onClick: () => onOpen(c.uid) }, "Open")
                  )
                );
              })
            )
          )
        )
  );
}

Object.assign(window, { QueueView });
