import { FormEvent, useMemo, useState } from 'react';
import { NavLink, Navigate, Route, Routes } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Area, AreaChart, Pie, PieChart, ResponsiveContainer, Tooltip } from 'recharts';
import clsx from 'clsx';
import { api } from './lib/api';
import { useAuthStore } from './store/auth';
import type {
  AiInsight,
  Budget,
  ChatMessage,
  Goal,
  RecurringPayment,
  StatementRow,
  StatementUpload,
  Transaction,
  User
} from './types';

interface DashboardResponse {
  user: User;
  balance: number;
  spending_trend: Array<{ day: string; amount: number }>;
  category_breakdown: Array<{ name: string; value: number }>;
  transactions: Transaction[];
  budgets: Budget[];
  goals: Goal[];
  recurring: RecurringPayment[];
  insights: AiInsight[];
}

const navItems = [
  ['/', 'Home'],
  ['/transactions', 'Moves'],
  ['/budgets', 'Budgets'],
  ['/ai', 'Coach'],
  ['/profile', 'Profile']
] as const;

function App() {
  const accessToken = useAuthStore((state) => state.accessToken);

  return (
    <Routes>
      <Route path="/auth" element={<AuthPage />} />
      <Route path="*" element={accessToken ? <Shell /> : <Navigate to="/auth" replace />} />
    </Routes>
  );
}

function Shell() {
  return (
    <div className="app-shell">
      <main className="screen">
        <Routes>
          <Route path="/" element={<DashboardPage />} />
          <Route path="/transactions" element={<TransactionsPage />} />
          <Route path="/budgets" element={<BudgetsPage />} />
          <Route path="/goals" element={<GoalsPage />} />
          <Route path="/recurring" element={<RecurringPage />} />
          <Route path="/statements" element={<StatementsPage />} />
          <Route path="/ai" element={<AiPage />} />
          <Route path="/profile" element={<ProfilePage />} />
        </Routes>
      </main>
      <nav className="bottom-nav">
        {navItems.map(([to, label]) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) => clsx('bottom-nav__item', isActive && 'is-active')}
          >
            {label}
          </NavLink>
        ))}
      </nav>
    </div>
  );
}

function AuthPage() {
  const [step, setStep] = useState<'request' | 'verify'>('request');
  const [email, setEmail] = useState('');
  const [otp, setOtp] = useState('');
  const setSession = useAuthStore((state) => state.setSession);

  const requestOtp = useMutation({
    mutationFn: () =>
      api<{ message: string; otp_preview?: string }>('/auth/request-otp', {
        method: 'POST',
        body: JSON.stringify({ email })
      }),
    onSuccess: () => setStep('verify')
  });

  const verifyOtp = useMutation({
    mutationFn: () =>
      api<{ access_token: string; user: User }>('/auth/verify-otp', {
        method: 'POST',
        body: JSON.stringify({ email, otp })
      }),
    onSuccess: ({ access_token, user }) => setSession(user, access_token)
  });

  return (
    <div className="auth-layout">
      <div className="hero-card">
        <p className="eyebrow">FinMan Pro</p>
        <h1>Money clarity, built for your phone.</h1>
        <p className="muted">
          Track spending, import statements, and get AI-backed suggestions that help you course
          correct faster.
        </p>
      </div>

      <form
        className="panel"
        onSubmit={(event) => {
          event.preventDefault();
          if (step === 'request') {
            requestOtp.mutate();
          } else {
            verifyOtp.mutate();
          }
        }}
      >
        <h2>{step === 'request' ? 'Sign in with email OTP' : 'Enter your code'}</h2>
        <label>
          Email
          <input value={email} onChange={(event) => setEmail(event.target.value)} type="email" required />
        </label>
        {step === 'verify' && (
          <label>
            One-time code
            <input value={otp} onChange={(event) => setOtp(event.target.value)} inputMode="numeric" required />
          </label>
        )}
        <button className="primary-button" type="submit">
          {step === 'request' ? 'Send code' : 'Verify and enter'}
        </button>
        {requestOtp.data?.otp_preview ? (
          <p className="helper">Local preview OTP: {requestOtp.data.otp_preview}</p>
        ) : null}
      </form>
    </div>
  );
}

function DashboardPage() {
  const { data } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => api<DashboardResponse>('/dashboard')
  });

  const breakdown = useMemo(
    () => data?.category_breakdown ?? [{ name: 'Needs data', value: 1 }],
    [data?.category_breakdown]
  );

  return (
    <div className="stack">
      <section className="hero-card">
        <p className="eyebrow">Hello</p>
        <h1>{data?.user.name ?? 'Money strategist'}</h1>
        <div className="balance-card">
          <div>
            <span>Current balance</span>
            <strong>${data?.balance.toFixed(2) ?? '0.00'}</strong>
          </div>
          <a className="pill-link" href="/transactions">
            Add move
          </a>
        </div>
      </section>

      <section className="panel">
        <div className="section-heading">
          <h2>Flow pulse</h2>
          <span>This month</span>
        </div>
        <div className="chart-wrap">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={data?.spending_trend ?? []}>
              <defs>
                <linearGradient id="areaGradient" x1="0%" x2="0%" y1="0%" y2="100%">
                  <stop offset="0%" stopColor="#6D3DF5" stopOpacity={0.72} />
                  <stop offset="100%" stopColor="#6D3DF5" stopOpacity={0.08} />
                </linearGradient>
              </defs>
              <Tooltip />
              <Area dataKey="amount" stroke="#6D3DF5" fill="url(#areaGradient)" />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </section>

      <section className="grid-two">
        <div className="panel compact">
          <div className="section-heading">
            <h2>Spend mix</h2>
          </div>
          <div className="pie-wrap">
            <ResponsiveContainer width="100%" height="100%">
              <PieChart>
                <Pie data={breakdown} dataKey="value" nameKey="name" innerRadius={46} outerRadius={72} fill="#6D3DF5" />
                <Tooltip />
              </PieChart>
            </ResponsiveContainer>
          </div>
        </div>
        <div className="panel compact">
          <div className="section-heading">
            <h2>Upcoming</h2>
          </div>
          <ul className="list">
            {(data?.recurring ?? []).slice(0, 3).map((item) => (
              <li key={item.id}>
                <strong>{item.merchant}</strong>
                <span>${item.amount.toFixed(2)} · {item.next_due_on}</span>
              </li>
            ))}
          </ul>
        </div>
      </section>

      <section className="panel">
        <div className="section-heading">
          <h2>AI corrective tips</h2>
          <NavLink to="/ai">Open coach</NavLink>
        </div>
        <div className="stack">
          {(data?.insights ?? []).slice(0, 3).map((insight) => (
            <article key={insight.id} className={clsx('insight-card', `severity-${insight.severity}`)}>
              <h3>{insight.title}</h3>
              <p>{insight.summary_text}</p>
            </article>
          ))}
        </div>
      </section>

      <section className="panel">
        <div className="section-heading">
          <h2>Recent transactions</h2>
          <NavLink to="/transactions">See all</NavLink>
        </div>
        <ul className="list">
          {(data?.transactions ?? []).slice(0, 5).map((item) => (
            <li key={item.id}>
              <strong>{item.merchant}</strong>
              <span>{item.occurred_on}</span>
              <b className={item.type === 'expense' ? 'expense' : 'income'}>
                {item.type === 'expense' ? '-' : '+'}${item.amount.toFixed(2)}
              </b>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}

function TransactionsPage() {
  const queryClient = useQueryClient();
  const { data } = useQuery({
    queryKey: ['transactions'],
    queryFn: () => api<{ data: Transaction[] }>('/transactions')
  });
  const [form, setForm] = useState({
    merchant: '',
    amount: '',
    type: 'expense',
    occurred_on: new Date().toISOString().slice(0, 10)
  });

  const createTransaction = useMutation({
    mutationFn: () =>
      api('/transactions', {
        method: 'POST',
        body: JSON.stringify({
          merchant: form.merchant,
          amount: Number(form.amount),
          type: form.type,
          occurred_on: form.occurred_on
        })
      }),
    onSuccess: () => {
      setForm({ merchant: '', amount: '', type: 'expense', occurred_on: new Date().toISOString().slice(0, 10) });
      queryClient.invalidateQueries({ queryKey: ['transactions'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    }
  });

  return (
    <div className="stack">
      <section className="panel">
        <div className="section-heading">
          <h1>Transactions</h1>
          <NavLink to="/statements">Import PDF</NavLink>
        </div>
        <form
          className="inline-form"
          onSubmit={(event) => {
            event.preventDefault();
            createTransaction.mutate();
          }}
        >
          <input
            placeholder="Merchant"
            value={form.merchant}
            onChange={(event) => setForm((state) => ({ ...state, merchant: event.target.value }))}
          />
          <input
            placeholder="Amount"
            inputMode="decimal"
            value={form.amount}
            onChange={(event) => setForm((state) => ({ ...state, amount: event.target.value }))}
          />
          <select value={form.type} onChange={(event) => setForm((state) => ({ ...state, type: event.target.value }))}>
            <option value="expense">Expense</option>
            <option value="income">Income</option>
            <option value="transfer">Transfer</option>
          </select>
          <input
            type="date"
            value={form.occurred_on}
            onChange={(event) => setForm((state) => ({ ...state, occurred_on: event.target.value }))}
          />
          <button className="primary-button" type="submit">Save</button>
        </form>
      </section>
      <section className="panel">
        <ul className="list">
          {(data?.data ?? []).map((item) => (
            <li key={item.id}>
              <strong>{item.merchant}</strong>
              <span>{item.occurred_on}</span>
              <b className={item.type === 'expense' ? 'expense' : 'income'}>
                {item.type === 'expense' ? '-' : '+'}${item.amount.toFixed(2)}
              </b>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}

function BudgetsPage() {
  const { data } = useQuery({
    queryKey: ['budgets'],
    queryFn: () => api<{ data: Budget[] }>('/budgets')
  });

  return (
    <div className="stack">
      <section className="panel">
        <div className="section-heading">
          <h1>Budgets</h1>
          <NavLink to="/goals">Savings goals</NavLink>
        </div>
        <div className="stack">
          {(data?.data ?? []).map((budget) => {
            const ratio = Math.min(100, (budget.spent_amount / budget.limit_amount) * 100 || 0);
            return (
              <article key={budget.id}>
                <div className="budget-row">
                  <strong>{budget.category_name}</strong>
                  <span>${budget.spent_amount.toFixed(2)} / ${budget.limit_amount.toFixed(2)}</span>
                </div>
                <div className="progress"><span style={{ width: `${ratio}%` }} /></div>
              </article>
            );
          })}
        </div>
      </section>
    </div>
  );
}

function GoalsPage() {
  const { data } = useQuery({
    queryKey: ['goals'],
    queryFn: () => api<{ data: Goal[] }>('/goals')
  });

  return (
    <div className="stack">
      <section className="panel">
        <div className="section-heading">
          <h1>Goals</h1>
          <NavLink to="/recurring">Recurring</NavLink>
        </div>
        <div className="stack">
          {(data?.data ?? []).map((goal) => {
            const ratio = Math.min(100, (goal.current_amount / goal.target_amount) * 100 || 0);
            return (
              <article key={goal.id} className="goal-card">
                <strong>{goal.name}</strong>
                <span>${goal.current_amount.toFixed(2)} of ${goal.target_amount.toFixed(2)}</span>
                <div className="progress"><span style={{ width: `${ratio}%` }} /></div>
              </article>
            );
          })}
        </div>
      </section>
    </div>
  );
}

function RecurringPage() {
  const { data } = useQuery({
    queryKey: ['recurring'],
    queryFn: () => api<{ data: RecurringPayment[] }>('/recurring-payments')
  });

  return (
    <div className="stack">
      <section className="panel">
        <div className="section-heading">
          <h1>Recurring payments</h1>
          <NavLink to="/">Dashboard</NavLink>
        </div>
        <ul className="list">
          {(data?.data ?? []).map((item) => (
            <li key={item.id}>
              <strong>{item.merchant}</strong>
              <span>{item.frequency} · due {item.next_due_on}</span>
              <b>${item.amount.toFixed(2)}</b>
            </li>
          ))}
        </ul>
      </section>
    </div>
  );
}

function StatementsPage() {
  const queryClient = useQueryClient();
  const [selectedFile, setSelectedFile] = useState<File | null>(null);

  const { data } = useQuery({
    queryKey: ['statements'],
    queryFn: () => api<{ data: StatementUpload[] }>('/statements')
  });

  const [selectedStatement, setSelectedStatement] = useState<number | null>(null);
  const rowsQuery = useQuery({
    queryKey: ['statement-rows', selectedStatement],
    queryFn: () => api<{ data: StatementRow[] }>(`/statements/${selectedStatement}/rows`),
    enabled: selectedStatement !== null
  });

  const uploadMutation = useMutation({
    mutationFn: async () => {
      if (!selectedFile) {
        throw new Error('Select a PDF first');
      }
      const formData = new FormData();
      formData.append('statement', selectedFile);
      return api('/statements/upload', { method: 'POST', body: formData });
    },
    onSuccess: () => {
      setSelectedFile(null);
      queryClient.invalidateQueries({ queryKey: ['statements'] });
    }
  });

  const confirmMutation = useMutation({
    mutationFn: () => api(`/statements/${selectedStatement}/confirm`, { method: 'POST' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['transactions'] });
      queryClient.invalidateQueries({ queryKey: ['dashboard'] });
    }
  });

  return (
    <div className="stack">
      <section className="panel">
        <div className="section-heading">
          <h1>Bank statement import</h1>
          <span>PDF staging + review</span>
        </div>
        <label className="upload-box">
          <input type="file" accept="application/pdf" onChange={(event) => setSelectedFile(event.target.files?.[0] ?? null)} />
          <span>{selectedFile ? selectedFile.name : 'Choose a statement PDF'}</span>
        </label>
        <button className="primary-button" onClick={() => uploadMutation.mutate()}>
          Upload and parse
        </button>
      </section>
      <section className="panel">
        <div className="section-heading">
          <h2>Import history</h2>
        </div>
        <div className="stack">
          {(data?.data ?? []).map((statement) => (
            <button
              className={clsx('history-card', selectedStatement === statement.id && 'is-selected')}
              key={statement.id}
              onClick={() => setSelectedStatement(statement.id)}
            >
              <strong>{statement.filename}</strong>
              <span>{statement.status} · {statement.uploaded_at}</span>
            </button>
          ))}
        </div>
      </section>
      {selectedStatement !== null && (
        <section className="panel">
          <div className="section-heading">
            <h2>Parsed rows</h2>
            <button className="primary-button" onClick={() => confirmMutation.mutate()}>
              Confirm import
            </button>
          </div>
          <ul className="list">
            {(rowsQuery.data?.data ?? []).map((row) => (
              <li key={row.id}>
                <strong>{row.description}</strong>
                <span>{row.occurred_on} · confidence {Math.round(row.confidence * 100)}%</span>
                <b>${row.amount.toFixed(2)}</b>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  );
}

function AiPage() {
  const queryClient = useQueryClient();
  const [message, setMessage] = useState('');
  const [threadId, setThreadId] = useState<number | null>(null);

  const insightsQuery = useQuery({
    queryKey: ['insights'],
    queryFn: () => api<{ data: AiInsight[] }>('/ai/insights/latest')
  });

  const threadMessages = useQuery({
    queryKey: ['chat', threadId],
    queryFn: () => api<{ data: ChatMessage[] }>(`/ai/chat/threads?thread_id=${threadId}`),
    enabled: threadId !== null
  });

  const generateInsights = useMutation({
    mutationFn: () => api('/ai/insights/generate', { method: 'POST' }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['insights'] })
  });

  const sendMessage = useMutation({
    mutationFn: () =>
      api<{ thread_id: number; message: ChatMessage }>('/ai/chat/message', {
        method: 'POST',
        body: JSON.stringify({ thread_id: threadId, message })
      }),
    onSuccess: (payload) => {
      setThreadId(payload.thread_id);
      setMessage('');
      queryClient.invalidateQueries({ queryKey: ['chat', payload.thread_id] });
    }
  });

  return (
    <div className="stack">
      <section className="panel">
        <div className="section-heading">
          <h1>AI money coach</h1>
          <button className="primary-button" onClick={() => generateInsights.mutate()}>
            Refresh insights
          </button>
        </div>
        <div className="stack">
          {(insightsQuery.data?.data ?? []).map((item) => (
            <article key={item.id} className={clsx('insight-card', `severity-${item.severity}`)}>
              <h3>{item.title}</h3>
              <p>{item.summary_text}</p>
              <small>{item.suggested_actions.join(' · ')}</small>
            </article>
          ))}
        </div>
      </section>

      <section className="panel">
        <div className="section-heading">
          <h2>Conversation</h2>
          <span>Grounded on your own data</span>
        </div>
        <div className="chat-log">
          {(threadMessages.data?.data ?? []).map((item) => (
            <div key={item.id} className={clsx('chat-bubble', item.role === 'assistant' && 'assistant')}>
              {item.content}
            </div>
          ))}
        </div>
        <form
          className="inline-form"
          onSubmit={(event: FormEvent) => {
            event.preventDefault();
            sendMessage.mutate();
          }}
        >
          <input value={message} onChange={(event) => setMessage(event.target.value)} placeholder="Ask about spending, budgets, or savings..." />
          <button className="primary-button" type="submit">Send</button>
        </form>
      </section>
    </div>
  );
}

function ProfilePage() {
  const clearSession = useAuthStore((state) => state.clearSession);
  return (
    <div className="stack">
      <section className="panel">
        <h1>Profile & settings</h1>
        <p className="muted">
          Manage your account, notification preferences, and future integrations here.
        </p>
        <button className="primary-button" onClick={() => clearSession()}>
          Sign out
        </button>
      </section>
    </div>
  );
}

export default App;
