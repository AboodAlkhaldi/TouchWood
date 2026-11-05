# Owner's reminders

Copied from the previous agents' notes on 2026-11-05. Keep it current; this copy is now the one to update.


The owner (2026-10-27): "all sessions must put all their reminders in the same place, so when I ask I
get all the held things". Every session adds its reminders HERE (not only in its own memory file),
marks them done here, and lists this whole file when the owner asks what is left or held.

**Why:** reminders scattered over many memory files went stale or were raised after they were already
delivered (the B2B "draft with no company" point, delivered 2026-09-30, was raised again 2026-10-27).
**How to apply:** one line per item: what, when to raise it, which session/file it came from. Verify an
item is still open before raising it; move done items to the Closed list with the date.

## Open

### At hosting / launch
1. (2026-11-03: owner tested Namecheap shared hosting: HTTPS on, port 443, REMOTE_ADDR the real IP, so NO trusted proxy is needed there; the code has none and needs none. Add one only if a CDN or Cloudflare is turned on later. On a signed-in page `data-page` must say "encryptHistory":true.) Set `SESSION_SECURE_COOKIE`; old note: `TRUSTED_PROXIES` once the host is chosen (handoff §15.4) — otherwise
   email-verification links answer 403 behind the host's proxy. (Access, [[touchwood-access-complete]])
1b. At deploy (owner 2026-11-03): re-check on the new code: a temporary PHP check file shows HTTPS on, port 443, the real IP in REMOTE_ADDR; a signed-in page says "encryptHistory":true; Back after sign-out shows nothing; delete the check file. Goes in the commands file too.
2. Lock the staging subdomain from the public — **but leave `/payments/notice/…` open**, or MyFatoorah's
   payment notices cannot reach it. (Stage 7, owner asked 2026-10-27)
3. On the real domain: enter the LIVE MyFatoorah key and secret again, and change the notice address in
   MyFatoorah's portal to the real domain. (Stage 7, payments.md §1.6)
4. The server must run Laravel's scheduler (cron every minute) and a queue worker — every module's jobs
   already need them (18 scheduled jobs on main 2026-10-27). (Stage 7)
5. Redesign the campaign styles together with the system-wide flags/tags/badges style, before launch
   (sales.md §9.2 #10). (Stage 6 screens, [[touchwood-stage6-screens]], owner asked 2026-10-27)

6a. Apple Pay on our checkout: get the domain-verification file from MyFatoorah support (tech@myfatoorah.com —
   their docs say so; the docs do not mention a portal download), serve it on the staging AND the real
   domain, keep it there, register each domain. (Stage 7, payments.md §1.2)
6b. Never disable the MyFatoorah portal user who created the API key — it disables the key. (Stage 7)
6d. On the deploy that ships the stock-dependent-on-by-default migration (branch
   feat/stock-dependent-default, 2026-10-28): run `php artisan inventory:orderable:rebuild` right after
   `migrate` — until it runs the shop shows 0-stock sizes as orderable (HANDOFF §15.4). Owner asked
   to keep this reminder (2026-10-28). (Stage 5, [[touchwood-stage5-decisions]])
6e. On the deploy that ships the sold-out fix (branch fix/catalog-sold-out-hidden, 2026-11-03): run in order `php artisan pricing:candidates:rebuild`, `inventory:orderable:rebuild`, `catalog:listing:rebuild`. Unpriced sizes leave the shop too. Every size told not orderable leaves the shop at once, including sizes switched on with no stock counted, so count stock before opening a store. (Stage 8)
6c. Ask MyFatoorah's account manager the numeric limit on status checks, and whether a 5-minute
   PaymentExpiry/SessionExpiry is accepted (first build step tests it anyway). (Stage 7)

### When the work is built
6. The KSA and Egypt city and district lists: CHANGED 2026-10-29 — the owner said "get the cities yourself
   from web": the stage 7 session finds open datasets, reports source + counts + problems, then seeds. Not
   owed by the owner any more; owed TO the owner: the chosen sources for a look before seeding. (Stage 7)
   SETTLED 2026-10-29: owner saw KSA-cities.csv; all 4,354 KSA kept, regions "… Region"; Egypt 27/389;
   AE/QA/BH top level only; Oman withdrawn. Per-country place FILE UPLOAD: owner DROPPED it, "think about
   it in future" — do not raise unless asked.
7. The shipping boxes list — the owner sends it; the boxes feature is built and stays unused until then.
   (Stage 7)
8. Translation (staff auto-English + shopper Translate button): service CHOSEN = DeepL (owner 2026-11-04; official library deeplcom/deepl-php v1.21.0 on Packagist, checked). NOTHING is built: no adapter, setting, token or screen; only each review's and question's language is stored. Spec: feedback.md F2, F5. Who builds it (me or the outside agent): not decided. (Stage 6 screens)

9. Phone card on the admin Account page: on phones, should its Change button also go under the number,
   to match the Email card (owner's pick 2026-10-29 for Email only)? It fits today; low priority.
   (Phone audit, [[touchwood-phone-audit]], 2026-10-29)

9c. Owner call (step 6, sales.md §9.6 #11): an order staff move to a place NO carrier serves keeps its way
   to pay as built (not switched to "staff will contact you"). Confirm or change. Raise with step 6's report.
   (Stage 7, step 6 session, 2026-10-31)
   SETTLED 2026-11-02: owner: keep it as it is (stays a bank transfer).

### Status not verified — from older session notes (check before raising)
(Owner 2026-10-29: "let reminders yet to answer" — keep them open, don't push for answers; 17–19 being
verified by the stage 7 session — raise only what is confirmed.)
11. CONFIRMED OPEN 2026-10-29 (checked in code + docs): (a) promotions.md contradicts itself on a
    deleted customer among segment Members — Q18 line 876 "Deleted customer" shown vs M3 line 538 "never
    shows"; the code follows Q18 (MarketingReadsTest, Segments/Show.tsx); owner picks, then fix the docs.
    (b) Arabic for coupons is mixed: قسيمة 40 + قسائم 16 vs كوبون 21 in lang/ar (menu says القسائم, the
    spec's menu row الكوبونات, permissions كوبونات); owner picks one. ([[touchwood-stage6-screens]])

12. Ops/SMS (2026-10-29): name the Saudi SMS provider (and later Egypt's). Corrected 2026-11-05: production refuses `log`, so until a provider is named a production server starts normally and every code fails when sent (a FAILED row, an error to the caller, S10b). The owner is deciding whether that stays.
    Brevo: the owner HAS the key and adds it at staging (2026-11-03); sending-domain DNS still before real emails. ([[touchwood-stage-8-ops]])
14. Brevo one-click unsubscribe (2026-10-30): Brevo's send API refuses standard headers, so our List-Unsubscribe
    header can't be sent; Brevo adds its own (unsubscribes at Brevo). Check with the real Brevo account that
    its unsubscribe reaches our webhook and withdraws consent. ([[touchwood-stage-8-ops]])

15. END OF PROJECT (owner 2026-10-31): give the owner EVERY command they'll run, with steps: create the first Super Admin
    (access:create-super-admin or as built), the authenticator reset for a lone Super Admin, DB export/import with the same
    APP_KEY, migrations/seed for a fresh live DB, queue worker + scheduler; server clock synced (NTP) — authenticator codes depend on time; SESSION_ENCRYPT=true. ([[touchwood-stage-8-ops]])
    Also: set OPS_NEWSLETTER_DRIVER=brevo on the live server; production refuses to boot on log (Ops step 10, 2026-11-01).

19. FILES OWED TO THE OWNER (owner 2026-11-02), when each is final, not yet: every photo size the site uses (when all photos are known);
    the Odoo API format after the edits; the products bulk upload format; any other format file still open.
    ALSO A FILE (owner 2026-11-03): every terminal command the owner will run (first Super Admin, authenticator reset, the Brevo check, rebuilds, deploy steps, from every session). Two kinds of reminders: normal ones, and FILES owed; keep them apart when listing.

17. Claude Design (told owner 2026-11-02): standalone claude.ai/design closes by 2026-12-14 (DesignSync tool's notice); save the storefront prototype TouchWood Home.dc.html out of it before then.

16. The whole site's wording: the owner checks ALL wording across the site later (owner 2026-11-02, Ops screens round 1), so new page words go in as approved for now. Raise when the screens are done. ([[touchwood-ops-screens]])

18. Redirect sheet PARKED (owner 2026-11-02): owner fills TouchWood-old-address-redirects.xlsx when the final product list goes live, after the system is built; must be done BEFORE launch. Before the old code goes down: copy the 6 blog posts' text and photos, and anything else worth keeping, because the old pages vanish with it.

20. Client's projects (owner 2026-11-02): ask the client about the finished projects and what we need from them: photos only, or a written story too. Draft message with the checklist was given to the owner 2026-11-02. Raise with Content.

21. Subscribers page, two calls (Ops screens, 2026-11-04, PR #347): the list is oldest first while other Ops lists are newest first; the hidden email's tooltip can't be opened by touch (a popover would). ([[touchwood-ops-screens]])

22. BEFORE DEPLOYMENT (owner 2026-11-04): remove the development-only hand-off files from GitHub: docs/HANDOFF.md, docs/AGENT-BRIEF.md, docs/AGENT-HANDOFF.md, docs/handoff/, and ask the owner about the rest of docs/. Owner 2026-11-05, again: everything put on GitHub for the outside agent is development-only and comes off at the end. They are for the development stage only. The repo STAYS PUBLIC for now (owner 2026-11-04: going private loses the free unlimited CI minutes); deleting a file does not remove it from history. Raise it in every "what's left" and at launch.

23. A sold-out product in an emptied category has no breadcrumb, though the category and everything above it now answer 200 (#344's review, 2026-11-05; ShopPages.php:205-207). Amendment 40(d) covers only the sub-category's own page. Owner's call.

24. Live server with no SMS provider (Ops final check, 2026-11-05): it starts normally, then every code fails when sent (a FAILED row, an error to the caller, S10b). Decide: refuse to start, as Brevo's key does (recommended, since sign-in codes are essential), or leave it. Owner's call. Choose the provider before go-live either way (reminder 12).
25. docs/modules/ops.md's top line still says "DRAFT… Not accepted", though it was merged and built. Owner to confirm it's accepted, then change the line.
26. Optional tests from the Ops final check: fire `GuestBecameCustomer`, `CustomerEmailVerified` and `CustomerUnblocked` in Ops' suite; schedule tests for 4 Ops jobs; `$timeout` on long Ops jobs once a worker command exists.

## Closed
- Flaky phone-width browser checks (old item 9b): DONE, #333 merged 2026-11-03 (fadb0b90); about 13 differently written checks unchanged.
- One verified account phone for shipping (old item 13): DONE, #284 merged 2026-10-31 (c2db1817).
- Stage 5 owner calls (old item 9) — CLOSED, checked 2026-10-29: archived sizes left off Low Stock
  (inventory.md:152, #136); Inventory 5 = count kept per store (#161); stage 5 screens all merged (#217, v0.7.0).
- Admin panel frame 89–100 queries (old item 10) — FALSE now: #108 (2026-10-10) cut it to 9; today 13 at
  most (AdminFrameQueryBudgetTest, recorded counts). Home for a Super Admin adds 3 queries per store (27 with
  3 stores) — Home's own reads, a possible later fix.
- B2B "draft with no company" — delivered at B2B step 6 (2026-09-30) and accepted. Raised again by mistake
  2026-10-27.
- Arabic font subset bug — fixed (`vite.config.ts` has `subsets: ['arabic', 'latin']`, checked 2026-10-27).
- Arabic price digits — replaced by Latin digits everywhere (2026-10-06).
- Home page ideas — received 2026-10-13.
