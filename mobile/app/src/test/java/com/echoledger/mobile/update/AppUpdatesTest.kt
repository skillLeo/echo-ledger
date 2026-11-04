package com.echoledger.mobile.update

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.data.AndroidReleaseDto
import com.echoledger.mobile.ui.UpdateText
import com.echoledger.mobile.ui.design.BannerKind
import java.io.File
import java.security.MessageDigest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** The website build's update rules (since v1.5); these hold in both builds. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class AppUpdatesTest {
    @get:Rule val folder = TemporaryFolder()

    private val release = AndroidReleaseDto(
        version = "1.5.1",
        code = 16,
        url = "https://ledger.example.com/downloads/files/EchoLedger-1.5.1.apk",
        sha256 = "a".repeat(64),
        bytes = 41_234_567,
        minSdk = 29
    )

    private fun sha(bytes: ByteArray) = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

    @Test
    fun `looks again after 12 hours, never sooner, and when the clock went back`() {
        val h = 60 * 60 * 1000L
        assertTrue(AppUpdates.due(0, 5 * h))
        assertFalse(AppUpdates.due(100 * h, 100 * h + 11 * h))
        assertTrue(AppUpdates.due(100 * h, 112 * h))
        assertTrue(AppUpdates.due(100 * h, 99 * h))
    }

    @Test
    fun `offers only a newer build that installs here and is well formed`() {
        assertEquals(release, AppUpdates.offer(release, currentCode = 15, sdk = 34))
        assertNull(AppUpdates.offer(release, currentCode = 16, sdk = 34))
        assertNull(AppUpdates.offer(release, currentCode = 17, sdk = 34))
        assertNull(AppUpdates.offer(release.copy(minSdk = 35), currentCode = 15, sdk = 34))
        assertNull(AppUpdates.offer(release.copy(sha256 = "not-a-hash"), currentCode = 15, sdk = 34))
        assertNull(AppUpdates.offer(release.copy(bytes = 0), currentCode = 15, sdk = 34))
        assertNull(AppUpdates.offer(null, currentCode = 15, sdk = 34))
        // An upper-case hash is still a hash.
        assertEquals(16, AppUpdates.offer(release.copy(sha256 = "A".repeat(64)), currentCode = 15, sdk = 34)?.code)
    }

    @Test
    fun `a download matches only with the published size and SHA-256`() {
        val bytes = "an apk".toByteArray()
        val file = folder.newFile("EchoLedger-16.apk").apply { writeBytes(bytes) }
        val published = release.copy(sha256 = sha(bytes), bytes = bytes.size.toLong())
        assertTrue(AppUpdates.matches(file, published))
        assertFalse(AppUpdates.matches(file, published.copy(bytes = bytes.size + 1L)))
        assertFalse(AppUpdates.matches(file, published.copy(sha256 = sha("another".toByteArray()))))
        assertFalse(AppUpdates.matches(File(folder.root, "missing.apk"), published))
    }

    @Test
    fun `downloads of this version or older are cleaned up, newer ones kept`() {
        val names = listOf("EchoLedger-14.apk", "EchoLedger-15.apk.part", "EchoLedger-16.apk", "EchoLedger-17.apk.part", "other.bin")
        val stale = AppUpdates.stale(names.map { File(folder.root, it) }, currentCode = 15).map { it.name }
        assertEquals(listOf("EchoLedger-14.apk", "EchoLedger-15.apk.part", "other.bin"), stale)
    }

    @Test
    fun `progress and size in words`() {
        assertEquals(0, AppUpdates.percent(0, 100))
        assertEquals(42, AppUpdates.percent(42, 100))
        assertEquals(100, AppUpdates.percent(150, 100))
        assertEquals("39.3 MB", AppUpdates.size(41_234_567))
        assertEquals("512 KB", AppUpdates.size(524_288))
        assertEquals("EchoLedger 1.5.1 is ready to install", AppUpdates.headline(release))
    }

    @Test
    fun `remembers the offer and the closed banner on this phone`() {
        val context = ApplicationProvider.getApplicationContext<Context>()
        val prefs = UpdatePrefs(context)
        assertNull(prefs.offered)
        assertEquals(0L, prefs.lastCheckMs)
        prefs.offered = release
        prefs.hiddenCode = 16
        prefs.lastCheckMs = 1234L
        val again = UpdatePrefs(context)
        assertEquals(release, again.offered)
        assertEquals(16, again.hiddenCode)
        assertEquals(1234L, again.lastCheckMs)
        again.offered = null
        assertNull(UpdatePrefs(context).offered)
    }

    @Test
    fun `every state has words and at most one action`() {
        assertEquals(UpdateText.Action.CHECK, UpdateText.line(UpdateStatus.Idle).action)
        assertEquals("You have the newest version.", UpdateText.line(UpdateStatus.UpToDate).text)
        assertEquals(UpdateText.Action.NONE, UpdateText.line(UpdateStatus.Checking).action)
        UpdateText.line(UpdateStatus.Available(release)).let {
            assertEquals("EchoLedger 1.5.1 is ready to install (39.3 MB).", it.text)
            assertEquals("Update", it.label)
        }
        assertEquals("Downloading the update… 42 %", UpdateText.line(UpdateStatus.Downloading(release, 42)).text)
        assertEquals(UpdateText.Action.ALLOW, UpdateText.line(UpdateStatus.NeedsPermission(release)).action)
        assertEquals(UpdateText.Action.NONE, UpdateText.line(UpdateStatus.Installing(release)).action)
        UpdateText.line(UpdateStatus.Failed(release, "It broke.")).let {
            assertEquals(BannerKind.Error, it.kind)
            assertEquals("Try again", it.label)
        }
        assertEquals(UpdateText.Action.CHECK, UpdateText.line(UpdateStatus.Failed(null, "No answer.")).action)
    }

    @Test
    fun `the banner shows an offer until it is closed and every update in progress`() {
        assertTrue(UpdateText.bannerShown(UpdateStatus.Available(release), hiddenCode = 0))
        assertFalse(UpdateText.bannerShown(UpdateStatus.Available(release), hiddenCode = 16))
        assertTrue(UpdateText.bannerShown(UpdateStatus.Downloading(release, 3), hiddenCode = 16))
        assertTrue(UpdateText.bannerShown(UpdateStatus.Failed(release, "x"), hiddenCode = 16))
        assertFalse(UpdateText.bannerShown(UpdateStatus.Failed(null, "x"), hiddenCode = 0))
        assertFalse(UpdateText.bannerShown(UpdateStatus.UpToDate, hiddenCode = 0))
        assertFalse(UpdateText.bannerShown(UpdateStatus.Idle, hiddenCode = 0))
    }
}
