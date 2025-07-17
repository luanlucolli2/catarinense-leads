import { useState, useEffect } from "react";

/**
 * usePersistedState guarda o estado no localStorage sob a chave `key`.
 * @param key Chave única em localStorage.
 * @param defaultValue Valor inicial (se não existir nada armazenado).
 */
export function usePersistedState<T>(
  key: string,
  defaultValue: T
): [T, React.Dispatch<React.SetStateAction<T>>] {
  const [state, setState] = useState<T>(() => {
    if (typeof window === "undefined") {
      return defaultValue;
    }
    try {
      const stored = window.localStorage.getItem(key);
      return stored ? (JSON.parse(stored) as T) : defaultValue;
    } catch {
      return defaultValue;
    }
  });

  useEffect(() => {
    try {
      window.localStorage.setItem(key, JSON.stringify(state));
    } catch {
      // falhar silenciosamente (usuários sem localStorage, etc.)
    }
  }, [key, state]);

  return [state, setState];
}
