import { createContext, useContext, useState, ReactNode } from 'react';

// 1. A interface agora só precisa do usuário
interface AuthContextType {
    user: any | null;
    setUser: (user: any | null) => void;
}

// 2. O contexto padrão também é simplificado
const AuthContext = createContext<AuthContextType>({
    user: null,
    setUser: () => { },
});

// 3. O Provedor agora só gerencia o estado do usuário
export const AuthProvider = ({ children }: { children: ReactNode }) => {
    const [user, _setUser] = useState<any | null>(() => {
        try {
            const storedUser = localStorage.getItem('USER');
            return storedUser ? JSON.parse(storedUser) : null;
        } catch (e) {
            return null;
        }
    });

    const setUser = (newUser: any | null) => {
        _setUser(newUser);
        if (newUser) {
            localStorage.setItem('USER', JSON.stringify(newUser));
        } else {
            localStorage.removeItem('USER');
        }
    };

    // 4. O valor fornecido ao resto da aplicação agora é mais limpo
    return (
        <AuthContext.Provider value={{ user, setUser }}>
            {children}
        </AuthContext.Provider>
    );
};

// O hook customizado não muda
export const useAuth = () => {
    return useContext(AuthContext);
};
