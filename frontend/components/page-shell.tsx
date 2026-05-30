import Link from 'next/link';
import { type ReactNode } from 'react';

type PageShellProps = {
  children: ReactNode;
  maxWidth?: 'sm' | 'md' | 'lg' | 'xl';
};

const maxWidthStyles = {
  sm: 'max-w-md',
  md: 'max-w-lg',
  lg: 'max-w-2xl',
  xl: 'max-w-3xl',
};

export function PageShell({ children, maxWidth = 'lg' }: PageShellProps) {
  return (
    <div className="min-h-full bg-background">
      <header className="border-b border-border bg-card/80 backdrop-blur-sm">
        <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
          <Link
            href="/"
            className="text-lg font-semibold tracking-tight text-foreground transition-colors hover:text-primary"
          >
            OpenPersona
          </Link>
          <p className="hidden text-sm text-muted sm:block">
            信頼できる情報ソースを公開するSNS
          </p>
        </div>
      </header>
      <main
        className={`mx-auto w-full px-6 py-10 ${maxWidthStyles[maxWidth]}`}
      >
        {children}
      </main>
    </div>
  );
}

type PageHeaderProps = {
  title: string;
  description?: string;
};

export function PageHeader({ title, description }: PageHeaderProps) {
  return (
    <div className="mb-8">
      <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
        {title}
      </h1>
      {description && (
        <p className="mt-2 text-sm text-muted sm:text-base">{description}</p>
      )}
    </div>
  );
}

type AlertProps = {
  message: string;
  variant?: 'info' | 'error';
};

export function Alert({ message, variant = 'info' }: AlertProps) {
  const styles =
    variant === 'error'
      ? 'border-destructive/30 bg-destructive/10 text-destructive'
      : 'border-primary/30 bg-accent text-foreground';

  return (
    <p
      className={`rounded-lg border px-4 py-3 text-sm ${styles}`}
      role="status"
    >
      {message}
    </p>
  );
}
