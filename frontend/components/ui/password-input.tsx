'use client';

import { useState, type InputHTMLAttributes } from 'react';
import { Input } from '@/components/ui/input';

type PasswordInputProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>;

export function PasswordInput({ className = '', ...props }: PasswordInputProps) {
  const [visible, setVisible] = useState(false);

  return (
    <div className="relative">
      <Input
        {...props}
        type={visible ? 'text' : 'password'}
        className={`pr-10 ${className}`}
      />
      <button
        type="button"
        onClick={() => setVisible((current) => !current)}
        className="absolute top-1/2 right-2 -translate-y-1/2 rounded px-1.5 py-0.5 text-muted transition-colors hover:text-foreground"
        aria-label={visible ? 'パスワードを隠す' : 'パスワードを表示'}
      >
        👁
      </button>
    </div>
  );
}
