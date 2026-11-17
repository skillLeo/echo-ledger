package com.echoledger.mobile.ui.v2

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary

/** Library items as the server sends them, with real-looking Pakistani businesses and durations (UTC times). */
object V2Fixtures {
    const val NOW = "2026-11-17T12:00:00+05:00"

    private fun item(
        id: String,
        title: String?,
        app: String,
        started: String,
        durationMs: Long,
        final: String?,
        preview: String? = null,
        client: String? = null,
        screens: Int? = null
    ) = LibraryItem(
        id = id,
        sourceDevice = "phone",
        sourceApp = app,
        title = title,
        client = client?.let { NamedRef("01JCLIENT$id", it) },
        startedAt = started,
        durationMs = durationMs,
        status = if (final == "failed") "failed" else "completed",
        transcript = final?.let { TranscriptSummary(finalStatus = it, finalTranscriptId = "01JT$id", preview = preview) },
        updatedAt = started,
        screenCount = screens
    )

    fun library(urdu: Boolean = false): List<LibraryItem> {
        fun t(en: String, ur: String) = if (urdu) ur else en
        return listOf(
            item(
                "01JA",
                t("Bilal Traders", "بلال ٹریڈرز"),
                "whatsapp",
                "2026-11-17T05:42:00Z",
                728_000,
                "completed",
                t("New rate for 40 cartons of basmati, delivery Thursday", "باسمتی کے 40 کارٹن کا نیا ریٹ، ڈیلیوری جمعرات")
            ),
            item("01JB", t("Rehman & Sons Textiles", "رحمان اینڈ سنز ٹیکسٹائل"), "phone_call", "2026-11-17T05:05:00Z", 271_000, null),
            item(
                "01JC",
                t("Al-Noor Logistics weekly", "النور لاجسٹکس، ہفتہ وار میٹنگ"),
                "google_meet",
                "2026-11-17T04:00:00Z",
                2_895_000,
                "processing",
                screens = 14
            ),
            item(
                "01JD",
                t("Shaheen Pharma Distributors", "شاہین فارما ڈسٹری بیوٹرز"),
                "whatsapp_business",
                "2026-11-16T16:20:00Z",
                1_297_000,
                "completed",
                t("Invoice PO-2291, payment in 45 days", "انوائس PO-2291، ادائیگی 45 دن میں")
            ),
            item("01JE", t("Mehran Steel Works", "مہران اسٹیل ورکس"), "phone_call", "2026-11-16T09:10:00Z", 422_000, "failed"),
            item(
                "01JF",
                null,
                "voice_note",
                "2026-11-14T03:17:00Z",
                84_000,
                "completed",
                t("GST return for the Karachi branch is due Friday", "کراچی برانچ کا جی ایس ٹی ریٹرن جمعہ تک")
            ),
            item(
                "01JG",
                t("Qureshi Builders site review", "قریشی بلڈرز، سائٹ کا جائزہ"),
                "zoom",
                "2026-11-02T10:00:00Z",
                4_000_000,
                "completed",
                client = t("Qureshi Builders", "قریشی بلڈرز"),
                screens = 6
            )
        )
    }
}
