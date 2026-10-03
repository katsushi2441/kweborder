#!/usr/bin/env python3
"""OGP（1200×630）。  /usr/bin/python3 scripts/make_ogp.py"""
import os, io, urllib.request
from PIL import Image, ImageDraw, ImageFont
R = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
B = '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc'; N = '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'
im = Image.new('RGB', (1200, 630), (244, 247, 249)); d = ImageDraw.Draw(im)
d.rectangle((0, 0, 1200, 12), fill=(10, 143, 133))
logo = Image.open(io.BytesIO(urllib.request.urlopen('https://exbridge.jp/images/logo-mark-128.png').read())).convert('RGBA').resize((48, 48))
im.paste(logo, (60, 56), logo)
d.text((120, 62), 'Kurage Web Order', font=ImageFont.truetype(B, 32), fill=(7, 117, 109))
d.text((60, 140), '取引先が品番で注文する', font=ImageFont.truetype(B, 62), fill=(20, 34, 47))
d.text((60, 220), 'BtoBのWEB受注', font=ImageFont.truetype(B, 62), fill=(20, 34, 47))
f = ImageFont.truetype(N, 27)
for k, t in enumerate(('メールアドレスで取引先が登録・1社に複数人', '品番で注文・出荷先を選ぶ・得意先別の価格', '受注済で成立 → 出荷手配 → 出荷済・CSVで基幹へ')):
    d.text((60, 330 + k * 44), '・' + t, font=f, fill=(60, 75, 90))
# 右：受注一覧の見本カード
x0, y0 = 760, 120
d.rounded_rectangle((x0, y0, 1150, 520), radius=18, fill=(255, 255, 255), outline=(221, 230, 236), width=2)
d.text((x0 + 24, y0 + 20), '受注一覧', font=ImageFont.truetype(B, 26), fill=(20, 34, 47))
rows = [('注文受付', (255, 241, 201)), ('受注済', (223, 243, 238)), ('出荷手配中', (223, 234, 251)), ('出荷済', (231, 226, 251))]
for i, (s, c) in enumerate(rows):
    y = y0 + 80 + i * 72
    d.line((x0 + 20, y - 10, 1130, y - 10), fill=(230, 236, 240), width=1)
    d.text((x0 + 24, y + 6), f'2610{10+i}-{"ABCD"[i]}{"7392"[i]}', font=ImageFont.truetype(N, 22), fill=(60, 75, 90))
    d.rounded_rectangle((x0 + 220, y + 4, x0 + 360, y + 40), radius=18, fill=c)
    d.text((x0 + 236, y + 8), s, font=ImageFont.truetype(B, 20), fill=(20, 34, 47))
m = Image.open('/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png').convert('RGBA'); m = m.resize((120, int(120 * m.height / m.width)))
im.paste(m, (60, 630 - m.height - 16), m)
d.text((200, 572), '株式会社エクスブリッジ', font=ImageFont.truetype(N, 22), fill=(93, 107, 122))
im.save(os.path.join(R, 'public', 'kwo_ogp.png'), optimize=True); print('ok')
