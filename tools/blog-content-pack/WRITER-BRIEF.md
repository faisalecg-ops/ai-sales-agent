# Brief for writers of Build Asia blog articles — second batch (growth sectors)

You are writing for the **Build Asia Blog**. It is the blog of Build Asia, Pakistan's international building materials and construction machinery exhibition. The 21st edition runs 18–20 March 2027 at the Karachi Expo Centre.

**Readers:** builders, contractors, architects, suppliers and dealers in Pakistan, and also international manufacturers, project investors and partners looking at the Pakistani market.

## The angle of this batch (important)

Each article shows **the opportunity in a sector related to the exhibition**. It does this through facts, not slogans:

- **Scale of demand:** money allocated, projects in the pipeline, growth rates, homes needed.
- **Gaps:** what is imported because local supply is short, what is under-served, where capacity or technology is lacking.
- **Policy and incentives:** tax measures, SEZ incentives, facilitation bodies, financing from development banks.
- **Who is already taking part:** international contractors, lenders and manufacturers named in the sources, joint ventures and technology partners.

What to do:
- Add a short section such as "Where the openings are" or "What this means for suppliers and partners". It turns the facts into plain observations, for example: "imports of X show demand that local makers do not yet meet".
- Add one honest line on what to watch (risks or delays mentioned in the sources). Balance is what makes the piece credible.
- End with a natural Build Asia line. For example: Build Asia 2027 brings project owners, contractors and suppliers from Pakistan and abroad together at the Karachi Expo Centre (the January 2026 edition had exhibitors from over 21 countries). Link to `{{a:build-asia-2027-what-to-expect}}`, the exhibitor profile, stand booking or registration.

What not to do:
- **Do not** write "foreign investors should invest" or "invest in Pakistan now". There must be no explicit sales pitch for foreign investment and no hype.
- **Do not** promise returns, give financial advice or make political claims.
- **Do not** write as if every reader is foreign. Write for anyone weighing the market.

## Look at the existing articles first

`tools/blog-content-pack/articles_1.py`, `articles_2.py` and `articles_3.py` hold the 21 existing articles. Match their format, tone and length.

## Output format

Write a Python file that defines `ARTICLES = []` and appends `dict(...)` entries. Each entry has these keys:

- `slug`: use the slug you are given.
- `category`: use the category slug you are given.
- `title`: under about 90 characters. Specific and factual. Use typographic apostrophes (’) and dashes (–).
- `excerpt`: one or two sentences, at most 220 characters.
- `tags`: 2 or 3 short tags.
- `sources`: a list of `(title_with_publisher_and_date, url)` tuples, for example `('Business Recorder, "PSDP …", 12 June 2026', 'https://…')`.
- `body`: an HTML string in `'''…'''`. Allowed tags:
  - `<p>`, `<h2>`, `<ul>`, `<ol>`, `<li>`
  - `<strong>`, `<em>`
  - `<table>`, `<thead>`, `<tbody>`, `<tr>`, `<th>`, `<td>`
  - `<a href>`, `<blockquote>`

  Do not use `<h1>`, inline styles, images or scripts.

Each article is **300–420 words** of body text. Use at least two `<h2>` subheadings. Include a table or list where it helps.

## Hard rules (these are checked by an independent fact-checker)

1. **Use only facts from sources you actually opened in this session.**
   - Every number, name, date and quote must appear in a source you fetched and read, and that source must be listed in `sources`.
   - Do not rely on memory for figures.
   - If you cannot confirm something, leave it out.
   - Make each fact's date clear in the text (for example "in FY26", "as of June 2026").
   - Do not quote people unless the source quotes them.
2. **Prefer recent, reputable sources.** Good sources for Pakistan include:
   - Business Recorder, Dawn, Profit / Pakistan Today, The Express Tribune, ProPakistani, APP and The News.
   - Official sources: SBP, PBS, Planning Commission and PSDP documents, ministries, BOI, SIFC, the PPP Authority and WAPDA.
   - Ratings agencies: PACRA, VIS, S&P, Fitch and Moody's (news reports of their actions are fine).
   - International bodies: World Bank, ADB, IMF, IFC and AIIB.

   Use sources from 2025 to 2026, the newer the better. Use 1–3 sources per article. Today is 10 October 2026. If WebFetch cannot open a page, try another source; never cite a page you could not read.
3. **Links in the body may only point to:**
   - the article's own `sources` URLs (exactly the same URL);
   - other blog articles, written as `{{a:slug}}`, using slugs from the list below or from `NEW-SLUGS.txt`;
   - these Build Asia pages:
     - https://buildasia.net/exhibit-profile/
     - https://buildasia.net/visitor-profile/
     - https://buildasia.net/karachi/
     - https://buildasia.net/karachi/exhibitors/
     - https://buildasia.net/contact/?enquiry=stand
     - https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web (register to visit)

   No other links.
4. **Build Asia facts are limited to these.** Do not invent anything else (for example country pavilions or delegations):
   - the 21st edition, 18–20 March 2027, Karachi Expo Centre;
   - the January 2026 Karachi edition had over 32,000 trade visitors, over 350 exhibitors and over 21 countries.
5. **Write in neutral, helpful British English** (organisation, metres, tonnes). Separate facts from observations clearly.
6. **Do not copy sentences from sources.** Summarise in your own words. Quote only short phrases, in quotation marks, and only where the source quotes them.
7. **Check that the file runs:** `cd tools/blog-content-pack && python3 -c "import articles_N; print(len(articles_N.ARTICLES))"`.

## Existing article slugs (for `{{a:...}}` links)

cement-sales-fy26-what-the-numbers-say, construction-grows-fy26-economic-survey, five-numbers-construction-outlook-2027, construction-machinery-imports-rise, buy-rent-or-lease-construction-equipment, heavy-machinery-site-safety-checklist, pakistan-marble-granite-mechanised-quarrying, paints-and-coatings-market-room-to-grow, furniture-exports-germany-overtakes-us, waterproofing-before-monsoon-checklist, pakistan-housing-gap-numbers, reits-pakistan-real-estate-investment, light-gauge-steel-framing-low-rise-homes, buildings-electricity-energy-conservation-building-code, rooftop-solar-7000-mw-building-design, designing-for-heat-karachi-lessons, build-asia-2027-what-to-expect, build-asia-2026-in-numbers, planning-your-visit-build-asia-karachi, exhibiting-shell-scheme-or-bare-space, proptech-convention-2026-conference-themes

Do not repeat what these already cover. Link to them instead.

## When you finish

Reply with a short list for each article:
- the slug;
- the word count;
- the sources used;
- any fact you were unsure of and removed.
