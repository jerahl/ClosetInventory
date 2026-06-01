/* ============================================================
   Seed data — deterministic generator so closet IDs / specs are
   stable across reloads (flags & edits persist via localStorage).
   Attaches window.SeedData = { schools, closets }.
   ============================================================ */
(function () {
  // deterministic PRNG
  function mulberry32(a) {
    return function () {
      a |= 0; a = (a + 0x6D2B79F5) | 0;
      let t = Math.imul(a ^ (a >>> 15), 1 | a);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  const rnd = mulberry32(73917);
  const pick = (arr) => arr[Math.floor(rnd() * arr.length)];
  const ri = (lo, hi) => lo + Math.floor(rnd() * (hi - lo + 1));
  const chance = (p) => rnd() < p;

  // school tag colors (oklch families)
  const palettes = [
    { bg: "oklch(0.95 0.03 256)", fg: "oklch(0.45 0.13 256)" },
    { bg: "oklch(0.95 0.03 195)", fg: "oklch(0.42 0.10 195)" },
    { bg: "oklch(0.95 0.05 72)",  fg: "oklch(0.46 0.12 64)" },
    { bg: "oklch(0.95 0.04 18)",  fg: "oklch(0.47 0.14 18)" },
    { bg: "oklch(0.95 0.03 300)", fg: "oklch(0.45 0.13 300)" },
    { bg: "oklch(0.95 0.04 150)", fg: "oklch(0.42 0.11 150)" },
    { bg: "oklch(0.95 0.03 230)", fg: "oklch(0.45 0.12 230)" },
    { bg: "oklch(0.95 0.04 330)", fg: "oklch(0.46 0.13 330)" },
    { bg: "oklch(0.95 0.04 95)",  fg: "oklch(0.45 0.11 95)" },
    { bg: "oklch(0.94 0.02 256)", fg: "oklch(0.40 0.02 256)" },
  ];

  const SCHOOLS = [
    { id: "RHS", name: "Riverside High School",    type: "High",   floors: 3, closets: 18, vlanBase: 10 },
    { id: "EWH", name: "Eastwood High School",     type: "High",   floors: 3, closets: 17, vlanBase: 20 },
    { id: "WVH", name: "Westview High School",     type: "High",   floors: 2, closets: 14, vlanBase: 30 },
    { id: "NMS", name: "Northgate Middle School",  type: "Middle", floors: 2, closets: 11, vlanBase: 40 },
    { id: "MGM", name: "Maple Grove Middle School",type: "Middle", floors: 2, closets: 10, vlanBase: 50 },
    { id: "OAK", name: "Oakmont Elementary",       type: "Elem",   floors: 1, closets: 7,  vlanBase: 60 },
    { id: "PIN", name: "Pinecrest Elementary",     type: "Elem",   floors: 1, closets: 7,  vlanBase: 70 },
    { id: "SUN", name: "Sunridge Elementary",      type: "Elem",   floors: 1, closets: 6,  vlanBase: 80 },
    { id: "LAK", name: "Lakeside Elementary",      type: "Elem",   floors: 1, closets: 6,  vlanBase: 90 },
    { id: "ADM", name: "District Admin Center",    type: "Admin",  floors: 2, closets: 8,  vlanBase: 100 },
  ];
  SCHOOLS.forEach((s, i) => { s.color = palettes[i % palettes.length]; });

  const SW_MODELS = [
    { v: "Cisco", m: "Catalyst 9300-48P", ports: 48, poe: true,  ru: 1 },
    { v: "Cisco", m: "Catalyst 9300-24P", ports: 24, poe: true,  ru: 1 },
    { v: "Cisco", m: "Catalyst 9200-48P", ports: 48, poe: true,  ru: 1 },
    { v: "Cisco", m: "Catalyst 9200-24P", ports: 24, poe: true,  ru: 1 },
    { v: "Cisco", m: "Catalyst 9500-16X", ports: 16, poe: false, ru: 1 },
    { v: "Aruba", m: "CX 6300M 48G",      ports: 48, poe: true,  ru: 1 },
    { v: "Aruba", m: "2930F-48G-PoE+",    ports: 48, poe: true,  ru: 1 },
    { v: "Juniper", m: "EX4300-48P",      ports: 48, poe: true,  ru: 1 },
    { v: "Meraki", m: "MS225-48LP",       ports: 48, poe: true,  ru: 1 },
    { v: "Meraki", m: "MS210-24P",        ports: 24, poe: true,  ru: 1 },
  ];
  const UPS_MODELS = [
    { m: "APC Smart-UPS SRT 3000VA", va: 3000 },
    { m: "APC Smart-UPS 2200VA",     va: 2200 },
    { m: "APC Smart-UPS 1500VA",     va: 1500 },
    { m: "Eaton 9PX 3000VA",         va: 3000 },
    { m: "Eaton 5PX 2200VA",         va: 2200 },
    { m: "CyberPower OR1500LCDRT",   va: 1500 },
  ];
  const PDU_MODELS = ["APC AP8941 Switched", "APC AP7900B Metered", "Eaton EMAB10 Managed", "Tripp Lite PDUMH20HVNET"];
  const TECHS = ["J. Whitfield", "M. Alvarez", "D. Carter", "S. Nguyen", "R. Patel", "T. Brooks", "K. Owens", "L. Sanders"];
  const MAINT_TYPES = [
    { t: "Switch replacement", tone: "amber" },
    { t: "IOS / firmware upgrade", tone: "teal" },
    { t: "UPS battery service", tone: "amber" },
    { t: "Patch / cable cleanup", tone: "default" },
    { t: "Added access switch", tone: "default" },
    { t: "Cooling / thermal check", tone: "teal" },
    { t: "Uplink fiber repair", tone: "amber" },
    { t: "Quarterly inspection", tone: "default" },
  ];
  const FLAG_REASONS = [
    "UPS battery reporting <40% health — schedule replacement.",
    "Access switch port utilization above 90%, needs capacity uplift.",
    "Closet running hot (>82°F), HVAC ticket filed.",
    "Loose uplink fiber, intermittent link errors on Te1/0/1.",
    "Door latch broken — physical security follow-up needed.",
    "Stack member 2 failing, RMA in progress.",
  ];
  const ROOMS = ["Closet", "Telecom Rm", "Data Closet", "IDF", "Server Rm"];

  function pad(n) { return n < 10 ? "0" + n : "" + n; }
  function dateAgo(daysAgo) {
    const d = new Date(2026, 4, 28); // late May 2026
    d.setDate(d.getDate() - daysAgo);
    return d.toISOString().slice(0, 10);
  }

  const closets = [];
  let uid = 1;

  SCHOOLS.forEach((school) => {
    for (let c = 0; c < school.closets; c++) {
      const isMDF = c === 0;
      const floor = isMDF ? 1 : ri(1, school.floors);
      const seq = c; // index within school
      const type = isMDF ? "MDF" : "IDF";
      const code = isMDF
        ? `${school.id}-MDF`
        : `${school.id}-IDF-${floor}${pad(ri(1, 6))}`;
      const room = isMDF ? "Main Distribution Frame" : `${pick(ROOMS)} ${floor}${pad(ri(1, 40))}`;

      // switches
      const swCount = isMDF ? ri(3, 5) : ri(1, 3);
      const switches = [];
      for (let s = 0; s < swCount; s++) {
        const md = isMDF && s === 0 ? SW_MODELS[4] : pick(SW_MODELS);
        const used = Math.min(md.ports, ri(Math.floor(md.ports * 0.3), md.ports));
        switches.push({
          name: `${school.id}-${type}-SW${s + 1}`,
          vendor: md.vendor || md.v,
          model: md.m,
          ports: md.ports,
          used,
          poe: md.poe,
          uplinks: isMDF ? ri(2, 4) : ri(1, 2),
          uplinkSpeed: md.poe ? (chance(0.5) ? "10G SFP+" : "1G SFP") : "10G SFP+",
          mgmtIp: `10.${school.vlanBase}.${seq}.${ri(2, 9)}`,
          serial: `FOC${ri(2100, 2640)}${String.fromCharCode(65 + ri(0, 25))}${String.fromCharCode(65 + ri(0, 25))}${ri(10, 99)}`,
          stack: chance(0.35) ? ri(2, 4) : 1,
        });
      }

      // power
      const upsCount = isMDF ? ri(1, 2) : 1;
      const upsList = [];
      for (let u = 0; u < upsCount; u++) {
        const ups = pick(UPS_MODELS);
        const load = ri(28, 86);
        upsList.push({
          model: ups.m, va: ups.va, loadPct: load,
          batteryHealthPct: ri(46, 100),
          runtimeMin: ri(14, 42),
        });
      }
      const pduCount = isMDF ? ri(2, 4) : ri(1, 2);
      const pduList = [];
      for (let d = 0; d < pduCount; d++) {
        pduList.push({ model: pick(PDU_MODELS), outlets: pick([8, 16, 24]), loadAmps: +(rnd() * 12 + 2).toFixed(1) });
      }
      const power = {
        upsList,
        pduList,
        circuits: isMDF ? ["A — 20A / 120V", "B — 20A / 120V"] : ["A — 20A / 120V"],
      };

      // maintenance
      const mCount = ri(2, 6);
      const maintenance = [];
      let d = ri(8, 60);
      for (let m = 0; m < mCount; m++) {
        const mt = pick(MAINT_TYPES);
        maintenance.push({
          date: dateAgo(d),
          type: mt.t,
          tone: mt.tone,
          tech: pick(TECHS),
          notes: maintNote(mt.t),
        });
        d += ri(45, 220);
      }

      // photos
      const photoLabels = ["Rack — front", "Rack — rear", "UPS / power", "Patch panel", "Room overview", "Label / asset tag"];
      const pCount = ri(2, 4);
      const photos = [];
      for (let p = 0; p < pCount; p++) photos.push(photoLabels[p % photoLabels.length]);

      const flagged = chance(0.13);
      const portsTotal = switches.reduce((a, s) => a + s.ports, 0);
      const portsUsed = switches.reduce((a, s) => a + s.used, 0);

      closets.push({
        uid: uid++,
        code,
        type,
        schoolId: school.id,
        floor,
        room,
        building: isMDF ? "Main" : pick(["Main", "Annex", "Gym", "Science Wing", "Arts Wing", "Athletics"]),
        switches,
        power,
        maintenance,
        photos,
        portsTotal,
        portsUsed,
        flagged,
        flagReason: flagged ? pick(FLAG_REASONS) : null,
        flagDate: flagged ? dateAgo(ri(1, 40)) : null,
        flagTech: flagged ? pick(TECHS) : null,
        updated: dateAgo(ri(1, 90)),
      });
    }
  });

  function maintNote(type) {
    const notes = {
      "Switch replacement": "Swapped failed unit, restored config from backup, verified all uplinks and PoE devices online.",
      "IOS / firmware upgrade": "Upgraded to recommended train, reloaded during maintenance window, no port flaps post-upgrade.",
      "UPS battery service": "Replaced battery modules, ran self-test, runtime back within spec.",
      "Patch / cable cleanup": "Re-dressed patch leads, relabeled drops, updated cable map.",
      "Added access switch": "Installed and stacked new access switch to expand port capacity for new classrooms.",
      "Cooling / thermal check": "Confirmed intake/exhaust airflow, cleaned filters, temps nominal.",
      "Uplink fiber repair": "Replaced damaged LC patch, cleaned connectors, link errors cleared.",
      "Quarterly inspection": "Routine inspection — power, environment, labeling and documentation verified.",
    };
    return notes[type] || "Service completed and verified.";
  }

  window.SeedData = { schools: SCHOOLS, closets };
})();
