---
paths:
  - 'app/Actions/GrantWalletEntry.php,app/Actions/EnterPicks.php,app/Actions/MakePick.php,app/Actions/SettleSlate.php'
---

# Actions Actions

## The Tallboy supply: ask the key before the balance, and a full cooler still writes a row
The weekly top-off (GrantWalletEntry::topOff) computes its AMOUNT from the balance, so the week key is checked FIRST — key-first, a second fire computes nothing; balance-first, it computes a number and then finds it has nothing to write, which is a grant whose value depends on when it lost a race.

A FULL COOLER STILL SPENDS THE KEY, at zero credits. Returning early without writing lets a reader holding six spend two and come straight back for a restock the same week, and again after that. The zero row is what makes the key the cap rather than the payment.

Rung-ups are SWEPT on the Picks visit, cumulative, not fired at the moment of promotion: the rung is a pure function of the XP total, so a sweep is exactly as complete as an eager hook and costs no extra SUM per entrant in the settlement job. The visit hook fires from mount(), never render() — the key stops it paying twice, nothing stops it asking twice.

Participation milestones count SATURDAYS, not slate_entries. Somebody in five groups seats five slates on one weekend; counting entries hands them the ten-week milestone before Halloween.

Every supply number is a constant in GrantWalletEntry so a rebalance is a deploy, and TallboySupplyTest reds when either top-off guard is broken back.
