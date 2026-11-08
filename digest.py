#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
digest.py — かなめ結びの記録(MD)から、思考分析の素材(digest)を作る。

SYSTEM.md と組み合わせて Claude Code で使う。ブラウザ版(toi.html)の
「解凍→選択→digest生成」のうち、選択とdigest生成をコマンドラインで担う。
出力(標準出力)を Claude Code に渡し、SYSTEM.md の指示で問いを受ける。

使い方の例:
  # 全件
  python3 digest.py ./kaname_data > digest.txt
  # 特定シリーズだけ（複数可・部分一致）
  python3 digest.py ./kaname_data --series 呪術廻戦 --series 定本 > digest.txt
  # 単一シリーズの話数範囲（何話から何話）
  python3 digest.py ./kaname_data --series 呪術廻戦 --from 5 --to 12 > digest.txt
  # 時刻メモのある記録だけに絞る
  python3 digest.py ./kaname_data --with-timeline > digest.txt

MDの構造は md.php / toi.html と同じ:
  front matter(key: 値) → ## 時刻メモ(任意) → ## 総評 → 末尾 score
"""
import argparse, glob, os, re, sys

def parse_md(text):
    """md.php の kaname_parse と同じ分解。fields/timeline_notes/entries/review/score を返す。"""
    lines = re.split(r'\r\n|\r|\n', text)
    fields = {}
    mode = 'front'
    tl, rv, score = [], [], ''
    for line in lines:
        if mode == 'front':
            if line.strip() == '## 時刻メモ':
                mode = 'timeline'; continue
            if line.strip() == '## 総評':
                mode = 'review'; continue
            m = re.match(r'^([a-zA-Z_]+):\s?(.*)$', line)
            if m:
                if m.group(1) == 'score':
                    score = m.group(2)
                else:
                    fields[m.group(1)] = m.group(2)
        elif mode == 'timeline':
            if line.strip() == '## 総評':
                mode = 'review'; continue
            tl.append(line)
        else:  # review
            m = re.match(r'^score:\s?(.*)$', line)
            if m:
                score = m.group(1); continue
            rv.append(line)
    notes = '\n'.join(tl).strip()
    entries = []
    if notes:
        for l in notes.split('\n'):
            m = re.match(r'^-\s*\[(\d+):(\d{2})(?::(\d{2}))?\]\s?(.*)$', l)
            if m:
                if m.group(3) is not None:
                    t = int(m.group(1)) * 3600 + int(m.group(2)) * 60 + int(m.group(3))
                else:
                    t = int(m.group(1)) * 60 + int(m.group(2))
                entries.append((t, m.group(4)))
    return {
        'fields': fields,
        'timeline_notes': notes,
        'entries': entries,
        'review': '\n'.join(rv).strip(),
        'score': score,
    }

def load_records(data_dir):
    recs = []
    for path in sorted(glob.glob(os.path.join(data_dir, '*.md'))):
        base = os.path.splitext(os.path.basename(path))[0]
        if not re.match(r'^\d{6}$', base):
            continue
        with open(path, encoding='utf-8') as f:
            p = parse_md(f.read())
        p['id'] = base
        recs.append(p)
    return recs

def ep_int(r):
    try:
        return int(r['fields'].get('episode', '') or 0)
    except ValueError:
        return 0

def fmt_time(t):
    mm, ss = divmod(t, 60)
    return f'{mm}:{ss:02d}'

def make_digest(records):
    """toi.html の corpusDigest と同じ整形。"""
    parts = []
    for r in records:
        f = r['fields']
        head = f"【{r['id']}】{f.get('series','') or '（無題）'} 第{f.get('episode','?')}話"
        if f.get('subtitle'):
            head += f" 「{f['subtitle']}」"
        if f.get('tags'):
            head += f" ｜タグ:{f['tags']}"
        if r['score'] != '':
            head += f" ｜星:{r['score']}"
        lines = [head]
        if r['entries']:
            lines.append('  時刻メモ:')
            for t, text in r['entries']:
                lines.append(f'    [{fmt_time(t)}] {text}')
        if r['review']:
            lines.append('  総評: ' + r['review'].replace('\n', ' / '))
        parts.append('\n'.join(lines))
    return '\n\n'.join(parts)

def main():
    ap = argparse.ArgumentParser(
        description='かなめ結びの記録から思考分析の素材(digest)を作る。SYSTEM.mdと併用。')
    ap.add_argument('data_dir', help='MDフォルダ（例: ./kaname_data）')
    ap.add_argument('--series', action='append', default=[],
                    help='対象シリーズ名（部分一致・複数指定可）。省略で全シリーズ')
    ap.add_argument('--from', dest='ep_from', type=int, default=None,
                    help='話数の下限（この話数以上）')
    ap.add_argument('--to', dest='ep_to', type=int, default=None,
                    help='話数の上限（この話数以下）')
    ap.add_argument('--with-timeline', action='store_true',
                    help='時刻メモのある記録だけに絞る')
    ap.add_argument('--list-series', action='store_true',
                    help='素材を出さず、含まれるシリーズ名と話数レンジの一覧だけ表示')
    args = ap.parse_args()

    if not os.path.isdir(args.data_dir):
        print(f'フォルダが見つかりません: {args.data_dir}', file=sys.stderr)
        sys.exit(1)

    records = load_records(args.data_dir)
    if not records:
        print('6桁MDが見つかりませんでした。', file=sys.stderr)
        sys.exit(1)

    # --list-series: シリーズ一覧を出して終わり
    if args.list_series:
        groups = {}
        for r in records:
            name = r['fields'].get('series', '') or '（無題）'
            groups.setdefault(name, []).append(ep_int(r))
        for name in sorted(groups, key=lambda s: s):
            eps = groups[name]
            lo, hi = min(eps), max(eps)
            rng = f'{lo}〜{hi}' if hi > lo else f'{lo}'
            print(f'{name}\t{len(eps)}話（{rng}）')
        return

    # 絞り込み
    sel = records
    if args.series:
        sel = [r for r in sel
               if any(s in (r['fields'].get('series', '') or '') for s in args.series)]
    if args.ep_from is not None:
        sel = [r for r in sel if ep_int(r) >= args.ep_from]
    if args.ep_to is not None:
        sel = [r for r in sel if ep_int(r) <= args.ep_to]
    if args.with_timeline:
        sel = [r for r in sel if r['entries']]

    if not sel:
        print('条件に合う記録がありませんでした。--list-series で確認してください。',
              file=sys.stderr)
        sys.exit(1)

    # 話数範囲を指定したのに複数シリーズが残る場合は注意（範囲は単一シリーズ向け）
    series_names = {r['fields'].get('series', '') or '（無題）' for r in sel}
    if (args.ep_from is not None or args.ep_to is not None) and len(series_names) > 1:
        print(f'※注意: 話数範囲を指定しましたが対象シリーズが{len(series_names)}件あります。'
              f'範囲は全シリーズに一律適用されます。', file=sys.stderr)

    # id 順で安定させる
    sel.sort(key=lambda r: r['id'])
    print(f'# 対象: {len(sel)}件 / {len(series_names)}シリーズ', file=sys.stderr)
    print(make_digest(sel))

if __name__ == '__main__':
    main()
