import React, { useState } from 'react';
import { resolveStorageUrl } from '../services/api';

export default function ProfilePhoto({ src, fallback, onError, ...props }) {
  const [failedSource, setFailedSource] = useState(null);
  const source = resolveStorageUrl(src);
  if (!source || source === failedSource) return fallback;
  return <img {...props} src={source} onError={() => {
    setFailedSource(source);
    onError?.();
  }} />;
}
