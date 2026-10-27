import sys,json,io,re,html,base64,unicodedata,urllib.request,urllib.parse,os
from PIL import Image,ImageStat
# Prave fotografije za članak: Wikimedia Commons (direktno, najbolje ocijenjene prvo), pa Pixabay.
# Upotreba: python3 slike.py "<naslovna>" "<slika 1>" "<slika 2>"
# Ispis: {"cover","coverCredit","inlineImages","inlineCredits","izvori"}; credit ide kao tekst ispod slike.
PIXABAY_KEY=os.environ.get("PIXABAY_KEY","")  # u rutini upisan ključ; nikad ga ne commitaj
USED=os.path.expanduser("~/.geovizija_used_images.json")
used=set(json.load(open(USED))) if os.path.exists(USED) else set()
UA={"User-Agent":"GeovizijaBot/1.0 (https://geovizija.com)"}
COMMONS="https://commons.wikimedia.org/w/api.php?"
# Slobodne licence (komercijalna upotreba i izmjene dozvoljene). NC i ND nisu.
FREE=re.compile(r"^(cc0|public domain|pd|cc by(-sa)? \d)",re.I)
# Nisu fotografije motiva: karte, grbovi, zastave, slike, crteži, skenovi...
JUNK=re.compile(r"\b(map|karta|mapa|flag|zastava|coat of arms|grb|logo|diagram|chart|painting|drawing|engraving|illustration|lithograph|postcard|stamp|poster|scan|document|banknote|coin|svg)\b",re.I)
DETAIL=("fresco","icon","detail","interior","inside","mosaic","altar","ceiling","close-up","closeup")
def get(url,t=30):
    return urllib.request.urlopen(urllib.request.Request(url,headers=UA),timeout=t).read()
def fold(s):
    return "".join(c for c in unicodedata.normalize("NFKD",s.lower().replace("đ","dj")) if not unicodedata.combining(c))
def fits(q,text):
    # Vlastita imena (veliko slovo) moraju biti u opisu slike; od ostalih riječi bar pola.
    text=fold(text); w=[fold(x) for x in q.split() if len(x)>3]
    names=[fold(x) for x in q.split() if x[:1].isupper()]
    if names and not all(n[:6] in text for n in names): return False
    return sum(1 for x in w if x[:5] in text)>=max(1,len(w)//2)
def plain(s):
    return re.sub(r"\s+"," ",html.unescape(re.sub(r"<[^>]+>","",s or ""))).strip()
def commons(q,cover):
    p={"action":"query","format":"json","generator":"search","gsrsearch":q+" filetype:bitmap","gsrnamespace":6,"gsrlimit":40,
       "prop":"imageinfo|categories","iiprop":"size|url|mime|extmetadata|user","iiurlwidth":2560 if cover else 1600,
       "iiextmetadatafilter":"LicenseShortName|Artist|ImageDescription","cllimit":"max","clshow":"!hidden"}
    try: pages=json.loads(get(COMMONS+urllib.parse.urlencode(p))).get("query",{}).get("pages",{})
    except Exception: return []
    out=[]
    for x in pages.values():
        ii=(x.get("imageinfo") or [{}])[0]; m=ii.get("extmetadata",{})
        lic=plain(m.get("LicenseShortName",{}).get("value"))
        cats=" ".join(c["title"] for c in x.get("categories",[]))
        title=x["title"][5:]
        if ii.get("mime")!="image/jpeg" or not FREE.match(lic) or JUNK.search(title): continue
        w,h=ii.get("width",0),ii.get("height",0)
        if w<1600 or w<h*1.2: continue
        if not fits(q,title+" "+cats+" "+plain(m.get("ImageDescription",{}).get("value"))[:300]): continue
        # Najbolje ocijenjene fotografije prve, zatim redoslijed pretrage, rezolucija i oblik blizu 16:9.
        score=(40-x.get("index",40))*1.5
        score+=30 if "Featured pictures" in cats else 22 if "Quality images" in cats else 15 if "Valued images" in cats else 0
        score+=8 if w>=3000 else 0
        score-=abs(w/h-16/9)*10
        # Naslovna prikazuje cijeli motiv, ne detalj (osim kad upit traži baš detalj).
        if cover: score-=sum(25 for d in DETAIL if d in fold(title) and d not in fold(q))
        # Autor: tekst prvog linka (korisnik) ako postoji, bez dodatnih napomena o licenci.
        raw=m.get("Artist",{}).get("value") or ""
        a=re.search(r"<a[^>]*href=\"[^\"]*/wiki/User:[^\"]*\"[^>]*>(.*?)</a>",raw,re.S)
        name=plain(a.group(1)) if a else plain(raw)
        name=re.sub(r"(?i)^user:","",name)
        by=re.search(r"(?i)\b(?:taken|photographed|made) by ([^.,;]+)",name)
        if by: name=by.group(1)
        name=re.split(r"\s+(?:You are|Attribution|Please|\(talk\)|- )",name)[0].strip()
        # Slobodan tekst umjesto imena (molbe, napomene): onda korisnik koji je postavio sliku.
        if not name or len(name)>60 or len(name.split())>6 or re.search(r"(?i)(own work|http|this photo|notified|gallery|^photo)",name): name=ii.get("user") or "nepoznat autor"
        artist=name
        out.append((score,"wikimedia",ii.get("thumburl") or ii["url"],"wm:"+title,f"Foto: {artist} / Wikimedia Commons, {lic}"))
    return [c[1:] for c in sorted(out,reverse=True)]
def pixabay(q,cover):
    if not PIXABAY_KEY: return []
    u="https://pixabay.com/api/?"+urllib.parse.urlencode({"key":PIXABAY_KEY,"q":q[:100],"image_type":"photo","orientation":"horizontal","per_page":30,"safesearch":"true","min_width":1600,"order":"popular"})
    try: r=json.loads(get(u))["hits"]
    except Exception: return []
    return [("pixabay",x["largeImageURL"],"px%s"%x["id"],f"Foto: {x.get('user') or 'Pixabay'} / Pixabay") for x in r if fits(q,x.get("tags",""))]
def looks_bad(im):
    # Crno-bijela, izmaglica bez kontrasta, pretamna, tamna bez boje ili izblijedjela fotografija.
    s=im.copy(); s.thumbnail((200,200))
    L=ImageStat.Stat(s.convert("L")); light,contrast=L.mean[0],L.stddev[0]
    color=ImageStat.Stat(s.convert("HSV").split()[1]).mean[0]
    return color<12 or contrast<25 or light<40 or (light<70 and color<40) or light>215
def fetch(q,cover):
    # Naslovna ostaje velika (do 2560 px, kvalitet 90): server iz nje pravi i Instagram i story.
    size,quality=(2560,90) if cover else (1600,85)
    spare=None  # najbolja odbačena zbog kvaliteta, ako nijedna druga ne prođe
    for src in (commons,pixabay):
        for name,url,uid,credit in src(q,cover)[:8]:
            if uid in used: continue
            try:
                im=Image.open(io.BytesIO(get(url,40))).convert("RGB")
                if im.width<1000 or im.width<im.height: continue
                im.thumbnail((size,size)); b=io.BytesIO(); im.save(b,"JPEG",quality=quality,optimize=True)
                if b.tell()>3_000_000: continue
                found=("data:image/jpeg;base64,"+base64.b64encode(b.getvalue()).decode(),f"{name}: {uid}",credit,uid)
                if looks_bad(im):
                    spare=spare or found; continue
                used.add(uid)
                return found[:3]
            except Exception: continue
    if spare: used.add(spare[3]); return spare[:3]
    return None,None,None
qs=sys.argv[1:4]; res=[fetch(q,i==0) for i,q in enumerate(qs)]
out={"izvori":[r[1] or "nema" for r in res]}
if res[0][0]: out["cover"]=res[0][0]; out["coverCredit"]=res[0][2]
inl=[]
for r in res[1:]:
    if not r[0]: break  # druga slika ne smije zauzeti mjesto prve scene
    inl.append(r)
if inl: out["inlineImages"]=[r[0] for r in inl]; out["inlineCredits"]=[r[2] for r in inl]
json.dump(sorted(used),open(USED,"w"))
print(json.dumps(out,ensure_ascii=False))
