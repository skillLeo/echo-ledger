// The EchoLedger mark (docs/design/brand): a voice's wave that dies down into the first ledger line, above two more
// lines, white on brand indigo. The brand's own drawing, not an icon: it keeps its 64-unit grid and stroke, and its
// indigo stays the brand's even where the window takes the system's accent colour. Below 24 px it is the small mark
// (one line fewer, bolder strokes), as mark-small.svg.
export const MARK_WAVE =
  "M13.00 24.00C13.25 23.07 14.00 19.82 14.50 18.43C15.00 17.04 15.50 16.09 16.00 15.66C16.50 15.22 17.00 15.39 17.50 15.82C18.00 16.25 18.50 17.24 19.00 18.22C19.50 19.20 20.00 20.56 20.50 21.70C21.00 22.83 21.50 24.09 22.00 25.03C22.50 25.97 23.00 26.81 23.50 27.34C24.00 27.87 24.50 28.14 25.00 28.20C25.50 28.26 26.00 28.02 26.50 27.70C27.00 27.38 27.50 26.81 28.00 26.28C28.50 25.76 29.00 25.09 29.50 24.55C30.00 24.01 30.50 23.45 31.00 23.05C31.50 22.65 32.00 22.33 32.50 22.15C33.00 21.97 33.50 21.92 34.00 21.96C34.50 22.00 35.00 22.19 35.50 22.39C36.00 22.59 36.50 22.89 37.00 23.16C37.50 23.43 38.25 23.86 38.50 24.00H51";
export const BRAND_INDIGO = "#3E63DD";
/** The ledger lines of the mark and of the small mark. */
export const MARK_LINES = "M13 38h38M13 49h22";
export const SMALL_MARK_LINES = "M13 45h26";

export function BrandMark({ size = 24, className = "brand-mark" }: { size?: number; className?: string }) {
  const small = size < 24;
  return (
    <svg className={className} width={size} height={size} viewBox="0 0 64 64" aria-hidden="true" focusable="false" data-mark={small ? "small" : "full"}>
      <rect width="64" height="64" rx="15" fill={BRAND_INDIGO} />
      <g fill="none" stroke="#fff" strokeWidth={small ? 6.5 : 5.5} strokeLinecap="round" strokeLinejoin="round">
        <path d={MARK_WAVE} transform={small ? "translate(0 5)" : undefined} />
        <path d={small ? SMALL_MARK_LINES : MARK_LINES} />
      </g>
    </svg>
  );
}

/** The mark and the name, as the brand's wordmark: Inter 600 with tight tracking, the text about 0.7 of the mark. */
export function Wordmark({ size = 24 }: { size?: number }) {
  return (
    <div className="wordmark">
      <BrandMark size={size} />
      <span className="brand-name">EchoLedger</span>
    </div>
  );
}
