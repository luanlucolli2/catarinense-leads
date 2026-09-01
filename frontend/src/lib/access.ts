export const C6_ONLY_EMAIL = "c6.links@catarinense.com";

type UserLike = {
  email?: string | null;
} | null | undefined;

export const isC6OnlyUser = (user: UserLike): boolean => {
  const email = (user?.email ?? "").trim().toLowerCase();
  return email === C6_ONLY_EMAIL;
};
