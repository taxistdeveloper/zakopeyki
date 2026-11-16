import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api.dart';
import '../extra_models.dart';
import '../main.dart';
import '../nav.dart';
import '../theme.dart';
import '../widgets/common.dart';

/// Текстовая страница сайта в нативном виде: about | offer | privacy | data-policy | listing-rules.
class InfoPageScreen extends StatefulWidget {
  const InfoPageScreen({super.key, required this.slug, this.title});

  final String slug;
  final String? title;

  @override
  State<InfoPageScreen> createState() => _InfoPageScreenState();
}

class _InfoPageScreenState extends State<InfoPageScreen> {
  InfoPage? _p;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _error = null);
    try {
      final p = await SessionScope.read(context).api.page(widget.slug);
      if (mounted) setState(() => _p = p);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    }
  }

  Future<void> _open(String url) async {
    final ok = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    if (!ok && mounted) showSnack(context, 'Не удалось открыть документ');
  }

  @override
  Widget build(BuildContext context) {
    final p = _p;
    return Scaffold(
      appBar: AppBar(title: Text(p?.title ?? widget.title ?? '')),
      body: p == null
          ? (_error != null ? ErrorView(message: _error!, onRetry: _load) : const Center(child: CircularProgressIndicator()))
          : ListView(
              padding: const EdgeInsets.only(top: 12, bottom: 32),
              children: [
                Padding(
                  padding: const EdgeInsets.fromLTRB(20, 0, 20, 12),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(p.title, style: Theme.of(context).textTheme.headlineSmall?.copyWith(fontWeight: FontWeight.w800)),
                      if (p.lead.isNotEmpty) ...[
                        const SizedBox(height: 8),
                        Text(p.lead, style: TextStyle(color: context.pal.muted, height: 1.4)),
                      ],
                      if (p.effective != null && p.effective!.isNotEmpty) ...[
                        const SizedBox(height: 6),
                        Text(p.effective!, style: TextStyle(color: context.pal.faint, fontSize: 12.5)),
                      ],
                    ],
                  ),
                ),
                for (final s in p.sections)
                  SectionCard(
                    title: s.title.isEmpty ? null : s.title,
                    child: SelectableText(s.body, style: const TextStyle(height: 1.45)),
                  ),
                if (p.tagline != null)
                  Padding(
                    padding: const EdgeInsets.all(20),
                    child: Text(p.tagline!,
                        textAlign: TextAlign.center,
                        style: const TextStyle(color: kBrand, fontWeight: FontWeight.w700, fontSize: 16)),
                  ),
                if (p.documents.isNotEmpty)
                  SectionCard(
                    title: p.documentsTitle ?? 'Документы',
                    padding: const EdgeInsets.fromLTRB(16, 16, 16, 4),
                    child: Column(children: [
                      for (final d in p.documents)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.picture_as_pdf_outlined, color: Color(0xFFDC2626)),
                          title: Text(d.title),
                          trailing: Icon(Icons.open_in_new_rounded, size: 18, color: context.pal.faint),
                          onTap: () => _open(d.url),
                        ),
                    ]),
                  ),
                if (p.email != null)
                  Center(
                    child: TextButton.icon(
                      onPressed: () => _open('mailto:${p.email}'),
                      icon: const Icon(Icons.mail_outline_rounded),
                      label: Text(p.email!),
                    ),
                  ),
              ],
            ),
    );
  }
}

/// Открыть текстовую страницу.
Future<void> openInfoPage(BuildContext context, String slug, {String? title}) =>
    Navigator.of(context).push(MaterialPageRoute(builder: (_) => InfoPageScreen(slug: slug, title: title)));
