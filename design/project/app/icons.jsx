/* Simple line icons (Lucide-style geometry). Exported to window. */
const Ic = (function () {
  const S = ({ d, children, vb = "0 0 24 24", sw = 2, fill = "none", ...p }) =>
    React.createElement(
      "svg",
      { viewBox: vb, fill, stroke: "currentColor", strokeWidth: sw, strokeLinecap: "round", strokeLinejoin: "round", ...p },
      children || React.createElement("path", { d })
    );
  const P = (d) => React.createElement("path", { key: Math.random(), d });

  return {
    Server: (p) => S({ ...p, children: [
      React.createElement("rect", { key: 1, x: 3, y: 3, width: 18, height: 7, rx: 1.6 }),
      React.createElement("rect", { key: 2, x: 3, y: 14, width: 18, height: 7, rx: 1.6 }),
      React.createElement("line", { key: 3, x1: 7, y1: 6.5, x2: 7.01, y2: 6.5 }),
      React.createElement("line", { key: 4, x1: 7, y1: 17.5, x2: 7.01, y2: 17.5 }),
    ]}),
    Switch: (p) => S({ ...p, children: [
      React.createElement("rect", { key: 1, x: 2.5, y: 8, width: 19, height: 8, rx: 1.6 }),
      React.createElement("path", { key: 2, d: "M6 12h.01M9 12h.01M12 12h.01M15 12h.01M18 12h.01" }),
    ]}),
    Bolt: (p) => S({ ...p, d: "M13 2 4.5 13.5H11l-1 8.5L19 10h-6.5z" }),
    Search: (p) => S({ ...p, children: [
      React.createElement("circle", { key: 1, cx: 11, cy: 11, r: 7 }),
      React.createElement("line", { key: 2, x1: 21, y1: 21, x2: 16.65, y2: 16.65 }),
    ]}),
    Plus: (p) => S({ ...p, d: "M12 5v14M5 12h14" }),
    Flag: (p) => S({ ...p, children: [
      React.createElement("path", { key: 1, d: "M4 22V4M4 4h12l-2 4 2 4H4" }),
    ]}),
    Wrench: (p) => S({ ...p, d: "M14.7 6.3a4 4 0 0 0-5.3 5.3L3 18l3 3 6.4-6.4a4 4 0 0 0 5.3-5.3l-2.6 2.6-2.3-.4-.4-2.3z" }),
    Photo: (p) => S({ ...p, children: [
      React.createElement("rect", { key: 1, x: 3, y: 4, width: 18, height: 16, rx: 2 }),
      React.createElement("circle", { key: 2, cx: 8.5, cy: 9.5, r: 1.6 }),
      React.createElement("path", { key: 3, d: "M21 16l-5-5L5 20" }),
    ]}),
    MapPin: (p) => S({ ...p, children: [
      React.createElement("path", { key: 1, d: "M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z" }),
      React.createElement("circle", { key: 2, cx: 12, cy: 10, r: 2.6 }),
    ]}),
    Building: (p) => S({ ...p, children: [
      React.createElement("rect", { key: 1, x: 4, y: 3, width: 16, height: 18, rx: 1.6 }),
      React.createElement("path", { key: 2, d: "M9 7h.01M15 7h.01M9 11h.01M15 11h.01M9 15h.01M15 15h.01" }),
    ]}),
    Chevron: (p) => S({ ...p, d: "M9 6l6 6-6 6" }),
    ChevronDown: (p) => S({ ...p, d: "M6 9l6 6 6-6" }),
    Sort: (p) => S({ ...p, d: "M8 3v18M8 21l-3-3M8 3l3 3M16 21V3M16 3l3 3M16 21l-3-3", sw: 1.7 }),
    Grid: (p) => S({ ...p, children: [
      React.createElement("rect", { key: 1, x: 3, y: 3, width: 7, height: 7, rx: 1.4 }),
      React.createElement("rect", { key: 2, x: 14, y: 3, width: 7, height: 7, rx: 1.4 }),
      React.createElement("rect", { key: 3, x: 3, y: 14, width: 7, height: 7, rx: 1.4 }),
      React.createElement("rect", { key: 4, x: 14, y: 14, width: 7, height: 7, rx: 1.4 }),
    ]}),
    List: (p) => S({ ...p, d: "M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01" }),
    X: (p) => S({ ...p, d: "M18 6 6 18M6 6l12 12" }),
    Check: (p) => S({ ...p, d: "M20 6 9 17l-5-5" }),
    CheckCircle: (p) => S({ ...p, children: [
      React.createElement("circle", { key: 1, cx: 12, cy: 12, r: 9 }),
      React.createElement("path", { key: 2, d: "M8.5 12l2.5 2.5 4.5-5" }),
    ]}),
    Alert: (p) => S({ ...p, children: [
      React.createElement("path", { key: 1, d: "M12 3 2 20h20L12 3z" }),
      React.createElement("line", { key: 2, x1: 12, y1: 10, x2: 12, y2: 14 }),
      React.createElement("line", { key: 3, x1: 12, y1: 17.5, x2: 12.01, y2: 17.5 }),
    ]}),
    Menu: (p) => S({ ...p, d: "M3 6h18M3 12h18M3 18h18" }),
    Edit: (p) => S({ ...p, d: "M4 20h4L19 9l-4-4L4 16v4zM14.5 5.5l4 4" }),
    Trash: (p) => S({ ...p, d: "M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" }),
    Dashboard: (p) => S({ ...p, children: [
      React.createElement("rect", { key: 1, x: 3, y: 3, width: 8, height: 10, rx: 1.4 }),
      React.createElement("rect", { key: 2, x: 3, y: 16, width: 8, height: 5, rx: 1.4 }),
      React.createElement("rect", { key: 3, x: 14, y: 3, width: 7, height: 5, rx: 1.4 }),
      React.createElement("rect", { key: 4, x: 14, y: 11, width: 7, height: 10, rx: 1.4 }),
    ]}),
    Network: (p) => S({ ...p, children: [
      React.createElement("rect", { key: 1, x: 9, y: 2, width: 6, height: 5, rx: 1.2 }),
      React.createElement("rect", { key: 2, x: 2, y: 17, width: 6, height: 5, rx: 1.2 }),
      React.createElement("rect", { key: 3, x: 16, y: 17, width: 6, height: 5, rx: 1.2 }),
      React.createElement("path", { key: 4, d: "M12 7v5M12 12H5v5M12 12h7v5" }),
    ]}),
    Calendar: (p) => S({ ...p, children: [
      React.createElement("rect", { key: 1, x: 3, y: 5, width: 18, height: 16, rx: 2 }),
      React.createElement("path", { key: 2, d: "M3 9h18M8 3v4M16 3v4" }),
    ]}),
    Clock: (p) => S({ ...p, children: [
      React.createElement("circle", { key: 1, cx: 12, cy: 12, r: 9 }),
      React.createElement("path", { key: 2, d: "M12 7v5l3 2" }),
    ]}),
    Battery: (p) => S({ ...p, children: [
      React.createElement("rect", { key: 1, x: 2, y: 7, width: 17, height: 10, rx: 2 }),
      React.createElement("path", { key: 2, d: "M22 10v4" }),
    ]}),
    Filter: (p) => S({ ...p, d: "M3 4h18l-7 8v6l-4 2v-8L3 4z" }),
    Download: (p) => S({ ...p, d: "M12 3v12m0 0 4-4m-4 4-4-4M4 19h16" }),
    Hash: (p) => S({ ...p, d: "M5 9h14M5 15h14M9 4 7.5 20M16.5 4 15 20", sw: 1.7 }),
    Cube: (p) => S({ ...p, children: [
      React.createElement("path", { key: 1, d: "M12 2.5 21 7v10l-9 4.5L3 17V7l9-4.5z" }),
      React.createElement("path", { key: 2, d: "M3 7l9 4.5L21 7M12 11.5V21" }),
    ]}),
  };
})();
window.Ic = Ic;
