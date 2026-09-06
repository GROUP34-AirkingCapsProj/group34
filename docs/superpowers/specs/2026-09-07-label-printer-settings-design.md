# Label Printer Settings — Design

**Date:** 2026-09-07
**Status:** approved, not yet implemented
**Relates to:** `2026-09-06-barcode-printing-page-design.md`

## Problem

The barcode printer is addressed through `.env`:

```
LABEL_PRINTER_TRANSPORT=
LABEL_PRINTER_HOST=
LABEL_PRINTER_PORT=9100
LABEL_PRINTER_SHARE=
```

Changing it means editing a file on the server and restarting. Nobody in a
warehouse can do that. So the day the printer dies and a replacement arrives
with a different address, direct printing stays broken until a developer is
found — and the people holding the boxes are the ones who know the printer
changed.

There is no printer yet. This work exists so that when one arrives, setting it
up is a job for whoever runs the warehouse, and so that the day-one failures —
wrong address, printer switched off, wrong network — produce a sentence someone
can act on instead of a 503.

Nothing here is required for printing. The browser-built PDF route needs no
configuration and prints through the ordinary print dialog to any printer the
machine can see. What configuration buys is skipping that dialog, and recording
print history: the PDF route cannot be stamped, because the server never sees
it.

## Scope

In: the printer's address moved from `.env` to the database, editable by an
admin; a reachability check; a panel on the System Configuration page.

Out: the label geometry — width, height and dpi. See the decisions below.
Out: any change to how printing itself works. `LabelPrintController`,
`ZplLabelBuilder` and the two print routes are untouched; only where
`LabelPrinterService` reads its address changes.

## Section 1 — Where the setting lives

Five keys on `SystemSetting`, behind one typed accessor pair, exactly as
session and company settings already work:

```php
SystemSetting::labelPrinter(): array          // defaults applied, always safe to read
SystemSetting::setLabelPrinter(array $printer): void
```

Keys: `label_printer_transport`, `label_printer_host`, `label_printer_port`,
`label_printer_share`, `label_printer_timeout`.

`LabelPrinterService` reads the database first and falls back to
`config('labelprinter.*')` only when **no `label_printer_transport` row
exists**. The `.env` keys therefore keep working, which keeps `.env.example`
honest and lets a deployment be pre-seeded. There is nothing to migrate: no
`LABEL_` key is set in this project's `.env` today.

The rule is "has an admin ever saved this", not "is the saved value empty".
Those differ in the one case that matters: saving *Not set up* stores an empty
transport, and a fallback keyed on emptiness would read that as "nothing
stored" and resurrect the `.env` printer an admin had just turned off. Once the
row exists the database is authoritative, including when it says off.

`configured` and `source` answer different questions and both appear in the
response: `source` is where the settings came from — `database`, `env` or
`none` — while `configured` is whether those settings amount to a reachable
target, which is what makes the direct-print button appear.

**The label geometry does not move.** `width_mm`, `height_mm` and `dpi` keep
coming from `config('labelprinter.label.*')`, because
`frontend/src/admin/utils/barcodeLabel.js` hardcodes the same numbers and the
two must agree — the config file's own docblock says so. An admin who could
edit one but not the other would silently make the PDF and the printer disagree
about what a sticker is.

Two sources of truth is a real hazard, so the panel names the one in effect:
*In use: database*, *In use: .env*, or *Not configured*. A setting that appears
to have been saved but is being overridden is the failure this avoids.

## Section 2 — Endpoints

| Route | Access | Returns |
|---|---|---|
| `GET /system/label-printer` | authenticated | current settings, `configured`, `source`, and the read-only stock size |
| `PUT /system/label-printer` | `role:admin` | validated save, then the same shape as GET |
| `POST /system/label-printer/test` | `role:admin` | `{ ok: bool, message: string }` |

Read is authenticated rather than admin-only, matching the existing
`GET /system/session-settings`. The printer's address is not a secret, and the
same page already shows company and session settings on a plain authenticated
read.

Validation:

- `transport` — `tcp`, `share`, or empty (empty means "no printer", which
  disables direct printing and is a legitimate state, not an error)
- `host` — required when transport is `tcp`
- `port` — integer 1–65535, defaults to 9100
- `share` — required when transport is `share`
- `timeout` — numeric 1–30 seconds

## Section 3 — The reachability check

`LabelPrinterService::probe()` opens the socket, or checks the share path is
writable, and **sends nothing**. It answers "can I reach it" without consuming
a label or requiring stock to be loaded — which is the state a printer is in on
the day it comes out of its box.

`POST /system/label-printer/test` always returns 200 with `ok: false` and a
message on failure, never a 5xx. An unreachable printer is an answer, not a
server error, and the panel needs to render it as information.

The messages are the deliverable, because the failure path is the one that gets
hit first:

- *"192.168.1.50:9100 did not answer — check the printer is switched on and on
  the same network as the server."*
- *"\\\\THIS-PC\\Zebra could not be written to — check the share exists and the
  server has permission."*
- *"No printer is set up. Choose a connection type and save first."*

**No test-print button.** Once settings are saved, `printerReady` turns true
and **Send to printer** appears on the Barcode Printing page, which prints a
real tag in one click. A second route to the same thing is a second thing to
keep working.

## Section 4 — The panel

A new `LabelPrinterPanel.jsx` component rendering an `sc-card`, so it matches
the existing panels without growing `SystemConfiguration.jsx` — that file is
812 lines already, and the page is a list of independent cards, which is
exactly the shape that should be composed rather than extended inline.

```
Barcode printer                              In use: database

  Connection   [ Network (IP)          ▾ ]   Not set up · Network · Windows share
  Address      [ 192.168.1.50            ]
  Port         [ 9100                    ]
  Timeout      [ 5 ] seconds

  Label stock  100 × 25 mm at 203 dpi
  Order this size. Changing it needs a developer — the sticker
  layout is built around it.

  [ Test connection ]                            [ Save ]
```

The address and port fields show only for `tcp`; the share path only for
`share`. Choosing "Not set up" hides both and is a valid save — that is how an
admin turns direct printing off when a printer is retired.

The test result renders inline beneath the button, as text, and persists until
the next test or save. It is never a toast: the whole point is to read the
message and act on it.

## Section 5 — Testing

Every case below runs without a printer, which is the point.

**Backend:**

- `SystemSetting::labelPrinter()` returns usable defaults when nothing is
  stored, so a fresh install cannot crash on a missing key.
- A saved transport and host read back through `LabelPrinterService`.
- `isConfigured()` follows the database rather than the config file once a
  transport is stored — the load-bearing case, since everything else in the
  panel is inert if the service is still reading the old source.
- With nothing in the database, the service falls back to `config()`, so a
  pre-seeded `.env` deployment still prints.
- Saving *Not set up* over a populated `.env` leaves direct printing **off**.
  This is the case the emptiness-versus-existence rule exists for: a service
  that fell back on an empty stored value would switch the printer back on
  behind the admin who had just retired it.
- Validation rejects `transport=tcp` with no host, and `transport=share` with
  no share path.
- A non-admin cannot `PUT`; an unauthenticated request cannot `GET`.
- `probe()` against a closed port returns a failure message rather than
  throwing — asserted against `127.0.0.1` on a port nothing is listening on,
  which is exactly the shape of the first real attempt.
- The test endpoint answers 200 with `ok: false` for an unreachable printer.

**Frontend:**

- The panel renders the stored values and the source.
- Switching connection type swaps which fields are shown.
- Saving sends what is on screen and shows what came back.
- A failed test renders its message inline.

## Decisions and rejected alternatives

**Making the label geometry editable.** Tempting, since the stock size is
exactly what might differ once a printer is actually bought. Rejected because
`barcodeLabel.js` hardcodes width and height for the on-screen and PDF labels,
and every function that uses them is synchronous and pure. Making them
server-driven means those become async or preloaded, which is a change to the
label rendering itself rather than to where its address is kept. The panel
displays the expected stock size instead, so whoever orders labels can read it
without asking a developer.

**Database as the only source, deleting the `.env` keys.** Cleaner — one place,
no precedence rule to explain. Rejected because it removes the ability to
pre-seed a deployment and would make `.env.example` a lie. The precedence is
one line and the panel states which source won.

**`.env` overriding the database.** Rejected outright: an admin would save a
setting, see it accepted, and watch nothing change. Any precedence rule that
lets a file silently beat the UI produces exactly the bug this work exists to
remove.

**A test-print button.** Rejected as duplication: printing a real tag is one
click on the Barcode Printing page as soon as the settings save, and a test
print needs loaded stock while a reachability check does not.

**Per-location printers.** A branch warehouse and a main warehouse would each
have their own Zebra. Genuinely useful and deliberately out of scope: there is
no printer at all yet, and one address is the smaller thing that makes the
second one easy to add later — the accessor already returns an array, so a
location key can be threaded through it without reshaping callers.
