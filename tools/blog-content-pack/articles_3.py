# Build Asia starter articles — part 3: Sustainable Building, Exhibition News

SRC_ECBC = ('Profit by Pakistan Today, "Energy ministry links building code to power efficiency standards", 30 May 2025',
            'https://profit.pakistantoday.com.pk/2025/05/30/energy-ministry-links-building-code-to-power-efficiency-standards')
SRC_SOLAR = ('ProPakistani, "Pakistan\'s solar boom reaches 7,000 MW as govt moves to replace net metering", 17 July 2026',
             'https://propakistani.pk/2026/07/17/pakistans-solar-boom-reaches-7000-mw-as-govt-moves-to-replace-net-metering/')
SRC_HEAT2015 = ('Dialogue Earth, "Karachi turns into heat trap", 24 June 2015',
                'https://dialogue.earth/en/climate/karachi-turns-into-heat-trap/')
SRC_HEAT2024 = ('Bloomberg via BNN Bloomberg, "Karachi sees a surge in deaths as heat wave sears Pakistan", 27 June 2024',
                'https://bnnbloomberg.ca/karachi-sees-a-surge-in-deaths-as-heat-wave-sears-pakistan-1.2090293')

ARTICLES = []

ARTICLES.append(dict(
    slug='buildings-electricity-energy-conservation-building-code',
    category='sustainable-building',
    featured=5,
    title='Buildings use about 60% of Pakistan’s electricity. Can a building code change that?',
    excerpt='The federal government wants the Energy Conservation Building Code 2023 applied to new construction. What the code is for, and what it means for designers and suppliers.',
    tags=['Energy efficiency', 'ECBC', 'Policy'],
    sources=[SRC_ECBC],
    body='''
<p>Every summer, Pakistan’s grid is pushed to its limit by one thing above all: keeping buildings cool. According to the Federal Energy Minister, Sardar Awais Ahmed Khan Leghari, buildings account for <strong>about 60% of electricity use</strong> in the country.</p>
<h2>The push for the code</h2>
<p>In May 2025 the minister wrote to provincial Chief Ministers and federal officials urging them to adopt the <strong>Energy Conservation Building Code 2023 (ECBC-2023)</strong> as the national standard for new construction. His letters called for:</p>
<ul>
<li>ECBC-2023 to apply across all development plans;</li>
<li>compliance by all government-funded projects under the Public Sector Development Programme;</li>
<li>provinces to build it into their planning — with a proposal to link approval of future projects to compliance;</li>
<li>efficient electrical systems in all new buildings.</li>
</ul>
<h2>What an energy code does</h2>
<p>An energy conservation building code sets minimum standards for how much energy a building may use — typically through the building envelope (walls, roofs, windows and insulation), lighting, air-conditioning and electrical systems. The idea is simple: decisions made at design stage lock in a building’s electricity bills for decades.</p>
<h2>What it means in practice</h2>
<ul>
<li><strong>Architects and engineers</strong> will need to show compliance at design stage, especially on public projects.</li>
<li><strong>Developers</strong> can market lower running costs to buyers and tenants facing high tariffs.</li>
<li><strong>Suppliers</strong> of insulation, high-performance glazing, reflective roofing, efficient air-conditioning, LED lighting and building controls stand to see demand grow.</li>
</ul>
<h2>The open questions</h2>
<p>The success of any code depends on enforcement by local building authorities, on training for designers and inspectors, and on materials being available at prices builders can afford. Those are the conversations the industry will need to have over the coming years.</p>
<p>Energy-efficient buildings and green construction practices are among the opportunity areas highlighted at Build Asia. <a href="{{a:rooftop-solar-7000-mw-building-design}}">See also: how rooftop solar is changing building design.</a></p>
'''))

ARTICLES.append(dict(
    slug='rooftop-solar-7000-mw-building-design',
    category='sustainable-building',
    title='7,000 MW on the rooftops: how solar is reshaping building design',
    excerpt='Net-metered solar grew 37-fold in six years. Roofs, structures and wiring now have to be designed for panels from day one.',
    tags=['Solar', 'Energy', 'Design'],
    sources=[SRC_SOLAR],
    body='''
<p>Pakistan’s rooftops have changed faster than almost anyone expected. Solar generation under net metering grew from <strong>190 MW in FY2020 to about 6,978 MW by FY2026</strong> — nearly 37 times in six years — according to figures presented by Syed Faizan Ali, Energy Adviser to the Power Division.</p>
<h2>Why it happened</h2>
<p>The same presentation set out the drivers: electricity tariffs rose about <strong>140%</strong> between FY2021 and FY2025, while the rupee lost about 75% of its value and imported solar panel prices fell by about <strong>60%</strong>. For many homes and businesses, generating their own power became the obvious choice.</p>
<h2>The rules are changing</h2>
<p>Under the Prosumer Regulations 2026, which took effect on 8 February 2026, new consumers move to <strong>net billing</strong>: electricity they export to the grid is paid at a lower reference price rather than credited one-for-one. Consumers with 2015 net-metering agreements keep their terms until their contracts expire. The result is that it now pays to use more of your own solar power on site — which puts more weight on batteries and on efficient buildings.</p>
<h2>What this means for building design</h2>
<ul>
<li><strong>Roofs:</strong> plan clear, shade-free areas, the right orientation, and safe access for cleaning and maintenance.</li>
<li><strong>Structure:</strong> design for the weight of panels and mounting frames, and for wind loads.</li>
<li><strong>Waterproofing:</strong> mounting penetrations are a common source of leaks if not detailed properly.</li>
<li><strong>Electrical:</strong> space for inverters and batteries, proper earthing, and cable routes planned in advance.</li>
<li><strong>Efficiency first:</strong> every unit of electricity saved by insulation and shading is one the panels do not need to produce.</li>
</ul>
<h2>Opportunities across the industry</h2>
<p>Solar installers, inverter and battery suppliers, cable makers, roofing and waterproofing companies, and structural engineers all have a part in getting rooftop solar right. Generators, the solar industry, UPS systems and electrical cables are exhibitor categories at Build Asia. <a href="{{a:buildings-electricity-energy-conservation-building-code}}">Read about the energy building code.</a></p>
'''))

ARTICLES.append(dict(
    slug='designing-for-heat-karachi-lessons',
    category='sustainable-building',
    title='Designing for heat: lessons from Karachi’s heatwaves',
    excerpt='From 2015 to 2024, deadly heatwaves have shown how buildings and cities can trap heat. What architects, builders and planners can do about it.',
    tags=['Climate', 'Design', 'Karachi'],
    sources=[SRC_HEAT2015, SRC_HEAT2024],
    body='''
<p>Karachi normally relies on its sea breeze, which the Pakistan Meteorological Department says keeps temperatures nearly 15°C lower than they would otherwise be. When that breeze fails during a heatwave, the city’s buildings and streets become part of the problem.</p>
<h2>What the heatwaves showed</h2>
<p>In June 2015 a heatwave lasting five to six days killed more than 750 people in the city, according to official reports at the time. In June 2024 Karachi reached 44°C, more than 7°C above normal, and the Edhi Foundation reported receiving 568 bodies over five days — far above its usual daily average — while officials disputed how many deaths were due to heat. The Sindh health department reported more than 2,100 heatstroke cases across the province that month. In both years, power cuts left many people without fans or cooling.</p>
<h2>How cities trap heat</h2>
<p>Experts quoted after the 2015 heatwave pointed to the way the city is built:</p>
<ul>
<li><strong>High-rise buildings closing natural wind corridors</strong>, according to urban planner Farhan Anwar;</li>
<li><strong>concrete surfaces</strong> that absorb heat by day and release it at night, so the city cannot “breathe”;</li>
<li><strong>too few trees</strong> to cool the air.</li>
</ul>
<h2>What design can do</h2>
<ul>
<li><strong>Cool and green roofs:</strong> reflective coatings or planted roofs reduce heat entering top floors. Anwar proposed greening roofs and pointed to cities that now require roof gardens or solar panels.</li>
<li><strong>Shading:</strong> deep window reveals, shades and verandas — features of traditional South Asian architecture.</li>
<li><strong>Ventilation:</strong> orientation and openings that use the sea breeze, and layouts that keep air moving between buildings.</li>
<li><strong>Insulation:</strong> keeps heat out, and keeps homes bearable during power cuts.</li>
<li><strong>Trees and water:</strong> neem trees and revived natural drainage channels were among the measures suggested.</li>
</ul>
<h2>A shared responsibility</h2>
<p>No single building fixes a city’s heat, but every building either adds to it or helps. Planners, architects, developers and material suppliers all have a part — and the same measures cut electricity bills, as the <a href="{{a:buildings-electricity-energy-conservation-building-code}}">energy building code</a> aims to do.</p>
<p>Insulation, reflective coatings, glazing and shading products are among the materials on show at Build Asia in Karachi.</p>
'''))

ARTICLES.append(dict(
    slug='build-asia-2027-what-to-expect',
    category='exhibition-news',
    featured=0,
    is_lead=True,
    title='Build Asia 2027: 18–20 March at Karachi Expo Centre — what to expect',
    excerpt='The 21st Build Asia brings building materials, construction machinery, property and stone together in Karachi. Dates, who exhibits, who visits, and how to take part.',
    tags=['Build Asia 2027', 'Karachi', 'Exhibition'],
    sources=[],
    body='''
<p>The <strong>21st Build Asia International Exhibition and Conference</strong> takes place from <strong>18 to 20 March 2027</strong> at the <strong>Karachi Expo Centre</strong>, open daily from 10:00 to 18:00. It is Pakistan’s international building materials and construction machinery show, organised by Ecommerce Gateway Pakistan (Pvt) Ltd, a member of UFI, the Global Association of the Exhibition Industry.</p>
<h2>Why it matters</h2>
<p>Build Asia is where Pakistan’s construction industry meets in one place: manufacturers, importers and distributors on one side; builders, developers, contractors, architects, engineers and government agencies on the other. It is also a gateway for international companies looking at Pakistan and the region, and for local firms seeking joint-venture partners and technology transfer.</p>
<h2>What is on show</h2>
<p>Exhibitors cover the whole building chain, including:</p>
<ul>
<li>cranes, earthmoving and hoisting machinery, drilling and mining equipment;</li>
<li>pre-fabricated building components, pre-engineered steel buildings and prefab housing;</li>
<li>steel, cement and concrete technology, construction chemicals;</li>
<li>marble, granite, ceramics and tiles; wood and timber;</li>
<li>paints and coatings, aluminium and PVC windows and doors, glazing and facades;</li>
<li>pumps, pipes, cables, generators, solar and electrical systems;</li>
<li>furniture, lighting, fire safety and security systems;</li>
<li>builders, developers, banks, housing finance and real estate services.</li>
</ul>
<p>Co-located shows including Stonefair Asia and Furniture Asia run alongside. See the full <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a>.</p>
<h2>Who visits</h2>
<p>The audience includes architects, engineers and town planners; builders, contractors and developers; interior designers; distributors and investors; government authorities and decision-makers; associations; consultants; real estate professionals; and foreign companies seeking joint ventures. See the <a href="https://buildasia.net/visitor-profile/">visitor profile</a>.</p>
<h2>How to take part</h2>
<ul>
<li><strong>Visiting:</strong> trade and corporate visitors can <a href="https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web">register online</a>; anyone who has not registered can register at the on-site desks.</li>
<li><strong>Exhibiting:</strong> stands are available as ready-built shell scheme or bare space. <a href="{{a:exhibiting-shell-scheme-or-bare-space}}">Which is right for you?</a> To enquire, <a href="https://buildasia.net/contact/?enquiry=stand">book a stand</a>.</li>
</ul>
<p>The last Karachi edition drew more than 32,000 trade visitors and 350 exhibitors from over 21 countries. <a href="{{a:build-asia-2026-in-numbers}}">See the results.</a></p>
'''))

ARTICLES.append(dict(
    slug='build-asia-2026-in-numbers',
    category='exhibition-news',
    title='Build Asia 2026 in numbers: 32,000 visitors, 350 exhibitors, 21 countries',
    excerpt='The figures from the post-show report of the January 2026 Karachi edition, and who opened and supported it.',
    tags=['Build Asia 2026', 'Results', 'Karachi'],
    sources=[],
    body='''
<p>The most recent Karachi edition of Build Asia was held from <strong>22 to 24 January 2026</strong> at the Karachi Expo Centre. These are the figures from its post-show report.</p>
<table>
<thead><tr><th>Build Asia Karachi 2026</th><th></th></tr></thead>
<tbody>
<tr><td>Trade visitors</td><td>32,000+</td></tr>
<tr><td>Exhibitors, international and local</td><td>350+</td></tr>
<tr><td>Countries taking part</td><td>21+</td></tr>
<tr><td>Indoor exhibition space</td><td>190,000+ sq ft (about 18,000 m²)</td></tr>
<tr><td>Outdoor area for heavy machinery</td><td>50,000+ sq ft (about 5,000 m²)</td></tr>
</tbody>
</table>
<h2>Opening and closing</h2>
<p>The edition was inaugurated by senior figures from the Association of Builders and Developers, together with the Consuls General of Oman and Iran, the Deputy Trade Representative of the Russian Federation Trade Mission, and the Principal Secretary to the Governor of Sindh. The closing ceremony was attended by the Minister for Local Government, Sindh.</p>
<h2>Supporters</h2>
<p>The event was supported by the Ministry of Industries and Production, the Trade Development Authority of Pakistan and the Pakistan Engineering Council. Build Asia’s supporters also include PASDEC, the All Pakistan Marble Industries Association, the All Karachi Marble Industries Association and the Karachi Chamber of Commerce and Industry.</p>
<h2>Alongside the exhibition</h2>
<p>The PROPTECH Convention ran with the show, covering housing, finance, design and technology in real estate. <a href="{{a:proptech-convention-2026-conference-themes}}">Read what it covered.</a></p>
<h2>Next edition</h2>
<p>The 21st edition takes place from 18 to 20 March 2027 at the Karachi Expo Centre. <a href="{{a:build-asia-2027-what-to-expect}}">What to expect at Build Asia 2027.</a> Photos from past editions are in the <a href="https://buildasia.net/gallery/">gallery</a>.</p>
'''))

ARTICLES.append(dict(
    slug='planning-your-visit-build-asia-karachi',
    category='exhibition-news',
    title='Planning your visit to Build Asia: a guide for trade visitors',
    excerpt='Registration, opening hours, getting the most from three days at the show, and help for visitors coming from abroad.',
    tags=['Visitors', 'Guide', 'Build Asia 2027'],
    sources=[],
    body='''
<p>Three days at a large trade show go quickly. A little planning turns a walk around the halls into meetings that move your projects forward.</p>
<h2>The essentials</h2>
<ul>
<li><strong>Dates:</strong> 18–20 March 2027</li>
<li><strong>Hours:</strong> 10:00 to 18:00 each day</li>
<li><strong>Venue:</strong> Karachi Expo Centre, Main University Road, Gulshan-e-Iqbal, Karachi</li>
<li><strong>Who can attend:</strong> trade and corporate visitors</li>
</ul>
<h2>Register before you go</h2>
<p><a href="https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web">Register online</a> for your visitor pass to save time at the entrance. If you have not registered, you can still register at the on-site desks.</p>
<h2>Make a plan</h2>
<ol>
<li><strong>List what you need:</strong> products to source, problems to solve, suppliers to compare.</li>
<li><strong>Check who is exhibiting</strong> and mark the stands to visit first.</li>
<li><strong>Book meetings</strong> with key suppliers in advance — their senior people are busy during show days.</li>
<li><strong>Bring drawings or specifications</strong> for current projects, so suppliers can give useful answers.</li>
<li><strong>Leave time to explore:</strong> the best finds are often stands you did not plan to visit.</li>
<li><strong>Visit the outdoor area</strong> to see heavy machinery in person.</li>
</ol>
<h2>Coming from abroad</h2>
<p>Recommended hotels are in the city centre, about 20–30 minutes from the Karachi Expo Centre. The organisers offer foreign visitor facilitation; contact details and travel information are on the <a href="https://buildasia.net/karachi/travel/">travel page</a>.</p>
<h2>After the show</h2>
<p>Follow up within a week while conversations are fresh: send your requirements, ask for quotations and samples, and arrange site or factory visits with the suppliers that impressed you.</p>
<p>Questions? Contact the Build Asia Secretariat at info@buildasia.net or (92-21) 111-222-444. <a href="{{a:build-asia-2027-what-to-expect}}">What to expect at Build Asia 2027.</a></p>
'''))

ARTICLES.append(dict(
    slug='exhibiting-shell-scheme-or-bare-space',
    category='exhibition-news',
    title='Exhibiting at Build Asia: shell scheme or bare space?',
    excerpt='The two ways to take a stand, what each includes, and how to choose — plus tips for a stand that brings business.',
    tags=['Exhibitors', 'Guide', 'Build Asia 2027'],
    sources=[],
    body='''
<p>Companies taking part in Build Asia choose between two kinds of stand. The right choice depends on what you are showing, your budget, and how you want to be seen.</p>
<h2>Shell scheme: ready to use</h2>
<p>A shell scheme stand is ready-built. The structure, fascia board with your company name, and basic furniture are supplied, so you arrive to a finished space and only need to bring products and graphics. Shell scheme starts at <strong>9 square metres</strong> and increases in 9 m² steps (9, 18, 27 …).</p>
<p><strong>Best for:</strong> first-time exhibitors, smaller products, distributors, service companies, and anyone who wants a simple, predictable setup.</p>
<h2>Bare space: your own design</h2>
<p>Bare space is floor area only. You design and build your own stand, usually with a contractor. Bare space starts at <strong>18 square metres</strong> and increases in 9 m² steps (18, 27, 36 …).</p>
<p><strong>Best for:</strong> established brands that want a distinctive presence, companies displaying large machinery, and anyone with a stand design they reuse across shows.</p>
<p>Both options include a 200-word company profile in the official exhibition catalogue. Heavy machinery can also be shown in the outdoor area.</p>
<h2>Tips for a stand that brings business</h2>
<ol>
<li><strong>Set a goal:</strong> leads, distributors, launches or brand awareness — and design for it.</li>
<li><strong>Show, don’t just tell:</strong> working products, samples people can touch, and short demonstrations draw crowds.</li>
<li><strong>Keep the message short:</strong> visitors should know what you do from the aisle in three seconds.</li>
<li><strong>Staff it with decision-makers</strong> who can answer technical and commercial questions.</li>
<li><strong>Capture every lead</strong> with notes on what the visitor needs.</li>
<li><strong>Invite your customers</strong> before the show and give them a reason to come.</li>
<li><strong>Follow up within a week.</strong></li>
</ol>
<p>Check whether your products fit the <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a>, then <a href="https://buildasia.net/contact/?enquiry=stand">book a stand</a> for 18–20 March 2027.</p>
'''))

ARTICLES.append(dict(
    slug='proptech-convention-2026-conference-themes',
    category='exhibition-news',
    title='PROPTECH Convention 2026: what the conference covered',
    excerpt='Affordable housing, REITs and tokenisation, digital payments, branded residences and women leaders — the themes from Build Asia’s 2026 conference.',
    tags=['Conference', 'Proptech', 'Real estate'],
    sources=[],
    body='''
<p>Alongside the exhibition, Build Asia runs a conference programme. At the January 2026 edition in Karachi, the <strong>PROPTECH Convention</strong> brought together developers, REIT managers, bankers — including speakers from the State Bank of Pakistan — architects and international real estate firms.</p>
<h2>The themes</h2>
<ul>
<li><strong>Affordable housing</strong> — how to deliver homes most families can actually buy. <a href="{{a:pakistan-housing-gap-numbers}}">The numbers behind the housing gap.</a></li>
<li><strong>Responsible coastal development</strong> — building near the sea without damaging it.</li>
<li><strong>Branded residences</strong> — the future of developments carrying hotel and lifestyle brands.</li>
<li><strong>What buyers want versus what the market offers</strong> — closing the gap between product and demand.</li>
<li><strong>REITs and tokenisation</strong> — new ways to fund and invest in property. <a href="{{a:reits-pakistan-real-estate-investment}}">Where Pakistan’s REIT market stands.</a></li>
<li><strong>Digital payments</strong> integrated with property technology platforms.</li>
<li><strong>Design and structure</strong>.</li>
<li><strong>Industrial growth in Karachi</strong>.</li>
<li><strong>Women leaders across industries</strong>.</li>
<li><strong>Non-banking financial businesses</strong> in real estate.</li>
<li><strong>Social media</strong> and its role in scaling real estate businesses.</li>
</ul>
<h2>Why proptech matters</h2>
<p>Technology is changing how property is financed, marketed, sold and managed — from online listings and digital payments to fractional investment. For an industry where most transactions have been slow and paper-based, these changes can improve transparency and widen access to investment.</p>
<h2>2027</h2>
<p>The conference programme for Build Asia 2027 (18–20 March, Karachi Expo Centre) is expected to be announced shortly. <a href="{{a:build-asia-2027-what-to-expect}}">What to expect at Build Asia 2027.</a></p>
'''))
