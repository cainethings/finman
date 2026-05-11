export type Severity = 'low' | 'medium' | 'high';

export interface User {
  id: number;
  name: string;
  email: string;
}

export interface Account {
  id: number;
  name: string;
  type: 'cash' | 'bank' | 'credit';
  balance: number;
}

export interface Transaction {
  id: number;
  account_id: number;
  category_id: number | null;
  type: 'income' | 'expense' | 'transfer';
  amount: number;
  merchant: string;
  notes: string | null;
  occurred_on: string;
  tags: string[];
}

export interface Budget {
  id: number;
  category_name: string;
  limit_amount: number;
  spent_amount: number;
}

export interface Goal {
  id: number;
  name: string;
  target_amount: number;
  current_amount: number;
  due_on: string | null;
}

export interface RecurringPayment {
  id: number;
  merchant: string;
  amount: number;
  frequency: string;
  next_due_on: string;
}

export interface StatementUpload {
  id: number;
  filename: string;
  status: string;
  uploaded_at: string;
}

export interface StatementRow {
  id: number;
  occurred_on: string;
  description: string;
  amount: number;
  balance: number | null;
  category_name: string | null;
  confidence: number;
}

export interface AiInsight {
  id: number;
  title: string;
  summary_text: string;
  severity: Severity;
  confidence: number;
  suggested_actions: string[];
}

export interface ChatThread {
  id: number;
  title: string;
  created_at: string;
}

export interface ChatMessage {
  id: number;
  role: 'user' | 'assistant' | 'system';
  content: string;
  created_at: string;
}
