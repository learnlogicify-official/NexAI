# Nex Portfolio (`local_nexportfolio`)

Codolio-style **Coding Portfolio** for Moodle 5.0+ / RemUI: connect LeetCode, CodeChef, Codeforces, GeeksforGeeks, and Coding Ninjas (Code360), fetch stats, and show a performance dashboard.

## Install

Use **`nexportfolio.zip`** from the repo root (folder inside zip must be named `nexportfolio`).

1. Site administration → Plugins → Install plugins → upload `nexportfolio.zip`
2. Complete upgrade (release **0.1.11**)
3. **Purge all caches**
4. Open **Coding Portfolio** (top menu or `/local/nexportfolio/index.php`)
5. Click **Refresh** on CodeChef (or Refresh all) so contest history reloads into Contest Participation

### Difficulty mapping (0.1.10)

All platforms expose `problemsByDifficulty` as **easy / medium / hard**:

| Platform | Source → EMH |
| --- | --- |
| LeetCode | Native easy / medium / hard |
| Coding Ninjas | easy; moderate→medium; hard + ninja→hard |
| GeeksforGeeks | school + basic + easy→easy; medium; hard |
| CodeChef | school / beginner / easy→easy; medium; hard / challenge→hard (when profile exposes buckets) |
| Codeforces | Unique solved by problem rating: ≤1200 easy, 1201–1600 medium, ≥1601 hard |

### LeetCode notes (0.1.9+)

- Contest **rating** from `contestRating`, global contest **rank** from `contestGlobalRanking` (not contribution ranking)
- **Current streak** vs **Max streak** (calendar-based)
- **Active days** = current calendar year only
- Contest history: name, rating, rank, solved/total
- Dashboard shows Problems Solved + Contest Participation + Platform Ratings cards

## Platforms

| Platform | How it fetches |
| --- | --- |
| Codeforces | Official API |
| LeetCode | Alfa LeetCode API (configurable) |
| CodeChef | Public profile HTML |
| GeeksforGeeks | Public profile HTML / embedded stats JSON |
| Coding Ninjas (Code360) | Public APIs using profile id from `naukri.com/code360/profile/<id>` |

For Coding Ninjas, paste the **profile id** (or full profile URL), not necessarily a display name. Optional admin setting: **Coding Ninjas proxy URL** (NexAcademy `/api/user/codingninjas-profile?username={username}`).

## Settings

Site administration → Plugins → Local plugins → **Nex Portfolio**

## AJAX

- `local_nexportfolio_save_handles`
- `local_nexportfolio_refresh_platform`
- `local_nexportfolio_get_portfolio`
