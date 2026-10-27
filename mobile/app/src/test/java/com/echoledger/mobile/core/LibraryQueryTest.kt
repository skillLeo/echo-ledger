package com.echoledger.mobile.core

import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.NamedRef
import com.echoledger.mobile.data.TranscriptSummary
import java.time.ZoneId
import java.time.ZonedDateTime
import org.junit.Assert.assertEquals
import org.junit.Test

class LibraryQueryTest {
    private val now = ZonedDateTime.of(2026, 10, 28, 12, 0, 0, 0, ZoneId.of("UTC"))

    private fun item(
        id: String,
        device: String = "phone",
        started: String = "2026-10-28T09:00:00Z",
        pinned: Boolean = false,
        client: String? = null,
        duration: Long = 60_000,
        final: String? = "completed",
        error: String? = null
    ) = LibraryItem(
        id = id,
        title = id,
        sourceDevice = device,
        startedAt = started,
        pinned = pinned,
        client = client?.let { NamedRef(it, it) },
        durationMs = duration,
        status = if (final == "failed") "failed" else "completed",
        transcript = TranscriptSummary(finalStatus = final, finalErrorCode = error),
        updatedAt = started
    )

    private val items = listOf(
        item("a", device = "phone", started = "2026-10-28T09:00:00Z", client = "acme"),
        item("b", device = "desktop", started = "2026-10-20T09:00:00Z", duration = 600_000),
        item("c", device = "web", started = "2026-10-27T09:00:00Z", pinned = true),
        item("d", device = "phone", started = "2026-10-26T09:00:00Z", final = "failed", error = "no_audio")
    )

    @Test
    fun `boxes in one group add up and groups narrow each other`() {
        val phoneOrDesktop = LibraryFilters(devices = setOf(SourceDevice.PHONE, SourceDevice.DESKTOP))
        assertEquals(listOf("a", "d", "b"), LibraryQuery.apply(items, phoneOrDesktop, "", LibrarySort.NEWEST, now).map { it.id })
        val phoneToday = phoneOrDesktop.copy(views = setOf(SmartView.TODAY))
        assertEquals(listOf("a"), LibraryQuery.apply(items, phoneToday, "", LibrarySort.NEWEST, now).map { it.id })
        assertEquals(3, phoneToday.count)
    }

    @Test
    fun `no client and a client folder can be ticked together`() {
        val f = LibraryFilters(folders = setOf("", "acme"))
        assertEquals(4, LibraryQuery.apply(items, f, "", LibrarySort.NEWEST, now).size)
        assertEquals(
            listOf("a"),
            LibraryQuery.apply(items, LibraryFilters(folders = setOf("acme")), "", LibrarySort.NEWEST, now).map {
                it.id
            }
        )
    }

    @Test
    fun `every sort keeps pinned recordings on top`() {
        assertEquals(listOf("c", "a", "d", "b"), LibraryQuery.apply(items, LibraryFilters(), "", LibrarySort.NEWEST, now).map { it.id })
        assertEquals(listOf("c", "b", "d", "a"), LibraryQuery.apply(items, LibraryFilters(), "", LibrarySort.OLDEST, now).map { it.id })
        assertEquals("b", LibraryQuery.apply(items, LibraryFilters(), "", LibrarySort.LONGEST, now)[1].id)
        assertEquals(listOf("c", "a", "b", "d"), LibraryQuery.apply(items, LibraryFilters(), "", LibrarySort.TITLE, now).map { it.id })
    }

    @Test
    fun `a recording without sound is No audio, not Failed`() {
        assertEquals(ItemStatus.NO_AUDIO, ItemStatus.of(items[3]))
        assertEquals(ItemStatus.FAILED, ItemStatus.of(item("x", final = "failed", error = "provider_rejected")))
        assertEquals(ItemStatus.READY, ItemStatus.of(items[0]))
        assertEquals(ItemStatus.PROCESSING, ItemStatus.of(item("y", final = "processing").copy(status = "processing")))
    }

    @Test
    fun `search still narrows the filtered list`() {
        assertEquals(listOf("a"), LibraryQuery.apply(items, LibraryFilters(), "ACME", LibrarySort.NEWEST, now).map { it.id })
    }
}
