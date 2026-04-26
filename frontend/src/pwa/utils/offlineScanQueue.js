const STORAGE_KEY = 'airking_pwa_pending_scans';

function readQueue() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function writeQueue(items) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
}

/**
 * @param {{ barcode: string, createdAt: string }} item
 */
export function enqueuePendingScan(item) {
  const q = readQueue();
  q.push({ ...item, id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}` });
  writeQueue(q);
}

export function listPendingScans() {
  return readQueue();
}

export function clearPendingScans() {
  localStorage.removeItem(STORAGE_KEY);
}

export function removePendingScan(id) {
  writeQueue(readQueue().filter((x) => x.id !== id));
}
