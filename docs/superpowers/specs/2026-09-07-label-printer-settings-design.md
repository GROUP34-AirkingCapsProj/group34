# Label Printer Settings — Design

**Date:** 2026-09-07
**Status:** approved, not yet implemented
**Relates to:** `2026-09-06-barcode-printing-page-design.md`

## Problem

Each warehouse has its own barcode printer, and the people using this system are
assigned to one of them. A clerk at Warehouse B receiving a PO prints the tags
for what just arrived — some minted by the system, some the supplier's own
codes, some manufacturer serials — and those labels have to come out of the
machine standing next to them.

The printer is addressed through `.env`:

```
LABEL_PRINTER_TRANSPORT=
LABEL_PRINTER_HOST=
LABEL_PRINTER_PORT=9100
LABEL_PRINTER_SHARE=
```

One address, for the whole system. Two failures follow from that.

**It names one machine.** With warehouses A and B both live, whoever is not
next to the configured printer sends their labels into another building. Paper
coming out of the wrong machine is worse than direct printing being unavailable:
nobody is standing there to notice, and the tags for a delivery at B are now
somewhere in A.

**Only a developer can change it.** Editing a file on the server and restarting
is not something a warehouse can do. The day a printer dies, direct printing
stays broken until someone with file access is found — while the people who
know it died are the ones holding the boxes.

There is no printer yet. This work exists so that when the first one arrives,
setting it up is a job for whoever runs the warehouse, so that adding the second
warehouse's printer is the same job again, and so that day-one failures — wrong
address, switched off, wrong network — produce a sentence someone can act on
instead of a 503.

Nothing here is required for printing. The browser-built PDF route needs no
configuration and reaches any printer the machine can see, Bluetooth included.
What configuration buys is skipping the print dialog, and recording print
history: the PDF route cannot be stamped, because the server never sees it.

## Scope

In: a printer per location, stored in the database and editable by an admin;
resolving which printer a job goes to; a reachability check; a panel on the
System Configuration page.

Out: the label geometry — width, height and dpi. See the decisions below.

Out: any change to how a sticker is built or what may be reprinted.
`ZplLabelBuilder`, `TagPrintPolicy` and the tier rules are untouched.
`LabelPrintController` changes only in where it sends the job.

## Section 1 — Where the settings live

One `SystemSetting` key, `label_printers`, holding a map from location id to
that location's connection. This is the shape `getArchiveConfig` /
`setArchiveConfig` already use for per-module settings, so it is a pattern this
codebase has rather than a new one:

```php
SystemSetting::labelPrinter(int $locationId): array   // defaults applied, always safe to read
SystemSetting::setLabelPrinter(int $locationId, array $printer): void
SystemSetting::labelPrinters(): array                 // the whole map, for the panel
```

Each entry holds `transport` (`tcp`, `share`, or empty), `host`, `port`,
`share` and `timeout`.

**The database is the only source.** The `.env` address keys are removed from
`.env.example` and no longer read. A single global address cannot serve several
warehouses, and falling back to one would send a location's labels to whatever
machine the file names — the wrong-building failure this work exists to remove.
Removing the fallback also removes a precedence rule, a "which source is in
effect" indicator, and the question of whether an empty saved value means "off"
or "unset". There is nothing to migrate: no `LABEL_` key is set in this
project's `.env` today.

`config/labelprinter.php` keeps the label geometry and nothing else.

**The label geometry does not move into the panel.** `width_mm`, `height_mm`
and `dpi` stay in config, because `frontend/src/admin/utils/barcodeLabel.js`
hardcodes the same numbers and the two must agree — the config file's own
docblock says so. An admin able to edit one but not the other would silently
make the PDF and the printer disagree about what a sticker is. The panel
displays the expected stock size read-only, so whoever orders labels can read
it without asking a developer.

## Section 2 — Which printer a job goes to

Resolved from **the location of the tags being printed**, not from the user.

A label exists to be stuck on a box, and the box is at that location. For a
warehouse clerk the two are the same thing — `forUser()` already means they can
only see their own location's tags — so this only differs for a centralized
admin, where it is the difference between labels appearing beside the stock and
labels appearing at head office.

In `LabelPrintController::print()`, after the policy filter has produced
`$printable`:

| Case | Result |
|---|---|
| one location | send to that location's printer |
| several locations | **422** — *"These 40 tags are at 2 locations. Filter to one location and print again."* |
| that location has no printer | **503** — *"No printer is set up for Warehouse B. Save the stickers as a PDF, or ask an admin to add one."* |

The location set is computed from `$printable` — what will actually print — and
never from the requested ids. A tag the policy has already dropped must not be
able to trigger a refusal for a batch that would otherwise have printed
cleanly.

Both refusals stamp nothing. This follows the rule the print history already
holds to: a job that never left must not leave a trail saying it did.

The 422 is actionable because the page it comes from has a Location filter. The
message says which control fixes it rather than only what is wrong.

## Section 3 — Endpoints

| Route | Access | Returns |
|---|---|---|
| `GET /system/label-printers` | authenticated | every location with its connection, whether configured, and the read-only stock size |
| `PUT /system/label-printers/{location}` | `role:admin` | validated save for one location |
| `POST /system/label-printers/{location}/test` | `role:admin` | `{ ok: bool, message: string }` |

The read returns **every location in the system**, not only the caller's,
because it backs an admin settings page whose job is to show which warehouses
still need a printer. It is authenticated rather than admin-only, matching the
existing `GET /system/session-settings`: a printer's address is not a secret,
and the same page already serves company and session settings on a plain
authenticated read.

A `PUT` or `test` for a location id that does not exist is a **404**. The panel
can only offer real locations, so an unknown one means a stale page or a
hand-made request, and silently creating a map entry for it would leave
settings nobody can see or remove.

Validation, per location:

- `transport` — `tcp`, `share`, or empty. Empty means "this location has no
  printer", which is a legitimate saved state and how an admin retires one.
- `host` — required when transport is `tcp`
- `port` — integer 1–65535, defaults to 9100
- `share` — required when transport is `share`
- `timeout` — numeric 1–30 seconds

`GET /api/barcode-labels/status` changes shape: it returns `configured` plus a
per-location list. Both are scoped the way the rest of this feature is — a
branch user sees their own location, a centralized user sees all — so
`configured` means "at least one printer among the locations whose tags this
user can print". The frontend shows **Send to printer** on `configured`.

## Section 4 — The reachability check

`LabelPrinterService::probe(int $locationId)` opens the socket, or checks the
share path is writable, and **sends nothing**. It answers "can I reach it"
without consuming a label or needing stock loaded — the state a printer is in
on the day it comes out of its box.

`POST /system/label-printers/{location}/test` always returns 200, with
`ok: false` and a message on failure, never a 5xx. An unreachable printer is an
answer, not a server error, and the panel renders it as information.

The messages are the deliverable, because the failure path is the one that gets
hit first:

- *"192.168.1.50:9100 did not answer — check the printer is switched on and on
  the same network as the server."*
- *"\\\\THIS-PC\\Zebra could not be written to — check the share exists and the
  server has permission."*
- *"No printer is set up for this location. Choose a connection type and save
  first."*

**No test-print button.** Once a location's settings are saved, **Send to
printer** works on the Barcode Printing page and prints a real tag in one
click. A second route to the same thing is a second thing to keep working, and
a test print needs loaded stock while a reachability check does not.

## Section 5 — The panel

A new `LabelPrinterPanel.jsx` rendering an `sc-card`, so it matches the
existing panels without growing `SystemConfiguration.jsx` — that file is 812
lines already, and the page is a list of independent cards, which is the shape
that should be composed rather than extended inline.

```
Barcode printers

  Warehouse A    Network · 192.168.1.50:9100        [ Edit ]  [ Test ]
  Warehouse B    Not set up                         [ Set up ]
  Main Office    Share · \\THIS-PC\Zebra            [ Edit ]  [ Test ]

  Label stock  100 × 25 mm at 203 dpi
  Order this size. Changing it needs a developer — the sticker
  layout is built around it.
```

Every location is listed, including those with no printer, so an admin can
prepare one before the hardware arrives.

Editing expands that row into the connection form. Address and port show only
for `tcp`, the share path only for `share`, and "Not set up" hides both and is
a valid save.

A test result renders inline beneath the row it belongs to and persists until
the next test or save. It is never a toast: the point is to read the message
and act on it.

## Section 6 — Testing

Every case runs without a printer, which is the point.

**Storage and service:**

- `labelPrinter()` returns usable defaults for a location with nothing stored,
  so a fresh install cannot crash on a missing key.
- Saving one location's printer leaves another location's alone — the map is
  merged, not replaced.
- `isConfigured()` is answered per location: configured at A, not configured at
  B.
- `probe()` against a closed port returns a failure message rather than
  throwing, asserted against `127.0.0.1` on a port nothing is listening on —
  exactly the shape of the first real attempt.

**Printing:**

- Tags at Warehouse A go to A's printer, not B's. The load-bearing case: a
  resolver that read the user's location, or the first configured printer,
  passes everything else here and fails this.
- A selection spanning two locations is refused with 422, and **nothing is
  stamped**.
- A location with no printer gives 503 naming that location, and **nothing is
  stamped**.
- The location set is computed after the policy filter: a batch holding one
  printable tag at A and one supplier code at B prints at A rather than being
  refused as mixed.

**Endpoints:**

- A non-admin cannot `PUT` or `test`; an unauthenticated request cannot `GET`.
- Validation rejects `transport=tcp` with no host, and `share` with no path.
- The test endpoint answers 200 with `ok: false` for an unreachable printer.

**Panel:**

- Every location renders, configured or not.
- Switching connection type swaps which fields show.
- Saving one location sends only that location.
- A failed test renders its message inline against the right row.

## Decisions and rejected alternatives

**One printer for the system.** The original shape of this design, and wrong as
soon as there are two warehouses: whoever is not beside the configured machine
prints into another building, silently. Per-location was briefly deferred as
"there is no printer yet"; the deferral was the mistake, because the storage
shape is what makes it cheap or expensive later.

**Resolving the printer from the user's location.** Simpler, and identical for
every warehouse clerk. Rejected because a centralized admin has no assigned
location and would lose direct printing entirely, and an admin who does have
one would print Warehouse A's labels at head office.

**Letting the user pick a printer per job.** Most flexible, and makes
deliberate cross-location printing possible. Rejected because it also makes
accidental cross-location printing possible on every single print, forever, to
buy a case nobody has asked for.

**Keeping `.env` as a fallback.** Defensible for one global printer; actively
harmful for several, since the fallback would be the wrong machine rather than
no machine. Removing it also deletes a precedence rule and an
empty-versus-unset ambiguity.

**Making the label geometry editable.** Tempting, since stock size is exactly
what might differ once a printer is bought. Rejected because `barcodeLabel.js`
hardcodes width and height for the on-screen and PDF labels, and every function
using them is synchronous and pure; server-driven geometry makes those async or
preloaded, which is a change to label rendering rather than to where the
address is kept.

**Warning "this location has no printer" before the click.** This page
otherwise states problems in advance — the mixed-tier warning does exactly
that. Rejected here because a selection can hold ids picked up by *select all
matching* whose rows are not on screen, so the check would be right only
sometimes. A pre-warning that is usually right is worse than a reliable message
at the moment of the click.

**A table instead of a JSON map.** More normalized, with a foreign key to
`locations`. Rejected for consistency: `archive_config` already stores
per-module settings as a map on `SystemSetting`, and a second mechanism for the
same shape of problem costs more than the orphaned-key risk it avoids. A
deleted location leaves an unread entry, which is inert.
