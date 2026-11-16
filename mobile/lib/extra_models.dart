// Конкурсы, бизнес-пакет, реклама, тексты страниц (контракт /api/mobile/v1).

int _int(Object? v, [int def = 0]) {
  if (v is int) return v;
  if (v is num) return v.toInt();
  return int.tryParse(v?.toString() ?? '') ?? def;
}

String _str(Object? v, [String def = '']) => v?.toString() ?? def;

List<Map<String, dynamic>> _maps(Object? v) =>
    ((v as List?) ?? []).map((e) => (e as Map).cast<String, dynamic>()).toList();

// ---------------------------------------------------------------- тексты

class PageSection {
  final String title;
  final String body;
  PageSection(this.title, this.body);
}

class PageDoc {
  final String title;
  final String url;
  PageDoc(this.title, this.url);
}

class InfoPage {
  final String title;
  final String lead;
  final String? effective;
  final List<PageSection> sections;
  final String? tagline;
  final String? documentsTitle;
  final List<PageDoc> documents;
  final String? email;

  InfoPage(this.title, this.lead, this.effective, this.sections, this.tagline, this.documentsTitle, this.documents, this.email);

  factory InfoPage.fromJson(Map<String, dynamic> j) => InfoPage(
        _str(j['title']),
        _str(j['lead']),
        j['effective'] as String?,
        _maps(j['sections']).map((s) => PageSection(_str(s['title']), _str(s['body']))).toList(),
        j['tagline'] as String?,
        j['documents_title'] as String?,
        _maps(j['documents']).map((d) => PageDoc(_str(d['title']), _str(d['url']))).toList(),
        j['contact_email'] as String?,
      );
}

// ---------------------------------------------------------------- конкурсы

class ContestCard {
  final int id;
  final String slug;
  final String title;
  final String? summary;
  final String? prize;
  final String? cover;
  final String status;
  final String statusLabel;
  final String mechanicLabel;
  final String? startsAt;
  final String? endsAt;
  final int entries;
  final int winnersCount;
  final String organizer;
  final int organizerId;

  ContestCard(this.id, this.slug, this.title, this.summary, this.prize, this.cover, this.status, this.statusLabel,
      this.mechanicLabel, this.startsAt, this.endsAt, this.entries, this.winnersCount, this.organizer, this.organizerId);

  factory ContestCard.fromJson(Map<String, dynamic> j) {
    final o = (j['organizer'] as Map?)?.cast<String, dynamic>() ?? const {};
    return ContestCard(
      _int(j['id']),
      _str(j['slug']),
      _str(j['title']),
      j['summary'] as String?,
      j['prize_summary'] as String?,
      j['cover_url'] as String?,
      _str(j['status']),
      _str(j['status_label']),
      _str(j['mechanic_label']),
      j['starts_at'] as String?,
      j['ends_at'] as String?,
      _int(j['entries_count']),
      _int(j['winners_count']),
      _str(o['name']),
      _int(o['id']),
    );
  }
}

class ContestCondition {
  final String label;
  final bool? met;
  ContestCondition(this.label, this.met);
}

class ContestWinner {
  final int id;
  final int place;
  final String name;
  final String? prize;
  final bool isMe;
  final String fulfilment;
  final String fulfilmentLabel;
  ContestWinner(this.id, this.place, this.name, this.prize, this.isMe, this.fulfilment, this.fulfilmentLabel);
}

class ContestDetail {
  final ContestCard card;
  final String description;
  final String rulesText;
  final String? criteriaText;
  final String mechanicHint;
  final List<ContestCondition> conditions;
  final bool requiresSubmission;
  final List<(int, String)> prizes;
  final bool canJoin;
  final String? myEntryStatus;
  final String? mySubmission;
  final bool canWithdraw;
  final List<ContestWinner> winners;
  final String publicUrl;
  final String? personalUrl;
  final bool hasOpenReport;
  final List<(String, String)> reportReasons;

  ContestDetail({
    required this.card,
    required this.description,
    required this.rulesText,
    required this.criteriaText,
    required this.mechanicHint,
    required this.conditions,
    required this.requiresSubmission,
    required this.prizes,
    required this.canJoin,
    required this.myEntryStatus,
    required this.mySubmission,
    required this.canWithdraw,
    required this.winners,
    required this.publicUrl,
    required this.personalUrl,
    required this.hasOpenReport,
    required this.reportReasons,
  });

  factory ContestDetail.fromJson(Map<String, dynamic> j) {
    final e = (j['my_entry'] as Map?)?.cast<String, dynamic>();
    return ContestDetail(
      card: ContestCard.fromJson(j),
      description: _str(j['description']),
      rulesText: _str(j['rules_text']),
      criteriaText: j['criteria_text'] as String?,
      mechanicHint: _str(j['mechanic_hint']),
      conditions: _maps(j['conditions']).map((c) => ContestCondition(_str(c['label']), c['met'] as bool?)).toList(),
      requiresSubmission: j['requires_submission'] == true,
      prizes: _maps(j['prizes']).map((p) => (_int(p['place']), _str(p['title']))).toList(),
      canJoin: j['can_join'] == true,
      myEntryStatus: e?['status_label'] as String?,
      mySubmission: e?['submission'] as String?,
      canWithdraw: e?['can_withdraw'] == true,
      winners: _maps(j['winners'])
          .map((w) => ContestWinner(_int(w['id']), _int(w['place']), _str(w['name']), w['prize'] as String?,
              w['is_me'] == true, _str(w['fulfilment']), _str(w['fulfilment_label'])))
          .toList(),
      publicUrl: _str(j['public_url']),
      personalUrl: j['personal_url'] as String?,
      hasOpenReport: j['has_open_report'] == true,
      reportReasons: _maps(j['report_reasons']).map((r) => (_str(r['code']), _str(r['label']))).toList(),
    );
  }
}

class MyContests {
  final List<Map<String, dynamic>> participations;
  final List<Map<String, dynamic>> wins;
  final List<ContestCard> organized;
  MyContests(this.participations, this.wins, this.organized);
}

// ---------------------------------------------------------------- бизнес

class BizPackage {
  final int id;
  final String name;
  final String? description;
  final int price;
  final int durationDays;
  final List<String> benefits;
  BizPackage(this.id, this.name, this.description, this.price, this.durationDays, this.benefits);

  factory BizPackage.fromJson(Map<String, dynamic> j) => BizPackage(
        _int(j['id']),
        _str(j['name']),
        j['description'] as String?,
        _int(j['price']),
        _int(j['duration_days']),
        ((j['benefits'] as List?) ?? []).map((e) => e.toString()).toList(),
      );
}

class BizInfo {
  final bool isBusiness;
  final bool salesEnabled;
  final int walletBalance;
  final String? subscriptionName;
  final String? subscriptionEnds;
  final List<(String, int, int)> usage;
  final List<BizPackage> plans;
  final List<BizPackage> addons;
  final String? upgradeStatus;
  final String? upgradeNote;

  BizInfo(this.isBusiness, this.salesEnabled, this.walletBalance, this.subscriptionName, this.subscriptionEnds, this.usage,
      this.plans, this.addons, this.upgradeStatus, this.upgradeNote);

  factory BizInfo.fromJson(Map<String, dynamic> j) {
    final sub = (j['subscription'] as Map?)?.cast<String, dynamic>();
    final up = (j['upgrade_request'] as Map?)?.cast<String, dynamic>();
    return BizInfo(
      j['is_business'] == true,
      j['sales_enabled'] == true,
      _int(j['wallet_balance']),
      sub?['name'] as String?,
      sub?['ends_at'] as String?,
      _maps(j['usage']).map((u) => (_str(u['label']), _int(u['used']), _int(u['cap']))).toList(),
      _maps(j['plans']).map(BizPackage.fromJson).toList(),
      _maps(j['addons']).map(BizPackage.fromJson).toList(),
      up?['status'] as String?,
      up?['note'] as String?,
    );
  }
}

// ---------------------------------------------------------------- реклама

class AdFormat {
  final String code;
  final String label;
  final bool enabled;
  final String unit; // thousand | week
  final int unitPrice;
  final int maxUnits;
  final String category; // none | optional | required
  AdFormat(this.code, this.label, this.enabled, this.unit, this.unitPrice, this.maxUnits, this.category);
}

class AdCategory {
  final String value;
  final String label;
  final String? parent;
  AdCategory(this.value, this.label, this.parent);
}

class AdCampaignItem {
  final int id;
  final String formatLabel;
  final String title;
  final String targetUrl;
  final String? image;
  final int units;
  final int amount;
  final int impressionsPaid;
  final int impressionsUsed;
  final int clicks;
  final String status;
  final String statusLabel;
  final String? rejectReason;
  final String? endsAt;
  final bool canPay;
  final bool canPause;
  final bool canResume;
  final bool canResubmit;

  AdCampaignItem(this.id, this.formatLabel, this.title, this.targetUrl, this.image, this.units, this.amount, this.impressionsPaid,
      this.impressionsUsed, this.clicks, this.status, this.statusLabel, this.rejectReason, this.endsAt, this.canPay, this.canPause,
      this.canResume, this.canResubmit);

  factory AdCampaignItem.fromJson(Map<String, dynamic> j) => AdCampaignItem(
        _int(j['id']),
        _str(j['format_label']),
        _str(j['title']),
        _str(j['target_url']),
        j['image_url'] as String?,
        _int(j['units']),
        _int(j['amount']),
        _int(j['impressions_paid']),
        _int(j['impressions_used']),
        _int(j['clicks']),
        _str(j['status']),
        _str(j['status_label']),
        j['reject_reason'] as String?,
        j['ends_at'] as String?,
        j['can_pay'] == true,
        j['can_pause'] == true,
        j['can_resume'] == true,
        j['can_resubmit'] == true,
      );
}

class AdsData {
  final List<AdCampaignItem> campaigns;
  final List<AdFormat> formats;
  final List<AdCategory> categories;
  AdsData(this.campaigns, this.formats, this.categories);

  factory AdsData.fromJson(Map<String, dynamic> j) => AdsData(
        _maps(j['campaigns']).map(AdCampaignItem.fromJson).toList(),
        _maps(j['formats'])
            .map((f) => AdFormat(_str(f['code']), _str(f['label']), f['enabled'] == true, _str(f['unit']), _int(f['unit_price']),
                _int(f['max_units'], 1), _str(f['category'])))
            .toList(),
        _maps(j['categories']).map((c) => AdCategory(_str(c['value']), _str(c['label']), c['parent'] as String?)).toList(),
      );
}
