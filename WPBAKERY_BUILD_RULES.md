# WPBakery Build Rules — NON-NEGOTIABLE

You are building pages on a live WordPress site through the `wpbakery_*` MCP
tools. This file is the contract. Read it fully before composing or editing any
page or section, and follow it exactly. It exists because the easy/lazy path —
dumping a section's content into one big block — produces pages that look fine
once but are **uneditable in the WPBakery builder**, which is the entire reason
the client uses WPBakery. Granular, native, panel-editable elements are the
product. A page that renders correctly but is one opaque blob is a FAILURE, even
if it looks right in a screenshot.

---

## 0. The Prime Directive

**One logical content unit = one native WPBakery element.**

A heading is a `vc_custom_heading`. A paragraph is a `vc_column_text`. A button
is a `vc_btn`/`us_btn`. An image is a `vc_single_image`/`us_image`. An FAQ is a
`vc_tta_accordion`. A divider is a `vc_separator`. Spacing is a
`vc_empty_space`. Each of these is a separately editable block in the builder.

If a client opens this page in WPBakery, **every distinct thing they'd want to
change must be its own element they can click.** That is the test for "done."

---

## 1. THE FORBIDDEN ANTI-PATTERN (this is why this file exists)

**Do NOT cram a section into one element.** Specifically, NEVER do any of these:

- ❌ Put a heading + paragraphs + button + image into a single `vc_column_text`
  as inline `<h2>…</h2><p>…</p><a class="button">…</a><img>` HTML.
- ❌ Use `vc_raw_html` (or a theme raw-HTML element like `us_html`) to lay out
  content. Raw-HTML blocks are one opaque, uneditable lump — banned for layout.
- ❌ Reproduce a multi-element layout (cards, grids, icon rows, stats bars) as
  hand-written HTML inside one text block.
- ❌ Use `<br>` stacks or inline `style="margin…"` inside a text block to fake
  spacing instead of `vc_empty_space` / real elements.

**Why it's wrong:** the heading isn't a real heading element, so theme
typography and per-element controls don't apply; the button can't be re-linked
in the panel; the image can't be swapped; nothing can be reordered; Design
Options and responsive controls are gone; a theme/migration can't re-map it; and
`wpbakery_validate` / `unrendered_shortcodes` can't see inside it. It looks done
and is actually broken.

**`vc_column_text` is for genuine running prose ONLY** — one or more paragraphs,
possibly with inline links or a `<ul>`/`<ol>` list inside the paragraph flow.
The moment a "text block" would contain a heading, a button, an image, an alert,
a divider, or a layout of multiple visual pieces, STOP and break it into the
elements in §3.

---

## 2. Mandatory workflow (do these in order, every time)

1. **Ping** (`wpbakery_ping`) — confirm the client + WPBakery version.
2. **Discover** (`wpbakery_list_elements`) — see what's registered, INCLUDING the
   site's own custom elements (e.g. `vb_*`, `us_*`). Prefer purpose-built site
   elements over generic ones (see §5).
3. **Read schemas** (`wpbakery_element_schema`) for every element you'll use —
   you need exact `param_name`s, dropdown values, and the value encoding (§4).
   Do NOT guess attribute names; the schema is ground truth.
4. **Decompose** the requested content into a tree of native elements on paper
   first: `vc_row → vc_column → [heading, text, button, image, …]`. (§3, §6)
5. **Build** the shortcodes. Use `wpbakery_build_element` for non-trivial
   elements so they're validated as you go.
6. **Validate** (`wpbakery_validate`) the full content before writing. Fix every
   error (unknown element/param) — don't `skip_validate` to paper over them.
7. **Write to a DRAFT** — `wpbakery_create_page(status="draft")` for new pages,
   or edit on a draft. Never compose-and-publish straight to a live page.
8. **Preview** (`wpbakery_render_preview`) — read `unrendered_shortcodes` (the
   theme dropped these → replace with theme-native equivalents) and screenshot
   the `preview_url` (`python screenshot.py "<url>" shot.png`, add `--height` for
   long pages), then Read the PNG and judge it against the request.
9. **Self-audit** against §7. If it fails, fix before going further.
10. **Style** via page CSS (§8), iterate with `wpbakery_append_page_css`.
11. **Publish** only when approved — `wpbakery_set_status(publish)`. Set SEO/
    noindex via `wpbakery_set_post_meta`.

Iterate cheaply: `wpbakery_append_page_css` for CSS, `wpbakery_replace_in_content`
for surgical content edits. Don't resend the whole page to change one thing.

---

## 3. Element selection map — decompose to THESE

| Content you're given | Native element (NOT a text block) |
|---|---|
| Section / sub-heading (h1–h6) | `vc_custom_heading` (set `font_container="tag:h2|text_align:…"`) |
| Body paragraph(s), prose, inline list | `vc_column_text` — prose only |
| Call-to-action button | `vc_btn` (or theme `us_btn` on Impreza) |
| A single image / photo | `vc_single_image` (or `us_image`) |
| Standalone icon | `vc_icon` |
| Icon + heading + blurb ("feature") | theme icon-box if present (`us_iconbox`), else `vc_icon` + `vc_custom_heading` + `vc_column_text` in a column |
| Notice / alert / callout | `vc_message` |
| Horizontal divider | `vc_separator` (label → `vc_text_separator`) |
| Vertical spacing | `vc_empty_space` |
| Bundled CTA banner (heading+text+button) | `vc_cta` |
| FAQ / collapsible Q&A | `vc_tta_accordion` → one `vc_tta_section` per question (title=question) → `vc_column_text` answer |
| Tabbed content | `vc_tta_tabs` → `vc_tta_section` per tab |
| Vertical tabs | `vc_tta_tour` → `vc_tta_section` |
| Percentage circle / stat | `vc_pie`; bars → `vc_progress_bar` |
| Image gallery / grid | `vc_gallery` / `vc_media_grid` |
| Embedded video | `vc_video` |
| Multi-column layout | `vc_row` → multiple `vc_column` (`width="1/2"` etc.); sub-grid → `vc_row_inner` → `vc_column_inner` |
| A repeated, site-specific section pattern | a site custom element if one exists (§5) |

If a layout needs columns inside a column, use `vc_row_inner` → `vc_column_inner`
— never nest `vc_column` directly in `vc_column`.

---

## 4. Attribute value encoding (write these correctly or the element breaks)

The element schema tells you each param's `type`. Encode per type:

- **textfield / textarea** → plain string. `title="Get a quote"`.
- **dropdown** → the option's *value*, not its label. `size="lg"`, `align="center"`.
- **checkbox** → the checked value, often `"true"` or `"yes"` (check the schema).
- **colorpicker** → hex. `color="#ff6600"`.
- **attach_image** → the bare **attachment ID integer**. `image="10172"` (NOT a URL).
- **attach_images** → comma-separated IDs. `images="10,11,12"`.
- **textarea_html / rich text** → NOT an attribute. It is the element's **inner
  content** between the tags, and its `param_name` is `content`. (That's why
  `vc_column_text`/`vc_tta_section` carry text between `[tag]…[/tag]`.)
- **vc_link** → encoding is theme-dependent; match what the live schema/page uses.
  Core WPBakery: pipe format, rawurlencoded —
  `link="url:%2Fcontact%2F|title:Contact|target:_blank"`. us-core/modern:
  rawurlencoded JSON — `link="%7B%22url%22%3A%22%2Fcontact%2F%22%7D"`
  (`{"url":"/contact/"}`). When unsure, read a working element on the live site.
- **font_container** → pipe `key:value`. `font_container="tag:h2|text_align:center"`.
- **google_fonts** → pipe with rawurlencoded values; or set `use_theme_fonts="yes"`.
- **css (css_editor, Design Options)** → `rawurlencode(json_encode({...}))` keyed
  by breakpoint (`default`/`laptops`/`tablets`/`mobiles`). But see §8 — prefer
  page CSS over `css=` on Impreza-class themes.
- **param_group** → `rawurlencode(json_encode([ {row1}, {row2} ]))`.

When in doubt about an encoding, read an existing element of the same type off
the live page (`wpbakery_get_page`) and mirror its format.

---

## 5. Prefer site-specific and theme-native elements

1. **Site custom elements first.** This site ships bespoke section elements
   (e.g. `vb_*` — Vista Solar, Vista Locations, Vista About). If one matches the
   section you're asked to build, USE IT — it's purpose-built, on-brand, and the
   editor exposes its fields. Read its schema and fill its params. Don't
   re-implement its layout by hand.
2. **Theme-native over WPBakery core twin.** On Impreza / us-core, prefer
   `us_btn` (NOT `vc_btn` — it renders as literal `[vc_btn]` text), `us_image`
   (NOT `vc_single_image` — also renders as literal text on Impreza),
   `us_iconbox`, `us_separator`. **Verified on a live Impreza/us-core site:**
   `vc_single_image` and `vc_btn` print their raw shortcode as visible text;
   `[us_image image="<id>" size="large"]` renders correctly. The `us_*` elements
   may NOT appear in `wpbakery_list_elements` (the theme registers the shortcode
   without vc_mapping it) yet still render — test one on a draft before assuming
   it's unavailable. Size/position `us_image` via CSS targeting `.<el_class> img`
   on its parent column (us_image takes no `el_class`).
3. **A literal-shortcode render is the #1 silent failure.** When a theme doesn't
   render an element it often outputs the raw `[shortcode]` as on-page text
   instead of dropping it — and `unrendered_shortcodes` can still come back
   EMPTY. So an empty `unrendered_shortcodes` is NOT proof an element rendered.
   The only reliable check is to screenshot the draft and look (see §2/§7).

---

## 6. Page structure (how a real section is composed)

Every visual band = its own `vc_row` (section). Inside: a `vc_column` (or two for
image/text splits). Inside each column: **multiple distinct content elements** in
order. Example of a correct hero + two-up + FAQ page:

```
[vc_row]                          ← hero band
  [vc_column]
    [vc_custom_heading text="Solar Installation Experts" font_container="tag:h1|text_align:left"]
    [vc_column_text]Intro paragraph of genuine prose.[/vc_column_text]
    [vc_btn title="Get a quote" link="%7B%22url%22%3A%22%2Fcontact%2F%22%7D"]
  [/vc_column]
[/vc_row]
[vc_row]                          ← two-up: text | image
  [vc_column width="1/2"]
    [vc_custom_heading text="Why choose us" font_container="tag:h2|text_align:left"]
    [vc_column_text]Body copy.[/vc_column_text]
    [vc_btn title="Learn more" link="…"]
  [/vc_column]
  [vc_column width="1/2"]
    [vc_single_image image="10172" img_size="large"]
  [/vc_column]
[/vc_row]
[vc_row]                          ← FAQ
  [vc_column]
    [vc_custom_heading text="FAQs" font_container="tag:h2|text_align:center"]
    [vc_tta_accordion]
      [vc_tta_section title="How long does install take?" active="1"]
        [vc_column_text]Answer prose.[/vc_column_text]
      [/vc_tta_section]
      [vc_tta_section title="Do you handle the rebate?"]
        [vc_column_text]Answer prose.[/vc_column_text]
      [/vc_tta_section]
    [/vc_tta_accordion]
  [/vc_column]
[/vc_row]
```

Note: heading, prose, and button are **three separate elements**, not one HTML
blob. That is the whole point.

---

## 7. Self-audit gate (run this before you call a build "done")

Answer each. If any answer is "no" (or "yes" where it should be "no"), fix it.

- [ ] Is **every heading** its own `vc_custom_heading` (no `<h1>`–`<h6>` buried
      inside a `vc_column_text`)?
- [ ] Is **every button/CTA** its own `vc_btn`/`us_btn` (no `<a class="button">`
      inside a text block)?
- [ ] Is **every image** its own `vc_single_image`/`us_image` (no `<img>` inside
      a text block)?
- [ ] Are there **zero** `vc_raw_html` / `us_html` / raw-HTML layout blocks?
- [ ] Does each `vc_column_text` contain **only prose** (paragraphs/lists), never
      a heading+button+image composite?
- [ ] Is multi-column layout done with real `vc_column` widths, not HTML/CSS
      columns inside one block?
- [ ] Did a **site custom element** (`vb_*`) or **theme element** (`us_*`) exist
      for this section, and did you use it instead of rebuilding it by hand?
- [ ] Did `wpbakery_validate` pass with no errors, and is `unrendered_shortcodes`
      empty in the preview?
- [ ] Did you actually **screenshot the preview and look at it**?

A useful smell test: count distinct element *types* in a content-rich section.
If a hero/feature section reduces to a single `vc_column_text`, you took the lazy
path — decompose it.

---

## 8. Styling: page CSS, not inline HTML, not `css=`

Give elements an `el_class` and style them via `wpbakery_set_page_css` /
`wpbakery_append_page_css` (or `update_page(page_css=…)`). Do NOT skin elements
by wrapping them in HTML, and do NOT rely on the WPBakery `css=` design-options
attribute for section backgrounds/padding — Impreza/us-core ignore it. Themes
re-render elements (e.g. Impreza renders accordions as `.w-tabs` /
`.w-tabs-section-*`, not `.vc_tta-*`); use `wpbakery_render_preview` to find the
REAL rendered class names and target those, with `!important` where you must beat
theme CSS. Page CSS stays editable in the builder and keeps content granular.

---

## 9. When (and when not) to author a NEW custom element

Most work needs ZERO new code — compose native elements (§3) and style with page
CSS. Author a new custom WPBakery element only when a **non-trivial, branded
section pattern recurs across many pages** and would otherwise be rebuilt by hand
each time (this is exactly why the site already has `vb_*` elements). A custom
element is a maintenance commitment in the theme/site plugin — never reach for it
as a shortcut to avoid decomposing a one-off page.

If you do build one (in the theme or a site plugin, NOT this MCP repo):

- Register in `vc_before_init` with `vc_map([... 'base'=>'my_el', 'params'=>[…]])`;
  modify existing elements in `vc_after_init` (`vc_add_param`/`vc_remove_param`).
- Provide a render callback: `add_shortcode('my_el', fn)` **or** an autonomous
  class `WPBakeryShortCode_My_El extends WPBakeryShortCode` (containers extend
  `WPBakeryShortCodesContainer`).
- Rich text param → `type=textarea_html`, `param_name="content"` (inner content).
- Containers: set `is_container=true`, `as_parent`/`as_child` to constrain
  nesting, `js_view="VcColumnView"`, and `default_content` for initial children.
- Map an existing 3rd-party shortcode by calling `vc_map()` only (no new
  `add_shortcode`); use `vc_lean_map()` to lazy-load heavy param arrays.
- Keep every field a real param so the section stays panel-editable — the same
  granularity rule applies inside custom elements.

Reference: WPBakery KB `vc_map` / nested-shortcodes-container / param-group docs.

---

## 10. Impreza/us-core CSS translation (target the theme's REAL markup)

The theme re-renders your shortcodes into ITS markup — style those classes (via
page CSS, §8), not WPBakery's. Verified on a live Impreza/us-core site:

| You build (el_class `x`) | Impreza renders | Style this |
|---|---|---|
| `vc_row` | `section.l-section.x` wrapping `.l-section-h` | bg / clip-path / padding on `.x`; the **content container** is `.l-section-h` — force `max-width` + `margin:0 auto` (pages can render full-bleed) |
| columns | a **CSS grid** `.g-cols.cols_N` (e.g. `grid-template-columns:538px 538px`) | for custom/asymmetric widths set `grid-template-columns` **on `.g-cols`** — setting a column's `width`/`flex` only shrinks the item INSIDE its track (52% of 538 = 280px; a classic trap) |
| `vc_custom_heading` | `div.vc_custom_heading.x` (text directly inside) | `.x` — and set `use_theme_fonts="yes"` or it defaults to Abril Fatface |
| `vc_column_text` | `.wpb_text_column.x > .wpb_wrapper > p` | `.x`, `.x p` (zero the `p` margins) |
| `vc_tta_accordion` | `.w-tabs` / `.w-tabs-section-header/-title/-control/-content-h` | those, with `!important` |

**Icons & buttons without leaks.** Per §5, `vc_btn`/`vc_icon`/`vc_single_image`
can print as literal `[shortcode]` text, and `us_*` render but aren't in `vc_map`
(so `validate` flags them). For guaranteed, validate-clean, pixel-perfect control:

- **Icon** → a CSS background-SVG on a native element:
  `.x-title::before{content:"";display:block;width:34px;height:34px;
  background:url("data:image/svg+xml,%3Csvg…") no-repeat center/contain;}`
  (URL-encode the SVG; `%23` for `#` in colours). No icon-font/shortcode dependency.
- **Button** → a styled `<a>` inside a `vc_column_text`
  (`[vc_column_text]<a class="btn">Label</a>[/vc_column_text]`), skinned in page CSS.

**Advanced CSS works natively** (verified): overlap / `z-index`, `clip-path`
angled section dividers, negative-margin cards that straddle two sections.
**Full-bleed** to the viewport edge from inside the padded container:
`margin-right: calc((container/2 − pad) − 50vw)` (≈ `calc(562.5px − 50vw)` for a
1185px container + 30px padding). Untested gap: a real **editable photo** (needs
media upload), as opposed to a CSS-background image.

---

## 11. Brand tokens & the fidelity loop (matching a target design)

The self-audit gate (§7) ensures a build is NATIVE; this section ensures it is
FAITHFUL to the requested design and on-brand.

**Brand tokens (context, not content).** Pull the site's real design language
once and reuse it. If `clients/<slug>.json` has a `brand` block, inject it as
page-CSS variables on the section scope (`.x{ --navy:#002a52; --accent:#f07d00; … }`)
and reference the vars. Otherwise extract it from the live site (chrome-devtools
`navigate_page` → `evaluate_script` over `getComputedStyle` of `h1/h2/body/p`, a
button, `.l-section-h`) and save it to the config.

**The fidelity loop:**

1. **Target.** Get/render the design and screenshot it as the benchmark.
   Reproduce THAT — never substitute an old block, never fake it with raw HTML.
2. **Build native** (§3) + brand tokens → `validate` → `update_page` →
   `set_page_css`.
3. **Preview & screenshot.** `render_preview` (it fetches the UNCACHED render so
   leaking shortcodes are caught) → screenshot the `preview_url` (append a `&cb=1`
   cache-buster; the site header + page-title band are theme chrome). Prefer the
   chrome-devtools MCP: `navigate_page` → `evaluate_script` (read the live DOM to
   find real classes / diagnose the gap) → `take_screenshot`.
4. **Score on two axes:** (a) fidelity — layout, type, spacing, colour vs target;
   (b) nativeness — the §7 gate. Diagnose, fix (CSS-only iterations are instant
   via `set_page_css`), repeat until it matches or plateaus.
5. On a genuine native ceiling, **report it honestly** — never cheat with raw HTML
   to hide it.

Housekeeping: `set_status` accepts `trash` (recoverable) to remove a test draft;
loop scratch (screenshots, references) lives in `.loop/` (git-ignored).
