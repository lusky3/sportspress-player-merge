# Duplicate detection — signals found in live data

**Date:** 2026-09-04
**Source:** a full production copy of rookiehockey.ca (2,215 players, 2,459 users,
8,794 orders), analysed read-only on `arl-local`.
**Why:** duplicate players and duplicate accounts surfaced while auditing the
`sp_user` player↔user link. The methods that found them are recorded here
because **this tool does not currently use most of them.**

## What the matcher uses today

`SP_Merge_Name_Matcher` is sophisticated about names — nickname canonicalisation,
accent folding, surname normalisation, French compounds, Levenshtein on both the
given and family name, and it strips trailing parenthesised annotations
(`(G)`, `(C)`, `(dup)`, `(Dup / Div 3)`).

It is also **name-only**. A grep across `class-sp-merge-name-matcher.php` for
`spt_email`, `billing_`, `sp_user` and `_customer_user` returns **zero** hits.
Every duplicate below was found using a signal the tool cannot currently see.

## Measured on live data

| Method | Result |
|---|---|
| Normalised-name grouping (what the tool does) | 79 duplicate-name groups, 162 player records |
| Name grouping **ignoring trailing digits** | catches `David Strike` / `David Strike2`, which plain normalisation misses |
| **Billing address + postcode, then name similarity** | **47 duplicate ACCOUNT pairs**, cleanly separated from **104 family pairs** |
| Billing phone | 79 shared-phone groups |

### The separation is unusually clean

Grouping users by normalised `billing_address_1` + `billing_postcode`, then
scoring the two names against each other, produced a bimodal result with nothing
in between:

- **Same address + name score 1.00** → 47 pairs, every one a genuine duplicate
  account (same person, personal vs work email).
- **Same address + name score 0.00** → 104 pairs, every one a household — a
  spouse, a parent, a sibling.

No pair landed between 0.00 and 1.00. Address alone is *not* a duplicate signal —
it is a **household** signal. Address **plus** name similarity is a duplicate
signal, and a strong one.

### Worked example: the case that defeated every other method

One person, discovered while investigating why an automated link failed:

| | |
|---|---|
| User 1270 | `dstrike@cogeco.ca`, 13 orders, 2018→2024 |
| User 1391 | `dstrike@daemarinc.com`, 2 orders, 2018 only |
| Both | `2372 Sinclair circle, Burlington L7P3C3` |
| Phone | `9058022360` vs `9058022368` — one digit apart |
| Player 8922 | `David Strike`, 33 seasons incl. current, `spt_email` = `acdc0824@gmail.com` |
| Player 93412 | `David Strike2`, 2 dormant seasons, no email |

**Two accounts, two player records, three email addresses, one human.**

- Email matching failed — all three addresses differ.
- Name matching returned two equally-scoring candidates and correctly refused to guess.
- **Billing address resolved it immediately**, and order history said which
  account is live (13 orders vs 2, six years vs four months).

## Recommendations

### 1. Treat bare trailing digits as a housekeeping suffix

`preprocess()` strips parenthesised annotations but not a bare trailing digit.
Run against the real matcher:

```
David Strike  vs David Strike2   -> {"match":true,"certainty":60,"scenario":"nickname+typo"}
Cody Lusk     vs Cody Lusk (G)   -> {"match":true,"certainty":100,"scenario":"exact"}
```

`David Strike2` **is** caught, but at 60% as a *typo*. It is not a typo — it is
the same housekeeping convention as `(dup)`, just written without brackets. It
should preprocess to `David Strike` and score as `exact`. A review queue ordered
by certainty currently buries this class of duplicate below real typos.

Suggested: extend the strip to `/\s*\d{1,2}\s*$/` after the parenthetical strip,
bounded to one or two digits so a legitimate name ending in a number is not
mangled. Verify against the roster first — no current title appears to rely on a
trailing digit as part of the real name.

### 2. Add corroborating signals, as tie-breakers rather than matchers

Names alone cannot separate `Lane Mikalski` from `Kory Mikalski` (correctly
rejected) or decide between two identical `David strike` accounts. Both need a
second axis:

| Signal | Where | Use |
|---|---|---|
| `spt_email` on the player | postmeta | exact match ⇒ strong confirm |
| `sp_user` → user's `user_email` / `billing_email` | postmeta + usermeta | exact match ⇒ strong confirm |
| `billing_address_1` + `billing_postcode` | usermeta / order meta | same address **and** similar name ⇒ duplicate; same address **and** dissimilar name ⇒ **household, suppress** |
| `billing_phone` | usermeta / order meta | near-match (edit distance 1) ⇒ confirm; the Strike pair differ by one digit |
| Order history | `_customer_user` | decides which record survives — most recent and most orders wins |
| Season overlap | `sp_season` terms | a record active for 33 seasons is the keeper over one dormant since 2022 |

The household finding is the important one and cuts **against** naive merging:
104 of 151 same-address pairs are different people. An address-only rule would
have merged a hundred families.

### 3. Surface a "same person, different records" confidence, not just a name score

The current output is `{match, certainty, scenario}` derived from the name. With
the signals above the tool could report *why* it believes two records are one
person — "names identical, same billing address, phone differs by one digit,
one record dormant since 2022" — which is what an operator needs to approve a
destructive merge quickly and safely.

### 4. Duplicate accounts are a separate, unaddressed problem

This tool merges `sp_player` records. The 47 pairs found here are duplicate
**WordPress users**, which nothing currently addresses. They matter because the
`sp_user` link points at a user: if a player links to the abandoned account, every
downstream consumer — profile pictures, discipline notices, GDPR export and
erasure — follows the wrong one. Worth deciding whether that belongs here, in
player-tools, or nowhere yet.

## Method, reproducible

Every figure above came from read-only SQL plus the theme's own name scorer
(`blueline_name_match_score()`), deliberately reusing an existing matcher rather
than writing a second one. The queries are straightforward:

- duplicate players: normalise `post_title` (strip parentheticals, digits and
  non-letters), `GROUP BY ... HAVING COUNT(*) > 1`
- duplicate accounts: normalise `billing_address_1` + `billing_postcode` per
  user, group, then score names pairwise within each group
- survivorship: count orders per `_customer_user`, and count `sp_season` terms
  per player record

**Caveat worth keeping:** these numbers are one league's data at one point in
time. The clean 1.00/0.00 split may be an artifact of this roster's naming
conventions rather than a general property. Re-measure before hard-coding any
threshold derived from it.
