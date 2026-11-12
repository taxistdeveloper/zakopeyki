class AppUser {
  const AppUser({
    required this.id,
    required this.name,
    required this.email,
    this.avatarUrl,
    this.isBusiness = false,
    this.twoFactorEnabled = false,
  });

  final int id;
  final String name;
  final String email;
  final String? avatarUrl;
  final bool isBusiness;
  final bool twoFactorEnabled;

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: (j['id'] as num?)?.toInt() ?? 0,
        name: (j['name'] ?? '').toString(),
        email: (j['email'] ?? '').toString(),
        avatarUrl: j['avatar_url'] as String?,
        isBusiness: j['is_business'] == true,
        twoFactorEnabled: j['two_factor_enabled'] == true,
      );
}
