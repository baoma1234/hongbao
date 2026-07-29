# -*- coding: utf-8 -*-
from PIL import Image
import os

src = r'C:\Users\Administrator\.cursor\projects\c-wwwroot-caijin-com-7111\assets\c__Users_Administrator_AppData_Roaming_Cursor_User_workspaceStorage_e161ae7bc2d7633c0076c050ffe50107_images_image-597bf2b1-acd9-4817-9fb8-6e75b297b5dd.png'
out_dir = r'c:\wwwroot\caijin.com_7111\public\888\img\chat'
os.makedirs(out_dir, exist_ok=True)
im = Image.open(src).convert('RGBA')
w, h = im.size
pixels = im.load()

def is_dark(x, y, thr=35):
    r, g, b, a = pixels[x, y]
    return a < 8 or (r < thr and g < thr and b < thr)

# known mid gaps from prior run
gap_x, gap_y = 431, 324
boxes = {
    'scan': (8, 8, gap_x - 8, gap_y - 8),
    'add_friend': (gap_x + 8, 8, w - 8, gap_y - 8),
    'friend_req': (8, gap_y + 8, gap_x - 8, h - 8),
    'create_group': (gap_x + 8, gap_y + 8, w - 8, h - 8),
}

def trim_to_icon(box):
    x0, y0, x1, y1 = box
    # find bright content (icon plate is light)
    tx0, ty0, tx1, ty1 = x1, y1, x0, y0
    found = False
    for y in range(y0, y1):
        for x in range(x0, x1):
            r, g, b, a = pixels[x, y]
            if a > 10 and (r > 45 or g > 45 or b > 45):
                found = True
                if x < tx0: tx0 = x
                if y < ty0: ty0 = y
                if x > tx1: tx1 = x
                if y > ty1: ty1 = y
    if not found:
        return box
    pad = 2
    return (max(0, tx0 - pad), max(0, ty0 - pad), min(w - 1, tx1 + pad), min(h - 1, ty1 + pad))

def key_black(crop):
    data = list(crop.getdata())
    out = []
    for r, g, b, a in data:
        m = max(r, g, b)
        if m < 28:
            out.append((0, 0, 0, 0))
        elif m < 55:
            # soft edge against black
            alpha = int(255 * (m - 28) / 27.0)
            out.append((r, g, b, max(0, min(255, alpha))))
        else:
            out.append((r, g, b, a))
    crop.putdata(out)
    return crop

for name, box in boxes.items():
    t = trim_to_icon(box)
    crop = im.crop((t[0], t[1], t[2] + 1, t[3] + 1))
    crop = key_black(crop)
    cw, ch = crop.size
    side = max(cw, ch)
    canvas = Image.new('RGBA', (side, side), (0, 0, 0, 0))
    canvas.paste(crop, ((side - cw) // 2, (side - ch) // 2), crop)
    canvas = canvas.resize((128, 128), Image.LANCZOS)
    path = os.path.join(out_dir, 'plus_%s.png' % name)
    canvas.save(path, 'PNG')
    print('saved', name, t, canvas.size)
print('ok')
