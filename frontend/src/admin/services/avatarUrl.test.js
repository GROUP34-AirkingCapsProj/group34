import { getApiBaseUrl, resolveStorageUrl } from './api';

jest.mock('../../runtimeEnv', () => ({ readEnv: () => undefined }));

test('loads avatar images through the API while preserving other storage and external URLs', () => {
  const api = getApiBaseUrl();
  expect(resolveStorageUrl('/storage/avatars/photo.png')).toBe(`${api}/avatars/photo.png`);
  expect(resolveStorageUrl('storage/avatars/photo.webp')).toBe(`${api}/avatars/photo.webp`);
  expect(resolveStorageUrl('/storage/receipts/file.pdf')).toBe(`${api.replace(/\/api$/, '')}/storage/receipts/file.pdf`);
  expect(resolveStorageUrl('https://example.com/photo.png')).toBe('https://example.com/photo.png');
  expect(resolveStorageUrl(null)).toBeNull();
});
