
import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { Card, CardContent, CardHeader } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import catarinenselogo from "../../public/catainenseLogo.png";

const Login = () => {
  const navigate = useNavigate();
  const [formData, setFormData] = useState({
    email: "",
    password: "",
  });
  const [isLoading, setIsLoading] = useState(false);

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

    // Simular delay de autenticação
    await new Promise(resolve => setTimeout(resolve, 1000));

    // Validar credenciais
    if (formData.email === "admin@catarinense.com" && formData.password === "123456") {
      // Login bem-sucedido
      localStorage.setItem("isAuthenticated", "true");
      toast.success("Login realizado com sucesso!");
      navigate("/");
    } else {
      // Login com falha
      toast.error("Email ou senha inválidos.");
    }

    setIsLoading(false);
  };

  return (
    <div className="min-h-screen bg-[#333] flex items-center justify-center p-4">
      <Card className="w-full max-w-md shadow-xl bg-[#333] border-gray-600">
        <CardHeader className="space-y-4 pb-6">
          {/* Logo Area */}
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

            <Button
              type="submit"
              className="w-full h-11 text-base font-medium bg-green-700 hover:bg-green-600 text-white transition-colors duration-200"
              disabled={isLoading}
            >
              {isLoading ? "Entrando..." : "Entrar"}
            </Button>
          </form>

          {/* Credenciais de teste */}
          <div className="mt-8 p-4 bg-gray-700 rounded-lg border border-gray-600">
            <p className="text-xs text-gray-300 text-center font-medium">
              Credenciais de teste:
            </p>
            <p className="text-xs text-gray-400 text-center mt-1">
              Email: admin@catarinense.com<br />
              Senha: 123456
            </p>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};

export default Login;
