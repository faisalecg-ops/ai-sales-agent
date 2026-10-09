# Universal rules for future work

Given by the owner on 29 September 2026. These apply to every future task
wherever they are relevant. When a task touches email, the website chat or
WhatsApp, check it against these rules before designing or building it.

---

## 1. Background research, prospect classification and a personalised sales approach

How much research is done, and how hard the assistant sells, depends on the
channel and on how interested the person is.

### A. Email

Before writing **every** email to a potential client, the assistant researches
the recipient and their company online.

The research should, where possible, find the **decision-making contact
person**, and decide what kind of opportunity the company is:

- Potential exhibitor
- Potential sponsor
- Industry or business prospect
- Another relevant commercial opportunity

It then looks at the company's:

- business profile
- industry, products and services
- size and business strength
- relevance to the market
- relevance to the exhibition
- likely requirements
- the contact person's role in decisions

From that it chooses the **package, stall option, sponsorship or other
offering** that best fits the company. The email is a strong, personalised
pitch built on that research, not a standard space-booking message.

### B. Website chatbot

The chatbot starts **light**. It qualifies the visitor rather than researching
them straight away.

Where appropriate it collects:

- First name
- Company
- Country
- **Interested in exhibiting / Interested in visiting** (a choice of two)

**Visiting:** thank them for visiting and ask whether they need anything else.

*Added by the owner, 29 September 2026:* even for someone who chose visiting,
**start from their relevance to the exhibition, then make a light try for a
sale.** If they could be an attendee of a conference the event has, lightly
pursue that; if their business also fits exhibiting, lightly suggest that.
Never more than a light suggestion, and never to someone whose business does
not fit.

**Exhibiting:** apply the same research as for email:

1. Research the visitor and the company.
2. Identify their likely category and, where possible, their role in decisions.
3. Analyse the company's profile and exhibition potential.
4. Choose the most suitable package, stall, sponsorship or other opportunity.
5. Continue with a personalised, relevant pitch based on that research.

### C. WhatsApp

For someone who registered online, first analyse what is known about them
before sending anything sales-oriented. Work out, where possible:

- whether they are a decision maker
- their company and its category
- whether they look like a potential exhibitor, sponsor, visitor or other
  prospect

The **first message is mainly a thank-you** for registering. If they appear to
have exhibitor potential, add a **light**, relevant pitch — not a full
proposal.

Once they are identified as a **serious** potential exhibitor, apply the same
deeper research as for email:

- research the company
- identify the relevant decision maker
- analyse the company's profile and exhibition potential
- choose the most appropriate package, stall, sponsorship or offering
- continue the WhatsApp conversation with a personalised pitch based on it

---

## 2. Country detection — website chatbot and WhatsApp only

The visitor's country comes first from what the visitor tells us.

**Order of preference:**

1. The country the visitor states in the conversation.
2. The country chosen or submitted in a form.
3. The country shown by other reliable information given during the
   conversation.
4. **The IP address, only as the last resort**, when the country still cannot
   be determined and a price has to be shown.

IP detection is never used when the visitor has already given their country.
It exists as a fallback for pricing, not as the main way of finding a
country.

---

## 3. Business facts

Given by the owner on 29 September 2026.

**Every WhatsApp number has its own API key.** A TextMeBot key is tied to the
one phone it was linked with. Each exhibition therefore stores its own key
alongside its own number, and nothing is ever sent using another exhibition's
key.

**Local and international sales reps are different people**, each with their
own:

- name
- designation
- email
- mobile number
- WhatsApp number
- WeChat

There is **one local and one international rep per exhibition**. Whenever
any "needs a person" rule applies — the visitor asks for a person or a call,
asks for a discount, complains, raises a contract, invoice, payment or
refund, or the assistant cannot answer — the visitor is given that rep's full
details, on WhatsApp and in the website chat. When the visitor's country is
not yet known, both reps are given, each labelled. Rep details are never
given for ordinary questions.

Anything that points a visitor or lead to "the sales team" must use the rep
for that visitor's region: local for Pakistan and for anyone whose country is
not yet known, international for everyone else. This is the same split the
platform already uses for notification emails.

---

## Where the platform stands against these rules (29 September 2026)

| Rule | Status |
|---|---|
| 2. Country: stated first, IP last | **Already how it works.** A country the visitor states is applied before anything else; the IP lookup runs only when a price is involved and the country is still unknown. WhatsApp uses the country from the registration form. The chat's introduction card feeds the same "stated by the visitor" path, as the company's country; anything the visitor says later in the chat overrides it. |
| 1C. WhatsApp first message: thank-you, light pitch only if relevant | **Already how it works.** |
| 1C. Deeper research once a serious exhibitor is identified | **Built.** "Serious" = the assistant sees an exhibitor or sponsor **and** the person has asked, in their own words, about a stand, price, size or booking. Research runs on the next scheduled task; the assistant uses it from their next message; a good fit (qualified or better) becomes a lead and the team is emailed the research. |
| 1B. Chatbot: four fields and the exhibit/visit choice, then research for exhibitors | **Built.** Visiting → thanked, helped, and — starting from relevance — at most a light mention of the conference or exhibiting. Exhibiting (or later exhibiting/sponsorship interest) → the company is researched with Tavily and the assistant uses the verified findings from the next reply. |
| 1A. Email: research the company and decision maker before every email, then pitch the best-fitting offering | **Built.** Prospecting campaigns research every company (score, decision maker, verified contact, best-fitting approved package) before an email is written from those facts; a person approves each email by default; sent from the exhibition's own sending profile at its own pace. |

The research in 1A, 1B and 1C is one capability used by three channels
(`CompanyResearch`), built once and shared, following the same evidence rules as the
Companies screen: observed facts, inferences and unknowns kept apart, and
only observed facts stated to the client.
