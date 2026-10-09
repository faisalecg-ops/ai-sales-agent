# Build Asia starter articles — part 2: Building Materials, Housing & Real Estate

SRC_MARBLE = ('APP / Wealth Pakistan, "135 mines in Pakistan adopt mechanized mining techniques", 19 October 2025',
              'https://www.app.com.pk/?p=1088564')
SRC_PAINT = ('U.S. International Trade Administration, "Pakistan Paints and Coatings", market intelligence, 14 August 2023',
             'https://www.trade.gov/market-intelligence/pakistan-paints-and-coatings')
SRC_FURN = ('PACRA, Sector Study: Furniture & Fixtures, March 2026',
            'https://pacra.com/view/storage/app/PACRA%20Research%20-%20Furniture%20&%20Fixtures%20-%20Mar%2726_1772963696.pdf')
SRC_HOUSING = ('Business Recorder, opinion, 20 November 2025 (figures from PMRC, UN-Habitat and the ACASH Report 2023)',
               'https://www.brecorder.com/news/40393310')
SRC_REIT = ('ProPakistani, "SECP approves PSX listing of Naya Nazimabad Apartments REIT", 18 August 2026',
            'https://propakistani.pk/2026/08/18/secp-approves-psx-listing-of-naya-nazimabad-apartments-reit/')
SRC_LGS = ('NED University of Engineering & Technology, Center for Affordable Housing and Sustainable Built Environment (CAHSBE), "Light Gauge Steel for Low Rise Buildings"',
           'https://cahsbe.neduet.edu.pk/LGS')

ARTICLES = []

ARTICLES.append(dict(
    slug='pakistan-marble-granite-mechanised-quarrying',
    category='building-materials',
    featured=3,
    title='Pakistan’s marble and granite: vast reserves, and the push to quarry them better',
    excerpt='Reserves are estimated at 30 billion tonnes, yet exports average about $37 million a year. Mechanised quarrying is starting to change that.',
    tags=['Marble', 'Stone', 'Exports'],
    sources=[SRC_MARBLE],
    body='''
<p>Pakistan sits on some of the world’s most varied stone. Marble and granite deposits run across Khyber Pakhtunkhwa, Balochistan, Sindh, Punjab, Gilgit-Baltistan and Azad Jammu and Kashmir, with reserves estimated at around <strong>30 billion tonnes</strong>.</p>
<p>Yet the industry’s export earnings are modest: marble exports have averaged about <strong>$37 million a year</strong> over the past five years, mostly as raw or semi-processed blocks. The gap between potential and earnings comes down largely to how stone is taken out of the ground.</p>
<h2>The cost of blasting</h2>
<p>Traditional quarrying often relies on blasting, which shatters much of the stone and leaves blocks of irregular size. According to official documents reported by Wealth Pakistan, waste at quarries that have modernised has fallen from <strong>nearly 85% to about 45%</strong>.</p>
<h2>Mechanised quarrying is spreading</h2>
<p>The Pakistan Stone Development Company (PASDEC) reports that <strong>135 operational mines</strong> have adopted mechanised techniques with its help — controlled cutting of standard square blocks instead of blasting. The programme has drawn about Rs 1.16 billion in combined public and private investment and created more than 800 jobs. PASDEC has also provided around Rs 1 billion in subsidies to miners over the past decade.</p>
<h2>Why block quality matters</h2>
<ul>
<li><strong>Standard blocks</strong> can be cut efficiently into slabs and tiles, increasing value per tonne.</li>
<li><strong>Less waste</strong> means more saleable stone from the same deposit.</li>
<li><strong>Value addition</strong> — polishing, cutting to size, finished products — is where export margins are made.</li>
</ul>
<h2>Opportunities along the chain</h2>
<p>The shift opens doors for suppliers of wire saws, cutting and polishing lines, block handling equipment, and the training that goes with them — and for buyers who want consistent, export-grade stone from Pakistan.</p>
<p>Stonefair Asia runs alongside Build Asia, bringing quarry owners, processors and machinery suppliers together. Marble, granite and ceramic producers, and quarrying and finishing machinery suppliers, are core <a href="https://buildasia.net/exhibit-profile/">exhibitor categories</a>.</p>
'''))

ARTICLES.append(dict(
    slug='paints-and-coatings-market-room-to-grow',
    category='building-materials',
    title='Paints and coatings: a market with room to grow',
    excerpt='Pakistan uses about 1.2 kg of paint per person a year — low by international standards. What that means for decorative and protective coatings.',
    tags=['Paints', 'Coatings', 'Market data'],
    sources=[SRC_PAINT],
    body='''
<p>Paint is the last product a building receives and the first thing people see. In Pakistan it is also a market with a lot of room left to grow.</p>
<h2>The size of the market</h2>
<p>The U.S. International Trade Administration estimated Pakistan’s paints and coatings market at about <strong>$400 million</strong>, with local production of around 0.25 million tonnes according to the Pakistan Coating Association, and expected growth of <strong>3.5% to 4% a year</strong>. Its market note dates from August 2023, so current figures may differ, but the structure it describes still holds.</p>
<h2>Low use per person</h2>
<p>Consumption is about <strong>1.2 kg per person per year</strong>, for a population of over 240 million. That is low by international standards, which means that as incomes rise, more homes are finished and more buildings are maintained, demand has a long way to grow.</p>
<h2>Where the demand comes from</h2>
<ul>
<li><strong>Architectural (decorative) paint</strong> for homes, offices and public buildings — the largest use.</li>
<li><strong>Protective and industrial coatings</strong> for steel structures, bridges, factories and pipelines.</li>
<li><strong>Wood coatings</strong> for furniture, doors and fittings.</li>
<li><strong>Infrastructure</strong> — dams, roads and government-funded housing.</li>
</ul>
<h2>Trends to watch</h2>
<ul>
<li><strong>Water-based paints</strong> with low solvent content, better for indoor air and painters’ health.</li>
<li><strong>Heat-reflective roof and wall coatings</strong>, increasingly relevant in hot cities (<a href="{{a:designing-for-heat-karachi-lessons}}">see our piece on designing for heat</a>).</li>
<li><strong>Waterproof and anti-fungal coatings</strong> for humid coastal areas and monsoon seasons.</li>
<li><strong>Protective coatings for steel</strong>, as more buildings use steel frames.</li>
</ul>
<p>Paints, varnishes and coatings are one of Build Asia’s exhibitor categories, alongside the construction chemicals and waterproofing products that often go with them.</p>
'''))

ARTICLES.append(dict(
    slug='furniture-exports-germany-overtakes-us',
    category='building-materials',
    title='Furniture exports: Germany overtakes the US as the top market',
    excerpt='Pakistan’s furniture exports are small but finding buyers in Europe. A look at the latest figures and what holds the sector back.',
    tags=['Furniture', 'Wood', 'Exports'],
    sources=[SRC_FURN],
    body='''
<p>Pakistani furniture makers have a reputation for craftsmanship, especially in carved hardwood. Turning that into exports has proved harder, but the latest figures show where buyers are.</p>
<h2>The figures</h2>
<p>According to PACRA’s furniture sector study (March 2026), exports of furniture (HS code 9403) were:</p>
<table>
<thead><tr><th>Period</th><th>Exports, USD million</th><th>Imports, USD million</th></tr></thead>
<tbody>
<tr><td>FY23</td><td>9.0</td><td>4.1</td></tr>
<tr><td>FY24</td><td>8.4</td><td>3.0</td></tr>
<tr><td>FY25</td><td>7.9</td><td>5.9</td></tr>
<tr><td>Jul–Jan FY26</td><td>4.8</td><td>4.8</td></tr>
</tbody>
</table>
<h2>Where it goes</h2>
<p>In FY25 the United States (26.7%) and Germany (25.8%) together took more than half of exports. In the first seven months of FY26, <strong>Germany became the largest market at 27.5%</strong>, followed by the US (18.5%), the UK (16.7%), the UAE (8.6%) and Canada (3.0%).</p>
<h2>What holds the sector back</h2>
<ul>
<li><strong>Raw materials:</strong> forest cover is only about 5% of the country, and local timber supplies have been depleted. Makers increasingly use chipboard, metal and imported wood, which raises costs.</li>
<li><strong>Fragmentation:</strong> the sector is made up of many small workshops and a handful of large firms, which makes it hard to meet large export orders consistently.</li>
<li><strong>Rising imports:</strong> imports nearly doubled in FY25, as new homes and offices sought modern designs.</li>
</ul>
<h2>Opportunities</h2>
<p>PACRA links furniture demand to residential and commercial construction, urbanisation and a growing middle class. Easier interest rates should help. For manufacturers, the openings lie in modern designs for new apartments and offices, consistent quality for export, and partnerships with suppliers of panel processing and finishing machinery.</p>
<p>Furniture Asia runs alongside Build Asia, and home and office furniture, kitchen cabinets, wood and timber, and lamination machines are all exhibitor categories.</p>
'''))

ARTICLES.append(dict(
    slug='waterproofing-before-monsoon-checklist',
    category='building-materials',
    title='Waterproofing before the monsoon: a checklist for owners and builders',
    excerpt='Most leaks start at roofs, joints and wet areas. What to check, which systems to consider, and the questions to ask an applicator.',
    tags=['Waterproofing', 'Construction chemicals', 'Guide'],
    sources=[],
    body='''
<p>Water is the slowest and most expensive enemy of a building. Leaks damage plaster and paint, rust reinforcement inside concrete, and grow mould that affects health. Most problems start in a few predictable places — which means they can be prevented.</p>
<h2>Where to look first</h2>
<ul>
<li><strong>Roofs:</strong> cracks, ponding water, blocked drains, and the joint where roof meets parapet.</li>
<li><strong>Bathrooms and kitchens:</strong> around drains, pipe entries and the base of walls.</li>
<li><strong>Basements and water tanks:</strong> water pushing in from the soil or out from the tank.</li>
<li><strong>External walls:</strong> hairline cracks, window and door frames, and where services enter the building.</li>
<li><strong>Expansion joints</strong> in larger buildings, which need flexible sealants.</li>
</ul>
<h2>Common systems, in plain words</h2>
<ul>
<li><strong>Cementitious coatings:</strong> brushed or troweled on; suit tanks, basements and wet areas.</li>
<li><strong>Liquid membranes</strong> (acrylic, polyurethane): form a seamless flexible skin; suit roofs.</li>
<li><strong>Bituminous membranes:</strong> sheets laid on roofs and foundations; durable but need careful laying.</li>
<li><strong>Crystalline admixtures:</strong> added to concrete so it seals its own pores.</li>
<li><strong>Sealants:</strong> for joints, frames and pipe entries.</li>
</ul>
<h2>Questions to ask an applicator</h2>
<ol>
<li>How will the surface be prepared? Most failures come from poor preparation, not poor products.</li>
<li>Is the system suited to our roof — exposed to sun, walked on, or covered?</li>
<li>How thick will the coating be, and how many coats?</li>
<li>What guarantee is given, and who gives it — the applicator or the manufacturer?</li>
<li>Will you do a flood test on the roof before handing over?</li>
</ol>
<h2>Design it in from the start</h2>
<p>The cheapest waterproofing is the one planned at design stage: proper roof slopes, enough drains, details at every joint, and good concrete. Repairs after leaks start always cost more.</p>
<p>Waterproofing, insulation, sealants and adhesives, and construction chemicals are all exhibitor categories at Build Asia. <a href="{{a:paints-and-coatings-market-room-to-grow}}">See also: the paints and coatings market.</a></p>
'''))

ARTICLES.append(dict(
    slug='pakistan-housing-gap-numbers',
    category='housing-real-estate',
    featured=4,
    title='Pakistan’s housing gap: the numbers behind the shortage',
    excerpt='Need grows by about a million homes a year, cities build fewer than half of what they need, and mortgages are just 0.44% of GDP.',
    tags=['Housing', 'Affordable housing', 'Finance'],
    sources=[SRC_HOUSING],
    body='''
<p>Housing is the single largest driver of construction in Pakistan, and the gap between need and supply is the defining fact of the market. Figures compiled from the Pakistan Mortgage Refinance Company (PMRC), UN-Habitat and the ACASH Report (2023) show its shape.</p>
<h2>The headline gap</h2>
<ul>
<li>National need is put at about <strong>38.3 million</strong> housing units against a supply of about <strong>36.2 million</strong> — a shortfall of around <strong>2.1 million</strong> units.</li>
<li>Need grows by about <strong>1.06 million units a year</strong>, driven by population growth and new households.</li>
<li>In urban areas about <strong>350,000</strong> new units are needed each year, but only about <strong>150,000</strong> are built.</li>
<li>About <strong>13 million households</strong> are overcrowded — 6 million in towns and cities and 7 million in rural areas.</li>
</ul>
<h2>Who needs homes</h2>
<p>Most of the need is at the lower end of the market: about 217,000 units a year for lower-income households, 87,500 for lower-middle-income and 35,000 for higher-income households. In rural areas, much of the need is about quality — replacing homes that are unsafe or inadequate.</p>
<h2>The finance problem</h2>
<p>Mortgage lending is only about <strong>0.44% of GDP</strong>. Without long-term finance, most families build slowly from savings, one room or one floor at a time. That shapes the whole supply chain: materials are bought in small quantities, and construction stretches over years.</p>
<h2>What could close the gap</h2>
<ul>
<li><strong>Affordable design:</strong> smaller, well-planned units on serviced plots.</li>
<li><strong>Faster building systems:</strong> prefabrication and <a href="{{a:light-gauge-steel-framing-low-rise-homes}}">light gauge steel framing</a> can cut time on site.</li>
<li><strong>Finance:</strong> more mortgage lending, and new investment routes such as <a href="{{a:reits-pakistan-real-estate-investment}}">REITs</a>.</li>
<li><strong>Better use of land:</strong> mid-rise apartments in cities rather than ever-wider sprawl.</li>
</ul>
<p>Affordable housing was one of the themes of the PROPTECH Convention at the last Build Asia. Builders, developers, banks and housing finance companies are all part of the show’s audience.</p>
'''))

ARTICLES.append(dict(
    slug='reits-pakistan-real-estate-investment',
    category='housing-real-estate',
    title='REITs come of age: 29 schemes and a seventh listing on the PSX',
    excerpt='Real estate investment trusts are giving developers a new source of money and investors a way into property. Where the market stands in 2026.',
    tags=['REITs', 'Real estate', 'Finance'],
    sources=[SRC_REIT],
    body='''
<p>For most investors in Pakistan, putting money into property has meant buying a plot or a flat. Real estate investment trusts (REITs) offer another way: buying units in a fund that owns or develops property, traded like shares.</p>
<h2>Where the market stands</h2>
<p>According to a report on the approval of the Naya Nazimabad Apartments REIT in August 2026, Pakistan has <strong>29 registered REIT schemes</strong>, of which <strong>six are listed</strong> on the Pakistan Stock Exchange. The Naya Nazimabad Apartments REIT is set to become the seventh.</p>
<h2>A developmental REIT</h2>
<p>The Naya Nazimabad scheme is a closed-end <strong>developmental REIT</strong> under the REIT Regulations, 2022 — it raises money to build, rather than only to own finished buildings. The public offering covers 44.06 million units, 15% of the scheme, with 75% offered to institutional and high-net-worth investors and 25% to retail investors through book building. The SECP described it as the 13th public offering it had approved in 2026.</p>
<h2>Why it matters for construction</h2>
<ul>
<li><strong>Developers</strong> get access to money from many investors, not just bank loans or advance payments from buyers.</li>
<li><strong>Buyers</strong> can see a project funded before it is sold off-plan, which can reduce the risk of stalled schemes.</li>
<li><strong>Investors</strong> can own a share of property with smaller sums, and sell their units on the exchange.</li>
<li><strong>Transparency</strong> improves, because listed REITs must report regularly to the regulator and investors.</li>
</ul>
<h2>Things to keep in mind</h2>
<p>REIT units can fall in value, and developmental REITs carry construction risk. As with any investment, investors should read the offering document and take professional advice.</p>
<p>REITs and tokenisation were on the agenda of the PROPTECH Convention at Build Asia 2026. <a href="{{a:proptech-convention-2026-conference-themes}}">Read what the conference covered.</a></p>
'''))

ARTICLES.append(dict(
    slug='light-gauge-steel-framing-low-rise-homes',
    category='housing-real-estate',
    title='Light gauge steel framing: a faster way to build low-rise homes?',
    excerpt='Light, termite-proof and quick to assemble, light gauge steel is being tested for affordable housing in Pakistan. How it works and where it fits.',
    tags=['Prefabrication', 'Steel', 'Affordable housing'],
    sources=[SRC_LGS],
    body='''
<p>Most homes in Pakistan are built from brick, block and reinforced concrete — strong and familiar, but slow, heavy and labour-intensive. Light gauge steel (LGS) framing offers a different approach for low-rise buildings, and it is being studied seriously for affordable housing.</p>
<h2>What it is</h2>
<p>LGS framing uses thin, cold-formed steel sections — studs, joists and trusses — screwed together into wall and roof frames, then clad with boards and insulation. Sections are factory-made to exact sizes, so assembly on site is fast and precise.</p>
<h2>The benefits</h2>
<p>NED University’s Center for Affordable Housing and Sustainable Built Environment (CAHSBE) describes LGS as an attractive alternative to traditional reinforced concrete for low-rise buildings. It notes that:</p>
<ul>
<li>sections are light enough to carry by hand, and allow quick building without heavy tools or equipment;</li>
<li>steel is non-combustible, which is a code requirement for some types of structures;</li>
<li>it does not rot, warp or suffer termite attack;</li>
<li>frames are easy to modify later.</li>
</ul>
<h2>Being tested in Pakistan</h2>
<p>CAHSBE worked with the construction industry to build an affordable model house using LGS on the NED campus, and the university reports that LGS was considered as one of the technologies for the Naya Pakistan Housing initiative. Final-year engineering students at NED compare LGS designs with reinforced concrete alternatives for cost and time to completion.</p>
<h2>Questions for anyone considering it</h2>
<ol>
<li><strong>Approvals:</strong> does the local building authority accept the system for this use?</li>
<li><strong>Corrosion:</strong> what galvanising and detailing protect the steel, especially near the coast?</li>
<li><strong>Heat and sound:</strong> what insulation and boards are used, and how does the home perform in summer?</li>
<li><strong>Skills:</strong> are trained installers available, and who supervises them?</li>
<li><strong>Cost:</strong> how does the full cost — including finishes — compare with a conventional build?</li>
</ol>
<p>Light gauge steel framing has featured in Build Asia’s past conference programme, and pre-fabricated building components, pre-engineered steel buildings and prefab housing systems remain core exhibitor categories. <a href="{{a:pakistan-housing-gap-numbers}}">Why faster building matters: the housing gap.</a></p>
'''))
