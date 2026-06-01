import { type SelectHTMLAttributes } from 'react';

type SelectProps = SelectHTMLAttributes<HTMLSelectElement>;

export function Select({ className = '', ...props }: SelectProps) {
  return (
    <select
      className={`w-full rounded-lg border border-border bg-card px-3.5 py-2.5 text-sm text-foreground focus:border-primary focus:outline-none focus:ring-2 focus:ring-ring/40 ${className}`}
      {...props}
    />
  );
}
