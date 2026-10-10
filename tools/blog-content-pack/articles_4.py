# Build Asia starter articles — part 4 (writer A): Infrastructure & Projects
# Facts only from the sources listed in each article (opened 10 Oct 2026).

# --- PSDP ---
SRC_PSDP_HIGHLIGHTS = ('APP, "Highlights of PSDP for FY 2026-27", 12 June 2026',
                       'https://www.app.com.pk/?p=1225059')
SRC_PSDP_COMMS = ('APP, "Rs 224,709 m allocated for Communications Division", 12 June 2026',
                  'https://www.app.com.pk/?p=1225079')
SRC_PSDP_Q1 = ('APP, "Govt. authorises Rs220.721bn in PSDP funds for FY2026-27 Q1", 6 October 2026',
               'https://www.app.com.pk/?p=1286712')

# --- Dams ---
SRC_DAMS_BUDGET = ('Zameen News, "Budget 2026-27: PKR 103bn allocated for key dam, hydropower schemes", 16 June 2026',
                   'https://www.zameen.com/news/pkr-103bn-allocated-for-key-dams-in-budget.html')
SRC_DAMS_RCC = ('The News, "RCC work to begin at Diamer Basha & Dasu dams this year", 17 February 2026',
                'https://www.thenews.pk/print/1399578-rcc-work-to-begin-at-diamer-basha-dasu-dams-this-year')
SRC_MOHMAND = ('WAPDA, "Construction continues on 10 key sites" (Mohmand Dam Project), 19 January 2026',
               'https://wapda.gov.pk/?p=4045')

# --- Motorways ---
SRC_M6_SEP = ('ProPakistani, "Construction Starting Date Announced for New Motorway", 22 September 2026',
              'https://propakistani.pk/2026/09/22/construction-starting-date-announced-for-new-motorway/')
SRC_M6_MAY = ('APP, "Govt steps up work on Hyderabad-Sukkur Motorway", 21 May 2026',
              'https://www.app.com.pk/?p=1214354')
SRC_CPEC_SURVEY = ('APP, "CPEC projects continue to boost connectivity, infrastructure development: Economic Survey", 11 June 2026',
                   'https://www.app.com.pk/?p=1224525')

# --- Karachi ---
SRC_K4_DAWN = ('Dawn, "Federal minister sets new deadline for completion of K-IV water supply project", 13 February 2026',
               'https://www.dawn.com/news/1972789/federal-minister-sets-new-deadline-for-completion-of-k-iv-water-supply-project')
SRC_K4_NUKTA = ('Nukta, "K-IV: 70% complete, but where is Karachi\'s water?", 22 August 2026',
                'https://nukta.com/k-iv-70-complete-but-where-is-karachis-water')
SRC_REDLINE = ('Nukta, "Karachi\'s BRT Red Line project plagued by delays, rising costs", 27 March 2026',
               'https://nukta.com/karachi-brt-red-line-project-delays')

# --- PPP ---
SRC_P3A_SHIFT = ('Profit by Pakistan Today, "Public Private Partnership Authority\'s administrative control shifted to Privatisation Division", 13 May 2026',
                 'https://profit.pakistantoday.com.pk/2026/05/13/public-private-partnership-authoritys-administrative-control-shifted-to-privatisation-division/')
SRC_M13 = ('Profit by Pakistan Today, "Govt moves to award Rs205 billion Kharian-Rawalpindi Motorway to FWO", 25 June 2026',
           'https://profit.pakistantoday.com.pk/2026/06/25/govt-moves-to-award-rs205-billion-kharian-rawalpindi-motorway-to-fwo-without-competitive-bidding-report')

ARTICLES = []

ARTICLES.append(dict(
    slug='psdp-2026-27-infrastructure-spending',
    category='infrastructure-projects',
    title='PSDP 2026-27: where the federal development money goes, and the work it creates',
    excerpt='Highways, water and power take the largest shares of the Rs1 trillion federal PSDP for 2026-27. The allocations, the biggest road schemes and how much had been released by September.',
    tags=['PSDP', 'Infrastructure', 'Budget 2026-27'],
    sources=[SRC_PSDP_HIGHLIGHTS, SRC_PSDP_COMMS, SRC_PSDP_Q1],
    body='''
<p>The Public Sector Development Programme (PSDP) is the government’s budget for building things: roads, dams, power lines and public buildings. For contractors and suppliers it is the clearest published guide to where public-sector work will come from in the year ahead.</p>
<h2>The size of the programme</h2>
<p>Budget figures released on 12 June 2026 put the national development outlay for 2026-27 at <strong>Rs3.675 trillion</strong>: Rs1 trillion for the federal PSDP, Rs2.224 trillion for the provinces and Rs451 billion for state-owned enterprises. The largest federal infrastructure allocations are:</p>
<table>
<thead><tr><th>Federal PSDP 2026-27</th><th>Allocation</th></tr></thead>
<tbody>
<tr><td>National Highway Authority (NHA)</td><td>Rs224.5 billion</td></tr>
<tr><td>Water Resources Division</td><td>Rs103.1 billion</td></tr>
<tr><td>Power Division (NTDC/PEPCO)</td><td>Rs88 billion</td></tr>
<tr><td>Railways Division</td><td>Rs40.7 billion</td></tr>
<tr><td>Housing and Works Division</td><td>Rs16.4 billion</td></tr>
</tbody>
</table>
<h2>Which roads get the money</h2>
<p>The NHA’s list is weighted towards Balochistan and Sindh. Among the largest items:</p>
<ul>
<li><strong>N-25 (Karachi–Quetta–Chaman):</strong> Rs48 billion for the Kararo–Wadh and Kuchlak–Chaman sections, Rs30 billion for dualisation and rehabilitation of other sections, and Rs22 billion for the 330 km Khuzdar–Kuchlak section.</li>
<li><strong>Hyderabad–Sukkur Motorway (306 km, six lanes):</strong> Rs30 billion.</li>
<li><strong>N-5, Moro to Ranipur:</strong> Rs9 billion, including 32 damaged bridges, with ADB funding.</li>
<li><strong>Indus Highway (N-55):</strong> Rs5 billion each for the ADB-funded Shikarpur–Rajanpur and Rajanpur–D.G. Khan sections.</li>
<li><strong>Karakoram Highway, Thakot–Raikot:</strong> Rs5 billion for 102 km of relocation, funded by China EXIM.</li>
</ul>
<h2>Allocation is not spending</h2>
<p>Money is released in stages. In the first quarter (July–September 2026) the government authorised <strong>Rs220.7 billion</strong>, about 22% of the federal PSDP, while spending reached <strong>Rs61.3 billion</strong>, around 6%. The Water Resources Division had the largest authorisation (Rs86.5 billion); the NHA had Rs22.4 billion authorised and Rs9.3 billion spent.</p>
<h2>Where the openings are</h2>
<p>Our reading of these figures, not the government’s:</p>
<ul>
<li>Several big road schemes carry ADB or Chinese lender funding, which usually means internationally tendered packages and work for both local and foreign contractors.</li>
<li>Roadworks in Balochistan and Sindh, plus dam and power schemes, point to steady demand for aggregates, bitumen, cement, steel and earthmoving equipment.</li>
<li>What to watch: the slow first-quarter spend. Quarterly release figures show how fast work can actually start on site.</li>
</ul>
<p>More detail: <a href="{{a:dams-under-construction-materials-demand}}">the dams</a>, <a href="{{a:motorways-highways-pipeline-2026}}">the motorway pipeline</a> and <a href="{{a:construction-machinery-imports-rise}}">machinery imports</a>. Contractors and suppliers working on public projects meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, 18–20 March at the Karachi Expo Centre.</p>
'''))

ARTICLES.append(dict(
    slug='dams-under-construction-materials-demand',
    category='infrastructure-projects',
    title='Mohmand, Diamer-Bhasha and Dasu: where Pakistan’s big dams stand in 2026',
    excerpt='Three large dams are under construction at once, with roller-compacted concrete work due at two of them in 2026. Their scale, progress, this year’s funding and what they consume.',
    tags=['Dams', 'Hydropower', 'WAPDA'],
    sources=[SRC_MOHMAND, SRC_DAMS_RCC, SRC_DAMS_BUDGET],
    body='''
<p>Pakistan has three large dams under construction at the same time, all run by WAPDA. By our own addition of the figures below, together they account for more than 9,600 MW of planned generating capacity.</p>
<h2>The three projects</h2>
<table>
<thead><tr><th>Project</th><th>Key figures</th><th>Status</th><th>PSDP 2026-27</th></tr></thead>
<tbody>
<tr><td>Mohmand (River Swat, KP)</td><td>213 m concrete-faced rock-fill dam, 1.29 MAF storage, 800 MW</td><td>About 50% overall (January 2026); power by early 2028</td><td>Rs22bn</td></tr>
<tr><td>Diamer-Bhasha (Indus, near Chilas)</td><td>8.1 MAF storage, 4,500 MW</td><td>Completion scheduled for 2030</td><td>Rs10bn, plus Rs4bn for land and resettlement</td></tr>
<tr><td>Dasu (Indus, Upper Kohistan)</td><td>4,320 MW in two stages</td><td>Generation expected in 2028</td><td>Rs15bn (Stage I)</td></tr>
</tbody>
</table>
<h2>Progress on site</h2>
<p>At <strong>Mohmand</strong>, WAPDA reported in January 2026 that work continued on ten sites. Pit excavation for the main dam finished in mid-2025, the rock fill had reached 392 m above sea level, the spillway was about 70% complete and powerhouse structural work had begun. The WAPDA chairman asked the contractor to speed up quarry development, which the main dam depends on.</p>
<p>At <strong>Diamer-Bhasha</strong>, The News reported in February 2026 that work was under way at 20 sites, excavation of the left abutment and dam pit was complete, and roller-compacted concrete (RCC) trials had succeeded. RCC placement was due to start in 2026 once the right abutment is excavated. At <strong>Dasu</strong>, 23 work fronts were active, crushing and batching plants were running, and RCC pouring for the main dam was also due in 2026.</p>
<h2>What they consume</h2>
<p>Our observation: the move to full RCC and rock-fill placement is the phase that draws most heavily on cement, aggregates, crushing and batching plant, haulage and earthmoving fleets, followed by electro-mechanical equipment for the powerhouses. The same budget also funds Tarbela’s 1,410 MW 5th extension (Rs3.4 billion), a refurbishment to lift Mangla Power Station from 1,000 MW to 1,310 MW, and smaller dams in Balochistan such as Awaran, Panjgur and Garuk.</p>
<p><strong>What to watch:</strong> the Rs103 billion water and hydropower allocation for 2026-27 is more than 42% below the Rs179 billion the Planning Ministry proposed, so the pace of releases matters. See <a href="{{a:psdp-2026-27-infrastructure-spending}}">our PSDP overview</a>.</p>
<p>Suppliers of cement, construction chemicals and heavy machinery meet project contractors at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a> in Karachi, 18–20 March. The <a href="https://buildasia.net/exhibit-profile/">exhibitor profile</a> lists the product groups on show.</p>
'''))

ARTICLES.append(dict(
    slug='motorways-highways-pipeline-2026',
    category='infrastructure-projects',
    title='M-6 and the KKH realignment: Pakistan’s motorway pipeline and who is financing it',
    excerpt='The 306 km Hyderabad–Sukkur Motorway is moving to contract award with IsDB, OPEC Fund and PPP finance, and Pakistan and China have agreed an 85:15 split for the KKH realignment. Where things stand.',
    tags=['Motorways', 'M-6', 'CPEC'],
    sources=[SRC_M6_SEP, SRC_M6_MAY, SRC_CPEC_SURVEY],
    body='''
<p>Two road projects show how Pakistan is now paying for large highways: by splitting them into sections and matching each section to a different lender or to private finance.</p>
<h2>M-6 Hyderabad–Sukkur: five sections, three financing routes</h2>
<p>The 306 km motorway is estimated at Rs359 billion, and ECNEC has approved a revised PC-I of Rs364 billion. Land acquisition for the main corridor is reported complete, and ProPakistani reported in September 2026 that construction was scheduled to begin in November 2026.</p>
<table>
<thead><tr><th>Section</th><th>Length / cost</th><th>Financing</th><th>Status (Sept 2026)</th></tr></thead>
<tbody>
<tr><td>I: Hyderabad–Tando Adam</td><td>57 km, Rs81bn</td><td>Public–private partnership</td><td>Tendering 6 Nov 2026; concession award targeted for 4 Feb 2027</td></tr>
<tr><td>II: Tando Adam–Nawabshah</td><td>63 km, Rs70bn</td><td>Public–private partnership</td><td>As section I</td></tr>
<tr><td>III: Nawabshah–Naushahro Feroze</td><td>65 km, Rs69bn</td><td>OPEC Fund, $230m</td><td>Bids received 2 Sept; award expected November</td></tr>
<tr><td>IV: Naushahro Feroze–Ranipur</td><td>61 km, about Rs68bn</td><td>Islamic Development Bank</td><td>Bids under evaluation</td></tr>
<tr><td>V: Ranipur–Sukkur</td><td>60 km, Rs71bn</td><td>Islamic Development Bank</td><td>Bid opening delayed by grievance process</td></tr>
</tbody>
</table>
<p>The IsDB approved $475 million for sections IV and V in September 2025 and signed the financing agreement on 19 January 2026. The Asian Development Bank is transaction adviser for the two PPP sections. According to APP in May 2026, the three northern sections are targeted for completion between March and May 2029.</p>
<h2>Karakoram Highway: rebuilding around the dams</h2>
<p>The Economic Survey 2025-26 (June 2026) says work on realigning the KKH between Thakot and Raikot is advancing under a government-to-government framework agreement with China. Phase II(A) moves 102 km of the road from Thor Nullah to Raikot, including 88 km of new alignment; Phase II(B) upgrades 139 km of the existing highway. The two countries agreed an 85:15 financing split, with the financing agreement still being finalised. The Survey also says MoUs are being advanced for the N-50 D.I. Khan–Zhob road, the M-10 and the Babusar Tunnel, with feasibility studies to follow.</p>
<h2>What this means for suppliers and partners</h2>
<ul>
<li>Lender-funded sections are usually procured through international bidding, which can be open to local and foreign contractors and their joint ventures.</li>
<li>Contract awards from late 2026 point to demand for asphalt plants, pavers, crushers and bridge steel in Sindh during 2027.</li>
<li>What to watch: section V’s bid delay and the PPP sections, which still depend on attracting a concessionaire.</li>
</ul>
<p>Read <a href="{{a:ppp-projects-pipeline-pakistan}}">how PPP projects work</a> and <a href="{{a:psdp-2026-27-infrastructure-spending}}">the PSDP road allocations</a>. Road contractors and equipment suppliers meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a>, Karachi Expo Centre, 18–20 March.</p>
'''))

ARTICLES.append(dict(
    slug='karachi-infrastructure-projects-2026',
    category='infrastructure-projects',
    title='K-IV and the Red Line BRT: where Karachi’s big projects stand in 2026',
    excerpt='Karachi’s K-IV water mainline is about 70% built with a December 2026 target, and the Red Line BRT is aiming for December 2027. Costs, progress and what is still needed.',
    tags=['Karachi', 'Water supply', 'Transport'],
    sources=[SRC_K4_NUKTA, SRC_K4_DAWN, SRC_REDLINE],
    body='''
<p>Two of Karachi’s biggest public projects, a bulk water line and a bus rapid transit corridor, have both run years behind schedule. Their progress matters to anyone building, supplying or operating in the city.</p>
<h2>K-IV: bulk water for the city</h2>
<p>K-IV, now implemented by WAPDA, is designed to bring <strong>260 million gallons a day</strong> (MGD) to the city in its first phase. Nukta reported in August 2026 that Karachi needs about 1,300 MGD and receives well below that.</p>
<ul>
<li><strong>Cost:</strong> the estimate rose from Rs120 billion to Rs170 billion, and the federal government will bear the full cost of the revised project (Dawn, February 2026).</li>
<li><strong>Who does what:</strong> WAPDA builds the bulk transmission and lines the canal; the Sindh government is responsible for the distribution network inside Karachi.</li>
<li><strong>Progress:</strong> officials put the mainline at about 70% complete in August 2026, with a December 2026 target set by the Planning Minister, Ahsan Iqbal.</li>
</ul>
<p>The catch, according to Nukta: the works that connect K-IV to the city’s existing network are behind schedule, so the mainline could be finished before water fully reaches consumers.</p>
<h2>Red Line BRT: University Road corridor</h2>
<p>The Red Line is a 23 km bus rapid transit corridor approved in 2019 and funded by the Asian Development Bank and other lenders. As of March 2026, Nukta reported:</p>
<table>
<thead><tr><th>Red Line BRT</th><th>Figure</th></tr></thead>
<tbody>
<tr><td>Progress</td><td>37% complete</td></tr>
<tr><td>Cost</td><td>Up from $503 million to $600 million</td></tr>
<tr><td>Completion target</td><td>December 2027 (originally June 2024)</td></tr>
<tr><td>Commuters affected daily on University Road</td><td>Over 350,000</td></tr>
</tbody>
</table>
<p>Reported problems include land disputes, payment issues, safety violations and administrative lapses.</p>
<h2>What these projects still need</h2>
<p>Our observations from the facts above:</p>
<ul>
<li>With K-IV’s mainline nearing completion, the next demand is in the city-side links: pipes, valves, pumping and storage tied into Karachi’s network.</li>
<li>The Red Line, at 37% in March 2026, still has most of its work ahead, which points to work for civil contractors, steel and concrete suppliers through 2027.</li>
<li>What to watch: both projects have slipped several times, and both have seen their costs rise.</li>
</ul>
<p>Karachi’s growth also depends on links out of the city, such as the <a href="{{a:motorways-highways-pipeline-2026}}">Hyderabad–Sukkur Motorway</a>. Build Asia 2027 takes place in Karachi itself, at the Expo Centre from 18 to 20 March; <a href="https://forms.ecgexhibitions.com/E-Pass/24/Karachi/web">register to visit</a> or read <a href="{{a:build-asia-2027-what-to-expect}}">what to expect</a>.</p>
'''))

ARTICLES.append(dict(
    slug='ppp-projects-pipeline-pakistan',
    category='infrastructure-projects',
    title='Public–private partnerships in Pakistan: the P3A, its projects and how firms take part',
    excerpt='The PPP Authority has moved under the Privatisation Division, and motorways are among the projects being prepared as PPPs. How the framework works, which projects are live and where private firms come in.',
    tags=['PPP', 'Infrastructure', 'Motorways'],
    sources=[SRC_P3A_SHIFT, SRC_M13, SRC_M6_SEP],
    body='''
<p>Alongside the PSDP and lender-funded schemes, some of Pakistan’s large projects are being prepared as public–private partnerships (PPPs): projects where a private company finances, builds and runs an asset under a concession.</p>
<h2>How the framework works</h2>
<p>The Public Private Partnership Authority (P3A) was set up under the PPP Authority Act, 2017. It provides the legal framework and helps federal agencies structure, develop and procure PPP projects. Key points, as reported by Profit in May 2026:</p>
<ul>
<li>Since a 2020 amendment, the P3A handles “Qualified” projects directly; other projects can go ahead with approval from the implementing agency’s principal accounting officer.</li>
<li>The P3A board approves projects that give the public sector value for money while staying financially viable for private investors.</li>
<li>Private firms may submit <strong>unsolicited proposals</strong> for projects the government has not identified but considers suitable for a PPP.</li>
<li>In May 2026, administrative control of the P3A moved from the Planning Division to the Privatisation Division, with the stated aim of widening private participation in infrastructure.</li>
</ul>
<h2>Projects in the pipeline</h2>
<table>
<thead><tr><th>Project</th><th>Size</th><th>Status (2026)</th></tr></thead>
<tbody>
<tr><td>Kharian–Rawalpindi Motorway (M-13)</td><td>117 km, Rs203.32bn (ECNEC, April 2026)</td><td>Build-operate-transfer; viability gap of over Rs40bn to be met by the federal government</td></tr>
<tr><td>M-6 sections I and II, Hyderabad–Nawabshah</td><td>120 km, Rs151bn combined</td><td>Tendering from 6 Nov 2026; concession award targeted for 4 Feb 2027; ADB as transaction adviser</td></tr>
</tbody>
</table>
<p>The M-13 cost has more than doubled from Rs96 billion in January 2022, after the road was upgraded from four to six lanes. ECNEC directed international competitive bidding, but in June 2026 Profit, citing Dawn, reported that the P3A board had backed, in principle, a negotiated award to the Frontier Works Organisation, subject to federal cabinet approval (not yet given as of that report).</p>
<h2>How private firms take part</h2>
<p>Our observations:</p>
<ul>
<li><strong>As concessionaires or sponsors</strong>, bidding for tendered PPP sections such as M-6 I and II, or bringing an unsolicited proposal.</li>
<li><strong>As subcontractors and suppliers</strong> to whoever wins, for materials, plant, tolling systems and maintenance.</li>
<li><strong>What to watch:</strong> the M-13 case shows that the procurement route for a PPP project can change after it has been approved.</li>
</ul>
<p>See <a href="{{a:motorways-highways-pipeline-2026}}">the motorway pipeline</a> for the lender-funded sections. Developers, contractors and suppliers meet at <a href="{{a:build-asia-2027-what-to-expect}}">Build Asia 2027</a> in Karachi, 18–20 March; the January 2026 edition drew over 350 exhibitors from over 21 countries. <a href="https://buildasia.net/contact/?enquiry=stand">Enquire about a stand</a>.</p>
'''))
