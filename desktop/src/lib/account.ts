// Your account from the app (contract 2.0.0-l, docs/API.md "Account and security from the apps"): the words the app
// says for each answer, in English and Urdu, the checks it makes before sending (the server still decides), and the
// proof each change asks for. The flows themselves are in views/main/AccountFlows.tsx, drawn by both designs.
import { api, type Account } from "./ipc";

/** One account action, answered whole (src-tauri my_account.rs `AccountReply`). */
export interface AccountReply<T = Record<string, unknown>> {
  ok: boolean;
  status: number;
  data: T | null;
  code: string | null;
  message: string | null;
  accepts: string[];
  errorCodes: Record<string, FieldCode[]> | null;
  errors: Record<string, string[]> | null;
  retryAfter: number | null;
  /** Two-step sign-in's QR code: rows of "1" (dark) and "0" (light). */
  qr: string[] | null;
}

/** One field error as a code (`{"code": "too_short", "min": 12}`, an image's `reason`). */
export interface FieldCode {
  code: string;
  min?: number;
  max?: number;
  reason?: string;
}

export type AccountAction =
  | "security" | "proof_code" | "name" | "avatar_remove" | "email" | "email_confirm" | "email_cancel" | "password"
  | "device_rename" | "device_sign_out" | "browser_sign_out" | "google_unlink" | "passkey_rename" | "passkey_remove"
  | "two_factor_begin" | "two_factor_confirm" | "recovery_codes" | "two_factor_off";

/** `GET /api/account/security`. */
export interface SecurityInfo {
  password: boolean;
  google: { linked: boolean; email: string | null };
  passkeys: { id: string; name: string; created_at: string | null; last_used_at: string | null }[];
  two_factor: { enabled: boolean; recovery_codes_left: number | null };
  browsers: { id: string; agent: string; country: string | null; last_active_at: string | null; current: boolean }[];
  devices: { id: string; name: string; platform: string; last_seen_at: string | null; current: boolean; status?: string; workspace?: { id: unknown; name: string } }[];
}

/** `POST /api/account/two-factor`. */
export interface TwoFactorStart {
  ceremony: string;
  secret: string;
  otpauth_uri: string;
  issuer?: string;
  label?: string;
  digits?: number;
  period?: number;
}

export const call = <T = Record<string, unknown>>(action: AccountAction, body?: Record<string, unknown> | null, id?: string) =>
  api.accountCall(action, body ?? null, id ?? null) as Promise<AccountReply<T>>;

// ---- Words --------------------------------------------------------------------------------------------------------

export type Lang = "en" | "ur";

/** The window's language for these words: Urdu when the page says so, else English. */
export function accountLang(): Lang {
  const lang = typeof document !== "undefined" ? document.documentElement.lang || "" : "";
  return lang.toLowerCase().startsWith("ur") ? "ur" : "en";
}

/** "15 minutes", "30 seconds". */
export function waitWords(seconds: number | null, lang: Lang = accountLang()): string {
  const s = seconds ?? 0;
  if (lang === "ur") return s >= 120 ? `${Math.ceil(s / 60)} منٹ` : s > 0 ? `${s} سیکنڈ` : "کچھ دیر";
  return s >= 120 ? `${Math.ceil(s / 60)} minutes` : s > 0 ? `${s} seconds` : "a few minutes";
}

type Words = Record<string, string>;

const EN: Words = {
  wrong_password: "That password isn't right.",
  invalid_otp: "That code didn't work. Type the 6-digit code your authenticator app shows now, or one of your recovery codes.",
  invalid_code: "That code didn't work. Check the email and type the 6-digit code.",
  proof_required: "Confirm it's you first.",
  email_taken: "Another account already uses this email.",
  expired: "This email change has ended. Start again.",
  already_enabled: "Two-step sign-in is already on.",
  not_enabled: "Two-step sign-in is off.",
  required_for_staff: "Staff accounts must keep two-step sign-in on.",
  last_sign_in_method: "Keep at least one way to sign in: set a password or add a passkey first.",
  passkey_not_found: "That passkey is no longer there.",
  device_not_found: "That device is no longer there.",
  browser_not_found: "That browser is no longer signed in.",
  current_device: "This is the computer you're using.",
  proof_code_not_needed: "Use your password or a two-step code instead.",
  too_large: "This picture is too large. Choose one under 5 MB.",
  server_busy: "The server is busy. Try again in a few seconds.",
  image_format: "This picture can't be read. Choose a JPEG, PNG or WebP photo.",
  image_broken: "This picture is damaged. Choose another photo.",
  image_too_small: "This picture is too small. Choose one at least 128 pixels wide and tall.",
  image_ratio: "This picture is too long and narrow. Choose one closer to a square.",
  image_too_many_pixels: "This picture is too big. Choose one under 25 megapixels.",
  field_required: "This can't be empty.",
  field_too_short: "Use at least {min} characters.",
  field_too_long: "Use {max} characters at most.",
  field_needs_letters: "Add at least one letter.",
  field_needs_numbers: "Add at least one number.",
  field_same_as_current: "Choose a password you don't use now.",
  field_invalid_characters: "Use letters, numbers, spaces and . ' - ( ) , only.",
  field_invalid_email: "Type a full email address, like name@example.com.",
  field_same_as_now: "This is already your email.",
  field_email_taken: "Another account already uses this email.",
  too_many_attempts: "Too many tries. Wait {wait}, then try again.",
  rate_limited: "That's a lot of changes for now. Try again in {wait}.",
};

const UR: Words = {
  wrong_password: "یہ پاس ورڈ درست نہیں۔",
  invalid_otp: "یہ کوڈ نہیں چلا۔ اپنی تصدیقی ایپ کا 6 ہندسوں والا موجودہ کوڈ لکھیں، یا کوئی ریکوری کوڈ۔",
  invalid_code: "یہ کوڈ نہیں چلا۔ ای میل دیکھ کر 6 ہندسوں والا کوڈ لکھیں۔",
  proof_required: "پہلے تصدیق کریں کہ یہ آپ ہیں۔",
  email_taken: "یہ ای میل کسی اور اکاؤنٹ میں استعمال ہو رہی ہے۔",
  expired: "ای میل کی تبدیلی ختم ہو گئی۔ دوبارہ شروع کریں۔",
  already_enabled: "دو مرحلوں والی سائن ان پہلے سے آن ہے۔",
  not_enabled: "دو مرحلوں والی سائن ان بند ہے۔",
  required_for_staff: "اسٹاف اکاؤنٹس میں دو مرحلوں والی سائن ان آن رہنی چاہیے۔",
  last_sign_in_method: "سائن ان کا کم از کم ایک طریقہ رکھیں: پہلے پاس ورڈ بنائیں یا پاس کی شامل کریں۔",
  passkey_not_found: "یہ پاس کی اب موجود نہیں۔",
  device_not_found: "یہ ڈیوائس اب موجود نہیں۔",
  browser_not_found: "یہ براؤزر اب سائن ان نہیں۔",
  current_device: "یہ وہی کمپیوٹر ہے جو آپ استعمال کر رہے ہیں۔",
  proof_code_not_needed: "اس کے بجائے اپنا پاس ورڈ یا دو مرحلوں والا کوڈ استعمال کریں۔",
  too_large: "یہ تصویر بہت بڑی ہے۔ 5 MB سے چھوٹی تصویر چنیں۔",
  server_busy: "سرور مصروف ہے۔ چند سیکنڈ بعد دوبارہ کوشش کریں۔",
  image_format: "یہ تصویر پڑھی نہیں جا سکتی۔ JPEG، PNG یا WebP تصویر چنیں۔",
  image_broken: "یہ تصویر خراب ہے۔ کوئی اور تصویر چنیں۔",
  image_too_small: "یہ تصویر بہت چھوٹی ہے۔ کم از کم 128 پکسل چوڑی اور اونچی تصویر چنیں۔",
  image_ratio: "یہ تصویر بہت لمبی اور پتلی ہے۔ چوکور کے قریب تصویر چنیں۔",
  image_too_many_pixels: "یہ تصویر بہت بڑی ہے۔ 25 میگا پکسل سے کم تصویر چنیں۔",
  field_required: "یہ خالی نہیں رہ سکتا۔",
  field_too_short: "کم از کم {min} حروف استعمال کریں۔",
  field_too_long: "زیادہ سے زیادہ {max} حروف استعمال کریں۔",
  field_needs_letters: "کم از کم ایک حرف شامل کریں۔",
  field_needs_numbers: "کم از کم ایک ہندسہ شامل کریں۔",
  field_same_as_current: "ایسا پاس ورڈ چنیں جو آپ ابھی استعمال نہیں کر رہے۔",
  field_invalid_characters: "صرف حروف، ہندسے، خالی جگہ اور . ' - ( ) , استعمال کریں۔",
  field_invalid_email: "پوری ای میل لکھیں، جیسے name@example.com۔",
  field_same_as_now: "یہ پہلے ہی آپ کی ای میل ہے۔",
  field_email_taken: "یہ ای میل کسی اور اکاؤنٹ میں استعمال ہو رہی ہے۔",
  too_many_attempts: "بہت زیادہ کوششیں۔ {wait} انتظار کریں، پھر دوبارہ کوشش کریں۔",
  rate_limited: "ابھی بہت زیادہ تبدیلیاں ہو گئیں۔ {wait} بعد دوبارہ کوشش کریں۔",
};

export const ACCOUNT_WORDS: Record<Lang, Words> = { en: EN, ur: UR };

const fill = (text: string, values: Record<string, string | number | undefined>) =>
  text.replace(/\{(\w+)\}/g, (_m, k: string) => String(values[k] ?? ""));

/** One field error in the app's words. */
export function fieldText(entry: FieldCode, lang: Lang = accountLang()): string | null {
  const words = ACCOUNT_WORDS[lang];
  if (entry.code === "invalid_image") return words[`image_${entry.reason ?? "format"}`] ?? words.image_format;
  const text = words[`field_${entry.code}`];
  return text ? fill(text, { min: entry.min, max: entry.max }) : null;
}

/** The field errors of a refusal, by field, in the app's words (the server's own sentence when a code is unknown). */
export function fieldErrors(reply: AccountReply<unknown>, lang: Lang = accountLang()): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [field, entries] of Object.entries(reply.errorCodes ?? {})) {
    const text = entries.map((e) => fieldText(e, lang)).find(Boolean);
    if (text) out[field] = text;
  }
  for (const [field, messages] of Object.entries(reply.errors ?? {})) {
    if (!out[field] && messages[0]) out[field] = messages[0];
  }
  return out;
}

/** The sentence for a refusal that is not a field's: the app's words for its code, else the core's sentence. */
export function replyText(reply: AccountReply<unknown>, lang: Lang = accountLang()): string {
  const words = ACCOUNT_WORDS[lang];
  const code = reply.code ?? "";
  if (code === "too_many_attempts" || code === "rate_limited") return fill(words[code], { wait: waitWords(reply.retryAfter, lang) });
  if (code === "invalid_image") return fieldErrors(reply, lang).image ?? words.image_format;
  if (code === "validation_failed") return Object.values(fieldErrors(reply, lang))[0] ?? reply.message ?? words.proof_required;
  return words[code] ?? reply.message ?? (lang === "ur" ? "سرور نے یہ قبول نہیں کیا۔" : "The server did not accept this.");
}

// ---- Checks before sending (the server still decides) ------------------------------------------------------------

export const NAME_MAX = 120;
export const PASSKEY_NAME_MAX = 80;

/**
 * A person's name as the server takes it (section 2): letters, marks and numbers of any script, spaces and
 * `. ' - ( ) ,`, with ZWNJ, ZWJ, LRM and RLM inside it (Urdu needs them). Returns a field code, or null when it is fine.
 */
export function nameProblem(name: string, max = NAME_MAX): FieldCode | null {
  const trimmed = name.trim();
  if (!trimmed) return { code: "required" };
  if ([...trimmed].length > max) return { code: "too_long", max };
  if (!/^[\p{L}\p{M}\p{N} .'\-(),‌‍‎‏]+$/u.test(trimmed)) return { code: "invalid_characters" };
  return null;
}

export interface PasswordRules {
  min: number;
  letters: boolean;
  numbers: boolean;
}

/** The server's rules until it says its own (`GET /api/auth/options` `password_rules`). */
export const DEFAULT_PASSWORD_RULES: PasswordRules = { min: 12, letters: true, numbers: true };

/** What a new password still needs, as field codes (none: it can be sent). */
export function passwordProblems(password: string, rules: PasswordRules = DEFAULT_PASSWORD_RULES): FieldCode[] {
  const out: FieldCode[] = [];
  if ([...password].length < rules.min) out.push({ code: "too_short", min: rules.min });
  if (rules.letters && !/\p{L}/u.test(password)) out.push({ code: "needs_letters" });
  if (rules.numbers && !/\p{N}/u.test(password)) out.push({ code: "needs_numbers" });
  return out;
}

/** The rules in one line, under the field. */
export function rulesLine(rules: PasswordRules, lang: Lang = accountLang()): string {
  if (lang === "ur") return `کم از کم ${rules.min} حروف${rules.letters ? "، حروف" : ""}${rules.numbers ? " اور ہندسوں کے ساتھ" : ""}۔`;
  const parts = [rules.letters && "letters", rules.numbers && "numbers"].filter(Boolean).join(" and ");
  return `At least ${rules.min} characters${parts ? `, with ${parts}` : ""}.`;
}

/** An email address that could be one (the server checks it properly). */
export const looksLikeEmail = (email: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim());

/** A 6-digit code or a recovery code, without spaces. */
export const cleanCode = (code: string) => code.replace(/\s+/g, "");

// ---- Proof ---------------------------------------------------------------------------------------------------------

/** How this account proves it is the person: a password, a two-step code (always when two-step is on), or a code by email. */
export type ProofKind = "password" | "otp" | "email_code";

/** The proof an account gives first: a two-step code when two-step is on; else its password; else a code by email. */
export function proofFor(account: Pick<Account, "two_factor"> & { has_password?: boolean | null }): ProofKind {
  if (account.two_factor) return "otp";
  return account.has_password === false ? "email_code" : "password";
}

/** The proof a refusal asks for instead (`proof_required` and `invalid_otp` say what this account accepts). */
export function proofAfter(reply: AccountReply<unknown>, now: ProofKind): ProofKind {
  if (reply.code !== "proof_required" && !(reply.code === "invalid_otp" && reply.accepts.length)) return now;
  const accepts = reply.accepts.filter((a): a is ProofKind => a === "password" || a === "otp" || a === "email_code");
  return accepts.includes(now) ? now : accepts[0] ?? now;
}

/** The proof as the body's fields. */
export function proofBody(kind: ProofKind, value: string): Record<string, string> {
  return kind === "password" ? { password: value } : { [kind]: cleanCode(value) };
}

/** A recovery code shown in two halves ("ab12 cd34") is easier to copy by hand. */
export function spacedSecret(secret: string): string {
  return secret.replace(/\s+/g, "").replace(/(.{4})(?=.)/g, "$1 ");
}
