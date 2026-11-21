package com.echoledger.mobile.account

import android.content.ClipData
import android.content.ClipDescription
import android.content.ClipboardManager
import android.os.Looper
import com.echoledger.mobile.testsupport.AppTest
import java.io.File
import java.time.Duration
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config

/**
 * The two-step key and the recovery codes on the clipboard: marked sensitive, cleared after a minute, on app lock and
 * on sign-out, and only while the clipboard still holds that copy.
 */
class SecretClipTest : AppTest() {
    private val clipboard get() = app.getSystemService(ClipboardManager::class.java)!!

    private fun text(): String? = clipboard.primaryClip?.takeIf { it.itemCount > 0 }?.getItemAt(0)?.text?.toString()

    private fun pass(seconds: Long) = shadowOf(Looper.getMainLooper()).idleFor(Duration.ofSeconds(seconds))

    @Test
    fun `a copied secret is marked sensitive and leaves the clipboard after a minute`() {
        SecretClip.copy(app, "Recovery codes", "aaaa-1111")
        assertEquals("aaaa-1111", text())
        assertTrue(clipboard.primaryClipDescription!!.extras!!.getBoolean(ClipDescription.EXTRA_IS_SENSITIVE))
        pass(59)
        assertEquals("aaaa-1111", text())
        pass(2)
        assertNull(text())
        assertFalse(SecretClip.waiting)
    }

    @Test
    @Config(sdk = [30])
    fun `before Android 13 the copy has no sensitive mark and is still cleared after a minute`() {
        SecretClip.copy(app, "Key", "JBSWY3DPEHPK3PXP")
        assertFalse(clipboard.primaryClipDescription!!.extras!!.containsKey(ClipDescription.EXTRA_IS_SENSITIVE))
        pass(61)
        assertNull(text())
    }

    @Test
    fun `what the person copied since is never cleared`() {
        SecretClip.copy(app, "Key", "JBSWY3DPEHPK3PXP")
        clipboard.setPrimaryClip(ClipData.newPlainText("Key", "a note of my own"))
        pass(61)
        assertEquals("a note of my own", text())
        // Nor on lock or sign-out: the copy is forgotten once something else is on the clipboard.
        SecretClip.clearNow(app)
        assertEquals("a note of my own", text())
        assertFalse(SecretClip.waiting)
    }

    @Test
    fun `the app locking clears the copy at once`() {
        SecretClip.copy(app, "Recovery codes", "aaaa-1111")
        SecretClip.clearNow(app)
        assertNull(text())
    }

    @Test
    fun `the copy on the clipboard is told apart by its label and token`() {
        val ours = ClipData.newPlainText("Key", "x").description.apply {
            extras = android.os.PersistableBundle().apply { putString(SecretClip.TOKEN_EXTRA, "t1") }
        }
        assertEquals(SecretClip.Holds.Ours, SecretClip.holds(ours, "t1", "Key"))
        assertEquals(SecretClip.Holds.Other, SecretClip.holds(ours, "t2", "Key"))
        assertEquals(SecretClip.Holds.Other, SecretClip.holds(ClipData.newPlainText("Key", "x").description, "t1", "Key"))
        assertEquals(SecretClip.Holds.Unknown, SecretClip.holds(null, "t1", "Key"))
    }

    @Test
    fun `signing out clears the copied secret and every kept picture`() = runBlocking {
        val pictures = File(app.cacheDir, "avatars")
        AvatarCache.of(app, "https://echoledger.test:443").put("7", 1, byteArrayOf(1))
        assertTrue(pictures.listFiles()!!.isNotEmpty())
        SecretClip.copy(app, "Recovery codes", "aaaa-1111")
        app.graph.settings.clearPairing()
        shadowOf(Looper.getMainLooper()).idle()
        assertNull(text())
        assertFalse(pictures.exists())
    }

    @Test
    fun `a new sign-in, maybe another account, keeps no picture of the last one`() = runBlocking {
        AvatarCache.of(app, "https://echoledger.test:443").put("7", 1, byteArrayOf(1))
        app.graph.settings.saveToken("8|eld_other")
        assertFalse(File(app.cacheDir, "avatars").exists())
    }
}
