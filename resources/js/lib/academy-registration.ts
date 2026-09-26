export const academyRegistration = [
    {
        id: 'invitation',
        title: 'Prepare your invitation code',
        intro: 'Register through a valid invitation to preserve the referral relationship.',
        items: [
            {
                title: 'An invitation code is required',
                body: 'Ask your inviter for their registration link or invitation code. New users must provide a valid code to register. Check the code before completing registration so the account is linked to the correct inviter.',
            },
        ],
    },
    {
        id: 'verification',
        title: 'Register and verify your identity',
        intro: 'Use an email address you can reliably access.',
        items: [
            {
                title: 'Register with your email',
                body: 'Enter an email address you use regularly, receive and enter the verification code, then complete the required account information and set your password. Keep access to this mailbox: it is used for account verification and password recovery.',
            },
            {
                title: 'Complete identity verification after registration',
                body: 'After registration, open identity verification and follow the document requirements shown. For a mainland China national ID, provide clear photos of both sides; for a passport, provide the information page. Enter information that matches the document and check the verification result. Registration alone does not mean identity verification is complete.',
            },
        ],
    },
    {
        id: 'activation',
        title: 'Choose how to activate',
        intro: 'Activate with a security deposit or an effective paid agent membership.',
        items: [
            {
                title: 'Option 1: activate as an ordinary member',
                body: 'Registration creates your account; activation is a separate step. An ordinary member can fund the required security deposit, for example 300 USDT, to activate. If you stop using the account, you can apply for a deposit refund, subject to the waiting period and refund conditions. Identity, account and card eligibility requirements still apply.',
            },
            {
                title: 'Option 2: purchase an annual membership',
                body: 'You can also choose an available paid agent level directly. Once payment succeeds and the membership is effective, it satisfies the activation requirement without a separate 300 USDT deposit. See the current level chart in Reward rules for annual fees, activation rewards, annual-fee commission rates and return targets.',
            },
        ],
    },
    {
        id: 'upgrade',
        title: 'Upgrade from ordinary member to agent',
        intro: 'Use eligible deposit funds toward the annual fee and pay the remainder.',
        items: [
            {
                title: 'Convert your deposit toward the annual fee',
                body: 'An ordinary member can open the promotion center to choose an available agent level. The confirmation page applies eligible security-deposit funds up to the amount due, then charges the remainder from the USDT wallet. For example, with a 300 USDT deposit and a 5000 USDT annual fee, 300 is converted and 4700 is paid from the wallet. Check the actual split before confirming.',
            },
            {
                title: 'New benefits apply after the upgrade takes effect',
                body: 'After successful payment and activation of the new level, future qualifying referrals use the new level\u2019s reward terms. Existing settled rewards are not recalculated. Available levels, upgrade eligibility and payment conditions are shown in the promotion center.',
            },
        ],
    },
] as const;
