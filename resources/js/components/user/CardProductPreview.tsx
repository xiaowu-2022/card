import { cardDisplayName } from '@/lib/card-display-name';

export function CardProductPreview({
    name,
    currency,
    bin,
}: {
    name: string;
    currency: string;
    bin?: string;
}) {
    return (
        <div className="user-card-product-preview-wrap">
            <div className="user-card-visual user-card-product-preview">
                <p className="user-card-brand">
                    <svg viewBox="0 0 50 64" aria-hidden="true">
                        <path d="M0 26 24 10 49 26 25 44Z" fill="currentColor" />
                        <path
                            d="M0 32 24 49 49 31V44L24 62 0 45Z"
                            fill="currentColor"
                            opacity=".65"
                        />
                        <path d="M24 0 48 16 25 33 12 24 24 16 36 16Z" fill="currentColor" />
                    </svg>
                    <span>Spec Pay</span>
                </p>
                <div className="user-card-chip-row">
                    <img
                        src="/images/cards/gold-chip.svg"
                        alt=""
                        aria-hidden="true"
                        width="106"
                        height="84"
                    />
                    <div>
                        <h3 className="user-card-name">{cardDisplayName(name)}</h3>
                        <p className="user-card-edition">SPEC U CARD</p>
                    </div>
                </div>
                <div className="user-card-preview-footer">
                    <p>
                        {currency}
                        {bin ? ` · BIN ${bin}` : ''}
                    </p>
                    <img
                        src="/images/cards/mastercard.svg"
                        alt="Mastercard"
                        width="160"
                        height="100"
                    />
                </div>
            </div>
        </div>
    );
}
