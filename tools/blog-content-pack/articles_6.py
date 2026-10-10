# Build Asia starter articles — part 6 (writer C): Industry & Manufacturing
# Facts only from the sources listed in each article (opened 10 Oct 2026).

SRC_SEZ_44 = ('Profit by Pakistan Today, "Pakistan’s special economic zones rise from 7 to 44 since 2019 under CPEC 2.0", 17 January 2026',
              'https://profit.pakistantoday.com.pk/2026/01/17/pakistans-special-economic-zones-rise-from-7-to-44-since-2019-under-cpec-2-0/')
SRC_SEZ_TARGETS = ('Profit by Pakistan Today, "Pakistan misses SEZ targets as CPEC phase II refocuses on exports", 5 May 2026',
                   'https://profit.pakistantoday.com.pk/2026/05/05/pakistan-misses-sez-targets-as-cpec-phase-ii-refocuses-on-exports/')
SRC_SEZ_BR = ('Business Recorder, Kashif Shafi (OICCI), "SEZ 2.0: From tax holidays to performance-based incentives", 1 August 2026',
              'https://www.brecorder.com/news/amp/40432793')

SRC_STEEL_NEWS = ('The News, "Steel sector at breaking point", 29 April 2026 (PALSP data)',
                  'https://www.thenews.pk/print/1412613-steel-sector-at-breaking-point')
SRC_STEEL_SCRAP = ('Dawn, "Iron, steel scrap imports hit record in October", 27 November 2025',
                   'https://www.dawn.com/news/1957616')
SRC_PSM_PID = ('Press Information Department, Government of Pakistan, PR No. 132, "Pakistan, Russia sign protocol to revive Steel Mills", 11 July 2025',
               'https://pid.gov.pk/site/press_detail/29669')

SRC_PACRA_GLASS = ('PACRA, Sector Study: Glass, 9 December 2025',
                   'https://www.pacra.com/view/storage/app/PACRA%20Research%20-%20Glass%20-%20Dec%2725_1765266161.pdf')
SRC_GHANI_CBS = ('Ghani Glass Limited, corporate briefing presentation, nine months to 31 March 2025 (PSX filing)',
                 'https://dps.psx.com.pk/download/document/257154.pdf')
SRC_TARIQ_CBS = ('Tariq Glass Industries Limited, corporate briefing session 2025, year to 30 June 2025 (PSX filing)',
                 'https://dps.psx.com.pk/download/document/261984.pdf')

SRC_TILES_TRIB = ('The Express Tribune, "Tariff cuts threaten tile industry", 21 June 2026',
                  'https://tribune.com.pk/story/2614309/tariff-cuts-threaten-tile-industry')
SRC_TILES_VALUATION = ('Profit by Pakistan Today, "Customs revises import values for ceramic and porcelain tiles", 12 January 2026',
                       'https://profit.pakistantoday.com.pk/?p=206285')
SRC_STCL = ('Shabbir Tiles and Ceramics Limited, interim financial statements and directors’ report, nine months to 31 March 2026 (PSX filing)',
            'https://dps.psx.com.pk/download/document/276231.pdf')

SRC_CEM_CAPACITY = ('International Cement Review (CemNet), "Pakistan’s cement industry capacity reached 84.58Mt in FY26", 12 June 2026',
                    'https://www.cemnet.com/News/story/181503/pakistan-s-cement-industry-capacity-reached-84-58-mt-in-fy26.html')
SRC_CEM_GC = ('Global Cement, "Update on Pakistan, July 2026", 8 July 2026',
              'https://globalcement.com/news/analysis/20977-update-on-pakistan-july-2026')
SRC_CEM_SEPT = ('ProPakistani, "Cement sales rise 7% in September", 2 October 2026 (APCMA data)',
                'https://propakistani.pk/2026/10/02/cement-sales-rise-7-in-september/')

ARTICLES = []

ARTICLES.append(dict(
    slug='special-economic-zones-incentives',
    category='industry-manufacturing',
    title='Pakistan’s Special Economic Zones: 44 zones, the incentives and the reforms ahead',
    excerpt='Pakistan now has 44 notified economic zones, but only a few CPEC zones are partly working. What the SEZ incentives are, and how IMF-linked reform will change them.',
    tags=['SEZs', 'Industry', 'Policy'],
    sources=[SRC_SEZ_44, SRC_SEZ_TARGETS, SRC_SEZ_BR],
    body='''
<p>Special Economic Zones (SEZs) offer factories serviced land, utilities and tax relief in one place. Each zone is also a construction project: roads, power and water networks, then sheds and warehouses.</p>
<h2>How many zones, and how many are working</h2>
<p>According to figures presented to the Federal Minister for Investment, Qaiser Ahmed Sheikh, in January 2026, Pakistan’s notified SEZs rose from <strong>seven in 2019 to 44 by 2025</strong>. The briefing noted progress on the Karachi Industrial Park, work towards a Gilgit-Baltistan SEZ and approval of a land lease policy for BQIP.</p>
<p>Zones on paper are not zones in use. In May 2026 the minister said Pakistan had fallen short of its SEZ targets. Of the nine zones designated under the first phase of CPEC, only four had moved beyond planning and were partly developed as of 2025:</p>
<ul>
<li>Rashakai, Khyber Pakhtunkhwa</li>
<li>Allama Iqbal Industrial City, Punjab</li>
<li>Dhabeji, Sindh</li>
<li>Bostan, Balochistan</li>
</ul>
<p>The 2018–2024 goal had been more than US$8 billion of foreign investment and 500,000 jobs from the zones.</p>
<h2>Who is coming in</h2>
<p>China remains the main partner. A business-to-business investment conference held during the Prime Minister’s visit to China in September 2025 produced more than 160 memoranda of understanding and joint ventures. Proposals from a September 2025 CPEC Joint Cooperation Committee meeting include government-to-government industrial zones in Karachi and Islamabad for relocating Chinese firms in electronics, textiles, pharmaceuticals and electric vehicles.</p>
<h2>The incentives, and how they will change</h2>
<p>As described in Business Recorder in August 2026, zone developers and enterprises currently receive a <strong>ten-year income tax exemption</strong> and a <strong>one-time exemption from customs duties and taxes</strong> on qualifying imported capital goods. Under the IMF-supported programme, Pakistan has committed to amend the SEZ Act by June 2027 and to phase out existing SEZ and EPZ fiscal incentives by 2035, subject to existing contracts, moving from profit-based to cost-based support.</p>
<h2>What this means for suppliers and partners</h2>
<ul>
<li>Zones that are actually being built out create demand for civil works, pre-engineered buildings, power and water systems and industrial flooring.</li>
<li>Firms entering a zone before the rules change will want clarity on how existing rights are protected.</li>
</ul>
<p><strong>What to watch:</strong> the same Business Recorder opinion piece argues that several zones remain underdeveloped and that missing power, gas, roads and water have held zones back more than tax rules. For the investment-facilitation side, see <a href="{{a:sifc-investment-facilitation-explained}}">our explainer on the SIFC</a>.</p>
<p>Zone developers, industrial builders and their suppliers meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March 2027 at the Karachi Expo Centre. See the <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a>.</p>
'''))

ARTICLES.append(dict(
    slug='steel-industry-capacity-demand-2026',
    category='industry-manufacturing',
    title='Pakistan’s steel industry in 2026: 9 million tonnes of capacity, 3.8 million produced',
    excerpt='Long-steel makers run far below capacity, squeezed by power tariffs and taxes, even as scrap imports point to a rebar recovery. The numbers, and what they mean for buyers.',
    tags=['Steel', 'Industry', 'Energy'],
    sources=[SRC_STEEL_NEWS, SRC_STEEL_SCRAP, SRC_PSM_PID],
    body='''
<p>Steel bars and sections are, after cement, the second pillar of almost every building and bridge in Pakistan. The industry that makes them has plenty of capacity but is running far below it.</p>
<h2>Capacity and output</h2>
<p>Figures from the Pakistan Association of Large Steel Producers (PALSP), reported in April 2026, show the gap clearly:</p>
<table>
<thead><tr><th>Indicator (PALSP, April 2026)</th><th>Figure</th></tr></thead>
<tbody>
<tr><td>Installed capacity</td><td>9 million tonnes</td></tr>
<tr><td>Current production</td><td>3.8 million tonnes</td></tr>
<tr><td>Steel use per person</td><td>48 kg</td></tr>
<tr><td>Electricity used each year</td><td>about 3 billion kWh (about 7 billion at full capacity)</td></tr>
<tr><td>Direct jobs</td><td>about 200,000</td></tr>
</tbody>
</table>
<p>Many units are shut and the rest run at 30% to 50% of capacity. For comparison, the same report says Bangladesh, with similar capacity, produces about 6.5 million tonnes a year.</p>
<h2>Why: energy and taxes</h2>
<p>The industry points to steep electricity tariffs, high interest rates and heavier taxes. Sales tax on steel rose from Rs10,350 per tonne in 2018-19 to between Rs37,000 and Rs40,500 per tonne in recent years. PALSP has asked for regionally competitive power tariffs, removal of the super tax and collection of tax at the scrap-import stage to curb evasion.</p>
<h2>Signs of a pick-up</h2>
<p>Imports of scrap, which local mills melt into steel bars, fell from 4.72 million tonnes of imports in 2021 to about 2.7–2.8 million tonnes in 2024-25. Then demand turned. In the first four months of FY26 (July–October 2025), scrap imports rose 58.5% to 1.299 million tonnes, with October a record at 381,991 tonnes. PALSP’s secretary general, Syed Wajid Bukhari, said the rise came from real demand, not cheaper scrap.</p>
<p>On the state side, Pakistan and Russia signed a protocol in Moscow in July 2025 to restore and modernise Pakistan Steel Mills in Karachi, originally built with Soviet help in 1973.</p>
<h2>What this means for suppliers and buyers</h2>
<ul>
<li>Spare capacity means local mills can meet more demand quickly if construction keeps growing.</li>
<li>Energy is the biggest lever: efficient furnaces, captive or renewable power and better rolling-mill equipment address the industry’s main cost.</li>
<li>Contractors should watch rebar price swings, which follow scrap prices and energy costs.</li>
</ul>
<p><strong>What to watch:</strong> the industry says high tariffs and taxes have already shut many units, and the July 2025 announcement on Pakistan Steel Mills gave no timetable.</p>
<p>Steel makers, rolling-mill suppliers and contractors meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a> in Karachi. Cement demand, steel’s closest companion, is covered in <a href="{{a:cement-sales-fy26-what-the-numbers-say}}">our FY26 cement analysis</a>.</p>
'''))

ARTICLES.append(dict(
    slug='pakistan-glass-industry-float-glass',
    category='industry-manufacturing',
    title='Pakistan’s glass industry: three float glass makers, 1,600 tonnes a day, rising imports',
    excerpt='Float glass for windows and facades comes from three local producers. What PACRA’s December 2025 study says about output, imports, energy costs and where the openings are.',
    tags=['Glass', 'Industry', 'Facades'],
    sources=[SRC_PACRA_GLASS, SRC_GHANI_CBS, SRC_TARIQ_CBS],
    body='''
<p>Windows, shopfronts and glass facades all start as float glass: flat sheets made by floating molten glass on a bath of tin. PACRA’s sector study of 9 December 2025 names float glass for the construction industry as the glass sector’s largest segment.</p>
<h2>Who makes it</h2>
<p>PACRA names three float glass producers — <strong>Tariq Glass, Ghani Glass and Ghani Value Glass</strong> — with a combined float capacity of about <strong>1,600 tonnes per day</strong>. These groups also make containers and tableware:</p>
<ul>
<li><strong>Ghani Glass</strong> lists a 1,000-tonne-a-day float glass plant at Sheikhupura (two furnaces), alongside container plants at Hattar and Karachi, and says it exports to 52 countries (briefing for the nine months to March 2025).</li>
<li><strong>Tariq Glass</strong> reports five plants with a combined capacity of 1,390 tonnes per day across tableware, container and float glass, and a joint venture with Lucky Core Industries (briefing for the year to June 2025).</li>
</ul>
<h2>Output, trade and costs</h2>
<table>
<thead><tr><th>Indicator (PACRA)</th><th>FY24</th><th>FY25</th></tr></thead>
<tbody>
<tr><td>Glass plates and sheets produced</td><td>20.2 million m²</td><td>13.0 million m² (about −36%)</td></tr>
<tr><td>Glass imports</td><td>—</td><td>US$114.7 million</td></tr>
<tr><td>Glass exports</td><td>US$41.0 million</td><td>US$33.2 million</td></tr>
<tr><td>Revenue of listed players</td><td>Rs85.2 billion</td><td>Rs88.7 billion</td></tr>
</tbody>
</table>
<p>Sheet output kept falling slightly in the first four months of FY26, to about 3.2 million m², while imports in that period rose about 10% to US$40.1 million. Glass fibres were the largest import category, at about a quarter of the total. Fuel and energy make up about <strong>34% of direct costs</strong>, and Ghani Glass listed surging gas prices and slow construction activity among its challenges.</p>
<h2>Where the openings are</h2>
<ul>
<li>Only three companies make float glass locally, so buyers have a narrow local choice and specialist products face little direct local competition.</li>
<li>Processing — toughening, laminating, insulating units and facade fabrication — adds value close to the building site.</li>
<li>Energy-saving furnace and process technology addresses the industry’s largest cost.</li>
</ul>
<p><strong>What to watch:</strong> PACRA rates the outlook stable but flags periodic plant shutdowns for maintenance, rising energy costs and muted construction activity. Net margins of listed players narrowed to about 9.1% in the first quarter of FY26.</p>
<p>Glass makers, processors and facade contractors meet buyers at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March 2027 at the Karachi Expo Centre. Interested companies can <a href="https://buildasia.net/contact/?enquiry=stand">enquire about a stand</a>.</p>
'''))

ARTICLES.append(dict(
    slug='tiles-ceramics-sanitaryware-industry',
    category='industry-manufacturing',
    title='Tiles and ceramics in Pakistan: half the capacity idle, imports and the tariff debate',
    excerpt='Local tile makers say they run at about half their capacity as energy costs bite and import duties fall. A look at the industry, the imports it competes with and what could change.',
    tags=['Tiles', 'Ceramics', 'Industry'],
    sources=[SRC_TILES_TRIB, SRC_TILES_VALUATION, SRC_STCL],
    body='''
<p>Floor and wall tiles, along with sanitaryware, are among the most visible finishing products in any home or office. Pakistan has an established tile industry, now going through a difficult period. Published data on sanitaryware is much thinner, so the figures here are for tiles.</p>
<h2>Half the capacity in use</h2>
<p>In June 2026 industry sources said Pakistan’s ceramic tile sector was running at <strong>nearly 50% of its installed capacity</strong>. They blamed a long economic slowdown, weak construction, high energy costs and expensive finance, and said their production costs are well above those in China and India.</p>
<p>The experience of one listed maker, Shabbir Tiles and Ceramics (the “Stile” brand), shows the pressure. Its net turnover for the nine months to March 2026 was about Rs8.85 billion, against about Rs10.63 billion a year earlier. Its directors cited subdued demand, an “oversupply situation”, higher energy, raw material and freight costs, and competition from undocumented operators. The FBR has issued STGO 02/2026, which requires electronic surveillance of tile production through video analytics.</p>
<h2>Imports and the tariff debate</h2>
<p>Imported tiles come from China and also from the UAE, Turkey and Iran, according to a January 2026 customs valuation ruling. During that review, local manufacturers said they had invested heavily in production, including larger-format tiles, while importers argued that international prices had fallen with new technology.</p>
<p>Under Phase II of tariff rationalisation in the 2026-27 budget, as reported in June 2026, regulatory duty on imported tiles is set to fall by 20% and additional customs duty by 50%. Local makers warned this could lead to closures; Naveed Qamar, chairman of the National Assembly finance committee, asked the Ministry of Commerce and the National Tariff Commission to explain the impact. For the wider budget picture, see <a href="{{a:budget-2026-27-what-it-means-for-construction}}">Budget 2026-27 and construction</a>.</p>
<h2>Where the room for growth is</h2>
<ul>
<li><strong>Energy efficiency:</strong> kilns and dryers that use less gas and power go straight at the industry’s main cost. Shabbir Tiles, for example, has borrowed under the SBP refinance scheme for renewable energy.</li>
<li><strong>Larger formats and porcelain:</strong> the segments where imports compete most directly and where local makers say they are investing.</li>
<li><strong>Documentation:</strong> if surveillance narrows the gap with undocumented producers, compliant makers gain room on price.</li>
</ul>
<p><strong>What to watch:</strong> lower duties will widen buyers’ choice but may squeeze local output while energy costs stay high.</p>
<p>Tile, ceramic and sanitaryware makers, importers and dealers meet specifiers at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, where the January 2026 edition drew over 32,000 trade visitors. See the <a href="https://buildasia.net/visitor-profile/">visitor profile</a>.</p>
'''))

ARTICLES.append(dict(
    slug='cement-exports-and-capacity-expansion',
    category='industry-manufacturing',
    title='Cement exports and new capacity: southern mills, African demand and US$700m of projects',
    excerpt='Pakistan’s cement capacity reached 84.58 million tonnes in FY26 while exports shifted south and towards Africa. Where new capacity is coming from and what it means for suppliers.',
    tags=['Cement', 'Exports', 'Industry'],
    sources=[SRC_CEM_CAPACITY, SRC_CEM_GC, SRC_CEM_SEPT],
    body='''
<p>Our <a href="{{a:cement-sales-fy26-what-the-numbers-say}}">analysis of FY26 cement despatches</a> covered the strong year at home. This article looks at the other side of the industry: how much it can make, where its exports go and who is adding capacity.</p>
<h2>Capacity and utilisation</h2>
<p>According to the Pakistan Economic Survey 2025-26, as reported by CemNet in June 2026, installed cement capacity grew from 45.62 million tonnes in FY16 to <strong>84.58 million tonnes in FY26</strong>. Utilisation improved to <strong>60.2%</strong> from 55.1% a year earlier — so, by our calculation, about two-fifths of capacity is still idle. In July–March FY26, exports earned US$271 million, up 17.7% on both higher volumes and better prices.</p>
<h2>Exports are moving south</h2>
<p>Global Cement, citing AKD Research, reports that exports from northern mills fell after the Afghan border closed in late 2025 amid hostilities, while southern mills exported more on growing African demand. The first quarter of FY27 shows the split clearly:</p>
<table>
<thead><tr><th>July–September 2026 (APCMA)</th><th>Million tonnes</th><th>Change</th></tr></thead>
<tbody>
<tr><td>Total despatches</td><td>13.141</td><td>+3.88%</td></tr>
<tr><td>Domestic</td><td>10.845</td><td>+7.79%</td></tr>
<tr><td>Exports (all from the south)</td><td>2.296</td><td>−11.32%</td></tr>
<tr><td>Southern exports</td><td>2.296</td><td>+19.15%</td></tr>
</tbody>
</table>
<p>Northern mills exported nothing in the quarter, against about 662,000 tonnes a year earlier.</p>
<h2>New capacity and efficiency</h2>
<ul>
<li>In April 2026 the SIFC approved cement projects worth <strong>US$700 million</strong> for Flying, Lucky, Bhutta, Asian Precious Minerals, Orient, Dandot and Maple Cement.</li>
<li>Lucky Cement completed an optimisation at its Karachi plant, adding 300,000 tonnes a year to reach 5.35 million tonnes, which is expected to cut fuel use per tonne. Lucky, which already has plants in Iraq and the DR Congo, is also considering a 2.5-million-tonne plant in Libya.</li>
</ul>
<h2>What this means for suppliers</h2>
<ul>
<li>Expansions and upgrades need kilns, mills, conveyors, refractories and process controls — and fuel-saving equipment, given energy costs.</li>
<li>Southern exports depend on port handling, bulk logistics and bagging.</li>
</ul>
<p><strong>What to watch:</strong> APCMA has voiced concern about falling exports and unstable energy prices, and Global Cement flags energy costs linked to the Iran conflict as a major risk.</p>
<p>Cement makers and their equipment suppliers meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a> in Karachi; the January 2026 edition had over 350 exhibitors from over 21 countries. For other export products, see <a href="{{a:building-materials-exports-markets}}">building-material exports</a>.</p>
'''))
