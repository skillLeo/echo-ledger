package com.echoledger.mobile.update

import android.Manifest
import android.content.ComponentName
import android.content.Context
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.calls.PhoneCallLog
import com.echoledger.mobile.net.ApiClient
import com.echoledger.mobile.privilege.SetupStep
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.translate.LiveTranslateService
import com.echoledger.mobile.ui.capabilities
import kotlinx.coroutines.test.runTest
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** The Google Play build (since v1.5): no call log, no updating itself (mobile/DISTRIBUTION.md). */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class PlayBuildTest {
    private lateinit var context: Context
    private lateinit var server: FakeServer

    @Before
    fun setUp() {
        context = ApplicationProvider.getApplicationContext()
        server = FakeServer()
    }

    @After
    fun tearDown() = server.close()

    @Test
    fun `asks for neither the call log nor installing apps`() {
        val requested = context.packageManager.getPackageInfo(
            context.packageName,
            PackageManager.GET_PERMISSIONS
        ).requestedPermissions.orEmpty().toList()
        assertFalse(Manifest.permission.READ_CALL_LOG in requested)
        assertFalse(Manifest.permission.REQUEST_INSTALL_PACKAGES in requested)
        assertFalse(Manifest.permission.PROCESS_OUTGOING_CALLS in requested)
        assertFalse(Manifest.permission.READ_CONTACTS in requested)
        assertFalse(Manifest.permission.READ_MEDIA_AUDIO in requested)
        assertFalse(Manifest.permission.BIND_ACCESSIBILITY_SERVICE in requested)
        assertFalse(PhoneCallLog.available)
        assertTrue(PhoneCallLog.permissionsToAsk().isEmpty())
        assertNull(PhoneCallLog.find(context, 0, 1))
        assertNull(PhoneCallLog.talkTimeMs(context, 0, 1))
    }

    @Test
    fun `has no capture helper and no special-use foreground service`() {
        val requested = context.packageManager.getPackageInfo(
            context.packageName,
            PackageManager.GET_PERMISSIONS
        ).requestedPermissions.orEmpty().toList()
        assertFalse(Manifest.permission.FOREGROUND_SERVICE_SPECIAL_USE in requested)
        assertFalse("moe.shizuku.manager.permission.API_V23" in requested)
        assertTrue(Manifest.permission.FOREGROUND_SERVICE_MICROPHONE in requested)
        assertNull(context.packageManager.resolveContentProvider("${context.packageName}.handoff", 0))
        assertNull(context.packageManager.resolveContentProvider("${context.packageName}.shizuku", 0))
        val recording = context.packageManager.getServiceInfo(ComponentName(context, RecordingService::class.java), 0)
        assertEquals(ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE, recording.foregroundServiceType)
        val translate = context.packageManager.getServiceInfo(ComponentName(context, LiveTranslateService::class.java), 0)
        assertEquals(ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PROJECTION, translate.foregroundServiceType)
        assertTrue(
            context.packageManager.queryIntentServices(
                android.content.Intent("android.service.notification.NotificationListenerService").setPackage(context.packageName),
                0
            ).isEmpty()
        )
        assertFalse(com.echoledger.mobile.core.SettingsCatalogue.entries.any { it.id == "auto-apps" || it.id == "after-call" })
        val caps = context.capabilities(helperConnected = false)
        assertFalse(caps.helperInBuild)
        assertFalse(caps.canCaptureCalls)
        assertFalse(SetupStep.HELPER in caps.missingSteps())
    }

    @Test
    fun `never looks for its own updates`() = runTest {
        assertFalse(SelfUpdate.SUPPORTED)
        SelfUpdate.check(context, ApiClient(server.baseUrl, emptyList(), token = "t"), force = true)
        SelfUpdate.restore(context)
        assertEquals(UpdateStatus.Idle, SelfUpdate.status.value)
        assertTrue(server.requests.isEmpty())
    }
}
