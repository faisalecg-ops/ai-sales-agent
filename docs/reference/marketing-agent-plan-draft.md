> **Owner note (8 Oct 2026): this draft is for discussion only. The owner does not agree with all of it. Do not build from it. The agreed direction is in docs/ecosystem-plan-discussion.md.**

# Build Asia International Marketing Intelligence & Distribution Agent
## Full Product & Implementation Plan
### Version 1.0 — October 2026

---

## 1. Executive Summary

The **Build Asia International Marketing Intelligence & Distribution Agent** is an AI-assisted marketing system designed specifically to promote the Build Asia Exhibition internationally.

Build Asia is a **Building & Construction Machinery, Building Materials, Construction Technology and Raw Materials Exhibition**.

The system should not be treated as a simple AI social-media post generator. Its primary purpose is to create a repeatable marketing intelligence and distribution process:

> **Discover → Research → Verify → Classify → Recommend → Generate → Approve → Publish/Submit → Track → Learn**

The system should continuously discover relevant international:

- Construction websites
- Construction machinery portals
- Building-material platforms
- B2B marketplaces
- Exhibition directories
- Industry publications
- Architecture platforms
- Engineering platforms
- Industry associations
- Chambers of commerce
- Country-specific portals
- Social-media opportunities
- Media partnership opportunities
- Article and press-release opportunities
- Potential exhibitor sources
- Competitor exhibition intelligence

It should then transform these discoveries into actionable marketing opportunities and content.

The system should also integrate with the existing **Build Asia AI Sales Agent** concept so that marketing research, company research and exhibitor recruitment can eventually share the same company/lead intelligence.

---

# 2. Primary Objectives

## 2.1 Marketing Objectives

The system should help Build Asia:

1. Increase international awareness.
2. Increase international visitor registrations.
3. Increase international exhibitor enquiries.
4. Identify manufacturers and suppliers that may exhibit.
5. Reach construction machinery buyers.
6. Reach contractors and developers.
7. Reach architects and engineers.
8. Reach distributors and importers.
9. Reach building-material manufacturers.
10. Build relationships with industry associations.
11. Build relationships with construction media.
12. Increase Build Asia's search-engine visibility.
13. Establish Build Asia as an industry information source rather than only an exhibition advertiser.
14. Maintain a continuous international marketing presence before, during and after the exhibition.

---

# 3. Core Principle

The system should not repeatedly publish:

> "Visit Build Asia."

Instead, it should create useful industry content and place Build Asia naturally inside that ecosystem.

Example:

### Industry topic

> Pakistan construction market opportunities for international machinery manufacturers

Then derive:

- LinkedIn article
- LinkedIn post
- Facebook post
- Instagram carousel
- Instagram Reel
- YouTube Short
- YouTube description
- X post
- Website article
- B2B marketplace description
- Newsletter item
- Media pitch

The exhibition becomes part of the commercial opportunity rather than the only subject of the content.

---

# 4. System Name

Recommended working name:

**Build Asia International Marketing Intelligence & Distribution Agent**

Short name:

**BA Marketing Agent**

Alternative internal module name:

**Build Asia Marketing Intelligence**

---

# 5. Target Audiences

The system should support separate campaigns for:

## 5.1 International Exhibitors

- Construction machinery manufacturers
- Heavy equipment manufacturers
- Building-material manufacturers
- Raw-material suppliers
- Construction technology companies
- Industrial suppliers
- Construction equipment dealers
- Exporters
- International brands
- Distributors

## 5.2 Trade Visitors

- Contractors
- Builders
- Developers
- Architects
- Civil engineers
- Structural engineers
- Procurement managers
- Project managers
- Importers
- Distributors
- Dealers
- Government/public-sector buyers
- Consultants
- Construction companies

## 5.3 Industry Influencers

- Associations
- Trade publications
- Journalists
- Industry analysts
- Construction influencers
- Architects
- Engineers
- Industry experts

---

# 6. Recommended Marketing Channels

## 6.1 Social Platforms

Priority:

1. LinkedIn
2. Facebook
3. Instagram
4. YouTube
5. TikTok
6. X
7. Threads

## 6.2 B2B Platforms

Potential platforms:

- Alibaba
- Made-in-China
- Global Sources
- IndiaMART
- TradeIndia
- EC21
- TradeKey
- Kompass
- Europages
- DirectIndustry

The system must verify current availability, requirements and suitability before recommending activity.

## 6.3 Machinery Platforms

Potential categories/platforms:

- Machineryline
- MachineryTrader
- Mascus
- Plant & Equipment
- Heavy Equipment Guide
- Equipment World
- Equipment Journal
- LECTURA
- Construction Equipment Guide
- Other relevant machinery portals

## 6.4 Exhibition Directories

Potential platforms:

- 10times
- EventsEye
- TradeFairDates
- ExpoTobi
- World Exhibitions
- ExpoDataBase
- AUMA
- Other relevant exhibition directories

## 6.5 Construction Media

Potential targets:

- Construction Week
- MEED
- Regional construction publications
- Machinery publications
- Building-material publications
- Architecture publications
- Infrastructure publications

## 6.6 Architecture / Design Platforms

Potential targets:

- ArchDaily
- Dezeen
- Architonic
- Architecture directories
- Building-product publications

## 6.7 Associations

Target:

- Construction associations
- Contractors' associations
- Architects' associations
- Engineers' associations
- Machinery associations
- Building-material associations
- Chambers of commerce
- Export associations
- Country-specific trade bodies

---

# 7. Important Platform Verification Rule

The AI must never assume that a platform:

- Accepts event listings
- Accepts articles
- Accepts press releases
- Offers advertising
- Allows automated posting
- Offers free listings
- Allows commercial promotion

These capabilities must be researched and verified.

Every platform record should have:

- Source URL
- Verification date
- Evidence/notes
- Verification status
- Contact/submission URL
- Last checked date

The AI must distinguish:

**Verified**

from

**Likely**

from

**Unknown**

---

# 8. System Architecture

Recommended architecture:

```text
                    BUILD ASIA MARKETING PLATFORM
                              │
                              ▼
                    ┌─────────────────────┐
                    │   Admin Dashboard   │
                    └──────────┬──────────┘
                               │
              ┌────────────────┼────────────────┐
              │                │                │
              ▼                ▼                ▼
      Platform Research   Content Engine   Campaign Engine
              │                │                │
              ▼                ▼                ▼
       Opportunity DB     Content DB      Campaign DB
              │                │                │
              └────────────────┼────────────────┘
                               │
                               ▼
                         AI Orchestrator
                               │
              ┌────────────────┼────────────────┐
              │                │                │
              ▼                ▼                ▼
          Web Research    LLM Analysis     Classification
              │                │                │
              └────────────────┼────────────────┘
                               │
                               ▼
                       Approval Workflow
                               │
                  ┌────────────┴────────────┐
                  ▼                         ▼
             Manual Post              Integration
                  │                         │
                  └────────────┬────────────┘
                               ▼
                       Performance Tracking
```

---

# 9. Recommended Technical Stack

The system should remain compatible with the user's existing development approach.

## Backend

- PHP 8.x
- MySQL / MariaDB
- MySQLi
- cPanel-compatible
- No Composer requirement
- No PHP framework requirement for initial version

## Frontend

- HTML5
- CSS3
- Bootstrap 5
- JavaScript
- AJAX/fetch
- Responsive admin interface

## AI

Use an LLM API through an abstraction layer.

The application should not hard-code business logic into the LLM.

Recommended separation:

```text
PHP:
Business rules
Security
Database
Workflow
Approval
Scheduling
Status
Validation

LLM:
Research interpretation
Classification
Summarization
Content generation
Recommendation
Reasoning
```

## Web Research

Use a controlled web-search/research layer.

The system should store:

- Search query
- Search date
- Source URL
- Source title
- Relevant extracted information
- Verification status

---

# 10. Multi-Agent Design

The system should eventually contain several specialized agents.

## Agent 1 — Platform Discovery Agent

Purpose:

Find new marketing/distribution platforms.

Search for:

- Construction websites
- Machinery portals
- B2B websites
- Exhibition directories
- Media
- Associations
- Country-specific websites

Output:

`marketing_platforms`

---

## Agent 2 — Platform Verification Agent

Purpose:

Check whether a platform actually supports a proposed activity.

Example:

```text
Platform:
Example Construction Portal

Questions:
Does it accept exhibition listings?
Does it accept articles?
Does it accept press releases?
Does it accept advertising?
Is registration required?
Is payment required?
Is international content accepted?
Who should be contacted?
```

Output:

Verified platform capabilities.

---

## Agent 3 — Marketing Opportunity Agent

Purpose:

Turn platforms into actionable opportunities.

Example:

```text
Platform:
Construction Publication

Opportunity:
Guest article

Recommended topic:
Pakistan construction market opportunities

Target:
International machinery manufacturers

Priority:
High

Action:
Contact editorial team
```

---

## Agent 4 — Content Strategy Agent

Purpose:

Determine what Build Asia should communicate.

Content categories:

- Industry news
- Market opportunities
- Machinery
- Building materials
- Construction technology
- Pakistan market
- International participation
- Exhibitor spotlight
- Visitor spotlight
- Country spotlight
- Product spotlight
- Project/infrastructure content
- Event announcements
- Educational content

---

## Agent 5 — Content Generation Agent

Creates platform-specific content.

One source can produce multiple outputs.

---

## Agent 6 — Campaign Agent

Creates campaigns by:

- Country
- Sector
- Audience
- Platform
- Objective
- Time period

Example:

```text
Campaign:
China Construction Machinery Recruitment

Audience:
Chinese construction machinery manufacturers

Objective:
Exhibitor recruitment

Duration:
30 days
```

---

## Agent 7 — Company Research Agent

Researches potential exhibitors.

For each company:

- Company
- Country
- Website
- Products
- Markets
- Export activity
- Existing international exhibitions
- Distributor network
- Relevant decision-maker
- LinkedIn
- Commercial fit
- Potential participation type

This agent should be shared with the Build Asia sales system where practical.

---

## Agent 8 — Competitor Exhibition Intelligence Agent

Monitor relevant exhibitions.

Examples:

- bauma
- bauma CHINA
- bauma CONEXPO INDIA
- Big 5
- Big 5 Construct Saudi
- Saudi Build
- Project Qatar
- Build4Asia
- Philconstruct
- Korea Build
- other relevant events

Research:

- Exhibitors
- Countries
- Product categories
- Media activity
- Content themes
- Potential Build Asia prospects

Do not copy competitor content.

---

## Agent 9 — Approval Agent / Workflow

This is not an autonomous AI decision-maker.

It controls the human approval process.

Statuses:

```text
Draft
AI Recommended
Awaiting Review
Approved
Rejected
Scheduled
Published
Submitted
Failed
Archived
```

---

# 11. Platform Database

Recommended table:

```text
marketing_platforms

id
name
domain
website_url
country
region
platform_type
industry
audience
description

free_or_paid
listing_available
article_available
press_release_available
advertising_available
social_promotion_available
newsletter_available
partnership_available

submission_url
advertising_url
contact_url

relevance_score
priority
verification_status
verification_notes
source_url

last_verified_at
created_at
updated_at
```

---

# 12. Platform Types

Use controlled values:

```text
social
b2b_marketplace
machinery_portal
construction_portal
industry_media
architecture
engineering
exhibition_directory
association
chamber
trade_publication
news
business_directory
country_portal
forum
newsletter
video
other
```

---

# 13. Platform Relevance Scoring

Use a transparent scoring model.

Example:

```text
Industry relevance       0–25
Target audience          0–25
International reach     0–20
Build Asia fit           0–15
Promotion capability     0–10
Credibility               0–5

Total                    100
```

Priority:

```text
80–100 = A / High
60–79  = B / Good
40–59  = C / Moderate
0–39   = D / Low
```

The score must be explainable.

---

# 14. Marketing Opportunities Database

```text
marketing_opportunities

id
platform_id

opportunity_type
title
description

target_country
target_sector
target_audience

recommended_action
submission_method

requirements
cost_information
deadline

source_url
contact_url

priority
confidence

status

discovered_at
verified_at
created_at
updated_at
```

Opportunity types:

```text
event_listing
article
guest_post
press_release
banner
newsletter
social_post
social_group
video
partnership
association_outreach
directory_listing
sponsorship
advertising
company_research
other
```

---

# 15. Content Database

```text
marketing_content

id
campaign_id
platform_id

content_type
title
content
summary

media_reference
source_reference

language
target_country
target_audience

status
approval_status

scheduled_at
published_at

created_at
updated_at
```

---

# 16. Campaign Database

```text
marketing_campaigns

id
name
description

objective
target_country
target_region
target_sector
target_audience

start_date
end_date

budget
status

created_at
updated_at
```

---

# 17. Company Intelligence Database

This can eventually connect to the Build Asia Sales Agent.

```text
marketing_companies

id
company_name
website
country
city

industry
sector
product_categories

export_markets
international_activity

linkedin_url
contact_url

decision_maker
decision_maker_title

company_size
fit_score
participation_recommendation

source_url
research_status
last_researched_at

created_at
updated_at
```

---

# 18. Content Strategy

The system should maintain reusable content pillars.

## Pillar 1 — Market Intelligence

Examples:

- Pakistan construction market
- Infrastructure development
- Construction investment
- Import opportunities
- Regional construction growth

## Pillar 2 — Machinery

Examples:

- Excavators
- Cranes
- Concrete machinery
- Asphalt machinery
- Block-making equipment
- Road machinery
- Material-handling equipment

## Pillar 3 — Building Materials

Examples:

- Cement
- Steel
- Aluminium
- Glass
- Tiles
- Sanitary ware
- Pipes
- Roofing
- Flooring
- Paint
- Construction chemicals

## Pillar 4 — Technology

Examples:

- Smart construction
- BIM
- Automation
- AI
- Digital construction
- Energy-efficient construction

## Pillar 5 — Business Opportunities

Examples:

- Distributor opportunities
- Import opportunities
- International expansion
- Market entry into Pakistan
- Supplier/buyer connections

## Pillar 6 — Build Asia

Examples:

- Exhibitor announcements
- Country participation
- Product launches
- Visitor registration
- Conferences
- Buyer programs
- Event updates

---

# 19. Weekly Content Framework

Recommended default:

### Monday
Market intelligence

### Tuesday
Machinery / technology

### Wednesday
Exhibitor spotlight

### Thursday
Building-material/product spotlight

### Friday
International country spotlight

### Saturday
Video / machinery demonstration

### Sunday
Build Asia event/community update

This should be configurable.

---

# 20. Daily Content Engine

The agent should not generate content simply because a schedule says "post."

It should first evaluate:

```text
Is there a relevant industry topic?
Is there new Build Asia information?
Is there a suitable company/product?
Is there a useful market insight?
Is there a suitable video?
Is there an upcoming deadline?
Is there an international opportunity?
```

If no meaningful content exists, it should recommend skipping the post rather than generating low-quality filler.

---

# 21. Platform-Specific Content Rules

## LinkedIn

Focus:

- B2B
- Decision-makers
- Market intelligence
- Exhibitor recruitment
- Professional articles
- Industry analysis

## Facebook

Focus:

- Event awareness
- Visitors
- Exhibitors
- Videos
- Photos
- General industry audience

## Instagram

Focus:

- Visual products
- Machinery
- Reels
- Exhibitor visuals
- Country participation

## YouTube

Focus:

- Interviews
- Product demonstrations
- Machinery
- Industry discussions
- Event videos
- Shorts

## TikTok

Focus:

- Machinery
- Technology
- Short educational content
- Visual demonstrations

## X

Focus:

- News
- Short industry commentary
- Event announcements
- Links

---

# 22. Content Reuse Engine

One approved source item should be transformed into:

```text
Master Topic
    │
    ├── LinkedIn
    ├── Facebook
    ├── Instagram
    ├── YouTube
    ├── TikTok
    ├── X
    ├── Website
    ├── Newsletter
    ├── B2B listing
    └── Media pitch
```

However, each version should be adapted to the platform instead of being copied word-for-word.

---

# 23. Human Approval System

The default mode should be:

**AI recommends → Human approves → System publishes/submits**

The system should never automatically send external commercial communication without explicit permission/configuration.

Approval interface:

```text
------------------------------------------------
Marketing Opportunity
------------------------------------------------
Platform: Construction Portal
Country: UAE

Opportunity:
Guest Article

AI Recommendation:
HIGH PRIORITY

Reason:
Large construction-professional audience
and strong regional relevance.

Suggested Topic:
Pakistan construction market opportunities
for international suppliers.

[Approve] [Reject] [Edit]
------------------------------------------------
```

---

# 24. Automation Levels

## Level 1 — Research Only

AI researches.

Human does everything else.

## Level 2 — Research + Draft

AI researches and prepares content.

Human publishes.

## Level 3 — Research + Approval + Scheduling

AI researches and drafts.

Human approves.

System schedules.

## Level 4 — Controlled Automation

Only approved integrations/platforms are automated.

## Level 5 — Continuous Intelligence

System continuously monitors:

- Platforms
- Industry news
- Competitor exhibitions
- New opportunities
- Campaign performance

---

# 25. Never Automatically Perform These Actions Without Approval

The system should not automatically:

- Spam websites
- Mass-submit forms
- Create fake accounts
- Send bulk unsolicited messages
- Post duplicate content repeatedly
- Scrape restricted/private information
- Circumvent platform restrictions
- Publish unverified claims
- Invent company information
- Invent contacts
- Invent advertising prices
- Invent platform capabilities

---

# 26. Competitor Intelligence Workflow

```text
Find exhibition
      ↓
Verify official website
      ↓
Find exhibitor information
      ↓
Identify companies
      ↓
Categorize products
      ↓
Research company
      ↓
Score Build Asia fit
      ↓
Add to prospect database
```

Potential output:

```text
Company:
ABC Machinery

Country:
China

Products:
Construction machinery

International activity:
High

Relevant to Build Asia:
Very High

Reason:
Already participates in international
construction exhibitions.

Suggested action:
Exhibitor recruitment research
```

---

# 27. Company Research Rules

When researching a potential exhibitor:

1. Start with the official website.
2. Verify company identity.
3. Identify products.
4. Identify target markets.
5. Check international activity.
6. Check existing exhibitions.
7. Identify relevant decision-maker where publicly available.
8. Determine commercial fit.
9. Recommend standard space / larger participation / sponsorship only where evidence supports it.
10. Store sources.

The AI must clearly distinguish:

**Fact**

from

**Inference**

from

**Recommendation**.

---

# 28. International Country Campaigns

The system should support campaigns such as:

```text
China
India
Turkey
UAE
Saudi Arabia
Qatar
Oman
Bahrain
Kuwait
Germany
Italy
Spain
South Korea
Malaysia
Indonesia
Australia
Other
```

Each campaign can contain:

- Platforms
- Associations
- Media
- Companies
- Distributors
- Manufacturers
- Content
- Outreach opportunities
- Performance

---

# 29. Example China Campaign

```text
Campaign:
Build Asia China 2027

Objective:
Recruit construction machinery manufacturers

Research:
Chinese machinery manufacturers

Platforms:
Made-in-China
Alibaba
Global Sources
Chinese industry media
Chinese machinery associations

Content:
Why Pakistan is an opportunity for
Chinese construction machinery companies

Company Research:
Identify manufacturers with export activity.

Output:
Qualified exhibitor prospects.
```

---

# 30. Example Middle East Campaign

```text
Campaign:
Build Asia Middle East

Countries:
UAE
Saudi Arabia
Qatar
Oman
Bahrain
Kuwait

Target:
Construction suppliers
Machinery companies
Developers
Contractors
Distributors

Channels:
LinkedIn
Construction media
MEED
Construction Week
Industry associations
Chambers
Regional directories
```

---

# 31. Media Partnership Module

The system should identify:

- Construction magazines
- Machinery magazines
- Engineering publications
- Architecture publications
- Industry websites
- Newsletters
- Podcasts
- YouTube channels
- Industry associations

For each:

```text
Media outlet
Audience
Country
Monthly reach if verified
Editorial contact
Advertising contact
Submission rules
Media kit
Partnership opportunity
Cost
Status
```

Possible partnership types:

- Media partner
- Editorial article
- Event listing
- Newsletter
- Banner
- Interview
- Video
- Social promotion
- Post-show coverage

---

# 32. Association Partnership Module

Research:

- National associations
- International associations
- Machinery associations
- Contractors' associations
- Architects' associations
- Engineering organizations
- Chambers of commerce

Recommended partnership:

> Build Asia Industry Partner

Possible benefits:

- Event listing
- Member newsletter
- Member invitation
- Association delegation
- Speaking opportunity
- Media coverage
- Group visitor program

---

# 33. Daily Intelligence Report

The system should generate a daily dashboard/report:

```text
BUILD ASIA MARKETING INTELLIGENCE
08 October 2026

New platforms:
7

New opportunities:
12

High-priority opportunities:
4

New potential exhibitors:
23

New media opportunities:
3

New association opportunities:
2

Content recommendations:
6

Competitor updates:
5

Pending approvals:
8

Published:
11

Failed:
1
```

---

# 34. Weekly Management Report

Management should receive:

```text
BUILD ASIA WEEKLY MARKETING REPORT

Research
---------
Platforms discovered: 42
Platforms verified: 31
Opportunities identified: 56

Content
-------
Posts prepared: 34
Approved: 27
Published: 23

Business Development
--------------------
Companies researched: 118
High-fit prospects: 37
Exhibitor opportunities: 19

Partnership
-----------
Media contacts: 12
Associations: 8

Performance
-----------
LinkedIn reach
Website traffic
Registration clicks
Enquiries
Leads
```

---

# 35. Analytics

Track wherever technically possible:

- Impressions
- Reach
- Engagement
- Clicks
- Website visits
- Registration clicks
- Lead enquiries
- Exhibitor enquiries
- Platform referral traffic
- Conversion rate

Do not pretend to have metrics that the platform does not expose.

---

# 36. UTM Tracking

Every campaign link should use controlled UTM parameters.

Example structure:

```text
utm_source=linkedin
utm_medium=social
utm_campaign=build_asia_china
utm_content=machinery_post_01
```

For external platforms:

```text
utm_source=machineryline
utm_medium=directory
utm_campaign=build_asia_2027
utm_content=listing
```

This allows actual commercial results to be measured.

---

# 37. Content Quality Controls

Before approval, AI should check:

### Accuracy

Are dates, venue, website and event facts correct?

### Relevance

Does the content relate to the intended audience?

### Duplication

Is it too similar to recent content?

### Tone

Is it professional and B2B appropriate?

### Commercial intent

Is the CTA appropriate?

### Claims

Are unsupported claims removed?

### Links

Are links valid and relevant?

---

# 38. Build Asia Knowledge Base

The agent should have access to an approved knowledge base containing:

- Exhibition description
- Official event dates
- Venue
- Exhibitor categories
- Visitor categories
- Product categories
- Official website
- Registration URLs
- Contact information
- Country participation
- Previous edition statistics
- Approved marketing language
- Brand guidelines
- Logos
- Approved images
- Sponsorship packages
- Stall packages
- FAQs

The LLM must use this knowledge instead of guessing.

---

# 39. Source Hierarchy

When facts conflict, prioritize:

1. Official Build Asia information
2. Official company/organization website
3. Official association
4. Trusted industry publication
5. Established directory
6. Secondary source
7. Search-result snippet

The agent should not treat a search-result snippet as definitive evidence when the official source is available.

---

# 40. Security

The system should include:

- Admin authentication
- Role-based permissions
- CSRF protection
- Prepared SQL statements
- Input validation
- Output escaping
- API key protection
- Audit logs
- Rate limits
- Research request limits
- Secure file handling
- No exposed credentials

Recommended roles:

```text
Super Admin
Marketing Manager
Marketing Editor
Researcher
Content Approver
Viewer
```

---

# 41. Audit Log

Track:

```text
User
Action
Record
Old value
New value
Timestamp
IP
Source
AI/manual
```

Examples:

- Platform approved
- Platform rejected
- Content edited
- Content approved
- Campaign created
- Company researched
- Opportunity closed

---

# 42. AI Prompt Architecture

Do not create one giant prompt.

Use specialized prompts.

Example:

```text
platform_discovery_prompt
platform_verification_prompt
opportunity_analysis_prompt
content_strategy_prompt
linkedin_content_prompt
facebook_content_prompt
instagram_content_prompt
youtube_content_prompt
company_research_prompt
competitor_research_prompt
media_partnership_prompt
association_research_prompt
quality_check_prompt
```

This makes the system easier to maintain.

---

# 43. AI Output Format

Where structured output is required, force JSON.

Example:

```json
{
  "platform_name": "...",
  "platform_type": "industry_media",
  "country": "...",
  "relevance_score": 87,
  "priority": "A",
  "opportunity_type": "article",
  "verification_status": "verified",
  "reason": "...",
  "source_url": "..."
}
```

PHP validates the JSON before storing it.

The AI must never directly write arbitrary SQL.

---

# 44. Scheduling

Recommended scheduled jobs:

### Daily

- Discover new platforms
- Search industry news
- Find opportunities
- Research competitor updates
- Generate recommendations

### Weekly

- Refresh priority platforms
- Research new companies
- Generate content calendar
- Produce management report

### Monthly

- Re-score platforms
- Review campaign performance
- Review inactive opportunities
- Discover new country-specific channels

---

# 45. Cron Architecture

Example:

```text
cron/platform_discovery.php
cron/platform_verification.php
cron/news_monitor.php
cron/opportunity_scan.php
cron/company_research.php
cron/content_generation.php
cron/daily_report.php
cron/weekly_report.php
```

Each job should have:

- Lock
- Timeout
- Error handling
- Logging
- Retry policy
- Maximum records per run

---

# 46. Admin Dashboard

Recommended main navigation:

```text
Dashboard

Research
 ├── Platform Discovery
 ├── Platform Database
 ├── Opportunities
 ├── Companies
 ├── Media
 ├── Associations
 └── Competitor Exhibitions

Marketing
 ├── Campaigns
 ├── Content Calendar
 ├── Content Library
 ├── Approval Queue
 └── Published Content

Analytics
 ├── Platform Performance
 ├── Campaign Performance
 ├── Content Performance
 ├── Leads
 └── Conversion

Reports
 ├── Daily
 ├── Weekly
 └── Management

Settings
 ├── Build Asia Knowledge
 ├── AI Settings
 ├── Users
 ├── Roles
 ├── Integrations
 └── Audit Log
```

---

# 47. Dashboard Cards

Top dashboard:

```text
Active Campaigns
Platforms
High-Priority Opportunities
Potential Exhibitors
Pending Approvals
Scheduled Content
Published Content
Media Partnerships
Association Partnerships
```

---

# 48. Opportunity Kanban

Useful workflow:

```text
DISCOVERED
     ↓
RESEARCHING
     ↓
VERIFIED
     ↓
RECOMMENDED
     ↓
APPROVAL
     ↓
ACTION
     ↓
COMPLETED
```

Alternative statuses:

```text
New
Researching
Verified
High Priority
Awaiting Approval
Approved
In Progress
Submitted
Published
Rejected
Expired
Archived
```

---

# 49. Content Calendar

Calendar view should show:

```text
Date
Platform
Campaign
Content type
Target audience
Status
Approval
Scheduled time
```

Color/status can be represented through normal UI styling, but the system should not depend on color alone.

---

# 50. Human-in-the-Loop Philosophy

The AI is an assistant, not an uncontrolled marketing employee.

The system should make it easy for a human to:

- Approve
- Edit
- Reject
- Reschedule
- Change target
- Change platform
- Change campaign
- Add notes
- Mark an opportunity as completed

---

# 51. Integration With Build Asia Sales Agent

This is an important long-term objective.

Shared entities:

```text
Companies
Contacts
Countries
Industries
Products
Campaigns
Research Sources
Leads
Interactions
```

Marketing Agent:

> Finds company

↓

Company Research:

> Determines commercial fit

↓

Sales Agent:

> Determines exhibitor opportunity

↓

Sales CRM:

> Tracks follow-up

Example:

```text
Marketing Agent
      ↓
ABC Machinery discovered
      ↓
Company Research
      ↓
High Build Asia fit
      ↓
Sales Agent
      ↓
Exhibitor lead
      ↓
Sales follow-up
```

---

# 52. Visitor Marketing Integration

The same system can generate visitor campaigns.

Example:

```text
Target:
Construction contractors in UAE

Content:
Why Pakistani construction suppliers
should attend Build Asia

CTA:
Register as Trade Visitor
```

Separate tracking should be maintained for:

- Visitor acquisition
- Exhibitor acquisition

---

# 53. Exhibitor Marketing Integration

Example:

```text
Target:
Chinese machinery manufacturers

Content:
Pakistan market opportunity

CTA:
Exhibit at Build Asia
```

The system should not use visitor-oriented content for exhibitor recruitment.

---

# 54. Marketing Funnel

## Exhibitor Funnel

```text
Industry Content
      ↓
Awareness
      ↓
Market Opportunity
      ↓
Build Asia
      ↓
Exhibitor Information
      ↓
Enquiry
      ↓
Sales Contact
      ↓
Proposal
      ↓
Booking
```

## Visitor Funnel

```text
Industry Content
      ↓
Awareness
      ↓
Event Value
      ↓
Build Asia
      ↓
Visitor Registration
      ↓
Registration
      ↓
Attendance
```

---

# 55. SEO Module

The system should also generate website content around relevant search topics.

Examples:

- Construction machinery exhibition Pakistan
- Building materials exhibition Pakistan
- Construction equipment Pakistan
- Construction machinery suppliers Pakistan
- Building materials suppliers Pakistan
- Construction technology exhibition
- International construction exhibition Pakistan
- Construction machinery trade show
- Building materials trade show Asia

The AI must research actual search intent before generating SEO content.

---

# 56. International SEO

Create country/sector landing pages where appropriate.

Examples:

```text
/build-asia-china
/build-asia-india
/build-asia-turkey
/build-asia-uae
/build-asia-saudi-arabia

/construction-machinery
/building-materials
/construction-technology
/raw-materials
```

Content should be genuinely useful and not doorway-page spam.

---

# 57. Email Newsletter Integration

Potential recurring newsletter:

**Build Asia Construction Industry Update**

Sections:

1. Market News
2. Machinery
3. Building Materials
4. Technology
5. International Opportunities
6. Featured Company
7. Build Asia Update

The system can generate a draft, but email sending should remain subject to existing consent and campaign rules.

---

# 58. Notification System

Notify the marketing team when:

### High-value platform discovered

```text
New High-Priority Marketing Platform

Country: Germany
Sector: Construction Machinery
Opportunity: Industry newsletter
Score: 91
```

### High-value company discovered

```text
Potential Exhibitor

Company: ABC Machinery
Country: China
Fit: 94/100
Reason: International construction machinery exporter
```

### High-value media opportunity

```text
Media Partnership Opportunity

Publication: XYZ Construction Media
Region: Middle East
Audience: Construction professionals
```

---

# 59. AI Confidence

Every important AI recommendation should include:

```text
Confidence:
High
Medium
Low
```

And ideally:

```text
Evidence:
Official website
Industry directory
Association source
Company website
```

This prevents management from treating AI guesses as facts.

---

# 60. Cost Control

AI and web research can become expensive.

Use:

### Cheap/fast stage

- Search
- URL filtering
- Basic classification

### Expensive stage

Only for shortlisted opportunities:

- Deep research
- Company analysis
- Decision-maker research
- Content generation

Do not perform deep AI analysis on every discovered website.

---

# 61. Deduplication

The system must prevent duplicate:

- Platforms
- Companies
- URLs
- Opportunities
- Articles
- Content topics

Normalize domains:

```text
https://www.example.com/
https://example.com
http://example.com
```

should normally resolve to the same platform.

---

# 62. Research Refresh Rules

Suggested:

### A platforms

Refresh every 30–60 days.

### B platforms

Refresh every 90 days.

### C platforms

Refresh every 180 days.

Immediately refresh if:

- Website changed
- Contact bounced
- Submission failed
- User reports incorrect information
- Platform becomes inactive

---

# 63. MVP — Phase 1

Build the smallest useful version first.

## MVP features

1. Admin login
2. Platform database
3. Platform discovery
4. Web research
5. Platform classification
6. Relevance scoring
7. Opportunity database
8. Campaign database
9. AI content generation
10. Approval workflow
11. Content calendar
12. Basic reporting
13. Audit log

Do not initially attempt complete automatic publishing to every platform.

---

# 64. Phase 2

Add:

- Company research
- Competitor exhibition intelligence
- Media database
- Association database
- Country campaigns
- UTM generation
- Performance tracking
- Daily intelligence reports
- Weekly management reports

---

# 65. Phase 3

Add integrations where officially supported:

- LinkedIn
- Facebook/Meta
- Instagram
- YouTube
- Approved CMS
- Email system
- Analytics

Only use official APIs/integrations and comply with platform rules.

---

# 66. Phase 4

Integrate with the Build Asia Sales Agent.

Shared:

- Company database
- Lead database
- Research
- Campaigns
- Contacts
- Industry classification

---

# 67. Phase 5

Advanced intelligence:

- Opportunity prediction
- Campaign performance recommendations
- Automated content calendar optimization
- International market scoring
- Exhibitor propensity scoring
- Media partnership scoring
- Country opportunity scoring

---

# 68. Recommended Initial Build Order

```text
1. Build Asia Knowledge Base
          ↓
2. Database
          ↓
3. Admin authentication
          ↓
4. Platform management
          ↓
5. Research engine
          ↓
6. Platform discovery agent
          ↓
7. Verification agent
          ↓
8. Opportunity engine
          ↓
9. Campaign manager
          ↓
10. Content engine
          ↓
11. Approval workflow
          ↓
12. Calendar
          ↓
13. Reports
          ↓
14. Company research
          ↓
15. Competitor intelligence
          ↓
16. Sales Agent integration
          ↓
17. Publishing integrations
```

---

# 69. Example End-to-End Workflow

Suppose the system discovers:

> XYZ Construction Machinery Association

The workflow is:

```text
Search
 ↓
Discover website
 ↓
Identify organization
 ↓
Verify official website
 ↓
Classify:
Association
 ↓
Determine audience:
Machinery manufacturers
 ↓
Calculate relevance:
92/100
 ↓
Identify opportunity:
Association partnership
 ↓
Research:
Membership + newsletter + event calendar
 ↓
Recommend:
Build Asia event listing + member promotion
 ↓
Generate:
Partnership proposal
 ↓
Human approval
 ↓
Contact
 ↓
Record outcome
 ↓
Follow-up reminder
```

---

# 70. Example Content Workflow

Input:

> Build Asia wants to promote international construction machinery participation.

System:

```text
Research audience
       ↓
Determine message
       ↓
Create master content
       ↓
Create LinkedIn version
       ↓
Create Facebook version
       ↓
Create Instagram version
       ↓
Create YouTube Short script
       ↓
Create X version
       ↓
Quality check
       ↓
Human approval
       ↓
Schedule
```

---

# 71. Example Daily Workflow

```text
02:00
Platform discovery

03:00
Industry/news research

04:00
Opportunity classification

05:00
Potential company research

06:00
Content recommendations

09:00
Marketing manager opens dashboard

09:15
Reviews opportunities

09:30
Approves content

10:00+
Publishing/scheduling
```

Actual times should be configurable according to server timezone and workload.

---

# 72. What the AI Should NOT Do

The agent should not:

- Invent websites.
- Invent contact persons.
- Invent company information.
- Invent advertising prices.
- Claim a website accepts submissions without evidence.
- Claim an article was published when it was not.
- Automatically spam directories.
- Automatically create hundreds of social accounts.
- Copy competitor content.
- Generate fake engagement.
- Generate fake reviews.
- Send unsolicited mass messages without appropriate consent/compliance.
- Bypass CAPTCHAs or access restrictions.
- Circumvent website terms or technical protections.

---

# 73. Quality Standard

Every important recommendation should answer:

### What?

What opportunity was found?

### Where?

Which platform?

### Why?

Why is it relevant to Build Asia?

### Who?

Who is the audience?

### How?

How should Build Asia use it?

### Cost?

Free / paid / unknown.

### Evidence?

Where did the information come from?

### Next action?

What should the marketing team do?

---

# 74. Management Decision View

Management should not have to read AI research.

The dashboard should summarize:

```text
TOP 10 MARKETING OPPORTUNITIES

1. China Machinery Association
   Score: 94
   Action: Partnership

2. Middle East Construction Publication
   Score: 91
   Action: Editorial + advertising

3. International Machinery Directory
   Score: 89
   Action: Exhibition listing

...
```

---

# 75. Success Metrics

The project should be judged by business outcomes rather than number of AI-generated posts.

Primary KPIs:

- International exhibitor enquiries
- International exhibitor leads
- Exhibitor bookings
- International visitor registrations
- Website traffic
- Qualified referral traffic
- Media partnerships
- Association partnerships
- Qualified companies discovered
- Marketing opportunities executed

Secondary KPIs:

- Reach
- Engagement
- Followers
- Video views
- Click-through rate

---

# 76. Long-Term Vision

The final system should evolve from:

> **AI content generator**

into:

> **AI-powered international marketing intelligence platform for Build Asia**

The system continuously learns:

```text
Which countries produce leads?
Which platforms produce traffic?
Which platforms produce exhibitors?
Which content produces enquiries?
Which industries respond?
Which companies exhibit internationally?
Which media partners perform?
Which campaigns produce registrations?
```

Then management can make decisions based on actual results.

---

# 77. Recommended Final Architecture

```text
                         BUILD ASIA AI PLATFORM
                                  │
          ┌───────────────────────┼───────────────────────┐
          │                       │                       │
          ▼                       ▼                       ▼
   MARKETING AGENT          SALES AGENT            KNOWLEDGE BASE
          │                       │                       │
          │                       │                       │
   ┌──────┼──────┐          ┌─────┼─────┐               │
   │      │      │          │     │     │               │
   ▼      ▼      ▼          ▼     ▼     ▼               ▼
Platforms Media Companies  Chatbot Leads Research   Approved Facts
   │      │      │          │     │     │
   └──────┼──────┘          └─────┼─────┘
          │                       │
          └───────────┬───────────┘
                      ▼
                 SHARED DATA
                      │
          ┌───────────┼───────────┐
          ▼           ▼           ▼
       Campaigns    Companies    Leads
          │           │           │
          └───────────┼───────────┘
                      ▼
                 MANAGEMENT
                   DASHBOARD
```

---

# 78. Final Recommendation

Do not attempt to build the entire autonomous system immediately.

Start with a controlled **Marketing Intelligence MVP**:

```text
Discover platforms
        ↓
Verify platforms
        ↓
Score platforms
        ↓
Find opportunities
        ↓
Create campaigns
        ↓
Generate content
        ↓
Human approval
        ↓
Track activity
```

Once this is stable, add:

```text
Company Research
        ↓
Exhibitor Prospecting
        ↓
Competitor Intelligence
        ↓
Media/Association Partnerships
        ↓
Sales Agent Integration
        ↓
Controlled Publishing
```

This approach gives Build Asia a practical system quickly while preserving a path toward a much more advanced AI marketing and sales platform.

---

# 79. Immediate Next Deliverables

The next implementation documents should be created in this order:

1. **Build Asia Marketing Agent — Functional Specification**
2. **Database Schema / SQL Specification**
3. **AI Agent & Prompt Specification**
4. **Web Research & Verification Rules**
5. **Platform Scoring Algorithm**
6. **Content Generation Rules**
7. **Campaign Management Specification**
8. **Admin Dashboard/UI Specification**
9. **API/Integration Specification**
10. **Security Specification**
11. **Cron/Automation Specification**
12. **Testing Plan**
13. **Deployment Plan for cPanel**
14. **Integration Plan with Build Asia Sales Agent**

The resulting system should remain modular so that additional agents, platforms, countries, campaigns and marketing channels can be added without redesigning the core application.
