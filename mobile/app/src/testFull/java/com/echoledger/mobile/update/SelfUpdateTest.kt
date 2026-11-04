package com.echoledger.mobile.update

import android.Manifest
import android.content.Context
import android.content.pm.PackageInstaller
import android.content.pm.PackageManager
import android.os.Looper
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.calls.PhoneCallLog
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.testsupport.FakeServer
import java.io.File
import java.security.MessageDigest
import kotlinx.coroutines.test.runTest
import mockwebserver3.MockResponse
import okio.Buffer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config

/** The website ("full") build updates itself from the server's downloads (since v1.5). */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class SelfUpdateTest {
    private lateinit var context: Context
    private lateinit var server: FakeServer
    private val apk = "EchoLedger update bytes".toByteArray()
    private val newer = BuildConfig.VERSION_CODE + 1

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        server = FakeServer()
        SelfUpdate.reset()
    }

    @After
    fun tearDown() {
        server.close()
        SelfUpdate.reset()
        shadowOf(context.packageManager).setCanRequestPackageInstalls(false)
    }

    private fun api() = ApiClient(server.baseUrl, emptyList(), token = "device-token")

    private fun sha(bytes: ByteArray) = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

    private fun manifest(
        code: Int = newer,
        sha256: String = sha(apk),
        bytes: Int = apk.size,
        url: String = "${server.baseUrl}/downloads/files/EchoLedger.apk"
    ) = """{"version":"9.9.9","code":$code,"url":"$url","sha256":"$sha256","bytes":$bytes,"min_sdk":29,"notes":"Faster uploads."}"""

    private fun publish(json: String = manifest()) {
        server.on("GET", "/downloads/android.json", FakeServer.json(200, json))
        server.on("GET", "/downloads/files/EchoLedger.apk", MockResponse.Builder().code(200).body(Buffer().write(apk)).build())
    }

    @Test
    fun `offers a newer build, remembers it and asks the server again only after 12 hours`() = runTest {
        publish()
        SelfUpdate.check(context, api(), force = false, nowMs = 10_000_000)
        val offered = (SelfUpdate.status.value as UpdateStatus.Available).release
        assertEquals(newer, offered.code)
        assertEquals("Faster uploads.", offered.notes)
        assertEquals(newer, UpdatePrefs(context).offered?.code)
        SelfUpdate.check(context, api(), force = false, nowMs = 10_000_000 + 60_000)
        assertEquals(1, server.requestsTo("GET", "/downloads/android.json").size)
        // A public page: the device token is not sent with it.
        assertNull(server.requests.first().headers["Authorization"])
        // After a restart the offer is back without a request.
        SelfUpdate.reset()
        SelfUpdate.restore(context)
        assertEquals(newer, (SelfUpdate.status.value as UpdateStatus.Available).release.code)
        assertEquals(1, server.requests.size)
    }

    @Test
    fun `nothing published, or nothing newer, is up to date`() = runTest {
        SelfUpdate.check(context, api(), force = true)
        assertEquals(UpdateStatus.UpToDate, SelfUpdate.status.value)
        publish(manifest(code = BuildConfig.VERSION_CODE))
        SelfUpdate.check(context, api(), force = true)
        assertEquals(UpdateStatus.UpToDate, SelfUpdate.status.value)
        assertNull(UpdatePrefs(context).offered)
    }

    @Test
    fun `check for updates says why it could not look, the automatic look stays quiet`() = runTest {
        server.on("GET", "/downloads/android.json", FakeServer.json(500, """{"message":"Server Error"}"""))
        SelfUpdate.check(context, api(), force = false, nowMs = 10_000_000)
        assertEquals(UpdateStatus.Idle, SelfUpdate.status.value)
        SelfUpdate.check(context, api(), force = true)
        val failed = SelfUpdate.status.value as UpdateStatus.Failed
        assertNull(failed.release)
        assertTrue(failed.message, failed.message.startsWith("Could not look for updates"))
    }

    @Test
    fun `an update on another server is never downloaded`() = runTest {
        shadowOf(context.packageManager).setCanRequestPackageInstalls(true)
        val elsewhere = AppUpdates.offer(
            com.echoledger.mobile.data.ApiJson.decodeFromString(
                com.echoledger.mobile.data.AndroidReleaseDto.serializer(),
                manifest(url = "https://downloads.example.net/EchoLedger.apk")
            ),
            BuildConfig.VERSION_CODE,
            34
        )!!
        SelfUpdate.install(context, api(), elsewhere)
        assertTrue((SelfUpdate.status.value as UpdateStatus.Failed).message.contains("not on your EchoLedger server"))
        assertTrue(server.requests.isEmpty())
    }

    @Test
    fun `Android must allow EchoLedger to install apps first`() = runTest {
        publish()
        SelfUpdate.check(context, api(), force = true)
        val release = (SelfUpdate.status.value as UpdateStatus.Available).release
        SelfUpdate.install(context, api(), release)
        assertEquals(UpdateStatus.NeedsPermission(release), SelfUpdate.status.value)
        assertTrue(server.requestsTo("GET", "/downloads/files/.*").isEmpty())
    }

    @Test
    fun `a download that does not match the published hash is deleted and not installed`() = runTest {
        shadowOf(context.packageManager).setCanRequestPackageInstalls(true)
        publish(manifest(sha256 = sha("something else".toByteArray())))
        SelfUpdate.check(context, api(), force = true)
        SelfUpdate.install(context, api(), (SelfUpdate.status.value as UpdateStatus.Available).release)
        assertTrue((SelfUpdate.status.value as UpdateStatus.Failed).message.contains("did not match"))
        assertTrue(File(context.cacheDir, "updates").listFiles().orEmpty().isEmpty())
        assertTrue(context.packageManager.packageInstaller.allSessions.isEmpty())
    }

    @Test
    fun `a matching download goes to Android's installer, and its answer comes back in words`() = runTest {
        shadowOf(context.packageManager).setCanRequestPackageInstalls(true)
        publish()
        SelfUpdate.check(context, api(), force = true)
        val release = (SelfUpdate.status.value as UpdateStatus.Available).release
        SelfUpdate.install(context, api(), release)
        assertEquals(UpdateStatus.Installing(release), SelfUpdate.status.value)
        val session = context.packageManager.packageInstaller.allSessions.single()
        assertEquals(context.packageName, session.appPackageName)
        // The person cancels on Android's screen.
        shadowOf(context.packageManager.packageInstaller).setSessionFails(session.sessionId)
        shadowOf(Looper.getMainLooper()).idle()
        val failed = SelfUpdate.status.value as UpdateStatus.Failed
        assertEquals(release, failed.release)
        // Tapping Update again reuses the checked download.
        SelfUpdate.install(context, api(), release)
        assertEquals(1, server.requestsTo("GET", "/downloads/files/.*").size)
    }

    @Test
    fun `the installer's answers in words`() {
        assertNull(UpdateInstallReceiver.message(PackageInstaller.STATUS_SUCCESS, null))
        assertTrue(UpdateInstallReceiver.message(PackageInstaller.STATUS_FAILURE_ABORTED, null)!!.contains("cancelled"))
        assertTrue(UpdateInstallReceiver.message(PackageInstaller.STATUS_FAILURE_CONFLICT, null)!!.contains("not signed like"))
        assertTrue(UpdateInstallReceiver.message(PackageInstaller.STATUS_FAILURE_STORAGE, null)!!.contains("free space"))
        assertEquals(
            "Android could not install the update (INSTALL_FAILED_X). Try again.",
            UpdateInstallReceiver.message(PackageInstaller.STATUS_FAILURE, "INSTALL_FAILED_X")
        )
    }

    @Test
    fun `the website build reads the call log and may install its updates`() {
        val requested = context.packageManager.getPackageInfo(
            context.packageName,
            PackageManager.GET_PERMISSIONS
        ).requestedPermissions.orEmpty().toList()
        assertTrue(Manifest.permission.READ_CALL_LOG in requested)
        assertTrue(Manifest.permission.REQUEST_INSTALL_PACKAGES in requested)
        assertTrue(PhoneCallLog.available)
        assertTrue(Manifest.permission.READ_CALL_LOG in PhoneCallLog.permissionsToAsk())
        assertTrue(SelfUpdate.SUPPORTED)
    }
}
