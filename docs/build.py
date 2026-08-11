#!/usr/bin/env python3
"""Build the Swasthya foundation dashboard (docs/index.html).

Reads the nineteen root-level .md documents, renders them into a single
self-contained HTML file with an accordion browser, so the page needs no
server and no external assets. Regenerate after editing any document:

    python docs/build.py

The .md files at the repository root remain the canonical contract; the
generated HTML is a convenience view.
"""

import html as htmlmod
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "docs" / "index.html"

DOCS = [
    ("README.md", "Project front door, honest status, document map"),
    ("ROADMAP.md", "23 gated phases, milestones M0-M5, MVP cut"),
    ("PRODUCT_REQUIREMENTS.md", "Product vision, 24 module groups, phasing"),
    ("MASTER_RULES.md", "Engineering constitution: 40 rule areas, 16 prohibitions"),
    ("ARCHITECTURE.md", "Modular monolith - services; 27 architecture areas"),
    ("TENANCY.md", "Multi-tenancy: context flow, RLS, lifecycle"),
    ("DATABASE.md", "42-entity logical model, RLS strategy, conventions"),
    ("API_CONTRACTS.md", "Envelope, error taxonomy, idempotency, examples"),
    ("DESIGN_SYSTEM.md", "Mobile-first design system, Identity Spine, high-risk actions"),
    ("SECURITY.md", "34 control areas: required / recommended / future"),
    ("TESTING_STRATEGY.md", "Pyramid, critical-workflow suites, CI cadence"),
    ("DEPLOYMENT.md", "Zero-downtime releases, IaC, environment stages"),
    ("DISASTER_RECOVERY.md", "RPO/RTO targets, PITR, drills - no claimed guarantees"),
    ("OBSERVABILITY.md", "Logs/metrics/traces; absolute never-log rule for PHI"),
    ("CLINICAL_SAFETY.md", "Clinician decides, software assists; high-risk rules"),
    ("INTEROPERABILITY.md", "FHIR/HL7/DICOM readiness; honest integration inventory"),
    ("AI_RULES.md", "Five-tier AI classification; no autonomous-action path"),
    ("BILLING.md", "SaaS billing separated from hospital patient billing"),
    ("DEVELOPMENT_LOG.md", "Permanent chronological record; only performed work"),
]

CODE_RE = re.compile(r"`([^`]+)`")


def inline(text):
    """Render inline markdown (code, links, bold, italic) to HTML."""
    codes = []

    def stash(m):
        codes.append(m.group(1))
        return "\x00%d\x00" % (len(codes) - 1)

    text = CODE_RE.sub(stash, text)
    text = htmlmod.escape(text, quote=False)

    def link(m):
        label, url = m.group(1), m.group(2)
        if url.endswith(".md") or url.startswith("#"):
            return label  # local doc links stay plain text; no dead links
        return '<a href="%s">%s</a>' % (htmlmod.escape(url, quote=True), label)

    text = re.sub(r"\[([^\]]+)\]\(([^)]+)\)", link, text)
    text = re.sub(r"\*\*([^*]+)\*\*", r"<strong>\1</strong>", text)
    text = re.sub(r"(?<!\*)\*([^*\n]+)\*(?!\*)", r"<em>\1</em>", text)
    text = re.sub(r"~~([^~]+)~~", r"<del>\1</del>", text)

    def restore(m):
        return "<code>%s</code>" % htmlmod.escape(codes[int(m.group(1))])

    return re.sub(r"\x00(\d+)\x00", restore, text)


def render_list(items, tag):
    """Render (indent, text) items as a nested <ul>/<ol>."""

    def rec(i, indent):
        parts = ["<%s>" % tag]
        while i < len(items) and items[i][0] == indent:
            parts.append("<li>" + inline(items[i][1]))
            i += 1
            if i < len(items) and items[i][0] > indent:
                sub, i = rec(i, items[i][0])
                parts.append(sub)
            parts.append("</li>")
        parts.append("</%s>" % tag)
        return "".join(parts), i

    if not items:
        return ""
    html, _ = rec(0, items[0][0])
    return html


def render_markdown(src):
    lines = src.split("\n")
    out = []
    para = []
    i, n = 0, len(lines)

    def flush_para():
        if para:
            out.append("<p>" + inline(" ".join(para)) + "</p>")
            del para[:]

    while i < n:
        line = lines[i]
        stripped = line.strip()

        if stripped.startswith("```"):
            flush_para()
            lang = stripped[3:].strip()
            i += 1
            code = []
            while i < n and not lines[i].strip().startswith("```"):
                code.append(lines[i])
                i += 1
            i += 1  # skip closing fence
            cls = " class=\"lang-%s\"" % htmlmod.escape(lang, quote=True) if lang else ""
            out.append("<pre><code%s>%s</code></pre>" % (cls, htmlmod.escape("\n".join(code))))
            continue

        if ("|" in line and i + 1 < n and "-" in lines[i + 1]
                and re.match(r"^\s*\|?[\s:\-|]+\|[\s:\-|]*$", lines[i + 1])):
            flush_para()
            header = [c.strip() for c in line.strip().strip("|").split("|")]
            i += 2
            rows = []
            while i < n and "|" in lines[i] and lines[i].strip():
                rows.append([c.strip() for c in lines[i].strip().strip("|").split("|")])
                i += 1
            parts = ["<table><thead><tr>"]
            parts += ["<th>" + inline(c) + "</th>" for c in header]
            parts.append("</tr></thead><tbody>")
            for r in rows:
                parts.append("<tr>" + "".join("<td>" + inline(c) + "</td>" for c in r) + "</tr>")
            parts.append("</tbody></table>")
            out.append("".join(parts))
            continue

        m = re.match(r"^(#{1,6})\s+(.*)$", line)
        if m:
            flush_para()
            level = min(len(m.group(1)) + 1, 6)  # shift down: # -> h2
            out.append("<h%d>%s</h%d>" % (level, inline(m.group(2)), level))
            i += 1
            continue

        if re.match(r"^\s*(---+|\*\*\*+)\s*$", stripped):
            flush_para()
            out.append("<hr>")
            i += 1
            continue

        if stripped.startswith(">"):
            flush_para()
            q = []
            while i < n and lines[i].strip().startswith(">"):
                q.append(lines[i].strip()[1:].strip())
                i += 1
            out.append("<blockquote>" + inline(" ".join(q)) + "</blockquote>")
            continue

        if re.match(r"^\s*[-*]\s+", line):
            flush_para()
            items = []
            while i < n:
                m = re.match(r"^(\s*)[-*]\s+(.*)$", lines[i])
                if m:
                    items.append((len(m.group(1)), m.group(2)))
                    i += 1
                    continue
                if lines[i].strip() == "":
                    k = i
                    while k < n and lines[k].strip() == "":
                        k += 1
                    if k < n and re.match(r"^\s*[-*]\s+", lines[k]):
                        i = k
                        continue
                    break
                if items and re.match(r"^\s+\S", lines[i]):
                    items[-1] = (items[-1][0], items[-1][1] + " " + lines[i].strip())
                    i += 1
                    continue
                break
            out.append(render_list(items, "ul"))
            continue

        if re.match(r"^\s*\d+\.\s+", line):
            flush_para()
            items = []
            while i < n:
                m = re.match(r"^(\s*)(\d+)\.\s+(.*)$", lines[i])
                if m:
                    items.append((len(m.group(1)), m.group(3)))
                    i += 1
                    continue
                if lines[i].strip() == "":
                    k = i
                    while k < n and lines[k].strip() == "":
                        k += 1
                    if k < n and re.match(r"^\s*\d+\.\s+", lines[k]):
                        i = k
                        continue
                    break
                if items and re.match(r"^\s+\S", lines[i]):
                    items[-1] = (items[-1][0], items[-1][1] + " " + lines[i].strip())
                    i += 1
                    continue
                break
            out.append(render_list(items, "ol"))
            continue

        if stripped == "":
            flush_para()
        else:
            para.append(stripped)
        i += 1

    flush_para()
    return "\n".join(out)


def doc_anchor(filename):
    return "doc-%s" % filename[:-3]


def build():
    rendered = {}
    missing = []
    for fname, _ in DOCS:
        p = ROOT / fname
        if not p.exists():
            missing.append(fname)
            continue
        rendered[fname] = render_markdown(p.read_text(encoding="utf-8"))
    if missing:
        raise SystemExit("Missing documents: %s" % ", ".join(missing))

    index_rows = []
    details = []
    for num, (fname, purpose) in enumerate(DOCS, 1):
        anchor = doc_anchor(fname)
        index_rows.append(
            '<tr><td>%d</td><td><a class="doclink" href="#%s">%s</a></td>'
            '<td>%s</td><td><span class="pill done">done</span></td></tr>'
            % (num, anchor, fname, purpose)
        )
        details.append(
            '<details class="doc" id="%s">'
            '<summary><span class="docname">%s</span>'
            '<span class="purpose">%s</span></summary>'
            '<div class="doc-body">%s</div></details>'
            % (anchor, fname, purpose, rendered[fname])
        )

    gov_links = {
        "MASTER_RULES.md": "the engineering constitution",
        "DEVELOPMENT_LOG.md": "the permanent engineering record",
    }
    gov_items = [
        '<li><b>The constitution:</b> <a class="doclink" href="#%s">%s</a> — everything lands via reviewed PRs; definition of done gates every merge.</li>'
        % (doc_anchor(f), f)
        for f in ("MASTER_RULES.md",)
    ] + [
        '<li><b>The log is permanent:</b> <a class="doclink" href="#%s">%s</a> records every meaningful change — only work actually performed.</li>'
        % (doc_anchor(f), f)
        for f in ("DEVELOPMENT_LOG.md",)
    ]
    # keep other governance bullets as static text below

    page = TEMPLATE
    page = page.replace("__DOC_TABLE__", "\n      ".join(index_rows))
    page = page.replace("__GOV_CONSTITUTION__", gov_items[0])
    page = page.replace("__GOV_LOG__", gov_items[1])
    page = page.replace("__DOC_DETAILS__", "\n  ".join(details))

    OUT.write_text(page, encoding="utf-8")
    print("Wrote %s (%d KB)" % (OUT, OUT.stat().st_size // 1024))


TEMPLATE = r"""<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Swasthya - Foundation Documentation</title>
<style>
  :root {
    --paper: #ffffff;
    --mist: #f3f6f7;
    --line: #d9e0e4;
    --ink: #1b2a38;
    --ink-soft: #3d4c5c;
    --slate: #64748b;
    --teal-700: #0f766e;
    --teal-800: #115e59;
    --teal-900: #134e4a;
    --danger: #b42318;
    --warning: #b45309;
    --success: #1f7a3d;
  }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: "Public Sans", "Segoe UI", system-ui, -apple-system, sans-serif;
    background: var(--mist);
    color: var(--ink);
    line-height: 1.55;
    padding: 0 0 4rem;
  }
  header.hero {
    background: var(--teal-900);
    color: #fff;
    padding: 3rem 1.5rem 2.5rem;
  }
  .hero-inner { max-width: 960px; margin: 0 auto; }
  .hero .kicker {
    font-size: 0.8rem; letter-spacing: 0.14em; text-transform: uppercase;
    color: #99f6e4; font-weight: 600; margin-bottom: 0.6rem;
  }
  .hero h1 { font-size: clamp(1.7rem, 4vw, 2.4rem); font-weight: 700; margin-bottom: 0.5rem; }
  .hero p.tagline { font-size: 1.05rem; color: #cce7e2; max-width: 46em; }
  .status-banner {
    max-width: 960px; margin: 1.2rem auto 0; padding: 0.75rem 1.25rem;
    background: #fef3f2; border: 1px solid #fecdca; border-radius: 6px;
    color: var(--danger); font-weight: 600; font-size: 0.95rem;
  }
  main { max-width: 960px; margin: 0 auto; padding: 1.5rem; }
  section { background: var(--paper); border: 1px solid var(--line); border-radius: 8px; padding: 1.5rem 1.75rem; margin-bottom: 1.25rem; }
  h2 { font-size: 1.15rem; color: var(--teal-900); margin-bottom: 0.9rem; font-weight: 700; }
  h3 { font-size: 0.95rem; color: var(--ink-soft); margin: 1rem 0 0.5rem; font-weight: 650; }
  p, li { font-size: 0.95rem; }
  ul { padding-left: 1.2rem; }
  li { margin-bottom: 0.3rem; }
  table { width: 100%; border-collapse: collapse; font-size: 0.9rem; margin: 0.5rem 0; }
  th, td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--line); vertical-align: top; }
  th { color: var(--ink-soft); font-weight: 650; font-size: 0.82rem; text-transform: uppercase; letter-spacing: 0.05em; }
  td a { color: var(--teal-700); text-decoration: none; font-weight: 600; }
  td a:hover { text-decoration: underline; }
  code { font-family: "IBM Plex Mono", ui-monospace, monospace; font-size: 0.82em; background: var(--mist); padding: 0.1em 0.35em; border-radius: 4px; }
  .pill { display: inline-block; padding: 0.12rem 0.55rem; border-radius: 999px; font-size: 0.74rem; font-weight: 650; letter-spacing: 0.03em; }
  .pill.design { background: #e0f2fe; color: #075985; }
  .pill.plan { background: #fef3c7; color: var(--warning); }
  .pill.future { background: #f1f5f9; color: var(--slate); }
  .pill.done { background: #dcfce7; color: var(--success); }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
  .muted { color: var(--slate); font-size: 0.88rem; }
  details.doc { background: var(--paper); border: 1px solid var(--line); border-radius: 8px; margin-bottom: 1rem; overflow: hidden; }
  details.doc > summary {
    cursor: pointer; padding: 0.9rem 1.1rem; list-style: none;
    display: flex; align-items: baseline; gap: 0.6rem; flex-wrap: wrap;
    color: var(--teal-900); font-weight: 650;
  }
  details.doc > summary::-webkit-details-marker { display: none; }
  details.doc > summary::before { content: "\25B8 "; color: var(--teal-700); font-weight: 700; }
  details.doc[open] > summary::before { content: "\25BE "; }
  details.doc > summary .docname { font-family: "IBM Plex Mono", ui-monospace, monospace; font-size: 0.92rem; }
  details.doc > summary .purpose { color: var(--slate); font-weight: 400; font-size: 0.85rem; }
  .doc-body { padding: 0.25rem 1.5rem 1.25rem; border-top: 1px solid var(--line); }
  .doc-body h2 { font-size: 1.02rem; margin-top: 1.1rem; }
  .doc-body h3 { font-size: 0.92rem; margin-top: 0.9rem; }
  .doc-body h4, .doc-body h5, .doc-body h6 { font-size: 0.88rem; margin-top: 0.8rem; color: var(--ink-soft); }
  .doc-body p, .doc-body li { font-size: 0.9rem; }
  .doc-body ul, .doc-body ol { padding-left: 1.3rem; }
  .doc-body li { margin-bottom: 0.2rem; }
  .doc-body pre {
    background: #0f172a; color: #e2e8f0; padding: 0.9rem 1rem; border-radius: 6px;
    overflow-x: auto; font-size: 0.8rem; line-height: 1.5; margin: 0.6rem 0;
    font-family: "IBM Plex Mono", ui-monospace, monospace;
  }
  .doc-body code { background: var(--mist); }
  .doc-body pre code { background: transparent; padding: 0; color: inherit; font-size: 1em; }
  .doc-body blockquote {
    border-left: 3px solid var(--teal-700); padding: 0.25rem 0 0.25rem 1rem;
    margin: 0.6rem 0; color: var(--ink-soft); background: var(--mist);
    border-radius: 0 4px 4px 0;
  }
  .doc-body hr { border: none; border-top: 1px solid var(--line); margin: 1rem 0; }
  .doc-body table { display: block; overflow-x: auto; white-space: nowrap; font-size: 0.84rem; }
  .doc-body li > ul, .doc-body li > ol { margin-top: 0.15rem; }
  @media (max-width: 720px) {
    .grid2 { grid-template-columns: 1fr; }
    section { padding: 1.1rem; }
    .doc-body { padding: 0.25rem 1rem 1rem; }
  }
  footer { max-width: 960px; margin: 0 auto; padding: 0 1.5rem; color: var(--slate); font-size: 0.85rem; }
</style>
</head>
<body>

<header class="hero">
  <div class="hero-inner">
    <div class="kicker">Swasthya &middot; Foundation Dashboard</div>
    <h1>A production HMS SaaS &mdash; foundation built, clinical modules next.</h1>
    <p class="tagline">Swasthya is a production-grade, nationally scalable, multi-tenant Hospital Management System for Nepal. This dashboard presents the engineering contract that governs its construction &mdash; nineteen documents spanning product, architecture, tenancy, security, clinical safety, and delivery &mdash; alongside the implemented backend foundation (Phases 2&ndash;4).</p>
  </div>
</header>

<div class="status-banner">Honest status: Phases 0&ndash;4 substantially complete &mdash; the design contract exists, and Phases 2&ndash;4 are implemented and tested in <code>backend/</code>. Nothing on this page is a working clinical feature.</div>

<main>

<section>
  <h2>What exists &amp; what does not</h2>
  <div class="grid2">
    <div>
      <h3>Exists</h3>
      <ul>
        <li>Nineteen design and governance documents</li>
        <li>Laravel API backend (Phases 2&ndash;4): API foundation, PostgreSQL schema, auth (access + rotating refresh tokens), RBAC, tenant/facility context, authorization gates, append-only hash-chained audit</li>
        <li>Hospital administration catalogs: departments, locations, wards/rooms/beds, staff (license encrypted at rest), services, facility configuration</li>
        <li>138 tests / 796 assertions green against real PostgreSQL</li>
        <li>A staged, gated 23-phase roadmap; a development log recording only real work</li>
      </ul>
    </div>
    <div>
      <h3>Does not exist</h3>
      <ul>
        <li>Any clinical, financial, or operational business module (patient master onward)</li>
        <li>A git repository (not yet initialized)</li>
        <li>Database-level RLS, MFA TOTP flow, CI, Docker, any deployment</li>
        <li>Any claimed feature, integration, or compliance status</li>
      </ul>
    </div>
  </div>
</section>

<section>
  <h2>Document index</h2>
  <p class="muted">All nineteen documents live at the repository root; click any name to open its full text below. Reading order for a new engineer: 1 &rarr; 2 &rarr; 3 &rarr; 4 &rarr; 5 &rarr; 6 &rarr; 7 &rarr; 8 &rarr; 9 &rarr; 10.</p>
  <table>
    <thead><tr><th>#</th><th>Document</th><th>Purpose</th><th>Status</th></tr></thead>
    <tbody>
      __DOC_TABLE__
    </tbody>
  </table>
</section>

<section>
  <h2>Technology stack &mdash; decided by design, pending ADR-001</h2>
  <table>
    <thead><tr><th>Layer</th><th>Choice</th><th>Status</th></tr></thead>
    <tbody>
      <tr><td>Frontend</td><td>React + TypeScript (single SPA)</td><td><span class="pill design">designed &mdash; not built</span></td></tr>
      <tr><td>Backend</td><td>Laravel (PHP) &mdash; the sole business API</td><td><span class="pill done">implemented &mdash; Phases 2&ndash;4</span></td></tr>
      <tr><td>Database</td><td>PostgreSQL with RLS tenancy</td><td><span class="pill done">implemented &mdash; dev/test (16.4); RLS pending</span></td></tr>
      <tr><td>Cache / queues / realtime</td><td>Redis</td><td><span class="pill design">designed &mdash; not built</span></td></tr>
      <tr><td>Files</td><td>S3-compatible object storage</td><td><span class="pill design">designed &mdash; not built</span></td></tr>
      <tr><td>AI / CDSS (future)</td><td>Python (FastAPI) &mdash; inference only</td><td><span class="pill future">future</span></td></tr>
      <tr><td>Interoperability (future)</td><td>FHIR / HL7 / DICOM readiness layers</td><td><span class="pill future">future</span></td></tr>
    </tbody>
  </table>
  <p class="muted">Deliberately not used: Angular, CodeIgniter, Node.js for business logic &mdash; one responsibility per technology.</p>
</section>

<section>
  <h2>Roadmap &mdash; current position</h2>
  <p><b>Position:</b> Phases 0&ndash;4 substantially complete &mdash; Phases 2&ndash;4 are implemented in <code>backend/</code>. Next: <b>Phase 5 &mdash; Patient Master</b> (patient registration, MRN, demographics, identifiers, contacts, consent, timeline), with M0 items (ADR-001 ratification, repository initialization) still open.</p>
  <table>
    <thead><tr><th>Milestone</th><th>Meaning</th></tr></thead>
    <tbody>
      <tr><td><b>M0</b> &mdash; Foundation ratified</td><td>ADR-001 ratified; repository initialized; the nineteen documents are the contract</td></tr>
      <tr><td><b>M1</b> &mdash; Vertical slice</td><td>Tenant + auth + RBAC + patient registration + booking, proven with red-line tests</td></tr>
      <tr><td><b>M2</b> &mdash; MVP / pilot-ready</td><td>A real hospital runs a full OPD day: schedule &rarr; book &rarr; queue &rarr; encounter &rarr; prescribe &rarr; dispense &rarr; bill &rarr; settle</td></tr>
      <tr><td><b>M3 / M4</b> &mdash; Phase 2/3 scope</td><td>Inpatient, emergency, diagnostics, insurance, HR; then OT/ICU/blood, telehealth, RPM, CDSS/AI, interoperability</td></tr>
      <tr><td><b>M5</b> &mdash; National scale</td><td>Measured capacity, resilience drills, localization, verified compliance &mdash; a standing commitment</td></tr>
    </tbody>
  </table>
  <p class="muted">The platform is built in 23 gated phases, never all at once. MVP is a horizontal cut across phases, not a single phase.</p>
</section>

<section>
  <h2>Governance</h2>
  <ul>
    __GOV_CONSTITUTION__
    <li><b>Decisions change through ADRs</b>, never by accretion &mdash; including the technology ownership table.</li>
    <li><b>No fabricated anything:</b> no fake data, integrations, analytics, or demo functionality masquerading as production.</li>
    <li><b>Compliance:</b> Swasthya claims no regulatory compliance or certification &mdash; none until verified by qualified assessment with documented evidence.</li>
    __GOV_LOG__
  </ul>
</section>

<section>
  <h2>Document browser &mdash; full text</h2>
  <p class="muted">Every foundation document, rendered from the root .md sources. The markdown files remain canonical; this page is regenerated with <code>python docs/build.py</code>.</p>
  __DOC_DETAILS__
</section>

</main>

<footer>
  Swasthya foundation dashboard &middot; generated 2026-08-11 &middot; repository: <code>Swasthya Nepal</code> &middot; this page is a presentation of the documentation, not application code.
</footer>

<script>
  document.querySelectorAll("a.doclink").forEach(function (a) {
    a.addEventListener("click", function (e) {
      e.preventDefault();
      var det = document.querySelector(a.getAttribute("href"));
      if (det) {
        det.open = true;
        det.scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  });
</script>

</body>
</html>
"""


if __name__ == "__main__":
    build()
