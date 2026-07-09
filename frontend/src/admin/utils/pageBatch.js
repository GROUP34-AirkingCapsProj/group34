/**
 * Batched pagination math.
 *
 * Instead of rendering every page number (which overflows the controls once a
 * table has many pages), we show one fixed-size *batch* of numbers at a time:
 * pages 1–5, then 6–10, and so on. The single Prev/Next steps one page; the
 * double «/» jumps to the previous/next batch so the visible window advances.
 *
 * @param {number} currentPage 1-based current page
 * @param {number} totalPages   total page count
 * @param {number} batchSize    how many numbers to show at once (default 5)
 */
export const PAGE_BATCH_SIZE = 5;

export function getPageBatch(currentPage, totalPages, batchSize = PAGE_BATCH_SIZE) {
  const total = Math.max(totalPages || 1, 1);
  const page = Math.min(Math.max(currentPage || 1, 1), total);

  const batchIndex = Math.floor((page - 1) / batchSize); // 0-based
  const start = batchIndex * batchSize + 1;
  const end = Math.min(start + batchSize - 1, total);

  const pages = [];
  for (let i = start; i <= end; i++) pages.push(i);

  return {
    pages,
    hasPrevBatch: start > 1,
    hasNextBatch: end < total,
    // Land on the first page of the neighbouring batch so the window is clean.
    prevBatchPage: Math.max(start - batchSize, 1),
    nextBatchPage: Math.min(end + 1, total),
  };
}
