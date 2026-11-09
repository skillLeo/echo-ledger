package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import org.junit.Assert.assertEquals
import org.junit.Test

/** Phase 1C D1 on the phone (v1.6): Group by and Sort by, the desktop's choices. */
class LibraryGroupingTest {
    private fun item(
        id: String,
        day: String = "Today",
        started: String = "2026-11-09T09:00:00Z",
        pinned: Boolean = false,
        client: String? = null,
        app: String = "whatsapp",
        appLabel: String = "WhatsApp",
        final: String? = "completed",
        chip: String? = null,
        device: String? = null,
        duration: Long = 60_000
    ) = LibraryItem(
        id = id,
        title = id,
        sourceApp = app,
        sourceAppLabel = appLabel,
        startedAt = started,
        pinned = pinned,
        client = client?.let { NamedRef(it, it) },
        device = device?.let { NamedRef(it, it) },
        languageChip = chip,
        durationMs = duration,
        status = when (final) {
            "failed" -> "failed"
            "completed" -> "completed"
            else -> "processing"
        },
        transcript = TranscriptSummary(finalStatus = final),
        updatedAt = started,
        labels = listOf(day) // the test's day name, read by [day]
    )

    private val day: (LibraryItem) -> String = { it.labels.first() }

    private fun labels(groups: List<LibraryGrouping.Group<LibraryItem>>) = groups.map { it.label to it.items.map { i -> i.id } }

    @Test
    fun `by date pinned come first, then the days, newest days first`() {
        val items = listOf(
            item("p", "Earlier", "2026-10-01T09:00:00Z", pinned = true),
            item("a", "Today", "2026-11-09T09:00:00Z"),
            item("b", "Yesterday", "2026-11-08T09:00:00Z"),
            item("c", "Today", "2026-11-09T07:00:00Z")
        )
        val sorted = LibraryQuery.apply(items, LibraryFilters(), "", LibrarySort.NEWEST)
        assertEquals(
            listOf("Pinned" to listOf("p"), "Today" to listOf("a", "c"), "Yesterday" to listOf("b")),
            labels(LibraryGrouping.groups(sorted, GroupBy.DATE, LibrarySort.NEWEST, day))
        )
    }

    @Test
    fun `by date with Oldest the oldest day comes first, and Longest keeps the days in order`() {
        val items = listOf(
            item("a", "Today", "2026-11-09T09:00:00Z", duration = 10_000),
            item("b", "Yesterday", "2026-11-08T09:00:00Z", duration = 900_000),
            item("c", "Today", "2026-11-09T07:00:00Z", duration = 500_000)
        )
        val oldest = LibraryQuery.apply(items, LibraryFilters(), "", LibrarySort.OLDEST)
        assertEquals(listOf("Yesterday", "Today"), LibraryGrouping.groups(oldest, GroupBy.DATE, LibrarySort.OLDEST, day).map { it.label })
        val longest = LibraryQuery.apply(items, LibraryFilters(), "", LibrarySort.LONGEST)
        assertEquals(
            listOf("Today" to listOf("c", "a"), "Yesterday" to listOf("b")),
            labels(LibraryGrouping.groups(longest, GroupBy.DATE, LibrarySort.LONGEST, day))
        )
    }

    @Test
    fun `by project A to Z with No project last`() {
        val items = listOf(item("a"), item("b", client = "zeta"), item("c", client = "Acme"))
        assertEquals(
            listOf("Acme", "zeta", "No project"),
            LibraryGrouping.groups(items, GroupBy.PROJECT, LibrarySort.NEWEST, day).map { it.label }
        )
    }

    @Test
    fun `by status Needs you comes first, then Transcribing, then Ready`() {
        val items = listOf(item("a"), item("b", final = "processing"), item("c", final = "failed"))
        assertEquals(
            listOf("Needs you", "Transcribing", "Ready"),
            LibraryGrouping.groups(items, GroupBy.STATUS, LibrarySort.NEWEST, day).map { it.label }
        )
    }

    @Test
    fun `by app voice notes have one group, by language and device the chip and the device name`() {
        val items =
            listOf(item("a"), item("b", app = "voice_note", appLabel = "Voice note"), item("c", chip = "UR+EN", device = "Galaxy S25"))
        assertEquals(
            listOf("Voice notes", "WhatsApp"),
            LibraryGrouping.groups(items, GroupBy.APP, LibrarySort.NEWEST, day).map {
                it.label
            }
        )
        assertEquals(
            listOf("UR+EN", "Automatic"),
            LibraryGrouping.groups(items, GroupBy.LANGUAGE, LibrarySort.NEWEST, day).map {
                it.label
            }
        )
        assertEquals("Galaxy S25", LibraryGrouping.label(items[2], GroupBy.DEVICE))
        assertEquals("Unknown device", LibraryGrouping.label(items[0], GroupBy.DEVICE))
    }

    @Test
    fun `None is one group without a header, and nothing gives no groups`() {
        val items = listOf(item("a"), item("b"))
        assertEquals(listOf("" to listOf("a", "b")), labels(LibraryGrouping.groups(items, GroupBy.NONE, LibrarySort.NEWEST, day)))
        assertEquals(0, LibraryGrouping.groups(emptyList<LibraryItem>(), GroupBy.DATE, LibrarySort.NEWEST, day).size)
    }

    @Test
    fun `group keys stay the same while the group exists, so folding follows it`() {
        val groups = LibraryGrouping.groups(listOf(item("a", client = "Acme")), GroupBy.PROJECT, LibrarySort.NEWEST, day)
        assertEquals("project:Acme", groups.single().key)
    }

    @Test
    fun `a choice is remembered per view and an unknown word reads as the default`() {
        assertEquals("all", LibraryGrouping.viewKey(LibraryFilters()))
        assertEquals("today", LibraryGrouping.viewKey(LibraryFilters(views = setOf(SmartView.TODAY))))
        assertEquals("all", LibraryGrouping.viewKey(LibraryFilters(views = setOf(SmartView.TODAY, SmartView.FAILED))))
        val old = LibraryViewChoice(group = "colour", sort = "random", density = "tiny")
        assertEquals(GroupBy.DATE, old.groupBy)
        assertEquals(LibrarySort.NEWEST, old.sortBy)
        assertEquals(Density.COMFORTABLE, old.densityOf)
        val chosen = LibraryViewChoice.of(GroupBy.PROJECT, LibrarySort.OPENED, Density.COMPACT)
        assertEquals(LibraryViewChoice("project", "opened", "compact"), chosen)
    }

    @Test
    fun `Shortest and Recently opened sort as on the desktop`() {
        val items =
            listOf(
                item("a", duration = 300_000),
                item("b", duration = 20_000),
                item("c", duration = 90_000, started = "2026-11-09T11:00:00Z")
            )
        assertEquals(listOf("b", "c", "a"), LibraryQuery.apply(items, LibraryFilters(), "", LibrarySort.SHORTEST).map { it.id })
        val opened = mapOf("a" to 2_000L, "b" to 5_000L)
        assertEquals(
            listOf("b", "a", "c"),
            LibraryQuery.apply(items, LibraryFilters(), "", LibrarySort.OPENED, opened = opened).map {
                it.id
            }
        )
    }
}
