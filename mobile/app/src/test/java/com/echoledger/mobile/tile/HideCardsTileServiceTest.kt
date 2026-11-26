package com.echoledger.mobile.tile

import android.content.ComponentName
import android.content.Intent
import android.content.pm.PackageManager
import android.service.quicksettings.Tile
import android.service.quicksettings.TileService
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.core.ShareGuardMemory
import com.echoledger.mobile.testsupport.AppTest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The Quick Settings tile "Hide EchoLedger windows" both ways. SystemUI binds the service for a tap and unbinds it about
 * 30 s after the panel closes, so a second tap can reach a new instance, with or without onStartListening first.
 */
class HideCardsTileServiceTest : AppTest() {
    /** A fresh binding of the tile service, as SystemUI makes one. */
    private fun bind(): HideCardsTileService = buildService(HideCardsTileService::class.java, Intent()).create().get()

    private fun HideCardsTileService.shows(state: Int, subtitle: String) {
        assertEquals(state, qsTile.state)
        assertEquals("Hide EchoLedger windows", qsTile.label)
        assertEquals(subtitle, qsTile.subtitle)
    }

    @Test
    fun `a tap hides the floating windows and a second tap shows them again`() {
        val tile = bind()
        tile.onStartListening()
        tile.shows(Tile.STATE_INACTIVE, "Shown")

        tile.onClick()
        assertTrue(ShareGuard.hiddenByUser.value)
        assertFalse(ShareGuard.textVisible.value)
        tile.shows(Tile.STATE_ACTIVE, "Hidden")

        tile.onClick()
        assertFalse(ShareGuard.hiddenByUser.value)
        assertTrue(ShareGuard.textVisible.value)
        tile.shows(Tile.STATE_INACTIVE, "Shown")
    }

    @Test
    fun `each tap may reach a new binding, listening or not`() {
        bind().apply {
            onStartListening()
            onClick()
            onStopListening()
        }
        assertTrue(ShareGuard.hiddenByUser.value)

        // Within 30 s of the panel closing the service is still bound: the tap comes without onStartListening.
        val second = bind()
        second.onClick()
        assertFalse(ShareGuard.hiddenByUser.value)
        second.shows(Tile.STATE_INACTIVE, "Shown")

        val third = bind()
        third.onStartListening()
        third.shows(Tile.STATE_INACTIVE, "Shown")
        third.onClick()
        third.shows(Tile.STATE_ACTIVE, "Hidden")
    }

    @Test
    fun `the tile shows what the recording controls and the dot did`() {
        ShareGuard.setHidden(true) // Hide EchoLedger windows on the recording controls
        val tile = bind()
        tile.onStartListening()
        tile.shows(Tile.STATE_ACTIVE, "Hidden")
        tile.onClick()
        assertFalse(ShareGuard.hiddenByUser.value)

        tile.onClick()
        ShareGuard.setHidden(false) // a tap on the grey dot
        tile.onStartListening() // the next time the panel opens
        tile.shows(Tile.STATE_INACTIVE, "Shown")
        tile.onClick()
        assertTrue("the tap after the dot hides them again", ShareGuard.hiddenByUser.value)
    }

    @Test
    fun `a tap while Android reports a screen share hides the windows, and the next one only gives back the choice`() {
        ShareGuard.setShared(true)
        assertFalse(ShareGuard.textVisible.value)
        val tile = bind()
        tile.onClick()
        tile.shows(Tile.STATE_ACTIVE, "Hidden")
        tile.onClick()
        tile.shows(Tile.STATE_INACTIVE, "Shown")
        assertFalse("still a dot until the screen share ends", ShareGuard.textVisible.value)
        ShareGuard.setShared(false)
        assertTrue(ShareGuard.textVisible.value)
    }

    /** Hide lasts the session (review of v1.8.1): after a restart the tile reads Shown, and the setting is still kept. */
    @Test
    fun `after a new process the tile shows Shown, because Hide lasts the session only`() {
        ShareGuard.restore(ShareGuardMemory(app))
        ShareGuard.setHideLive(false)
        bind().apply {
            onStartListening()
            onClick()
        }
        assertTrue(ShareGuard.hiddenByUser.value)

        ShareGuard.resetForTest() // the process ends (a cached kill, a crash, an update)
        ShareGuard.restore(ShareGuardMemory(app)) // EchoLedgerApp of the next process

        val tile = bind()
        tile.onStartListening()
        tile.shows(Tile.STATE_INACTIVE, "Shown")
        assertTrue(ShareGuard.textVisible.value)
        assertEquals("the setting outlives the process", false, ShareGuard.hideLive.value)
        tile.onClick()
        tile.shows(Tile.STATE_ACTIVE, "Hidden")
    }

    @Test
    fun `the tile is an on-off switch for TalkBack, named Hide EchoLedger windows`() {
        val info = app.packageManager.getServiceInfo(ComponentName(app, HideCardsTileService::class.java), PackageManager.GET_META_DATA)
        assertTrue(info.metaData.getBoolean(TileService.META_DATA_TOGGLEABLE_TILE))
        assertEquals("Hide EchoLedger windows", info.loadLabel(app.packageManager).toString())
    }
}
