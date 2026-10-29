# The place lists loaded at the start

The migration `2026_10_29_200000_load_address_countries_and_places.php` loads these files once, into a
country that has no places yet; Super Admins edit them on the Places page from then on.

Format: `{country, regions: [{ar, en, cities: [{ar, en, districts: [{ar, en}]}]}]}`.

| File | Levels | Source | Licence |
|---|---|---|---|
| `places-SA.json` | 13 regions, 4,354 cities, 3,721 districts | [yasseralsamman/saudi-national-address](https://github.com/yasseralsamman/saudi-national-address) (`data/dist`, from SPL's National Address) | CC0-1.0 (the data) |
| `places-EG.json` | 27 governorates, 389 cities | [ahmed-ashraf-dv/egydata](https://github.com/ahmed-ashraf-dv/egydata) | MIT, Copyright (c) 2026 egydata |
| `places-AE.json` | 7 emirates | Wikidata labels | CC0 |
| `places-QA.json` | 8 municipalities | Wikidata labels | CC0 |
| `places-BH.json` | 4 governorates | Wikidata labels | CC0 |

Cleaned as the owner decided (2026-10-29): names trimmed; a name repeated under one parent dropped (the
first kept, or the one with districts) — 238 in Saudi Arabia; Saudi regions read "… Region" in English
so they never read as cities; UAE, Qatar and Bahrain stop at their first level (the customer types the
city), each row's type word the same. Oman is not on the list.
