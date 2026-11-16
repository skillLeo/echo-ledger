package com.echoledger.mobile.ui.v2.samples

import androidx.compose.ui.graphics.vector.ImageVector
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoPlayerLabels
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.theme.EchoLanguage

/** The source of a sample recording. */
enum class SampleSource(val icon: ImageVector) {
    WhatsApp(EchoIcons.WhatsApp),
    WhatsAppBusiness(EchoIcons.WhatsAppBusiness),
    PhoneCall(EchoIcons.PhoneCall),
    Meeting(EchoIcons.Meeting),
    VoiceNote(EchoIcons.VoiceNote)
}

data class SampleRecording(
    val source: SampleSource,
    val title: String,
    val meta: String,
    val time: String,
    val durationMs: Long,
    val status: Pair<String, EchoStatusKind>? = null,
    val today: Boolean = true,
    val transcribed: Boolean = true
)

data class SampleLine(val speaker: String, val time: String, val text: String, val isMe: Boolean)

data class SampleSetting(val title: String, val value: String? = null, val subtitle: String? = null, val switch: Boolean? = null)

data class SampleGroup(val title: String, val rows: List<SampleSetting>)

/**
 * Real-looking content for the Gate 3 sample screens, in English and Urdu: Pakistani businesses, real durations,
 * Latin digits for times and durations in both languages.
 */
data class SampleContent(
    val language: EchoLanguage,
    // Navigation and Library
    val recordings: String,
    val searchRecordings: String,
    val filterToday: String,
    val filterUntranscribed: String,
    val filterStarred: String,
    val filterVoiceNotes: String,
    val home: String,
    val library: String,
    val live: String,
    val ask: String,
    val accountAndSettings: String,
    val sourceNames: Map<SampleSource, String>,
    val items: List<SampleRecording>,
    // Recording page
    val recordingTitle: String,
    val recordingMeta: String,
    val transcribed: String,
    val tabs: List<String>,
    val share: String,
    val move: String,
    val delete: String,
    val player: EchoPlayerLabels,
    val transcript: List<SampleLine>,
    // Settings
    val settings: String,
    val searchSettings: String,
    val profileName: String,
    val profileEmail: String,
    val plan: String,
    val usage: String,
    val upgrade: String,
    val groups: List<SampleGroup>,
    val ready: String
) {
    companion object {
        fun of(language: EchoLanguage) = if (language == EchoLanguage.Urdu) Urdu else English

        private fun min(m: Int, s: Int) = (m * 60L + s) * 1000L

        val English = SampleContent(
            language = EchoLanguage.English,
            recordings = "Recordings",
            searchRecordings = "Search recordings",
            filterToday = "Today",
            filterUntranscribed = "Untranscribed",
            filterStarred = "Starred",
            filterVoiceNotes = "Voice notes",
            home = "Home",
            library = "Library",
            live = "Live",
            ask = "Ask",
            accountAndSettings = "Account and settings",
            sourceNames = mapOf(
                SampleSource.WhatsApp to "WhatsApp call",
                SampleSource.WhatsAppBusiness to "WhatsApp Business call",
                SampleSource.PhoneCall to "Phone call",
                SampleSource.Meeting to "Meeting",
                SampleSource.VoiceNote to "Voice note"
            ),
            items = listOf(
                SampleRecording(
                    SampleSource.WhatsApp,
                    "Bilal Traders",
                    "New rate for 40 cartons of basmati, delivery Thursday",
                    "10:42",
                    min(12, 8)
                ),
                SampleRecording(
                    SampleSource.PhoneCall,
                    "Rehman & Sons Textiles",
                    "Phone call",
                    "10:05",
                    min(4, 31),
                    "Untranscribed" to EchoStatusKind.Neutral,
                    transcribed = false
                ),
                SampleRecording(
                    SampleSource.Meeting,
                    "Al-Noor Logistics weekly",
                    "Port Qasim backlog cleared, two trucks short",
                    "09:00",
                    min(48, 15)
                ),
                SampleRecording(
                    SampleSource.VoiceNote,
                    "Voice note",
                    "GST return for the Karachi branch is due Friday",
                    "08:17",
                    min(1, 24)
                ),
                SampleRecording(
                    SampleSource.WhatsAppBusiness,
                    "Shaheen Pharma Distributors",
                    "Invoice PO-2291, payment in 45 days",
                    "Yesterday",
                    min(21, 37),
                    today = false
                ),
                SampleRecording(
                    SampleSource.PhoneCall,
                    "Mehran Steel Works",
                    "Phone call",
                    "Yesterday",
                    min(7, 2),
                    "Failed" to EchoStatusKind.Danger,
                    today = false,
                    transcribed = false
                ),
                SampleRecording(
                    SampleSource.WhatsApp,
                    "Dr. Farah Naz Clinic",
                    "Audit team booked for Thursday at 4 pm",
                    "Yesterday",
                    min(3, 52),
                    today = false
                ),
                SampleRecording(
                    SampleSource.Meeting,
                    "Qureshi Builders site review",
                    "Block C slab poured; cement rate up Rs 60 a bag",
                    "Mon",
                    min(1, 6, 40),
                    today = false
                ),
                SampleRecording(
                    SampleSource.WhatsApp,
                    "Karachi Cargo Services",
                    "Two containers held at customs until Monday",
                    "Mon",
                    min(9, 18),
                    today = false
                )
            ),
            recordingTitle = "Bilal Traders",
            recordingMeta = "WhatsApp call · Today, 10:42",
            transcribed = "Transcribed",
            tabs = listOf("Transcript", "Summary", "Ask", "Moments", "Details"),
            share = "Share",
            move = "Move",
            delete = "Delete",
            player = EchoPlayerLabels("Play", "Pause", "Back 10 seconds", "Forward 10 seconds", "Position", "Playback speed"),
            transcript = listOf(
                SampleLine(
                    "Bilal Ahmed",
                    "0:04",
                    "Assalam o alaikum Ayesha. Please send the new rate for forty cartons of basmati.",
                    false
                ),
                SampleLine(
                    "Ayesha (you)",
                    "0:11",
                    "Walaikum assalam. It is Rs 4,200 a carton now, delivered to your Hyderabad godown by Thursday.",
                    true
                ),
                SampleLine(
                    "Bilal Ahmed",
                    "0:26",
                    "Fine. Payment in 45 days as usual, and put our PO number on the invoice this time.",
                    false
                ),
                SampleLine("Ayesha (you)", "0:38", "Of course. I will WhatsApp the quotation before six this evening.", true),
                SampleLine("Bilal Ahmed", "0:47", "One more thing: hold ten cartons for the Sukkur order, we confirm on Monday.", false)
            ),
            settings = "Settings",
            searchSettings = "Search settings",
            profileName = "Ayesha Siddiqui",
            profileEmail = "ayesha@bilaltraders.pk",
            plan = "Starter plan",
            usage = "212 of 300 minutes used this month",
            upgrade = "Upgrade",
            groups = listOf(
                SampleGroup(
                    "Recording",
                    listOf(
                        SampleSetting("Auto-record sources", "WhatsApp, phone calls"),
                        SampleSetting("After-call action", "Show card"),
                        SampleSetting("Auto-transcribe after calls", subtitle = "Uses minutes from your plan", switch = true)
                    )
                ),
                SampleGroup(
                    "Transcription & language",
                    listOf(
                        SampleSetting("Transcript language", "Urdu and English"),
                        SampleSetting("App language", "English"),
                        SampleSetting("Vocabulary", "38 words")
                    )
                ),
                SampleGroup(
                    "Privacy & data",
                    listOf(
                        SampleSetting("App lock", "Fingerprint"),
                        SampleSetting("Keep recordings", "90 days")
                    )
                ),
                SampleGroup(
                    "Appearance",
                    listOf(
                        SampleSetting("Theme", "System"),
                        SampleSetting("Classic look", switch = false)
                    )
                ),
                SampleGroup("Setup & health", listOf(SampleSetting("Capture helper"))),
                SampleGroup("About & help", listOf(SampleSetting("Version", "2.0.0")))
            ),
            ready = "Ready"
        )

        val Urdu = SampleContent(
            language = EchoLanguage.Urdu,
            recordings = "ریکارڈنگز",
            searchRecordings = "ریکارڈنگز تلاش کریں",
            filterToday = "آج",
            filterUntranscribed = "ٹرانسکرپٹ باقی",
            filterStarred = "پسندیدہ",
            filterVoiceNotes = "وائس نوٹس",
            home = "ہوم",
            library = "لائبریری",
            live = "لائیو",
            ask = "پوچھیں",
            accountAndSettings = "اکاؤنٹ اور ترتیبات",
            sourceNames = mapOf(
                SampleSource.WhatsApp to "واٹس ایپ کال",
                SampleSource.WhatsAppBusiness to "واٹس ایپ بزنس کال",
                SampleSource.PhoneCall to "فون کال",
                SampleSource.Meeting to "میٹنگ",
                SampleSource.VoiceNote to "وائس نوٹ"
            ),
            items = listOf(
                SampleRecording(SampleSource.WhatsApp, "بلال ٹریڈرز", "باسمتی کے 40 کارٹن کا نیا ریٹ، ڈیلیوری جمعرات", "10:42", min(12, 8)),
                SampleRecording(
                    SampleSource.PhoneCall,
                    "رحمان اینڈ سنز ٹیکسٹائل",
                    "فون کال",
                    "10:05",
                    min(4, 31),
                    "ٹرانسکرپٹ باقی" to EchoStatusKind.Neutral,
                    transcribed = false
                ),
                SampleRecording(
                    SampleSource.Meeting,
                    "النور لاجسٹکس، ہفتہ وار میٹنگ",
                    "پورٹ قاسم کا بیک لاگ ختم، دو ٹرک کم",
                    "09:00",
                    min(48, 15)
                ),
                SampleRecording(SampleSource.VoiceNote, "وائس نوٹ", "کراچی برانچ کا جی ایس ٹی ریٹرن جمعہ تک", "08:17", min(1, 24)),
                SampleRecording(
                    SampleSource.WhatsAppBusiness,
                    "شاہین فارما ڈسٹری بیوٹرز",
                    "انوائس PO-2291، ادائیگی 45 دن میں",
                    "کل",
                    min(21, 37),
                    today = false
                ),
                SampleRecording(
                    SampleSource.PhoneCall,
                    "مہران اسٹیل ورکس",
                    "فون کال",
                    "کل",
                    min(7, 2),
                    "ناکام" to EchoStatusKind.Danger,
                    today = false,
                    transcribed = false
                ),
                SampleRecording(SampleSource.WhatsApp, "ڈاکٹر فرح ناز کلینک", "آڈٹ ٹیم جمعرات شام 4 بجے", "کل", min(3, 52), today = false),
                SampleRecording(
                    SampleSource.Meeting,
                    "قریشی بلڈرز، سائٹ کا جائزہ",
                    "بلاک سی کی چھت ڈل گئی، سیمنٹ 60 روپے مہنگا",
                    "پیر",
                    min(1, 6, 40),
                    today = false
                ),
                SampleRecording(
                    SampleSource.WhatsApp,
                    "کراچی کارگو سروسز",
                    "دو کنٹینر پیر تک کسٹمز میں رکے ہیں",
                    "پیر",
                    min(9, 18),
                    today = false
                )
            ),
            recordingTitle = "بلال ٹریڈرز",
            recordingMeta = "واٹس ایپ کال · آج، 10:42",
            transcribed = "ٹرانسکرپٹ تیار",
            tabs = listOf("ٹرانسکرپٹ", "خلاصہ", "پوچھیں", "لمحات", "تفصیلات"),
            share = "شیئر",
            move = "منتقل کریں",
            delete = "حذف کریں",
            player = EchoPlayerLabels("چلائیں", "روکیں", "10 سیکنڈ پیچھے", "10 سیکنڈ آگے", "مقام", "رفتار"),
            transcript = listOf(
                SampleLine("بلال احمد", "0:04", "السلام علیکم عائشہ صاحبہ۔ باسمتی کے چالیس کارٹن کا نیا ریٹ بھیج دیں۔", false),
                SampleLine(
                    "عائشہ (آپ)",
                    "0:11",
                    "وعلیکم السلام۔ اب ریٹ 4,200 روپے فی کارٹن ہے، جمعرات تک حیدرآباد گودام پہنچ جائے گا۔",
                    true
                ),
                SampleLine(
                    "بلال احمد",
                    "0:26",
                    "ٹھیک ہے۔ ادائیگی ہمیشہ کی طرح 45 دن میں، اور اس بار انوائس پر ہمارا PO نمبر ضرور لکھیں۔",
                    false
                ),
                SampleLine("عائشہ (آپ)", "0:38", "جی بالکل۔ آج شام چھ بجے سے پہلے کوٹیشن واٹس ایپ کر دیتی ہوں۔", true),
                SampleLine("بلال احمد", "0:47", "ایک بات اور: سکھر والے آرڈر کے لیے دس کارٹن روک لیں، پیر کو کنفرم کریں گے۔", false)
            ),
            settings = "ترتیبات",
            searchSettings = "ترتیبات تلاش کریں",
            profileName = "عائشہ صدیقی",
            profileEmail = "ayesha@bilaltraders.pk",
            plan = "اسٹارٹر پلان",
            usage = "اس مہینے 300 میں سے 212 منٹ استعمال ہوئے",
            upgrade = "اپ گریڈ کریں",
            groups = listOf(
                SampleGroup(
                    "ریکارڈنگ",
                    listOf(
                        SampleSetting("خودکار ریکارڈنگ کے ذرائع", "واٹس ایپ، فون کالز"),
                        SampleSetting("کال کے بعد", "کارڈ دکھائیں"),
                        SampleSetting("کال کے بعد خودکار ٹرانسکرپٹ", subtitle = "آپ کے پلان کے منٹ استعمال ہوتے ہیں", switch = true)
                    )
                ),
                SampleGroup(
                    "ٹرانسکرپشن اور زبان",
                    listOf(
                        SampleSetting("ٹرانسکرپٹ کی زبان", "اردو اور انگریزی"),
                        SampleSetting("ایپ کی زبان", "اردو"),
                        SampleSetting("خاص الفاظ", "38 الفاظ")
                    )
                ),
                SampleGroup(
                    "رازداری اور ڈیٹا",
                    listOf(
                        SampleSetting("ایپ لاک", "فنگر پرنٹ"),
                        SampleSetting("ریکارڈنگز کب تک رکھیں", "90 دن")
                    )
                ),
                SampleGroup(
                    "ظاہری شکل",
                    listOf(
                        SampleSetting("تھیم", "سسٹم"),
                        SampleSetting("پرانا ڈیزائن", switch = false)
                    )
                ),
                SampleGroup("سیٹ اپ اور جانچ", listOf(SampleSetting("کیپچر ہیلپر"))),
                SampleGroup("معلومات اور مدد", listOf(SampleSetting("ورژن", "2.0.0")))
            ),
            ready = "تیار"
        )

        private fun min(h: Int, m: Int, s: Int) = ((h * 60L + m) * 60L + s) * 1000L
    }
}
