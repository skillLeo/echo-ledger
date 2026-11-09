package com.echoledger.mobile.core

import com.echoledger.mobile.core.ServerBanners.Kind
import com.echoledger.mobile.data.AnnouncementDto
import com.echoledger.mobile.data.AppUpdateDto
import com.echoledger.mobile.data.MaintenanceDto
import java.time.Instant
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** v1.6 P2.7: the banners an update, maintenance and the admin's announcement put at the top of the Library. */
class ServerBannersTest {
    private val now = Instant.parse("2026-11-09T10:00:00Z")

    @Test
    fun `nothing from the server shows nothing`() {
        assertTrue(ServerBanners.of(ServerNotices(), emptyList(), now).isEmpty())
    }

    @Test
    fun `an update says the server's words, and a refused version says the recordings wait`() {
        val asked = ServerBanners.of(
            ServerNotices(appUpdate = AppUpdateDto("1.6.0", required = true, message = "Please update EchoLedger to 1.6.0 or later.")),
            emptyList(),
            now
        )
        assertEquals(Kind.UPDATE, asked.single().kind)
        assertEquals("Please update EchoLedger to 1.6.0 or later.", asked.single().text)
        assertTrue(asked.single().warning)
        val refused = ServerBanners.of(ServerNotices(appUpdate = AppUpdateDto("1.6.0", required = true, refused = true)), emptyList(), now)
        assertEquals(ServerBanners.REFUSED, refused.single().text)
    }

    @Test
    fun `maintenance shows until its end, then goes`() {
        val on = ServerNotices(maintenance = MaintenanceDto("Back at 11:00.", until = "2026-11-09T11:00:00Z"))
        assertEquals("Back at 11:00.", ServerBanners.of(on, emptyList(), now).single().text)
        assertTrue(ServerBanners.of(on, emptyList(), Instant.parse("2026-11-09T11:00:01Z")).isEmpty())
        assertEquals(
            ServerBanners.MAINTENANCE_FALLBACK,
            ServerBanners.of(ServerNotices(maintenance = MaintenanceDto()), emptyList(), now).single().text
        )
    }

    @Test
    fun `an announcement can be closed, and a new text shows again`() {
        val notice = ServerNotices(announcement = AnnouncementDto("New: plans and badges.", "info"))
        val shown = ServerBanners.of(notice, emptyList(), now).single()
        assertEquals(Kind.ANNOUNCEMENT, shown.kind)
        assertEquals(false, shown.warning)
        assertTrue(ServerBanners.of(notice, listOf(shown.key!!), now).isEmpty())
        val next = ServerNotices(announcement = AnnouncementDto("Maintenance on Sunday.", "warning"))
        assertEquals(true, ServerBanners.of(next, listOf(shown.key!!), now).single().warning)
    }

    @Test
    fun `an announcement past its end is not shown, and the order is update, maintenance, announcement`() {
        val old = ServerNotices(announcement = AnnouncementDto("Old news", until = "2026-11-01T00:00:00Z"))
        assertTrue(ServerBanners.of(old, emptyList(), now).isEmpty())
        val all = ServerNotices(AppUpdateDto("1.6.0"), AnnouncementDto("Hello"), MaintenanceDto("Down"))
        assertEquals(listOf(Kind.UPDATE, Kind.MAINTENANCE, Kind.ANNOUNCEMENT), ServerBanners.of(all, emptyList(), now).map { it.kind })
    }
}
