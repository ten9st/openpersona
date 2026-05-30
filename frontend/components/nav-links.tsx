import Link from 'next/link';

type NavLinkProps = {
  href: string;
  children: React.ReactNode;
  variant?: 'primary' | 'secondary';
};

export function NavLink({ href, children, variant = 'secondary' }: NavLinkProps) {
  const styles =
    variant === 'primary'
      ? 'bg-primary text-primary-foreground hover:bg-primary-hover shadow-sm'
      : 'border border-border bg-card text-foreground hover:bg-accent';

  return (
    <Link
      href={href}
      className={`inline-flex items-center justify-center rounded-lg px-4 py-2.5 text-sm font-medium transition-colors ${styles}`}
    >
      {children}
    </Link>
  );
}

type ActionBarProps = {
  children: React.ReactNode;
};

export function ActionBar({ children }: ActionBarProps) {
  return (
    <div className="mb-8 flex flex-wrap items-center gap-3">{children}</div>
  );
}
