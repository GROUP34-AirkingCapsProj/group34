import React from 'react';

/**
 * The dealer and customer details that print on the client's copy of the
 * incident report form.
 *
 * Filled by the REPORTER, not the inspector — the reporter is the one who knows
 * which dealer the unit went out through and whose site it is sitting on. By
 * the time an inspector opens it, that trail is a phone call away.
 *
 * Shown only for `defective` and `return`, the two outcomes that involve a
 * customer at all. A picker filing a theft must never be asked for a customer's
 * site address; there isn't one, and asking teaches people the form is full of
 * fields to ignore.
 *
 * Every field is optional, deliberately. A reporter genuinely may not know the
 * date of purchase, and blocking Next on it does not produce the date — it
 * produces "n/a" typed into a field that then prints on a client's document.
 */
export default function ClientFormBlock({
  dealerName, setDealerName,
  dealerPhone, setDealerPhone,
  dealerMobile, setDealerMobile,
  dealerEmail, setDealerEmail,
  branchAddress, setBranchAddress,
  customerName, setCustomerName,
  customerContact, setCustomerContact,
  siteAddress, setSiteAddress,
  purchasedAt, setPurchasedAt,
  receivedAtDor, setReceivedAtDor,
  disabled,
}) {
  return (
    <>
      <div className="ak-muted" style={{ fontSize: '0.84rem' }}>
        These print on the client&apos;s copy of the incident report form. Fill in
        what you know — all of it is optional, and a reviewer can chase the rest.
      </div>

      <fieldset
        className="ak-card"
        style={{ border: '1px solid var(--ak-border, #ddd)', padding: '0.75rem', margin: 0 }}
      >
        <legend className="ak-label" style={{ padding: '0 0.35rem' }}>Dealer</legend>

        <Text id="incident-dealer-name" label="Dealer name"
          value={dealerName} onChange={setDealerName} disabled={disabled} />

        <div style={{ display: 'flex', gap: '0.6rem', flexWrap: 'wrap' }}>
          <Text id="incident-dealer-phone" label="Phone" grow
            value={dealerPhone} onChange={setDealerPhone} disabled={disabled} />
          <Text id="incident-dealer-mobile" label="Mobile" grow
            value={dealerMobile} onChange={setDealerMobile} disabled={disabled} />
        </div>

        <Text id="incident-dealer-email" label="Email" type="email"
          value={dealerEmail} onChange={setDealerEmail} disabled={disabled} />
        <Text id="incident-branch-address" label="Branch address" textarea
          value={branchAddress} onChange={setBranchAddress} disabled={disabled} />
      </fieldset>

      <fieldset
        className="ak-card"
        style={{ border: '1px solid var(--ak-border, #ddd)', padding: '0.75rem', margin: 0 }}
      >
        <legend className="ak-label" style={{ padding: '0 0.35rem' }}>Customer and site</legend>

        <div style={{ display: 'flex', gap: '0.6rem', flexWrap: 'wrap' }}>
          <Text id="incident-customer-name" label="Customer name" grow
            value={customerName} onChange={setCustomerName} disabled={disabled} />
          <Text id="incident-customer-contact" label="Contact number" grow
            value={customerContact} onChange={setCustomerContact} disabled={disabled} />
        </div>

        <Text id="incident-site-address" label="Site address" textarea
          value={siteAddress} onChange={setSiteAddress} disabled={disabled} />

        <div style={{ display: 'flex', gap: '0.6rem', flexWrap: 'wrap' }}>
          <Text id="incident-purchased-at" label="Date of purchase" type="date" grow
            value={purchasedAt} onChange={setPurchasedAt} disabled={disabled} />
          <Text id="incident-received-dor" label="Date received (DOR)" type="date" grow
            value={receivedAtDor} onChange={setReceivedAtDor} disabled={disabled} />
        </div>
      </fieldset>
    </>
  );
}

/** One labelled field. Ten near-identical blocks is what this saves. */
function Text({ id, label, value, onChange, type = 'text', textarea = false, grow = false, disabled }) {
  return (
    <div className="ak-field" style={grow ? { flex: '1 1 180px' } : undefined}>
      <label className="ak-label" htmlFor={id}>{label}</label>
      {textarea ? (
        <textarea
          id={id}
          className="ak-input"
          rows={2}
          maxLength={255}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
        />
      ) : (
        <input
          id={id}
          className="ak-input"
          type={type}
          maxLength={150}
          value={value}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
        />
      )}
    </div>
  );
}
