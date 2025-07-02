
// src/api/mockData.ts

// 1. Definição das Interfaces (baseado no SRS)

export interface ImportJob {
  id: number;
  fileName: string;
  type: 'Cadastral' | 'Higienização';
  status: 'Concluído' | 'Falhou' | 'Em Progresso';
  errorCount: number;
  finishedAt: string; // Usar string ISO para facilitar
  user: {
    name: string;
  };
}

export interface ImportError {
  id: number;
  import_job_id: number;
  row_number: number;
  column_name: string;
  error_message: string;
}

// 2. Mock Data

export const mockImportJobs: ImportJob[] = [
  {
    id: 1,
    fileName: 'base_clientes_maio.xlsx',
    type: 'Cadastral',
    status: 'Concluído',
    errorCount: 2,
    finishedAt: '2025-06-29T14:30:00Z',
    user: { name: 'João Silva' }
  },
  {
    id: 2,
    fileName: 'higienizacao_semana24.xlsx',
    type: 'Higienização',
    status: 'Concluído',
    errorCount: 0,
    finishedAt: '2025-06-28T10:15:00Z',
    user: { name: 'Maria Souza' }
  },
  {
    id: 3,
    fileName: 'leads_urgentes.xlsx',
    type: 'Cadastral',
    status: 'Falhou',
    errorCount: 1,
    finishedAt: '2025-06-27T18:00:00Z',
    user: { name: 'João Silva' }
  },
  {
    id: 4,
    fileName: 'nova_base_junho.xlsx',
    type: 'Cadastral',
    status: 'Em Progresso',
    errorCount: 0,
    finishedAt: '',
    user: { name: 'Maria Souza' }
  }
];

export const mockImportErrors: ImportError[] = [
  {
    id: 101,
    import_job_id: 1,
    row_number: 42,
    column_name: 'CpfCliente',
    error_message: 'CPF com formato inválido.'
  },
  {
    id: 102,
    import_job_id: 1,
    row_number: 153,
    column_name: 'DataImportacaoCadastro',
    error_message: 'Campo obrigatório não preenchido.'
  },
  {
    id: 103,
    import_job_id: 3,
    row_number: 5,
    column_name: 'Origem Cadastro',
    error_message: 'Valor não permitido para este campo.'
  }
];

// Lógica de busca fictícia
export const fetchErrorsForJob = (jobId: number): Promise<ImportError[]> => {
  return new Promise(resolve => {
    setTimeout(() => {
      const errors = mockImportErrors.filter(e => e.import_job_id === jobId);
      resolve(errors);
    }, 300); // Simula uma pequena latência de rede
  });
};
