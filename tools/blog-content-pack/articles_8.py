# Build Asia starter articles — part 8 (second batch): Economy & Business Climate
# Facts only from the sources listed in each article (opened 10 Oct 2026).

SRC_BUDGET_PO = ('Pakistan Observer, "What\'s new in Budget 2026-27 after amendments? A look at key changes taking effect from July 1", 23 June 2026',
                 'https://pakobserver.net/whats-new-in-budget-2026-27-after-amendments-a-look-at-key-changes-taking-effect-from-july-1/')
SRC_BUDGET_ZAMEEN = ('Zameen News, "Budget 2026-27: Tax cuts proposed to revive property sector", 15 June 2026',
                     'https://www.zameen.com/news/budget-proposes-tax-cuts-for-property-sector.html')
SRC_BUDGET_PP = ('ProPakistani, "Govt officially notifies Budget 2026-27", 30 June 2026',
                 'https://propakistani.pk/2026/06/30/govt-officially-notifies-budget-2026-27/amp/')

SRC_SBP_HIKE = ('Dawn, "Central bank increases policy rate by 100bps to 11.5pc", 27 April 2026',
                'https://www.dawn.com/news/1995495')
SRC_SBP_SEP = ('State Bank of Pakistan, Monetary Policy Statement, 14 September 2026',
               'https://www.sbp.org.pk/assets/documents/press-release/PR-14-Sep-2026.pdf')
SRC_HOUSING_FIN = ('ProPakistani, "House financing amount in Pakistan reaches Rs. 37 billion", 7 May 2026',
                   'https://propakistani.pk/2026/05/07/house-financing-amount-in-pakistan-reaches-rs-37-billion/amp/')

SRC_REMIT = ('Profit by Pakistan Today, "Pakistan receives record $41.6 billion workers\' remittances in FY26", 9 July 2026',
             'https://profit.pakistantoday.com.pk/2026/07/09/pakistan-receives-record-dollar416-billion-workers-remittances-in-fy26')
SRC_RDA = ('Profit by Pakistan Today, "Roshan Digital Account gross inflows reach $13.37 billion by June 2026", 10 July 2026',
           'https://profit.pakistantoday.com.pk/2026/07/10/roshan-digital-account-gross-inflows-reach-dollar1337-billion-by-june-2026')
SRC_EXPATS = ('Zameen News, "Budget 2026-27: Incentives package announced for expats", 15 June 2026',
              'https://www.zameen.com/news/budget-2026-27-incentives-package-announced-for-expats.html')

SRC_MOODYS = ('The Express Tribune, "Moody\'s upgrades Pakistan\'s sovereign credit rating to B3", 24 August 2026',
              'https://tribune.com.pk/story/2625596/moodys-upgrades-pakistans-sovereign-credit-rating-to-b3')
SRC_IMF_SLA = ('Gulf News, "IMF clears $1.21b for Pakistan after fourth loan review", 8 October 2026',
               'https://gulfnews.com/business/economy/imf-clears-121b-for-pakistan-after-fourth-loan-review-1.500702624')
SRC_EUROBOND = ('The Express Tribune, "Pakistan raises $3b in largest-ever international bond transaction", 3 September 2026',
                'https://tribune.com.pk/story/2627207/pakistan-raises-3b-in-largest-ever-international-bond-transaction')

SRC_SIFC_BOI = ('Board of Investment (invest.gov.pk), "Special Investment Facilitation Council (SIFC)", notification No. 7(218)-HR/2023',
                'https://invest.gov.pk/node/1989')
SRC_SIFC_RADIO = ('Radio Pakistan, "SIFC playing pivotal role in bringing investment to Pakistan", 7 May 2026',
                  'https://www.radio.gov.pk/07-05-2026/sifc-playing-pivotal-role-in-bringing-investment-to-pakistan')
SRC_SIFC_REPORT = ('The News, "SIFC report to be made public by June 2027", 2 February 2026',
                   'https://www.thenews.pk/print/1396739-sifc-report-to-be-made-public-by-june-2027')

ARTICLES = []

ARTICLES.append(dict(
    slug='budget-2026-27-what-it-means-for-construction',
    category='economy-business-climate',
    title='Budget 2026-27: property taxes halved, 7E abolished – what it means for construction',
    excerpt='From 1 July 2026, advance tax on property deals was halved and Section 7E scrapped, alongside a Rs1 trillion federal PSDP and Rs71 billion for housing finance.',
    tags=['Budget', 'Property tax', 'Housing'],
    sources=[SRC_BUDGET_PO, SRC_BUDGET_ZAMEEN, SRC_BUDGET_PP],
    body='''
<p>After amendments, the National Assembly approved the federal budget for 2026-27 in June 2026, with total federal spending of <strong>Rs18.771 trillion</strong>. The Finance Act 2026 was notified on 30 June, with its measures in force from <strong>1 July 2026</strong>. For the building industry, the most direct changes are in how property deals are taxed.</p>
<h2>Property transactions: lower taxes</h2>
<p>For active taxpayers, the advance tax collected on buying and selling property was roughly halved:</p>
<table>
<thead><tr><th>Measure</th><th>Before</th><th>From 1 July 2026</th></tr></thead>
<tbody>
<tr><td>Advance tax paid by buyers</td><td>2.5%</td><td>1.25% of fair market value</td></tr>
<tr><td>Advance tax paid by sellers</td><td>5.5%</td><td>2.75% of gross sale amount</td></tr>
<tr><td>Section 7E (deemed income of 5% of property value)</td><td>In force</td><td>Abolished</td></tr>
</tbody>
</table>
<p>Section 7E had treated certain properties as earning a deemed income and was widely criticised by stakeholders. The government presented the package as a way to stimulate investment, boost market activity and encourage tax compliance.</p>
<h2>Housing and development spending</h2>
<ul>
<li><strong>Housing finance:</strong> Rs71 billion is allocated to the PM Apna Ghar Scheme, which aims to widen access to affordable housing finance.</li>
<li><strong>Federal PSDP:</strong> Rs1 trillion, within total national development spending of Rs3.675 trillion. Separately, the budget allocates Rs103.1 billion for water projects and Rs116.2 billion for energy. <a href="{{a:psdp-2026-27-infrastructure-spending}}">Our PSDP article looks at where the money goes.</a></li>
<li><strong>Import duties:</strong> the notified budget reduces or removes additional customs duties and cuts regulatory duties, which are expected to reduce collections by Rs47.06 billion and Rs65.57 billion respectively.</li>
</ul>
<h2>What this means for builders and suppliers</h2>
<ul>
<li><strong>Developers:</strong> cheaper transactions can make it easier to sell finished units and recycle money into new projects. Whether sales actually pick up will show in the coming months’ cement and property data.</li>
<li><strong>Affordable housing:</strong> a funded housing-finance scheme matters most to builders of smaller, lower-cost homes, where the <a href="{{a:pakistan-housing-gap-numbers}}">housing gap</a> is widest.</li>
<li><strong>Contractors:</strong> the PSDP signals demand for road, water and power work; what matters is how much is actually released.</li>
</ul>
<p><strong>What to watch:</strong> budget allocations are targets, not spending, and lower duties also mean lower revenue. Track actual development releases and property sales through the year rather than the headline figures.</p>
<p>Developers, contractors and the suppliers who serve them meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March 2027 at the Karachi Expo Centre. Firms selling into housing and infrastructure can check the <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a>.</p>
'''))

ARTICLES.append(dict(
    slug='interest-rates-construction-finance-2026',
    category='economy-business-climate',
    title='From 22% to 11.5%: the policy rate path and what it means for construction finance',
    excerpt='The SBP cut its policy rate from a record 22% to 10.5%, then raised it to 11.5% in April 2026. What the path means for developers, contractors and home buyers.',
    tags=['Interest rates', 'Finance', 'SBP'],
    sources=[SRC_SBP_HIKE, SRC_SBP_SEP, SRC_HOUSING_FIN],
    body='''
<p>Few numbers matter more to a builder than the cost of borrowing. Working capital, machinery purchases, land and home buyers’ mortgages all follow the State Bank of Pakistan’s policy rate. Over the past two years that rate has moved a long way, mostly down, with one turn up in 2026.</p>
<h2>The path so far</h2>
<table>
<thead><tr><th>Period</th><th>Policy rate</th></tr></thead>
<tbody>
<tr><td>Before June 2024</td><td>22% (record high)</td></tr>
<tr><td>June 2024 – December 2025</td><td>Cut by a cumulative 1,150 basis points</td></tr>
<tr><td>December 2025 – April 2026</td><td>Held at 10.5%</td></tr>
<tr><td>27 April 2026 (effective 28 April)</td><td>Raised by 100 basis points to 11.5%</td></tr>
<tr><td>14 September 2026</td><td>Held at 11.5%</td></tr>
</tbody>
</table>
<p>The April increase was the first in nearly three years. The Monetary Policy Committee linked it to the Middle East conflict, which pushed up oil, freight and insurance costs. Headline inflation, 7.3% in March 2026, reached 11.1% in August. At its September meeting, seven of ten committee members voted to hold the rate. The SBP expects inflation to ease towards the upper end of its 5–7% target range by June 2027.</p>
<h2>Credit is still growing</h2>
<p>Even at 11.5%, the policy rate is roughly half its 22% peak. Private sector credit grew 13.4% year on year, according to the September statement. Housing finance, however, remains small: an official briefing document reported in May 2026 put it at <strong>0.3% of GDP</strong>, and State Bank data put its share of total bank advances at 1.48%. The same document reported 25,304 housing finance applications by 30 April 2026, with Rs37.154 billion approved but only Rs5.071 billion disbursed.</p>
<h2>What it means for construction</h2>
<ul>
<li><strong>Developers and contractors:</strong> with the policy rate at about half its peak, bank-funded working capital and equipment are more viable than when rates were at 22%.</li>
<li><strong>Home buyers:</strong> mortgages remain rare, so most housing demand still depends on buyers’ own savings. A wider mortgage market would change the pace of building more than any single rate cut. <a href="{{a:pakistan-housing-gap-numbers}}">See the housing gap in numbers.</a></li>
<li><strong>Suppliers:</strong> dealers who offer credit to contractors face lower funding costs than two years ago, but should plan for rates staying at this level for now.</li>
</ul>
<p><strong>What to watch:</strong> the SBP lists commodity price volatility, electricity and gas tariff adjustments, food prices and the Middle East conflict as risks to inflation. Further cuts depend on inflation falling back.</p>
<p>Banks, housing finance companies and the builders who use them are part of the audience at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a> in Karachi, 18–20 March 2027.</p>
'''))

ARTICLES.append(dict(
    slug='remittances-record-property-demand',
    category='economy-business-climate',
    title='Record $41.6 billion remittances in FY26: what it means for property demand',
    excerpt='Overseas Pakistanis sent a record $41.6 billion home in 2025-26, and Roshan Digital Account inflows passed $13.3 billion. Why this money matters for housing and construction.',
    tags=['Remittances', 'Overseas Pakistanis', 'Real estate'],
    sources=[SRC_REMIT, SRC_RDA, SRC_EXPATS],
    body='''
<p>Money sent home by Pakistanis working abroad is one of the steadiest sources of demand for new homes, plots and renovations. In 2025-26 it reached a new high.</p>
<h2>A record year</h2>
<p>State Bank of Pakistan data released on 9 July 2026 show workers’ remittances of <strong>$41.6 billion in FY26</strong>, up 8.6% from $38.3 billion in FY25. In June 2026 alone, $3.5 billion arrived. The largest senders that month were:</p>
<table>
<thead><tr><th>Country</th><th>June 2026</th></tr></thead>
<tbody>
<tr><td>Saudi Arabia</td><td>$829.6 million</td></tr>
<tr><td>United Arab Emirates</td><td>$792.2 million</td></tr>
<tr><td>United Kingdom</td><td>$514.9 million</td></tr>
<tr><td>United States</td><td>$296.8 million</td></tr>
</tbody>
</table>
<h2>Roshan Digital Accounts</h2>
<p>Roshan Digital Accounts (RDAs), launched for overseas Pakistanis in 2020, are a second channel. By the end of June 2026, gross RDA inflows had reached <strong>$13.365 billion</strong>, according to data compiled by Arif Habib Limited from SBP figures. Of this, $8.435 billion had been used within Pakistan and $2.093 billion repatriated. Net investment in Naya Pakistan Certificates stood at $1.901 billion. The published figures do not break down how much went into property.</p>
<h2>Budget changes that affect overseas buyers</h2>
<p>As presented on 12 June 2026, the 2026-27 budget cut withholding tax on property purchases by filers from 2.5% to 1.25% and on sales from 5.5% to 2.75%. It also abolished the capital value tax on foreign assets and cut withholding tax on international card transactions from 5% to 0.5%. <a href="{{a:budget-2026-27-what-it-means-for-construction}}">More on the budget measures.</a></p>
<h2>What this means for builders</h2>
<ul>
<li>Remittances add to household spending power at home. A record year suggests steady potential demand for housing, finishes, fittings and contractors’ services, especially where overseas families build or buy.</li>
<li>Much of the money comes from the Gulf, the UK and the US. Projects aimed at overseas buyers need clear titles, approvals and remote payment options.</li>
<li>Listed property funds offer another route for small investors. <a href="{{a:reits-pakistan-real-estate-investment}}">How REITs work in Pakistan.</a></li>
</ul>
<p><strong>What to watch:</strong> the largest sources are in the Gulf, so regional conflict or a slowdown there could affect flows. Remittance figures also do not show directly how much is spent on property.</p>
<p>Developers, builders and housing finance providers meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March 2027 at the Karachi Expo Centre. <a href="https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web">Register to visit.</a></p>
'''))

ARTICLES.append(dict(
    slug='credit-ratings-reserves-recovery-2026',
    category='economy-business-climate',
    title='Rating upgrades, IMF reviews and $21.5 billion reserves: Pakistan’s macro picture in 2026',
    excerpt='Two credit rating upgrades, a record $3 billion Eurobond and an IMF staff-level agreement on the fourth review in October 2026. What has changed in Pakistan’s economy, and what has not.',
    tags=['Economy', 'IMF', 'Credit ratings'],
    sources=[SRC_MOODYS, SRC_IMF_SLA, SRC_EUROBOND],
    body='''
<p>For companies planning projects or supply contracts in Pakistan, the country’s financial stability sets the backdrop: whether imports can be paid for, whether the rupee holds and whether banks can lend. In 2026 several indicators moved in the same direction.</p>
<h2>Credit ratings</h2>
<table>
<thead><tr><th>Agency</th><th>Action</th><th>Date</th></tr></thead>
<tbody>
<tr><td>S&amp;P Global</td><td>Upgraded from B− to B</td><td>22 July 2026</td></tr>
<tr><td>Moody’s</td><td>Upgraded from Caa1 to B3, outlook stable</td><td>24 August 2026</td></tr>
</tbody>
</table>
<p>Moody’s expected improved governance to help sustain recent gains in the external position, and pointed to better debt affordability: interest payments took about 35% of government revenue in FY26, down from 49% in FY25. B3 is still seven notches below investment grade, and Moody’s listed weaknesses including a narrow revenue base, very low foreign direct investment and high dependence on remittances.</p>
<h2>The IMF programme</h2>
<p>Pakistan’s 37-month, $7 billion Extended Fund Facility began in September 2024, and a Resilience and Sustainability Facility of about $1.4 billion was added in 2025. On 7 October 2026, IMF staff reached a staff-level agreement on the fourth EFF review and the third RSF review, worth about <strong>$1.21 billion</strong> once approved by the IMF board. Disbursements under both arrangements would then total about $5.7 billion. The IMF estimated FY26 growth at 3.6% and said inflation had eased to about 10.3% in September 2026.</p>
<h2>Reserves and market access</h2>
<ul>
<li>Gross reserves reached about <strong>$21.5 billion</strong> by the end of September 2026, according to the IMF.</li>
<li>In early September 2026, Pakistan raised <strong>$3 billion</strong> through a two-part Eurobond (5½ and 10 years, coupons of 7.50% and 7.90%), with nearly $6 billion in orders — its largest single international bond issue.</li>
</ul>
<h2>What this means for the building industry</h2>
<ul>
<li>Stronger reserves make it easier to pay for imported machinery, materials and components. <a href="{{a:construction-machinery-imports-rise}}">Read about construction machinery imports.</a></li>
<li>Better ratings and bond access can lower borrowing costs for the government and banks over time, which feeds through to project finance.</li>
</ul>
<p><strong>What to watch:</strong> the IMF still rates risks as high, citing geopolitical tensions and energy prices, and wants the State Bank to keep monetary policy tight until inflation durably returns to its target range. <a href="{{a:interest-rates-construction-finance-2026}}">See the policy rate path.</a></p>
<p>Build Asia 2027 brings project owners, contractors and suppliers from Pakistan and abroad together at the Karachi Expo Centre, 18–20 March 2027; the January 2026 edition had exhibitors from over 21 countries. <a href="{{a:build-asia-2027-what-to-expect}}">What to expect.</a></p>
'''))

ARTICLES.append(dict(
    slug='sifc-investment-facilitation-explained',
    category='economy-business-climate',
    title='The Special Investment Facilitation Council explained: structure, sectors and projects',
    excerpt='Set up in 2023 as a “single window” for investment, the SIFC coordinates federal and provincial bodies. How it is organised, what it has worked on, and the transparency questions.',
    tags=['SIFC', 'Investment', 'Policy'],
    sources=[SRC_SIFC_BOI, SRC_SIFC_RADIO, SRC_SIFC_REPORT],
    body='''
<p>The Special Investment Facilitation Council (SIFC) is often mentioned when large projects in Pakistan are announced. This is a plain guide to what it is and how it works.</p>
<h2>What it is</h2>
<p>The SIFC was constituted by the Prime Minister following a meeting on 2 June 2023 on attracting investment from Gulf (GCC) countries, through notification No. 7(218)-HR/2023. Its terms of reference include acting as a <strong>“Single Window”</strong> for cooperation with Gulf (GCC) and other countries, preparing a long-term investment roadmap, cutting bureaucratic hurdles, coordinating federal and provincial bodies, and fast-tracking projects. The original notification named five fields: defence, agriculture, minerals, IT and telecommunication, and energy.</p>
<h2>How it is organised</h2>
<table>
<thead><tr><th>Tier</th><th>Members include</th><th>Meets</th></tr></thead>
<tbody>
<tr><td>Apex Committee</td><td>Prime Minister, with federal ministers, provincial Chief Ministers and the Chief of Army Staff (by special invitation)</td><td>Quarterly</td></tr>
<tr><td>Executive Committee</td><td>Minister for Planning, Development and Special Initiatives, federal and provincial ministers, provincial Chief Secretaries</td><td>Monthly</td></tr>
<tr><td>Implementation Committee</td><td>SIFC Secretariat, with sector coordinators and sectoral divisions</td><td>Fortnightly</td></tr>
</tbody>
</table>
<p>Officials from the Finance Ministry, the Board of Investment, the FBR and the State Bank are co-opted members, and a National Coordinator from the Pakistan Army sits on the Apex and Executive Committees.</p>
<h2>What it has worked on</h2>
<p>According to Radio Pakistan in May 2026, citing the Secretary of the SIFC Apex Committee, Dr Jahanzeb Khan, the council’s work has included:</p>
<ul>
<li><strong>Cement:</strong> facilitating the establishment of seven new cement factories. <a href="{{a:cement-exports-and-capacity-expansion}}">More on cement capacity.</a></li>
<li><strong>Transport:</strong> logistics, railways and motorways, including reviving the M-12 motorway project.</li>
<li><strong>Other sectors:</strong> large-scale farming, pharmaceuticals, warehousing and a medical tourism initiative.</li>
</ul>
<h2>Transparency</h2>
<p>Following an IMF condition, the government has drawn up an action plan for the SIFC’s first annual report: a draft by December 2026, a final version by March 2027 and public release by June 2027. It is to list facilitated investments and the tax, policy and regulatory incentives granted, with a justification and estimated value for each.</p>
<h2>What this means for the industry</h2>
<p>For construction suppliers, the SIFC is mainly a signal of where large projects are being pushed, such as cement plants and transport. The annual report, once published, should give a clearer picture of what has actually been facilitated. <a href="{{a:special-economic-zones-incentives}}">See also: incentives in Special Economic Zones.</a></p>
<p>Project owners, contractors and suppliers meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March 2027 at the Karachi Expo Centre.</p>
'''))
