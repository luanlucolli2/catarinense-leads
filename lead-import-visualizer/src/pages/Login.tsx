import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import catarinenselogo from "../../public/catainenseLogo.png";
import axiosClient from "@/api/axiosClient";
import { useAuth } from "@/contexts/AuthContext";
import { Loader2 } from "lucide-react"; // <- import do spinner
import http from "@/api/http";           // <= Importa o cliente genérico

const Login = () => {
  const navigate = useNavigate();
  const { setUser } = useAuth();

  const [formData, setFormData] = useState({
    email: "teste@catarinense.com",
    password: "password",
  });
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setFormData(prev => ({ ...prev, [name]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setError(null);

     try {
    // 1. Usar o cliente GENÉRICO para o handshake do Sanctum (sem /api)
    await http.get('/sanctum/csrf-cookie');

    // 2. Usar o cliente da API para o login (já tem /api na base)
    // A chamada é para '/login', o resultado final será '/api/login'
    const response = await axiosClient.post('/login', formData);

    const { user } = response.data;
    setUser(user);
    
    toast.success("Login realizado com sucesso!");
    navigate("/");

  } catch (err: any) {
      setIsLoading(false);
      // O erro 419 também cairá aqui. Podemos tratá-lo.
      if (err.response?.status === 419) {
          setError("Sua sessão expirou. Por favor, tente novamente.");
          toast.error("Sessão expirada. Tente fazer o login de novo.");
      } else if (err.response?.status === 422) {
        const msg = err.response.data.errors.email[0];
        setError(msg);
        toast.error(msg);
      } else {
        const errorMsg = err.response?.data?.message || "Ocorreu um erro. Verifique sua conexão.";
        setError(errorMsg);
        toast.error(errorMsg);
      }
    }
  };

  return (
    <div
      className="min-h-screen flex items-center justify-center p-6"
      style={{
        background:
          "radial-gradient(circle at center, #1f2937 0%, #111827 80%)"
      }}
    >
      <Card className="
        w-full max-w-md
        bg-gradient-to-tl from-gray-800 to-gray-700
        border border-gray-600
        shadow-2xl shadow-black/60
      ">
        <CardHeader className="pb-5 border-b border-gray-600">
          <div className="flex justify-center">
            <img
              src={catarinenselogo}
              alt="Logo Catarinense"
              className="h-20 object-contain"
            />
          </div>
        </CardHeader>

        <CardContent className="pt-4">
          <form onSubmit={handleSubmit} className="space-y-5">
            <div>
              <Label htmlFor="email" className="text-gray-200">Email</Label>
              <Input
                id="email"
                name="email"
                type="email"
                placeholder="Digite seu email"
                value={formData.email}
                onChange={handleInputChange}
                required
                className="mt-1 h-11 bg-gray-700 border-gray-600 text-white placeholder-gray-400 focus:border-green-400 focus:ring-green-400"
              />
            </div>

            <div>
              <Label htmlFor="password" className="text-gray-200">Senha</Label>
              <Input
                id="password"
                name="password"
                type="password"
                placeholder="Digite sua senha"
                value={formData.password}
                onChange={handleInputChange}
                required
                className="mt-1 h-11 bg-gray-700 border-gray-600 text-white placeholder-gray-400 focus:border-green-400 focus:ring-green-400"
              />
            </div>

            {error && (
              <p className="text-red-400 text-center text-sm">{error}</p>
            )}

            <Button
              type="submit"
              disabled={isLoading}
              className="w-full h-11 text-base font-medium bg-green-700 hover:bg-green-600 text-white shadow-md shadow-green-700/40 transition-colors duration-200"
            >
              {isLoading ? (
                <div className="flex items-center justify-center">
                  <Loader2 className="w-5 h-5 animate-spin mr-2" />
                  Entrando...
                </div>
              ) : (
                "Entrar"
              )}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
};

export default Login;
