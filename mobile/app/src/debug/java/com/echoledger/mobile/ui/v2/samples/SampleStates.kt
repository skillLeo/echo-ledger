package com.echoledger.mobile.ui.v2.samples

import com.echoledger.mobile.ui.v2.theme.EchoLanguage

/** Which state a sample screen shows. */
enum class SampleState { Content, Empty, Loading, Error }

/** The words of the empty, loading and error states, in English and Urdu. */
data class SampleStateCopy(
    val noRecordingsTitle: String,
    val noRecordingsBody: String,
    val setUpRecording: String,
    val libraryErrorTitle: String,
    val libraryErrorBody: String,
    val tryAgain: String,
    val untranscribed: String,
    val failed: String,
    val noTranscriptTitle: String,
    val noTranscriptBody: String,
    val transcribe: String,
    val transcriptFailedTitle: String,
    val transcriptFailedBody: String,
    val accountError: String,
    val settingsQuery: String,
    val noSettingsTitle: String,
    val noSettingsBody: String
) {
    companion object {
        fun of(language: EchoLanguage) = if (language == EchoLanguage.Urdu) Urdu else English

        val English = SampleStateCopy(
            noRecordingsTitle = "No recordings yet",
            noRecordingsBody = "WhatsApp calls, phone calls and voice notes you record show up here.",
            setUpRecording = "Set up recording",
            libraryErrorTitle = "Couldn't load your recordings",
            libraryErrorBody = "Check the internet connection. Recordings on this phone are safe.",
            tryAgain = "Try again",
            untranscribed = "Untranscribed",
            failed = "Failed",
            noTranscriptTitle = "No transcript yet",
            noTranscriptBody = "Transcribing this 12:08 call uses 13 of your 88 remaining minutes.",
            transcribe = "Transcribe",
            transcriptFailedTitle = "The transcript failed",
            transcriptFailedBody = "The speech engines could not transcribe it this time. Your audio is safe: try again.",
            accountError = "Couldn't load your account and plan. Settings on this phone still work.",
            settingsQuery = "VPN",
            noSettingsTitle = "No settings match \"VPN\"",
            noSettingsBody = "Try another word, such as language, lock or theme."
        )

        val Urdu = SampleStateCopy(
            noRecordingsTitle = "ابھی کوئی ریکارڈنگ نہیں",
            noRecordingsBody = "آپ کی ریکارڈ کی ہوئی واٹس ایپ کالز، فون کالز اور وائس نوٹس یہاں نظر آئیں گے۔",
            setUpRecording = "ریکارڈنگ سیٹ اپ کریں",
            libraryErrorTitle = "ریکارڈنگز لوڈ نہیں ہو سکیں",
            libraryErrorBody = "انٹرنیٹ کنکشن چیک کریں۔ اس فون پر موجود ریکارڈنگز محفوظ ہیں۔",
            tryAgain = "دوبارہ کوشش کریں",
            untranscribed = "ٹرانسکرپٹ باقی",
            failed = "ناکام",
            noTranscriptTitle = "ابھی ٹرانسکرپٹ نہیں بنا",
            noTranscriptBody = "اس 12:08 منٹ کی کال کے ٹرانسکرپٹ میں آپ کے باقی 88 میں سے 13 منٹ لگیں گے۔",
            transcribe = "ٹرانسکرپٹ بنائیں",
            transcriptFailedTitle = "ٹرانسکرپٹ نہیں بن سکا",
            transcriptFailedBody = "اس بار اسپیچ انجن اسے ٹرانسکرائب نہیں کر سکے۔ آپ کی آڈیو محفوظ ہے: دوبارہ کوشش کریں۔",
            accountError = "آپ کا اکاؤنٹ اور پلان لوڈ نہیں ہو سکا۔ اس فون کی ترتیبات کام کر رہی ہیں۔",
            settingsQuery = "وی پی این",
            noSettingsTitle = "\"وی پی این\" سے کوئی ترتیب نہیں ملی",
            noSettingsBody = "کوئی اور لفظ آزمائیں، جیسے زبان، لاک یا تھیم۔"
        )
    }
}
