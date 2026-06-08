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

export const formatAuthorSummary = (user: PostAuthor) => {
  const parts = [`${user.last_name}${user.first_name ?? ''}`];

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
