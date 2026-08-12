import React from 'react';
import { printIncidentReport } from '../../utils/incidentReportDocument';

/**
 * The button that produces the client's INCIDENT REPORT FORM.
 *
 * It lives in two places on purpose. The inspector prints it at the end of the
 * inspection, signs it and has the branch manager counter-sign; the admin, who
 * receives that hardcopy, can reprint the same document from the decision
 * drawer to check the paper in front of them against the record. Both call the
 * one builder, so the two printouts cannot drift apart.
 *
 * `overrides` exists for the inspection screen: the findings and the signature
 * names are local state there until the inspection is submitted, and printing a
 * form with those boxes blank — seconds after typing them — would send the
 * inspector to the pen for fields the system already knows.
 *
 * Props:
 *   report    {object|null}  the payload of GET /api/incident-reports/{id}
 *   overrides {object}       fields to print in place of the saved ones
 *   label     {string}
 *   className {string}
 */
export default function IncidentReportPrint({
  report, overrides, label = 'Print incident report', className = 'ak-btn',
}) {
  return (
    <button
      type="button"
      className={className}
      disabled={!report}
      onClick={() => printIncidentReport({ ...report, ...(overrides || {}) })}
      title="Print the client's form for signing, then carry the signed copy to the admin"
    >
      {label}
    </button>
  );
}
