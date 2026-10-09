# Build Asia starter articles — part 1: Market Insights, Construction Machinery
# Facts only from the sources listed in each article (opened 9 Oct 2026).

SRC_CEMENT = ('Profit by Pakistan Today, "Cement dispatches rise 7.21% to 50.515 million tonnes in FY26", 4 July 2026',
              'https://profit.pakistantoday.com.pk/2026/07/04/cement-dispatches-rise-721percent-to-50515-million-tonnes-in-fy26')
SRC_GDP = ('Asia News Network, "Pakistan economy grows 3.7pc in FY26 — fastest in four years, but short of target", 12 June 2026',
           'https://asianews.network/pakistan-economy-grows-3-7pc-in-fy26-fastest-in-four-years-but-short-of-target/')
SRC_PACRA_MACH = ('PACRA, Sector Study: Machinery, January 2026',
                  'https://www.pacra.com/view/storage/app/PACRA%20Research%20-%20Machinery%20-%20Jan%2726_1767447674.pdf')
SRC_HOUSING = ('Business Recorder, opinion, 20 November 2025 (figures from PMRC, UN-Habitat and the ACASH Report 2023)',
               'https://www.brecorder.com/news/40393310')
SRC_SOLAR = ('ProPakistani, "Pakistan\'s solar boom reaches 7,000 MW as govt moves to replace net metering", 17 July 2026',
             'https://propakistani.pk/2026/07/17/pakistans-solar-boom-reaches-7000-mw-as-govt-moves-to-replace-net-metering/')

ARTICLES = []

ARTICLES.append(dict(
    slug='cement-sales-fy26-what-the-numbers-say',
    category='market-insights',
    featured=1,
    title='Cement sales rise 7.2% in FY26: what the numbers say about building activity',
    excerpt='Pakistan’s cement makers despatched 50.5 million tonnes in 2025-26, led by a 9.5% jump at home. A closer look at what the figures tell builders and suppliers.',
    tags=['Cement', 'Market data', 'FY26'],
    sources=[SRC_CEMENT],
    body='''
<p>Cement is the most reliable early signal of how much is actually being built in Pakistan. Every housing scheme, road and factory floor starts with it, and the industry reports its despatches every month. That makes the full-year figures for 2025-26 worth a careful look.</p>
<h2>The headline numbers</h2>
<p>According to figures reported by the All Pakistan Cement Manufacturers Association (APCMA), total despatches reached <strong>50.515 million tonnes</strong> in FY26, up <strong>7.21%</strong> from 47.116 million tonnes the year before.</p>
<table>
<thead><tr><th>FY26</th><th>Million tonnes</th><th>Change on FY25</th></tr></thead>
<tbody>
<tr><td>Total despatches</td><td>50.515</td><td>+7.21%</td></tr>
<tr><td>Domestic sales</td><td>41.507</td><td>+9.5%</td></tr>
<tr><td>Exports</td><td>9.008</td><td>−2.19%</td></tr>
</tbody>
</table>
<p>The growth came from home. Domestic sales rose by 3.6 million tonnes while exports slipped slightly — a sign that demand from local construction, rather than overseas buyers, carried the year.</p>
<h2>North and south tell different stories</h2>
<p>Mills in the north sold <strong>34.72 million tonnes</strong> at home, up 10.83%, while their exports fell by more than half. Mills in the south grew domestic sales more slowly (up 3.18% to 6.79 million tonnes) but increased exports by 9.36% to 8.23 million tonnes, helped by their access to the ports.</p>
<h2>A strong finish to the year</h2>
<p>June 2026 was particularly busy: despatches rose <strong>18.38%</strong> year on year to 4.33 million tonnes, with domestic sales up 26.78%. An APCMA spokesperson said demand was “expected to remain strong” in the coming months, while noting that high fuel and energy costs continue to weigh on the sector.</p>
<h2>What it means for the supply chain</h2>
<ul>
<li><strong>Builders and contractors</strong> can read rising domestic despatches as evidence that projects are moving from planning to the ground.</li>
<li><strong>Suppliers of steel, aggregates, construction chemicals and machinery</strong> usually follow cement: higher pours mean more rebar, admixtures, pumps and mixers.</li>
<li><strong>Energy costs</strong> remain the industry’s main pressure point — one reason efficient kilns, waste-heat recovery and alternative fuels keep coming up in industry discussions.</li>
</ul>
<p>Cement producers, admixture suppliers and the equipment makers who serve them meet buyers at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a> in Karachi. Companies in the supply chain can see the <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a> to judge whether the audience fits.</p>
'''))

ARTICLES.append(dict(
    slug='construction-grows-fy26-economic-survey',
    category='market-insights',
    title='Construction grows 5.7% in FY26 as Pakistan’s economy picks up',
    excerpt='The Economic Survey 2025-26 puts construction growth at 5.73%, ahead of industry as a whole. What drove it, and why it matters for the year ahead.',
    tags=['Economy', 'Market data', 'FY26'],
    sources=[SRC_GDP, SRC_CEMENT],
    body='''
<p>When the Finance Minister, Muhammad Aurangzeb, released the Pakistan Economic Survey 2025-26 in Islamabad on 11 June 2026, construction stood out as one of the brighter spots in the provisional figures.</p>
<h2>The provisional figures</h2>
<table>
<thead><tr><th>FY26 (provisional)</th><th>Growth</th></tr></thead>
<tbody>
<tr><td>GDP</td><td>3.7%</td></tr>
<tr><td>Industry</td><td>3.51%</td></tr>
<tr><td><strong>Construction</strong></td><td><strong>5.73%</strong></td></tr>
<tr><td>Large-scale manufacturing</td><td>6.1%</td></tr>
<tr><td>Services</td><td>4.09%</td></tr>
<tr><td>Agriculture</td><td>2.89%</td></tr>
</tbody>
</table>
<p>GDP growth of 3.7% was the fastest in four years, though below the 4.2% target. Within industry, construction grew well ahead of the sector average and was described as a positive contributor.</p>
<h2>It matches what the factories saw</h2>
<p>The survey figure is consistent with the cement industry’s own numbers: domestic cement sales rose 9.5% over the same year, to 41.5 million tonnes (<a href="{{a:cement-sales-fy26-what-the-numbers-say}}">see our analysis of the FY26 cement figures</a>). Large-scale manufacturing, which includes many building-material producers, grew 6.1% — its best result in four years, with 16 of 22 sub-sectors growing.</p>
<h2>Why it matters</h2>
<p>Construction is one of the economy’s largest employers and pulls dozens of other industries with it, from steel and glass to transport and finance. Faster growth in construction usually means:</p>
<ul>
<li>more demand for building materials and finishing products;</li>
<li>more investment in machinery and equipment by contractors;</li>
<li>more work for architects, engineers, consultants and skilled trades.</li>
</ul>
<p>The figures are provisional and will be revised, but the direction is clear. For companies planning their next year, the question is less whether demand exists than where it will come from: housing, infrastructure, industrial buildings or energy-efficient retrofits.</p>
<p>Those conversations happen in person at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, where builders, developers and government agencies meet suppliers. Trade visitors can <a href="https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web">register online</a> before the show.</p>
'''))

ARTICLES.append(dict(
    slug='five-numbers-construction-outlook-2027',
    category='market-insights',
    title='Five numbers that shape Pakistan’s construction outlook for 2027',
    excerpt='Growth, cement, housing need, mortgages and solar: five figures every builder, supplier and investor should keep in mind going into 2027.',
    tags=['Outlook', 'Market data', 'Housing'],
    sources=[SRC_GDP, SRC_CEMENT, SRC_HOUSING, SRC_SOLAR],
    body='''
<p>Forecasts come and go, but a handful of published figures explain most of what will happen in Pakistan’s building industry next year. Here are five worth keeping in view.</p>
<h2>1. 5.73% — construction growth in FY26</h2>
<p>The Economic Survey 2025-26 puts provisional construction growth at 5.73%, ahead of industry overall (3.51%). <a href="{{a:construction-grows-fy26-economic-survey}}">Read more on the survey figures.</a></p>
<h2>2. 50.5 million tonnes — cement despatched in FY26</h2>
<p>Total despatches rose 7.21%, and domestic sales 9.5%, to 41.5 million tonnes. Cement is the earliest hard signal of building activity. <a href="{{a:cement-sales-fy26-what-the-numbers-say}}">See the full breakdown.</a></p>
<h2>3. About 1.06 million — new homes needed every year</h2>
<p>Figures compiled from the Pakistan Mortgage Refinance Company, UN-Habitat and the ACASH Report put the annual increase in housing need at about 1.06 million units. In cities alone, about 350,000 new units are needed each year, but only around 150,000 are built. <a href="{{a:pakistan-housing-gap-numbers}}">More on the housing gap.</a></p>
<h2>4. 0.44% — mortgage finance as a share of GDP</h2>
<p>The same sources put mortgage lending at just 0.44% of GDP. Most families still build or buy with savings, which keeps a large part of demand slow and incremental. Any growth in housing finance would change the pace of the market.</p>
<h2>5. About 7,000 MW — rooftop solar under net metering</h2>
<p>Net-metered solar grew from 190 MW in FY2020 to about 6,978 MW by FY2026, according to the Power Division’s energy adviser. Buildings are becoming power producers, which changes how roofs, wiring and structures are designed. <a href="{{a:rooftop-solar-7000-mw-building-design}}">How solar is reshaping building design.</a></p>
<h2>Reading them together</h2>
<p>The picture is of an industry growing again, with large unmet demand for homes, limited finance, and a fast shift towards energy-conscious buildings. Companies that offer affordable systems, faster building methods and energy-saving products are best placed for it.</p>
'''))

ARTICLES.append(dict(
    slug='construction-machinery-imports-rise',
    category='construction-machinery',
    featured=2,
    title='Construction machinery imports jump as contractors re-equip',
    excerpt='Imports of construction and mining machinery rose to $138 million in FY25, and the first months of FY26 point higher again. What the trend says about the market.',
    tags=['Machinery', 'Imports', 'Market data'],
    sources=[SRC_PACRA_MACH],
    body='''
<p>Pakistan builds with imported machines. Excavators, cranes, pumps and batching plants largely come from abroad, so import figures are a good guide to how confident contractors feel about the work ahead.</p>
<h2>What the figures show</h2>
<p>According to PACRA’s machinery sector study (January 2026), imports of construction and mining machinery were:</p>
<table>
<thead><tr><th>Period</th><th>USD million</th></tr></thead>
<tbody>
<tr><td>FY23</td><td>85</td></tr>
<tr><td>FY24</td><td>94</td></tr>
<tr><td>FY25</td><td>138</td></tr>
<tr><td>First five months of FY26</td><td>98</td></tr>
</tbody>
</table>
<p>That is a rise of roughly 47% in FY25 alone, and the first five months of FY26 already reached about 70% of the previous full year.</p>
<h2>Part of a wider machinery upturn</h2>
<p>All machinery imports rose to about <strong>$9.6 billion</strong> in FY25 — around 16.5% of the country’s total imports — and were up about 20% year on year in the first five months of FY26. PACRA noted they may exceed $10 billion in FY26. Construction and mining machinery is still a small share of that total, but its share has been growing.</p>
<h2>What is driving it</h2>
<p>PACRA points to several supports for demand: easing inflation (around 5.6% in December 2025), a lower policy rate (about 10.5%) that makes equipment finance cheaper, and a larger federal development budget. It also notes that most demand is met by imports and that local producers face stiff competition.</p>
<h2>What it means for buyers and sellers</h2>
<ul>
<li><strong>Contractors</strong> are replacing ageing fleets as financing costs ease. <a href="{{a:buy-rent-or-lease-construction-equipment}}">Our guide to buying, renting or leasing</a> covers the trade-offs.</li>
<li><strong>Dealers and manufacturers</strong> face buyers who compare total cost of ownership, after-sales support and spare-parts availability, not just the purchase price.</li>
<li><strong>Service companies</strong> — maintenance, parts, training and telematics — grow with every machine sold.</li>
</ul>
<p>Build Asia has a dedicated outdoor area for heavy machinery, more than 50,000 sq ft at the last Karachi edition, where buyers can see equipment working rather than in a brochure.</p>
'''))

ARTICLES.append(dict(
    slug='buy-rent-or-lease-construction-equipment',
    category='construction-machinery',
    title='Buy, rent or lease? A practical guide to construction equipment decisions',
    excerpt='How contractors can decide whether to own, rent or lease each machine — and the questions to ask suppliers before signing.',
    tags=['Machinery', 'Contractors', 'Guide'],
    sources=[],
    body='''
<p>Every contractor faces the same question with each machine: buy it, rent it when needed, or lease it over several years? There is no single right answer, but there is a sound way to decide.</p>
<h2>Start with utilisation</h2>
<p>The most important number is how many days a year the machine will actually work for you. A machine that works most of the year on your own projects usually costs less to own. One that is needed for a few weeks on a single job is almost always cheaper to rent.</p>
<h2>When buying makes sense</h2>
<ul>
<li>You have a steady pipeline of work that needs the machine for most of the year.</li>
<li>You have operators and maintenance staff who can look after it.</li>
<li>The machine holds its value well and has a good resale market.</li>
<li>Financing is available at a rate your projects can carry.</li>
</ul>
<h2>When renting makes sense</h2>
<ul>
<li>The need is short or occasional — a crane for a lift, a piling rig for foundations.</li>
<li>You want the latest model without tying up capital.</li>
<li>You would rather not manage transport, servicing and storage.</li>
</ul>
<h2>When leasing makes sense</h2>
<p>Leasing sits in between: predictable monthly payments, no large upfront outlay, and often the option to buy at the end. It suits companies with steady work but limited cash to spare, and lease payments can be easier to plan for than a loan.</p>
<h2>Questions to ask any supplier</h2>
<ol>
<li>What is the total cost over the period, including servicing, insurance and transport?</li>
<li>How quickly are spare parts available locally, and where?</li>
<li>Is operator training included?</li>
<li>What happens if the machine breaks down mid-project — is a replacement provided?</li>
<li>For used machines: what are the working hours, and is there a service record?</li>
</ol>
<h2>Think in fleets, not machines</h2>
<p>Many contractors own the machines they use every day — excavators, loaders, mixers — and rent the specialist ones. Reviewing the mix once a year keeps money from sitting in idle equipment.</p>
<p>Dealers, rental companies and finance providers can be compared side by side at Build Asia’s machinery halls and outdoor display. <a href="{{a:construction-machinery-imports-rise}}">Read why contractors are re-equipping.</a></p>
'''))

ARTICLES.append(dict(
    slug='heavy-machinery-site-safety-checklist',
    category='construction-machinery',
    title='Heavy machinery on site: a safety checklist for contractors',
    excerpt='Most machinery accidents are preventable. A practical daily checklist for site managers, operators and the people working around them.',
    tags=['Safety', 'Machinery', 'Guide'],
    sources=[],
    body='''
<p>Heavy machines make construction possible at scale — and they are behind many of the most serious accidents on building sites. Most of those accidents follow the same few patterns, which means most can be prevented with routine habits.</p>
<h2>Before work starts each day</h2>
<ul>
<li><strong>Walk-around inspection:</strong> tyres or tracks, hydraulic hoses, leaks, lights, mirrors, horn and reversing alarm.</li>
<li><strong>Check the operator:</strong> trained and authorised for that machine, rested, and wearing the right protective equipment.</li>
<li><strong>Check the ground:</strong> soft soil, slopes, trenches and buried services change what a machine can safely do.</li>
<li><strong>Check overhead:</strong> power lines are a leading cause of fatal accidents with cranes, tippers and excavators.</li>
</ul>
<h2>Keep people and machines apart</h2>
<ul>
<li>Mark exclusion zones around working machines and lifting areas.</li>
<li>Use a trained banksman (signaller) whenever a machine reverses or visibility is limited.</li>
<li>Plan walkways so workers do not cross the machine’s path.</li>
<li>Never allow passengers or riding on buckets and forks.</li>
</ul>
<h2>Lifting operations</h2>
<ul>
<li>Know the load weight and the crane’s capacity at that radius — not just its maximum.</li>
<li>Inspect slings, chains and hooks before each lift.</li>
<li>Stop lifting in high wind.</li>
</ul>
<h2>Maintenance and records</h2>
<p>Keep a service log for every machine and act on faults immediately. A machine that is “nearly fine” should not be on site. Lock out machines during maintenance so they cannot be started.</p>
<h2>Build a safety culture</h2>
<p>A short daily briefing, near-miss reporting without blame, and visible commitment from site management do more than any poster. Safety is also good business: accidents stop work, damage equipment and harm a company’s reputation.</p>
<p>Fire-fighting, safety and security equipment suppliers are among the exhibitor categories at Build Asia. <a href="{{a:buy-rent-or-lease-construction-equipment}}">See also: buy, rent or lease?</a></p>
'''))
