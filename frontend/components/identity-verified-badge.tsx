type IdentityVerifiedBadgeProps = {
  verified?: boolean;
  className?: string;
};

export function IdentityVerifiedBadge({
  verified = false,
  className = '',
}: IdentityVerifiedBadgeProps) {
  if (!verified) {
    return null;
  }

  return (
    <span
      className={`inline-flex items-center rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary ${className}`}
    >
      ✓ 本人確認済み
    </span>
  );
}
