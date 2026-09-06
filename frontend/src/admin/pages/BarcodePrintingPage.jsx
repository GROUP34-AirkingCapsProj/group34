import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import AdminLayout from '../components/AdminLayout';
import Pagination from '../components/Pagination';
import TagCard from '../components/tags/TagCard';
import TagFilterBar from '../components/tags/TagFilterBar';
import TagSelectionBar from '../components/tags/TagSelectionBar';
import BarcodeZoom from '../components/tags/BarcodeZoom';
import { useUserLocation } from '../context/AuthContext';
import { useLabelPrinter } from '../hooks/useLabelPrinter';
import { useTagSelection } from '../hooks/useTagSelection';
import { barcodeLabelsAPI } from '../services/api';
import { toast } from '../utils/toast';
import '../styles/barcode_printing.css';

/**
 * Print labels for stock that has been received.
 *
 * Every identity a receipt produced shows up here — the tags we minted, the
 * supplier's own codes, and appliance serials from the manufacturer — because
 * a clerk holding an unreadable label needs to find it whoever printed it
 * first. Nothing on this page mints an identifier; see TagPrintPolicy on the
 * server for what may be reprinted and why the two modes differ.
 *
 * Filters live in the URL. A print run is long enough that someone will
 * reload, hand the screen over, or send the link to whoever is at the
 * printer, and all three should land on the same list.
 */

const PER_PAGE = 24;

const FILTER_KEYS = [
  'product_type', 'date_from', 'date_to',
  'location_id', 'receipt', 'q', 'print_state',
];

export default function BarcodePrintingPage() {
  const [searchParams, setSearchParams] = useSearchParams();

  const filters = useMemo(() => {
    const current = {};
    FILTER_KEYS.forEach((key) => {
      const value = searchParams.get(key);
      if (value) current[key] = value;
    });
    return current;
  }, [searchParams]);

  const page = Number(searchParams.get('page') || 1);

  const [rows, setRows] = useState([]);
  const [pagination, setPagination] = useState(null);
  const [counts, setCounts] = useState(null);
  const [loading, setLoading] = useState(true);
  const [zoomedTag, setZoomedTag] = useState(null);
  const [truncatedTotal, setTruncatedTotal] = useState(0);

  /* LocationSelector fetches nothing — without this list it degrades to a
     bare number box, which is a worse filter than none. */
  const { allowedLocations } = useUserLocation();
  const { printerReady, sending, sendToPrinter, savePdf } = useLabelPrinter();
  const selection = useTagSelection();

  useEffect(() => {
    let active = true;
    setLoading(true);

    barcodeLabelsAPI.queue(filters, page, PER_PAGE)
      .then((res) => {
        if (!active) return;
        setRows(res?.data || []);
        setPagination(res?.pagination || null);
        setCounts(res?.counts || null);
      })
      .catch((e) => {
        if (active) toast.error(e?.message || 'Could not load the tags.');
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [filters, page]);

  /* Filters and the page number share the query string, so a filter change
     must reset the page — page 7 of a narrower list is usually empty. */
  const applyFilters = useCallback((next) => {
    const params = new URLSearchParams();

    FILTER_KEYS.forEach((key) => {
      if (next[key]) params.set(key, next[key]);
    });

    setSearchParams(params);
  }, [setSearchParams]);

  const goToPage = useCallback((nextPage) => {
    const params = new URLSearchParams(searchParams);
    params.set('page', String(nextPage));
    setSearchParams(params);
  }, [searchParams, setSearchParams]);

  /* Counted from what the server said about each row, not from the tier
     string: bulk_printable IS the server's answer, and a second opinion
     computed here could disagree with what actually prints. Only rows on
     screen can be counted — ids picked up by "select all" are not held as
     rows — so a select-all selection reports what this page can see. */
  const ineligibleCount = useMemo(() => rows.filter(
    (row) => selection.isSelected(row.serial_id) && !row.bulk_printable,
  ).length, [rows, selection]);

  const selectedTags = useMemo(
    () => selection.selectedIds.map((id) => ({ serial_id: id })),
    [selection.selectedIds],
  );

  const handleSelectAll = useCallback(async () => {
    try {
      const res = await barcodeLabelsAPI.queueIds(filters);
      const ids = res?.data?.serial_ids || [];

      selection.selectAll(ids);
      setTruncatedTotal(res?.data?.truncated ? res.data.total : 0);
    } catch (e) {
      toast.error(e?.message || 'Could not select the matching tags.');
    }
  }, [filters, selection]);

  const handleClear = useCallback(() => {
    selection.clear();
    setTruncatedTotal(0);
  }, [selection]);

  const handleReprint = useCallback(async (tag) => {
    try {
      const res = await barcodeLabelsAPI.print([tag.serial_id], 'single');
      toast.success(res?.message || 'Sent to the printer.');
    } catch (e) {
      toast.error(e?.message || 'The printer could not be reached. Save it as a PDF instead.');
    }
  }, []);

  return (
    <AdminLayout>
      <div className="bpp-page">
        <header className="bpp-header no-print">
          <h1>Barcode Printing</h1>
          <p className="bpp-subtitle">
            Every code recorded when stock came in. Scan one off the screen, or print a
            replacement when the label on the box is damaged.
          </p>
        </header>

        <TagFilterBar
          filters={filters}
          onChange={applyFilters}
          counts={counts}
          allowedLocations={allowedLocations}
        />

        <TagSelectionBar
          count={selection.count}
          ineligibleCount={ineligibleCount}
          truncatedTotal={truncatedTotal}
          printerReady={printerReady}
          sending={sending}
          onSelectAll={handleSelectAll}
          onClear={handleClear}
          onSend={() => sendToPrinter(selectedTags, 'bulk')}
          onSavePdf={() => savePdf(rows.filter((row) => selection.isSelected(row.serial_id)))}
        />

        {loading && <p className="bpp-status">Loading tags…</p>}

        {!loading && rows.length === 0 && (
          <p className="bpp-status">No tags match these filters.</p>
        )}

        <div className="bpp-grid">
          {rows.map((row) => (
            <TagCard
              key={row.serial_id}
              tag={row}
              selected={selection.isSelected(row.serial_id)}
              onToggle={selection.toggle}
              onZoom={setZoomedTag}
              onReprint={handleReprint}
            />
          ))}
        </div>

        {pagination && (
          <Pagination
            currentPage={pagination.current_page}
            totalPages={pagination.last_page}
            totalItems={pagination.total}
            pageSize={pagination.per_page}
            onPageChange={goToPage}
            label="tags"
          />
        )}

        {zoomedTag && (
          <BarcodeZoom
            value={zoomedTag.serial_number}
            label={zoomedTag.product_name}
            onClose={() => setZoomedTag(null)}
          />
        )}
      </div>
    </AdminLayout>
  );
}
