import { createContext, useContext, useEffect, useMemo, useState } from 'react';
import { getSupabaseClient, isSupabaseConfigured } from '../../supabase/client';

const SupabaseAuthContext = createContext(null);

export function SupabaseAuthProvider({ children }) {
  const [session, setSession] = useState(null);
  const [loading, setLoading] = useState(true);
  const configured = isSupabaseConfigured();
  const client = useMemo(() => (configured ? getSupabaseClient() : null), [configured]);

  useEffect(() => {
    if (!client) {
      setSession(null);
      setLoading(false);
      return undefined;
    }
    let cancelled = false;
    client.auth.getSession().then(({ data: { session: s } }) => {
      if (!cancelled) {
        setSession(s);
        setLoading(false);
      }
    });
    const {
      data: { subscription },
    } = client.auth.onAuthStateChange((_event, s) => {
      setSession(s);
    });
    return () => {
      cancelled = true;
      subscription.unsubscribe();
    };
  }, [client]);

  const value = useMemo(
    () => ({
      client,
      configured,
      session,
      loading,
      user: session?.user ?? null,
      signIn: async (email, password) => {
        if (!client) return { error: new Error('Supabase is not configured.') };
        return client.auth.signInWithPassword({ email, password });
      },
      signUp: async (email, password, fullName) => {
        if (!client) return { error: new Error('Supabase is not configured.') };
        return client.auth.signUp({
          email,
          password,
          options: { data: { full_name: fullName || '' } },
        });
      },
      signOut: async () => {
        if (!client) return;
        await client.auth.signOut();
      },
    }),
    [client, configured, session, loading]
  );

  return <SupabaseAuthContext.Provider value={value}>{children}</SupabaseAuthContext.Provider>;
}

export function useSupabaseAuth() {
  const ctx = useContext(SupabaseAuthContext);
  if (!ctx) throw new Error('useSupabaseAuth must be used under SupabaseAuthProvider');
  return ctx;
}
