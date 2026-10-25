package com.echoledger.mobile.e2e

import android.Manifest
import android.content.pm.PackageManager
import android.content.pm.PermissionInfo
import android.provider.Settings
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import androidx.test.uiautomator.By
import androidx.test.uiautomator.UiObject2
import com.echoledger.mobile.e2e.support.AppState
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.Shell
import com.echoledger.mobile.e2e.support.Ui
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/**
 * Runtime permission prompts and manual recording. Each test starts from a fresh install state (no runtime permission
 * granted, app data cleared by the test orchestrator); tests that need a permission grant it through the instrumentation.
 */
@RunWith(AndroidJUnit4::class)
class PermissionsAndRecordingTest : E2eTest() {
    private fun granted(permission: String) = Ui.context.checkSelfPermission(permission) == PackageManager.PERMISSION_GRANTED

    private fun grant(permission: String) {
        InstrumentationRegistry.getInstrumentation().uiAutomation.grantRuntimePermission(Ui.PACKAGE, permission)
    }

    private fun openSetup() {
        launchPaired()
        Ui.openTab("Settings")
        Ui.awaitText("Device")
        Ui.clickText("Setup guide")
        Ui.awaitText("Setup")
    }

    /** The "Allow" button on the same row as [row]. */
    private fun allowFor(row: String): UiObject2 {
        val y = Ui.awaitText(row).visibleBounds.centerY()
        return Ui.device.findObjects(By.text("Allow")).minByOrNull { Math.abs(it.visibleBounds.centerY() - y) }
            ?: throw AssertionError("no Allow button for $row\n${Ui.hierarchySummary()}")
    }

    /** The recording notification as the system holds it (read with dumpsys: the shade is slow to render here). */
    private fun recordingNotificationHasStopAction(): Boolean {
        val dump = Shell.run("dumpsys notification --noredact")
        return Regex("pkg=com\\.echoledger\\.mobile[\\s\\S]{0,4000}").findAll(dump).any {
            it.value.contains("Recording") && it.value.contains("Stop")
        }
    }

    private fun answerPrompt(vararg labels: String) {
        Ui.awaitCondition("the system permission dialog", Ui.NORMAL) { Ui.permissionDialogButton(*labels) != null }
        Ui.permissionDialogButton(*labels)!!.click()
    }

    @Test
    fun pressingRecWithoutTheMicrophonePermissionDoesNothingAndDoesNotCrash() {
        assertFalse("microphone permission must start out missing", granted(Manifest.permission.RECORD_AUDIO))
        launchPaired()
        Ui.openTab("Record")
        Ui.awaitText("Not recording")
        Ui.clickText("REC")
        Thread.sleep(3_000)
        assertTrue("still showing the idle recorder", Ui.has("Not recording") && Ui.has("REC"))
        Ui.snapshot("record-idle")
        assertTrue(AppState.recordings().isEmpty())
        assertTrue("the recorder tells where the microphone is configured", Ui.has("Open setup"))
    }

    @Test
    fun theSetupScreenListsTheMissingPermissionsAndAutoRecordIsLockedUntilTheHelperRuns() {
        openSetup()
        assertTrue(Ui.has("•  Microphone"))
        assertTrue(Ui.hasContaining("Notifications"))
        assertTrue(Ui.hasContaining("Caption bubble"))
        assertTrue(Ui.hasContaining("Not enabled yet.") || Ui.hasContaining("helper"))
        Ui.clickDescription("Back")
        Ui.openTab("Record")
        Ui.awaitText("Auto-record WhatsApp calls")
        assertTrue(Ui.has("Enable call capture in Setup first"))
        assertFalse("auto-record cannot be switched on without the capture helper", Ui.switchNear("Auto-record WhatsApp calls").isEnabled)
        assertTrue(Ui.has("Open setup"))
    }

    @Test
    fun allowingTheMicrophoneFromSetupShowsTheSystemPromptAndMarksItDone() {
        openSetup()
        allowFor("•  Microphone").click()
        answerPrompt("While using the app", "Only this time")
        Ui.awaitText("✓  Microphone")
        Ui.snapshot("setup-microphone-granted")
        assertTrue(granted(Manifest.permission.RECORD_AUDIO))
    }

    @Test
    fun denyingTheMicrophonePromptKeepsItListedAsMissing() {
        openSetup()
        allowFor("•  Microphone").click()
        answerPrompt("Don't allow", "Deny")
        Ui.awaitText("•  Microphone")
        assertFalse(granted(Manifest.permission.RECORD_AUDIO))
    }

    @Test
    fun theNotificationPermissionIsRequestedFromSetup() {
        openSetup()
        allowFor("•  Notifications (Stop / Keep / Discard actions)").click()
        answerPrompt("Allow")
        Ui.awaitText("✓  Notifications (Stop / Keep / Discard actions)")
        assertTrue(granted(Manifest.permission.POST_NOTIFICATIONS))
    }

    @Test
    fun theCaptionBubbleRowOpensTheSystemOverlaySettings() {
        openSetup()
        allowFor("•  Caption bubble (optional)").click()
        Ui.awaitCondition("the overlay permission screen", Ui.NORMAL) { Ui.find(By.pkg("com.android.settings")) != null }
        assertFalse(Settings.canDrawOverlays(Ui.context))
        Ui.device.pressBack()
        Ui.awaitText("Setup")
    }

    @Test
    fun manualRecordingFromTheMicrophoneIsSavedUploadedAndTranscribed() {
        grant(Manifest.permission.RECORD_AUDIO)
        Backend.wipeSessions()
        launchPaired()
        AppState.disableCaptions()
        Ui.openTab("Record")
        Ui.awaitText("Not recording")
        Ui.clickText("REC")
        Ui.awaitText("Recording…", Ui.NORMAL)
        assertTrue("the button turns into STOP", Ui.has("STOP"))
        Thread.sleep(4_000)
        Ui.clickText("STOP")
        Ui.awaitText("Not recording", Ui.NORMAL)
        Ui.awaitCondition("the recording to reach the server", 120_000, 3_000) {
            Backend.liveSessions().any { it.sourceApp == "other" && it.finalStatus == "completed" }
        }
        assertEquals(1, Backend.liveSessions().size)
        Ui.openTab("Library")
        Ui.awaitText("Other", Ui.LONG)
        assertTrue(Ui.has("Phone"))
    }

    @Test
    fun aNotificationWithAStopActionIsShownWhileRecording() {
        grant(Manifest.permission.RECORD_AUDIO)
        grant(Manifest.permission.POST_NOTIFICATIONS)
        launchPaired()
        AppState.disableCaptions()
        Ui.openTab("Record")
        Ui.clickText("REC")
        Ui.awaitText("Recording…", Ui.NORMAL)
        try {
            Ui.awaitCondition("the recording notification with a Stop action", Ui.NORMAL) { recordingNotificationHasStopAction() }
        } finally {
            if (Ui.has("STOP")) Ui.clickText("STOP")
        }
        Ui.awaitText("Not recording", Ui.NORMAL)
    }

    @Test
    fun theOnlyUserFacingPermissionsAreMicrophoneCameraAndNotifications() {
        val pm = Ui.context.packageManager
        val requested = pm.getPackageInfo(Ui.PACKAGE, PackageManager.GET_PERMISSIONS).requestedPermissions.orEmpty().toSet()
        val dangerous = requested.filter { name ->
            runCatching { pm.getPermissionInfo(name, 0).protection == PermissionInfo.PROTECTION_DANGEROUS }.getOrDefault(false)
        }.toSet()
        assertEquals(
            setOf(Manifest.permission.CAMERA, Manifest.permission.RECORD_AUDIO, Manifest.permission.POST_NOTIFICATIONS),
            dangerous
        )
        listOf("ACCESS_FINE_LOCATION", "READ_CONTACTS", "READ_PHONE_STATE", "READ_EXTERNAL_STORAGE", "READ_MEDIA_AUDIO").forEach {
            assertFalse("$it must not be requested", requested.contains("android.permission.$it"))
        }
    }
}
