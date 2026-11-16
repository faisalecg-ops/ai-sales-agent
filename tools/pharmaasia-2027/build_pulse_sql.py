#!/usr/bin/env python3
"""
Pharma Asia Pulse after the 24th edition. Writes pharmaasia-pulse-post-event.sql
for phpMyAdmin (the Pulse database, tables prefixed blog_).

Approved by the owner on 10 November 2026 (Claude Doc "Pharma Asia 2026
Post-Event Update Plan"). Authority for 2026 facts, highest first: Post-Event Fact
Sheet, Closing Day Press Release, Pre-Event Press Release.

  - Numbers strip: the 2026 results instead of 2025.
  - Event panel fallback, register and book-a-stand addresses: the 25th edition.
  - First slide: 2026 results instead of "register to visit".
  - A dated note on top of the three articles written before the show.
  - "Shell scheme or bare space?": the 2027 tariff and the new booking address.
  - Three new articles, as DRAFTS for the editor to check and publish.
Each change applies only where the text is still as it was on 10 November 2026.
"""
import json
import os

OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'pharmaasia-pulse-post-event.sql')
SITE = 'https://pharmaasia.com.pk'
PULSE = SITE + '/pulse'


def q(v):
    if v is None:
        return 'NULL'
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''").replace('\n', '\\n') + "'"


def j(v):
    return q(json.dumps(v, ensure_ascii=False, separators=(',', ':')))


sql = []
w = sql.append
w('-- Pharma Asia Pulse: after the 24th edition. Built by tools/pharmaasia-2027/build_pulse_sql.py.')
w('-- Import once, in phpMyAdmin, into the Pulse database.')
w('SET NAMES utf8mb4;')
w('')
w('-- Settings')
w("UPDATE blog_settings SET value = " + j([
    {'value': 24697, 'suffix': '', 'label': 'Visitors'},
    {'value': 378, 'suffix': '', 'label': 'Participating companies'},
    {'value': 25, 'suffix': '', 'label': 'Countries'},
    {'value': 821, 'suffix': '', 'label': 'Booths'},
]) + " WHERE name = 'stats' AND value LIKE '%25000%' AND value LIKE '%514%';")
w("UPDATE blog_settings SET value = " + j('{event} in numbers — the October 2026 Karachi edition') +
  " WHERE name = 'stats_caption' AND value LIKE '%December 2025%';")
w("UPDATE blog_settings SET value = " + j(SITE + '/book-a-stand/') + " WHERE name = 'book_stand_url' AND value LIKE '%booking.php%';")
w("UPDATE blog_settings SET value = " + j(SITE + '/register-to-visit/') + " WHERE name = 'register_url' AND value LIKE '%E-Pass/3/Karachi%';")
w("UPDATE blog_settings SET value = " + j('2027-12-07') + " WHERE name = 'event_start' AND value = " + j('2026-10-20') + ";")
w("UPDATE blog_settings SET value = " + j('2027-12-09') + " WHERE name = 'event_end' AND value = " + j('2026-10-22') + ";")
w("UPDATE blog_settings SET value = " + j('25th International Exhibition & Conferences, incorporating the International Pharma Convention') +
  " WHERE name = 'event_tagline' AND value LIKE '%24th International Exhibition%';")
w('')
w('-- First slide (it stopped showing on 22 October): the results of the show.')
w("UPDATE blog_slides SET headline = " + q('Pharma Asia 2026: 378 companies, 25 countries, 24,697 visitors') +
  ", text = " + q('Every hall of Karachi Expo Centre sold out. The 25th edition: 7–9 December 2027.') +
  ", button_label = 'See the results', button_url = " + q(SITE + '/karachi/2026/') + ", ends_at = NULL"
  " WHERE id = 1 AND button_label = 'Register to visit' AND headline LIKE 'Pharma Asia 2026%';")
w('')

RESULTS_LINK = SITE + '/karachi/2026/'
w('-- A dated note on top of the articles written before the show. Addresses and titles are unchanged.')
notes = {
    1: 'Update, November 2026: the 24th Pharma Asia took place from 20 to 22 October 2026. This article was written before the show. '
       'See the <a href="' + RESULTS_LINK + '">results of the 2026 edition</a>. The 25th edition is from 7 to 9 December 2027.',
    16: 'Update, November 2026: this was the visitor guide for the October 2026 show, which has taken place. '
        'The 25th edition is from 7 to 9 December 2027; registration details will be on the <a href="' + SITE + '/register-to-visit/">Register to visit</a> page.',
    24: 'Update, November 2026: this guide was written before the October 2026 show from the exhibitor list, which named 233 of the companies. '
        'In all, 378 companies from 25 countries took part. See the <a href="' + RESULTS_LINK + '">results of the 2026 edition</a>.',
}
for aid, note in notes.items():
    w("UPDATE blog_articles SET body = CONCAT(" + q('<p><em>' + note + '</em></p>\n') + ", body)"
      " WHERE id = %d AND body NOT LIKE '%%Update, November 2026%%';" % aid)
w('')
w('-- "Shell scheme or bare space?": the published 2027 rates and the new booking address.')
w("UPDATE blog_articles SET body = REPLACE(body, " + q('<p>Participation charges exclude government taxes. To check availability') + ", " +
  q('<p>For the 25th edition, 7 to 9 December 2027, the published rates are US$375 per sq m for shell scheme stalls (minimum 9 sq m), '
    'US$325 per sq m for bare space inside halls (minimum 18 sq m) and US$150 per sq m for outdoor exhibit space. 15% VAT is applicable on all rates: '
    'see the <a href="' + SITE + '/participation-tariff/">participation tariff</a>. To check availability') + ")"
  " WHERE id = 31 AND body NOT LIKE '%participation-tariff%';")
w("UPDATE blog_articles SET body = REPLACE(body, " + q(SITE + '/booking.php') + ", " + q(SITE + '/book-a-stand/') + ") WHERE body LIKE '%/booking.php%';")
w('')

SRC_FACT = '<li>Pharma Asia, Post-Event Fact Sheet 2026 (organiser’s document)</li>'
SRC_CLOSE = '<li>Pharma Asia, Closing Day Press Release, 22 October 2026 (organiser’s document)</li>'
SRC_PRE = '<li>Pharma Asia, Pre-Event Press Release, October 2026 (organiser’s document)</li>'
MINISTER = 'Syed Mustafa Kamal, Federal Minister for National Health Services, Regulations and Coordination'

articles = [
    dict(
        slug='pharma-asia-2026-closes-350-million-mous-business',
        title='24th Pharma Asia closes with USD 350 million in MoUs and business',
        excerpt='Three days at Karachi Expo Centre ended on 22 October 2026 with the Federal Health Minister as chief guest of the closing ceremony.',
        meta='The 24th Pharma Asia closed on 22 October 2026 at Karachi Expo Centre: USD 350 million in MoUs and business, 378 companies from 25 countries, 24,697 visitors.',
        sources=[SITE + '/karachi/2026/'],
        body=(
            '<p>The 24th Pharma Asia International Exhibition &amp; Conferences closed at the Karachi Expo Centre on <strong>22 October 2026</strong> after three days of trade, technology and investment meetings. MoUs signed and business conducted during the exhibition were valued at <strong>USD 350 million</strong>.</p>'
            '<h2>From inauguration to closing ceremony</h2>'
            '<p>The exhibition was inaugurated on 20 October by Mr. Adnan Hirani, Chairman of the Pakistan Pharmaceutical Manufacturers’ Association (PPMA). ' + MINISTER + ', attended the closing ceremony on 22 October as chief guest. He was joined by office bearers of PPMA, representatives of the Special Investment Facilitation Council (SIFC), foreign delegates and leading exhibitors.</p>'
            '<p>H.E. Dr. Bakheet Ateeq Al Remeithi, Consul General of the United Arab Emirates in Karachi, visited the exhibition, toured the halls and met exhibitors and delegates.</p>'
            '<h2>The show in figures</h2>'
            '<table><thead><tr><th>Measure</th><th>2026</th></tr></thead><tbody>'
            '<tr><td>Participating companies</td><td>378 (198 foreign, 180 local)</td></tr>'
            '<tr><td>Countries</td><td>25</td></tr>'
            '<tr><td>Booths</td><td>821</td></tr>'
            '<tr><td>Visitors</td><td>24,697</td></tr>'
            '<tr><td>MoUs and business</td><td>USD 350 million</td></tr>'
            '</tbody></table>'
            '<p>China led the international participation, with suppliers of APIs, formulations, packaging, machinery and cleanrooms. Visitors met exhibitors across the pharmaceutical value chain, from APIs and excipients to processing machinery, laboratory solutions and water treatment.</p>'
            '<h2>Organisers and partners</h2>'
            '<p>Pharma Asia is organised by Ecommerce Gateway Pakistan (Pvt) Ltd in collaboration with PPMA, with strategic support from SIFC. The organisers thanked the Federal Ministry of Health, PPMA, SIFC, exhibitors, foreign delegations and visitors.</p>'
            '<h2>Next edition</h2>'
            '<p>The 25th Pharma Asia will be held from 7 to 9 December 2027 at the Karachi Expo Centre. <a href="' + SITE + '/book-a-stand/">Stand enquiries are open</a>.</p>'
            '<h2>Sources</h2><ul>' + SRC_FACT + SRC_CLOSE + SRC_PRE + '</ul>'
        ),
    ),
    dict(
        slug='pharma-asia-2026-in-numbers',
        title='Pharma Asia 2026 in numbers: 821 booths, 378 companies, 25 countries, 24,697 visitors',
        excerpt='The organiser’s post-event fact sheet for the 24th edition, 20–22 October 2026, in one place.',
        meta='Pharma Asia 2026 results from the post-event fact sheet: 821 booths, 378 companies (198 foreign), 25 countries and 24,697 visitors at Karachi Expo Centre.',
        sources=[SITE + '/karachi/2026/'],
        body=(
            '<p>The 24th Pharma Asia International Exhibition &amp; Conferences was held from 20 to 22 October 2026 at the Karachi Expo Centre, open from 10 am to 7 pm each day. These are the figures in the organiser’s post-event fact sheet.</p>'
            '<h2>Exhibition</h2>'
            '<table><thead><tr><th>Measure</th><th>Total</th><th>Breakdown</th></tr></thead><tbody>'
            '<tr><td>Booths</td><td>821</td><td>123 shell scheme, 698 bare space</td></tr>'
            '<tr><td>Participating companies</td><td>378</td><td>198 foreign, 180 local</td></tr>'
            '<tr><td>Participating countries</td><td>25</td><td>See below</td></tr>'
            '<tr><td>Visitors</td><td>24,697</td><td>416 foreign, 24,281 local</td></tr>'
            '</tbody></table>'
            '<p>Every hall of the Karachi Expo Centre was taken: all six halls, with the foyer between Halls 5 and 6 opened as Hall 7.</p>'
            '<h2>Countries</h2>'
            '<p>Companies came from 25 countries. The major ones were China, Germany, the United States, Japan, Russia, Malaysia, Spain, Switzerland, Italy and Korea. Foreign companies made up 198 of the 378 participants.</p>'
            '<h2>Business and convention</h2>'
            '<p>MoUs signed and business conducted during the exhibition were valued at USD 350 million, according to the closing day press release.</p>'
            '<p>The 14th International Pharma Convention ran alongside the exhibition on 20 October. Its inaugural session was “Resilient Pharma Pakistan: Quality, Self-Reliance &amp; Sustainable Growth”. <a href="' + SITE + '/convention/2026/">Talks and videos from the convention</a> are on the Pharma Asia website.</p>'
            '<h2>Media</h2>'
            '<p>More than 70 print and electronic media representatives covered the show, including satellite channels and leading Urdu and English newspapers.</p>'
            '<h2>A note on the exhibitor directory</h2>'
            '<p>The <a href="' + SITE + '/karachi/2026/exhibitors/">online exhibitor directory</a> lists 233 companies with their hall and booth numbers. It was compiled before the show and does not include every one of the 378 participating companies.</p>'
            '<h2>Sources</h2><ul>' + SRC_FACT + SRC_CLOSE + '</ul>'
        ),
    ),
    dict(
        slug='pharma-asia-2027-dates-rates-how-to-book',
        title='Pharma Asia 2027: dates, rates and how to book',
        excerpt='The 25th edition is from 7 to 9 December 2027 at Karachi Expo Centre. The three ways to take part, the published rates and the booking steps.',
        meta='Pharma Asia 2027: 7–9 December 2027, Karachi Expo Centre. Shell scheme, bare space and outdoor rates per sq m, minimum sizes and how to book a stand.',
        sources=[SITE + '/participation-tariff/'],
        body=(
            '<p>The 25th Pharma Asia International Exhibition &amp; Conferences will be held from <strong>7 to 9 December 2027</strong> at the Karachi Expo Centre. Every hall was taken at the 2026 edition, and many exhibitors have already rebooked.</p>'
            '<h2>Three ways to take part</h2>'
            '<table><thead><tr><th>Option</th><th>Rate per sq m</th><th>Minimum</th><th>Best for</th></tr></thead><tbody>'
            '<tr><td>Shell scheme stall</td><td>US$375</td><td>9 sq m</td><td>A finished stand, minimal effort</td></tr>'
            '<tr><td>Bare space inside halls</td><td>US$325</td><td>18 sq m</td><td>Custom stands and machinery</td></tr>'
            '<tr><td>Outdoor exhibit space</td><td>US$150</td><td>Not stated</td><td>Large exhibits outside the halls</td></tr>'
            '</tbody></table>'
            '<p>15% VAT is applicable on all rates. The rates are those published on the <a href="' + SITE + '/participation-tariff/">participation tariff</a> page in November 2026.</p>'
            '<h2>What a first stand costs</h2>'
            '<p>A 9 sq m shell scheme stall is US$3,375, plus US$506.25 VAT, a total of US$3,881.25. The smallest bare space, 18 sq m, is US$5,850, plus US$877.50 VAT, a total of US$6,727.50.</p>'
            '<h2>How to book</h2>'
            '<ol>'
            '<li><strong>Send an enquiry</strong> through the <a href="' + SITE + '/book-a-stand/">Book a stand</a> page, with the option and size you have in mind.</li>'
            '<li><strong>Receive a proposal.</strong> The sales team sends stand options, an official proposal and the participation contract.</li>'
            '<li><strong>Confirm.</strong> Space is reserved once the paperwork is completed with the team.</li>'
            '</ol>'
            '<p>Sponsorship packages are available on request from the <a href="' + SITE + '/downloads/">downloads</a> page.</p>'
            '<h2>Who you will meet</h2>'
            '<p>The 2026 edition had 24,697 visitors and 378 participating companies from 25 countries. Read <a href="' + PULSE + '/exhibition-news/pharma-asia-2026-in-numbers">Pharma Asia 2026 in numbers</a>.</p>'
            '<h2>Sources</h2><ul><li><a href="' + SITE + '/participation-tariff/" target="_blank" rel="noopener">Pharma Asia, Participation Tariff (organiser’s page)</a></li>' + SRC_FACT + '</ul>'
        ),
    ),
]
w('-- Three new articles, as drafts in Exhibition News, by the Editorial Desk. Check and publish them in the Pulse admin.')
for a in articles:
    w("INSERT INTO blog_articles (title, slug, category_id, author_id, excerpt, body, status, seo_title, meta_description, lang, reading_minutes, source, sources)\n"
      "SELECT %s, %s, (SELECT id FROM blog_categories WHERE slug = 'exhibition-news' LIMIT 1), (SELECT id FROM blog_users WHERE role = 'author' ORDER BY id LIMIT 1),\n"
      "       %s, %s, 'draft', '', %s, 'en', 3, 'admin', %s FROM DUAL\n"
      " WHERE NOT EXISTS (SELECT 1 FROM blog_articles WHERE slug = %s);"
      % (q(a['title']), q(a['slug']), q(a['excerpt']), q(a['body']), q(a['meta']), j(a['sources']), q(a['slug'])))
    assert len(a['meta']) <= 320, a['slug']
w('')
with open(OUT, 'w', encoding='utf-8') as f:
    f.write('\n'.join(sql) + '\n')
print('wrote', OUT)
