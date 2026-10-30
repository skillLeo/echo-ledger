package com.echoledger.mobile.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.TranscriptViewsDto

/**
 * Show as (since v1.2): the transcript in its own words, or the Roman Urdu or English reading view the server wrote
 * (Claude). The views are labelled; the transcript itself never changes.
 */
enum class ShowAs(val key: String, val label: String) {
    ORIGINAL("original", "Original"),
    ROMAN("roman", "Roman Urdu"),
    ENGLISH("english", "English"),
    URDU("urdu", "اردو");

    companion object {
        fun of(key: String?): ShowAs = entries.firstOrNull { it.key == key } ?: ORIGINAL

        /** How often the page asks whether the views are written yet. */
        const val POLL_MS = 5_000L

        /** The text of each line in [view], aligned with [segments]; null for the original words or a view not ready. */
        fun lines(segments: List<SegmentDto>, view: ShowAs, views: TranscriptViewsDto?): List<String?>? {
            val ready = views?.takeIf { view != ORIGINAL && it.status == "ready" } ?: return null
            val texts = when (view) {
                ROMAN -> ready.roman
                ENGLISH -> ready.english
                else -> ready.urdu
            } ?: return null
            return segments.indices.map { texts.getOrNull(it) }
        }

        /** The line under the switch: who wrote the view, that it is being written, or why it failed. */
        fun status(view: ShowAs, views: TranscriptViewsDto?): String? {
            if (view == ORIGINAL) return null
            val by = views?.model?.let { " by $it" }.orEmpty()
            return when (views?.status) {
                "ready" -> "${view.label} view$by. The transcript itself is unchanged."
                "failed" -> "The view could not be made: ${views?.error ?: "try again later."}"
                else -> "Writing the ${view.label} view… this takes a minute or two."
            }
        }
    }
}

/** Roman Urdu · اردو · English · Original as one segmented control (only the views made for this language), and the status. */
@Composable
internal fun ShowAsRow(available: List<String>, selected: ShowAs, views: TranscriptViewsDto?, onPick: (ShowAs) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(Space.s1)) {
        val options = ShowAs.entries.filter { it != ShowAs.ORIGINAL && it.key in available } + ShowAs.ORIGINAL
        com.echoledger.mobile.ui.design.SegmentedControl(
            options.map { it.label },
            options.indexOf(selected).coerceAtLeast(0),
            { onPick(options[it]) }
        )
        ShowAs.status(selected, views)?.let {
            Text(
                it,
                color = Brand.Muted,
                fontSize = 12.sp,
                modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }
            )
        }
    }
}
