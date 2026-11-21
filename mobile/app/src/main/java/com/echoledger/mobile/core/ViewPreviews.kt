package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscriptViewsDto

/**
 * A Library row's snippet in the transcript's default view (U19: Roman Urdu for an Urdu recording) once that view is
 * ready. The server's `preview` is the original words, so the phone reads the view's first line itself, once per
 * transcript and view, and keeps it for the rows. Pure; unit-tested.
 */
object ViewPreviews {
    /** "transcriptId:roman" for a finished transcript whose default view is ready; null when the row keeps its preview. */
    fun key(item: LibraryItem): String? {
        val t = item.transcript ?: return null
        val id = t.finalTranscriptId ?: return null
        val views = t.views ?: return null
        if (t.finalStatus != "completed" || views.status != "ready" || views.default == "original" || views.default.isBlank()) return null
        return "$id:${views.default}"
    }

    /** The first line with words in [view] of [views], trimmed to the server's preview length (160); null when it has none. */
    fun firstLine(views: TranscriptViewsDto, view: String): String? {
        if (views.status != "ready") return null
        val lines = when (view) {
            "roman" -> views.roman
            "english" -> views.english
            "urdu" -> views.urdu
            else -> null
        } ?: return null
        val line = lines.firstOrNull { !it.isNullOrBlank() }?.trim() ?: return null
        return if (line.length > 160) line.take(159).trimEnd() + "…" else line
    }

    /** [items] with each row's snippet in its default view where [previews] has it (by [key]). */
    fun apply(items: List<LibraryItem>, previews: Map<String, String>): List<LibraryItem> {
        if (previews.isEmpty()) return items
        return items.map { item ->
            val line = key(item)?.let(previews::get) ?: return@map item
            item.copy(transcript = item.transcript?.copy(preview = line))
        }
    }
}
