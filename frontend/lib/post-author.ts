export type PostAuthor = {
  id: number;
  last_name: string;
  first_name: string | null;
  age: number | null;
  region: string | null;
};

export const formatAuthorSummary = (user: PostAuthor) => {
  const parts = [`${user.last_name}${user.first_name ?? ''}`];

  if (user.region) {
    parts.push(user.region);
  }

  if (user.age != null) {
    parts.push(`${user.age}歳`);
  }

  return parts.join(' · ');
};
