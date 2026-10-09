# Notes for the Build Asia website chat (from the Event Blog chat, 9 October 2026)

The Event Blog is installed separately inside the website's `public_html/blog`
folder (its own files, database tables `blog_…`, admin at `/blog/admin`). The
website stays at buildasia.ecgateway.net for review and moves to
**buildasia.net**. Three things on the website side:

1. **Install instructions:** the website's README tells installers to delete
   everything inside `public_html` (except `cgi-bin` and `.well-known`). Once the
   blog is installed, that would delete it. The instruction should also keep
   the `blog` folder. Upgrades that only overwrite files are fine.
2. **robots.txt** is produced by `app/pages/robots.php`, not a file. In
   production, add a second sitemap line:
   `Sitemap: https://buildasia.net/blog/sitemap.xml`.
3. **Menu:** add a "Blog" (or "News & insights") link to `/blog/` in the
   header and/or footer menu.

The website's front controller already serves real folders directly
(`RewriteCond %{REQUEST_FILENAME} -d`), and its security headers are sent by
its own PHP, so they do not affect `/blog`. No other website change is needed.

The blog's default look is taken from the website's theme (`main.css`): navy
`#142a5c`, royal `#1f4fbf`, amber `#f7b10b`, sky `#eef3fb`, Archivo (wide
headlines), the slanted amber/navy band, the counter strip and the scroll
motion (rise-and-fade stagger, band drawing across, header compaction, gentle
drift). If the website's colours change, change them in the blog's admin →
Branding too.
