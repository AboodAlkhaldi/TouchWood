import type { Tone } from '@/lib/tones';

/*
| A company's status and an application's state, by meaning - one map for the staff screens and
| the shop, so a state is the same colour on both sides (owner, 2026-10-04, after looking at Geist's
| badge rule, Atlassian's lozenges and GitHub's labels):
| - approved: green, healthy;
| - waiting for a decision (Pending, Under Review): amber, something to see to;
| - rejected and suspended: red - a decision against, as Atlassian's "declined" and GitHub's closed
|   pull request are red;
| - anything else (a draft, a state a newer module adds): gray, neutral.
| The word is always on the badge; the colour never says it alone (Geist's Badge).
*/

export function companyStatusTone(status: string): Tone {
    switch (status) {
        case 'APPROVED':
            return 'green-subtle';
        case 'PENDING':
            return 'amber-subtle';
        case 'REJECTED':
        case 'SUSPENDED':
            return 'red-subtle';
        default:
            return 'gray-subtle';
    }
}

export function applicationStateTone(state: string): Tone {
    switch (state) {
        case 'APPROVED':
            return 'green-subtle';
        case 'SUBMITTED':
            return 'amber-subtle';
        case 'REJECTED':
            return 'red-subtle';
        default:
            return 'gray-subtle';
    }
}
