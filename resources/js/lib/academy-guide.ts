export const academyGuide = [
    {
        id: 'assets',
        title: 'Asset overview',
        intro: 'Manage your balances, deposits, transfers and wealth savings in one place.',
        items: [
            {
                title: 'Total asset valuation',
                body: 'The overview combines your security deposit, USDT, USDC, ETH and BTC wallets, wealth principal and card balances into a USDT estimate. Crypto values change with available market prices; the estimate is not your spendable USDT balance.',
            },
            {
                title: 'Depositing funds',
                body: 'You can deposit USDT, USDC, ETH and BTC without a platform deposit fee. Select the correct network and send the exact amount shown on your deposit order. Security deposits, annual membership fees and card funding use USDT; other assets can be exchanged into USDT after arrival.',
            },
            {
                title: 'Withdrawals and arrival times',
                body: 'For example, a company may charge 10% on USDT and USDC withdrawals and no fee on ETH and BTC. Check the fee and net amount before confirming. Next-day arrival is an estimate and depends on processing and network confirmation. Internal account transfers are fee-free and settle immediately when successful.',
            },
            {
                title: 'Exchanging into USDT',
                body: 'USDC, ETH and BTC can be exchanged into USDT. The reverse direction is not supported. Check the current quote and the USDT amount before confirming; USDC also uses the displayed quote rather than a guaranteed 1:1 rate.',
            },
            {
                title: 'Transfers by account ID',
                body: 'Every registered account has its own account ID. Choose USDT, USDC, ETH or BTC, enter a recipient in the same company and verify the recipient and amount. Confirm with your account password to make a fee-free transfer in the selected asset.',
            },
            {
                title: 'Security deposit and activation',
                body: 'An ordinary member can activate by funding the required security deposit, for example 300 USDT. An effective paid annual membership also qualifies without a separate deposit. Deposit refunds follow the waiting period, for example 30 days, and return to the available wallet once the refund conditions are met. Identity and card eligibility checks still apply.',
            },
            {
                title: 'Fixed-term wealth savings',
                body: 'Choose an asset and a term. Monthly interest is paid automatically into the matching wallet. Early redemption returns the whole principal minus interest already paid. For contracts with automatic renewal, redeem after maturity and before the next midnight in the company timezone; otherwise the principal renews for the same term and rate, while paid interest stays in your wallet. Check your order for its maturity rules.',
            },
        ],
    },
    {
        id: 'cards',
        title: 'Using your card',
        intro: 'From opening a card to everyday payments and activity records.',
        items: [
            {
                title: 'Opening a card',
                body: 'After activation and required verification, choose a card product and prepare its opening fee plus initial funding. For a 2 USDT opening fee and 20 USDT initial funding, prepare at least 22 USDT, plus any fee shown in the quote. Use a phone number and email that can receive messages; payment verification codes often arrive by email.',
            },
            {
                title: 'Reading the card details',
                body: 'The masked number ends with the last four digits of your card. An expiry of 11/28 means November 2028. Select View and enter your platform account password to reveal the full number and three-digit CVV. Keep these details and verification codes private.',
            },
            {
                title: 'Linking to WeChat or Alipay',
                body: 'If the payment app supports your card, open its bank-card section and add the card number, expiry and CVV. Follow the prompts for your payment password and any email verification code. After linking, select the card when paying. Initial or larger payments may require another code; availability depends on the app and card.',
            },
            {
                title: 'Card funding and returns',
                body: 'Card funding moves USDT from your wallet to the card. For example, a product may require a minimum of 20 per reload. A supported card return moves funds back into your USDT wallet. Check the minimum, fee and available amount on the action page; completion is shown in the order result.',
            },
            {
                title: 'Activity and more options',
                body: 'Activity below each card shows the funding and spending records saved by the platform. Open More to manage supported card settings, including the linked email and phone number.',
            },
        ],
    },
    {
        id: 'account',
        title: 'Your account and invitations',
        intro: 'Find your profile, membership level and team information.',
        items: [
            {
                title: 'Profile and membership upgrades',
                body: 'Me shows your nickname, linked email and account ID, alongside your current level. Select Upgrade to see eligible higher levels and the annual-fee difference. The page shows the available options and conditions.',
            },
            {
                title: 'Promotion and invitation posters',
                body: 'The promotion center shows your current level and commission details. You can save a poster with your invitation code and share it with new users. Registration requires a valid invitation code.',
            },
            {
                title: 'Team and performance data',
                body: 'Invitation data brings together your team and performance information so you can follow invitation activity and progress.',
            },
            {
                title: 'Learning in U Card Academy',
                body: 'U Card Academy is the entry point for feature guides, registration and invitation information, and referral reward rules.',
            },
        ],
    },
] as const;
