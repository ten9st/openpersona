import { type LabelHTMLAttributes, type ReactNode } from 'react';

type LabelProps = LabelHTMLAttributes<HTMLLabelElement> & {
  children: ReactNode;
};

export function Label({ className = '', children, ...props }: LabelProps) {
  return (
    <label
      className={`flex flex-col gap-1.5 text-sm font-medium text-foreground ${className}`}
      {...props}
    >
      {children}
    </label>
  );
}

export function CheckboxLabel({
  className = '',
  children,
  ...props
}: LabelHTMLAttributes<HTMLLabelElement> & { children: ReactNode }) {
  return (
    <label
      className={`flex cursor-pointer items-center gap-2.5 text-sm text-foreground ${className}`}
      {...props}
    >
      {children}
    </label>
  );
}
