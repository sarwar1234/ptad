# PTAD Loader — Unmapped Column Policy

## The rule (applies to every config in this folder)

Every module's Excel sheet has a small number of columns that don't map
directly to one of the schema's named fields (e.g. "GSP_SPI", "DCTS_Treatment_Basis",
"Unit", a non-English duplicate description column). Per the company
requirement that **no data may be left behind**, these are resolved as
follows, uniformly, across all 25 modules:

**Every unmapped column's value is appended into that row's `remarks`
field**, prefixed with its own original column header as a label, e.g.:

```
[Original remarks text, if any] | GSP_SPI: A | Unit: NMB
```

This guarantees:
- Nothing is silently discarded — every cell's value ends up in the database.
- The data is still human-readable and searchable (remarks is a TEXT field).
- No schema change or new column is needed to ship this.
- If TDAP later decides a specific column deserves its own dedicated
  database field (e.g. promoting "Unit" to a real column), that's a
  straightforward follow-up migration — the data is already captured
  and nothing needs to be re-loaded from Excel to make that change.

## What this resolves

Every `_extra_note` flag left in the individual config files as
"no direct schema field mapping decided" is resolved by this policy.
Those notes are left in place in each config file as documentation of
WHAT the column was and WHY it doesn't have a dedicated field — not
because a decision is still pending.

## What this does NOT resolve (genuine structural items, not cosmetic)

The following are NOT simple placement questions and are NOT solved by
this policy. They are listed here, once, in plain language:

1. **Türkiye PTA (PTA_TUR_PAK.json)** — the visible sheet only shows
   ONE point-in-time rate (selected by a date picker). No hidden
   sheet with the full year-by-year rate history (like China's
   `China_Raw`) was found in this workbook. This means: as things
   stand, the loader can only capture whatever rate is currently
   displayed for whatever date the sheet happens to be set to — not
   the complete phased schedule the database is designed to hold.
   **This needs a decision from TDAP**: either (a) someone confirms
   whether such a full schedule exists elsewhere (a hidden sheet we
   haven't found, a separate file), or (b) the module is loaded as a
   single current snapshot rather than a full phased schedule, with
   that limitation documented for users.

2. **GSTP (RTA_GSTP.json)** — the workbook's structure (45 stacked
   country sections in one sheet, each with its own repeating header)
   is fundamentally different from every other file, and needs its
   own dedicated parsing logic rather than the simple column-mapping
   every other config uses. This is a bigger coding task, not a data
   question — flagged here just so it's on your radar; it will be
   handled as part of building that module's format handler in a
   later step, no action needed from you now.

Everything else across all 25 modules is a normal placement/mapping
detail, now resolved uniformly by the policy above.
