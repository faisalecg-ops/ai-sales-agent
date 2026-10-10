# Build Asia starter articles — part 5: Cities & Urban Development (second batch)
# Facts only from the sources listed in each article (opened 10 Oct 2026).

SRC_SURVEY_POP = ('Finance Division, Pakistan Economic Survey 2023-24, Chapter 12: "Population, Labour Force and Employment" (Census 2023 figures)',
                  'https://finance.gov.pk/survey/chapter_24/12_population.pdf')
SRC_NEWS_URBAN = ('The News, "Pakistan’s urban boom", 31 January 2026',
                  'https://www.thenews.pk/print/1396144-pakistan-s-urban-boom')
SRC_SOCH_CITIES = ('Soch Fact Check, "Lahore has not surpassed Karachi’s population" (PBS Census 2023 figures), 30 September 2023',
                   'https://www.sochfactcheck.com/lahore-has-not-surpassed-karachis-population/')

SRC_APP_GWADAR = ('APP, "Gwadar Airport, free zone to boost Balochistan trade access, Senate told", 13 February 2026',
                  'https://www.app.com.pk/?p=1161951')
SRC_NEWS_GWADAR = ('The News, "How Gwadar port is benefiting from Hormuz crisis: report", 30 April 2026',
                   'https://www.thenews.pk/print/1412828-how-gwadar-port-is-benefiting-from-hormuz-crisis-report')
SRC_BR_GWADAR = ('Business Recorder, "Gwadar Port sees maritime surge after ME crisis, tariff cut", 26 May 2026',
                 'https://www.brecorder.com/news/amp/40422812')

SRC_DT_RUDA = ('Daily Times, "RUDA to revolutionise urban landscape of Lahore in 2026", 2 January 2026',
               'https://dailytimes.com.pk/1428101/ruda-to-revolutionise-urban-landscape-of-lahore-in-2026/')
SRC_NEWS_CBD = ('The News, "CBD launches new projects for investors", 10 May 2026',
                'https://www.thenews.pk/print/1414727-cbd-launches-new-projects-for-investors')
SRC_PP_RUDA = ('ProPakistani, "Ravi Urban Development Authority to Get Rs. 15 Billion From Punjab Govt", 1 October 2026',
               'https://propakistani.pk/2026/10/01/ravi-urban-development-authority-to-get-rs-15-billion-from-punjab-govt/')

SRC_PP_BURJ = ('ProPakistani, "Pakistan’s Tallest Building to Be Built in Karachi’s DHA", 25 July 2026',
               'https://propakistani.pk/2026/07/25/pakistans-tallest-building-to-be-built-in-karachis-dha/')
SRC_PP_CDA = ('ProPakistani, "CDA Eases Parking Rules for Commercial Buildings", 3 August 2026',
              'https://propakistani.pk/2026/08/03/cda-eases-parking-rules-for-commercial-buildings/')
SRC_PP_FIRE = ('ProPakistani, "Punjab Issues New Fire Safety Rules For Commercial Buildings After Karachi Gul Plaza Tragedy", 25 January 2026',
               'https://propakistani.pk/2026/01/25/punjab-issues-new-fire-safety-rules-for-commercial-buildings-after-karachi-gul-plaza-tragedy/amp/')

SRC_NEWS_ACAG_MAR = ('The News, "2,000 families being provided free residential plots: minister", 12 March 2026',
                     'https://www.thenews.pk/print/1404016-2-000-families-being-provided-free-residential-plots-minister')
SRC_NEWS_ACAG_JUN = ('The News, "‘Apni Chhat, Apna Ghar’ project: 100,633 houses complete, minister told", 3 June 2026',
                     'https://www.thenews.pk/print/1418550-apni-chhat-apna-ghar-project-100-633-houses-complete-minister-told')
SRC_TRIB_ACAG = ('The Express Tribune, "Applicants await ‘Apni Chhat Apna Ghar’ loans", 25 August 2026',
                 'https://tribune.com.pk/story/2625724/applicants-await-apni-chhat-apna-ghar-loans')

ARTICLES = []

ARTICLES.append(dict(
    slug='pakistan-urbanisation-census-cities',
    category='cities-urban-development',
    title='Census 2023: Pakistan’s cities are growing almost twice as fast as the countryside',
    excerpt='The 2023 census counted 93.75 million city dwellers, with urban areas growing 3.65% a year against 1.90% in rural areas. What that pace means for housing and building.',
    tags=['Urbanisation', 'Census 2023', 'Cities'],
    sources=[SRC_SURVEY_POP, SRC_NEWS_URBAN, SRC_SOCH_CITIES],
    body='''
<p>Pakistan’s seventh Population and Housing Census, carried out in 2023, gives the clearest recent picture of how quickly the country’s towns and cities are filling up. The figures below come from the census results as reported in the Pakistan Economic Survey 2023-24.</p>
<h2>The headline figures</h2>
<table>
<thead><tr><th>Census</th><th>2017</th><th>2023</th></tr></thead>
<tbody>
<tr><td>Urban population</td><td>75.67 million</td><td>93.75 million</td></tr>
<tr><td>Urban share of population</td><td>36.44%</td><td>38.82%</td></tr>
<tr><td>Households</td><td>32.20 million</td><td>38.34 million</td></tr>
<tr><td>Average household size</td><td>6.39</td><td>6.30</td></tr>
</tbody>
</table>
<p>Between the two counts the urban population grew by <strong>3.65% a year</strong>, against 1.90% in rural areas. The survey puts much of that gap down to people moving from villages to cities.</p>
<p>The urban share varies widely by province: <strong>53.73%</strong> in Sindh, 46.90% in Islamabad, 40.70% in Punjab, 30.96% in Balochistan and 15.01% in Khyber Pakhtunkhwa.</p>
<h2>The biggest urban regions</h2>
<p>According to PBS census figures, the seven districts of the Karachi division held <strong>20.38 million</strong> people in 2023. Lahore district alone counted 13.0 million, and the wider Lahore division (with Kasur, Nankana Sahib and Sheikhupura) 22.77 million, out of a national total of 241.49 million.</p>
<h2>The official count may understate it</h2>
<p>An opinion article in The News in January 2026 cited a World Bank study which found that <strong>88%</strong> of Pakistanis live in areas with urban characteristics, far above the official figure. The same piece noted that urban centres produce roughly 55% of GDP and that the ten largest cities generate 95% of federal tax revenue.</p>
<h2>What this means for building</h2>
<ul>
<li><strong>Homes:</strong> by our own arithmetic, the number of households rose by about 6.1 million between 2017 and 2023 — roughly one million new households a year, each needing a home. Our article on <a href="{{a:pakistan-housing-gap-numbers}}">the housing gap</a> looks at the shortfall in detail.</li>
<li><strong>Density:</strong> faster urban growth on limited land pushes developers towards apartments and mixed-use buildings, which need lifts, pumps, fire systems and facade products as well as basic materials (see <a href="{{a:high-rise-growth-pakistan-cities}}">vertical growth in Pakistan’s cities</a>).</li>
<li><strong>Infrastructure:</strong> water, sewerage, roads and power networks have to grow with the population, creating steady demand for pipes, cables, pumps and civil works.</li>
</ul>
<p>What to watch: the census measures growth, not whether planning keeps up with it. The News article argues that housing, transport and energy need to be planned together.</p>
<p>Developers, contractors and suppliers working in Pakistan’s growing cities meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March 2027 at the Karachi Expo Centre. You can <a href="https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web">register to visit</a>.</p>
'''))

ARTICLES.append(dict(
    slug='gwadar-port-city-development-2026',
    category='cities-urban-development',
    title='Gwadar in 2026: a busier port, a new airport and a free zone looking for tenants',
    excerpt='Gwadar handled about 11,000 containers in April 2026 alone, more than in all of 2025. Where the port, the new airport and the free zone stand, and what to watch.',
    tags=['Gwadar', 'Ports', 'Balochistan'],
    sources=[SRC_APP_GWADAR, SRC_NEWS_GWADAR, SRC_BR_GWADAR],
    body='''
<p>For most of the past two decades Gwadar has been better known for its plans than its traffic. In 2026 that began to change, partly because of events far from Balochistan. Here is where the main pieces stand.</p>
<h2>The port: a sudden surge</h2>
<p>The News reported on 30 April 2026 that Gwadar had handled about <strong>11,000 standard containers</strong> so far that month, against roughly 8,300 in the whole of 2025. Historically, no more than 20 ships had called in a single year. The port offers over 90,000 square metres of general cargo storage.</p>
<p>The trigger was disruption around the Strait of Hormuz, which pushed shipping lines to look for alternatives. The government added a price incentive: Business Recorder reported in May 2026 that it had cut port charges as follows.</p>
<table>
<thead><tr><th>Charge</th><th>Reduction</th></tr></thead>
<tbody>
<tr><td>Berthing fees, transshipment container vessels</td><td>25%</td></tr>
<tr><td>International transshipment container cargo</td><td>40%</td></tr>
<tr><td>Transit container cargo</td><td>31%</td></tr>
</tbody>
</table>
<p>Larger ships followed. A vessel carrying over <strong>53,000 tonnes of steel billets</strong> berthed on 24 May 2026, after one with about 34,000 tonnes of transshipment cargo on 11 May.</p>
<h2>The airport and the free zone</h2>
<p>According to a government answer in the Senate in February 2026, the New Gwadar International Airport has been operating since <strong>20 January 2025</strong>. The Gwadar Free Zone covers about <strong>2,281 acres</strong> at the port and was described as fully operational, with ready warehouses, office space and industrial units. Its incentives include:</p>
<ul>
<li>a 23-year holiday from federal, provincial and local taxes;</li>
<li>leases of up to 99 years;</li>
<li>exemption from import duties and sales tax on machinery and equipment.</li>
</ul>
<p>Road links named in the same answer include the M-8 Ratodero–Gwadar motorway, the N-85 Hoshab–Surab highway and the N-10 Makran Coastal Highway to Karachi.</p>
<h2>Where the openings are</h2>
<ul>
<li><strong>Warehousing and port services:</strong> more cargo means more demand for storage, handling equipment and maintenance.</li>
<li><strong>Industrial units in the free zone:</strong> the duty exemption on machinery lowers the cost of setting up processing or assembly.</li>
<li><strong>Roads and utilities:</strong> connecting the port, airport and hinterland remains a federal responsibility and an ongoing programme of civil works.</li>
</ul>
<p>What to watch: The News described much of the new activity as transshipment and anchorage — a “temporary logistical buffer”, with cargo not meant for local markets. Some analysts saw a long-term opportunity, but the paper said it remained to be seen whether the surge would bring sustained growth after the crisis.</p>
<p>Contractors, equipment suppliers and project owners active across Pakistan meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a> in Karachi. See the <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a>.</p>
'''))

ARTICLES.append(dict(
    slug='lahore-new-urban-projects-ravi-cbd',
    category='cities-urban-development',
    title='Lahore’s new urban projects: Ravi City and the Central Business District in 2026',
    excerpt='River training and embankments along 46 km of the Ravi, and hotel, residential and commercial sites on offer in the CBD: where Lahore’s two flagship projects stand.',
    tags=['Lahore', 'RUDA', 'CBD Punjab'],
    sources=[SRC_DT_RUDA, SRC_NEWS_CBD, SRC_PP_RUDA],
    body='''
<p>Two Punjab government authorities are reshaping parts of Lahore: the Ravi Urban Development Authority (RUDA), which is developing a new city along the River Ravi, and CBD Punjab, which is turning state land into a business district. Both are led by the same chief executive, Imran Amin.</p>
<h2>Ravi City: flood protection first</h2>
<p>Daily Times reported in January 2026 that the riverfront project runs along a <strong>46 km</strong> stretch of the Ravi and, in the words of RUDA’s chief executive, covers “over 140,000 acres”. Most of the early work is river control:</p>
<ul>
<li>Phase 1 river channelisation covers 14.5 km, with the first 3 km on the right bank completed and the other reported packages at between 6.5% and 52%.</li>
<li>Phases 2 and 3 cover 31.5 km; two packages are under way on a government-to-government basis, both below 2% complete.</li>
<li>46 km of embankments are planned on both banks within three years; 7 km were built in 2025.</li>
<li>A 9.5 km, 140-foot-wide road linking the Lahore Ring Road with the riverbank is in progress.</li>
</ul>
<p>Other works reported include the Chaharbagh-01 regeneration across 138 acres (98% complete), civil infrastructure for the 168-acre Chaharbagh Enclave (43%), a completed RUDA Industrial Zone and 100,000 trees planted on 198 acres at Jhoke.</p>
<p>On funding, ProPakistani reported on 1 October 2026 that the Punjab cabinet had approved a <strong>Rs15 billion</strong> loan from CBD Punjab to RUDA for flood protection along the Ravi, to reach RUDA as a supplementary grant in 2026-27.</p>
<h2>The Central Business District: sites on offer</h2>
<p>In May 2026 CBD Punjab invited expressions of interest and sealed bids for:</p>
<table>
<thead><tr><th>Project</th><th>Location</th><th>Size</th></tr></thead>
<tbody>
<tr><td>The Regalia, branded residences (design, build, finance, own, operate and maintain)</td><td>CBD Quaid District</td><td>3 plots of 7.11 kanals each</td></tr>
<tr><td>Five-star-plus hotel and mixed-use complex</td><td>CBD Quaid District</td><td>41 kanals</td></tr>
<tr><td>Two petrol station sites, 10-year lease</td><td>CBD NSIT City</td><td>6.77 and 5.38 kanals</td></tr>
</tbody>
</table>
<h2>What this means for suppliers and partners</h2>
<ul>
<li><strong>Heavy civil works now:</strong> channelisation, embankments and roads call for earthmoving machinery, aggregates, cement and geotechnical expertise.</li>
<li><strong>Buildings later:</strong> hotels, branded residences and mixed-use towers will need facades, lifts, building services and fit-out once developers are chosen.</li>
</ul>
<p>What to watch: most Ravi channelisation packages beyond Phase 1 were still at an early stage or in design at the start of 2026; in our view, building plots depend on the flood works finishing.</p>
<p>Developers, contractors and machinery suppliers meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March 2027 at the Karachi Expo Centre. Interested companies can <a href="https://buildasia.net/contact/?enquiry=stand">enquire about a stand</a>.</p>
'''))

ARTICLES.append(dict(
    slug='high-rise-growth-pakistan-cities',
    category='cities-urban-development',
    title='Building upwards: a record Karachi tower, new parking and fire rules, and what towers need',
    excerpt='An 82-storey twin-tower project in Karachi, eased parking rules in Islamabad and tighter fire rules in Punjab: how 2026 is shaping tall buildings, and what they need.',
    tags=['High-rise', 'Building regulations', 'Fire safety'],
    sources=[SRC_PP_BURJ, SRC_PP_CDA, SRC_PP_FIRE],
    body='''
<p>As land in Pakistan’s big cities gets scarcer and dearer, more projects are going up rather than out. Three developments in 2026 show how the rules around tall buildings are moving — towards larger projects, simpler approvals and stricter safety.</p>
<h2>Karachi: the tallest project yet</h2>
<p>ProPakistani reported in July 2026 that the foundation stone had been laid for <strong>Burj Quaid</strong>, two towers of 82 storeys each on a six-acre site at DHA Golf Club, Karachi. It said the towers are expected to be Pakistan’s tallest buildings once complete, and project officials said the Pakistan Civil Aviation Authority had approved the height. The report links the project to ABS Developers.</p>
<h2>Islamabad: fewer basements</h2>
<p>In August 2026 the Capital Development Authority (CDA) Board amended its Building Control Regulations 2023 to ease parking for high-rise commercial buildings:</p>
<table>
<thead><tr><th>Parking rule</th><th>Requirement</th></tr></thead>
<tbody>
<tr><td>Before</td><td>1 space per 750 sq ft of covered commercial area</td></tr>
<tr><td>From August 2026</td><td>1 space per 1,000 sq ft</td></tr>
</tbody>
</table>
<p>The CDA said the old ratio had forced some projects to dig up to six basement levels, raising costs and concerns about soil and structural safety. The new rule applies to commercial plots offered at its auction of 4–6 August 2026 and later auctions.</p>
<h2>Punjab: fire safety tightened</h2>
<p>After the Gul Plaza fire in Karachi, the Punjab government issued new rules for commercial buildings in January 2026. Firefighting equipment became mandatory, every floor must have a fire extinguisher, and three-storey buildings must install steel evacuation staircases (no NOC needed). Owners were given 15 days to comply. In Rawalpindi, the development authority sent a fire safety checklist to high-rise owners and planned audits covering fire equipment, electrical systems and emergency routes.</p>
<h2>What tall buildings need</h2>
<p>Every tower is a bundle of systems that low-rise construction barely uses. In our view, the main ones are:</p>
<ul>
<li><strong>Vertical transport:</strong> lifts and, in commercial buildings, escalators, with long-term maintenance contracts.</li>
<li><strong>Facades:</strong> glazing, aluminium and cladding systems that handle wind, heat and fire.</li>
<li><strong>Fire safety:</strong> detection, alarms, sprinklers, pumps, fire-rated doors and protected stairs.</li>
<li><strong>Building services (MEP):</strong> water pressure pumps, chillers, standby power and electrical distribution.</li>
<li><strong>Structure and foundations:</strong> high-strength concrete, rebar, formwork and piling.</li>
</ul>
<p>What to watch: the 2026 safety push was a reaction to a disaster, and in our view enforcement across existing buildings is the open question. For designers, our article on <a href="{{a:designing-for-heat-karachi-lessons}}">designing for heat in Karachi</a> covers related facade choices.</p>
<p>Lift, facade, fire safety and MEP suppliers meet developers and contractors at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a> in Karachi. See the <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a>.</p>
'''))

ARTICLES.append(dict(
    slug='punjab-housing-programme-apni-chhat',
    category='cities-urban-development',
    title='Apni Chhat Apna Ghar: over 100,000 homes built with Punjab’s interest-free loans',
    excerpt='By June 2026 Punjab’s housing programme had completed 100,633 houses with more than Rs208 billion in interest-free loans. What it means for building-material demand.',
    tags=['Housing', 'Punjab', 'Building materials'],
    sources=[SRC_NEWS_ACAG_MAR, SRC_NEWS_ACAG_JUN, SRC_TRIB_ACAG],
    body='''
<p>Punjab’s <strong>Apni Chhat Apna Ghar</strong> (“my roof, my home”) programme lends families money, interest-free, to build a house on their own plot, which is pledged as security. Because each family builds its own home, in our view much of the money goes into local purchases of cement, bricks, steel and fittings.</p>
<h2>Progress in 2026</h2>
<p>Figures given to the Punjab Housing Minister, Bilal Yasin, by the Punjab Housing and Town Planning Agency (PHATA) show how fast the programme moved in the first half of the year:</p>
<table>
<thead><tr><th>Indicator</th><th>March 2026</th><th>June 2026</th></tr></thead>
<tbody>
<tr><td>Houses completed</td><td>83,330</td><td>100,633</td></tr>
<tr><td>Houses under construction</td><td>45,425</td><td>32,412</td></tr>
<tr><td>Families given loans</td><td>131,680</td><td>over 156,000</td></tr>
<tr><td>Loans provided</td><td>over Rs183 billion</td><td>over Rs208 billion</td></tr>
</tbody>
</table>
<p>In March 2026 the programme had more than 1.9 million registrations and over 1.135 million applications, and Rs8.4 billion had been repaid. In June the minister said the government would fund interest-free loans for <strong>another 100,000 families</strong> in the coming fiscal year.</p>
<p>A companion scheme, <strong>Apni Zameen Apna Ghar</strong>, was providing free residential plots to 2,000 families, according to the minister in March 2026.</p>
<h2>What this means for materials demand</h2>
<p>By our own arithmetic, about 17,300 houses were completed between the March and June updates — well over 5,000 a month. A few observations follow:</p>
<ul>
<li><strong>Small, scattered buyers:</strong> loans of up to Rs1.5 million each (as reported in Vehari) go to individual families across the province, so demand reaches district dealers and shops rather than big contractors.</li>
<li><strong>Basic materials first:</strong> cement, bricks, steel bars, roofing, doors, windows, wiring and sanitary fittings make up most of a modest single-family house.</li>
<li><strong>Room for efficient methods:</strong> standard designs and faster building systems could help families build within a fixed loan; see our article on <a href="{{a:light-gauge-steel-framing-low-rise-homes}}">light-gauge steel framing</a>.</li>
</ul>
<h2>What to watch</h2>
<p>The Express Tribune reported in August 2026 that applicants in Vehari whose loans were approved between January and June 2026 were still waiting for their first instalment. Demand from the programme depends on the money arriving on time.</p>
<p>For the national picture, read <a href="{{a:pakistan-housing-gap-numbers}}">Pakistan’s housing gap in numbers</a>. Material makers and dealers serving the housing market meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March 2027 at the Karachi Expo Centre. You can <a href="https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web">register to visit</a>.</p>
'''))
