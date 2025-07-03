import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import catarinenselogo from "../../public/catainenseLogo.png";
import axiosClient from "@/api/axiosClient"; // 1. Importar nosso cliente Axios
import { useAuth } from "@/contexts/AuthContext"; // 2. Importar nosso hook de autenticação

const Login = () => {
  const navigate = useNavigate();
  const { setUser, setToken } = useAuth(); // 3. Pegar as funções para definir o usuário e o token

  const [formData, setFormData] = useState({
    email: "teste@catarinense.com", // Pré-preenchido para facilitar os testes
    password: "password",
  });
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setError(null);

    try {
      // 4. Substituir a simulação pela chamada de API real
      const response = await axiosClient.post('/login', formData);
      
      // 5. Se o login for bem-sucedido, usar nosso contexto para salvar os dados
      const { user, access_token } = response.data;
      setUser(user);
      setToken(access_token);

      toast.success("Login realizado com sucesso!");
      navigate("/"); // 6. Redirecionar para o Dashboard

    } catch (err: any) {
      // 7. Tratar erros da API
      setIsLoading(false);
      if (err.response && err.response.status === 422) {
        // Erro de validação do Laravel
        setError(err.response.data.errors.email[0]);
        toast.error(err.response.data.errors.email[0]);
      } else {
        // Outros erros (rede, servidor fora do ar, etc.)
        setError("Ocorreu um erro. Verifique sua conexão ou tente novamente.");
        toast.error("Ocorreu um erro ao tentar fazer login.");
      }
    }
    // Não precisamos mais do setIsLoading(false) aqui, pois ele já é tratado no bloco catch.
  };

  return (
    <div className="min-h-screen bg-[#353535] flex items-center justify-center p-4">
      <Card className="w-full max-w-md shadow-xl bg-[#333] border-none">
        <CardHeader className="space-y-4 pb-6">
          <div className="text-center space-y-2">
            <div className="flex justify-center mb-1">
              <img
                src={catarinenselogo}
                alt="Logo Catarinense"
                className="h-20 object-contain"
              />
            </div>
          </div>
        </CardHeader>
        
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-6">
            <div className="space-y-2">
              <Label htmlFor="email" className="text-gray-300">Email</Label>
              <Input
                id="email"
                name="email"
                type="email"
                placeholder="Digite seu email"
                value={formData.email}
                onChange={handleInputChange}
                required
                className="h-11 bg-gray-700 border-gray-600 text-white placeholder:text-gray-400 focus:border-green-500 focus:ring-green-500"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="password" className="text-gray-300">Senha</Label>
              <Input
                id="password"
                name="password"
                type="password"
                placeholder="Digite sua senha"
                value={formData.password}
                onChange={handleInputChange}
                required
                className="h-11 bg-gray-700 border-gray-600 text-white placeholder:text-gray-400 focus:border-green-500 focus:ring-green-500"
              />
            </div>

            {error && <p className="text-red-500 text-sm text-center">{error}</p>}

            <Button
              type="submit"
              className="w-full h-11 text-base font-medium bg-green-700 hover:bg-green-600 text-white transition-colors duration-200"
              disabled={isLoading}
            >
              {isLoading ? "Entrando..." : "Entrar"}
            </Button>
          </form>
        </CardContent>
      </Card>
    </div>
  );
};

export default Login;
