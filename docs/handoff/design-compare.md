# Design against what is built

Copied from the previous agents' notes on 2026-11-05. Keep it current; this copy is now the one to update.


Read-only comparison, 2026-11-03, code at 1dc654b6, design in scratchpad design2. Source read only, nothing rendered. Part of [[touchwood-content-round1]].

**Why:** the Content spec and storefront rebuild start from this.
**How to apply:** verify against current code before relying on it.

**Design's structure:** screens `cat`, `pdp`, `cart`, `order`, `b2b` in `TouchWood Screens`, plus `TouchWood Home`. Phone (<=640) is a separate layout for Home, cat, pdp, cart; tablet is desktop reflowed (hamburger <=1024, one column <=820). No design for checkout, account, search, brand, wishlist.

**Missing from the shop:** top bar, phone tab bar, phone header, sticky header, Home (placeholder only at Platform/Storefront/Home.tsx), grid/list switch, phone filter sheets, sticky buy bar, swipe gallery. **Content's data:** hero, phone banners, service strip, top-bar text, footer, homepage order, retail and company blocks, points band words, projects, guides, client logos. Category photo exists but the shop's data doesn't carry it.
**Dropped by the owner's rule:** bundles, مختارات لك, save for later, share, price slider, availability filters, branches link, New and Offers pages, review translate, delivery-days line.
**Must keep, design omits:** staff view line and menu, back to panel, shopper lines, verify-email badge, country and language switches, bell, canonical and noindex, Not available now, greyed sizes, Last pieces, how sold, sale percent, cart notices and coupon, next gift, returns, buy again, phone verification, company per store. The consent switch has a backend but no shop screen.

**Tokens differ:** brand #0B3B63 vs #02365e, copper #8A5130 vs #864720, ink #14293A vs #0b2031, page #FFFFFF vs #f7f8f9; design has hero, footer, photo well colours with no token; cards use borders vs built shadow rings.

**Campaign parts today:** token: page background, header, category row, footer, badges, buttons, cards, prices, logo. Missing: announcement bar, hero, section bands, photo well, tab bar. No slot either side: flags, ribbons. Hardcoded: stars (amber), heart (red). `data-campaign` is wired in app.blade.php with only base values.

**Image slots at 375/641/820/1024/1400+:** hero free cover none/593x420/772x420/471x420/647x420; phone banner 2:1 347x174; category tile 4:3 187x140 to 315x236; home card 1:1 124/186/245/313/250; category card 1:1 142/287/377/338/246; product main 1:1 phone then 4:3, 307/593x445/772x579/472x354/816x612; thumbs 139 to 195; project 593x400 to 801x400; guides 593x190 to 501x191; client logo 180x78; cart line 84 to 96. Built photo sizes 200/600/1200/2400; built gallery is 1:1 vs design 4:3.

**Owner's calls still open:** card add button, stock counts and product codes (design shows; R1-9 and amendment 5(d) forbid); technical list (build or drop); adopt the design's colours into themes.css or keep current; flag and ribbon slots.

## Admin design against the built panel (2026-11-03, read only; only Orders read closely; design screens Campaigns, Stores, Inventory, Categories, Customers, Companies, Order detail not read)
Owner's rule: keep built, owner decides conflicts. **Open, sent to the owner 2026-11-03:** menu areas (design 15 short task rows vs built 16 role-editor areas; §14's tree follows the design); page ground (design grey page, built white); list layout in one card vs stacked; tabs (Orders is a pill, 7 pages underlined, counts); opening an order by whole row vs number only; language switch one press (frontend.md §2.2, §1.11 #3) vs inside the person menu; "All operational" line (design only); phone top bar (View Store icon-only, 44 px menu button, 20 px title, breakpoint 640 vs 768); phone tables stack vs scroll (rule mixed).
**Settled by docs, keep built:** colours (frontend.md §1.10 set B), radius, card edge, sidebar, top bar, page header, table heads, row actions, Show More, badges, fields, product editor actions, empty states.
