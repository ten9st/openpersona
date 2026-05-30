import { type HTMLAttributes, type ReactNode } from 'react';

type CardProps = HTMLAttributes<HTMLDivElement> & {
  children: ReactNode;
};

export function Card({ className = '', children, ...props }: CardProps) {
  return (
    <div
      className={`rounded-xl border border-border bg-card p-6 shadow-sm ${className}`}
      {...props}
    >
      {children}
    </div>
  );
}
