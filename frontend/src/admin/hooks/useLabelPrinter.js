import { useCallback, useEffect, useState } from 'react';
import { barcodeLabelsAPI } from '../services/api';
import { printBarcodeLabels } from '../utils/printBarcodeLabels';
import { toast } from '../utils/toast';

/**
 * The two ways a sticker reaches paper.
 *
 * `sendToPrinter` goes straight to the Zebra: the server holds the connection
 * and sends ZPL, because no browser can pick a printer or skip the print
 * dialog. `savePdf` builds the same sticker as a PDF whose page size IS the
 * label, for when there is no printer wired up or someone wants a copy.
 *
 * `printerReady` says whether a printer is configured at all, so the UI can
 * offer a button that works rather than one that always errors.
 */

/* Asked once per page load, not once per tag sheet — the answer only changes
   when someone edits the server's config. */
let statusPromise = null;

/** Clears the cached answer. For tests, which need each case to start cold. */
export const resetLabelPrinterStatus = () => { statusPromise = null; };

const fetchPrinterStatus = () => {
  if (!statusPromise) {
    statusPromise = barcodeLabelsAPI.status()
      .then((res) => Boolean(res?.data?.configured))
      .catch(() => false);
  }
  return statusPromise;
};

export function useLabelPrinter() {
  const [printerReady, setPrinterReady] = useState(false);
  const [sending, setSending] = useState(false);

  useEffect(() => {
    let active = true;
    fetchPrinterStatus().then((ready) => {
      if (active) setPrinterReady(ready);
    });
    return () => { active = false; };
  }, []);

  const sendToPrinter = useCallback(async (tags) => {
    /* The printer is told which tags by id, so a payload that carries only
       serial numbers cannot reach it. Silence here would look like a dead
       button; the PDF still works and says so. */
    const ids = (tags || []).map((tag) => tag?.serial_id).filter((id) => id != null);
    if (ids.length === 0) {
      toast.error('These tags cannot be sent to the printer. Save them as a PDF instead.');
      return;
    }

    setSending(true);
    try {
      const res = await barcodeLabelsAPI.print(ids);
      toast.success(res?.message || 'Sent to the printer.');
    } catch (e) {
      /* The printer being off is not a broken button. Say what happened and
         point at the way that still works. */
      toast.error(e?.message || 'The printer could not be reached. Save the stickers as a PDF instead.');
    } finally {
      setSending(false);
    }
  }, []);

  const savePdf = useCallback((tags, productName) => {
    printBarcodeLabels(tags, productName, { onNotice: toast.info });
  }, []);

  return { printerReady, sending, sendToPrinter, savePdf };
}

export default useLabelPrinter;
