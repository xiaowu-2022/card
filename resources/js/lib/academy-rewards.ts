export const academyRewards = [
    {
        id: 'levels',
        title: 'Membership benefits at a glance',
        intro: 'Compare activation rewards, annual-fee rates and return targets.',
        items: [
            {
                title: 'Two types of commission',
                body: 'Ordinary members receive a direct activation reward only. Effective paid agents can receive both ordinary-member activation commissions and annual-fee commissions.',
            },
            {
                title: 'How to read the examples',
                body: 'For example, Level 1 may offer 50 per activation and a 30% direct annual-fee rate; Level 6 may offer 100 and 80%, while Level 8 may offer 120 per activation. Always compare these examples with the current chart.',
            },
        ],
    },
    {
        id: 'activation',
        title: 'Ordinary-member activation rewards',
        intro: 'Only the first qualifying activation earns an activation commission.',
        items: [
            {
                title: 'Ordinary members: direct invitations only',
                body: 'An ordinary member earns 20 when a directly invited user first activates through a security deposit. Indirect invitations earn no activation reward, and inviting a paid agent earns no annual-fee commission for an ordinary member.',
            },
            {
                title: 'Paid agents: positive reward differences',
                body: 'A direct activation uses your effective level\u2019s reward. For indirect activations, you receive only the positive difference above rewards already covered along the invitation chain. A lower or equal reward amount leaves no difference for you; you do not receive the full direct reward again.',
            },
            {
                title: 'Example: Level 6 and Level 4',
                body: 'If your Level 6 reward is 100 and your direct Level 4 agent\u2019s reward is 80, a qualifying new activation referred by that agent pays them 80 and you the remaining 20. Deeper team activations use the same covered-reward calculation; 20 is not guaranteed for every team member.',
            },
            {
                title: 'First activation is recorded once',
                body: 'An account that first activates by paying an annual fee generates annual-fee commission, not a second activation reward. Later deposits, refunds followed by new deposits, upgrades and renewals do not create another first activation. Confirmed commissions are credited to the USDT available balance.',
            },
        ],
    },
    {
        id: 'annual',
        title: 'Agent annual-fee rewards',
        intro: 'Direct rewards use your own rate; indirect rewards use the eligible rate difference.',
        items: [
            {
                title: 'Direct invitations: your full rate',
                body: 'You must hold an effective paid agent membership. A directly invited agent may have a lower, equal or higher level: the commission uses your own annual-fee rate without subtracting the invited agent\u2019s rate. The base is the amount actually paid from the wallet; converted security deposit is excluded.',
            },
            {
                title: 'Direct examples at a 60% rate',
                body: 'Suppose your Level 4 annual fee is 10,000 and your reward rate is 60%. A direct invitee paying 5,000 entirely from their wallet earns you 3,000. A higher-level direct invitee paying 50,000 entirely from their wallet earns you 30,000.',
            },
            {
                title: 'Indirect invitations: eligibility comes first',
                body: 'For an indirect annual-fee commission, your effective level must be at least the level purchased by the payer. You then receive only the positive difference between your rate and the highest rate already covered by eligible agents below you. If either condition fails, there is no indirect reward. A lower-level agent can still earn on their own direct invitation to a higher level, but an indirect ancestor below the payer\u2019s purchased level cannot.',
            },
            {
                title: 'Example: a 10 percentage-point difference',
                body: 'Assume your rate is 60%, the eligible agent below you has 50%, and you meet the payer-level requirement. On a 5,000 wallet payment, that agent receives 2,500 and you receive 500: 5,000 \u00d7 (60% \u2212 50%). If lower agents already cover 80%, a 60% rate leaves no positive difference. Upgrading can affect future qualifying rewards, not past settled commissions.',
            },
        ],
    },
    {
        id: 'returns',
        title: 'Automatic annual-fee returns',
        intro: 'Follow the weighted first-activation progress within your active annual cycle.',
        items: [
            {
                title: 'Direct counts as 1; indirect counts as 0.5',
                body: 'Each eligible first activation contributes 1 for a direct invitee or 0.5 for an indirect invitee at depths 2\u20135. Counting stops after the fifth generation. Both a first security-deposit activation and a first paid-membership activation can count; each account counts only once. Repeat funding, upgrades and renewals do not add progress.',
            },
            {
                title: 'Equal or higher levels exclude the branch',
                body: 'At the first activation, the system records the levels along the invitation path. An equal or higher-level node and the branch below it are excluded from your return count. Later level changes do not recalculate recorded progress. Upgrading changes eligibility only for future first activations. This five-generation rule governs return progress, not commission depth.',
            },
            {
                title: 'Meeting the target',
                body: 'Weighted progress equals eligible direct activations plus half of eligible indirect activations. For a target of 100, 100 direct activations, 200 indirect activations, or 40 direct plus 120 indirect activations all reach the target. These are examples; use your own cycle\u2019s target and eligible counts.',
            },
            {
                title: 'Check your return progress',
                body: 'The promotion center shows your cycle\u2019s progress and return status. Reaching the target triggers an automatic return of the paid annual fee that has not already been returned, including any eligible converted deposit. No manual application is needed. Processing or account issues may delay arrival; check the recorded return status and wallet activity.',
            },
        ],
    },
] as const;
