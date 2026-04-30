import { createContext, useContext, useState, useCallback } from 'react';
import { removeToken } from '../services/api';

const AuthContext = createContext(null);

const readStoredUser = () => {
  try {
    return JSON.parse(sessionStorage.getItem('user') || 'null');
  } catch {
    return null;
  }
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(readStoredUser);

  const updateUser = useCallback((userData) => {
    setUser(userData);
    if (userData) {
      sessionStorage.setItem('user', JSON.stringify(userData));
    } else {
      sessionStorage.removeItem('user');
    }
  }, []);

  const clearSession = useCallback(() => {
    updateUser(null);
    removeToken();
  }, [updateUser]);

  return (
    <AuthContext.Provider value={{ user, updateUser, clearSession }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
};
