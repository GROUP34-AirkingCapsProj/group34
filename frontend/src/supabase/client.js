import { createClient } from '@supabase/supabase-js';

const url = process.env.REACT_APP_SUPABASE_URL?.trim();
const anonKey = process.env.REACT_APP_SUPABASE_ANON_KEY?.trim();

let singleton = null;

/**
 * Shared Supabase browser client (anon key + RLS). Returns null if env is missing.
 */
export function getSupabaseClient() {
  if (!url || !anonKey) return null;
  if (!singleton) {
    singleton = createClient(url, anonKey, {
      auth: {
        persistSession: true,
        autoRefreshToken: true,
        detectSessionInUrl: true,
        storage: typeof window !== 'undefined' ? window.localStorage : undefined,
      },
    });
  }
  return singleton;
}

export function isSupabaseConfigured() {
  return Boolean(url && anonKey);
}
