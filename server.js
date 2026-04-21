const http = require("http");
const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");
const PORT = 3003;
const MIME = { ".html": "text/html", ".css": "text/css", ".js": "application/javascript", ".json": "application/json", ".png": "image/png", ".jpg": "image/jpeg", ".svg": "image/svg+xml", ".ico": "image/x-icon", ".woff2": "font/woff2", ".woff": "font/woff" };

// --- Analytics Config ---
const DATA_DIR = path.join(__dirname, "data/analytics");
const SESSIONS_DIR = path.join(DATA_DIR, "sessions");
const DAILY_DIR = path.join(DATA_DIR, "daily");
const INDEX_FILE = path.join(DATA_DIR, "index.json");
const SALES_FILE = path.join(__dirname, "api/sales.json");
const ADMIN_PASS = "MomentoCrypto2026!";

// Ensure dirs exist
[DATA_DIR, SESSIONS_DIR, DAILY_DIR].forEach(d => { try { fs.mkdirSync(d, { recursive: true }); } catch {} });

// --- In-memory state ---
let sessionIndex = {};
try { sessionIndex = JSON.parse(fs.readFileSync(INDEX_FILE, "utf8")); } catch { sessionIndex = {}; }
const activeStreams = new Map(); // sessionId -> WriteStream
const geoCache = new Map(); // ip -> { country, city, ts }

function flushIndex() {
    try { fs.writeFileSync(INDEX_FILE, JSON.stringify(sessionIndex)); } catch (e) { console.error("[Analytics] Index flush failed:", e.message); }
}
setInterval(flushIndex, 60000);

// Cleanup old sessions (>30 days)
function cleanupOld() {
    const cutoff = Date.now() - 30 * 24 * 60 * 60 * 1000;
    let cleaned = 0;
    for (const [sid, meta] of Object.entries(sessionIndex)) {
        if (meta.start < cutoff) {
            try { fs.unlinkSync(path.join(SESSIONS_DIR, `${sid}.events.jsonl`)); } catch {}
            try { fs.unlinkSync(path.join(SESSIONS_DIR, `${sid}.rrweb.jsonl`)); } catch {}
            try { fs.unlinkSync(path.join(SESSIONS_DIR, `${sid}.meta.json`)); } catch {}
            delete sessionIndex[sid];
            cleaned++;
        }
    }
    if (cleaned) { console.log(`[Analytics] Cleaned ${cleaned} old sessions`); flushIndex(); }
}
cleanupOld();
setInterval(cleanupOld, 24 * 60 * 60 * 1000);

// Geo lookup with cache
function geoLookup(ip) {
    if (!ip || ip === "127.0.0.1" || ip === "::1") return { country: "Local", country_code: "XX", city: "" };
    const cached = geoCache.get(ip);
    if (cached && Date.now() - cached.ts < 3600000) return cached;
    try {
        const raw = execSync(`curl -s --max-time 2 "http://ip-api.com/json/${ip}?fields=status,country,countryCode,city"`, { encoding: "utf8", timeout: 3000 });
        const geo = JSON.parse(raw);
        if (geo.status === "success") {
            const result = { country: geo.country || "", country_code: geo.countryCode || "", city: geo.city || "", ts: Date.now() };
            geoCache.set(ip, result);
            return result;
        }
    } catch {}
    return { country: "Unknown", country_code: "XX", city: "" };
}

function getClientIP(req) {
    return (req.headers["x-forwarded-for"] || req.headers["x-real-ip"] || req.socket.remoteAddress || "").split(",")[0].trim();
}

function parseUA(ua) {
    if (!ua) return { device: "unknown", browser: "unknown" };
    const device = /Mobi/i.test(ua) ? (/Tablet|iPad/i.test(ua) ? "tablet" : "mobile") : "desktop";
    let browser = "other";
    if (/Chrome/i.test(ua) && !/Edge|OPR/i.test(ua)) browser = "Chrome";
    else if (/Firefox/i.test(ua)) browser = "Firefox";
    else if (/Safari/i.test(ua) && !/Chrome/i.test(ua)) browser = "Safari";
    else if (/Edge/i.test(ua)) browser = "Edge";
    else if (/OPR|Opera/i.test(ua)) browser = "Opera";
    return { device, browser };
}

function readBody(req) {
    return new Promise((resolve) => {
        let body = "";
        req.on("data", c => body += c);
        req.on("end", () => { try { resolve(JSON.parse(body)); } catch { resolve({}); } });
    });
}

function authCheck(req) {
    const auth = req.headers.authorization || "";
    return auth === `Bearer ${ADMIN_PASS}`;
}

// --- Aggregate computation ---
function computeAggregate(from, to) {
    const sessions = Object.values(sessionIndex).filter(s => s.start >= from && s.start <= to);
    const total = sessions.length;
    const converted = sessions.filter(s => s.converted).length;
    const countries = {};
    const devices = {};
    const browsers = {};
    const referrers = {};
    const packages = { starter: 0, trader: 0, pro: 0 };
    let totalDuration = 0;
    let bounced = 0;
    let totalRevenue = 0;

    sessions.forEach(s => {
        countries[s.country || "Unknown"] = (countries[s.country || "Unknown"] || 0) + 1;
        devices[s.device || "unknown"] = (devices[s.device || "unknown"] || 0) + 1;
        browsers[s.browser || "other"] = (browsers[s.browser || "other"] || 0) + 1;
        const ref = s.referrer || "direct";
        referrers[ref] = (referrers[ref] || 0) + 1;
        totalDuration += (s.duration || 0);
        if ((s.pages || 0) <= 1 && (s.duration || 0) < 10000) bounced++;
        if (s.sale) {
            packages[s.sale.package] = (packages[s.sale.package] || 0) + 1;
            totalRevenue += s.sale.amount || 0;
        }
    });

    // Funnel counts from events
    let funnelPackagesViewed = 0, funnelPayClicked = 0, funnelInvoiceCreated = 0, funnelPaymentComplete = 0;
    sessions.forEach(s => {
        if (s.saw_packages) funnelPackagesViewed++;
        if (s.pay_clicked) funnelPayClicked++;
        if (s.invoice_created) funnelInvoiceCreated++;
        if (s.converted) funnelPaymentComplete++;
    });

    return {
        visitors: total,
        sessions: total,
        converted,
        conversionRate: total ? (converted / total * 100).toFixed(1) : 0,
        bounceRate: total ? (bounced / total * 100).toFixed(1) : 0,
        avgDuration: total ? Math.round(totalDuration / total / 1000) : 0,
        totalRevenue,
        countries: Object.entries(countries).sort((a, b) => b[1] - a[1]),
        devices: Object.entries(devices).sort((a, b) => b[1] - a[1]),
        browsers: Object.entries(browsers).sort((a, b) => b[1] - a[1]),
        referrers: Object.entries(referrers).sort((a, b) => b[1] - a[1]),
        packages,
        funnel: { visited: total, saw_packages: funnelPackagesViewed, pay_clicked: funnelPayClicked, invoice_created: funnelInvoiceCreated, payment_complete: funnelPaymentComplete }
    };
}

// FAQ aggregate
function computeFaqStats(from, to) {
    const faqCounts = {};
    for (const [sid, meta] of Object.entries(sessionIndex)) {
        if (meta.start < from || meta.start > to) continue;
        const eventsFile = path.join(SESSIONS_DIR, `${sid}.events.jsonl`);
        try {
            const lines = fs.readFileSync(eventsFile, "utf8").trim().split("\n");
            lines.forEach(line => {
                try {
                    const ev = JSON.parse(line);
                    if (ev.type === "faq_open" && ev.data?.question) {
                        const q = ev.data.question;
                        faqCounts[q] = (faqCounts[q] || 0) + 1;
                    }
                } catch {}
            });
        } catch {}
    }
    return Object.entries(faqCounts).sort((a, b) => b[1] - a[1]).map(([q, c]) => ({ question: q, count: c }));
}

// Heatmap aggregate
function computeHeatmap(from, to) {
    const clicks = [];
    let count = 0;
    for (const [sid, meta] of Object.entries(sessionIndex)) {
        if (meta.start < from || meta.start > to) continue;
        if (count++ > 200) break; // limit scan
        const eventsFile = path.join(SESSIONS_DIR, `${sid}.events.jsonl`);
        try {
            const lines = fs.readFileSync(eventsFile, "utf8").trim().split("\n");
            lines.forEach(line => {
                try {
                    const ev = JSON.parse(line);
                    if (ev.type === "click" && ev.data?.xPct != null) {
                        clicks.push({ x: ev.data.xPct, y: ev.data.yPct, section: ev.data.section || "" });
                    }
                } catch {}
            });
        } catch {}
    }
    return clicks;
}

// --- HTTP Server ---
http.createServer(async (req, res) => {
    const url = new URL(req.url, `http://${req.headers.host}`);
    const pathname = url.pathname;

    // CORS for analytics
    if (req.method === "OPTIONS") { res.writeHead(204, { "Access-Control-Allow-Origin": "*", "Access-Control-Allow-Methods": "POST,GET,OPTIONS", "Access-Control-Allow-Headers": "Content-Type,Authorization" }); res.end(); return; }

    // --- Analytics: Ingest Event ---
    if (req.method === "POST" && pathname === "/api/analytics/event") {
        const body = await readBody(req);
        const sid = body.sid;
        if (!sid) { res.writeHead(400); res.end(); return; }

        const ip = getClientIP(req);
        const ua = req.headers["user-agent"] || "";
        const { device, browser } = parseUA(ua);

        // Initialize session if new
        if (!sessionIndex[sid]) {
            const geo = geoLookup(ip);
            sessionIndex[sid] = {
                start: Date.now(), last: Date.now(), duration: 0,
                country: geo.country, country_code: geo.country_code, city: geo.city,
                device, browser, referrer: body.data?.referrer || "", utm: body.data?.utm || null,
                pages: 0, events: 0, saw_packages: false, pay_clicked: false, invoice_created: false,
                converted: false, sale: null, scroll_max: 0
            };
        }

        const session = sessionIndex[sid];
        session.last = Date.now();
        session.duration = session.last - session.start;
        session.events = (session.events || 0) + 1;

        // Update session flags from events
        if (body.type === "page_view") session.pages = (session.pages || 0) + 1;
        if (body.type === "section_view" && body.data?.section === "packages") session.saw_packages = true;
        if (body.type === "pay_click") session.pay_clicked = true;
        if (body.type === "invoice_created") session.invoice_created = true;
        if (body.type === "payment_complete") { session.converted = true; session.sale = body.data?.sale || null; }
        if (body.type === "scroll" && body.data?.percent > (session.scroll_max || 0)) session.scroll_max = body.data.percent;

        // Append event to session file
        const eventLine = JSON.stringify({ type: body.type, ts: body.ts || Date.now(), data: body.data || {} }) + "\n";
        const evFile = path.join(SESSIONS_DIR, `${sid}.events.jsonl`);
        try { fs.appendFileSync(evFile, eventLine); } catch {}

        res.writeHead(204, { "Access-Control-Allow-Origin": "*" }); res.end();
        return;
    }

    // --- Analytics: Ingest rrweb ---
    if (req.method === "POST" && pathname === "/api/analytics/rrweb") {
        const body = await readBody(req);
        const sid = body.sid;
        if (!sid || !body.events) { res.writeHead(400); res.end(); return; }

        const rrFile = path.join(SESSIONS_DIR, `${sid}.rrweb.jsonl`);
        const lines = body.events.map(e => JSON.stringify(e)).join("\n") + "\n";
        try { fs.appendFileSync(rrFile, lines); } catch {}

        res.writeHead(204, { "Access-Control-Allow-Origin": "*" }); res.end();
        return;
    }

    // --- Admin Dashboard ---
    if (pathname === "/admin" || pathname === "/admin/") {
        const dashFile = path.join(__dirname, "admin-dashboard.html");
        try {
            const data = fs.readFileSync(dashFile);
            res.writeHead(200, { "Content-Type": "text/html" });
            res.end(data);
        } catch { res.writeHead(404); res.end("Dashboard not found"); }
        return;
    }

    // --- Admin API: Auth check ---
    if (pathname.startsWith("/api/analytics/") && pathname !== "/api/analytics/event" && pathname !== "/api/analytics/rrweb") {
        if (!authCheck(req)) { res.writeHead(401, { "Content-Type": "application/json" }); res.end(JSON.stringify({ error: "Unauthorized" })); return; }
    }

    // --- Admin API: Dashboard stats ---
    if (req.method === "GET" && pathname === "/api/analytics/dashboard") {
        const period = url.searchParams.get("period") || "7d";
        const now = Date.now();
        let from = now - 7 * 86400000;
        if (period === "today") from = new Date().setHours(0, 0, 0, 0);
        else if (period === "30d") from = now - 30 * 86400000;
        else if (period === "all") from = 0;

        const stats = computeAggregate(from, now);

        // Daily breakdown
        const daily = {};
        Object.values(sessionIndex).filter(s => s.start >= from).forEach(s => {
            const day = new Date(s.start).toISOString().split("T")[0];
            if (!daily[day]) daily[day] = { visitors: 0, converted: 0, revenue: 0 };
            daily[day].visitors++;
            if (s.converted) daily[day].converted++;
            if (s.sale) daily[day].revenue += s.sale.amount || 0;
        });
        stats.daily = Object.entries(daily).sort().map(([date, d]) => ({ date, ...d }));

        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify(stats));
        return;
    }

    // --- Admin API: Session list ---
    if (req.method === "GET" && pathname === "/api/analytics/sessions") {
        const page = parseInt(url.searchParams.get("page") || "1");
        const limit = parseInt(url.searchParams.get("limit") || "50");
        const all = Object.entries(sessionIndex)
            .map(([id, m]) => ({ id, ...m }))
            .sort((a, b) => b.start - a.start);
        const paginated = all.slice((page - 1) * limit, page * limit);
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ sessions: paginated, total: all.length, page, pages: Math.ceil(all.length / limit) }));
        return;
    }

    // --- Admin API: Single session events ---
    if (req.method === "GET" && pathname.match(/^\/api\/analytics\/session\/[^/]+$/)) {
        const sid = pathname.split("/").pop();
        const meta = sessionIndex[sid];
        if (!meta) { res.writeHead(404, { "Content-Type": "application/json" }); res.end(JSON.stringify({ error: "Not found" })); return; }
        const evFile = path.join(SESSIONS_DIR, `${sid}.events.jsonl`);
        let events = [];
        try {
            events = fs.readFileSync(evFile, "utf8").trim().split("\n").map(l => JSON.parse(l));
        } catch {}
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ meta, events }));
        return;
    }

    // --- Admin API: Session rrweb recording ---
    if (req.method === "GET" && pathname.match(/^\/api\/analytics\/session\/[^/]+\/rrweb$/)) {
        const sid = pathname.split("/")[4];
        const rrFile = path.join(SESSIONS_DIR, `${sid}.rrweb.jsonl`);
        try {
            const lines = fs.readFileSync(rrFile, "utf8").trim().split("\n");
            const events = lines.map(l => { try { return JSON.parse(l); } catch { return null; } }).filter(Boolean);
            res.writeHead(200, { "Content-Type": "application/json" });
            res.end(JSON.stringify({ events }));
        } catch {
            res.writeHead(200, { "Content-Type": "application/json" });
            res.end(JSON.stringify({ events: [] }));
        }
        return;
    }

    // --- Admin API: Heatmap ---
    if (req.method === "GET" && pathname === "/api/analytics/heatmap") {
        const now = Date.now();
        const from = now - 7 * 86400000;
        const clicks = computeHeatmap(from, now);
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ clicks }));
        return;
    }

    // --- Admin API: FAQ stats ---
    if (req.method === "GET" && pathname === "/api/analytics/faq") {
        const now = Date.now();
        const from = now - 30 * 86400000;
        const faq = computeFaqStats(from, now);
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ faq }));
        return;
    }

    // --- Admin API: Live sessions ---
    if (req.method === "GET" && pathname === "/api/analytics/live") {
        const cutoff = Date.now() - 5 * 60 * 1000;
        const live = Object.entries(sessionIndex)
            .filter(([_, m]) => m.last > cutoff)
            .map(([id, m]) => ({ id: id.slice(0, 8), country: m.country, device: m.device, pages: m.pages, scroll_max: m.scroll_max, duration: Math.round((Date.now() - m.start) / 1000), idle: Math.round((Date.now() - m.last) / 1000) }));
        res.writeHead(200, { "Content-Type": "application/json" });
        res.end(JSON.stringify({ live, count: live.length }));
        return;
    }

    // --- Admin API: Sales data ---
    if (req.method === "GET" && pathname === "/api/analytics/sales") {
        try {
            const sales = JSON.parse(fs.readFileSync(SALES_FILE, "utf8"));
            res.writeHead(200, { "Content-Type": "application/json" });
            res.end(JSON.stringify({ sales }));
        } catch {
            res.writeHead(200, { "Content-Type": "application/json" });
            res.end(JSON.stringify({ sales: [] }));
        }
        return;
    }

    // --- Static files ---
    let filePath = pathname === "/" ? "/index.html" : pathname;
    filePath = path.join(__dirname, filePath);
    if (!filePath.startsWith(__dirname)) { res.writeHead(403); res.end(); return; }
    fs.readFile(filePath, (err, data) => {
        if (err) { res.writeHead(404); res.end("Not Found"); return; }
        res.writeHead(200, { "Content-Type": MIME[path.extname(filePath)] || "application/octet-stream" });
        res.end(data);
    });
}).listen(PORT, "0.0.0.0", () => console.log("MomentoCrypto running on port " + PORT));
