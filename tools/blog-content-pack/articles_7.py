# Build Asia starter articles — part 7: Trade & Supply Chains (second batch, writer D)
# Facts only from the sources listed in each article (opened 10 Oct 2026).

SRC_APP_MACH_FY26 = ('APP, "Agricultural machinery, implements worth of $135.267 mln imported in FY2026", 5 August 2026',
                     'https://www.app.com.pk/?p=1258417')
SRC_PBC_11M = ('Pakistan Business Council, "Pakistan’s Trade in Goods Data, July 2025 – May 2026 vs. July 2024 – May 2025" (source: Pakistan Bureau of Statistics)',
               'https://www.pbc.org.pk/wp-content/uploads/Trade-in-Goods-Data-Jul-25-to-May-26-Vs.-Jul-24-to-May-25.pdf')
SRC_CEMNET_11M = ('CemNet / International Cement Review, "Pakistan’s cement exports show growth in 11MFY26", 6 July 2026',
                  'https://www.cemnet.com/News/story/181635/pakistan-s-cement-exports-show-growth-in-11mfy26.html')
SRC_GC_JUL26 = ('Global Cement, "Update on Pakistan, July 2026", 8 July 2026',
                'https://globalcement.com/news/analysis/20977-update-on-pakistan-july-2026')
SRC_APP_STONE = ('APP, "Pakistan’s marble and onyx exports to China stable in 2025", 10 February 2026',
                 'https://www.app.com.pk/?p=1160335')
SRC_KUN_UAP = ('Kun.uz, "Uzbekistan ratifies feasibility study agreement for Trans-Afghan railway", 10 February 2026',
               'https://kun.uz/en/news/2026/02/10/uzbekistan-ratifies-feasibility-study-agreement-for-trans-afghan-railway')
SRC_KABULNOW_BORDER = ('KabulNow, "Pakistan to Reopen Border Crossings and Transit Routes with Afghanistan", 9 August 2026',
                       'https://kabulnow.com/2026/08/pakistan-to-reopen-border-crossings-and-transit-routes-with-afghanistan/')
SRC_PROFIT_CAREC = ('Profit by Pakistan Today, "ADB warns of $360 million loan lapse for CAREC project due to delay in road works", 12 January 2026',
                    'https://profit.pakistantoday.com.pk/2026/01/12/adb-warns-of-360-million-loan-lapse-for-carec-project-due-to-delay-in-road-works/')
SRC_BARRICK_APR26 = ('Barrick, "Barrick Provides an Update on Reko Diq", 2 April 2026',
                     'https://www.barrick.com/English/news/news-details/2026/barrick-provides-an-update-on-reko-diq/default.aspx')
SRC_PROFIT_RD_FIN = ('Profit by Pakistan Today, "$3 billion project financing for Reko Diq advances with IFC, IDA loan approval", 18 June 2025',
                     'https://profit.pakistantoday.com.pk/2025/06/18/3-billion-project-financing-for-reko-diq-advances-with-ifc-ida-loan-approval/')
SRC_PROFIT_RD_RAIL = ('Profit by Pakistan Today, "Reko Diq project cost rises to $7.7bn, rail link financing planned", 10 September 2025',
                      'https://profit.pakistantoday.com.pk/2025/09/10/reko-diq-project-cost-rises-to-7-7bn-rail-link-financing-planned/')
SRC_DAWN_ML1 = ('Dawn, "Groundbreaking of ML-1 early next year: minister", 1 July 2026',
                'https://www.dawn.com/news/2012099')
SRC_TRIB_ML1 = ('The Express Tribune, "ML-1 moves closer to groundbreaking", 16 August 2026',
                'https://tribune.com.pk/story/2624113/ml-1-moves-closer-to-groundbreaking')
SRC_PROFIT_ML1 = ('Profit by Pakistan Today (citing Business Recorder), "Pakistan in advanced talks with ADB for $2bn loan to fund Karachi–Rohri ML-1 section", 16 February 2026',
                  'https://profit.pakistantoday.com.pk/2026/02/16/pakistan-in-advanced-talks-with-adb-for-2bn-loan-to-fund-karachi-rohri-ml-1-section-report')

ARTICLES = []

ARTICLES.append(dict(
    slug='construction-imports-what-pakistan-buys',
    category='trade-supply-chains',
    title='What Pakistan imports to build: machinery, steel and timber in FY26',
    excerpt='Machinery imports passed $10.9 billion in FY26, and construction and mining equipment grew fastest of the machinery lines. Official trade data shows where local supply still falls short.',
    tags=['Imports', 'Machinery', 'Trade data'],
    sources=[SRC_APP_MACH_FY26, SRC_PBC_11M],
    body='''
<p>Import figures are a useful map of the building supply chain. They show what contractors and factories need but cannot yet buy at home in the quantity or quality they want. The Pakistan Bureau of Statistics (PBS) data for 2025-26 gives a clear picture.</p>
<h2>Machinery: the biggest bill</h2>
<p>For the full year FY26 (July 2025 to June 2026), the machinery group cost <strong>$10.919 billion</strong>, up 5.95% from $10.307 billion in FY25, according to PBS figures reported by APP. In the first eleven months alone, machinery made up about 15.75% of all goods imported.</p>
<p>The machinery lines most closely tied to building, together with steel and timber (which sit outside the machinery group), moved as follows over July 2025 to May 2026, according to PBS data tabulated by the Pakistan Business Council:</p>
<table>
<thead><tr><th>Jul–May FY26</th><th>USD million</th><th>Change on a year earlier</th></tr></thead>
<tbody>
<tr><td>Construction and mining machinery</td><td>212.6</td><td>+65.8%</td></tr>
<tr><td>Power-generating machinery</td><td>745.9</td><td>+16.2%</td></tr>
<tr><td>Electrical machinery and apparatus</td><td>2,590.3</td><td>−24.5%</td></tr>
<tr><td>Iron and steel</td><td>2,369.0</td><td>+9.9%</td></tr>
<tr><td>Iron and steel scrap</td><td>1,771.5</td><td>+1.6%</td></tr>
<tr><td>Wood and cork</td><td>113.8</td><td>+25.0%</td></tr>
</tbody>
</table>
<h2>Reading the numbers</h2>
<ul>
<li><strong>Construction and mining machinery</strong> is still a small line, but it grew faster than any other line in the table, and faster than any other part of the machinery group. It continues the rise we described in <a href="{{a:construction-machinery-imports-rise}}">our earlier look at machinery imports</a>.</li>
<li><strong>Steel volumes rose faster than value.</strong> Iron and steel imports reached about 3.87 million tonnes in eleven months, up 22.6% by weight, while the bill rose only 9.9%. Scrap imports came to about 3.43 million tonnes, up 4.2% by weight.</li>
<li><strong>Timber</strong> is a small but fast-growing import, up a quarter on the year.</li>
<li><strong>Electrical machinery</strong> fell by almost a quarter. The data does not say why.</li>
</ul>
<h2>Where the openings are</h2>
<p>These are observations, not forecasts. Steady imports of steel and heavy equipment show demand that local makers do not yet fully meet. That leaves room for local assembly, dealership networks, spare parts, servicing and rental fleets around imported machines. Our article on <a href="{{a:steel-industry-capacity-demand-2026}}">Pakistan’s steel industry</a> looks at the domestic side.</p>
<p>What to watch: total imports rose 6.26% in the eleven months while exports fell 5.67%. In our view, a wider trade gap can bring tighter import conditions, which matters to buyers who rely on imported equipment.</p>
<p>Machinery suppliers, steel traders and contractors meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March at the Karachi Expo Centre. The January 2026 edition drew over 350 exhibitors from over 21 countries. See the <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a>.</p>
'''))

ARTICLES.append(dict(
    slug='building-materials-exports-markets',
    category='trade-supply-chains',
    title='Building-material exports: where Pakistan’s cement and stone are sold',
    excerpt='Cement export earnings rose in July–May FY26 while full-year volume held near 9 million tonnes, and Pakistan was China’s third-largest supplier of calcareous building stone in 2025.',
    tags=['Exports', 'Cement', 'Stone'],
    sources=[SRC_CEMNET_11M, SRC_GC_JUL26, SRC_APP_STONE],
    body='''
<p>Pakistan mostly imports for construction, but some building materials go the other way. Cement and natural stone are two of them. Their export figures show which overseas buyers already trust Pakistani supply.</p>
<h2>Cement: more revenue, flat volume</h2>
<p>The All Pakistan Cement Manufacturers Association put FY26 cement exports at about <strong>9 million tonnes</strong>, slightly down on the year, as reported by Global Cement in July 2026. Trade figures for the first eleven months (July 2025 to May 2026), reported by CemNet, show a better picture by value:</p>
<table>
<thead><tr><th>Jul–May</th><th>FY25</th><th>FY26</th><th>Change</th></tr></thead>
<tbody>
<tr><td>Volume (million tonnes)</td><td>8.02</td><td>8.11</td><td>+1.19%</td></tr>
<tr><td>Value (USD million)</td><td>287.9</td><td>316.3</td><td>+9.85%</td></tr>
</tbody>
</table>
<p>The value rose much faster than the tonnage, which suggests better prices per tonne. CemNet notes that Pakistan has historically shipped cement and clinker to Afghanistan, Sri Lanka, Brazil, the USA and parts of Africa.</p>
<h2>North and south moved apart</h2>
<p>Geography shaped the year. AKD Research, quoted by Global Cement, said exports from northern plants fell after the Afghan border closed in late 2025. Global Cement added that exports from the south rose on growing demand from Africa, but not enough to lift the national total. For domestic sales, see <a href="{{a:cement-sales-fy26-what-the-numbers-say}}">our FY26 cement figures</a>, and for new kilns see <a href="{{a:cement-exports-and-capacity-expansion}}">cement exports and capacity expansion</a>.</p>
<h2>Stone: a foothold in China</h2>
<p>Chinese customs data for 2025, reported by APP in February 2026, ranked Pakistan as China’s <strong>third-largest supplier</strong> of calcareous building stone, including onyx. It shipped about 31.7 million kg worth $8.10 million, behind Türkiye and Bulgaria. Marble and travertine cut into blocks or slabs rose to $5.41 million from $5.02 million. Crude granite shipments fell sharply while cut granite rose.</p>
<h2>What this means for suppliers and partners</h2>
<ul>
<li>Higher earnings per tonne suggest firmer export prices, and in FY26 southern producers fared better than northern ones.</li>
<li>The move from crude to cut stone is the value-addition story told in <a href="{{a:pakistan-marble-granite-mechanised-quarrying}}">our marble and granite article</a>. Cutting, polishing and finishing lines are the tools for it.</li>
<li>Other finished goods, such as <a href="{{a:furniture-exports-germany-overtakes-us}}">furniture</a>, are another export story worth following.</li>
</ul>
<p>What to watch: CemNet reported in July 2026 that the Afghan border had been closed for the past nine months. For later developments on the crossings, see <a href="{{a:regional-corridors-central-asia-trade}}">our article on regional corridors</a>.</p>
<p>Exporters, stone processors and buyers meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a> in Karachi, 18–20 March 2027.</p>
'''))

ARTICLES.append(dict(
    slug='regional-corridors-central-asia-trade',
    category='trade-supply-chains',
    title='Rail to Uzbekistan, roads under CAREC: Pakistan’s regional corridors in 2026',
    excerpt='A trans-Afghan railway is in feasibility, a decision to reopen Afghan crossings was reported in August 2026 and ADB road money has a deadline. What the corridors mean for construction.',
    tags=['Corridors', 'Railways', 'Central Asia'],
    sources=[SRC_KUN_UAP, SRC_KABULNOW_BORDER, SRC_PROFIT_CAREC],
    body='''
<p>Pakistan’s ports could give landlocked Central Asia a route to the sea. Three strands of regional work could turn that geography into trade: a railway through Afghanistan, the land border crossings themselves and road upgrades under the Central Asia Regional Economic Cooperation (CAREC) programme. Each involves building work, at very different stages.</p>
<h2>The Uzbekistan–Afghanistan–Pakistan railway</h2>
<ul>
<li><strong>Stage:</strong> feasibility. The three countries signed a framework agreement on 17 July 2025 for a joint technical and economic study of a Naibabad–Kharlachi line. Uzbekistan ratified it in February 2026.</li>
<li><strong>Route:</strong> Termez through Naibabad, Maidanshahr and Logar to Kharlachi.</li>
<li><strong>Scale:</strong> a 2018 proposal envisaged up to 20 million tonnes of cargo a year.</li>
<li><strong>Cost:</strong> estimates have ranged widely, from $4.6 billion (Uzbekistan Railways, 2022) to $8.2 billion (Pakistan’s Ministry of Railways). In autumn 2023 the cost was revised to about $7 billion.</li>
<li><strong>Funding:</strong> not yet confirmed. A build-operate-transfer public–private partnership is one option being considered.</li>
</ul>
<h2>The border: a reported decision to reopen</h2>
<p>Torkham was shut to travellers and cargo in October 2025 after several days of clashes. On 8 August 2026, Pakistani media reported that Interior Minister Mohsin Naqvi had told business representatives of a decision to reopen all crossings and transit routes with Afghanistan. As of KabulNow’s report the next day, the decision had not been officially confirmed, and putting it into practice depended on an official announcement. Check the current status before planning shipments. According to the Pakistan-Afghanistan Joint Chamber of Commerce and Industry, two-way trade peaked at about $2.5 billion in 2010 and was just over $1.8 billion in 2022-23.</p>
<h2>CAREC roads: money with a deadline</h2>
<p>Under Tranche III of ADB’s CAREC programme, Pakistan is widening sections of the N55 highway. The project is estimated at Rs170 billion and backed by a <strong>$360 million</strong> ADB loan. In January 2026, Profit reported that the project had been stalled for over eight months. The works are split into four lots, and the loan must be used by 31 December 2027.</p>
<h2>What this means for construction</h2>
<ul>
<li>A trans-Afghan line would need track, bridges, tunnels and terminals on a large scale, but it is years away and still unfunded.</li>
<li>The nearer-term work is roads, border facilities and logistics yards. For the wider road pipeline, see <a href="{{a:motorways-highways-pipeline-2026}}">motorways and highways in 2026</a>.</li>
</ul>
<p>What to watch: the border closure showed how quickly trade on these routes can stop, and the ADB loan for the N55 has a fixed deadline.</p>
<p>Contractors, logistics firms and suppliers meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, Karachi Expo Centre, 18–20 March 2027.</p>
'''))

ARTICLES.append(dict(
    slug='reko-diq-mining-construction-demand',
    category='trade-supply-chains',
    title='Reko Diq: a multi-billion-dollar mine, its financing, and the 2026 slowdown',
    excerpt='Phase 1 of the Reko Diq copper-gold project was costed at $5.6–6.0 billion, with up to $3 billion of project finance sought in 2025. In 2026 Barrick slowed work for a review. What it means for construction.',
    tags=['Mining', 'Reko Diq', 'Balochistan'],
    sources=[SRC_BARRICK_APR26, SRC_PROFIT_RD_FIN, SRC_PROFIT_RD_RAIL],
    body='''
<p>Reko Diq in Balochistan is one of the world’s largest undeveloped copper-gold deposits, according to Profit. The project is large, it has major international lenders, and as of 2026 its development is slowing down.</p>
<h2>Who owns it, and how big it is</h2>
<p>Barrick holds 50% of Reko Diq, with the rest split between Pakistan’s federal and provincial governments. Barrick has disclosed estimated capital costs of <strong>$5.6–6.0 billion for Phase 1</strong> and $3.3–3.6 billion for Phase 2 (100% basis). Profit reported an estimated mine life of 37 years.</p>
<h2>The financing package</h2>
<p>In June 2025 the company was raising up to <strong>$3 billion</strong> in limited-recourse project finance for Phase 1. On 12 June 2025, IFC and IDA approved up to $700 million of it. The lenders named included:</p>
<ul>
<li>ADB, the US DFC and US EXIM;</li>
<li>Export Development Canada, KfW IPEX-Bank and Euler Hermes;</li>
<li>Sweden’s EKN and Finland’s Finnvera;</li>
<li>a covered commercial bank tranche.</li>
</ul>
<h2>The rail link</h2>
<p>In September 2025 Profit reported a proposal, discussed at shareholder meetings, for <strong>$350 million</strong> to be routed through the mining company to Pakistan Railways as a loan for a line linking the mine to Port Qasim, as part of the ML-3 project. Under the proposal the railway would have three years to build it. For the separate main-line upgrade, see <a href="{{a:ml-1-railway-upgrade-status}}">our ML-1 article</a>.</p>
<h2>2026: a slowdown and a review</h2>
<p>On 5 February 2026 Barrick announced a review of all aspects of the project. On 2 April it said it would slow development and continue the review until mid-2027, citing the review’s early findings and escalating security issues in Pakistan and the region. Phase 1 development was approved on this reduced-spend basis. Barrick expects significant increases to the capital budget and the timeline, and the review will also look at financing and scope. First production had been targeted for the end of 2028.</p>
<h2>Where the openings are</h2>
<p>The following are observations. A mine of this size needs earthworks, a processing plant, camps, roads, power and water, and the rail line to the port. That means demand for heavy equipment, steel, cement, and maintenance and spare-parts services over many years. The slowdown and review affect the timing, and possibly the scope, of that demand. Suppliers will want to follow the review’s outcome in 2027. On machinery, see <a href="{{a:construction-machinery-imports-rise}}">construction machinery imports</a>.</p>
<p>Mining and construction equipment suppliers meet project owners and contractors at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March, Karachi Expo Centre. See the <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a>.</p>
'''))

ARTICLES.append(dict(
    slug='ml-1-railway-upgrade-status',
    category='trade-supply-chains',
    title='ML-1 railway upgrade: ADB money for Karachi–Rohri, groundbreaking targeted for 2027',
    excerpt='The first 480 km of the Karachi–Peshawar main line is moving towards an ADB-led start. Here are the costs, lenders and timetable as of 2026, and what the works involve.',
    tags=['Railways', 'ML-1', 'Infrastructure'],
    sources=[SRC_DAWN_ML1, SRC_TRIB_ML1, SRC_PROFIT_ML1],
    body='''
<p>Main Line-1 (ML-1) is Pakistan’s rail spine, running from Karachi to Peshawar. Upgrading it has been planned for years under CPEC. In 2026 the plan changed shape: the first section will start with multilateral money, and Chinese financing may follow later.</p>
<h2>The financing as of 2026</h2>
<ul>
<li><strong>Whole line:</strong> Dawn reported in July 2026 that the 1,680-km route was estimated at about <strong>$10 billion</strong>. Government figures reported by The Express Tribune in August 2026 gave about $6.66 billion for the “rationalised” project.</li>
<li><strong>First phase:</strong> Dawn reported in July 2026 that the ADB had committed a loan package of around $2 billion. Profit reported in February 2026, when talks were at an advanced stage, that the ADB was expected to cover about $1.2 billion of the Karachi–Rohri section, with AIIB and the European Investment Bank in talks for the rest.</li>
<li><strong>Preparation:</strong> The Express Tribune reported a $10 million ADB project readiness financing loan for Karachi–Rohri.</li>
<li><strong>China:</strong> Dawn reported that starting with ADB money was judged prudent and that China had agreed; Mr Iqbal said Chinese financing could come in a later phase.</li>
</ul>
<h2>The timetable</h2>
<table>
<thead><tr><th>Step</th><th>Timing (as reported)</th></tr></thead>
<tbody>
<tr><td>Second round of market consultation</td><td>From 8 September 2026</td></tr>
<tr><td>Review phase deadline</td><td>30 October 2026</td></tr>
<tr><td>Groundbreaking, first phase</td><td>Early 2027, according to the Planning Minister</td></tr>
<tr><td>Karachi–Rohri construction</td><td>About three years</td></tr>
</tbody>
</table>
<h2>What the works involve</h2>
<p>The corridor is divided into five sections: Karachi–Rohri (480 km, estimated at $2.04 billion), Rohri–Multan, Multan–Lahore, Lahore–Rawalpindi and Rawalpindi–Peshawar. The planned works include:</p>
<ul>
<li>rehabilitating the track and doubling it where needed;</li>
<li>better signalling and a higher axle load;</li>
<li>passenger train speeds of up to 160 km/h.</li>
</ul>
<h2>What this means for suppliers and partners</h2>
<p>The following are observations. International competitive procurement under a multilateral lender usually opens work to qualified contractors and suppliers from many countries. Track materials, ballast, signalling, bridges and earthmoving fleets are all likely needs. Other rail plans, such as the proposed <a href="{{a:reko-diq-mining-construction-demand}}">Reko Diq line</a>, are covered separately.</p>
<p>What to watch: the project has long been delayed, and The Express Tribune reported in August 2026 that ADB financing and completed project documents were still key requirements for construction to start.</p>
<p>Rail and infrastructure suppliers meet contractors at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March, Karachi Expo Centre. <a href="https://buildasia.net/contact/?enquiry=stand">Enquire about a stand</a>.</p>
'''))
