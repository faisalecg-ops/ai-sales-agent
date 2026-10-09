import sys, json, shutil, os, random, math
sys.path.insert(0,'.')
from PIL import Image, ImageDraw, ImageFont
from articles_1 import ARTICLES as A1
from articles_2 import ARTICLES as A2
from articles_3 import ARTICLES as A3
OUT='/home/claude/ai-sales-agent/blog/content-packs/buildasia'
os.makedirs(OUT+'/images', exist_ok=True)
NAVY=(20,42,92); DEEP=(13,29,66); ROYAL=(31,79,191); AMBER=(247,177,11)
# logos
lg=Image.open('/tmp/claude-0/ba-site/public_html/assets/img/buildasia-logo.webp').convert('RGBA')
lg.save(OUT+'/images/buildasia-logo.png')
white=Image.new('RGBA',lg.size,(255,255,255,0)); px=lg.load(); wp=white.load()
for y in range(lg.size[1]):
    for x in range(lg.size[0]):
        a=px[x,y][3]
        if a: wp[x,y]=(255,255,255,a)
white.save(OUT+'/images/buildasia-logo-white.png')
shutil.copy('/tmp/claude-0/ba-site/public_html/assets/img/slides/buildasia-21-karachi-2027.jpg', OUT+'/images/buildasia-21-karachi-2027-banner.jpg')
# slider backgrounds (text is added by the blog)
def slide(name, seed, hue):
    W,H=1920,860; im=Image.new('RGB',(W,H),hue); ov=Image.new('RGBA',(W,H),(0,0,0,0)); d=ImageDraw.Draw(ov); r=random.Random(seed)
    # skyline of towers on the right
    x=900
    while x<W:
        w=r.randint(70,150); h=r.randint(220,620); d.rectangle([x,H-h,x+w,H],fill=(255,255,255,r.randint(14,34)))
        for wy in range(H-h+25,H-20,46):
            for wx in range(x+14,x+w-20,32):
                if r.random()<.35: d.rectangle([wx,wy,wx+14,wy+20],fill=(247,177,11,r.randint(60,150)))
        x+=w+r.randint(10,40)
    # crane
    d.line([(1500,H),(1500,110)],fill=(255,255,255,70),width=16); d.line([(1180,130),(1840,130)],fill=(255,255,255,70),width=14)
    d.line([(1720,130),(1720,360)],fill=(255,255,255,60),width=4); d.rectangle([1690,360,1750,410],fill=AMBER+(200,))
    d.polygon([(0,H-70),(W*0.2,H-70),(W*0.2-30,H),(0,H)],fill=AMBER+(255,)); d.polygon([(W*0.215,H-70),(W*0.7,H-70),(W*0.7-30,H),(W*0.215-30,H)],fill=ROYAL+(255,))
    Image.alpha_composite(im.convert('RGBA'),ov).convert('RGB').save(OUT+'/images/'+name, quality=82, optimize=True, progressive=True)
slide('slide-build-asia-2027.jpg', 7, NAVY); slide('slide-industry-insights.jpg', 11, DEEP)
for a in A1+A2+A3:
    shutil.copy('covers/'+a['slug']+'.jpg', OUT+'/images/cover-'+a['slug']+'.jpg')

order=['build-asia-2027-what-to-expect','cement-sales-fy26-what-the-numbers-say','construction-machinery-imports-rise','pakistan-marble-granite-mechanised-quarrying',
       'pakistan-housing-gap-numbers','buildings-electricity-energy-conservation-building-code']
arts=[]
for a in A1+A2+A3:
    b=dict(a); b['body']=b['body'].strip(); b['image']='cover-'+a['slug']+'.jpg'
    b['sources']=[{'title':t,'url':u} for t,u in a['sources']]
    b['featured']= order.index(a['slug'])+1 if a['slug'] in order else 0
    b.pop('is_lead',None)
    arts.append(b)
# the 2027 article shows the official banner inside the text
for b in arts:
    if b['slug']=='build-asia-2027-what-to-expect':
        b['body']=b['body'].replace('<h2>Why it matters</h2>','<figure>{{img:buildasia-21-karachi-2027-banner.jpg|Build Asia 2027 — 18–20 March, Karachi Expo Centre}}<figcaption>The official banner of the 21st edition.</figcaption></figure>\n<h2>Why it matters</h2>',1)

pack={
 'name':'Build Asia — starter content', 'version':'2026-10-09',
 'description':'Six real-world categories and 21 researched starter articles for the Build Asia blog, with cover images, the official logo, the link library, counter figures, menus, slider and event details. Facts checked against published sources and Build Asia\'s approved information on 9 October 2026.',
 'author':{'name':'Build Asia Editorial Desk','title':'Build Asia','bio':'News, analysis and practical guides on Pakistan\'s building and construction industry, from the team behind Build Asia, Pakistan\'s international building materials and construction machinery exhibition.'},
 'settings_if_empty':{
   'site_name':'Build Asia Blog','site_tagline':'News and insight for Pakistan\'s building and construction industry',
   'event_name':'Build Asia','event_tagline':'International Building Materials & Construction Machinery Show',
   'event_start':'2027-03-18','event_end':'2027-03-20','event_venue':'Karachi Expo Centre','event_city':'Karachi','event_country':'Pakistan',
   'main_site_url':'https://buildasia.net','register_url':'https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web','book_stand_url':'https://buildasia.net/contact/?enquiry=stand',
   'contact_address':'Ecommerce Gateway Pakistan (Pvt) Ltd, 8 Kokan Society, Dr Azhar Hussain Road, near Kokan Park, off Shaheed-e-Millat Road, Karachi 74000, Pakistan',
   'contact_phone':'(92-21) 111-222-444','contact_email':'info@buildasia.net',
   'organization_name':'Build Asia',
   'seo_home_title':'Construction industry news and insight from Pakistan | Build Asia Blog',
   'seo_home_description':'Market data, building materials, machinery, housing and sustainable building in Pakistan — news and practical guides from Build Asia.',
   'stats_caption':'{event} in numbers — the January 2026 Karachi edition',
   'chat_agent':'buildasia','chat_base_url':'https://salesagent.ecgateway.net',
   'mail_from_name':'Build Asia Secretariat','mail_from_email':'info@buildasia.net',
 },
 'social_links':{'facebook':'https://facebook.com/buildasia','x':'https://twitter.com/BuildAsia','linkedin':'https://linkedin.com/in/buildasia/'},
 'stats':[{'value':32000,'suffix':'+','label':'Trade visitors'},{'value':350,'suffix':'+','label':'Exhibitors'},{'value':21,'suffix':'+','label':'Countries'},{'value':190000,'suffix':'+ sq ft','label':'Exhibition space'}],
 'branding':{'logo_light':'buildasia-logo.png','logo_dark':'buildasia-logo-white.png','favicon':'buildasia-logo.png'},
 'categories':[
   {'slug':'market-insights','name':'Market Insights','description':'Data and analysis on Pakistan\'s construction economy: cement, steel, investment, imports and the outlook for builders and suppliers.'},
   {'slug':'construction-machinery','name':'Construction Machinery','description':'Cranes, earthmoving and lifting equipment: market trends, buying decisions, rental and site safety.'},
   {'slug':'building-materials','name':'Building Materials','description':'Cement, steel, stone, paints, waterproofing, wood and finishes — the products that make Pakistan\'s buildings.'},
   {'slug':'housing-real-estate','name':'Housing & Real Estate','description':'Housing need, affordable building systems, property finance and REITs in Pakistan.'},
   {'slug':'sustainable-building','name':'Sustainable Building','description':'Energy efficiency, solar, heat-resilient design and green construction for Pakistan\'s climate.'},
   {'slug':'exhibition-news','name':'Exhibition News','description':'Build Asia dates, results, conference themes and guides for visitors and exhibitors.'},
 ],
 'links':[
   {'label':'Register to visit (E-Pass)','url':'https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web','kind':'register','keywords':'register, visitor pass, e-pass, register online'},
   {'label':'Book a stand','url':'https://buildasia.net/contact/?enquiry=stand','kind':'stand','keywords':'book a stand, exhibit, stand booking'},
   {'label':'Exhibitor profile','url':'https://buildasia.net/exhibit-profile/','kind':'exhibitors','keywords':'exhibitor profile, exhibitor categories, who exhibits'},
   {'label':'Visitor profile','url':'https://buildasia.net/visitor-profile/','kind':'other','keywords':'visitor profile, who visits'},
   {'label':'Build Asia Karachi 2027','url':'https://buildasia.net/karachi/','kind':'other','keywords':'build asia 2027, karachi edition'},
   {'label':'Exhibitors at Build Asia Karachi','url':'https://buildasia.net/karachi/exhibitors/','kind':'exhibitors','keywords':'exhibitor list, exhibitors'},
   {'label':'Travel and hotels — Karachi','url':'https://buildasia.net/karachi/travel/','kind':'venue','keywords':'travel, hotels, venue, karachi expo centre'},
   {'label':'Photo gallery','url':'https://buildasia.net/gallery/','kind':'other','keywords':'gallery, photos'},
   {'label':'About Build Asia','url':'https://buildasia.net/about/','kind':'other','keywords':'about build asia, organiser'},
   {'label':'Contact the Build Asia team','url':'https://buildasia.net/contact/','kind':'other','keywords':'contact, secretariat'},
 ],
 'main_menu':[{'label':'Market Insights','cat':'market-insights'},{'label':'Machinery','cat':'construction-machinery'},{'label':'Materials','cat':'building-materials'},
              {'label':'Housing','cat':'housing-real-estate'},{'label':'Sustainability','cat':'sustainable-building'},{'label':'Build Asia News','cat':'exhibition-news'}],
 'footer_menu':[{'label':'About Build Asia','url':'https://buildasia.net/about/'},{'label':'Exhibitor profile','url':'https://buildasia.net/exhibit-profile/'},
                {'label':'Visitor profile','url':'https://buildasia.net/visitor-profile/'},{'label':'Book a stand','url':'https://buildasia.net/contact/?enquiry=stand'},
                {'label':'Gallery','url':'https://buildasia.net/gallery/'},{'label':'Contact','url':'https://buildasia.net/contact/'}],
 'slides':[
   {'image':'slide-build-asia-2027.jpg','headline':'Build Asia 2027 · 18–20 March','text':'Pakistan\'s international building materials and construction machinery show, Karachi Expo Centre.','button_label':'Register to visit','button_url':'https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web','ends_at':'2027-03-20 13:00:00'},
   {'image':'slide-industry-insights.jpg','headline':'Insight for the people who build Pakistan','text':'Market data, materials, machinery and practical guides for builders, suppliers and investors.','button_label':'Latest articles','button_url':'{{blog}}latest'},
 ],
 'articles':arts,
}
json.dump(pack, open(OUT+'/pack.json','w'), ensure_ascii=False, indent=1)
print(len(arts),'articles;', sum(1 for a in arts if a['featured']), 'featured')
from collections import Counter; print(Counter(a['category'] for a in arts))
