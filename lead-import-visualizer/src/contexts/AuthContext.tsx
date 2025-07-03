// Em src/contexts/AuthContext.tsx

import { createContext, useContext, useState, ReactNode } from 'react';

// Definindo os tipos para o contexto
interface AuthContextType {
    user: any | null;
    token: string | null;
    setUser: (user: any | null) => void;
    setToken: (token: string | null) => void;
}

// Criando o contexto com um valor padrão
const AuthContext = createContext<AuthContextType>({
    user: null,
    token: null,
    setUser: () => { },
    setToken: () => { },
});

// Criando o "Provedor" do contexto
export const AuthProvider = ({ children }: { children: ReactNode }) => {
    const [user, _setUser] = useState<any | null>(JSON.parse(localStorage.getItem('USER') || 'null'));
    const [token, _setToken] = useState<string | null>(localStorage.getItem('AUTH_TOKEN'));

    const setToken = (newToken: string | null) => {
        _setToken(newToken);
        if (newToken) {
            localStorage.setItem('AUTH_TOKEN', newToken);
        } else {
            localStorage.removeItem('AUTH_TOKEN');
        }
    };

    const setUser = (newUser: any | null) => {
        _setUser(newUser);
        if (newUser) {
            localStorage.setItem('USER', JSON.stringify(newUser));
        } else {
            localStorage.removeItem('USER');
        }
    }

    return (
        <AuthContext.Provider value={{ user, token, setUser, setToken }}>
            {children}
        </AuthContext.Provider>
    );
};

// Criando um hook customizado para usar o contexto facilmente
export const useAuth = () => {
    return useContext(AuthContext);
};