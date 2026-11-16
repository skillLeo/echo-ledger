package com.echoledger.mobile.core

import android.content.Context
import com.echoledger.mobile.core.ShareGuard.Mode
import com.echoledger.mobile.testsupport.AppTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** During screen sharing as kept on the phone (review of v1.8.1): the real preference file, not a stand-in. */
class ShareGuardMemoryTest : AppTest() {
    @After
    fun forget() = ShareGuard.resetForTest()

    @Test
    fun `a Hide the first v1_8_1 builds stored in the preference file is not brought back, and goes with the next change`() {
        val prefs = app.getSharedPreferences("screen_share_privacy", Context.MODE_PRIVATE)
        prefs.edit().putBoolean("hidden", true).putString("mode", "hide").commit()

        ShareGuard.resetForTest() // a new process
        ShareGuard.restore(ShareGuardMemory(app))

        assertFalse("Hide is for the session only", ShareGuard.hiddenByUser.value)
        assertTrue(ShareGuard.textVisible.value)
        assertEquals(Mode.PRIVATE, ShareGuard.mode.value)
        assertTrue("left as it was until the setting changes", prefs.contains("hidden"))

        ShareGuard.setMode(Mode.SHOW)
        assertFalse("gone with the next change of the setting", prefs.contains("hidden"))
        assertEquals("show", prefs.getString("mode", null))
        ShareGuard.resetForTest()
        ShareGuard.restore(ShareGuardMemory(app))
        assertEquals(Mode.SHOW, ShareGuard.mode.value)
        assertFalse(ShareGuard.hiddenByUser.value)
    }
}
