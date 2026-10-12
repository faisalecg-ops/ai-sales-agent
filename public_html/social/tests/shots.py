# Screen checks (not deployed): signs in and photographs every /social page.
import sys, asyncio
from playwright.async_api import async_playwright
base, art, ig, fb, month = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], sys.argv[5]
OUT = '/tmp/claude-0/-home-claude-ai-sales-agent/40b5576f-35bf-5c09-a10b-a0b4d48fb7f6/scratchpad/shots/'
pages = [('dashboard','/social/'),('plan','/social/plan.php'),('articles','/social/articles.php'),('article','/social/article.php?id='+art),
         ('posts','/social/posts.php'),('post-ig','/social/post.php?id='+ig),('post-fb-due','/social/post.php?id='+fb),('calendar','/social/calendar.php?month='+month),
         ('media','/social/media.php'),('settings','/social/settings.php'),('connections','/social/connections.php'),('activity','/social/activity.php'),('guide','/social/guide.php'),
         ('newpost','/social/posts.php?new=1'),('exhibitors','/social/exhibitors.php'),('kit-admin','/social/exhibitors.php?kit='+(sys.argv[6] if len(sys.argv)>6 else '1')),
         ('post-failed','/social/post.php?id='+(sys.argv[7] if len(sys.argv)>7 else '1')),('kit-public','/social/kit.php?k='+(sys.argv[8] if len(sys.argv)>8 else 'x'))]
async def main():
    async with async_playwright() as p:
        b = await p.chromium.launch()
        for name, w in (('d', 1440), ('m', 390)):
            ctx = await b.new_context(viewport={'width': w, 'height': 900})
            pg = await ctx.new_page()
            errs = []
            pg.on('pageerror', lambda e: errs.append(str(e)))
            pg.on('console', lambda m: errs.append(m.text) if m.type == 'error' else None)
            await pg.goto(base + '/admin/login.php?next=/social/')
            await pg.fill('input[name=username]', 'boss'); await pg.fill('input[name=password]', 'Pass-boss-2026')
            await pg.click('button[type=submit]'); await pg.wait_for_load_state('networkidle')
            for key, path in pages:
                if name == 'm' and key in ('activity', 'guide', 'newpost', 'sales-admin', 'connections'):
                    continue
                r = await pg.goto(base + path, wait_until='networkidle')
                # Links on the page point at the live domain; keep the shot local.
                await pg.screenshot(path=OUT + name + '-' + key + '.png', full_page=True)
                over = await pg.evaluate('document.documentElement.scrollWidth > window.innerWidth + 1')
                print(name, key, r.status, 'OVERFLOW' if over else '')
            print('errors:', errs[:8])
            await ctx.close()
        await b.close()
import re, pathlib
cfg = pathlib.Path(__file__).resolve().parents[3] / 'config' / 'config.php'
orig = cfg.read_text()
cfg.write_text(re.sub(r"'base_url'\s*=>\s*'[^']*'", "'base_url' => '" + base + "'", orig, count=1))
try:
    asyncio.run(main())
finally:
    cfg.write_text(orig)
