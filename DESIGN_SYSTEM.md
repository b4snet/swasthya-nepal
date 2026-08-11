# DESIGN_SYSTEM.md — Swasthya Design System

> **Status:** Working baseline · **Owner:** Design (ratified with the Principal Architect)
> **Version:** 1.0
> **Document chain:** `PRODUCT_REQUIREMENTS.md` (what) → `MASTER_RULES.md` (how) → `ARCHITECTURE.md` (shape) → `DATABASE.md` (data) → **this document** (how it looks, feels, and behaves).
>
> **Scope:** the visual and interaction system for every Swasthya surface — patient portal and staff workspace, one SPA. This document specifies design rules; it does not design screens (screen designs come per feature, against these rules).
>
> **Audience:** the people the system must serve at speed: **doctors, nurses, receptionists, pharmacists, laboratory staff, radiology staff, accountants, hospital administrators, executives — and patients** where applicable. Every rule below exists to make their work faster, safer, and less mentally taxing.

---

## 0. Design Intent

**Swasthya is a clinical instrument, not a marketing site.** It should feel like a well-made stethoscope: precise, quiet, dependable — no decoration that doesn't do a job. The personality comes from two deliberate choices:

1. **Color temperature.** A deep, blue-leaning teal (not the generic medical blue, not a sterile white-on-blue corporate look) on a cool, clean paper. Calm in bright OPD light, legible on low-cost phone screens, and unmistakably "care" without being childish.
2. **Typography clarity.** A humanist body face with **tabular numerals as a first-class feature** (clinical numbers must line up), a distinctive rounded-grotesque display face used with restraint, and **Devanagari support built in** — the interface will be used in Nepali, on Nepali keyboards, on phones that render Nepali text every day. Localization is a design constraint here, not an afterthought.

**The signature element — the Identity Spine.** One element is present in every clinical context and is remembered because it saves lives: a **persistent patient identity bar** pinned to the top of clinical screens, carrying name, MRN, age/sex, and an always-visible allergy alert chip. It never scrolls away. Every clinical screen the patient is "in" shows the same bar, so the person you are about to prescribe for, dispense to, or transfuse is confirmed every single time. This is the design system's one memorable move, and it is a safety device, not decoration (see Section 33 and the High-Risk Actions rules).

---

## 1. Design Principles

The seven priorities, in order of weight:

1. **Clarity over cleverness.** Every screen answers: *who is this about, what state is it in, what is the next action?* If a screen cannot answer all three in three seconds, it is not done.
2. **Speed is safety.** In the ER, at the counter, during the morning OPD rush — the interface must not add steps. Defaults are pre-filled, the most likely next action is the primary action, and the same workflow is always the same number of taps.
3. **Accessibility is not a feature; it is the baseline.** WCAG 2.1 AA, visible focus, keyboard-complete, screen-reader-complete, colorblind-safe (never color alone). Section 30.
4. **Low cognitive load.** One primary action per screen. No competing emphasis. Information is grouped, labeled, and ordered by what the worker needs next — not by the database schema.
5. **Safe workflows by construction.** High-risk actions are hard to trigger accidentally and impossible to trigger silently (High-Risk Actions rules). The identity of the patient and the operator is confirmed at the moment it matters.
6. **Mobile-first, literally.** Every workflow is designed at phone width first. Desktop is the *expanded* version of the mobile experience, never the other way around. The busiest users (nurses, front desk, doctors on rounds) live on phones and tablets.
7. **Consistent interaction.** The same action has the same name, the same color, the same position everywhere. A "Dispense" button is always the same button. Users learn the system once; every screen reinforces that learning.

---

## 2. Information Hierarchy

- **One primary question per screen.** Screen structure, in order: **identity context → state/status → content → action.**
- **Visual weight ladder:** color (only for meaning) > size > weight > position > spacing. Two elements at the same weight compete; one must give.
- **The primary action is visually unique** — one filled button per screen (Section 13). Secondary actions are quieter by a full step.
- **Clinical data outranks interface furniture.** Numbers, names, and statuses get the strongest typographic treatment; navigation, chrome, and decoration get the weakest.
- **Progressive disclosure:** hide advanced controls behind explicit "More" affordances; never hide safety information (allergies, critical values) behind anything.
- **Reading order = work order:** the eye path on every screen follows the actual workflow, left-to-right (LTR) and top-to-bottom by default, RTL-ready via logical properties.

---

## 3. Layout System

- **Base unit: 4 px.** All spacing, sizing, and radii derive from the 4 px grid (Section 9).
- **Grid:** 4 columns on phones, 8 on tablets, 12 on desktop. Gutter: 16 px mobile / 24 px tablet+.
- **Content width:** transactional forms cap at **640 px** centered; dense tables and dashboards use full width; text-heavy reading surfaces cap at 720 px (line length).
- **Page scaffold:** a persistent header (context + global actions), the Identity Spine when a patient is in context, the content region, and the navigation appropriate to the viewport (Sections 5–7).
- **Touch-row height:** 48 px for any tappable row on touch devices (Section 32); 40 px in desktop dense mode.
- **No horizontal scrolling on mobile by design:** anything that would scroll horizontally is restructured into cards or a drill-down (Section 17).
- **Safe areas:** respect notch/home-indicator insets on mobile; content never sits under them.

---

## 4. Responsive Breakpoints

Mobile-first: **base styles ARE the phone experience.** Breakpoints add structure; nothing designed at mobile is removed at desktop.

| Token | Min width | Primary devices | What changes |
|---|---|---|---|
| `base` | 0 (default) | Phones < 480 px | Single column, bottom navigation, bottom-sheet containers, stacked cards |
| `sm` | 480 px | Large phones | Slightly wider forms; two-column stat rows |
| `md` | 768 px | Tablets portrait | Side rail appears (Section 7); 8-col grid; drawers instead of bottom sheets for filters |
| `lg` | 1024 px | Tablets landscape, small laptops | Full sidebar (Section 6); 12-col grid; tables instead of cards where dense |
| `xl` | 1280 px | Desktops | Multi-pane layouts, dense mode available |
| `xxl` | 1536 px | Wide desktops | Maximum information density; dashboards |

Rules:
- Test at every breakpoint boundary with real content, not lorem ipsum.
- The breakpoint change must never move the primary action more than one position.
- Tables degrade to cards at `md` and below (Section 17) — never horizontal scroll.
- Device orientation: portrait is the design target for clinical mobile use; landscape must not break (overflow-safe), but is not the design target.

---

## 5. Mobile Navigation

- **Bottom navigation bar** (48–56 px, 3–5 destinations, always visible except during full-screen flows): the five most-used destinations per role — e.g., Home (queue/agenda), Search (patients), New (quick actions, center, prominent), Inbox (notifications), More.
- **The center "New" button is the creation hub** (register patient, book appointment, start encounter, dispense) — the most-used creation action is one thumb-tap from anywhere (Section 35).
- **One level deep from the bottom bar, no more:** deeper navigation uses in-screen back headers, not stacked tabs.
- **Back behavior:** Android back / iOS swipe-back are supported natively; back always returns to the previous *step*, never logs out and never loses unsaved input without warning (Section 27).
- **Quick actions in-context:** a bottom action bar (not a FAB) carries the current screen's primary actions in the thumb zone (Section 35).
- **No hamburger on mobile** as the primary nav mechanism: the five destinations are always visible; everything else lives behind "More" or in-context headers.

---

## 6. Desktop Navigation

- **Left sidebar** (collapsible to icon rail, 240 px / 64 px): primary modules by role — Home, Patients, Appointments, Clinical (OPD/IPD/ER), Pharmacy, Laboratory, Radiology, Billing, Inventory, Reports, Admin.
- **Sidebar = navigation, header = context.** The top header carries: tenant/facility switcher, global search, notifications, user menu. Global actions never hide in the sidebar.
- **Role-gated items:** a user only sees modules their role entitles them to (the API enforces; the UI reflects — `MASTER_RULES.md` §8.4).
- **Breadcrumbs** on deep screens (3 levels max) with the current level bolded.
- **Keyboard:** full sidebar navigation by arrow keys, shortcuts for the top five destinations (Section 31).
- **Dense mode toggle** in the user menu for power users (Section 34) — desktop only.

---

## 7. Tablet Navigation

- **Rail navigation (md–lg):** icon rail on the left (56 px) with tooltips, no text labels until `lg` — saves width, keeps destinations visible.
- **Bottom bar hides, rail shows** at `md`; the transition keeps the same five primary destinations in the same relative order (principles 6–7: mobile and desktop are one system).
- **Split-pane opportunity:** tablets in landscape can show list + detail (Section 34 pattern) — but the *default* remains single-pane until the user resizes; nothing on tablet is a mini-desktop by default.
- **Touch remains the primary input on tablets:** all touch rules (Sections 32, 35) still apply at `md`/`lg` when the device is touch.

---

## 8. Typography

- **Body — humanist sans** (preferred: *Public Sans*, fallback system stack): clear, neutral, built for dense reading. Size 16/24 on mobile, 15/22 on desktop (density), never below 14 for body text.
- **Display — rounded grotesque** (preferred: *Manrope*): used with restraint for screen titles, page headers, and large numbers — the system's quiet personality. Not used for body text, not used for clinical values.
- **Mono — *IBM Plex Mono*:** for identifiers only — MRNs, accession numbers, order numbers, batch numbers, claim numbers. Identifiers are read aloud, transcribed, and compared; mono prevents digit confusion.
- **Devanagari support is mandatory:** the Latin faces above must fall back to *Noto Sans Devanagari* (and Nepali system fonts) at every weight used; line-height for Devanagari text is validated visually (diacritics and conjuncts need headroom). The type scale below must be re-checked in Nepali before any Nepali release.
- **Tabular numerals:** `font-variant-numeric: tabular-nums` on all numeric columns, prices, dosages, and vitals — clinical numbers line up, are scannable, and don't jump as they change.
- **Type scale (rem):**

| Token | Size/Line | Weight | Use |
|---|---|---|---|
| `display` | 34/40 | 700 | Page-level titles — sparing |
| `title` | 26/32 | 700 | Screen titles |
| `h2` | 22/28 | 650 | Section headers |
| `h3` | 19/26 | 600 | Card headers, group headers |
| `body` | 16/24 | 400 | Default (mobile) |
| `body-desktop` | 15/22 | 400 | Default (desktop ≥ `xl`) |
| `label` | 14/20 | 600 | Input labels, table headers, captions |
| `caption` | 12/16 | 400 | Non-essential metadata only |
| `data` | 16/24 | 500 mono | Clinical values, identifiers |

- **Case:** sentence case everywhere; ALL CAPS only for critical-value labels (Section 33), where it functions as a warning signal.
- **Weight discipline:** never more than two weights visible on a screen; emphasis is earned.

---

## 9. Spacing

- **Scale (4 px grid):** `xs` 4 · `sm` 8 · `md` 12 · `lg` 16 · `xl` 24 · `2xl` 32 · `3xl` 48 · `4xl` 64.
- **Defaults:** screen padding `lg` (16) mobile / `xl` (24) desktop; card padding `lg`; section gap `xl`; form field gap `lg`; touch-row internal padding to reach 48 px height.
- **Spacing encodes grouping:** related items sit `sm–md` apart; groups sit `xl` apart; screens breathe at `3xl`. If two things are close, they belong together.
- **Radii:** `sm` 4 (inputs, chips) · `md` 6 (buttons, cards) · `lg` 10 (sheets, dialogs) · `full` (pills, badges). No giant decorative radii; this is an instrument, not a toy.
- **Elevation:** `shadow-sm` (cards on paper), `shadow-md` (popovers, dropdowns), `shadow-lg` (modals, bottom sheets). Elevation is a signal of layering, used sparingly.

---

## 10. Color System

A cool, paper-based clinical palette with a deep teal as the brand and action color. Values are the **working baseline** and are validated in visual QA on real devices (bright OPD light, night-ward dim light) before release; the *relationships* (contrast targets) are the contract.

**Neutrals (cool, not cream):**

| Token | Value | Use |
|---|---|---|
| `paper` | `#FFFFFF` | Primary background |
| `mist` | `#F3F6F7` | Section/surface background, table stripes |
| `line` | `#D9E0E4` | Borders, dividers, table rules |
| `slate-500` | `#64748B` | Muted/disabled text (AA on paper only as captions) |
| `ink` | `#1B2A38` | Primary text — near-black, cool |
| `ink-soft` | `#3D4C5C` | Secondary text |

**Brand & action:**

| Token | Value | Use |
|---|---|---|
| `teal-600` | `#0D9488` | Hover states, icon accents |
| `teal-700` | `#0F766E` | Primary buttons, active states, links (white text on it ≥ AA for large/bold; use 800 for small text) |
| `teal-800` | `#115E59` | Small text on white, pressed states, Identity Spine accents |
| `teal-900` | `#134E4A` | Deep accents, header text on light teal |

**Usage rules:**

- **Color always carries a second signal** — never meaning by color alone (Section 12).
- Text on `paper` must meet WCAG AA (4.5:1 normal, 3:1 large); the token set above is chosen to meet it; any new token must be contrast-checked before it enters the system.
- Never use a *different* color to say the same thing (e.g., two different blues for "info") — semantic colors are a closed set (Section 11).

---

## 11. Semantic Colors

A closed set — every colored element in the product uses one of these, in one of its documented roles:

| Token | Value | Role |
|---|---|---|
| `semantic-success` | `#1F7A3D` | Confirmation, saved, paid, verified, available |
| `semantic-info` | `#175CD3` | Information, new results, needs-attention (non-critical) |
| `semantic-warning` | `#B45309` | Warnings, expiring stock, overdue, low stock |
| `semantic-danger` | `#B42318` | Errors, destructive actions, critical values, allergy alerts |
| `semantic-neutral` | `#475569` | Neutral status (scheduled, pending) |

Rules:

- Each semantic color has a **surface tint** (10–15 % of the base) for chips/banners — e.g., danger-tint `#FEF3F2` with danger text — so status chips read as chips, not as blocks of raw color.
- **Status is never color alone:** every status shows a text label; where space allows, an icon or shape signal accompanies color (colorblind-safe, Section 30).
- Destructive and critical share the danger family but are *visually different patterns*: destructive = outlined/filled red button; critical = red + icon + "CRITICAL" label (Section 33). They are not the same thing.

---

## 12. Status Colors

Status presentation is a **system of label + tint + optional icon**, per entity family:

| Status family | Pattern |
|---|---|
| Appointment | booked (neutral), checked-in (info), in-consultation (info, bold), completed (success), cancelled/no-show (neutral, struck or muted) |
| Order (lab/rad) | ordered (neutral), in-progress (info), stat (warning + "STAT" label), completed (success), critical result (danger + icon) |
| Stock/batch | available (success), low (warning), expiring (warning + label), expired (danger), quarantined (danger), depleted (neutral) |
| Financial | draft (neutral), issued (info), partial (warning), paid (success), voided (neutral struck) |
| Admission/bed | available (success), occupied (info), reserved (warning), cleaning (neutral), out-of-service (danger) |
| Verification (lab) | entered (neutral), verified (success), corrected (warning + reason) |

Rules:

- The **label always prints**; the chip color is redundant support, never the only signal.
- Status text uses the semantic token at AA contrast on its tint.
- Status transitions are the same visual language everywhere: a status that changes *state* shows the new chip plus, where it matters, a "changed by X at time" line (the audit is one tap away).
- **No animated color pulses** (seizure risk, distraction): critical states are static + loud, not flashing (Section 33).

---

## 13. Buttons

- **Hierarchy (one of each role per screen max):**
  - **Primary** — filled `teal-700`, white text, weight 600. The one main action. Never more than one per viewport.
  - **Secondary** — outlined `line` border, `ink` text. The next-most-likely action.
  - **Tertiary/ghost** — text-only. Navigation-like actions, "Cancel".
  - **Destructive** — filled `semantic-danger` *or* danger-outlined for lighter severity. Only for destructive actions (High-Risk rules), never for navigation.
- **Sizes:** `l` 48 px (touch default), `m` 40 px (desktop default), `s` 32 px (table cell, desktop dense only). All sizes keep a ≥ 24 px effective hit area via padding.
- **States:** default, hover (+5 % darken), pressed (−5 %), **busy** (spinner replaces label, controls disabled for the operation duration), disabled (only when the action genuinely cannot run — with the reason shown as helper text; never disabled "while we decide").
- **Labels are verbs, specific:** "Save changes", "Dispense", "Verify results", "Check in". Same verb everywhere for the same action (principle 7). Never "Submit", never "OK".
- **Icon + label** for actions whose meaning needs reinforcement (Dispense, Void, Discharge); icon-only buttons are allowed for table-row actions but must carry accessible names and ≥ 44 px hit area on touch.

---

## 14. Inputs

- **Persistent labels above inputs** (16 px height, weight 600) — floating labels are rejected: they fail at speed, with autofill, and with screen readers.
- **Every input has an explicit label; placeholder is example, never the label.** Placeholder text in `slate-500` at caption weight, used to show format ("YYYY-MM-DD"), not to explain.
- **Mobile input modes are set from the data type:** `inputmode="numeric"` for MRN/phone/amount (numeric keypad), `type="tel"`, `type="date"` (native picker), `type="password"` with reveal toggle (reveal never in clinical settings without reason).
- **Autofill/autocomplete attributes are configured per field** (name, tel, email, postal-code) so patients and staff don't retype.
- **Field states:** default (paper, `line` border, 1 px, radius `sm`), focus (2 px `teal-800` ring + white halo — visible on every surface), error (danger border + icon + inline message), disabled (mist fill, slate text), read-only (mist fill, ink text — used for frozen clinical/financial values, which are never editable).
- **Error messages** are specific and actionable (Section 25): "Enter a date after 2000" — never "Invalid".
- **Character counters** only where input length is a real constraint (identifiers), never for name fields.

---

## 15. Selects

- **Native selects are the default on touch** — the OS picker is faster and more accessible than a custom dropdown on mobile.
- **Desktop:** custom combobox (type-ahead) for long lists (medicines, tests, departments); plain select for short lists (≤ 7 items render as radio group or chips instead — selects are the wrong control for tiny option sets).
- **No option-free states:** a select that can be empty shows a first "— Select —" option and validates on submit.
- **Searchable selects filter as you type** and show the highlighted match; results are grouped for long catalogs.
- **Clinical catalogs** (medicines, tests, diagnoses) always use searchable selects with code + name shown, and are never free-text fields (typing a medicine name that doesn't exist in the formulary is how wrong drugs get prescribed — High-Risk rules).

---

## 16. Search

**Patient search is the most safety-critical interaction in the product — it is the front door to the wrong-patient problem.**

- **Global search** lives in the header on every viewport (tap-to-focus on mobile): searches patients, staff, and entities by name, MRN, and identifier; results grouped by type.
- **Patient search behavior:** debounced (≥ 300 ms) server-side search over `pg_trgm` (name variants, phonetic) + exact MRN; results show name, MRN, age/sex, DOB, and a **distinct identity confirmation line** ("Confirm identity before opening") — opening a record is a deliberate second step, not a side effect of typing.
- **Scan support:** the patient search input accepts barcode scans (MRN wristbands, ID cards) — the scan field is always focusable (Section 35 quick actions).
- **Search results respect the Identity Spine:** after selection, the patient bar renders and persists; switching patients in clinical context requires going back to search, never an inline "change patient" shortcut on a clinical screen.
- **Empty/ambiguous results** show guidance: "No exact match — check spelling or scan the wristband. If the patient may be new, register them" (Section 23) — never "No results." with a dead end.
- **Keyboard:** type-to-search, arrows to move, Enter to open, Escape to clear (Section 31).

---

## 17. Tables

- **Desktop (`lg`+):** real tables. Sticky header; sortable columns with visible indicators; column filters where the column count demands; row actions on hover (but always reachable by keyboard).
- **Mobile (`< lg`):** tables become **cards** — each row is a card with the primary identifier prominent, key status as a chip, and the row's primary action as the card's tap target. **No horizontal scrolling by design.**
- **Numbers right-align, tabular numerals** (Section 8); text left-align; statuses center. Currency columns show symbol + minor-unit formatting per tenant config.
- **Dense mode** (Section 34) reduces row height to 40 px and shows more columns at `xl+`.
- **Selection + bulk actions** appear only with an explicit "Select" mode; bulk actions are confirmed as a batch (Section 27) and never include irreversible clinical actions without per-row review.
- **Long tables paginate or virtualize** (never "load all"); the current page, count, and filters are always visible.
- **Zero-data rows** use the empty-state pattern (Section 23), never a bare "—".

---

## 18. Cards

- **Card anatomy:** optional header (title + primary action of the card), content, optional footer (secondary actions, metadata). One card = one concept.
- **Tap targets:** a card that opens something is fully tappable with a visible chevron/affordance; a card with an internal action has the action as the primary target, not the whole card.
- **Clinical cards** (patient summary, prescription, result) follow the Identity Spine and clinical-data rules (Section 33): identifiers in mono, values with units, statuses as chips.
- **Card density:** comfortable spacing by default; dense mode compresses. Cards never nest more than one level deep.
- **Selection cards** (e.g., choose medicine, choose bed) show radio/checkbox semantics with a check affordance, not color change alone.

---

## 19. Modals

- **Modals are for decisions and short confirmations, not for reading or long forms** — anything longer than a confirmation is a page or a drawer (Section 20).
- **Sizes:** `sm` 400 px (confirmations), `md` 560 px (short forms), `lg` 720 px (medium forms) — full-width minus safe margins on mobile (Section 21 for mobile behavior).
- **Anatomy:** title (what this is), body (what happens), actions (primary + cancel, destructive-first where applicable), optional close (×) — close never bypasses a destructive confirmation.
- **Focus:** focus moves into the modal on open; focus is trapped; Escape closes only when nothing unsaved (unsaved input → confirmation, Section 27); focus returns to the trigger on close.
- **No stacked modals.** One modal at a time; a modal that needs another modal is a design failure.
- **Backdrop:** click-outside closes only for non-destructive, non-unsaved modals; destructive modals require the explicit action.
- **Scroll containment:** modal body scrolls, header and actions stay pinned; no scrolling behind the modal.

---

## 20. Drawers

- **Drawers are for supplementary context: filters, patient details, notifications history, entity details.** The main screen stays visible and interactive-adjacent (page state preserved).
- **Desktop/tablet:** right-side drawer, 360 px (`lg`), 400 px (`xl`), full height, scrim optional (light scrim recommended to preserve context).
- **Mobile:** drawer content becomes a **bottom sheet** (Section 21) — the same component family, different placement.
- **Drawers never contain the primary action of the underlying workflow** — the drawer is support; the main action stays on the main surface.
- **Filters in drawers apply live** with a visible "N filters applied" summary and a one-tap "Clear".
- **Close behavior:** Escape, scrim click (non-destructive), back gesture on mobile; unsaved filter edits confirm before close.

---

## 21. Bottom Sheets

- **The mobile container for actions and short forms**: the sheet rises from the bottom, full-width, rounded top corners (`lg`), drag handle.
- **Snap points:** ~50 % (peek) and ~92 % (expanded); a full-screen variant (100 %) for short forms with a close affordance. Snap state is remembered within a session.
- **Anatomy:** handle → title → content (scrollable) → **actions pinned above the safe area**, primary nearest the thumb.
- **Focus:** focus moves in on open, is contained, and returns on close; Escape and swipe-down close (unless unsaved — confirm first, Section 27).
- **One sheet at a time; sheets never open sheets.** A sheet that needs another sheet is a page.
- **The sheet is not a stealth modal:** its title always states what the user is choosing or confirming.

---

## 22. Forms

- **Structure:** title (what the form achieves) → grouped sections with headers (identity, contact, clinical, payment) → primary action (one) pinned at bottom on mobile / bottom-right on desktop.
- **Field ordering follows work order** (Section 2), not schema order: registration asks name/DOB/sex/phone before anything else; charge forms show the amount first.
- **Defaults reduce typing:** sex/department/location pre-fill from context; the last-used value is a hint, never silently applied (except tenant-configurable safe defaults).
- **Validation:** validate on blur for field-level, validate the whole form on submit; **never disable the submit button while the form is valid** — show errors on submit instead (disabled buttons hide the problem).
- **Error summary** at the top of the form on submit failure ("3 fields need attention"), with focus moved to the first error (Section 25).
- **Saving:** "Save changes" is one tap; a spinner replaces the label while busy; success is quiet (Section 26). Long operations (reports, batch imports) show progress, never a frozen button.
- **Clinical forms** (encounters, prescriptions, admissions) additionally: an explicit "Sign" step with the clinician's identity confirmed, and an audit line ("Signed by Dr. X, 14:32") — signing is an event, not a save (Section 33, High-Risk rules).
- **Unfinished forms:** leaving a form with unsaved input triggers the confirmation pattern (Section 27); drafts are kept (clinical drafts) or discarded deliberately (counter forms).

---

## 23. Empty States

Every empty state answers three questions: **what this is, why it's empty, what to do next.**

- **Pattern:** centered or in-context, icon (line style, neutral) → title ("No appointments today") → one-line explanation ("The queue is clear for this doctor's morning session.") → optional primary action ("Book first appointment").
- **Empty is never an error and never "No data."** It is an invitation with a direction.
- **Clinical empties are honest:** "No laboratory results yet" (not "results: none") — missing and negative are visually distinct (Section 33: explicit "—" for missing, never a fake zero).
- **Filtered-away empties** include a "Clear filters" action and say what was filtered, not that data doesn't exist.
- **Search empties** follow Section 16 (duplicate-check guidance, register-new path).

---

## 24. Loading States

- **Skeletons for content regions** (shape-matching placeholders at `mist` with quiet shimmer), never blank regions; the page layout is drawn immediately so nothing jumps.
- **Spinners for actions:** the button shows its label replaced by a spinner (button width unchanged — no layout jump); actions are *busy*, not disabled-without-explanation.
- **Initial load:** skeleton list; **refresh:** stale-while-revalidate with a subtle "updating" indicator when safe (never for clinical truth — clinical data loads fresh and blocks interaction until loaded; `ARCHITECTURE.md` §13).
- **Queue/status screens** (tokens, wait times) update live via the realtime channel with a quiet "live" indicator; a disconnected indicator appears within 10 s (Section 25 offline state).
- **Never show an infinite spinner:** every loading state has a timeout → error state with retry (Section 25).

---

## 25. Error States

- **Voice:** the system's voice, plain and factual — *what happened, what the user can do.* "Connection lost. Check your network and try again." — never "An error occurred", never an apology without a path.
- **Field errors:** danger border + icon + inline message under the field, announced to screen readers on appearance.
- **Form errors:** error summary at top, focus jumps to first error.
- **Request errors:** banner at the top of the surface with retry; a failed save never silently loses input — the draft is kept and the error explains how to recover.
- **Full-screen errors** (route/load failure): title, explanation, primary "Try again", secondary "Back"; never a blank page.
- **Offline:** a persistent, dismissible banner ("Offline — changes queue until reconnected"); clinical mutations offline are only allowed in designed, reconciliation-safe flows (`MASTER_RULES.md` §14, `ARCHITECTURE.md` §3) — everything else blocks with an explanation.
- **Errors are logged with correlation IDs** (Section 28) so a user-reported error has a trace; the UI can surface "Reference #abc123" in support-facing errors.

---

## 26. Success States

- **Quiet confirmation is the default:** the action completes, the state updates, and a brief toast (Section 29) confirms ("Dispensed", "Saved", "Payment recorded"). No confetti, no celebratory screens — this is a clinical instrument.
- **Screen-transition success:** where the next state *is* the confirmation (e.g., after "Check in", the queue shows the patient checked in), no toast is needed — the state change is the message.
- **Persistent confirmations for significant events:** "Result verified" shows in the record's audit line ("Verified by X, 14:32") — permanent, not toast.
- **Idempotent actions show "Already done"** instead of re-running (double-tap protection, Section 32).

---

## 27. Confirmation Patterns

A single ladder by risk — every action in the product sits on it:

| Level | Risk | Pattern |
|---|---|---|
| L0 | Reversible, low impact | Execute instantly; toast with **Undo** where an undo window is safe (mark no-show, dismiss alert) |
| L1 | Moderate impact, reversible with audit | Confirmation dialog: title + what happens + Cancel/Primary |
| L2 | Destructive or irreversible (void charge, merge patients, reverse dispense, discard unit, discharge) | **Type-to-confirm dialog**: the destructive verb + affected identifier must be typed (e.g., "MERGE") or the MRN retyped; reason field required; audit event mandatory |
| L3 | Life-critical (transfusion, controlled dispensing, result override) | L2 + **two-person verification in-app** where policy requires (dual verification) — second operator authenticates in the flow; both recorded |

Rules:

- **Cancel is always available and never punished;** destructive dialogs put the destructive action *first* in reading order but keep Cancel visually safe.
- **Reason capture** is mandatory at L2+ (free text + code list); the reason prints in the audit trail.
- **No "are you sure?" wallpaper:** L0/L1 confirmations are used only when the consequence is real; over-confirming trains users to click through (alert fatigue — `MASTER_RULES.md` §34.4).
- **Unsaved-input warnings** use the L1 pattern ("You have unsaved changes. Discard them?") — never silent loss, never a blocked exit without explanation.
- **Every confirmation's title names the object**, never a generic verb: "Void charge #INV-1042?" — not "Confirm action?".

---

## 28. Notifications

- **One notification center** (bell in the header; the Inbox tab on mobile) with per-role relevance: results to verify, approvals waiting, critical alerts, stock warnings, appointment changes.
- **Priority tiers are visual and behavioral:**
  - **Critical** (danger pattern + persistent badge): critical lab values, allergy-risk events, escalation timeouts. These also use the alert/banner pattern (Section 33) — a toast is not enough.
  - **Important** (info/warning): verification queues, approvals, expiring stock, payment failures.
  - **Informational** (neutral): confirmations, status changes, campaign notices.
- **Badges count actionable items, not events**: "3" means three things need attention, not three notifications about the same result.
- **Delivery:** in-app always; email/SMS per user preference and per consent (`PRODUCT_REQUIREMENTS.md` §5.4); critical clinical alerts never depend on a single channel.
- **Actions from notifications:** tapping a notification opens the *record in context* (with the Identity Spine, never a bare entity), not a notification list.
- **Notification content never contains PHI in the notification channel itself** without consent rules being satisfied (`MASTER_RULES.md` §10) — in-app previews are access-controlled like the record.

---

## 29. Toasts

- **Position:** bottom-center above the bottom nav on mobile (thumb-visible), top-right below the header on desktop. Never over the Identity Spine or a modal's action bar.
- **Anatomy:** icon (by tone) + message + optional action ("Undo", "View"); quiet `shadow-lg`, radius `md`.
- **Duration:** 4 s default, 6 s with an action; **never auto-dismiss for L2+ outcomes** (those are confirmed in the record, Section 26).
- **Stacking:** max 3; the newest is highest; excess queue.
- **Dismiss:** tap or swipe away; Escape on desktop. Toasts are dismissible, never blocking.
- **Critical clinical alerts do not use toasts** (Section 28) — toast is for confirmations and minor state changes; a critical value uses the alert banner + record-level marking (Section 33).
- **Accessibility:** toasts are announced to screen readers; a toast with an action keeps focus reachable (never steals focus).

---

## 30. Accessibility

**WCAG 2.1 AA is the floor** (`MASTER_RULES.md` §15); clinical safety raises the bar on specific patterns:

- **Contrast:** text ≥ 4.5:1 (normal) / 3:1 (large); UI components and focus indicators ≥ 3:1. Every token addition is contrast-checked before entry (Section 10).
- **Never color alone:** all status (Section 12), charts, and alerts pair color with text/icon/shape. This is both an a11y rule and a clinical-safety rule (colorblind clinicians exist).
- **Focus is always visible:** 2 px ring + 1 px white halo, on every interactive element, in every state. Focus follows a logical order that matches the workflow (Section 31).
- **Screen readers:** every control has an accessible name; every screen has a heading structure; labels are programmatically bound to inputs; icon buttons have names; tables have headers; live regions announce status changes (results arrive, queue updates) without stealing focus.
- **Touch:** targets ≥ 44 × 44 (Section 32); no hover-dependent functionality (Section 4).
- **Motion:** `prefers-reduced-motion` disables shimmer, transitions, and drag-sheet animations; nothing essential is conveyed by motion (Section 24's shimmer is decoration only).
- **Language:** `lang` is set per locale; Devanagari rendering is validated per font-weight (Section 8); numerals are localized per tenant config while identifiers (MRN) always render in Latin digits.
- **Zoom:** the interface remains usable at 200 % zoom (no horizontal loss at mobile widths); text never clips.
- **Automated checks** (axe-based) run in CI on rendered screens; manual screen-reader review is required for every critical workflow before release (`MASTER_RULES.md` §15.6). An a11y regression is a release-blocking defect.

---

## 31. Keyboard Navigation

- **Everything is keyboard-operable:** every action, dialog, sheet, table row, and form control. There are no mouse-only paths.
- **Visible focus throughout** (Section 30); Tab order matches the visual workflow order.
- **Dialogs/sheets:** focus moves in on open, is trapped, Escape closes (with unsaved-input confirmation per Section 27), focus returns to the trigger on close.
- **Tables:** arrow keys navigate rows; Enter opens the row; Shift+Space toggles selection in select-mode.
- **Shortcuts (desktop, documented and opt-in-visible):** `/` global search, `N` new (contextual), `1–5` bottom destinations, `?` opens the shortcut help sheet. Shortcuts never fire inside inputs; a shortcut that conflicts with a clinical typing field is not a shortcut.
- **Skip link:** "Skip to content" is first in the tab order.
- **No keyboard trap without a way out:** every region that captures arrows (tables, lists) releases on Tab.

---

## 32. Touch Targets

- **Minimum 44 × 44 px** for every interactive element on touch; adjacent targets are separated by ≥ 8 px.
- **Critical actions are ≥ 48 px tall** (primary buttons, quick actions, row actions).
- **Thumb zones:** primary actions sit in the lower third on mobile (bottom action bar, Section 35); destructive actions are never the nearest-to-thumb accidental target (danger sits with a gap or behind the confirmation pattern).
- **Double-tap protection:** submission buttons debounce; idempotent APIs make retries safe (`MASTER_RULES.md` §12.4); a double tap on "Dispense" cannot double-dispense.
- **Swipe gestures are shortcuts only** — every swipeable action has a visible tap path; swipe never triggers an irreversible action.
- **Device validation:** target sizes are verified on representative low-cost Android and iOS devices in CI/manual review (`MASTER_RULES.md` §14.5).

---

## 33. Clinical Data Presentation

The rules that make clinical screens safe to read fast:

- **The Identity Spine is always present in clinical context** (Section 0): name, MRN (mono), age/sex, and the **allergy chip** — an always-visible amber/red "ALLERGIES" chip listing known allergies (or a quiet "No known allergies" line; absence is a fact, not a blank). It never scrolls away.
- **Tabular numerals and right-alignment** for every measured value and amount (Section 8).
- **Units are always shown:** "120/80 mmHg", "98.6 °F / 37.0 °C", "Hb 12.4 g/dL". A value without a unit is a defect.
- **Reference ranges print with the value:** "5.2 (4.0–6.0) mmol/L"; out-of-range values are flagged with a subtle tint + arrow (↑/↓), never color alone.
- **Critical values** are the loudest element: red, an alert icon, the word **"CRITICAL"**, and the value at display size — plus a recorded acknowledgment line ("Acknowledged by Dr. X, 14:32"). No animation (Section 12). Critical never hides behind a chip or a toast (Section 28).
- **Missing vs. negative is explicit:** a missing value renders "—" (not a zero, not a blank); a genuinely measured zero renders "0". A "—" can never be misread as 0.
- **Dose/frequency text is structured, not free-form prose** where possible: "Paracetamol 500 mg — 1 tab, every 8 h, oral, 5 days". The components (medication, dose, route, frequency, duration) are separate labeled fields on the prescription, then rendered as one readable line.
- **Identifiers in mono** (MRN, accession, batch, claim numbers); a patient's MRN is visible in the Identity Spine and on every printout, not just in search.
- **Dates with age context in clinical lists:** "12 Mar 2026 (54 y)" — DOB is a fact; age is derived and shown where it matters (dosing).
- **Charts/trends** (vitals, lab over time): axes labeled, units in labels, reference-range band drawn, points readable at the smallest supported viewport; trend sparklines show value + direction + unit on demand — never a naked line without a scale.
- **Print/export renders the same hierarchy** — print styles are designed (Identity Spine header, mono identifiers, units, signature lines), because printouts are clinical documents too.

---

## 34. Dense Desktop Workflows

For power users at `xl+` — accountants, lab supervisors, admins, executives:

- **Density toggle** (comfortable ↔ compact) in the user menu; compact reduces row height to 40 px, body to 14 px, and gaps to `sm`. Default is comfortable; the choice persists.
- **Multi-pane layouts:** list (left) + detail (right) with synchronized scroll; the list is the navigation, the detail is the work surface. Pane widths resizable with a visible handle; state persists.
- **Batch operations** (verify multiple results, approve requests, allocate payments) with an explicit select mode, a running selection summary, and batch confirmation (Section 27 — batch never includes L2+ actions without per-row review).
- **Data density without overload:** grouping rows by status/facility, column visibility controls, saved filters/views per user, and keyboard-first row operations (Section 31).
- **Audit-adjacent workflows** (reconciliation, claims review) show the transaction trail inline: "CHG-1 posted 14:31 by A → INV-1042 issued 14:32 by A → PAY-88 allocated 14:40 by B" — the trail is the working document.
- **Dashboards (executives):** KPIs at display size with tabular numerals, trend context (period-over-period), and drill-down to the underlying data — a number without a path to its source is decoration (and a metric without a versioned definition is a defect — `PRODUCT_REQUIREMENTS.md` §6.19).

---

## 35. Mobile Quick Actions

The mobile speed layer — every action is reachable in ≤ 2 thumb taps:

- **Bottom action bar (primary):** the current screen's actions pinned above the safe area; primary action is always the rightmost (thumb reach) unless the workflow dictates otherwise; the bar scrolls content above it, never below it.
- **Center "New" hub** in the bottom nav (Section 5): register patient, book appointment, start encounter, dispense, collect payment — the creation hub is contextual to the role.
- **Scan-first:** the barcode scan action is one tap in the patient context (wristband scan = identity confirmation); scan input focus is one tap anywhere (Section 16).
- **Contextual quick actions per surface:** Queue → "Check in" / "Start visit"; Prescription → "Dispense"; Result → "Verify"; Bed → "Assign". The verb is the button (Section 13).
- **Undo where safe** (L0 actions, Section 27): "Marked as no-show — Undo".
- **Quick actions never skip safety:** the quickest path still passes the confirmation ladder (Section 27) and the Identity Spine check (Section 33) — speed is achieved by removing *steps that don't matter*, never by removing the ones that do.

---

## High-Risk Clinical Actions — Rules

These actions are subject to the confirmation ladder (Section 27) **and** the following specific rules. This list is a minimum; new actions are classified at design time by the same criteria (irreversibility × clinical/financial impact):

| Action | Required protections |
|---|---|
| **Merge patient records** | L2 type-to-confirm (type "MERGE"), both MRNs shown, reason required, full audit; merged record keeps complete history (`PRODUCT_REQUIREMENTS.md` §6.1) |
| **Void/adjust a charge or invoice** | L2 + reason code + approver where policy requires; reversing entry, never an edit (`MASTER_RULES.md` §37) |
| **Reverse a dispense / return medicine** | L2 + reason; stock and charge are reversed transactionally with the batch restored (`DATABASE.md` 3.30) |
| **Discard a blood unit / expired stock** | L2 + unit/batch identifier retyped; disposition recorded; recall path documented |
| **Discharge / transfer a patient** | L1–L2 by policy + Identity Spine confirmation; discharge summary must be complete before settlement (`PRODUCT_REQUIREMENTS.md` §6.5) |
| **Override a CDSS alert (interaction, allergy, dose)** | L1 + mandatory reason; alert context and rule version recorded for reconstruction (`MASTER_RULES.md` §34) |
| **Correct/override a verified lab result** | L2 + reason; the correction is a new audited version, never an edit; critical-value escalation re-run if applicable |
| **Administer a medication (MAR)** | Identity Spine + patient double-confirm (name + MRN); dual verification where policy requires; refusal/miss reasons captured |
| **Issue/transfuse blood** | Identity Spine + unit-to-patient compatibility confirmed on screen; dual verification; reaction reporting path visible |
| **Sign a clinical note / encounter** | The signer's identity is confirmed in-flow; "signed by X at time" is permanent; amendments are new audited versions |

**General rules:**

1. **No silent destructive actions.** Every action on the ladder leaves an audit event with actor, object, reason, and timestamp (`MASTER_RULES.md` §19) — the UI shows the audit line, the database enforces it.
2. **The Identity Spine is checked at the moment of action**, not at the start of the session: the confirmation dialog shows name + MRN again.
3. **Reason capture is structured** (code + optional note), never free-text-only.
4. **Speed shortcuts never bypass these rules** (Section 35) — the ladder is the floor.
5. **Two-person verification happens in-app** (L3): the second operator authenticates in the flow; both identities are recorded; the UI shows the completed verification state.
6. **Any new action classified as high-risk at design review** must state its protections in the design spec before build; unclassified-by-default is prohibited (`MASTER_RULES.md` §11).

---

## Implementation Notes

- **Tokens are code:** the design tokens here (color, type, spacing, radius, elevation, breakpoints) are implemented as CSS custom properties in one token file; components consume tokens only — no raw hex or px in feature code. A token addition requires a contrast/scale check and design review (governance like `MASTER_RULES.md` §25.1).
- **Components** (buttons, inputs, modals, sheets, toasts, chips, tables) are built once in the shared layer and reused by every feature (`ARCHITECTURE.md` §3); a feature that hand-rolls a shared component is a review violation.
- **Motion** is limited to: skeleton shimmer, sheet/drawer slide (200 ms ease-out), toast fade, focus transitions. Reduced-motion disables all of it (Section 30).
- **Design QA checklist per screen:** identity context present where clinical · one primary action · contrast passes · keyboard-complete · focus visible · works at 200 % zoom · mobile width first · empty/loading/error states designed · high-risk actions classified · Devanagari rendering checked.
- **Regression gates:** visual regression tests per component, a11y scans in CI, touch-target lint, and the keyboard audit in the Definition of Done (`MASTER_RULES.md` §40).
- **Iconography:** one line-weight icon set (1.5–2 px), semantic color usage only; icons always have labels in clinical surfaces (an icon without a label is a defect at speed).

---

*This document is the design contract for Swasthya. Screens are designed against it per feature; the system itself changes only through the same review discipline as code. The measure of success is simple: a nurse can check in a patient, a pharmacist can dispense against a prescription, and a doctor can sign an encounter — on a phone, in bright light, in under a minute, without ever wondering which patient is in front of them.*
