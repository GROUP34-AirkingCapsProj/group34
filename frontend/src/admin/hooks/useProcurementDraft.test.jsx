import { act, renderHook } from '@testing-library/react';
import { useState } from 'react';
import { useProcurementDraft } from './useProcurementDraft';

const useEditor = (userId = 7, type = 'pr') => {
  const [purpose, setPurpose] = useState('');
  const [rows, setRows] = useState([]);
  const draft = useProcurementDraft(userId, type, { purpose: [purpose, setPurpose], rows: [rows, setRows] });
  return { purpose, rows, setPurpose, setRows, draft };
};

beforeEach(() => localStorage.clear());

test('restores incomplete inputs after leaving and reopening the editor', () => {
  const first = renderHook(() => useEditor());
  act(() => { first.result.current.setPurpose('Urgent restock'); first.result.current.setRows([{ qty: '' }]); });
  first.unmount();
  const next = renderHook(() => useEditor());
  expect(next.result.current.purpose).toBe('Urgent restock');
  expect(next.result.current.rows).toEqual([{ qty: '' }]);
  expect(next.result.current.draft.status).toContain('restored');
});

test('isolates users and document types', () => {
  const first = renderHook(() => useEditor());
  act(() => first.result.current.setPurpose('Private draft'));
  expect(renderHook(() => useEditor(8)).result.current.purpose).toBe('');
  expect(renderHook(() => useEditor(7, 'po')).result.current.purpose).toBe('');
});

test('clears a completed or discarded draft and saves subsequent edits', () => {
  const editor = renderHook(() => useEditor());
  act(() => editor.result.current.setPurpose('First request'));
  act(() => editor.result.current.draft.clear(true));
  expect(editor.result.current.purpose).toBe('');
  expect(localStorage.getItem('procurement-draft:v1:7:pr')).toBeNull();
  act(() => editor.result.current.setPurpose('Next request'));
  expect(JSON.parse(localStorage.getItem('procurement-draft:v1:7:pr')).form.purpose).toBe('Next request');
});

test('ignores malformed drafts and reports unavailable storage', () => {
  localStorage.setItem('procurement-draft:v1:7:pr', '{invalid');
  const editor = renderHook(() => useEditor());
  expect(editor.result.current.purpose).toBe('');
  const spy = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('Quota exceeded'); });
  act(() => editor.result.current.setPurpose('Unsaved input'));
  expect(editor.result.current.draft.status).toContain('could not be saved');
  expect(editor.result.current.purpose).toBe('Unsaved input');
  spy.mockRestore();
});
