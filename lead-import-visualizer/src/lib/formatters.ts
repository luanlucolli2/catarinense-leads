// Em src/lib/formatters.ts

import { format, parse } from 'date-fns';
import { ptBR } from 'date-fns/locale';

/**
 * Formata um CPF (11 dígitos) para o padrão XXX.XXX.XXX-XX.
 */
export const formatCPF = (cpf: string | null | undefined): string => {
  if (!cpf) return '--';
  const cpfDigits = cpf.replace(/\D/g, '');
  if (cpfDigits.length !== 11) return cpf;
  return cpfDigits.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
};

/**
 * Tenta formatar um valor como moeda brasileira. Se o valor não for
 * um número válido, retorna o valor original como string.
 */
export const formatCurrency = (value: string | number | null | undefined): string => {
  if (value === null || value === undefined) return '--';

  const numericValue = typeof value === 'string' ? parseFloat(value.replace(',', '.')) : value;

  if (isNaN(numericValue)) {
    // Se não for um número (ex: "Não Autorizado"), retorna o texto original.
    return value.toString();
  }

  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(numericValue);
};

/**
 * Formata uma data para o padrão dd/MM/yyyy HH:mm.
 * Regras:
 * - "YYYY-MM-DD" => trata como data sem hora (usa formatDateOnly).
 * - "YYYY-MM-DD HH:mm:ss" (sem timezone) => interpreta como UTC e converte para local.
 * - Outras strings => delega para o Date nativo.
 */
export const formatDate = (dateString?: string | null): string => {
  if (!dateString) return '--';

  // Apenas data (sem hora)
  if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
    return formatDateOnly(dateString);
  }

  // Padrão "YYYY-MM-DD HH:mm:ss" (sem timezone) → tratar como UTC
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(dateString)) {
    try {
      const asUtcIso = dateString.replace(' ', 'T') + 'Z';
      const d = new Date(asUtcIso);
      return format(d, 'dd/MM/yyyy HH:mm', { locale: ptBR });
    } catch {
      // fallback abaixo
    }
  }

  // Demais formatos: tenta usar o parser nativo
  try {
    return format(new Date(dateString), 'dd/MM/yyyy HH:mm', { locale: ptBR });
  } catch {
    return 'Data inválida';
  }
};

/**
 * Formata um datetime sem timezone assumindo que o valor ja esta em horario local.
 * Ex.: "2026-05-15 10:29:36" => "15/05/2026 10:29"
 */
export const formatLocalDateTime = (dateString?: string | null): string => {
  if (!dateString) return '--';

  if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
    return formatDateOnly(dateString);
  }

  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(dateString)) {
    try {
      const d = parse(dateString, 'yyyy-MM-dd HH:mm:ss', new Date());
      return format(d, 'dd/MM/yyyy HH:mm', { locale: ptBR });
    } catch {
      // fallback abaixo
    }
  }

  try {
    return format(new Date(dateString), 'dd/MM/yyyy HH:mm', { locale: ptBR });
  } catch {
    return 'Data inválida';
  }
};

// src/lib/formatters.ts
export const formatDateOnly = (
  iso: string | null | undefined,
  pattern: string = "dd/MM/yyyy",
): string => {
  if (!iso) return "--";
  try {
    // aceita "YYYY-MM-DD" ou "YYYY-MM-DD HH:mm[:ss][...]" e usa só a parte da data
    const m = iso.match(/^(\d{4}-\d{2}-\d{2})/);
    const only = m ? m[1] : iso.slice(0, 10);
    const date = parse(only, "yyyy-MM-dd", new Date());
    return format(date, pattern, { locale: ptBR });
  } catch {
    return "Data inválida";
  }
};



/**
 * Formata um número de telefone para os padrões brasileiros.
 * Lida com variações como 55, 9º dígito, etc.
 */
export const formatPhone = (phone: string | null | undefined): string => {
  if (!phone) return '--';

  const phoneDigits = phone.replace(/\D/g, '');

  // A lógica inteligente: um número completo com DDI tem 12 ou 13 dígitos (55 + DDD + numero).
  // Se tiver mais de 11 dígitos e começar com 55, é seguro assumir que é um DDI.
  if (phoneDigits.length > 11 && phoneDigits.startsWith('55')) {
    const ddd = phoneDigits.substring(2, 4);
    const number = phoneDigits.substring(4);
    if (number.length === 9) {
      return `+55 (${ddd}) ${number.substring(0, 5)}-${number.substring(5)}`;
    }
    return `+55 (${ddd}) ${number.substring(0, 4)}-${number.substring(4)}`;
  }

  // Números padrão com DDD (11 ou 10 dígitos)
  if (phoneDigits.length === 11) {
    return phoneDigits.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
  }
  if (phoneDigits.length === 10) {
    return phoneDigits.replace(/(\d{2})(\d{4})(\d{4})/, '($1) $2-$3');
  }

  return phone; // Retorna o original se não se encaixar
};
