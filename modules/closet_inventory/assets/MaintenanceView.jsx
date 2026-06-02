/* District-wide maintenance log. Mirrors QueueView's page-head + stat-strip
   + card-wrapped table pattern. Filter state lives on the parent (App.jsx)
   so the controlled inputs survive view switches. */

const MAINT_TONE_STYLE = {
  default: { bg: "var(--surface-2)",   fg: "var(--muted)" },
  amber:   { bg: "var(--amber-soft)",  fg: "var(--amber)" },
  teal:    { bg: "var(--teal-soft, color-mix(in oklch, var(--teal) 20%, transparent))", fg: "var(--teal)" }
};

function maintToneChip(tone, label) {
  const t = MAINT_TONE_STYLE[tone] || MAINT_TONE_STYLE.default;
  return React.createElement("span", {
    style: {
      display: "inline-flex", alignItems: "center",
      padding: "2px 8px", borderRadius: 999, fontSize: 11, fontWeight: 600,
      background: t.bg, color: t.fg, maxWidth: 240,
      overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap"
    },
    title: label || ""
  }, label || "—");
}

function maintTruncate(s, n) {
  if (!s) return "";
  return s.length > n ? s.slice(0, n - 1) + "…" : s;
}

function MaintenanceView({ data, filters, onChangeFilters, onOpen, onRefresh, isAdmin }) {
  const loading = !data;
  const entries = (data && data.entries) || [];
  const totals = (data && data.totals) || { entries: 0, uniqueClosets: 0, uniqueTechs: 0 };
  const techs = (data && data.techs) || [];
  const types = (data && data.types) || [];

  const f = filters || { from: "", to: "", tech: "", type: "" };
  const setF = (k, v) => onChangeFilters({ ...f, [k]: v });

  const stat = (k, v) =>
    React.createElement("div", { className: "stat" },
      React.createElement("div", { className: "stat__k" }, k),
      React.createElement("div", { className: "stat__v" }, v)
    );

  return React.createElement(React.Fragment, null,
    React.createElement("div", { className: "page-head" },
      React.createElement("div", null,
        React.createElement("h1", { className: "page-title" }, "Maintenance log"),
        React.createElement("p", { className: "page-desc" }, "Recent work across every closet.")
      ),
      isAdmin && React.createElement("div", { className: "page-head__actions" },
        React.createElement("button", { className: "btn", onClick: onRefresh, title: "Reload the log" },
          React.createElement(Ic.Network, null), "Refresh")
      )
    ),

    React.createElement("div", { className: "stat-strip", style: { gridTemplateColumns: "repeat(2, 1fr)" } },
      stat("Entries", totals.entries),
      stat("Closets serviced", totals.uniqueClosets)
    ),

    React.createElement("div", { className: "card", style: { marginTop: 16, padding: 12 } },
      React.createElement("div", {
        style: {
          display: "flex", flexWrap: "wrap", gap: 10, alignItems: "center"
        }
      },
        React.createElement("label", { style: { display: "flex", flexDirection: "column", fontSize: 11, color: "var(--muted)" } },
          "From",
          React.createElement("input", {
            type: "date", className: "input", value: f.from || "",
            onChange: (e) => setF("from", e.target.value),
            style: { minWidth: 140 }
          })
        ),
        React.createElement("label", { style: { display: "flex", flexDirection: "column", fontSize: 11, color: "var(--muted)" } },
          "To",
          React.createElement("input", {
            type: "date", className: "input", value: f.to || "",
            onChange: (e) => setF("to", e.target.value),
            style: { minWidth: 140 }
          })
        ),
        React.createElement("label", { style: { display: "flex", flexDirection: "column", fontSize: 11, color: "var(--muted)" } },
          "Tech",
          React.createElement("select", {
            className: "input", value: f.tech || "",
            onChange: (e) => setF("tech", e.target.value),
            style: { minWidth: 160 }
          },
            React.createElement("option", { value: "" }, "All"),
            techs.map((t) => React.createElement("option", { key: t, value: t }, t))
          )
        ),
        React.createElement("label", { style: { display: "flex", flexDirection: "column", fontSize: 11, color: "var(--muted)" } },
          "Type",
          React.createElement("select", {
            className: "input", value: f.type || "",
            onChange: (e) => setF("type", e.target.value),
            style: { minWidth: 180 }
          },
            React.createElement("option", { value: "" }, "All"),
            types.map((t) => React.createElement("option", { key: t, value: t }, t))
          )
        )
      )
    ),

    React.createElement("div", { className: "card", style: { marginTop: 16, overflow: "hidden" } },
      React.createElement("div", { className: "panel__head", style: { padding: "12px 16px", borderBottom: "1px solid var(--border)" } },
        React.createElement("div", { className: "panel-icon", style: { background: "var(--primary-soft)", color: "var(--primary)" } },
          React.createElement(Ic.Wrench, null)),
        React.createElement("h3", null, "Recent maintenance"),
        React.createElement("span", { className: "count" }, entries.length)
      ),
      loading
        ? React.createElement("div", { className: "muted", style: { padding: 24, textAlign: "center" } }, "Loading maintenance…")
        : entries.length === 0
          ? React.createElement("div", { className: "muted", style: { padding: 24, textAlign: "center" } },
              "No maintenance entries match the current filters.")
          : React.createElement("div", { style: { overflowX: "auto" } },
              React.createElement("table", { className: "tbl queue-tbl" },
                React.createElement("thead", null,
                  React.createElement("tr", null,
                    React.createElement("th", null, "Date"),
                    React.createElement("th", null, "Closet"),
                    React.createElement("th", { className: "q-col-school" }, "School"),
                    React.createElement("th", { className: "q-col-mid" }, "Type"),
                    React.createElement("th", { className: "q-col-mid" }, "Raised by"),
                    React.createElement("th", { className: "q-col-mid" }, "Notes"),
                    React.createElement("th", { style: { textAlign: "right" } }, "Actions")
                  )
                ),
                React.createElement("tbody", null,
                  entries.map((m) =>
                    React.createElement("tr", { key: m.id },
                      React.createElement("td", { style: { whiteSpace: "nowrap" } }, m.date || "—"),
                      React.createElement("td", null,
                        React.createElement("a", {
                          onClick: () => onOpen(m.closetUid),
                          style: { cursor: "pointer", fontWeight: 600 }
                        }, m.closetCode || ("#" + m.closetUid))
                      ),
                      React.createElement("td", { className: "q-col-school" },
                        m.schoolId
                          ? React.createElement(SchoolTag, { id: m.schoolId })
                          : React.createElement("span", { className: "muted" }, "—")
                      ),
                      React.createElement("td", { className: "q-col-mid" }, maintToneChip(m.tone, m.type || "—")),
                      React.createElement("td", { className: "q-col-mid" },
                        m.tech || React.createElement("span", { className: "muted" }, "—")),
                      React.createElement("td", { className: "q-col-mid", title: m.notes || "" },
                        maintTruncate(m.notes || "", 100)),
                      React.createElement("td", { style: { textAlign: "right", whiteSpace: "nowrap" } },
                        React.createElement("button", {
                          className: "btn btn--sm",
                          onClick: () => onOpen(m.closetUid)
                        }, "Open closet")
                      )
                    )
                  )
                )
              )
            )
    )
  );
}

Object.assign(window, { MaintenanceView });
