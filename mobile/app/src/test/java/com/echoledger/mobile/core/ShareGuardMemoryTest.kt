package com.echoledger.mobile.core

import android.content.Context
import com.echoledger.mobile.testsupport.AppTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** Hide EchoLedger's live words as kept on the phone: the real preference file, not a stand-in. */
class ShareGuardMemoryTest : AppTest() {
    @After
    fun forget() = ShareGuard.resetForTest()

    private val prefs by lazy { app.getSharedPreferences("screen_share_privacy", Context.MODE_PRIVATE) }

    private fun newProcess() {
        ShareGuard.resetForTest()
        ShareGuard.restore(ShareGuardMemory(app))
    }

    @Test
    fun `a Hide the first v1_8_1 builds stored in the preference file is not brought back, and goes with the next change`() {
        prefs.edit().putBoolean("hidden", true).putString("mode", "hide").commit()
        newProcess()

        assertFalse("Hide is for the session only", ShareGuard.hiddenByUser.value)
        assertTrue(ShareGuard.textVisible.value)
        assertTrue(ShareGuard.hideLive.value)
        assertTrue("left as it was until the setting changes", prefs.contains("hidden"))

        ShareGuard.setHideLive(false)
        assertFalse("gone with the next change of the setting", prefs.contains("hidden"))
        assertFalse("and During screen sharing with it", prefs.contains("mode"))
        assertEquals(false, prefs.getBoolean("hide_live_words", true))
        newProcess()
        assertFalse(ShareGuard.hideLive.value)
    }

    @Test
    fun `During screen sharing kept before 2_0_5 reads as the new switch, from each old value`() {
        listOf("show" to false, "hide" to true, "black_box" to true).forEach { (mode, hide) ->
            prefs.edit().clear().putString("mode", mode).commit()
            newProcess()
            assertEquals(mode, hide, ShareGuard.hideLive.value)
        }
        prefs.edit().clear().commit()
        newProcess()
        assertTrue("nothing kept: on", ShareGuard.hideLive.value)
    }
}
