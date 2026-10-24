package com.echoledger.mobile.capture

import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.privilege.SetupStep
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class CallerPolicyTest {
    @Test
    fun `shell root and own uid may hand over the binder`() {
        assertTrue(CallerPolicy.isAllowed(CallerPolicy.SHELL_UID, ownUid = 10234))
        assertTrue(CallerPolicy.isAllowed(CallerPolicy.ROOT_UID, ownUid = 10234))
        assertTrue(CallerPolicy.isAllowed(10234, ownUid = 10234))
    }

    @Test
    fun `any other app is rejected`() {
        assertFalse(CallerPolicy.isAllowed(10999, ownUid = 10234))
        assertFalse(CallerPolicy.isAllowed(1000, ownUid = 10234)) // system, but not shell/root/self
    }
}

class CapabilitiesTest {
    private fun caps(sdk: Int, helper: Boolean) = Capabilities(
        sdkInt = sdk,
        helperConnected = helper,
        micPermission = true,
        notificationPermission = true,
        overlayPermission = true
    )

    @Test
    fun `call capture needs Android 14 and a connected helper`() {
        assertFalse(caps(33, helper = true).canCaptureCalls)
        assertFalse(caps(34, helper = false).canCaptureCalls)
        assertTrue(caps(34, helper = true).canCaptureCalls)
    }

    @Test
    fun `the OS support flag ignores the helper`() {
        assertTrue(caps(34, helper = false).callCaptureSupportedByOs)
        assertFalse(caps(33, helper = false).callCaptureSupportedByOs)
    }

    @Test
    fun `missing steps lists the helper only when the OS supports it`() {
        val a13 = Capabilities(33, helperConnected = false, micPermission = true, notificationPermission = true, overlayPermission = false)
        assertFalse(a13.missingSteps().contains(SetupStep.HELPER))

        val a14 =
            Capabilities(34, helperConnected = false, micPermission = false, notificationPermission = false, overlayPermission = false)
        assertEquals(
            listOf(SetupStep.MICROPHONE, SetupStep.NOTIFICATIONS, SetupStep.HELPER, SetupStep.OVERLAY),
            a14.missingSteps()
        )
    }
}
