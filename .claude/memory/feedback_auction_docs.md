---
name: Auction API documentation rule
description: When modifying Auction APIs, always update payload docs and Postman collections in Modules/Auction/payload/ and Modules/Auction/postman/ version folders
type: feedback
---

When creating or modifying Auction APIs (v1, v2, v3) or Auction AI APIs, MUST also update:
1. Corresponding `.md` payload documentation in `Modules/Auction/payload/{v1,v2,v3,ai}/`
2. Corresponding Postman collection in `Modules/Auction/postman/{v1,v2,v3,ai}/`

**Why:** User centralized all API docs on 2026-04-06 to maintain consistency between code, documentation, and Postman collections. Previously files were scattered across `Modules/Auction/routes/rest_api/payload/` and `Modules/AI/`.

**How to apply:** After any Auction API change, check if the relevant payload `.md` and Postman `.json` exist in the correct version folder and update them to reflect the new behavior.
