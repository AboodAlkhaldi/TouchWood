# Rebuilding the wording file

Copied from the previous agents' notes on 2026-11-05. Keep it current; this copy is now the one to update.


Owner's review workbook: sheets How to use, Words, Outside lang files. Last built 2026-11-05 from main 8f6a5b7a: 6,918 pairs, 29 outside lines, saved in Downloads.

To rebuild, in a scratch folder W, changing no checkout:
1. `git fetch origin`, then `git archive origin/main lang src/Modules resources/js | tar -x -C W/src`, and write `git rev-parse origin/main` to `W/commit.txt`.
2. `PAO_DISABLE=1 php W/dump.php W/src > W/rows.json` (W as a C:/ path for php and python).
3. `python -I W/build.py W <output.xlsx>`, then compare the cells with rows.json and send the file with SendUserFile.

Covers only lang/{en,ar}/*.php and Arabic lines in resources/js .ts/.tsx (comments and code included). English typed directly in code or screens is NOT covered. tests/ fixtures stay out on purpose.

## dump.php
```php
<?php
// Flattens every lang/{en,ar}/*.php under the exported tree into JSON rows.
$root = $argv[1];
$rows = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$flat = function ($a, $p, &$out) use (&$flat) { foreach ($a as $k => $v) { $key = $p === '' ? (string) $k : "$p.$k"; if (is_array($v)) { $flat($v, $key, $out); } else { $out[$key] = (string) $v; } } };
foreach ($it as $f) {
  $path = str_replace(chr(92), '/', $f->getPathname());
  if (!preg_match('#^(.*)/lang/(en|ar)/(.+)\.php$#', $path, $m)) continue;
  $data = (function ($file) { return include $file; })($f->getPathname());
  if (!is_array($data)) continue;
  $out = []; $flat($data, '', $out);
  $base = substr($m[1], strlen($root) + 1);
  foreach ($out as $k => $v) { $rows[] = ['base' => $base, 'lang' => $m[2], 'file' => $m[3], 'key' => $k, 'text' => $v]; }
}
echo json_encode($rows, JSON_UNESCAPED_UNICODE);
```

## build.py
```python
import json, re, os, sys
from openpyxl import Workbook
from openpyxl.styles import Font, Alignment, PatternFill
from openpyxl.utils import get_column_letter

W = sys.argv[1]; OUT = sys.argv[2]
rows = json.load(open(f'{W}/rows.json', encoding='utf-8'))
commit = open(f'{W}/commit.txt', encoding='utf-8').read().strip()
pairs = {}
for r in rows:
    k = (r['base'], r['file'], r['key'])
    pairs.setdefault(k, {})[r['lang']] = r['text']

def area(base):
    m = re.match(r'src/Modules/([^/]+)/', base)
    return m.group(1) if m else 'Shared'

F = Font(name='Arial', size=10); FB = Font(name='Arial', size=10, bold=True)
YEL = PatternFill('solid', start_color='FFF2CC'); HEAD = PatternFill('solid', start_color='D9D9D9'); RED = PatternFill('solid', start_color='F8CBAD')
LTR = Alignment(wrap_text=True, vertical='top'); RTL = Alignment(wrap_text=True, vertical='top', horizontal='right', readingOrder=2)

wb = Workbook()
how = wb.active; how.title = 'How to use'
lines = [
 ('TouchWood wording review', FB),
 (f'Every sentence in the language files of main at commit {commit[:8]} ({len(pairs):,} pairs), English and Arabic side by side.', F),
 ('', F),
 ('How to correct:', FB),
 ('1. Go to the "Words" tab. Use the filter on Area or File to work one part at a time.', F),
 ('2. Do NOT change columns A to E. Write your correction only in the yellow columns F (English) and G (Arabic).', F),
 ('3. Leave F and G empty where the sentence is fine. Use H for a note to me if something needs explaining.', F),
 ('4. Keep placeholders like :count, :name, :seconds exactly as they are - they are replaced by real values.', F),
 ('5. Rows with a red Key are missing in one language.', F),
 ('6. The "Outside lang files" tab lists Arabic written directly in the screens code: just tell me in column D what to change.', F),
 ('7. Send the file back and I put every correction in its file.', F),
 ('', F),
 ('Example row (how a correction looks):', FB),
]
for i, (t, f) in enumerate(lines, 1):
    c = how.cell(row=i, column=1, value=t); c.font = f
ex = ['Inventory', 'admin_stock.php', 'history_notes.shipped_beyond', ':count more shipped than counted - recount', 'شُحن :count أكثر من المعدود - أعِد الجرد', '', 'شُحنت :count قطعة أكثر من المعدود - أعِد العدّ', 'clearer Arabic']
hdr = ['Area', 'File', 'Key', 'English (now)', 'Arabic (now)', 'English (your correction)', 'Arabic (your correction)', 'Note']
r0 = len(lines) + 1
for j, h in enumerate(hdr, 1):
    c = how.cell(row=r0, column=j, value=h); c.font = FB; c.fill = HEAD
for j, v in enumerate(ex, 1):
    c = how.cell(row=r0 + 1, column=j, value=v); c.font = F; c.alignment = RTL if j in (5, 7) else LTR
    if j in (6, 7): c.fill = YEL
how.column_dimensions['A'].width = 95
for j, w in enumerate([14, 22, 30, 40, 40, 40, 40, 20], 1):
    if j > 1: how.column_dimensions[get_column_letter(j)].width = w

ws = wb.create_sheet('Words')
for j, h in enumerate(hdr, 1):
    c = ws.cell(row=1, column=j, value=h); c.font = FB; c.fill = HEAD; c.alignment = LTR
order = sorted(pairs, key=lambda k: (area(k[0]) != 'Shared', area(k[0]), k[1], k[2]))
for i, k in enumerate(order, 2):
    p = pairs[k]
    vals = [area(k[0]), k[1] + '.php', k[2], p.get('en', ''), p.get('ar', ''), None, None, None]
    for j, v in enumerate(vals, 1):
        c = ws.cell(row=i, column=j, value=v); c.font = F
        c.alignment = RTL if j in (5, 7) else LTR
        if j in (6, 7): c.fill = YEL
    if 'en' not in p or 'ar' not in p:
        ws.cell(row=i, column=3).fill = RED
for j, w in enumerate([14, 22, 34, 50, 50, 40, 40, 24], 1):
    ws.column_dimensions[get_column_letter(j)].width = w
ws.freeze_panes = 'D2'; ws.auto_filter.ref = f'A1:H{len(order) + 1}'

ow = wb.create_sheet('Outside lang files')
for j, h in enumerate(['File', 'Line', 'Code line with Arabic', 'What to change (your note)'], 1):
    c = ow.cell(row=1, column=j, value=h); c.font = FB; c.fill = HEAD
ar = re.compile('[؀-ۿ]'); n = 2
base = f'{W}/src/resources/js'
for dp, _, fs in os.walk(base):
    for fn in sorted(fs):
        if not fn.endswith(('.ts', '.tsx')): continue
        fp = os.path.join(dp, fn)
        for ln, line in enumerate(open(fp, encoding='utf-8'), 1):
            if ar.search(line):
                for j, v in enumerate([os.path.relpath(fp, f'{W}/src').replace(os.sep, '/'), ln, line.strip()[:300], None], 1):
                    c = ow.cell(row=n, column=j, value=v); c.font = F; c.alignment = LTR
                    if j == 4: c.fill = YEL
                n += 1
for j, w in enumerate([45, 7, 90, 40], 1):
    ow.column_dimensions[get_column_letter(j)].width = w
ow.freeze_panes = 'A2'

wb.save(OUT)
print('pairs', len(order), 'missing one side', sum(1 for k in order if len(pairs[k]) < 2), 'outside lines', n - 2)
```
