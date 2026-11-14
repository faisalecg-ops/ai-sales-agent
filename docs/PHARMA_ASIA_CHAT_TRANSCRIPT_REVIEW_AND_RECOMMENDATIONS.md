# Pharma Asia Sales Chat Review — Findings, Recommendations & Conclusion

**Purpose:** Consolidate the review of the supplied Pharma Asia AI sales conversation into an actionable document for the AI Sales Agent repository.

**Scope note:** This is a conversation-quality review based on the supplied transcript. It is not a code audit of the latest implementation, and it does not independently verify event facts, package prices, VAT, benefits, availability, or URLs quoted by the agent.

## 1. Executive conclusion

The conversation shows progress in product and business discovery, but the agent still behaves too much like a scripted qualification form when the visitor moves into a buying discussion.

The agent understood that the visitor represented a China-based company exploring the Pakistan market, wanted to meet distributors and direct pharmaceutical buyers, and intended to display stainless-steel reactor tanks and pressure vessels. It introduced exhibition participation and sponsorship options.

However, it did not consistently answer the complete commercial question, did not explain clearly how exhibiting and sponsorship can complement each other, and appeared to promise an email quotation before later saying it could not send one.

**The most important correction is quotation-action integrity.** The agent must never imply that it will email, submit, reserve, notify, or arrange something unless the application has a real backend action and that action has returned confirmed success. If the capability does not exist, the agent must disclose that before asking for contact details.

The next priorities are complete package comparisons, recommendations based on the visitor's objectives and equipment requirements, and realistic regression tests using the actual model.

## 2. What the agent did well

Retain these strengths:

1. It understood the company was based in China and interested in the Pakistan market.
2. It identified the objective of meeting distributors and direct buyers from pharmaceutical companies.
3. It captured the product category: stainless-steel reactor tanks and pressure vessels.
4. It recognised the importance of procurement and purchasing decision-makers.
5. It introduced exhibition stand participation and sponsorship as possible routes.
6. It recognised that the visitor was evaluating commercial options, not merely asking for general event information.

The objective is not to discard discovery, but to make the agent use the information already collected to give better commercial advice.

## 3. Problems identified

### 3.1 Stand and sponsorship were treated too much like competing choices

A stand and sponsorship serve different purposes and may complement each other.

- **Exhibition stand:** Physical product display, demonstrations, meetings, and direct interaction with visitors.
- **Sponsorship:** Wider event visibility, brand positioning, recognition, and the promotional benefits defined for the selected tier.
- **Combined participation:** A stand plus a suitable sponsorship tier may be appropriate when the visitor needs both product demonstrations and stronger brand exposure.

The agent should explain the distinction and assess whether a combined approach is appropriate. It must not imply that sponsorship includes a physical stand unless approved package terms explicitly say so.

### 3.2 The recommendation was not sufficiently tailored to the equipment

Stainless-steel reactor tanks and pressure vessels may require more than an ordinary display space. Before giving a definitive stand-size recommendation, the agent should establish relevant details:

- Approximate dimensions of each unit.
- Number of units to be displayed.
- Approximate weight and any floor-loading considerations.
- Whether the display will be full-size equipment, scaled models, samples, photographs, or digital presentations.
- Power, utility, installation, access, or other technical requirements, if applicable.
- Whether equipment will be demonstrated or only displayed.

Do not turn this into a long questionnaire. First explain the available options, then ask the single most important missing question.

### 3.3 Package prices were not presented together

When the visitor asked for sponsorship tiers, the agent repeatedly made them request Gold and Platinum individually. This creates friction and appears evasive.

When a visitor asks for all sponsorship tiers, present all configured tiers in one response, including:

- Exact tier name.
- Base price.
- Applicable tax or VAT, if confirmed.
- Total including tax, where approved.
- Key benefits and entitlements.
- Important qualifications or availability notes, where verified.

Do not make the visitor ask for each tier separately.

### 3.4 The agent did not provide the complete comparison requested

When a visitor asks for prices or quotations for all options, provide the most complete useful comparison available. Distinguish clearly between:

1. Showing published package prices.
2. Comparing packages and benefits.
3. Preparing an indicative written comparison.
4. Creating a formal quotation.
5. Sending a quotation by email.
6. Recording a request and notifying the sales team.

These are different actions. If formal quotations genuinely must be issued separately, explain that limitation, but still provide the available comparison in chat.

### 3.5 The email quotation exchange is a serious trust issue

The visitor supplied an email address after the agent asked for it in the context of sending a Platinum quotation. The agent then said it could not send the quotation directly.

This is the most serious issue in the transcript.

The agent must follow one of these paths:

- **Email workflow exists:** Collect the required details, invoke the real workflow, and report success only after the backend confirms it.
- **Sales request workflow exists:** Create the request, notify the responsible team, and confirm only after the backend confirms the request and notification.
- **Neither workflow exists:** Explain that it cannot send an email or submit a quotation request. It may still show published information in chat or provide an approved contact route.

Never invent a quotation reference, email delivery, sales-team notification, booking, or follow-up. Do not request an email address on the promise of an action the application cannot perform.

### 3.6 The final call to action may not match the visitor's intent

A general “Book a Stand” link may be suitable for stand participation but may not be the correct destination for a sponsorship enquiry or combined participation request.

Use the approved route that matches the visitor's intent: stand booking, sponsorship enquiry, general sales contact, or formal quotation request. Verify the destination rather than assuming an available URL is suitable.

### 3.7 Commercial facts and package names need consistency checks

The transcript appears to use different wording for the top sponsorship tier (for example, “Title Partner” and “Title Sponsor”). Use the exact approved name consistently.

Verify all of the following against authoritative sources before treating them as facts:

- Visitor attendance figure.
- Edition number and event dates.
- Sponsorship tier names.
- Base prices, VAT rate, and tax calculations.
- Sponsorship benefits and entitlements.
- Stand sizes and inclusions.
- Availability, deadlines, and booking claims.
- Registration and enquiry URLs.

Do not let the language model improvise commercial facts when approved structured data is available.

## 4. Required conversational behaviour

### 4.1 Answer the complete question first

If a visitor asks for all sponsorship prices, show all configured tiers together. If they ask for all participation options, explain the relevant options together. Do not answer only one part of a multi-part question and force the visitor to ask again.

### 4.2 Recommend against the visitor's actual objective

Consider the visitor's country and target market, business objective, product/equipment category, target audience, display and technical requirements, desired visibility, and budget if voluntarily provided.

Explain *why* an option fits. Do not simply label a package “best.”

### 4.3 Treat stand and sponsorship as complementary

Explain the different purposes of each. Do not imply that a sponsorship package includes a stand, or that a stand includes sponsorship benefits, unless the approved terms explicitly confirm it.

### 4.4 Avoid repetitive qualification

Use information already supplied. Do not ask the visitor to repeat their company, country, objective, or product category. Ask only for information that materially changes the recommendation.

### 4.5 Make limitations clear before collecting contact information

If the agent cannot email a quotation, it must not ask for an email address as though it can. If it can record a request but cannot send the quotation itself, explain that distinction before collecting details.

### 4.6 Maintain a consultative tone

Be direct, complete, professional, helpful rather than pushy, clear about prices and inclusions, honest about limitations, and specific to the visitor's business.

## 5. Recommended prompt instructions

Incorporate the following behavioural guidance into the relevant sales-agent system prompt or prompt-building layer. Prompt instructions do not replace backend validation or approved data.

### COMMERCIAL RESPONSIVENESS AND PACKAGE RECOMMENDATIONS

1. **Answer package questions completely.** If a visitor asks for all sponsorship tiers, provide all configured tiers in one response. Do not make the visitor request each tier separately.
2. **Provide useful comparisons.** Include approved package names, prices, applicable taxes, totals, and configured benefits when available.
3. **Use approved commercial data.** Never invent or estimate prices, VAT, benefits, stand sizes, availability, or event facts. If information is unavailable, say so and use an approved sales route.
4. **Recommend based on the visitor's needs.** Consider business objective, country/market, products, target customers, display requirements, desired visibility, and stated budget.
5. **Explain stand versus sponsorship.** A stand supports physical display and direct engagement; sponsorship supports the branding and promotional benefits listed for that tier. They may be complementary. Do not imply one includes the other without approved terms.
6. **Ask relevant technical questions.** For machinery or large equipment, establish dimensions, number of units, display format, weight, and relevant utility/access needs before making a definitive space recommendation.
7. **Do not over-qualify.** Use information already supplied. Ask only the most important missing question.
8. **Do not artificially restrict comparisons.** If several options are requested, compare them together. If formal quotations have a genuine operational limitation, explain it while still providing the published comparison.
9. **Distinguish information from actions.** Showing a price, preparing a comparison, creating a quotation, submitting a request, notifying a sales team, and sending an email are separate operations.
10. **Never claim an action succeeded without confirmation.** Confirm a quotation, email, booking, lead submission, or notification only after the relevant backend operation reports success.
11. **Never promise unsupported email delivery.** Do not request an email address on the promise of sending a quotation unless an active, working email workflow exists and will be invoked.
12. **Explain limitations before collecting contact details.** If the application cannot send a quotation or create a request, state that clearly and offer only supported alternatives.
13. **Use the correct call to action.** Provide the approved stand, sponsorship, or general enquiry link that matches the visitor's intent.
14. **Maintain terminology.** Use exact approved sponsorship tier names consistently.
15. **Preserve conversation context.** Do not ask the visitor to repeat information already captured.

## 6. Example of a better response for this visitor

This is an illustrative response pattern, not an approved quotation. Actual package names, prices, benefits, tax treatment, stand sizes, and URLs must come from current approved sources.

> Based on your objective of entering the Pakistan market, meeting pharmaceutical distributors and direct buyers, and presenting stainless-steel reactor tanks and pressure vessels, I would assess two complementary options.
>
> **Exhibition stand:** This gives your team a physical space to present the equipment, discuss specifications, and meet potential buyers. The appropriate size depends on the dimensions and number of units you plan to display.
>
> **Sponsorship:** This can add wider event visibility and brand recognition among the relevant audience. I can compare all available sponsorship tiers together so you can assess their benefits and investment.
>
> **Combined participation:** If your priority is both equipment presentation and stronger brand exposure, a stand combined with a suitable sponsorship tier may be worth considering. Sponsorship should not be assumed to include a stand unless the package terms explicitly confirm it.
>
> I can show you the approved prices and benefits for all available sponsorship tiers. To recommend a suitable stand size, may I know the approximate dimensions of the largest equipment unit you intend to display, and whether it will be a full-size unit or a model?

When the visitor asks for all sponsorship prices, display the full configured comparison without requiring separate requests.

If the visitor asks for an email quotation, follow the real capability path:

- If email sending is implemented, explain the submission step, invoke the backend, and confirm only after success.
- If only a sales enquiry can be recorded, explain that the chat cannot send an official quotation but can submit an enquiry, then confirm only after the request workflow succeeds.
- If neither action is implemented, disclose that the chat cannot email or submit an official quotation and provide the approved contact route.

Do not use sample wording to imply capabilities the deployed application does not have.

## 7. Backend and application requirements

Prompt changes alone are not sufficient.

### 7.1 Quotation and enquiry action integrity — critical

Inspect the actual request, lead, and notification workflow. Establish:

- Whether a structured quotation-request action exists.
- Whether email and required fields are validated.
- Whether the request is saved in the database.
- Whether an email is actually sent, and through which configured service.
- Whether the sales team is notified.
- Whether failures are returned to the conversation layer.
- Whether the agent receives a definitive success/failure result.
- Whether duplicate submissions are prevented or safely handled.
- Whether the action is logged for audit and troubleshooting.

The model should request an action through a defined contract. PHP should validate and execute it. The model must not be the authority that decides whether an email was sent or a lead was saved.

Success messaging must be driven by backend results. On failure, the agent should state that the operation did not complete and offer a supported alternative.

### 7.2 Structured package comparison

Where possible, expose configured sponsorship tiers as structured data rather than relying on the model to retrieve separate fragments from long knowledge articles.

A comparison should be assembled from the approved source and include all active tiers. Keep tier name, price, tax, total, and benefits tied to authoritative configuration.

If knowledge retrieval returns only one or two tiers at a time, improve retrieval or provide a dedicated structured comparison payload. Do not try to solve incomplete retrieval solely through prompt wording.

### 7.3 Recommendation context

The model may explain and recommend, but it must not invent underlying commercial facts. Make available the visitor's stated objective, product and display format, target audience, approved stand options and inclusions, sponsorship tiers and benefits, and known constraints or missing technical details.

For large equipment, avoid definitive stand-size recommendations until necessary display information is known.

### 7.4 Preserve existing safeguards

Implement improvements without weakening existing project rules:

- PHP/database remain authoritative for pricing, country/geo handling, lead creation, and security.
- Use only approved package data and tariff sources.
- Preserve multi-tenant separation and agent_id scoping.
- Do not expose international prices to Pakistan/undetermined visitors contrary to configured pricing policy.
- Do not allow the model to override server-side decisions.
- Do not claim a lead or request was recorded unless the database operation confirms it.

## 8. Implementation priority

| Priority | Work item | Reason | Expected outcome |
|---|---|---|---|
| P0 — Critical | Verify and correct quotation/email/request integrity | The transcript creates an expectation of email delivery that is later withdrawn | No false promises; accurate confirmation of real actions |
| P1 — High | Present all configured sponsorship tiers together | Visitors should not request tiers one by one | Complete, efficient comparison |
| P1 — High | Make package facts structured and approved | Prompt-only retrieval can omit tiers or produce inconsistent details | Consistent names, prices, benefits, and tax |
| P1 — High | Improve stand + sponsorship recommendations | Options should match visitor objectives | Consultative recommendations |
| P2 — Medium | Add equipment-specific qualification | Large equipment has practical space and technical requirements | More defensible stand recommendations |
| P2 — Medium | Verify CTA routing | A stand link may not serve a sponsorship enquiry | Correct next step for each intent |
| P2 — Medium | Add real-model conversation regression tests | Mock tests do not prove production conversation quality | Fewer real-world regressions |

## 9. Conversation regression tests

Test both the model response and backend side effects.

### Test 1 — All sponsorship tiers

**Visitor:** “Please share all sponsorship packages with prices and benefits.”

**Expected:** Every active configured tier appears in one response; prices, tax, totals, and benefits match approved data; no tier-by-tier prompting; no invented packages.

### Test 2 — All participation options

**Visitor:** “What are all the ways my company can participate?”

**Expected:** Explain stand and sponsorship; distinguish physical display from branding; explain that options may be combined where permitted; do not imply sponsorship includes a stand without explicit terms.

### Test 3 — Business-specific recommendation

**Visitor:** “We are a Chinese manufacturer of stainless-steel reactor tanks and pressure vessels. We want to meet Pakistani distributors and pharmaceutical companies.”

**Expected:** Use the stated objective and products; explain why a stand may help; explain sponsorship visibility; do not declare a stand size without display details; ask a concise relevant technical question.

### Test 4 — Large equipment display

**Visitor:** “We want to display two full-size reactor tanks.”

**Expected:** Ask approximate dimensions and relevant weight/access/utility details; do not guarantee a stand size without verified information; explain that final suitability may require organiser/technical confirmation.

### Test 5 — All options and a quotation

**Visitor:** “Please give me prices for all options and send me a quotation.”

**Expected:** Provide the complete published comparison; distinguish indicative comparison from official quotation; use the real quotation workflow if it exists; otherwise disclose the limitation before requesting an email address.

### Test 6 — Email address supplied

**Visitor:** “Send it to procurement@example.com.”

**Expected:** Do not claim delivery merely because an address was supplied; invoke the configured backend action if available; confirm only after success; on failure, state that the operation did not complete.

### Test 7 — Negation and hypothetical language

**Visitor:** “We are not ready to book yet. We are only comparing the options.”

**Expected:** Do not treat this as a booking commitment; provide information without creating a booking or falsely marking the lead ready to purchase.

### Test 8 — Sponsorship enquiry CTA

**Visitor:** “How can I proceed with the Platinum sponsorship?”

**Expected:** Use the approved sponsorship enquiry/quotation route; do not default to a stand-booking link unless it is the correct process; do not claim availability unless verified.

### Test 9 — Unknown package inclusion

**Visitor:** “Does the Gold package include a stand?”

**Expected:** Check approved package terms; if not explicitly confirmed, say so and offer verification; do not infer inclusions from the tier name or price.

### Test 10 — Price and tax consistency

**Visitor:** “What is the final amount including VAT?”

**Expected:** Use the configured tax rule and approved calculation; label tax included/additional; do not mix base price, tax, and total; do not calculate from stale transcript values.

## 10. Release validation checklist

- [ ] Sponsorship tier names match the approved tariff.
- [ ] Package prices match the approved tariff.
- [ ] VAT/tax treatment and totals are verified.
- [ ] Benefits and inclusions match the approved source.
- [ ] Stand sizes and inclusions match approved participation data.
- [ ] All configured sponsorship tiers can be shown in one response.
- [ ] Stand and sponsorship are explained as distinct but potentially complementary.
- [ ] Equipment recommendations request relevant technical details.
- [ ] Quotation workflow existence is verified, or the prompt discloses that it does not exist.
- [ ] Email sending is verified end-to-end if claimed.
- [ ] Sales-team notification is verified end-to-end if claimed.
- [ ] Backend success/failure is passed reliably to the conversation layer.
- [ ] The agent never confirms an uncompleted action.
- [ ] Sponsorship enquiries use the correct approved URL.
- [ ] Existing pricing, geo, tenant, and security safeguards remain intact.
- [ ] Real-model regression conversations pass, not just mocked-provider tests.

## 11. Price figures appearing in the reviewed transcript — verification required

The transcript included the following figures:

| Tier as stated in transcript | Base price | VAT stated | Total stated |
|---|---:|---:|---:|
| Silver Partner | USD 10,000 | USD 1,500 | USD 11,500 |
| Gold Partner | USD 20,000 | USD 3,000 | USD 23,000 |
| Platinum Partner | USD 40,000 | USD 6,000 | USD 46,000 |
| Title Sponsor | USD 75,000 | USD 11,250 | USD 86,250 |

**These figures are reproduced only to identify the amounts stated in the conversation. They are not certified or approved prices in this review.** Compare them with the current official Pharma Asia participation/sponsorship tariff and confirm exact names, tax rules, inclusions, and totals before using them in the agent. This table must not become a pricing source of truth.

## 12. Final recommendation and conclusion

Do not treat this as a prompt-only problem. The transcript reveals a combination of:

1. **Conversation policy gaps:** The agent does not consistently provide complete comparisons or explain complementary participation options.
2. **Knowledge/data presentation risks:** Package information may not be retrieved or presented as one complete, authoritative comparison.
3. **Potential backend capability gaps:** The quotation/email exchange suggests the conversation may promise an operation the application cannot complete.
4. **Testing gaps:** A successful mocked acceptance test does not establish that the production model will handle a realistic commercial discussion correctly.

Recommended sequence:

1. **Audit quotation and lead submission first.** Establish exactly what the deployed application can save, send, and notify. Correct promise/confirmation behaviour before encouraging visitors to provide contact details.
2. **Make package comparison complete and data-driven.** All active sponsorship tiers should be available together from approved structured data.
3. **Improve recommendation instructions.** Assess stand, sponsorship, and combined participation against the visitor's stated objectives.
4. **Add equipment-specific qualification.** Ask for dimensions and relevant technical requirements before making a definitive space recommendation.
5. **Validate the full journey with real-model tests.** Include success, failure, missing-data, and non-committal visitor scenarios.

**Overall conclusion:** The agent is improving at discovering what a prospective exhibitor wants, but it must now become more reliable at helping that visitor make a commercial decision. Every commercial answer should be complete, every recommendation explainable, every price traceable to approved data, and every promised action verifiably real.
