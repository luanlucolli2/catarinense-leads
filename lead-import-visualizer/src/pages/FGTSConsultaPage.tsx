import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { usePersistedState } from "@/hooks/usePersistedState";
import factaLogo from "@/assets/factalogo.png";
import v8Logo from "@/assets/v8logo.png";

import FGTSFactaConsultaPage from "./FGTSFactaConsultaPage";
import FGTSV8ConsultaPage from "./FGTSV8ConsultaPage";

const FGTSConsultaPage = () => {
  const [activeTab, setActiveTab] = usePersistedState<"facta" | "v8">(
    "fgts:activeTab",
    "facta"
  );

  const headerTitle = activeTab === "v8" ? "Consulta FGTS (V8)" : "Consulta FGTS (Facta)";
  const headerDescription = activeTab === "v8"
    ? "Faça consultas FGTS V8 em massa por lista de CPFs e baixe o resultado em CSV."
    : "Faça consultas em massa na FGTS Base Offline (Facta). Os resultados são gerados em CSV.";

  return (
    <div className="p-4 lg:p-6 max-w-full min-w-0">
      <div className="mb-6 max-w-full">
        <h1 className="text-xl lg:text-2xl font-bold text-gray-900 mb-2">
          {headerTitle}
        </h1>
        <p className="text-gray-600 text-sm lg:text-base">
          {headerDescription}
        </p>
      </div>

      <Tabs
        value={activeTab}
        onValueChange={(value) => setActiveTab(value as "facta" | "v8")}
        className="space-y-6"
      >
        <TabsList className="flex w-fit h-auto p-1 bg-muted/50 rounded-lg justify-start">
          <TabsTrigger
            value="facta"
            className="px-6 py-2 rounded-md text-sm font-medium transition-all duration-200 data-[state=active]:bg-background data-[state=active]:text-foreground text-gray-600 hover:text-gray-900 hover:bg-gray-50"
          >
            <span className="inline-flex items-center gap-2">
              <img
                src={factaLogo}
                alt="Facta"
                className="h-4 w-4 object-contain"
              />
              Facta
            </span>
          </TabsTrigger>
          <TabsTrigger
            value="v8"
            className="px-6 py-2 rounded-md text-sm font-medium transition-all duration-200 data-[state=active]:bg-background data-[state=active]:text-foreground text-gray-600 hover:text-gray-900 hover:bg-gray-50"
          >
            <span className="inline-flex items-center gap-2">
              <img
                src={v8Logo}
                alt="V8"
                className="h-4 w-4 object-contain"
              />
              V8
            </span>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="facta" className="space-y-6">
          <FGTSFactaConsultaPage />
        </TabsContent>

        <TabsContent value="v8" className="space-y-6">
          <FGTSV8ConsultaPage />
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default FGTSConsultaPage;
