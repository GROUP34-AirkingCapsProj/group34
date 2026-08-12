import {
  reasonLabel,
  SUSPECTED_CAUSES, ROOT_CAUSES, RETURN_DIRECTIONS,
} from './incidentReasons';

/**
 * The client's INCIDENT REPORT FORM, rendered from one report.
 *
 * This is the paper document LGM Global Industries already works from, and the
 * order of the blocks below is theirs, not ours. It has to be printable BEFORE
 * the admin confirms: the inspector prints it, signs it, the branch manager
 * counter-signs, and the signed hardcopy is physically carried to the admin —
 * who needs it in hand to approve. A document only available after
 * confirmation would be useless to the person doing the confirming.
 *
 * Built as an HTML string opened in a print window, the same way
 * `poExport.js` produces the purchase order. Keeping it a pure function of the
 * report means it can be asserted on in Jest without a DOM or a print dialog.
 *
 * Evidence PHOTOS are deliberately not embedded. They live on a private disk
 * behind an authenticated route, so a popup window's <img> would 401 — and the
 * client's form asks only whether each requirement was met, which is what the
 * checklist prints.
 */

/* The letterhead, exactly as it appears on the client's form. */
const LETTERHEAD = {
  name: 'LGM GLOBAL INDUSTRIES INC.',
  address: 'Door 3, BLUZ Building, Tadeco Road, Panabo City, Davao del Norte',
  contact: 'airkingpanabo@gmail.com | (+63) 917 143 6394',
  branches: 'Branches: Panabo | Tagum | Davao | Cagayan de Oro | Cebu | Butuan | '
    + 'Pagadian | Kidapawan | Iligan | General Santos | Valencia',
};

const FORM_NOTE = 'This form should be completed whenever a damage defective unit '
  + '(i.e., broken/crack panel) or missing accessory occurs which in need approval '
  + 'from management.';

/**
 * The five Related Docs requirements in the client's order and their wording.
 *
 * The keys mirror App\Models\IncidentEvidence::REQUIREMENTS; the labels are the
 * client's, which read differently from the in-app checklist labels on purpose
 * — this document has to be recognisable as their form.
 */
export const RELATED_DOCS = [
  { key: 'defect_photo',         label: 'picture of defect' },
  { key: 'model_serial_sticker', label: 'picture of model/serial sticker' },
  { key: 'box_two_sides',        label: 'Two side of box including the serial sticker' },
  { key: 'accessories_warranty', label: 'Completed accessories picture including warranty card' },
  { key: 'asc_service_report',   label: 'Service report if checked by ASC' },
];

/**
 * Escape before interpolating. Every field here is free text somebody typed —
 * a narrative or a skip reason containing "<" would otherwise silently eat the
 * rest of the document.
 */
const esc = (value) => String(value ?? '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;');

/** A value for a form field, or the blank rule the client writes on by hand. */
const orBlank = (value) => {
  const text = typeof value === 'string' ? value.trim() : value;
  return text === null || text === undefined || text === '' ? '' : esc(text);
};

const fmtDate = (raw) => {
  if (!raw) return '';
  const d = new Date(raw);
  return Number.isNaN(d.getTime())
    ? esc(raw)
    : d.toLocaleDateString('en-PH', { month: '2-digit', day: '2-digit', year: 'numeric' });
};

const causeLabel = (options, value) =>
  options.find((o) => o.value === value)?.label || value;

/**
 * The defect line: the reason, plus whichever cause field that reason filled.
 * Only one of the three is ever set — the filing flow nulls the others — so
 * they are read in turn rather than branched on the reason a second time.
 */
export function unitDefectText(report) {
  const parts = [reasonLabel(report.reason)];
  if (report.root_cause) parts.push(causeLabel(ROOT_CAUSES, report.root_cause));
  if (report.suspected_cause) parts.push(causeLabel(SUSPECTED_CAUSES, report.suspected_cause));
  if (report.last_known_location) parts.push(`last seen: ${report.last_known_location}`);
  if (report.return_direction) parts.push(causeLabel(RETURN_DIRECTIONS, report.return_direction));
  if (report.return_reason) parts.push(report.return_reason);
  return parts.filter(Boolean).join(' — ');
}

const ROLE_LABEL = { indoor: 'Model ID', outdoor: 'Model OD', single: 'Model' };

/**
 * One printed unit row per `incident_items` row.
 *
 * `unit_role` decides which of the client's lines it is: a split system files
 * as an indoor unit and an outdoor unit on ONE report, and their form has a
 * line for each. The sticker fields are stored verbatim and are what the form
 * wants; the catalogue product name stands in only when the sticker was never
 * recorded, so a row never prints blank.
 */
export function printableUnits(report) {
  const items = Array.isArray(report.items) ? report.items : [];
  const dorRow = items.findIndex((i) => i.unit_role === 'indoor');
  return items.map((item, index) => ({
    label: ROLE_LABEL[item.unit_role] || 'Model',
    model: item.model_code || item.product_name || (item.product_id ? `#${item.product_id}` : ''),
    serial: item.serial_number || '',
    // The DOR belongs to the delivery, not to a unit, so it prints once — on
    // the indoor line the client's form puts it on, or on the first line when
    // the report has no indoor unit.
    dor: index === (dorRow === -1 ? 0 : dorRow) ? (report.received_at_dor || '') : null,
  }));
}

/**
 * Each requirement with what the report can show for it: photos attached, or a
 * skip WITH the reason given for it, or nothing at all.
 *
 * A skip is data, not an absence. Printing "Skipped" without the reason would
 * turn a documented impossibility ("the box was already discarded") into what
 * looks like a lazy inspection.
 */
export function relatedDocsStatus(report) {
  const rows = Array.isArray(report.evidence) ? report.evidence : [];
  return RELATED_DOCS.map((doc) => {
    const mine = rows.filter((e) => e.requirement === doc.key);
    const photos = mine.filter((e) => e.photo_path || e.photo_url).length;
    const skip = mine.find((e) => e.skipped_reason);
    return {
      ...doc,
      satisfied: photos > 0,
      photos,
      skippedReason: photos === 0 && skip ? skip.skipped_reason : null,
      provided: mine.length > 0,
    };
  });
}

/**
 * @param {object} report  the payload of GET /api/incident-reports/{id}
 * @returns {string} a complete printable HTML document
 */
export function buildIncidentReportHtml(report) {
  const r = report || {};

  const units = printableUnits(r);
  const unitRows = units.length === 0
    // Never collapse the block away: the signed paper must still show the two
    // ruled lines the client expects to write a model onto by hand.
    ? [{ label: 'Model', model: '', serial: '', dor: r.received_at_dor || '' }]
    : units;

  const unitsHtml = unitRows.map((u) => `
      <tr>
        <th>${esc(u.label)}</th>
        <td class="rule">${orBlank(u.model)}</td>
        <th>Serial</th>
        <td class="rule">${orBlank(u.serial)}</td>
        ${u.dor === null
    ? '<th class="blank"></th><td class="blank"></td>'
    : `<th>DOR</th><td class="rule">${fmtDate(u.dor)}</td>`}
      </tr>`).join('');

  const docsHtml = relatedDocsStatus(r).map((doc, index) => `
      <li>
        <span class="tick">${doc.satisfied ? '&#10003;' : '&#9633;'}</span>
        <span class="doc-label">${index + 1}. ${esc(doc.label)}</span>
        <span class="doc-status">${doc.satisfied
    ? `Provided (${doc.photos} photo${doc.photos === 1 ? '' : 's'})`
    : doc.skippedReason
      ? `Skipped &mdash; ${esc(doc.skippedReason)}`
      : 'Not provided'}</span>
      </li>`).join('');

  const narrative = [r.narrative, r.inspection_findings].filter(Boolean).join('\n\n');

  return `<!DOCTYPE html>
<html>
<head>
  <meta charset="UTF-8">
  <title>Incident Report - ${esc(r.incident_number || '')}</title>
  <style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'Times New Roman', Times, serif; font-size: 13px; color: #000; padding: 36px; max-width: 850px; margin: 0 auto; }
    .letterhead { text-align: center; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 14px; }
    .letterhead h1 { font-size: 22px; font-weight: 900; letter-spacing: 0.03em; }
    .letterhead p { font-size: 11px; color: #222; margin-top: 2px; }
    .doc-title { text-align: center; font-size: 17px; font-weight: 900; letter-spacing: 0.08em; margin: 10px 0 6px; }
    .form-note { font-style: italic; font-size: 11.5px; text-align: center; margin: 0 auto 14px; max-width: 640px; }
    .ref-line { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 10px; }
    .block { border: 1px solid #000; padding: 8px 10px; margin-bottom: 10px; }
    .block > .block-title { font-weight: 900; font-size: 12.5px; text-transform: uppercase; margin-bottom: 6px; }
    .kv { display: grid; grid-template-columns: 150px 1fr; gap: 3px 10px; font-size: 12.5px; }
    .kv .k { font-weight: 700; }
    .kv .v { border-bottom: 1px solid #999; min-height: 16px; }
    table.units { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    table.units th { text-align: left; font-weight: 700; padding: 4px 6px 4px 0; white-space: nowrap; }
    table.units td { padding: 4px 10px 4px 0; }
    table.units td.rule { border-bottom: 1px solid #999; min-width: 110px; }
    table.units .blank { border: 0; }
    .summary-note { font-size: 11.5px; font-style: italic; margin-bottom: 6px; }
    .narrative { border-bottom: 1px solid #999; min-height: 54px; white-space: pre-wrap; padding-bottom: 4px; }
    ul.docs { list-style: none; font-size: 12.5px; }
    ul.docs li { display: grid; grid-template-columns: 20px 1fr minmax(220px, 40%); gap: 6px; padding: 3px 0; border-bottom: 1px dotted #bbb; }
    ul.docs .tick { font-weight: 900; }
    ul.docs .doc-status { text-align: right; }
    .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 34px; }
    .sig-name { min-height: 20px; font-weight: 700; text-align: center; }
    .sig-rule { border-top: 1px solid #000; padding-top: 3px; font-size: 11.5px; text-align: center; }
    @media print { body { padding: 16px; } }
  </style>
</head>
<body>
  <div class="letterhead">
    <h1>${esc(LETTERHEAD.name)}</h1>
    <p>${esc(LETTERHEAD.address)}</p>
    <p>${esc(LETTERHEAD.contact)}</p>
    <p>${esc(LETTERHEAD.branches)}</p>
  </div>

  <div class="doc-title">INCIDENT REPORT FORM</div>
  <p class="form-note">${esc(FORM_NOTE)}</p>

  <div class="ref-line">
    <span>Report No.: <strong>${orBlank(r.incident_number)}</strong></span>
    <span>Branch: ${orBlank(r.location_name)}</span>
    <span>Date: ${fmtDate(r.occurred_at || r.created_at)}</span>
  </div>

  <div class="block">
    <div class="kv">
      <div class="k">Dealer Name</div><div class="v">${orBlank(r.dealer_name)}</div>
      <div class="k">Phone No, Mobile No, Email Add</div>
      <div class="v">${[r.dealer_phone, r.dealer_mobile, r.dealer_email].filter(Boolean).map(esc).join(' &nbsp;·&nbsp; ')}</div>
      <div class="k">Branch Address</div><div class="v">${orBlank(r.branch_address)}</div>
    </div>
  </div>

  <div class="block">
    <table class="units"><tbody>${unitsHtml}</tbody></table>
  </div>

  <div class="block">
    <div class="block-title">Unit Defect</div>
    <div class="narrative" style="min-height:24px">${orBlank(unitDefectText(r))}</div>
  </div>

  <div class="block">
    <div class="block-title">Problem Summary</div>
    <p class="summary-note">(We encourage for a detailed report and when and how the defect found)</p>
    <div class="kv" style="margin-bottom:6px">
      <div class="k">When/Date of Purchased</div><div class="v">${fmtDate(r.purchased_at)}</div>
      <div class="k">Who: Customer name</div><div class="v">${orBlank(r.customer_name)}</div>
      <div class="k">Contact number</div><div class="v">${orBlank(r.customer_contact)}</div>
      <div class="k">Site Address</div><div class="v">${orBlank(r.site_address)}</div>
    </div>
    <div class="k" style="font-weight:700;font-size:12.5px">What happened</div>
    <div class="narrative">${orBlank(narrative)}</div>
  </div>

  <div class="block">
    <div class="block-title">Related Docs Requirement</div>
    <ul class="docs">${docsHtml}</ul>
    ${r.checked_by_asc ? '<p style="font-size:11.5px;margin-top:4px">Checked by an authorised service centre (ASC).</p>' : ''}
  </div>

  <div class="signatures">
    <div>
      <div class="sig-name">${orBlank(r.signed_by_representative)}</div>
      <div class="sig-rule">Authorized Representative &amp; Signature / Date</div>
    </div>
    <div>
      <div class="sig-name">${orBlank(r.signed_by_branch_manager)}</div>
      <div class="sig-rule">Branch Manager Name &amp; Signature / Date</div>
    </div>
  </div>
</body>
</html>`;
}

/**
 * Open the form in a print window. Same shape as `exportPOToDocument`: the
 * delay lets the popup lay the document out before the print dialog measures
 * it, which is what stops a blank first page.
 */
export function printIncidentReport(report) {
  const html = buildIncidentReportHtml(report);
  const win = window.open('', '_blank', 'width=920,height=760');
  if (!win) {
    alert('Please allow pop-ups to print the incident report form.');
    return;
  }
  win.document.write(html);
  win.document.close();
  win.focus();
  setTimeout(() => win.print(), 600);
}
