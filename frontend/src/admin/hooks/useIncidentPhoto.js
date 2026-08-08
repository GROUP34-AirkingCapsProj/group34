import { useCallback, useState } from 'react';
import { incidentReportsAPI } from '../services/api';

/**
 * Holds an incident's evidence photo and how it arrived, and uploads it.
 *
 * Both incident modals previously duplicated this, and had already drifted
 * (`?? null` in one, `|| null` in the other). Adding a camera and a provenance
 * field would have tripled that, so it lives here once.
 *
 * Returns { file, source, previewUrl, setFromCamera, setFromFile, clear, uploadIfAny }.
 * uploadIfAny() resolves to { photo_path, photo_source }, both null when there
 * is no photo, and THROWS if an upload returns no path — filing an incident
 * without the evidence the user attached, while reporting success, loses it
 * silently.
 */
export function useIncidentPhoto() {
  const [file, setFile] = useState(null);
  const [source, setSource] = useState(null);   // 'captured' | 'uploaded' | null
  const [previewUrl, setPreviewUrl] = useState(null);

  const replace = useCallback((nextFile, nextSource) => {
    // The updater form revokes whatever it displaces, so the caller never has
    // to think about object URL lifetime.
    setPreviewUrl((old) => {
      if (old) URL.revokeObjectURL(old);
      return nextFile ? URL.createObjectURL(nextFile) : null;
    });
    setFile(nextFile);
    setSource(nextFile ? nextSource : null);
  }, []);

  const setFromCamera = useCallback((f) => replace(f, 'captured'), [replace]);
  const setFromFile   = useCallback((f) => replace(f || null, 'uploaded'), [replace]);
  const clear         = useCallback(() => replace(null, null), [replace]);

  const uploadIfAny = useCallback(async () => {
    if (!file) return { photo_path: null, photo_source: null };

    const up = await incidentReportsAPI.uploadPhoto(file);
    const path = up?.data?.path || null;
    // Fail loudly rather than filing without the evidence the user attached
    // and still reporting success.
    if (!path) throw new Error('The photo upload did not return a path. Nothing was filed.');

    return { photo_path: path, photo_source: source };
  }, [file, source]);

  return { file, source, previewUrl, setFromCamera, setFromFile, clear, uploadIfAny };
}
