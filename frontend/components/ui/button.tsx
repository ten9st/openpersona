import { type ButtonHTMLAttributes } from 'react';

type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'destructive';
type ButtonSize = 'default' | 'sm';

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  size?: ButtonSize;
};

const variantStyles: Record<ButtonVariant, string> = {
  primary:
    'bg-primary text-primary-foreground hover:bg-primary-hover shadow-sm',
  secondary:
    'border border-border bg-card text-foreground hover:bg-accent',
  ghost: 'text-muted hover:bg-accent hover:text-foreground',
  destructive:
    'border border-destructive/30 text-destructive hover:bg-destructive/10',
};

const sizeStyles: Record<ButtonSize, string> = {
  default: 'rounded-lg px-4 py-2.5 text-sm',
  sm: 'rounded-md px-3 py-1.5 text-xs',
};

export function Button({
  variant = 'primary',
  size = 'default',
  className = '',
  children,
  ...props
}: ButtonProps) {
  return (
    <button
      className={`inline-flex items-center justify-center font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 ${variantStyles[variant]} ${sizeStyles[size]} ${className}`}
      {...props}
    >
      {children}
    </button>
  );
}
