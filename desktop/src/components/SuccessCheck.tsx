import { CheckIcon } from "./ControlIcons";

/**
 * A thing just worked (since v1.5, the phone's SuccessCheck): a recording kept, a message that confirms something, a
 * share made. The check pops in; with reduced motion it is simply there (styles.css).
 */
export function SuccessCheck({ size = 16 }: { size?: number }) {
  return <span className="success-check" data-testid="success-check" aria-hidden="true"><CheckIcon size={size} /></span>;
}
