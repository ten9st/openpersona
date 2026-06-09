export type TrustScoreSummary = {
  total_score: number;
  max_score: number;
};

export type PostAuthor = {
  id: number;
  last_name: string;
  first_name: string | null;
  age: number | null;
  region: string | null;
  trust_score?: TrustScoreSummary;
  identity_verified?: boolean;
};

export const formatTrustScore = (trustScore: TrustScoreSummary) =>
  `透明性 ${trustScore.total_score}/${trustScore.max_score}`;

/** 「透明性スコア」ラベルと併用する数値表示（例: 40/50） */
export const formatTrustScoreRatio = (trustScore: TrustScoreSummary) =>
  `${trustScore.total_score}/${trustScore.max_score}`;

/** 姓のみ、または公開されている姓名を表示する。本人以外には「さん」を付ける */
export const formatPersonName = (
  lastName: string,
  firstName?: string | null,
  options?: { isSelf?: boolean },
) => {
  const name = firstName ? `${lastName}${firstName}` : lastName;

  return options?.isSelf ? name : `${name}さん`;
};

export const formatAuthorSummary = (
  user: PostAuthor,
  currentUserId?: number | null,
) => {
  const isSelf =
    currentUserId != null && user.id === currentUserId;

  const parts = [
    formatPersonName(user.last_name, user.first_name, { isSelf }),
  ];

  if (user.region) {
    parts.push(user.region);
  }

  if (user.age != null) {
    parts.push(`${user.age}歳`);
  }

  if (user.trust_score) {
    parts.push(formatTrustScore(user.trust_score));
  }

  return parts.join(' · ');
};
