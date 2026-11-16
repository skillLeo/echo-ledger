// The words of each sample screen's loading, empty and error states, in English and Urdu.
import type { Lang } from "./content";

export type ScreenState = "ready" | "loading" | "empty" | "error";

type StateCopy = {
  library: { loading: string; emptyTitle: string; emptyBody: string; errorTitle: string; errorBody: string; retry: string };
  recording: {
    loading: string; emptyTitle: string; emptyBody: string; transcribe: string;
    errorTitle: string; errorBody: string; retry: string; untranscribed: string;
  };
  settings: {
    loading: string; emptyQuery: string; emptyTitle: string; emptyBody: string; clear: string;
    errorTitle: string; errorBody: string; retry: string;
  };
};

export const STATE_COPY: Record<Lang, StateCopy> = {
  en: {
    library: {
      loading: "Loading recordings",
      emptyTitle: "No recordings yet",
      emptyBody: "Calls and meetings you record on this computer or your phone, and files you import, appear here.",
      errorTitle: "The library didn't load",
      errorBody: "EchoLedger couldn't reach the server. Recordings saved on this computer are safe.",
      retry: "Try again",
    },
    recording: {
      loading: "Loading the recording",
      emptyTitle: "Not transcribed yet",
      emptyBody: "Transcribe this call to read, search and share what was said. 18 minutes take about 2 minutes.",
      transcribe: "Transcribe",
      errorTitle: "This recording didn't open",
      errorBody: "It may have been deleted on another device, or the server is out of reach.",
      retry: "Try again",
      untranscribed: "Untranscribed",
    },
    settings: {
      loading: "Loading your account",
      emptyQuery: "caller tune",
      emptyTitle: "No settings match",
      emptyBody: "Try another word, such as language, storage or captions.",
      clear: "Clear search",
      errorTitle: "Your account didn't load",
      errorBody: "Settings on this computer still work. Your plan and profile show once the server answers.",
      retry: "Try again",
    },
  },
  ur: {
    library: {
      loading: "ریکارڈنگز لوڈ ہو رہی ہیں",
      emptyTitle: "ابھی کوئی ریکارڈنگ نہیں",
      emptyBody: "اس کمپیوٹر یا فون پر ریکارڈ کی گئی کالز اور میٹنگز، اور درآمد کی گئی فائلیں یہاں نظر آئیں گی۔",
      errorTitle: "لائبریری لوڈ نہیں ہوئی",
      errorBody: "ایکو لیجر سرور تک نہیں پہنچ سکا۔ اس کمپیوٹر پر محفوظ ریکارڈنگز محفوظ ہیں۔",
      retry: "دوبارہ کوشش کریں",
    },
    recording: {
      loading: "ریکارڈنگ لوڈ ہو رہی ہے",
      emptyTitle: "ابھی ٹرانسکرپٹ نہیں بنا",
      emptyBody: "جو کچھ کہا گیا اسے پڑھنے، تلاش کرنے اور شیئر کرنے کے لیے یہ کال ٹرانسکرائب کریں۔ 18 منٹ میں تقریباً 2 منٹ لگتے ہیں۔",
      transcribe: "ٹرانسکرائب کریں",
      errorTitle: "یہ ریکارڈنگ نہیں کھلی",
      errorBody: "ہو سکتا ہے یہ کسی اور ڈیوائس پر حذف ہو گئی ہو، یا سرور تک رسائی نہ ہو۔",
      retry: "دوبارہ کوشش کریں",
      untranscribed: "ٹرانسکرپٹ باقی",
    },
    settings: {
      loading: "آپ کا اکاؤنٹ لوڈ ہو رہا ہے",
      emptyQuery: "کالر ٹیون",
      emptyTitle: "کوئی ترتیب نہیں ملی",
      emptyBody: "کوئی اور لفظ آزمائیں، جیسے زبان، اسٹوریج یا کیپشنز۔",
      clear: "تلاش صاف کریں",
      errorTitle: "آپ کا اکاؤنٹ لوڈ نہیں ہوا",
      errorBody: "اس کمپیوٹر کی ترتیبات کام کرتی رہیں گی۔ سرور کے جواب دینے پر آپ کا پلان اور پروفائل نظر آئے گا۔",
      retry: "دوبارہ کوشش کریں",
    },
  },
};
