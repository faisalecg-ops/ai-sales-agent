import sys, textwrap, math, random
sys.path.insert(0,'.')
from PIL import Image, ImageDraw, ImageFont
from articles_1 import ARTICLES as A1
from articles_2 import ARTICLES as A2
from articles_3 import ARTICLES as A3
ARTS=A1+A2+A3
NAVY=(20,42,92); DEEP=(13,29,66); ROYAL=(31,79,191); AMBER=(247,177,11); SKY=(238,243,251); WHITE=(255,255,255)
CATS={'market-insights':('Market Insights',NAVY),'construction-machinery':('Construction Machinery',DEEP),'building-materials':('Building Materials',(24,52,110)),
      'housing-real-estate':('Housing & Real Estate',NAVY),'sustainable-building':('Sustainable Building',(16,58,88)),'exhibition-news':('Exhibition News',DEEP)}
W,H=1600,900
fb=ImageFont.truetype('archivo-black.ttf',78); fk=ImageFont.truetype('archivo-bold.ttf',30); fs=ImageFont.truetype('archivo-bold.ttf',24)
def motif(d,cat,seed):
    rnd=random.Random(seed)
    if cat=='market-insights':   # rising bars
        x=1100
        for i in range(7):
            h=120+i*45+rnd.randint(-25,25); d.rectangle([x+i*62,H-110-h,x+i*62+40,H-110],fill=(255,255,255,26))
    elif cat=='construction-machinery':  # crane lines
        d.line([(1250,H-110),(1250,170)],fill=(255,255,255,60),width=14); d.line([(1130,190),(1540,190)],fill=(255,255,255,60),width=12)
        d.line([(1250,170),(1150,190)],fill=(255,255,255,40),width=6); d.line([(1420,190),(1420,430)],fill=(255,255,255,50),width=4)
        d.rectangle([1395,430,1445,480],fill=(247,177,11,160))
    elif cat=='building-materials':  # stone blocks
        for r in range(5):
            for c in range(4):
                if rnd.random()<.75: d.rectangle([1110+c*112+(r%2)*56,190+r*112,1110+c*112+(r%2)*56+100,190+r*112+100],fill=(255,255,255,18+rnd.randint(0,22)))
    elif cat=='housing-real-estate':  # roofs
        for i,(x,s) in enumerate([(1100,170),(1270,220),(1430,140)]):
            base=H-110; d.rectangle([x,base-s,x+150,base],fill=(255,255,255,28)); d.polygon([(x-15,base-s),(x+75,base-s-90),(x+165,base-s)],fill=(255,255,255,40))
            for wy in range(base-s+30,base-30,55): d.rectangle([x+30,wy,x+60,wy+30],fill=(247,177,11,90)); d.rectangle([x+90,wy,x+120,wy+30],fill=(247,177,11,60))
    elif cat=='sustainable-building':  # sun + panels
        d.ellipse([1290,120,1450,280],fill=(247,177,11,170))
        for i in range(12):
            a=i*math.pi/6; d.line([(1370+math.cos(a)*100,200+math.sin(a)*100),(1370+math.cos(a)*135,200+math.sin(a)*135)],fill=(247,177,11,140),width=6)
        for i in range(4):
            x=1080+i*125; d.polygon([(x,H-150),(x+110,H-150),(x+140,H-260),(x+30,H-260)],fill=(255,255,255,40))
    else:  # exhibition: hall grid
        for c in range(5):
            for r in range(4): d.rectangle([1100+c*90,200+r*120,1100+c*90+70,200+r*120+90],outline=(255,255,255,55),width=3)
        d.rectangle([1280,320,1440,500],fill=(247,177,11,150))
for i,a in enumerate(ARTS):
    label,bg=CATS[a['category']]
    im=Image.new('RGB',(W,H),bg); ov=Image.new('RGBA',(W,H),(0,0,0,0)); d=ImageDraw.Draw(ov)
    motif(d,a['category'],i)
    # slanted band like the website
    d.polygon([(0,H-60),(W*0.22,H-60),(W*0.22-25,H),(0,H)],fill=AMBER+(255,))
    d.polygon([(W*0.235,H-60),(W*0.74,H-60),(W*0.74-25,H),(W*0.235-25,H)],fill=ROYAL+(255,))
    d.polygon([(W*0.755,H-60),(W,H-60),(W,H),(W*0.755-25,H)],fill=DEEP+(255,))
    im=Image.alpha_composite(im.convert('RGBA'),ov).convert('RGB'); d=ImageDraw.Draw(im)
    d.text((90,110),label.upper(),font=fk,fill=AMBER)
    lines=textwrap.wrap(a['title'],width=20)[:6]
    size=74 if len(lines)<=4 else 62; f=ImageFont.truetype('archivo-black.ttf',size)
    y=175
    for ln in lines: d.text((90,y),ln,font=f,fill=WHITE); y+=size+14
    d.text((90,H-130),'BUILD ASIA BLOG',font=fs,fill=(169,183,211))
    im.save(f"covers/{a['slug']}.jpg",quality=84,optimize=True,progressive=True)
print(len(ARTS),'covers')
